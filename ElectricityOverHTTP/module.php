<?php

class SML_Electricity_HTTP extends IPSModule
{

	#================================================================================================
    public function Create()
	#================================================================================================
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean('BasicCheck', true);
        $this->RegisterPropertyBoolean('CrcCheck', true);
        $this->RegisterPropertyBoolean('AddMissing', true);

    }

	#================================================================================================
    public function ApplyChanges()
	#================================================================================================
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->ForceParent('{4CB91589-CE01-4700-906F-26320EFCF6C4}');

    }

	#================================================================================================
    public function ReceiveData($JSONString)
	#================================================================================================
    {
        $data = json_decode($JSONString);
        $this->SendDebug("Received", utf8_decode($data->Buffer), 0);

        $data->Buffer = str_replace(' ', '',  utf8_decode($data->Buffer));
        $this->SetBuffer('Record', substr($data->Buffer, 16));

        if($this->ReadPropertyBoolean('BasicCheck')){
            if(!$this->BasicCheck($this->GetBuffer('Record'))){
                $this->SendDebug('Error', 'BasicCheck: SML-String not valid', 0);
                return;
            }
        }

        if($this->ReadPropertyBoolean('CrcCheck')){
            if ($this->smlCheckCrc('1B1B1B1B01010101'.$this->GetBuffer('Record')) !== true){
                $this->SendDebug('Error', 'CrcCheck: SML-String not valid', 0);
                return;
            }
        }
        
        $this->SetBuffer('Position', 0);

        for($i = 0; $i < 3; $i++){
            if(hexdec(substr($this->GetBuffer('Record'), intval($this->GetBuffer('Position')), 1)) == 7){
                $this->GetList();
            }else{
                break;
            }
        }
    }

    #================================================================================================
    private function GetString()
	#================================================================================================
    {
        $pos = intval($this->GetBuffer('Position'));
        $rec = $this->GetBuffer('Record');

        $pre = hexdec(substr($rec, $pos, 1));
        if($pre == 8){
            $anz = bindec(decbin(hexdec(substr($rec, $pos+1, 1))).substr("0000".decbin(hexdec(substr($rec, $pos+2, 2))),-4));
            $pos += 2;
        }else{
            $anz = hexdec(substr($rec, $pos+1, 1));
        }

        if($anz > 0){
            $result = substr($rec, $pos + 2, 2*($anz-1));
            $pos += 2 * ($anz-1);
            $this->SetBuffer('Position', $pos);
            return $result;
        }else{
            $pos +=2;
            $this->SetBuffer('Position', $pos);
        }
    }

	#================================================================================================
    private function GetList()
	#================================================================================================
    {
        $pos = intval($this->GetBuffer('Position'));
        $rec = $this->GetBuffer('Record');

        $pre = hexdec(substr($rec, $pos, 1));
        if($pre == 15){
            $anz = bindec(decbin(hexdec(substr($rec, $pos+1, 1))).substr("0000".decbin(hexdec(substr($rec, $pos+2, 2))),-4));
            $pos += 2;
        }else{
            $anz = hexdec(substr($rec, $pos+1, 1));
        }
        $this->SetBuffer('Position', $pos);

        $array = array();
        for ($i = 0; $i < $anz; $i++) {
            $pos = intval($this->GetBuffer('Position'));
            $pos += 2;
            $this->SetBuffer('Position', $pos);

            switch(hexdec(substr($rec, $pos, 1))){
                case 0:
                    $array[] = $this->GetString();
                    break;
                case 5:
                    $string = $this->GetString();
                    $array[] = $this->Value($string, true);
                    break;
                case 6:
                    $string = $this->GetString();
                    $array[] = $this->Value($string, false);
                    break;
                case 15:
                case 7:
                    $array[] = $this->GetList();
                    break;
            }
        }
        $this->GetProperties($array);
        return $array;
    }

	#================================================================================================
    private function GetProperties($array)
	#================================================================================================
    {

        if(!is_array($array) || count($array)<7)return;
        if(is_string($array[0]) && strlen($array[0]) == 12){
            $Typ = hexdec(substr($array[0], 4,2));
            $Index = $this->Index(substr($array[0], 4));
            if($Typ > 0 && $Typ < 96){

                # Unit   ##############################################################
                $scaler = 1;
                switch ($array[3]) {
                    case 8:
                        if (!IPS_VariableProfileExists('Angle.EHZ')) {
                            IPS_CreateVariableProfile('Angle.EHZ', 2);
                            IPS_SetVariableProfileIcon('Angle.EHZ', 'Link');
                            IPS_SetVariableProfileText('Angle.EHZ', '', ' °');
                            IPS_SetVariableProfileDigits('Angle.EHZ', 1);
                        }
                        $unit = 'Angle.EHZ';
                        break;
                    case 27:
                    case 29:
                        $unit = '~Watt';
                        if(IPS_GetKernelVersion() < 6.1)$unit = '~Watt.14490';
                        break;
                    case 30:
                    case 32:
                        $scaler /= 1000;
                        $unit = '~Electricity';
                        break;
                    case 33:
                        $unit = '~Ampere';
                        break;
                    case 35:
                        $unit = '~Volt';
                        break;

                    case 44:
                        $unit = '~Hertz';
                        break;
                    
                    default:
                    $unit = '';
                    break;
                }

                # Scaler ##############################################################
                $scaler *= pow(10, $array[4]);

                # Value  ##############################################################
                $value = $array[5] * $scaler;
                $this->AddValue($Index, $value, $unit);

                $this->SendDebug($Index, "Unit: $unit -- Scaler: $scaler  -- Value: $value", 0);
            }
        }            

        return;
    }

	#================================================================================================
    private function Index($string)
	#================================================================================================
    {
        $index = '';
        for ($i = 0; $i < strlen($string) -2; $i+=2) {
            $index .= hexdec(substr($string,$i,2)).'.';
        }
        $index = substr($index, 0, -1);
        if(hexdec(substr($string,$i,2)) !== 255) $index .= '*'.hexdec(substr($string,$i,2));
        return $index;
    }

	#================================================================================================
    private function Value($string, $signed)
	#================================================================================================
    {
        if($signed){
            for($i = 0; $i < strlen($string); $i+=2){
                if(substr($string, $i, 2) != 'FF'){
                    if($i > 0)$string = substr($string, $i -2);
                    break;
                }
            }
            $ref = '7';
            for($i = 1; $i < strlen($string); $i++)$ref .='F';
        }
        if($signed && (hexdec($ref) - hexdec($string)) < 0){
            $ref = str_replace('7', 'F', $ref);
            return hexdec($string) - hexdec($ref) - 1;
        }else{
            return hexdec($string);
        }
    }

	#================================================================================================
    private function AddValue($Index, $Value, $Profile)
	#================================================================================================
    {
        if($this->ReadPropertyBoolean('AddMissing')) $this->RegisterVariableFloat(md5($Index), $Index, $Profile);
        if(@$this->GetIDForIdent(md5($Index)) === false)return;
        if($Profile == '~Electricity'){
            if($Value != 0) $this->SetValue(md5($Index), $Value);
        }else{
            $this->SetValue(md5($Index), $Value);
        }
    }

	#================================================================================================
    private function Str2Hex($string)
	#================================================================================================
    {
        $hex = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $hex .= sprintf('%02X', ord($string[$i]));
        }

        return $hex;
    }

	#================================================================================================
    private function BasicCheck($string)
	#================================================================================================
    {
        if(strlen($string)%4 != 0)return false;
        if(substr($string,-16, 10) != '1B1B1B1B1A')return false;
        $fill = hexdec(substr($string, -6, 2));
        $fill = ($fill +1)*2;
        if(hexdec(substr($string,-$fill-16, $fill)) != 0)return false;

        return true;
    }

	#================================================================================================
    private function crcReverseChar($char)
	#================================================================================================
    {
        $byte = ord($char);
        $tmp = 0;
        for($i = 0; $i < 8; ++$i){
            if($byte & (1 << $i)){
                $tmp |= (1 << (7 - $i));
            }
        }
        return chr($tmp);
    }

	#================================================================================================
    private function crcReverseString($str)
	#================================================================================================
    {
        $m = 0;
        $n = strlen($str) - 1;
        while($m <= $n){
            if($m == $n){
                $str[$m] = reverseChar($str[$m]);
                break;
            }
            $ord1 = $this->crcReverseChar($str[$m]);
            $ord2 = $this->crcReverseChar($str[$n]);
            $str[$m] = $ord2;
            $str[$n] = $ord1;
            $m++;
            $n--;
        }
        return $str;
    }

	#================================================================================================
    private function crc16($str, $polynomial, $initValue, $xOrValue, $inputReverse = false, $outputReverse = false)
	#================================================================================================
    {
        $crc = $initValue;

        for($i = 0; $i < strlen($str); $i++){
            if($inputReverse){
                $c = ord($this->crcReverseChar($str[$i]));
            }else{
                $c = ord($str[$i]);
            }
            $crc ^= ($c << 8);
            for($j = 0; $j < 8; ++$j){
                if($crc & 0x8000){
                    $crc = (($crc << 1) & 0xffff) ^ $polynomial;
                }else{
                    $crc = ($crc << 1) & 0xffff;
                }
            }
        }

        if($outputReverse){
            $ret = pack('cc', $crc & 0xff, ($crc >> 8) & 0xff);
            $ret = $this->crcReverseString($ret);
            $arr = unpack('vshort', $ret);
            $crc = $arr['short'];
        }

        $crc = $crc ^ $xOrValue;
        $crc = $crc & 0xFFFF;
        return $crc;
    }

	#================================================================================================
    private function smlCheckCrc($smlPaketHex)
	#================================================================================================
    {
        $result = false;

        if(strlen($smlPaketHex)>0){
            $smlPaket = hex2bin($smlPaketHex);

            $crc = $this->crc16(substr($smlPaket,0,-2), 0x1021, 0xffff, 0xffff, true, true);
            $crcHex = (string) sprintf('%04X', $crc);

            if((substr($smlPaketHex, -4,-2)===substr($crcHex, 2)) && (substr($smlPaketHex, -2)===substr($crcHex,0,-2))){
                $result = true;
            }
        }

        return $result;
    }
}

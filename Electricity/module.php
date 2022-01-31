<?php

class SML_Electricity extends IPSModule
{

	#================================================================================================
    public function Create()
	#================================================================================================
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Update', 1);

		#----------------------------------------------------------------------------------------
		# Timer zum Aktualisieren der Daten
		#----------------------------------------------------------------------------------------

		$this->RegisterTimer('Update', 0, 'IPS_RequestAction($_IPS["TARGET"], "OpenFilter", "");');
    }

	#================================================================================================
    public function ApplyChanges()
	#================================================================================================
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->ForceParent('{AC6C6E74-C797-40B3-BA82-F135D941D1A2}');

        $this->SetReceiveDataFilter("");
        $this->SetTimerInterval("Update", $this->ReadPropertyInteger('Update')*1000);
    }

	#================================================================================================
    public function GetConfigurationForParent() 
	#================================================================================================
    {
        return '{
                    "ParseType":0,
                    "LeftCutChar":"",
                    "LeftCutCharAsHex":true,
                    "RightCutChar":"1B 1B 1B 1B 01 01 01 01",
                    "RightCutCharAsHex":true,
                    "DeleteCutChars":false,
                    "InputLength":0,
                    "SyncChar":"",
                    "SyncCharAsHex":false,
                    "Timeout":0
                }';
    }

	#================================================================================================
	public function RequestAction($Ident, $Value) 
	#================================================================================================
	{
		switch($Ident) {
			case "OpenFilter":
                $this->SetReceiveDataFilter("");
				break;
		}
	}

	#================================================================================================
    public function ReceiveData($JSONString)
	#================================================================================================
    {
        $data = json_decode($JSONString);
        $this->SendDebug("Received", utf8_decode($data->Buffer), 1);

        $this->SetBuffer('Record', $this->Str2Hex(utf8_decode($data->Buffer)));
        $this->SetBuffer('Position', 0);

        for($i = 0; $i < 3; $i++){
            if(hexdec(substr($this->GetBuffer('Record'), intval($this->GetBuffer('Position')), 1)) == 7){
                $this->GetList();
            }else{
                break;
            }
        }
        if($i>2) $this->SetReceiveDataFilter(".*BLOCKED.*");
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
            if($Typ > 0 && $Typ < 96){
                $Index = $this->Index(substr($array[0], 4,6));

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
                $this->AddValue($Index, round($value, 2), $unit);

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
        for ($i = 0; $i < strlen($string); $i+=2) {
            $index .= hexdec(substr($string,$i,2)).'.';
        }

        return substr($index, 0, -1);
    }

	#================================================================================================
    private function Value($string, $signed)
	#================================================================================================
    {
        $dec = strlen($string)/2;
        $value = hexdec($string);

        if($signed){
            if($dec == 1){
                $value = ($value + pow(2,7))%pow(2,8) - pow(2,7);
            }elseif($dec == 2){
                $value = ($value + pow(2,15))%pow(2,16) - pow(2,15);
            }else{
                $value = ($value + pow(2,31))%pow(2,32) - pow(2,31);
            }
        }

        return $value;
    }

	#================================================================================================
    private function AddValue($Index, $Value, $Profile)
	#================================================================================================
    {
        $this->RegisterVariableFloat(md5($Index), $Index, $Profile);
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
}

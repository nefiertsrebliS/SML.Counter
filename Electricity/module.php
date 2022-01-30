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

        $Items = explode("\x01\x77\x07\x01\x00", utf8_decode($data->Buffer));

        foreach ($Items as $Item) {
            $Typ = $this->Index(substr($Item, 0,1));

            if($Typ == 0 || $Typ > 95) continue;    # keine Zählerdaten

            $Index = $this->Index(substr($Item, 0,3));
            $this->SendDebug($Index, $Item, 1);
            $pos = 3;

            if($this->length($Item, $pos) == 158){
                $pos++;
                $length = $this->length($Item, $pos);
                $pos += $length+1;
                $length = $this->length($Item, $pos);

                # Sonderdaten #########################################################
                if($length == 17){
                    $pos++;
                    $length = $this->length($Item, $pos);
                    $pos += $length+1;
                    $length = $this->length($Item, $pos);
                    $pos += $length;
                }
                $pos++;
                $length = $this->length($Item, $pos);

                # Unit   ##############################################################
                $scaler = 1;
                switch ($this->Value($Item, $pos)) {
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
                $pos += $length+1;
                $length = $this->length($Item, $pos);

                # Scaler ##############################################################
                $scaler *= pow(10, $this->Value($Item, $pos));
                $pos += $length+1;
                $length = $this->length($Item, $pos);

                # Value  ##############################################################
                $value = $this->Value($Item, $pos) * $scaler;
                $this->AddValue($Index, round($value, 2), $unit);

                $this->SendDebug('Result', 'Unit: '.$unit.' Scaler: '.$scaler.' Value: '.$value, 0);
            }
        }

        $this->SetReceiveDataFilter(".*BLOCKED.*");
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

	#================================================================================================
    private function Index($string)
	#================================================================================================
    {
        $index = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $index .= hexdec(sprintf('%02X', ord($string[$i]))).'.';
        }

        return substr($index, 0, -1);
    }

	#================================================================================================
    private function Value($string, $start)
	#================================================================================================
    {
        $dec = hexdec(sprintf('%02X', ord(substr($string, $start, 1))));
        if($dec == 1)return 0;
        if($dec < 98){
            $dec -=81; 
            $hex = substr($string, $start+1,$dec);
            $value = hexdec($this->Str2Hex($hex));
            if($dec == 1){
                $value = ($value + pow(2,7))%pow(2,8) - pow(2,7);
            }elseif($dec == 2){
                $value = ($value + pow(2,15))%pow(2,16) - pow(2,15);
            }else{
                $value = ($value + pow(2,31))%pow(2,32) - pow(2,31);
            }
        }else{
            $hex = substr($string, $start+1,$dec-97);
            $value = hexdec($this->Str2Hex($hex));
        }
        return $value;
    }

	#================================================================================================
    private function length($string, $start)
	#================================================================================================
    {
        $dec = hexdec(sprintf('%02X', ord(substr($string, $start, 1))));
        if($dec == 1){
            $dec = 97;
        }elseif($dec < 98){
            $dec += 16;
        }
        return $dec-97;
    }
}

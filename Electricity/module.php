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
            $Index = $this->Index(substr($Item, 0,3));
            $this->SendDebug($Index, $Item, 1);
            switch ($Index) {
                case '1.8.0':
                case '2.8.0':
                    $result = stristr($Item, chr(0x621E));
                    $scaler = $this->Scaler(substr($result, 2, 1));
                    $value = hexdec($this->Str2Hex(substr($result, 4))) * $scaler / 1000;
                    $this->SendDebug('Value', $value, 0);
                    $this->AddValue($Index, round($value, 2), '~Electricity');
                    break;
                
                case '16.7.0':
                    $result = stristr($Item, chr(0x621B));
                    $scaler = $this->Scaler(substr($result, 2, 1));
                    $value = hexdec($this->Str2Hex(substr($result, 4))) * $scaler;
                    $this->SendDebug('Value', $value, 0);
                    $this->AddValue($Index, $value, '~Watt');
                    break;

                case '32.7.0':
                case '52.7.0':                    
                case '72.7.0':
                    $result = stristr($Item, chr(0x6223));
                    $scaler = $this->Scaler(substr($result, 2, 1));
                    $value = hexdec($this->Str2Hex(substr($result, 4))) * $scaler;
                    $this->SendDebug('Value', $value, 0);
                    $this->RegisterVariableFloat(md5($Index), $Index, '~Volt');
                    $this->AddValue($Index, $value, '~Volt');
                    break;

                case '31.7.0':
                case '51.7.0':                    
                case '71.7.0':
                    $result = stristr($Item, chr(0x6221));
                    $scaler = $this->Scaler(substr($result, 2, 1));
                    $value = hexdec($this->Str2Hex(substr($result, 4))) * $scaler;
                    $this->SendDebug('Value', $value, 0);
                    $this->RegisterVariableFloat(md5($Index), $Index, '~Ampere');
                    $this->AddValue($Index, $value, '~Ampere');
                    break;

                case '81.7.1':
                case '81.7.2':                    
                case '81.7.4':
                case '81.7.15':                    
                case '81.7.26':
                    $result = stristr($Item, chr(0x6208));
                    $scaler = $this->Scaler(substr($result, 2, 1));
                    $value = hexdec($this->Str2Hex(substr($result, 4))) * $scaler;
                    $this->SendDebug('Value', $value, 0);
                    if (!IPS_VariableProfileExists('Angle.EHZ')) {
                        IPS_CreateVariableProfile('Angle.EHZ', 2);
                        IPS_SetVariableProfileIcon('Angle.EHZ', 'Link');
                        IPS_SetVariableProfileText('Angle.EHZ', '', ' °');
                        IPS_SetVariableProfileDigits('Angle.EHZ', 1);
                    }
                    $this->AddValue($Index, $value, 'Angle.EHZ');
                    break;

                case '14.7.0':
                    $result = stristr($Item, chr(0x622C));
                    $scaler = $this->Scaler(substr($result, 2, 1));
                    $value = hexdec($this->Str2Hex(substr($result, 4))) * $scaler;
                    $this->SendDebug('Value', $value, 0);
                    $this->AddValue($Index, $value, '~Hertz.50');
                    break;

                default:
                    break;
            }
        }

        $this->SetReceiveDataFilter(".*BLOCKED.*");
    }

	#================================================================================================
    private function AddValue($Index, $Value, $Profile)
	#================================================================================================
    {
        $this->RegisterVariableFloat(md5($Index), $Index, $Profile);
        if($Value != 0) $this->SetValue(md5($Index), $Value);
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
    private function Scaler($string)
	#================================================================================================
    {
        $dec = hexdec(sprintf('%02X', ord($string)));
        $scaler = $dec == 0?1:pow(10, $dec-256);

        return $scaler;
    }
}

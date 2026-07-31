<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class ChamSysQuickQ extends IPSModuleStrict
{
    use SmartLog;

    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('Playbacks', '[]');
        $this->RegisterPropertyString('Heads', '[]');

        // Profile anlegen
        $this->CreateProfile('CQQ.Intensity', 2, '%', 0, 100, 1, 0, 'Sun');
        $this->CreateProfile('CQQ.Switch', 0, '', 0, 1, 1, 0, 'Power');
        if (!IPS_VariableProfileExists('CQQ.Action')) {
            IPS_CreateVariableProfile('CQQ.Action', 1);
            IPS_SetVariableProfileAssociation('CQQ.Action', 1, 'GO', '', 0x00FF00);
            IPS_SetVariableProfileIcon('CQQ.Action', 'Execute');
        }

        // Master Variable
        $this->RegisterVariableFloat('MasterIntensity', 'Master Fader', '', 0);
        $this->EnableAction('MasterIntensity');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Master Profile setzen
        IPS_SetVariableCustomProfile($this->GetIDForIdent('MasterIntensity'), 'CQQ.Intensity');

        // Playbacks
        $playbacks = json_decode($this->ReadPropertyString('Playbacks'), true);
        if (is_array($playbacks)) {
            foreach ($playbacks as $playback) {
                $id = $playback['ID'];
                $name = $playback['Name'];

                $identIntensity = 'Playback_Intensity_' . $id;
                $this->MaintainVariable($identIntensity, $name . ' Fader', 2, '', 10, true);
                IPS_SetVariableCustomProfile($this->GetIDForIdent($identIntensity), 'CQQ.Intensity');
                $this->EnableAction($identIntensity);

                $identGo = 'Playback_Go_' . $id;
                $this->MaintainVariable($identGo, $name . ' Go', 1, '', 11, true);
                IPS_SetVariableCustomProfile($this->GetIDForIdent($identGo), 'CQQ.Action');
                $this->EnableAction($identGo);
                
                $identRelease = 'Playback_Release_' . $id;
                $this->MaintainVariable($identRelease, $name . ' Release', 1, '', 12, true);
                IPS_SetVariableCustomProfile($this->GetIDForIdent($identRelease), 'CQQ.Action');
                $this->EnableAction($identRelease);
            }
        }

        // Heads
        $heads = json_decode($this->ReadPropertyString('Heads'), true);
        if (is_array($heads)) {
            foreach ($heads as $head) {
                $id = $head['ID'];
                $name = $head['Name'];

                $ident = 'Head_Intensity_' . $id;
                $this->MaintainVariable($ident, $name . ' Intensität', 2, '', 20, true);
                IPS_SetVariableCustomProfile($this->GetIDForIdent($ident), 'CQQ.Intensity');
                $this->EnableAction($ident);
            }
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'MasterIntensity') {
            $this->SetValue($Ident, $Value);
            $this->SendOSCFloat('/grand/fader', max(0.0, min(1.0, $Value / 100.0)));
            return;
        }

        if (strpos($Ident, 'Playback_Intensity_') === 0) {
            $id = (int)str_replace('Playback_Intensity_', '', $Ident);
            $this->SetValue($Ident, $Value);
            $this->SendOSCFloat("/pb/{$id}/fader", max(0.0, min(1.0, $Value / 100.0)));
            return;
        }

        if (strpos($Ident, 'Playback_Go_') === 0) {
            $id = (int)str_replace('Playback_Go_', '', $Ident);
            $this->SendOSCTrigger("/pb/{$id}/go");
            return;
        }

        if (strpos($Ident, 'Playback_Release_') === 0) {
            $id = (int)str_replace('Playback_Release_', '', $Ident);
            $this->SendOSCTrigger("/pb/{$id}/release");
            return;
        }

        if (strpos($Ident, 'Head_Intensity_') === 0) {
            $id = (int)str_replace('Head_Intensity_', '', $Ident);
            $this->SetValue($Ident, $Value);
            $this->SendOSCFloat("/head/{$id}/intensity", max(0.0, min(1.0, $Value / 100.0)));
            return;
        }

        $this->SmartLog("Unknown RequestAction Ident: $Ident", "ERROR");
    }

    public function PlaybackGo(int $pbNumber): void
    {
        $this->SendOSCTrigger("/pb/{$pbNumber}/go");
    }

    public function PlaybackRelease(int $pbNumber): void
    {
        $this->SendOSCTrigger("/pb/{$pbNumber}/release");
    }

    public function SetPlaybackFader(int $pbNumber, float $level): void
    {
        $this->SendOSCFloat("/pb/{$pbNumber}/fader", max(0.0, min(1.0, $level)));
    }

    public function SetHeadIntensity(int $headId, float $percent): void
    {
        $this->SendOSCFloat("/head/{$headId}/intensity", max(0.0, min(1.0, $percent / 100.0)));
    }

    public function SetHeadColorRGB(int $headId, int $r, int $g, int $b): void
    {
        $rFloat = max(0.0, min(1.0, $r / 255.0));
        $gFloat = max(0.0, min(1.0, $g / 255.0));
        $bFloat = max(0.0, min(1.0, $b / 255.0));
        
        $this->SendOSCFloat("/head/{$headId}/col/r", $rFloat);
        $this->SendOSCFloat("/head/{$headId}/col/g", $gFloat);
        $this->SendOSCFloat("/head/{$headId}/col/b", $bFloat);
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString);
        $buffer = utf8_decode($data->Buffer);
        
        $address = strtok($buffer, "\0");
        $this->SmartLog("Received OSC from QuickQ: $address", "INFO");
        
        return "";
    }

    private function SendOSCFloat(string $address, float $value): void
    {
        $buf = $address . "\0";
        while (strlen($buf) % 4 !== 0) { $buf .= "\0"; }
        
        $buf .= ",f\0\0";
        $buf .= pack("G", $value); // Big-Endian 32-bit Float
        
        if ($this->HasActiveParent()) {
            $this->SendDataToParent(json_encode([
                'DataID' => '{C87D040B-E261-4191-B739-122B48A8D521}', // UDP TX GUID
                'Buffer' => utf8_encode($buf)
            ]));
        }
    }

    private function SendOSCTrigger(string $address): void
    {
        $buf = $address . "\0";
        while (strlen($buf) % 4 !== 0) { $buf .= "\0"; }
        
        $buf .= ",\0\0\0"; 
        
        if ($this->HasActiveParent()) {
            $this->SendDataToParent(json_encode([
                'DataID' => '{C87D040B-E261-4191-B739-122B48A8D521}', // UDP TX GUID
                'Buffer' => utf8_encode($buf)
            ]));
        }
    }

    private function CreateProfile(string $Name, int $ProfileType, string $Suffix, int $MinValue, int $MaxValue, int $StepSize, int $Digits, string $Icon): void 
    {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, $ProfileType);
        }
        IPS_SetVariableProfileText($Name, '', $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
        IPS_SetVariableProfileDigits($Name, $Digits);
        IPS_SetVariableProfileIcon($Name, $Icon);
    }
}

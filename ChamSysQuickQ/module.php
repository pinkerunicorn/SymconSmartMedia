<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class ChamSysQuickQ extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('Playbacks', '[]');
        $this->RegisterPropertyString('Heads', '[]');
        
        $this->DA_RegisterAvailability(900);
        $this->DA_RegisterWatchdog();

        // Master Variable
        $this->RegisterVariableFloat('MasterIntensity', 'Master Fader', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Sun',
            'SUFFIX' => '%',
            'MINVALUE' => 0,
            'MAXVALUE' => 100,
            'STEP' => 1
        ], 0);
        $this->EnableAction('MasterIntensity');
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'require',
            'modules' => [
                [
                    'moduleID' => '{82347F20-F541-41E1-AC5B-A636FD3AE2D8}'
                ]
            ]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // Playbacks
        $playbacks = json_decode($this->ReadPropertyString('Playbacks'), true);
        if (is_array($playbacks)) {
            foreach ($playbacks as $playback) {
                $id = $playback['ID'];
                $name = $playback['Name'];

                $identIntensity = 'Playback_Intensity_' . $id;
                $this->RegisterVariableFloat($identIntensity, $name . ' Fader', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'Sun',
                    'SUFFIX' => '%',
                    'MINVALUE' => 0,
                    'MAXVALUE' => 100,
                    'STEP' => 1
                ], 10);
                $this->EnableAction($identIntensity);

                $identGo = 'Playback_Go_' . $id;
                $this->RegisterVariableInteger($identGo, $name . ' Go', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Execute',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'GO', 'IconActive' => false, 'IconValue' => '', 'Color' => 0x00FF00]
                    ])
                ], 11);
                $this->EnableAction($identGo);
                
                $identRelease = 'Playback_Release_' . $id;
                $this->RegisterVariableInteger($identRelease, $name . ' Release', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Execute',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'RELEASE', 'IconActive' => false, 'IconValue' => '', 'Color' => 0x00FF00]
                    ])
                ], 12);
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
                $this->RegisterVariableFloat($ident, $name . ' Intensität', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'Sun',
                    'SUFFIX' => '%',
                    'MINVALUE' => 0,
                    'MAXVALUE' => 100,
                    'STEP' => 1
                ], 20);
                $this->EnableAction($ident);
            }
        }

        // Legacy-Profile bereinigen
        if (IPS_VariableProfileExists('CQQ.Intensity')) {
            IPS_DeleteVariableProfile('CQQ.Intensity');
        }
        if (IPS_VariableProfileExists('CQQ.Switch')) {
            IPS_DeleteVariableProfile('CQQ.Switch');
        }
        if (IPS_VariableProfileExists('CQQ.Action')) {
            IPS_DeleteVariableProfile('CQQ.Action');
        }

    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'DA_Watchdog') {
            $this->DA_HandleWatchdog();
            return;
        }

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

        $this->SLogError("Unknown RequestAction Ident: $Ident");
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

    public function ReceiveData(string $JSONString): string
    {
        $this->DA_SetAvailable(true);
        $this->DA_ResetWatchdog(300);
        
        $data = json_decode($JSONString);
        $buffer = mb_convert_encoding($data->Buffer, 'ISO-8859-1', 'UTF-8');
        
        $address = strtok($buffer, "\0");
        $this->SLogInfo("Received OSC from QuickQ: $address");
        
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
                'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', // I/O TX GUID
                'Buffer' => mb_convert_encoding($buf, 'UTF-8', 'ISO-8859-1')
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
                'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', // I/O TX GUID
                'Buffer' => mb_convert_encoding($buf, 'UTF-8', 'ISO-8859-1')
            ]));
        }
    }

}

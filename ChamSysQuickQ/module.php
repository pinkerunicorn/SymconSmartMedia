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

        $this->DA_RegisterAvailability(900);
        $this->DA_RegisterWatchdog();
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

        // Playbacks dynamisch anlegen
        $playbacks = json_decode($this->ReadPropertyString('Playbacks'), true);
        if (is_array($playbacks)) {
            foreach ($playbacks as $index => $playback) {
                $id = $playback['ID'];
                $name = $playback['Name'];
                $basePos = 10 + ($index * 10);

                // Fader (0-100%)
                $identFader = 'PB_Fader_' . $id;
                $this->RegisterVariableFloat($identFader, $name . ' Fader', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'Intensity',
                    'SUFFIX' => '%',
                    'MINVALUE' => 0,
                    'MAXVALUE' => 100,
                    'STEP' => 1
                ], $basePos);
                $this->EnableAction($identFader);

                // Go Button
                $identGo = 'PB_Go_' . $id;
                $this->RegisterVariableInteger($identGo, $name . ' Go', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Execute',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'GO', 'IconActive' => true, 'IconValue' => 'Execute', 'Color' => 0x00CC00]
                    ])
                ], $basePos + 1);
                $this->EnableAction($identGo);

                // Flash Button
                $identFlash = 'PB_Flash_' . $id;
                $this->RegisterVariableInteger($identFlash, $name . ' Flash', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Electricity',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'FLASH', 'IconActive' => true, 'IconValue' => 'Electricity', 'Color' => 0xFFAA00]
                    ])
                ], $basePos + 2);
                $this->EnableAction($identFlash);
            }
        }

        // Legacy-Profile bereinigen
        foreach (['CQQ.Intensity', 'CQQ.Switch', 'CQQ.Action'] as $profile) {
            if (IPS_VariableProfileExists($profile)) {
                IPS_DeleteVariableProfile($profile);
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'DA_Watchdog') {
            $this->DA_HandleWatchdog();
            return;
        }

        // Fader: /pb/<id> float 0.0-1.0
        if (str_starts_with($Ident, 'PB_Fader_')) {
            $id = (int)str_replace('PB_Fader_', '', $Ident);
            $this->SetValue($Ident, $Value);
            $this->SendOSCFloat("/pb/{$id}", max(0.0, min(1.0, (float)$Value / 100.0)));
            $this->SendDebug('Playback Fader', "PB {$id} -> " . round((float)$Value, 1) . '%', 0);
            return;
        }

        // Go: /pb/<id>/go float 1.0
        if (str_starts_with($Ident, 'PB_Go_')) {
            $id = (int)str_replace('PB_Go_', '', $Ident);
            $this->SendOSCFloat("/pb/{$id}/go", 1.0);
            $this->SendDebug('Playback Go', "PB {$id}", 0);
            return;
        }

        // Flash: /pb/<id>/flash float 1.0
        if (str_starts_with($Ident, 'PB_Flash_')) {
            $id = (int)str_replace('PB_Flash_', '', $Ident);
            $this->SendOSCFloat("/pb/{$id}/flash", 1.0);
            $this->SendDebug('Playback Flash', "PB {$id}", 0);
            return;
        }

        $this->SLogError("Unbekannte Aktion: $Ident");
    }

    // --- Öffentliche Funktionen für Skript-Zugriff ---

    public function PlaybackGo(int $pbNumber): void
    {
        $this->SendOSCFloat("/pb/{$pbNumber}/go", 1.0);
    }

    public function PlaybackFlash(int $pbNumber): void
    {
        $this->SendOSCFloat("/pb/{$pbNumber}/flash", 1.0);
    }

    public function SetPlaybackFader(int $pbNumber, float $level): void
    {
        $this->SendOSCFloat("/pb/{$pbNumber}", max(0.0, min(1.0, $level)));
    }

    // --- Empfang vom Pult ---

    public function ReceiveData(string $JSONString): string
    {
        $this->DA_SetAvailable(true);
        $this->DA_ResetWatchdog(300);

        $data = json_decode($JSONString);
        $buffer = mb_convert_encoding($data->Buffer, 'ISO-8859-1', 'UTF-8');

        $address = strtok($buffer, "\0");
        $this->SendDebug('OSC Empfangen', $address, 0);
        $this->SLogInfo("OSC vom QuickQ: $address");

        return "";
    }

    // --- OSC Encoding ---

    private function SendOSCFloat(string $address, float $value): void
    {
        // OSC Address (null-terminated, padded to 4-byte boundary)
        $buf = $address . "\0";
        while (strlen($buf) % 4 !== 0) { $buf .= "\0"; }

        // OSC Type Tag (float)
        $buf .= ",f\0\0";

        // OSC Float Value (Big-Endian 32-bit)
        $buf .= pack("G", $value);

        if ($this->HasActiveParent()) {
            $this->SendDataToParent(json_encode([
                'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
                'Buffer' => mb_convert_encoding($buf, 'UTF-8', 'ISO-8859-1')
            ]));
        }
    }

}

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

                // Fader (0-100%) – direktes Steuern des Playback-Levels
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

                // Effektwert (0-100%) – Zielwert für Flash
                $identEffect = 'PB_Effect_' . $id;
                $this->RegisterVariableFloat($identEffect, $name . ' Effektwert', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'Fog',
                    'SUFFIX' => '%',
                    'MINVALUE' => 0,
                    'MAXVALUE' => 100,
                    'STEP' => 1
                ], $basePos + 1);
                $this->EnableAction($identEffect);

                // Haltezeit (0-5s in 0.1s Schritten)
                $identHoldTime = 'PB_HoldTime_' . $id;
                $this->RegisterVariableFloat($identHoldTime, $name . ' Haltezeit', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'Clock',
                    'SUFFIX' => 's',
                    'MINVALUE' => 0,
                    'MAXVALUE' => 5,
                    'STEP' => 0.1
                ], $basePos + 2);
                $this->EnableAction($identHoldTime);

                // Go Button
                $identGo = 'PB_Go_' . $id;
                $this->RegisterVariableInteger($identGo, $name . ' Go', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Execute',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'GO', 'IconActive' => true, 'IconValue' => 'Execute', 'Color' => 0x00CC00]
                    ])
                ], $basePos + 3);
                $this->EnableAction($identGo);

                // Flash Button (Shot: Effektwert halten, dann zurück auf 0%)
                $identFlash = 'PB_Flash_' . $id;
                $this->RegisterVariableInteger($identFlash, $name . ' Flash', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Electricity',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'FLASH', 'IconActive' => true, 'IconValue' => 'Electricity', 'Color' => 0xFFAA00]
                    ])
                ], $basePos + 4);
                $this->EnableAction($identFlash);

                // Shot-Timer (für Haltezeit, initial deaktiviert)
                $this->RegisterTimer('ShotTimer_' . $id, 0, 'CQQ_ShotEnd($_IPS[\'TARGET\'], ' . $id . ');');
            }
        }

        // Legacy-Variablen bereinigen (aus alter Version)
        $legacyVars = ['MasterIntensity'];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 2) { // Variable
                $ident = $obj['ObjectIdent'];
                if (in_array($ident, $legacyVars)
                    || str_starts_with($ident, 'Head_Intensity_')
                    || str_starts_with($ident, 'Playback_Intensity_')
                    || str_starts_with($ident, 'Playback_Go_')
                    || str_starts_with($ident, 'Playback_Release_')
                    || str_starts_with($ident, 'PB_Fade_')
                    || str_starts_with($ident, 'PB_FadeTime_')
                ) {
                    $this->SendDebug('Cleanup', "Lösche Legacy-Variable: $ident (ID: $childID)", 0);
                    IPS_DeleteVariable($childID);
                }
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

        // Fader: Sofort setzen
        if (str_starts_with($Ident, 'PB_Fader_')) {
            $id = (int)str_replace('PB_Fader_', '', $Ident);
            $this->SetValue($Ident, $Value);
            $this->SendOSCFloat("/pb/{$id}", max(0.0, min(1.0, (float)$Value / 100.0)));
            return;
        }

        // Effektwert: Nur Wert speichern
        if (str_starts_with($Ident, 'PB_Effect_')) {
            $this->SetValue($Ident, $Value);
            return;
        }

        // Haltezeit: Nur Wert speichern
        if (str_starts_with($Ident, 'PB_HoldTime_')) {
            $this->SetValue($Ident, $Value);
            return;
        }

        // Go
        if (str_starts_with($Ident, 'PB_Go_')) {
            $id = (int)str_replace('PB_Go_', '', $Ident);
            $this->SendOSCFloat("/pb/{$id}/go", 1.0);
            return;
        }

        // Flash: Shot-Effekt (Effektwert setzen → Haltezeit warten → zurück auf 0%)
        if (str_starts_with($Ident, 'PB_Flash_')) {
            $id = (int)str_replace('PB_Flash_', '', $Ident);
            $this->StartShot($id);
            return;
        }

        $this->SLogError("Unbekannte Aktion: $Ident");
    }

    // --- Shot-Logik (Flash mit Haltezeit) ---

    private function StartShot(int $pbId): void
    {
        $effectValue = $this->GetValue('PB_Effect_' . $pbId);
        $holdTime = $this->GetValue('PB_HoldTime_' . $pbId);

        // Sofort auf Effektwert setzen
        $this->SendOSCFloat("/pb/{$pbId}", max(0.0, min(1.0, $effectValue / 100.0)));
        $this->SetValue('PB_Fader_' . $pbId, $effectValue);
        $this->SendDebug('Shot', "PB {$pbId}: ON bei {$effectValue}% (Haltezeit: {$holdTime}s)", 0);

        if ($holdTime <= 0) {
            // Keine Haltezeit → bleibt auf dem Wert stehen
            return;
        }

        // Timer starten: nach Haltezeit auf 0% zurücksetzen
        $holdTimeMs = max(1, (int)round($holdTime * 1000));
        $this->SetTimerInterval('ShotTimer_' . $pbId, $holdTimeMs);
    }

    public function ShotEnd(int $pbId): void
    {
        // Timer deaktivieren (einmalig)
        $this->SetTimerInterval('ShotTimer_' . $pbId, 0);

        // Playback auf 0% zurücksetzen
        $this->SendOSCFloat("/pb/{$pbId}", 0.0);
        $this->SetValue('PB_Fader_' . $pbId, 0.0);
        $this->SendDebug('Shot', "PB {$pbId}: OFF (zurück auf 0%)", 0);
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

    public function ShotPlayback(int $pbNumber, float $effectPercent, float $holdTimeSec): void
    {
        $this->SetValue('PB_Effect_' . $pbNumber, $effectPercent);
        $this->SetValue('PB_HoldTime_' . $pbNumber, $holdTimeSec);
        $this->StartShot($pbNumber);
    }

    // --- Empfang vom Pult ---

    public function ReceiveData(string $JSONString): string
    {
        $this->DA_SetAvailable(true);
        $this->DA_ResetWatchdog(300);

        $data = json_decode($JSONString);
        $buffer = hex2bin($data->Buffer);

        $address = strtok($buffer, "\0");
        $this->SendDebug('OSC Empfangen', $address, 0);

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
                'Buffer' => bin2hex($buf)
            ]));
        }
    }

}

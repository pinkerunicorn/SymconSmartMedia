<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class ChamSysQuickQ extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    private const FADE_INTERVAL_MS = 50; // Timer-Intervall in ms

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

                // Fade-Zeit (0-30 Sekunden)
                $identFadeTime = 'PB_FadeTime_' . $id;
                $this->RegisterVariableFloat($identFadeTime, $name . ' Fadezeit', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'Clock',
                    'SUFFIX' => 's',
                    'MINVALUE' => 0,
                    'MAXVALUE' => 30,
                    'STEP' => 0.5
                ], $basePos + 1);
                $this->EnableAction($identFadeTime);

                // Fade-Button
                $identFade = 'PB_Fade_' . $id;
                $this->RegisterVariableInteger($identFade, $name . ' Fade starten', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Rocket',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'FADE', 'IconActive' => true, 'IconValue' => 'Rocket', 'Color' => 0x3399FF]
                    ])
                ], $basePos + 2);
                $this->EnableAction($identFade);

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

                // Flash Button
                $identFlash = 'PB_Flash_' . $id;
                $this->RegisterVariableInteger($identFlash, $name . ' Flash', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'Electricity',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'FLASH', 'IconActive' => true, 'IconValue' => 'Electricity', 'Color' => 0xFFAA00]
                    ])
                ], $basePos + 4);
                $this->EnableAction($identFlash);

                // Fade-Timer registrieren (initial deaktiviert)
                $this->RegisterTimer('FadeTimer_' . $id, 0, 'CQQ_FadeTick($_IPS[\'TARGET\'], ' . $id . ');');
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

        // Fade-Zeit: Nur Wert speichern
        if (str_starts_with($Ident, 'PB_FadeTime_')) {
            $this->SetValue($Ident, $Value);
            return;
        }

        // Fade starten
        if (str_starts_with($Ident, 'PB_Fade_')) {
            $id = (int)str_replace('PB_Fade_', '', $Ident);
            $this->StartFade($id);
            return;
        }

        // Go
        if (str_starts_with($Ident, 'PB_Go_')) {
            $id = (int)str_replace('PB_Go_', '', $Ident);
            $this->SendOSCFloat("/pb/{$id}/go", 1.0);
            return;
        }

        // Flash
        if (str_starts_with($Ident, 'PB_Flash_')) {
            $id = (int)str_replace('PB_Flash_', '', $Ident);
            $this->SendOSCFloat("/pb/{$id}/flash", 1.0);
            return;
        }

        $this->SLogError("Unbekannte Aktion: $Ident");
    }

    // --- Fade-Logik ---

    private function StartFade(int $pbId): void
    {
        $fadeTime = $this->GetValue('PB_FadeTime_' . $pbId);
        $targetValue = $this->GetValue('PB_Fader_' . $pbId);

        if ($fadeTime <= 0) {
            // Kein Fade, sofort setzen
            $this->SendOSCFloat("/pb/{$pbId}", max(0.0, min(1.0, $targetValue / 100.0)));
            $this->SendDebug('Fade', "PB {$pbId} -> {$targetValue}% (sofort)", 0);
            return;
        }

        // Aktuellen Ist-Wert vom Pult holen (= letzter gesendeter Wert)
        // Wir lesen den Buffer, falls ein Fade schon läuft, nehmen wir den aktuellen Zwischenstand
        $fadeState = json_decode($this->GetBuffer('FadeState_' . $pbId), true);
        if (is_array($fadeState) && isset($fadeState['current'])) {
            $startValue = $fadeState['current'];
        } else {
            // Kein laufender Fade - wir starten bei 0 wenn der Fader auf einen Wert gesetzt ist
            $startValue = 0.0;
        }

        $totalSteps = max(1, (int)round($fadeTime * 1000 / self::FADE_INTERVAL_MS));

        $fadeState = [
            'pbId' => $pbId,
            'start' => $startValue,
            'target' => $targetValue,
            'current' => $startValue,
            'step' => 0,
            'totalSteps' => $totalSteps
        ];

        $this->SetBuffer('FadeState_' . $pbId, json_encode($fadeState));
        $this->SetTimerInterval('FadeTimer_' . $pbId, self::FADE_INTERVAL_MS);

        $this->SendDebug('Fade', "PB {$pbId}: {$startValue}% -> {$targetValue}% in {$fadeTime}s ({$totalSteps} Schritte)", 0);
    }

    public function FadeTick(int $pbId): void
    {
        $fadeState = json_decode($this->GetBuffer('FadeState_' . $pbId), true);
        if (!is_array($fadeState)) {
            $this->SetTimerInterval('FadeTimer_' . $pbId, 0);
            return;
        }

        $fadeState['step']++;
        $progress = min(1.0, $fadeState['step'] / $fadeState['totalSteps']);

        // Lineare Interpolation
        $currentValue = $fadeState['start'] + ($fadeState['target'] - $fadeState['start']) * $progress;
        $fadeState['current'] = $currentValue;

        // An Pult senden
        $this->SendOSCFloat("/pb/{$pbId}", max(0.0, min(1.0, $currentValue / 100.0)));

        // Fader-Variable im WebFront live mitziehen
        $this->SetValue('PB_Fader_' . $pbId, round($currentValue, 1));

        if ($progress >= 1.0) {
            // Fade fertig
            $this->SetTimerInterval('FadeTimer_' . $pbId, 0);
            $this->SetBuffer('FadeState_' . $pbId, '');
            $this->SendDebug('Fade', "PB {$pbId}: Fertig bei {$currentValue}%", 0);
        } else {
            $this->SetBuffer('FadeState_' . $pbId, json_encode($fadeState));
        }
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

    public function FadePlayback(int $pbNumber, float $targetPercent, float $fadeTimeSec): void
    {
        $this->SetValue('PB_Fader_' . $pbNumber, $targetPercent);
        $this->SetValue('PB_FadeTime_' . $pbNumber, $fadeTimeSec);
        $this->StartFade($pbNumber);
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

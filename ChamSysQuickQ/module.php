<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class ChamSysQuickQ extends IPSModuleStrict
{
    use DeviceRegistration_Trait;
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

        // Zentraler Shot-Timer für alle Playbacks (ausschließlich in Create registrieren!)
        $this->RegisterTimer('ShotTimer', 0, 'CQQ_ShotTimerTick($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        parent::Destroy();
        $this->DR_Unregister();
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

                // Fader (0-100%) – direktes Steuern und bidirektionale Rückmeldung
                $identFader = 'PB_Fader_' . $id;
                $this->RegisterVariableFloat($identFader, $name . ' Fader', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'sliders',
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
                    'ICON' => 'smog',
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
                    'ICON' => 'clock',
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
                    'ICON' => 'play',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'GO', 'IconActive' => true, 'IconValue' => 'play', 'Color' => 0x00CC00]
                    ])
                ], $basePos + 3);
                $this->EnableAction($identGo);

                // Flash Button (Shot: Effektwert halten, dann zurück auf 0%)
                $identFlash = 'PB_Flash_' . $id;
                $this->RegisterVariableInteger($identFlash, $name . ' Flash', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON' => 'bolt',
                    'OPTIONS' => json_encode([
                        ['Value' => 1, 'Caption' => 'FLASH', 'IconActive' => true, 'IconValue' => 'bolt', 'Color' => 0xFFAA00]
                    ])
                ], $basePos + 4);
                $this->EnableAction($identFlash);
        $this->DR_Register('DevicesGenericSensor');
            }
        }

        // Heads (Lampen) dynamisch anlegen
        $heads = json_decode($this->ReadPropertyString('Heads'), true);
        if (is_array($heads)) {
            foreach ($heads as $index => $head) {
                $id = $head['ID'];
                $name = $head['Name'];
                $basePos = 200 + ($index * 10);

                $ident = 'Head_Intensity_' . $id;
                $this->RegisterVariableFloat($ident, $name . ' Intensitaet', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'ICON' => 'sun',
                    'SUFFIX' => '%',
                    'MINVALUE' => 0,
                    'MAXVALUE' => 100,
                    'STEP' => 1
                ], $basePos);
                $this->EnableAction($ident);
            }
        }

        // Legacy-Variablen bereinigen (aus alten Alpha-Versionen)
        $legacyVars = ['MasterIntensity'];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 2) { // Variable
                $ident = $obj['ObjectIdent'];
                if (in_array($ident, $legacyVars)
                    || str_starts_with($ident, 'Playback_Intensity_')
                    || str_starts_with($ident, 'Playback_Go_')
                    || str_starts_with($ident, 'Playback_Release_')
                    || str_starts_with($ident, 'PB_Fade_')
                    || str_starts_with($ident, 'PB_FadeTime_')
                ) {
                    $this->SendDebug('Cleanup', "Loesche Legacy-Variable: $ident (ID: $childID)", 0);
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

        // Feedback-Stream vom QuickQ anfordern
        if ($this->HasActiveParent()) {
            $this->RequestFeedback();
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
            $this->SetValue($ident, $value);
            $this->SendOSCFloat("/pb/{$id}", max(0.0, min(1.0, (float)$Value / 100.0)));
            return;
        }

        // Effektwert: Nur Wert speichern
        if (str_starts_with($Ident, 'PB_Effect_')) {
            $this->SetValue($ident, $value);
            return;
        }

        // Haltezeit: Nur Wert speichern
        if (str_starts_with($Ident, 'PB_HoldTime_')) {
            $this->SetValue($ident, $value);
            return;
        }

        // Go
        if (str_starts_with($Ident, 'PB_Go_')) {
            $id = (int)str_replace('PB_Go_', '', $Ident);
            $this->SendOSCFloat("/pb/{$id}/go", 1.0);
            return;
        }

        // Flash: Shot-Effekt (Effektwert setzen -> Haltezeit warten -> zurück auf 0%)
        if (str_starts_with($Ident, 'PB_Flash_')) {
            $id = (int)str_replace('PB_Flash_', '', $Ident);
            $this->StartShot($id);
            return;
        }

        // Head Intensity
        if (str_starts_with($Ident, 'Head_Intensity_')) {
            $id = (int)str_replace('Head_Intensity_', '', $Ident);
            $this->SetValue($ident, $value);
            $this->SendOSCFloat("/head/{$id}/intensity", max(0.0, min(1.0, (float)$Value / 100.0)));
            return;
        }

        $this->SLogError("Unbekannte Aktion: $Ident");
    }

    // --- Shot-Logik (Flash mit Haltezeit via zentralem ShotTimer) ---

    private function StartShot(int $pbId): void
    {
        $effectValue = (float)$this->GetValue('PB_Effect_' . $pbId);
        $holdTime = (float)$this->GetValue('PB_HoldTime_' . $pbId);

        // Sofort auf Effektwert setzen
        $this->SendOSCFloat("/pb/{$pbId}", max(0.0, min(1.0, $effectValue / 100.0)));
        $this->SetValueIfChanged('PB_Fader_' . $pbId, $effectValue);
        $this->SendDebug('Shot', "PB {$pbId}: ON bei {$effectValue}% (Haltezeit: {$holdTime}s)", 0);

        if ($holdTime <= 0) {
            // Keine Haltezeit -> bleibt auf dem Wert stehen
            return;
        }

        // In die Liste aktiver Shots eintragen
        $activeShots = json_decode($this->GetBuffer('ActiveShots'), true);
        if (!is_array($activeShots)) {
            $activeShots = [];
        }

        $activeShots[(string)$pbId] = microtime(true) + $holdTime;
        $this->SetBuffer('ActiveShots', json_encode($activeShots));

        // Zentralen Timer mit 50ms Intervall aktivieren
        $this->SetTimerInterval('ShotTimer', 50);
    }

    public function ShotTimerTick(): void
    {
        $activeShots = json_decode($this->GetBuffer('ActiveShots'), true);
        if (!is_array($activeShots) || empty($activeShots)) {
            $this->SetTimerInterval('ShotTimer', 0);
            return;
        }

        $now = microtime(true);
        $remainingShots = [];

        foreach ($activeShots as $pbIdStr => $endTime) {
            $pbId = (int)$pbIdStr;
            if ($now >= $endTime) {
                // Playback auf 0% zurücksetzen
                $this->SendOSCFloat("/pb/{$pbId}", 0.0);
                if (@$this->GetIDForIdent('PB_Fader_' . $pbId)) {
                    $this->SetValueIfChanged('PB_Fader_' . $pbId, 0.0);
                }
                $this->SendDebug('Shot', "PB {$pbId}: OFF (Haltezeit abgelaufen)", 0);
            } else {
                $remainingShots[$pbIdStr] = $endTime;
            }
        }

        $this->SetBuffer('ActiveShots', json_encode($remainingShots));

        if (empty($remainingShots)) {
            $this->SetTimerInterval('ShotTimer', 0);
        }
    }

    public function ShotEnd(int $pbId): void
    {
        $activeShots = json_decode($this->GetBuffer('ActiveShots'), true);
        if (is_array($activeShots) && isset($activeShots[(string)$pbId])) {
            unset($activeShots[(string)$pbId]);
            $this->SetBuffer('ActiveShots', json_encode($activeShots));
            if (empty($activeShots)) {
                $this->SetTimerInterval('ShotTimer', 0);
            }
        }

        // Playback auf 0% zurücksetzen
        $this->SendOSCFloat("/pb/{$pbId}", 0.0);
        if (@$this->GetIDForIdent('PB_Fader_' . $pbId)) {
            $this->SetValueIfChanged('PB_Fader_' . $pbId, 0.0);
        }
        $this->SendDebug('Shot', "PB {$pbId}: OFF (manuell beendet)", 0);
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

    public function SetHeadIntensity(int $headId, float $percent): void
    {
        $this->SendOSCFloat("/head/{$headId}/intensity", max(0.0, min(1.0, $percent / 100.0)));
    }

    public function ShotPlayback(int $pbNumber, float $effectPercent, float $holdTimeSec): void
    {
        $this->SetValueIfChanged('PB_Effect_' . $pbNumber, $effectPercent);
        $this->SetValueIfChanged('PB_HoldTime_' . $pbNumber, $holdTimeSec);
        $this->StartShot($pbNumber);
    }

    public function RequestFeedback(): void
    {
        // Aktiviert das Senden von Statusänderungen (Fader, Tasten) am QuickQ
        $this->SendOSCCommand('/feedback/pb+exec');
        $this->SendOSCCommand('/feedback/pb');
        $this->SendDebug('OSC Feedback', 'Feedback-Stream angefordert (/feedback/pb)', 0);
    }

    // --- Empfang und Dekodierung vom Pult (Bidirektional) ---

    public function ReceiveData(string $JSONString): string
    {
        $hash = md5($JSONString);
        if ($this->GetBuffer('LastPayloadHash') === $hash) {
            return "OK";
        }
        $this->SetBuffer('LastPayloadHash', $hash);

        $this->DA_SetAvailable(true);
        $this->DA_ResetWatchdog(300);

        $data = json_decode($JSONString);
        if (!isset($data->Buffer)) {
            return "";
        }

        $buffer = hex2bin($data->Buffer);
        if ($buffer === false || strlen($buffer) === 0) {
            return "";
        }

        $parsed = $this->ParseOSCMessage($buffer);
        if ($parsed === null) {
            return "";
        }

        $address = $parsed['address'];
        $args = $parsed['args'];

        $this->SendDebug('OSC Empfangen', $address . ' ' . json_encode($args), 0);

        // Feedback für Playback Fader (z.B. /pb/1, /pb/1/fader, /playback/1)
        if (preg_match('#^/(?:pb|playback)/(\d+)(?:/fader)?$#i', $address, $matches)) {
            $pbId = (int)$matches[1];
            $ident = 'PB_Fader_' . $pbId;
            if (@$this->GetIDForIdent($ident)) {
                $rawVal = $args[0] ?? 0.0;
                if (is_float($rawVal) || is_numeric($rawVal)) {
                    $valFloat = (float)$rawVal;
                    // Wenn Wert im Bereich 0.0 - 1.0 liegt -> in % (0 - 100) umrechnen
                    $percent = ($valFloat <= 1.0 && $valFloat >= 0.0) ? round($valFloat * 100.0, 1) : $valFloat;
                    $currentVal = (float)$this->GetValue($ident);
                    if (abs($currentVal - $percent) >= 0.1) {
                        $this->SetValueIfChanged($ident, $percent);
                        $this->SendDebug('Feedback Update', "Fader PB {$pbId} aktualisiert auf {$percent}%", 0);
                    }
                }
            }
        }

        // Feedback für Heads (z.B. /head/1, /head/1/intensity)
        if (preg_match('#^/head/(\d+)(?:/intensity)?$#i', $address, $matches)) {
            $headId = (int)$matches[1];
            $ident = 'Head_Intensity_' . $headId;
            if (@$this->GetIDForIdent($ident)) {
                $rawVal = $args[0] ?? 0.0;
                if (is_float($rawVal) || is_numeric($rawVal)) {
                    $valFloat = (float)$rawVal;
                    $percent = ($valFloat <= 1.0 && $valFloat >= 0.0) ? round($valFloat * 100.0, 1) : $valFloat;
                    $currentVal = (float)$this->GetValue($ident);
                    if (abs($currentVal - $percent) >= 0.1) {
                        $this->SetValueIfChanged($ident, $percent);
                        $this->SendDebug('Feedback Update', "Head {$headId} aktualisiert auf {$percent}%", 0);
                    }
                }
            }
        }

        return "";
    }

    // --- OSC Binary Parser & Encoder ---

    private function ParseOSCMessage(string $buffer): ?array
    {
        $len = strlen($buffer);
        if ($len < 4) {
            return null;
        }

        // 1. OSC Address
        $nullPos = strpos($buffer, "\0");
        if ($nullPos === false) {
            return null;
        }
        $address = substr($buffer, 0, $nullPos);
        $offset = (int)ceil(($nullPos + 1) / 4) * 4;

        if ($offset >= $len) {
            return ['address' => $address, 'args' => []];
        }

        // 2. Type Tag String
        if ($buffer[$offset] !== ',') {
            return ['address' => $address, 'args' => []];
        }

        $typeTagNullPos = strpos($buffer, "\0", $offset);
        if ($typeTagNullPos === false) {
            return ['address' => $address, 'args' => []];
        }
        $typeTag = substr($buffer, $offset, $typeTagNullPos - $offset);
        $offset = (int)ceil(($typeTagNullPos + 1) / 4) * 4;

        // 3. Arguments
        $types = str_split(substr($typeTag, 1));
        $args = [];

        foreach ($types as $type) {
            if ($offset >= $len) {
                break;
            }
            switch ($type) {
                case 'f': // 32-bit Float Big Endian
                    if ($offset + 4 <= $len) {
                        $raw = substr($buffer, $offset, 4);
                        $unpacked = unpack('Gval', $raw);
                        $args[] = $unpacked ? (float)$unpacked['val'] : 0.0;
                        $offset += 4;
                    }
                    break;

                case 'i': // 32-bit Signed Int Big Endian
                    if ($offset + 4 <= $len) {
                        $raw = substr($buffer, $offset, 4);
                        $unpacked = unpack('Nval', $raw);
                        $val = $unpacked ? (int)$unpacked['val'] : 0;
                        if ($val >= 0x80000000) {
                            $val -= 0x100000000;
                        }
                        $args[] = $val;
                        $offset += 4;
                    }
                    break;

                case 's': // Null-terminated String
                    $sNullPos = strpos($buffer, "\0", $offset);
                    if ($sNullPos !== false) {
                        $args[] = substr($buffer, $offset, $sNullPos - $offset);
                        $offset = (int)ceil(($sNullPos + 1) / 4) * 4;
                    }
                    break;

                default:
                    $offset += 4;
                    break;
            }
        }

        return [
            'address' => $address,
            'args'    => $args
        ];
    }

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

    private function SendOSCCommand(string $address): void
    {
        $buf = $address . "\0";
        while (strlen($buf) % 4 !== 0) { $buf .= "\0"; }

        $buf .= ",\0\0\0";

        if ($this->HasActiveParent()) {
            $this->SendDataToParent(json_encode([
                'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
                'Buffer' => bin2hex($buf)
            ]));
        }
    }



    protected function SetValueIfChanged(string $ident, mixed $value): bool
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
            return true;
        }
        return false;
    }
}

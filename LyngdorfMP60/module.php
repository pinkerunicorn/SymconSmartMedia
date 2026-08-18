<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
class LyngdorfMP60 extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeString('SourceMap', '[]');
        $this->RegisterAttributeString('AudioModeMap', '[]');
        $this->RegisterAttributeString('VoicingMap', '[]');
        $this->RegisterAttributeString('ZoneSourceMap', '[]');


        $this->RegisterPropertyBoolean('HideVariablesWhenOff', false);

        // Receive Buffer für unvollständige TCP Pakete
        $this->SetBuffer('ReceiveBuffer', '');

        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', 'Power', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH,
            'ICON'        => 'power-off'
        ], 1);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('Volume', 'Lautstärke', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SLIDER,
            'ICON'        => 'volume-high',
            'SUFFIX'      => 'dB',
            'MIN'         => -99.9,
            'MAX'         => 24.0,
            'STEP'        => 0.5
        ], 2);
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', 'Mute', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH,
            'ICON'        => 'volume-xmark'
        ], 3);
        $this->EnableAction('Mute');

        $this->RegisterVariableInteger('Source', 'Quelle', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'        => 'tv'
        ], 4);
        $this->EnableAction('Source');

        $this->RegisterVariableInteger('AudioMode', 'Audio Mode', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'        => 'wave-square'
        ], 5);
        $this->EnableAction('AudioMode');

        $this->RegisterVariableInteger('Voicing', 'Voicing', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'        => 'speaker'
        ], 6);
        $this->EnableAction('Voicing');

        $this->RegisterVariableString('AudioTypeIn', 'Audio Type In', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'waveform'], 7);
        $this->RegisterVariableString('AudioTypeOut', 'Audio Type Out', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'waveform'], 8);

        // Zone B Variablen
        $this->RegisterVariableBoolean('ZoneBPower', 'Zone B', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'power-off'
        ], 50);
        $this->EnableAction('ZoneBPower');

        $this->RegisterVariableFloat('ZoneBVolume', 'Zone B Lautstarke', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'sliders',
            'SUFFIX'       => 'dB',
            'MIN'          => -99.9,
            'MAX'          => 24.0,
            'STEP'         => 0.5
        ], 51);
        $this->EnableAction('ZoneBVolume');

        $this->RegisterVariableBoolean('ZoneBMute', 'Zone B Mute', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'volume-xmark'
        ], 52);
        $this->EnableAction('ZoneBMute');

        $this->RegisterVariableInteger('ZoneBSource', 'Zone B Quelle', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'tv'
        ], 53);
        $this->EnableAction('ZoneBSource');

        $this->RegisterVariableString('SoftwareVersion', 'Software Version', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 9);
        $this->RegisterVariableBoolean('SoftwareUpdateAvailable', 'Update verfügbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Download',
            'OPTIONS' => json_encode([
                [
                    'Value' => false, 
                    'Caption' => 'Nein', 
                    'IconActive' => true, 
                    'IconValue' => 'Check', 
                    'ColorActive' => true, 
                    'ColorDisplay' => 0x00CC00, 
                    'ColorValue' => 0x00CC00,
                    'ContentColorActive' => false, 
                    'ContentColorDisplay' => -1, 
                    'ContentColorValue' => -1
                ],
                [
                    'Value' => true, 
                    'Caption' => 'Ja', 
                    'IconActive' => true, 
                    'IconValue' => 'Download', 
                    'ColorActive' => true, 
                    'ColorDisplay' => 0xFF0000, 
                    'ColorValue' => 0xFF0000,
                    'ContentColorActive' => false, 
                    'ContentColorDisplay' => -1, 
                    'ContentColorValue' => -1
                ]
            ])
        ], 10);

        $this->RegisterTimer('UpdatePolling', 0, 'LYNG_UpdateData($_IPS[\'TARGET\']);');
        $this->RegisterTimer('FirmwareUpdateCheck', 0, 'LYNG_CheckFirmwareUpdate($_IPS[\'TARGET\']);');

        $this->DA_RegisterAvailability(900);
        $this->DA_RegisterWatchdog();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, 10505 /* IM_CHANGESTATUS */);
        }

        // Regelmäßiges Polling (alle 30 Sekunden) als Fallback
        $this->SetTimerInterval('UpdatePolling', 30000);

        // Einmal täglich nach Updates prüfen (86400000 ms)
        $this->SetTimerInterval('FirmwareUpdateCheck', 86400000);

        $this->RestoreDynamicPresentation('Source', '🎵 Quelle', 4, 'SourceMap', 'TV');
        $this->RestoreDynamicPresentation('AudioMode', '🎛 Audio Mode', 5, 'AudioModeMap', 'Sound');
        $this->RestoreDynamicPresentation('Voicing', '🗣 Voicing', 6, 'VoicingMap', 'Speaker');
        $this->RestoreDynamicPresentation('ZoneBSource', 'Zone B Quelle', 53, 'ZoneSourceMap', 'TV');

        // Erzwinge die 0.5 Schrittweite für bereits existierende Profile
        if (IPS_VariableProfileExists('LyngdorfMP60.Volume')) {
            IPS_SetVariableProfileValues('LyngdorfMP60.Volume', -99.9, 24.0, 0.5);
        }
        if (IPS_VariableProfileExists('LyngdorfMP60.ZoneBVolume')) {
            IPS_SetVariableProfileValues('LyngdorfMP60.ZoneBVolume', -99.9, 24.0, 0.5);
        }
    }

    private function RestoreDynamicPresentation(string $ident, string $varName, int $position, string $mapName, string $icon): void
    {
        $map = json_decode($this->ReadAttributeString($mapName), true);
        if (is_array($map) && count($map) > 0) {
            $options = [];
            foreach ($map as $key => $val) {
                $options[] = [
                    'Value' => (int)$key,
                    'Caption' => $val,
                    'IconActive' => true,
                    'IconValue' => $icon,
                    'Color' => -1
                ];
            }
            $this->RegisterVariableInteger($ident, $varName, [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'ICON' => $icon,
                'OPTIONS' => json_encode($options)
            ], $position);
            IPS_SetVariableCustomProfile($this->GetIDForIdent($ident), '');
        }
        
        $profileName = 'Lyngdorf.'. $ident . '.'. $this->InstanceID;
        if (IPS_VariableProfileExists($profileName)) {
            IPS_DeleteVariableProfile($profileName);
        }
    }

    protected function Log(string $text): void
    {
        $this->SLog('INFO', $text);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === 10505) { // IM_CHANGESTATUS
            if ($Data[0] === 102) { // IS_ACTIVE
                $this->SendDebug('System', 'Socket reconnected, forcing UpdateData', 0);
                $this->Log('Verbindung zum Gerät (wieder-)hergestellt. Lade Status...');
                $this->UpdateData();
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'DA_Watchdog') {
            $this->DA_HandleWatchdog();
            return;
        }
        
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->Log('Schalte Gerät EIN (POWERONMAIN)');
                    $this->SendCommand('!POWERONMAIN');
                } else {
                    $this->Log('Schalte Gerät AUS (POWEROFFMAIN)');
                    $this->SendCommand('!POWEROFFMAIN');
                }
                break;

            case 'Volume':
                $volInt = (int)round($Value * 10);
                $this->SendCommand('!VOL('. $volInt . ')');
                break;

            case 'Mute':
                if ($Value) {
                    $this->SendCommand('!MUTEON');
                } else {
                    $this->SendCommand('!MUTEOFF');
                }
                break;

            case 'Source':
                $this->SendCommand('!SRC('. $Value . ')');
                break;

            case 'AudioMode':
                $this->SendCommand('!AUDMODE('. $Value . ')');
                break;

            case 'Voicing':
                $this->SendCommand('!RPVOI('. $Value . ')');
                break;

            case 'ZoneBPower':
                if ($Value) {
                    $this->Log('Zone B: Schalte EIN');
                    $this->SendCommand('!POWERONZONE2');
                } else {
                    $this->Log('Zone B: Schalte AUS');
                    $this->SendCommand('!POWEROFFZONE2');
                }
                break;

            case 'ZoneBVolume':
                $volInt = (int)round($Value * 10);
                $this->SendCommand('!ZVOL('. $volInt . ')');
                break;

            case 'ZoneBMute':
                if ($Value) {
                    $this->SendCommand('!ZMUTEON');
                } else {
                    $this->SendCommand('!ZMUTEOFF');
                }
                break;

            case 'ZoneBSource':
                $this->SendCommand('!ZSRC('. $Value . ')');
                break;
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->DA_SetAvailable(true);
        $this->DA_ResetWatchdog(300);
        $data = json_decode($JSONString);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SLog('ERROR', 'Ungültiges JSON empfangen', json_last_error_msg());
            return '';
        }
        if (isset($data->Buffer)) {
            $payload = is_string($data->Buffer) ? hex2bin($data->Buffer) : '';
        } else {
            return "";
        }
        
        $buffer = $this->GetBuffer('ReceiveBuffer');
        $buffer .= $payload;

        $packets = explode("\r", $buffer);
        $this->SetBuffer('ReceiveBuffer', array_pop($packets));

        foreach ($packets as $packet) {
            $packet = trim($packet);
            if (!empty($packet)) {
                $this->ProcessPacket($packet);
            }
        }
        return "";
    }

    public function UpdateData(): void
    {
        if ($this->HasActiveParent()) {
            $this->SendCommand('!VERB(1)');
            $this->SendCommand('!SWVER?');
            $this->SendCommand('!POWER?');
            $this->SendCommand('!VOL?');
            $this->SendCommand('!MUTE?');
            $this->SendCommand('!SRCS?');
            $this->SendCommand('!AUDMODEL?');
            $this->SendCommand('!RPVOIS?');
            $this->SendCommand('!SRC?');
            $this->SendCommand('!AUDMODE?');
            $this->SendCommand('!RPVOI?');
            $this->SendCommand('!AUDTYPE?');
            $this->SendCommand('!AUDTYPEOUT?');
            // Zone B
            $this->SendCommand('!POWERZONE2?');
            $this->SendCommand('!ZVOL?');
            $this->SendCommand('!ZMUTE?');
            $this->SendCommand('!ZSRCS?');
            $this->SendCommand('!ZSRC?');
        }
    }

    public function CheckFirmwareUpdate(): void
    {
        if ($this->HasActiveParent()) {
            $this->Log('Prüfe auf Firmware Update...');
            // Da das Update-Kommando nicht dokumentiert ist, versuchen wir einige gängige Befehle
            $this->SendCommand('!SWVER?');
            $this->SendCommand('!DEVICE?');
            $this->SendCommand('!UPDATE?');
            $this->SendCommand('!SWUPDATE?');
            $this->SendCommand('!SYSINFO?');
        }
    }

    private function ProcessPacket(string $packet): void
    {
        if (strpos($packet, '!') !== 0 && strpos($packet, '#') !== 0) {
            return;
        }

        $command = substr($packet, 1);

        $this->SendDebug('Receive', $command, 0);
        $this->Log('DEBUG RX: ' . $command);

        if (preg_match('/^POWER\((\d)\)$/', $command, $matches)) {
            $power = ($matches[1] == '1');
            if ($this->GetValue('Power') !== $power) {
                $this->Log('Status geändert: Power = '. ($power ? 'ON': 'OFF'));
            }
            $this->SetValue('Power', (bool)$power);
            $this->UpdateVisibility($power);
        } 
        elseif ($command === 'POWERONMAIN'|| $command === 'PON'|| $command === 'POWERON') {
            if (!$this->GetValue('Power')) {
                $this->Log('Status geändert: Power = ON');
            }
            $this->SetValue('Power', true);
            $this->UpdateVisibility(true);
        }
        elseif ($command === 'POWEROFFMAIN'|| $command === 'POFF'|| $command === 'POWEROFF') {
            if ($this->GetValue('Power')) {
                $this->Log('Status geändert: Power = OFF');
            }
            $this->SetValue('Power', false);
            $this->UpdateVisibility(false);
        }
        elseif (preg_match('/^VOL\((-?\d+)\)$/', $command, $matches)) {
            $this->SetValue('Volume', floatval($matches[1]) / 10);
        }
        elseif ($command === 'MUTEON') {
            $this->SetValue('Mute', true);
        }
        elseif ($command === 'MUTEOFF') {
            $this->SetValue('Mute', false);
        }
        elseif (preg_match('/^SRC\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateDynamicProfile('Source', '🎵 Quelle', 4, 'SourceMap', $index, $name, 'TV');
            $this->SetValue('Source', $index);
        }
        elseif (preg_match('/^SRC\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Source', intval($matches[1]));
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateDynamicProfile('AudioMode', '🎛 Audio Mode', 5, 'AudioModeMap', $index, $name, 'Sound');
            $this->SetValue('AudioMode', $index);
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('AudioMode', intval($matches[1]));
        }
        elseif (preg_match('/^RPVOI\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateDynamicProfile('Voicing', '🗣 Voicing', 6, 'VoicingMap', $index, $name, 'Speaker');
            $this->SetValue('Voicing', $index);
        }
        elseif (preg_match('/^RPVOI\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Voicing', intval($matches[1]));
        }
        elseif (preg_match('/^AUDTYPE\((.*)\)$/', $command, $matches)) {
            $this->SetValue('AudioTypeIn', $matches[1]);
        }
        elseif (preg_match('/^AUDTYPEOUT\((.*)\)$/', $command, $matches)) {
            $this->SetValue('AudioTypeOut', $matches[1]);
        }
        // Zone B Responses
        elseif (preg_match('/^POWERZONE2\((\d)\)$/', $command, $matches)) {
            $power = ($matches[1] == '1');
            if ($this->GetValue('ZoneBPower') !== $power) {
                $this->Log('Zone B Power = ' . ($power ? 'ON' : 'OFF'));
            }
            $this->SetValue('ZoneBPower', $power);
            $this->UpdateZoneBVisibility($power);
        }
        elseif ($command === 'POWERONZONE2') {
            if (!$this->GetValue('ZoneBPower')) {
                $this->Log('Zone B Power = ON');
            }
            $this->SetValue('ZoneBPower', true);
            $this->UpdateZoneBVisibility(true);
        }
        elseif ($command === 'POWEROFFZONE2') {
            if ($this->GetValue('ZoneBPower')) {
                $this->Log('Zone B Power = OFF');
            }
            $this->SetValue('ZoneBPower', false);
            $this->UpdateZoneBVisibility(false);
        }
        elseif (preg_match('/^ZVOL\((-?\d+)\)$/', $command, $matches)) {
            $this->SetValue('ZoneBVolume', floatval($matches[1]) / 10);
        }
        elseif ($command === 'ZMUTEON') {
            $this->SetValue('ZoneBMute', true);
        }
        elseif ($command === 'ZMUTEOFF') {
            $this->SetValue('ZoneBMute', false);
        }
        elseif (preg_match('/^ZSRC\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateDynamicProfile('ZoneBSource', 'Zone B Quelle', 53, 'ZoneSourceMap', $index, $name, 'TV');
            $this->SetValue('ZoneBSource', $index);
        }
        elseif (preg_match('/^ZSRC\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('ZoneBSource', intval($matches[1]));
        }
        elseif (preg_match('/^(?:SW)?VER\((.*)\)$/i', $command, $matches)) {
            $version = trim($matches[1], '"');
            if ($this->GetValue('SoftwareVersion') !== $version) {
                $this->Log('Software Version erkannt: ' . $version);
            }
            $this->SetValue('SoftwareVersion', $version);
        }
        elseif (preg_match('/^(?:SW)?UPDATE\((.*)\)$/i', $command, $matches)) {
            $val = strtolower(trim($matches[1], '"'));
            $isUpdate = ($val === '1' || $val === 'true' || $val === 'yes' || $val === 'available');
            $this->SetValue('SoftwareUpdateAvailable', $isUpdate);
        }
    }

    private function SendCommand(string $command): void
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        $this->SendDebug('Transmit', $command, 0);
        
        $this->SendDataToParent(json_encode([
            'DataID'=> '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
            'Buffer'=> bin2hex($command . "\r")
        ]));
    }

    private function UpdateVisibility(bool $powerState): void
    {
        if (!$this->ReadPropertyBoolean('HideVariablesWhenOff')) {
            $this->SetHiddenSafe('Volume', false);
            $this->SetHiddenSafe('Mute', false);
            $this->SetHiddenSafe('Source', false);
            $this->SetHiddenSafe('AudioMode', false);
            $this->SetHiddenSafe('Voicing', false);
            $this->SetHiddenSafe('AudioTypeIn', false);
            $this->SetHiddenSafe('AudioTypeOut', false);
            return;
        }

        $hidden = !$powerState;
        $this->SetHiddenSafe('Volume', $hidden);
        $this->SetHiddenSafe('Mute', $hidden);
        $this->SetHiddenSafe('Source', $hidden);
        $this->SetHiddenSafe('AudioMode', $hidden);
        $this->SetHiddenSafe('Voicing', $hidden);
        $this->SetHiddenSafe('AudioTypeIn', $hidden);
        $this->SetHiddenSafe('AudioTypeOut', $hidden);
    }

    private function UpdateZoneBVisibility(bool $powerState): void
    {
        if (!$this->ReadPropertyBoolean('HideVariablesWhenOff')) {
            $this->SetHiddenSafe('ZoneBVolume', false);
            $this->SetHiddenSafe('ZoneBMute', false);
            $this->SetHiddenSafe('ZoneBSource', false);
            return;
        }

        $hidden = !$powerState;
        $this->SetHiddenSafe('ZoneBVolume', $hidden);
        $this->SetHiddenSafe('ZoneBMute', $hidden);
        $this->SetHiddenSafe('ZoneBSource', $hidden);
    }

    private function SetHiddenSafe(string $ident, bool $hidden): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && $id > 0) {
            IPS_SetHidden($id, $hidden);
        }
    }



    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'LyngdorfMP60: '. $Message);
        return true;
    }

    private function UpdateDynamicProfile(string $ident, string $varName, int $position, string $mapName, int $index, string $name, string $icon): void
    {
        $map = json_decode($this->ReadAttributeString($mapName), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SLog('ERROR', 'Ungültiges JSON empfangen', json_last_error_msg());
            return;
        }
        if (!is_array($map)) {
            $map = [];
        }
        $map[$index] = $name;
        $this->WriteAttributeString($mapName, json_encode($map));
        
        $options = [];
        foreach ($map as $key => $val) {
            $options[] = [
                'Value' => (int)$key,
                'Caption' => $val,
                'IconActive' => true,
                'IconValue' => $icon,
                'Color' => -1
            ];
        }
        
        $this->RegisterVariableInteger($ident, $varName, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON' => $icon,
            'OPTIONS' => json_encode($options)
        ], $position);
        IPS_SetVariableCustomProfile($this->GetIDForIdent($ident), '');
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Hier stellst du ein, ob die Variablen im WebFront versteckt werden sollen, wenn der Receiver ausgeschaltet ist. Das sorgt für mehr Übersichtlichkeit!"
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "CheckBox",
                    "name": "HideVariablesWhenOff",
                    "caption": "Variablen verstecken, wenn das Gerät ausgeschaltet ist"
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Label",
            "caption": "Hier kannst du die aktuellen Werte manuell vom Receiver abfragen, falls mal etwas asynchron sein sollte."
        },
        {
            "type": "Button",
            "label": "Werte manuell vom Receiver aktualisieren",
            "onClick": "LYNG_UpdateData($id);"
        },
        {
            "type": "Button",
            "label": "Manuell auf Updates prüfen",
            "onClick": "LYNG_CheckFirmwareUpdate($id);"
        },
        {
            "type": "Button",
            "label": "TestConnection",
            "onClick": "echo 'OK';"
        }
    ],
    "status": [
        { "code": 102, "icon": "active", "caption": "Verbunden" },
        { "code": 104, "icon": "inactive", "caption": "Host nicht konfiguriert" },
        { "code": 201, "icon": "inactive", "caption": "Gerät antwortet nicht" },
        { "code": 202, "icon": "inactive", "caption": "Verbindungsfehler" },
        { "code": 203, "icon": "inactive", "caption": "Timeout" },
        { "code": 204, "icon": "inactive", "caption": "Offline" }
    ]
}
EOT;
    }
}


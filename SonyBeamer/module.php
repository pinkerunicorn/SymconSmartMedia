<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
class SonyBeamer extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    private static array $inputMap = [
        0 => 'hdmi1',
        1 => 'hdmi2',
        2 => 'video1',
        3 => 'component'
    ];

    private static array $pictureModeMap = [
        0 => 'dynamic',
        1 => 'standard',
        2 => 'brt_priority',
        3 => 'cinema_film_1',
        4 => 'cinema_film_2',
        5 => 'reference',
        6 => 'tv',
        7 => 'photo',
        8 => 'game',
        9 => 'bright_cinema',
        10 => 'bright_tv',
        11 => 'user'
    ];

    public function Create(): void{
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyString('Host', '192.168.1.100');
        $this->RegisterPropertyInteger('Port', 53595);
        $this->RegisterPropertyInteger('UpdateInterval', 20); // Default to 20s

        // Timer für Polling
        $this->RegisterTimer('UpdateTimer', 0, 'SONY_UpdateStatus($_IPS[\'TARGET\']);');

        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', 'Status', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH,
            'ICON'        => 'power-off'
        ], 10);
        $this->EnableAction('Power');

        // Alte String-Variablen entfernen, falls vorhanden
        $inputId = @$this->GetIDForIdent('Input');
        if ($inputId && IPS_GetVariable($inputId)['VariableType'] !== 1) { // 1 = Integer
            $this->UnregisterVariable('Input');
        }
        $picId = @$this->GetIDForIdent('PictureMode');
        if ($picId && IPS_GetVariable($picId)['VariableType'] !== 1) {
            $this->UnregisterVariable('PictureMode');
        }

        $this->RegisterVariableInteger('Input', 'Eingang', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON' => 'plug',
            'OPTIONS' => json_encode([
                ['Value' => 0, 'Caption' => 'HDMI 1', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 1, 'Caption' => 'HDMI 2', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 2, 'Caption' => 'Video 1', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 3, 'Caption' => 'Component', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]
            ])
        ], 20);
        $this->EnableAction('Input');

        $this->RegisterVariableInteger('PictureMode', 'Bildmodus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON' => 'tv',
            'OPTIONS' => json_encode([
                ['Value' => 0, 'Caption' => 'Dynamic', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 1, 'Caption' => 'Standard', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 2, 'Caption' => 'Brightness Priority', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 3, 'Caption' => 'Cinema Film 1', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 4, 'Caption' => 'Cinema Film 2', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 5, 'Caption' => 'Reference', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 6, 'Caption' => 'TV', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 7, 'Caption' => 'Photo', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 8, 'Caption' => 'Game', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 9, 'Caption' => 'Bright Cinema', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 10, 'Caption' => 'Bright TV', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => 11, 'Caption' => 'User', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]
            ])
        ], 30);
        $this->EnableAction('PictureMode');

        $this->RegisterVariableInteger('OperationTime', 'Betriebsstunden', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'stopwatch',
            'SUFFIX'=> 'h'
        ], 40);
        $this->RegisterVariableInteger('LightSourceTime', 'Lampenstunden', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'lightbulb',
            'SUFFIX'=> 'h'
        ], 50);
        $this->RegisterVariableString('Warning', 'Warnungen', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'=> 'triangle-exclamation'
        ], 60);

        $this->DA_RegisterAvailability(900);
    }

    public function Destroy(): void
    {
        parent::Destroy();
        }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if (empty($this->ReadPropertyString('Host'))) {
            $this->SetStatus(104);
            return;
        }

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval < 5) $interval = 5;
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

        // Legacy-Profile bereinigen
        if (IPS_VariableProfileExists('Sony.Input')) {
            IPS_DeleteVariableProfile('Sony.Input');
        }
        if (IPS_VariableProfileExists('Sony.PictureMode')) {
            IPS_DeleteVariableProfile('Sony.PictureMode');
        }

        $this->UpdateVisibility(!$this->GetValue('Power'));

    
    }


    public function RequestAction(string $Ident, mixed $Value): void{
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->SendSingleCommand('power "on"');
                    $this->SLogInfo("Einschaltbefehl gesendet.");
                } else {
                    $this->SendSingleCommand('power "off"');
                    $this->SLogInfo("Ausschaltbefehl gesendet.");
                }
                break;
            case 'Input':
                if (isset(self::$inputMap[$Value])) {
                    $cmdVal = self::$inputMap[$Value];
                    $this->SendSingleCommand("input \"$cmdVal\"");
                    $this->SLogInfo("Eingang auf $cmdVal gesetzt.");
                }
                break;
            case 'PictureMode':
                if (isset(self::$pictureModeMap[$Value])) {
                    $cmdVal = self::$pictureModeMap[$Value];
                    $this->SendSingleCommand("picture_mode \"$cmdVal\"");
                    $this->SLogInfo("Bildmodus auf $cmdVal gesetzt.");
                }
                break;
            default:
                throw new Exception("Invalid Action");
        }
        
        usleep(200000);
        $this->UpdateStatus();
    }

    public function UpdateStatus(): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');

        if (empty($host)) {
            $this->SendDebug("Log", "UpdateStatus abgebrochen: Keine IP-Adresse (Host) konfiguriert!", 0);
            return;
        }

        $this->SendDebug("Log", "Verbinde mit Beamer $host:$port...", 0);
        
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) {
            $this->SendDebug("Log", "Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            $this->DA_SetAvailable(false, 'Verbindungsfehler');return;
        }
        
        stream_set_timeout($fp, 2);
        
        // Begrüßung abwarten
        $greeting = fread($fp, 128);
        if (!empty(trim((string)$greeting))) {
            $this->SendDebug("Log", "Begrüßung: ". trim((string)$greeting), 0);
        }
        
        $commands = [
            'power_status ?',
            'input ?',
            'picture_mode ?',
            'error ?',
            'timer ?'
        ];
        
        foreach ($commands as $cmd) {
            $this->SendDebug("Transmit", $cmd, 0);
            fwrite($fp, $cmd . "\r\n");
            
            // Warte auf Antwort
            $response = fread($fp, 1024);
            $response = trim((string)$response);
            if (!empty($response)) {
                $this->DA_SetAvailable(true);
                $this->SendDebug("Receive", $response, 0);
                
                $lines = explode("\n", str_replace("\r", "", $response));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $this->ParseLine($line);
                    }
                }
            } else {
                $this->SendDebug("Log", "Keine Antwort auf $cmd", 0);
            }
            
            usleep(200000); // Kurze Pause zwischen den Befehlen
        }
        
        fclose($fp);
    }

    private function SendSingleCommand(string $cmd): void
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');

        if (empty($host)) return;

        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) {
            $this->SendDebug("Log", "Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            $this->DA_SetAvailable(false, 'Verbindungsfehler');return;
        }
        
        stream_set_timeout($fp, 2);
        fread($fp, 128); // Begrüßung ignorieren
        
        $this->SendDebug("Transmit", $cmd, 0);
        fwrite($fp, $cmd . "\r\n");
        
        $response = trim((string)fread($fp, 1024));
        if (!empty($response)) {
            $this->DA_SetAvailable(true);
            $this->SendDebug("Receive", $response, 0);
            $lines = explode("\n", str_replace("\r", "", $response));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $this->ParseLine($line);
                }
            }
        }
        fclose($fp);
    }

    private function ParseLine(string $line): void
    {
        $cleanLine = trim($line, '"');
        
        if ($cleanLine === 'ok') {
            return;
        }

        if (in_array($cleanLine, ['err_cmd', 'err_inactive'])) {
            $this->SendDebug("ParseError", "Beamer meldet Fehler / Ablehnung: ". $cleanLine . "(Mögliche Ursache: Beamer ist im Standby oder Befehl ungültig)", 0);
            return;
        }
        
        if ($cleanLine === 'NOKEY') {
            $this->SendDebug("Log", "Beamer sendet NOKEY (Authentifizierung ist aus, das ist normal!).", 0);
            return;
        }

        // Power Status
        if (in_array($cleanLine, ['standby', 'startup', 'on', 'cooling1', 'cooling2', 'saving_standby'])) {
            $isPowered = ($cleanLine === 'on'|| $cleanLine === 'startup');
            if ($this->GetValue('Power') !== $isPowered) {
                $this->SetValue('Power', (bool)$isPowered);
                $this->UpdateVisibility(!$isPowered);
            }
            return;
        }

        // Inputs
        $inputKey = array_search($cleanLine, self::$inputMap);
        if ($inputKey !== false) {
             if ($this->GetValue('Input') !== $inputKey) {
                 $this->SetValue('Input', $inputKey);
             }
             return;
        }

        // Picture Mode
        $picKey = array_search($cleanLine, self::$pictureModeMap);
        if ($picKey !== false) {
             if ($this->GetValue('PictureMode') !== $picKey) {
                 $this->SetValue('PictureMode', $picKey);
             }
             return;
        }

        // Timer (JSON Array)
        if (strpos($line, '[') === 0 && strpos($line, '{') !== false) {
             $arr = json_decode($line, true);
             if (is_array($arr)) {
                 foreach ($arr as $item) {
                     if (isset($item['operation'])) {
                         $this->SetValue('OperationTime', (int)$item['operation']);
                     }
                     if (isset($item['light_src'])) {
                         $this->SetValue('LightSourceTime', (int)$item['light_src']);
                     }
                 }
             }
             return;
        }

        // Error / Warning (JSON Array aus Strings)
        if (strpos($line, '[') === 0 && strpos($line, '{') === false) {
             $arr = json_decode($line, true);
             if (is_array($arr) && count($arr) > 0) {
                 $this->SetValue('Warning', $arr[0]);
             }
             return;
        }
    }

    private function UpdateVisibility(bool $hidden): void
    {
        $idents = ['Input', 'PictureMode', 'OperationTime', 'LightSourceTime', 'Warning'];
        foreach ($idents as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false && $id > 0) {
                IPS_SetHidden($id, $hidden);
            }
        }
    }


    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Hier stellst du die Netzwerkverbindung zu deinem Sony Beamer ein. Trage die IP-Adresse und den Port deines Geräts ein."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "Host",
                    "caption": "IP-Adresse"
                },
                {
                    "type": "NumberSpinner",
                    "name": "Port",
                    "caption": "Port"
                },
                {
                    "type": "NumberSpinner",
                    "name": "UpdateInterval",
                    "caption": "Update Intervall (Sekunden)"
                }
            ]
        },
        {
            "type": "Label",
            "label": "Tipp: Das Update Intervall bestimmt, wie oft die Daten vom Beamer abgefragt werden. Setze es nicht zu niedrig, um das Netzwerk nicht zu belasten (Empfehlung: 20 Sekunden)."
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Status jetzt aktualisieren",
            "onClick": "SONY_UpdateStatus($id);"
        },
        {
            "type": "Button",
            "label": "TestConnection",
            "onClick": "SONY_UpdateStatus($id);"
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


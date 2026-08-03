<?php

class Regenradar extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('OpenWeatherInstanceID', 0);
        $this->RegisterPropertyInteger('TemperatureID', 0);
        $this->RegisterPropertyInteger('HumidityID', 0);
        $this->RegisterPropertyInteger('WindSpeedID', 0);
        $this->RegisterPropertyInteger('Rain1hID', 0);

        $this->RegisterPropertyString('RadarProvider', 'rainviewer');
        $this->RegisterPropertyString('RainbowApiKey', '');
        $this->RegisterPropertyInteger('RainbowForecastHours', 1);
        $this->RegisterPropertyInteger('RadarRefreshSeconds', 600);
        $this->RegisterPropertyBoolean('EnableAutoplay', false);
        $this->RegisterPropertyBoolean('ShowTileDebug', false);
        $this->RegisterPropertyInteger('Zoom', 7);
        $this->RegisterPropertyString('Theme', 'dark');
        $this->RegisterPropertyString('MapStyle', 'street');

        // Gemerkte Variablen, auf deren VM_UPDATE die Wetter-/Forecast-Anzeige sofort aktualisiert wird.
        $this->RegisterAttributeString('WeatherWatchIDs', '[]');
        // Signatur des zuletzt an die Visualisierung übertragenen neuesten Radarframes.
        $this->RegisterAttributeString('LastRadarFrameSignature', '');

        // HTML-SDK aktivieren. 1 = individuelle Darstellung via HTML-SDK.
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Wetter- und Forecast-Variablen überwachen: bei VM_UPDATE sofort nur die Wetterdaten neu senden.
        $this->RefreshWeatherWatchRegistrations();

        // Bei jeder Konfigurationsänderung das komplette HTML neu laden,
        // damit Theme, Kartenstil, Provider, Zoom, API-Key usw. sofort übernommen werden.
        $this->ReloadHtml();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        $watchIDs = json_decode($this->ReadAttributeString('WeatherWatchIDs'), true);
        if (!is_array($watchIDs) || !in_array((int) $SenderID, array_map('intval', $watchIDs), true)) {
            return;
        }

        $this->SendDebug(
            'MessageSink',
            'VM_UPDATE von "' . IPS_GetName($SenderID) . '" (ID ' . $SenderID . ') erkannt, Wetterdaten werden aktualisiert.',
            0
        );

        // Variable wurde geändert: nur Wetter + Forecast aktualisieren, kein HTML-Reload.
        $this->UpdateWeather();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'RefreshRadar':
                $this->UpdateRadar();
                return;

            case 'ReloadHtml':
                $this->ReloadHtml();
                return;

            case 'SetRadarProvider':
                $provider = strtolower(trim((string) $Value));
                if (!in_array($provider, ['rainviewer', 'rainbow', 'meteoswiss'], true)) {
                    throw new Exception('Ungültiger Radar-Provider: ' . $provider);
                }

                if ($provider === $this->ReadPropertyString('RadarProvider')) {
                    return;
                }

                $this->SendDebug('SetRadarProvider', 'Radar-Provider wird auf ' . $provider . ' umgeschaltet.', 0);
                IPS_SetProperty($this->InstanceID, 'RadarProvider', $provider);
                IPS_ApplyChanges($this->InstanceID);
                return;
        }

        throw new Exception('Invalid Ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Wetterdaten',
                    'items' => [
                        [
                            'type'    => 'Label',
                            'caption' => "Das Modul erfordert eine installierte OpenWeatherOneCall Instanz. Bitte installieren Sie das Modul und legen Sie eine Instanz an."
                        ],
                        [
                            'type' => 'SelectObject',
                            'name' => 'OpenWeatherInstanceID',
                            'caption' => 'OpenWeatherOneCall Instanz',
                            'objectType' => 1
                        ],
                        [
                            'type' => 'SelectObject',
                            'name' => 'TemperatureID',
                            'caption' => 'Temperatur Variable (leer = kommt von OpenWeather)',
                            'objectType' => 2
                        ],
                        [
                            'type' => 'SelectObject',
                            'name' => 'HumidityID',
                            'caption' => 'Luftfeuchte Variable (leer = kommt von OpenWeather)',
                            'objectType' => 2
                        ],
                        [
                            'type' => 'SelectObject',
                            'name' => 'WindSpeedID',
                            'caption' => 'Wind Variable (leer = kommt von OpenWeather)',
                            'objectType' => 2
                        ],
                        [
                            'type' => 'SelectObject',
                            'name' => 'Rain1hID',
                            'caption' => 'Regen 1h Variable (leer = kommt von OpenWeather)',
                            'objectType' => 2
                        ],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Radar',
                    'items' => [
                        [
                            'type' => 'Select',
                            'name' => 'RadarProvider',
                            'caption' => 'Radar Provider',
                            'options' => [
                                ['caption' => 'Rainviewer', 'value' => 'rainviewer'],
                                ['caption' => 'Rainbow', 'value' => 'rainbow'],
                                ['caption' => 'MeteoSwiss', 'value' => 'meteoswiss'],
                            ],
                        ],
                        ['type' => 'ValidationTextBox', 'name' => 'RainbowApiKey', 'caption' => 'Rainbow API-Key'],
                        [
                            'type' => 'Select',
                            'name' => 'RainbowForecastHours',
                            'caption' => 'Rainbow Zukunftsvorschau',
                            'options' => [
                                ['caption' => '1 Stunde', 'value' => 1],
                                ['caption' => '2 Stunden', 'value' => 2],
                                ['caption' => '3 Stunden', 'value' => 3],
                                ['caption' => '4 Stunden', 'value' => 4],
                            ],
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'RadarRefreshSeconds', 'caption' => 'Radar aktualisieren alle Sekunden', 'minimum' => 60],
                        ['type' => 'CheckBox', 'name' => 'EnableAutoplay', 'caption' => 'Autoplay aktivieren'],
                        ['type' => 'CheckBox', 'name' => 'ShowTileDebug', 'caption' => 'Tile-Debug im HTML anzeigen'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Darstellung',
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Zoom', 'caption' => 'Start-Zoom', 'minimum' => 1, 'maximum' => 12],
                        [
                            'type' => 'Select',
                            'name' => 'Theme',
                            'caption' => 'Theme',
                            'options' => [
                                ['caption' => 'Dunkel', 'value' => 'dark'],
                                ['caption' => 'Hell', 'value' => 'light'],
                            ],
                        ],
                        [
                            'type' => 'Select',
                            'name' => 'MapStyle',
                            'caption' => 'Kartenstil',
                            'options' => [
                                ['caption' => 'Street', 'value' => 'street'],
                                ['caption' => 'Topo', 'value' => 'topo'],
                                ['caption' => 'NatGeo', 'value' => 'natgeo'],
                                ['caption' => 'Satellite', 'value' => 'satellite'],
                                ['caption' => 'HOT', 'value' => 'hot'],
                                ['caption' => 'OSM FR', 'value' => 'osmfr'],
                                ['caption' => 'OpenTopo', 'value' => 'opentopo'],
                            ],
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Radar jetzt aktualisieren',
                    'onClick' => 'RGR_UpdateRadar($id);',
                ],
                [
                    'type' => 'Button',
                    'caption' => 'HTML neu laden',
                    'onClick' => 'IPS_RequestAction($id, "ReloadHtml", true);',
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                                'type'   => 'Image',
                                'onClick'=> "echo 'https://paypal.me/mbstern';",
                                'image'=> "data:image/jpeg;base64,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"
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => "Sag danke und unterstütze den Modulentwickler: paypal.me/mbstern"
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetVisualizationTile(): string
    {
        $initial = [
            'type' => 'init',
            'data' => [
                'config'  => $this->BuildClientConfig(),
                'weather' => $this->BuildWeatherPayload(),
                'radar'   => $this->BuildRadarPayload()
            ]
        ];

        $initialJson = json_encode(
            $initial,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        return <<<HTML
<div id="wetterradar-root" class="wr-root">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">

    <style>
        .wr-root {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: system-ui, Segoe UI, Roboto, sans-serif;
            --wr-bg: rgba(40,40,40,0.85);
            --wr-text: #fff;
            --wr-panel: #2b2b2b;
            --wr-border: #444;
            --wr-btn-bg: #333;
            --wr-btn-fg: #fff;
            --wr-btn-border: #999;
            --k: 1;
            --wr-fs: calc(12px * var(--k));
            --wr-fs-small: calc(11px * var(--k));
            --wr-fs-tiny: calc(10px * var(--k));
            --wr-pad: calc(8px * var(--k));
            --wr-gap: calc(8px * var(--k));
            --wr-radius: calc(10px * var(--k));
            --wr-shadow: 0 calc(4px * var(--k)) calc(14px * var(--k)) rgba(0,0,0,.25);
            --wr-icon: calc(20px * var(--k));
            --wr-forecast-icon: calc(40px * var(--k));
            --wr-legend-swatch-w: calc(18px * var(--k));
            --wr-legend-swatch-h: calc(12px * var(--k));
            --wr-controls-w: calc(220px * var(--k));
            --wr-range-h: calc(22px * var(--k));
            --wr-btn-pad-v: calc(8px * var(--k));
            /* Script-kompatible Aliase für Tooltip-CSS */
            --bg: var(--wr-bg);
            --text: var(--wr-text);
            --pad: var(--wr-pad);
            --radius: var(--wr-radius);
            --fs-small: var(--wr-fs-small);
        }
        .wr-root.wr-light {
            --wr-bg: rgba(255,255,255,0.90);
            --wr-text: #222;
            --wr-panel: #fff;
            --wr-border: #ccc;
            --wr-btn-bg: #fff;
            --wr-btn-fg: #000;
            --wr-btn-border: #ddd;
        }
        .wr-root #map {
            width: 100%;
            height: 100%;
            z-index: 0;
        }
        .wr-panel {
            position: absolute;
            z-index: 1000;
            background: var(--wr-bg);
            color: var(--wr-text);
            border-radius: var(--wr-radius);
            padding: var(--wr-pad);
            box-shadow: var(--wr-shadow);
            backdrop-filter: blur(4px);
            font-size: var(--wr-fs);
        }
        #wr-current {
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: calc(var(--wr-gap) + 6px);
            align-items: center;
        }
        #wr-current .wr-value {
            display: flex;
            align-items: center;
            gap: calc(6px * var(--k));
            font-size: var(--wr-fs);
            white-space: nowrap;
        }
        #wr-current img {
            width: var(--wr-icon);
            height: var(--wr-icon);
        }
        #wr-legend {
            bottom: 10px;
            left: 10px;
            font-size: var(--wr-fs-tiny);
        }
        #wr-legend .wr-legend-entry {
            display: flex;
            align-items: center;
            gap: calc(var(--wr-gap) - 4px);
            margin-bottom: calc(4px * var(--k));
        }
        #wr-legend .wr-legend-color {
            width: var(--wr-legend-swatch-w);
            height: var(--wr-legend-swatch-h);
            border: 1px solid var(--wr-border);
        }
        #wr-legend .wr-legend-provider,
        #wr-legend .wr-legend-title {
            font-weight: 600;
            margin-bottom: calc(4px * var(--k));
            font-size: var(--wr-fs-tiny);
            white-space: nowrap;
        }
        #wr-legend .wr-provider-select {
            display: block;
            width: auto;
            max-width: 100%;
            margin: 0 0 calc(4px * var(--k));
            padding: 0 calc(16px * var(--k)) 0 0;
            border: 0;
            border-radius: 0;
            background-color: transparent;
            color: var(--wr-text);
            font: inherit;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
            appearance: auto;
            -webkit-appearance: menulist;
        }
        #wr-legend .wr-provider-select:focus-visible {
            outline: 1px solid var(--wr-btn-border);
            outline-offset: 2px;
        }
        #wr-legend .wr-provider-select:disabled {
            cursor: progress;
            opacity: .65;
        }
        #wr-legend .wr-provider-select option {
            color: #111;
            background: #fff;
        }
        #wr-legend .wr-legend-image {
            display: block;
            width: calc(120px * var(--k));
            max-width: 100%;
            height: calc(10px * var(--k));
            object-fit: fill;
            border: 1px solid var(--wr-border);
            background: rgba(255,255,255,.08);
            margin-bottom: calc(3px * var(--k));
        }
        #wr-legend .wr-legend-scale {
            display: flex;
            justify-content: space-between;
            gap: calc(6px * var(--k));
            font-size: calc(8px * var(--k));
            opacity: .9;
            line-height: 1.1;
        }
        #wr-legend .wr-legend-note {
            margin-top: calc(3px * var(--k));
            font-size: calc(8px * var(--k));
            opacity: .75;
            line-height: 1.1;
            max-width: calc(130px * var(--k));
        }
        #wr-forecast {
            bottom: 10px;
            right: 10px;
            display: flex;
            gap: var(--wr-gap);
            padding: calc(var(--wr-pad) - 2px) var(--wr-pad);
            border-radius: var(--wr-radius);
        }
        #wr-controls {
            top: 10px;
            right: 10px;
            width: var(--wr-controls-w);
        }
        #wr-controls .wr-row {
            display: flex;
            gap: calc(var(--wr-gap) - 2px);
            justify-content: space-between;
            margin-bottom: calc(var(--wr-gap) - 2px);
        }
        #wr-controls button {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--wr-btn-pad-v) 0;
            font-size: var(--wr-fs);
            line-height: 1;
            gap: 6px;
            min-width: 0;
            border-radius: calc(var(--wr-radius) - 2px);
            background: var(--wr-btn-bg);
            color: var(--wr-btn-fg);
            border: 1px solid var(--wr-btn-border);
            -webkit-appearance: none;
            appearance: none;
            background-image: none;
            box-shadow: none;
            cursor: pointer;
        }
        #wr-controls button:hover { filter: brightness(1.08); }
        #wr-controls label {
            display: block;
            font-size: var(--wr-fs-small);
            margin: 6px 0 2px;
        }
        #wr-frame-slider {
            width: 100%;
            height: var(--wr-range-h);
        }
        #wr-frame-time {
            display: block;
            margin-top: 6px;
            font-weight: 600;
            text-align: center;
            font-size: var(--wr-fs-small);
            cursor: pointer;
        }
        .wr-ico {
            width: 14px;
            height: 14px;
            display: inline-block;
        }
        .wr-ico svg {
            width: 100%;
            height: 100%;
            display: block;
            fill: currentColor;
        }
        .wr-forecast-entry {
            text-align: center;
            cursor: default;
        }
        .wr-forecast-entry img {
            width: var(--wr-forecast-icon);
            height: var(--wr-forecast-icon);
        }
        .wr-forecast-entry .wr-day {
            font-weight: 600;
            margin-bottom: 2px;
            font-size: var(--wr-fs);
        }
        .wr-forecast-entry .wr-temp {
            font-size: var(--wr-fs-small);
            opacity: .9;
        }
        #wr-status {
            display: none;
        }
        #wr-tile-debug {
            top: 10px;
            left: 58px;
            width: max-content;
            min-width: 0;
            max-width: min(240px, calc(100% - 68px));
            box-sizing: border-box;
            display: none;
            font-size: var(--wr-fs-small);
            line-height: 1.25;
            white-space: nowrap;
        }
        #wr-tile-debug .wr-debug-title {
            font-weight: 700;
            margin-bottom: 4px;
        }
        #wr-tile-debug .wr-debug-line {
            opacity: .95;
        }
        /* Tooltip 1:1 wie im Script: gleiche Variablen, gleiche Abstände, gleicher Schatten. */
        .tooltip {
            position: fixed;
            background: var(--bg);
            color: var(--text);
            font-size: var(--fs-small);
            padding: calc(var(--pad) - 2px) var(--pad);
            border-radius: var(--radius);
            box-shadow: 0 calc(8px * var(--k)) calc(18px * var(--k)) rgba(0,0,0,.28);
            white-space: normal;
            line-height: 1.25;
            max-width: calc(220px * var(--k));
            z-index: 2000;
        }
        @media (min-width: 701px) and (max-width: 1300px) {
            #wr-current {
                display: inline-flex;
                width: max-content;
                min-width: 340px;
                max-width: 560px;
            }
            #wr-current-values { flex: 0 0 auto; }
        }

        @media (min-width: 540px) {
            #wr-controls {
                width: 130px;
            }
            #wr-controls .wr-row {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                column-gap: 2px;
                margin-bottom: 6px;
            }
            #wr-controls button {
                border-radius: 2px;
                padding: 4px 0;
                font-size: 12px;
            }
            #wr-controls label {
                font-size: 12px;
                margin: 4px 0 2px;
            }
            #wr-frame-slider {
                height: 18px;
            }
            #wr-frame-time {
                font-size: 12px;
                margin-top: 4px;
            }
            .wr-ico {
                width: 12px;
                height: 12px;
            }
        }

        @media (max-width: 700px) and (min-width: 540px) {
            #wr-current {
                top: 8px;
                left: 56px;
                right: auto;
                transform: none;
                display: inline-flex;
                width: max-content;
                min-width: 0;
                max-width: calc(100vw - 56px - 16px - (130px + 16px));
                justify-content: flex-end;
                text-align: right;
                flex-wrap: wrap;
                gap: 6px;
            }
        }

        @media (max-width: 700px) {
            #wr-tile-debug {
                left: 8px;
                right: auto;
                top: 8px;
                width: max-content;
                min-width: 0;
                max-width: calc(100% - 16px);
                font-size: 10px;
                white-space: nowrap;
            }
        }

        @media (max-width: 539px) {
            #wr-current {
                top: 8px;
                left: auto;
                right: 8px;
                transform: none;
                display: inline-flex;
                width: max-content;
                min-width: 0;
                max-width: calc(100vw - 56px - 16px);
                justify-content: flex-end;
                text-align: right;
                flex-wrap: wrap;
                gap: 6px;
            }
            #wr-controls {
                left: auto;
                right: 8px;
                width: 120px;
            }
            #wr-controls .wr-row {
                gap: 4px;
                margin-bottom: 6px;
                display: flex;
            }
            #wr-controls button {
                padding: 6px 0;
                font-size: 11px;
                border-radius: 6px;
            }
            #wr-controls label {
                font-size: 10px;
                margin: 4px 0 2px;
            }
            #wr-frame-slider {
                height: 16px;
            }
            #wr-frame-time {
                font-size: 10px;
                margin-top: 4px;
            }
        }

        /* Nur ganz am Schluss / sehr kleine Handybreite: Vorhersage rechts moderat kompakter. */
        @media (max-width: 430px) {
            #wr-forecast {
                max-width: min(200px, calc(100% - 120px));
                padding: 4px 5px;
                gap: 3px;
            }

            .wr-forecast-entry {
                flex-basis: 26px;
                min-width: 26px;
            }

            .wr-forecast-entry img {
                width: 22px;
                height: 22px;
            }

            .wr-forecast-entry .wr-day {
                font-size: 8px;
            }

            .wr-forecast-entry .wr-temp {
                font-size: 8px;
            }
        }

    </style>

    <div id="map"></div>

    <div id="wr-tile-debug" class="wr-panel"></div>

    <div id="wr-current" class="wr-panel">
        <div class="wr-value"><img src="https://raw.githubusercontent.com/basmilius/weather-icons/dev/production/fill/svg/thermometer.svg" alt=""><span id="wr-temp">–</span></div>
        <div class="wr-value"><img src="https://raw.githubusercontent.com/basmilius/weather-icons/dev/production/fill/svg/humidity.svg" alt=""><span id="wr-humidity">–</span></div>
        <div class="wr-value"><img src="https://raw.githubusercontent.com/basmilius/weather-icons/dev/production/fill/svg/wind.svg" alt=""><span id="wr-wind">–</span></div>
        <div class="wr-value"><img src="https://raw.githubusercontent.com/basmilius/weather-icons/dev/production/fill/svg/rain.svg" alt=""><span id="wr-rain">–</span></div>
        <div class="wr-value"><img src="https://raw.githubusercontent.com/basmilius/weather-icons/dev/production/fill/svg/cloudy.svg" alt=""><span id="wr-clouds">–</span></div>
    </div>

    <div id="wr-controls" class="wr-panel">
        <div class="wr-row">
            <button id="wr-btn-prev" title="Vorheriger Frame" aria-label="Vorheriger">
                <span class="wr-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15.5 5.5 8.5 12l7 6.5-1.5 1.5L5.5 12l8.5-8.5 1.5 2z"/></svg></span>
            </button>
            <button id="wr-btn-play" title="Abspielen" aria-label="Abspielen">
                <span class="wr-ico" aria-hidden="true"></span>
            </button>
            <button id="wr-btn-next" title="Nächster Frame" aria-label="Nächster">
                <span class="wr-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m8.5 5.5 1.5-2L18.5 12l-8.5 8.5-1.5-1.5 7-6.5-7-6.5z"/></svg></span>
            </button>
        </div>
        <label for="wr-frame-slider">Zeit</label>
        <input id="wr-frame-slider" type="range" min="0" max="0" step="1" value="0">
        <span id="wr-frame-time">⏳</span>
    </div>

    <div id="wr-legend" class="wr-panel"></div>

    <div id="wr-forecast" class="wr-panel"></div>
    <div id="wr-status" class="wr-panel">Initialisierung...</div>
</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/h5wasm@0.10.3/dist/iife/h5wasm.min.js"></script>

<script>
(function wrScaleByTile(){
    function computeK(){
        const w = document.documentElement.clientWidth || window.innerWidth || 800;
        const k = Math.max(0.75, Math.min(1.0, w / 780));
        const root = document.getElementById('wetterradar-root');
        if (root) root.style.setProperty('--k', k.toFixed(3));
        if (window.wrMapRef && window.wrMapRef.invalidateSize) setTimeout(function() { window.wrMapRef.invalidateSize(); }, 0);
    }
    computeK();
    window.addEventListener('resize', computeK);
})();

const WR_INITIAL = {$initialJson};
const WR_ICON_URL = "https://raw.githubusercontent.com/basmilius/weather-icons/dev/production/fill/svg/";
const WR_ICON_MAP = {
    "01d":"clear-day","01n":"clear-night",
    "02d":"partly-cloudy-day","02n":"partly-cloudy-night",
    "03d":"cloudy","03n":"cloudy",
    "04d":"overcast","04n":"overcast",
    "09d":"rain","09n":"rain","10d":"rain","10n":"rain",
    "11d":"thunderstorms-day","11n":"thunderstorms-night",
    "13d":"snow","13n":"snow",
    "50d":"fog","50n":"fog"
};

let wrMap = null;
let wrBaseLayer = null;
let wrRadarLayer = null;
let wrData = WR_INITIAL;
let wrRadarPayload = null;
let wrConfig = (WR_INITIAL && WR_INITIAL.data && WR_INITIAL.data.config) ? WR_INITIAL.data.config : {};
let wrFrames = [];
let wrFrameIndex = 0;
let wrAnimationTimer = null;
let wrRadarRefreshTimer = null;
let wrLastRadarRequestAt = 0;
let wrRadarLayerCache = {};
let wrRadarObjectUrlCache = {};
let wrRadarLoadedAtCache = {};
let wrH5ReadyPromise = null;
let wrTileDebugStats = { frame: '-', provider: '-', source: '-', loadedAt: '-', started: 0, loaded: 0, errors: 0, complete: false };
const WR_ANIMATION_DELAY_MS = 1000;
const wrPlaySvg = '<svg viewBox="0 0 24 24"><path d="M8 5l12 7-12 7V5z"/></svg>';
const wrPauseSvg = '<svg viewBox="0 0 24 24"><path d="M8 5h3v14H8V5zm5 0h3v14h-3V5z"/></svg>';

function wrSetText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value || '–';
}

function wrNumber(value, digits) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Number(n.toFixed(digits || 0));
}

function wrRemoveTooltip() {
    const root = document.getElementById('wetterradar-root');
    const t = (root || document).querySelector('.tooltip');
    if (t) t.remove();
}

function wrShowForecastTooltip(entry, f) {
    wrRemoveTooltip();

    const t = document.createElement('div');
    t.className = 'tooltip';
    t.innerHTML =
        "<b style='display:block; margin-bottom:4px;'>" + (f.description || 'Vorhersage') + "</b>" +
        "<div>💧 " + wrNumber(f.humidity, 0) + " %</div>" +
        "<div>🌡️ " + Math.round(Number(f.max || 0)) + "° / " + Math.round(Number(f.min || 0)) + "°</div>" +
        "<div>💨 " + Math.round(Number(f.wind || 0)) + " km/h</div>" +
        (Number(f.rain || 0) > 0 ? "<div>🌧️ " + Number(f.rain).toFixed(1) + " mm</div>" : "") +
        (Number(f.snow || 0) > 0 ? "<div>❄️ " + Number(f.snow).toFixed(1) + " mm</div>" : "") +
        "<div>☁️ " + Math.round(Number(f.clouds || 0)) + " %</div>";

    // Im Modul in den Root hängen, damit die Script-Variablen (--bg/--text) wirklich greifen.
    const root = document.getElementById('wetterradar-root');
    (root || document.body).appendChild(t);

    const rect = entry.getBoundingClientRect();
    const margin = 8;
    const tRect = t.getBoundingClientRect();

    let left = rect.left + rect.width / 2 - tRect.width / 2;
    if (left < margin) left = margin;

    const maxLeft = window.innerWidth - margin - tRect.width;
    if (left > maxLeft) left = maxLeft;

    t.style.left = left + "px";
    t.style.top = (rect.top - 8) + "px";
    t.style.transform = "translate(0, -100%)";
}


function wrFrameTime(frame) {
    if (!frame || !frame.time) return 'Keine Radarframes';
    return new Date(Number(frame.time) * 1000).toLocaleTimeString();
}

function wrSetPlayState(isPlaying) {
    const btn = document.getElementById('wr-btn-play');
    if (!btn) return;
    btn.title = isPlaying ? 'Pause' : 'Abspielen';
    btn.setAttribute('aria-label', isPlaying ? 'Pause' : 'Abspielen');
    const ico = btn.querySelector('.wr-ico');
    if (ico) ico.innerHTML = isPlaying ? wrPauseSvg : wrPlaySvg;
}

function wrStopAnimation() {
    if (wrAnimationTimer) {
        clearTimeout(wrAnimationTimer);
        wrAnimationTimer = null;
    }
    wrSetPlayState(false);
}

function wrWrapFrameIndex(index) {
    if (!wrFrames.length) return 0;
    while (index >= wrFrames.length) index -= wrFrames.length;
    while (index < 0) index += wrFrames.length;
    return index;
}


function wrSetTileDebugVisible() {
    const box = document.getElementById('wr-tile-debug');
    if (!box) return;
    box.style.display = (wrConfig && wrConfig.showTileDebug) ? 'block' : 'none';
}

function wrUpdateTileDebug() {
    const box = document.getElementById('wr-tile-debug');
    if (!box) return;

    wrSetTileDebugVisible();
    if (!wrConfig || !wrConfig.showTileDebug) return;

    box.innerHTML =
        '<div class="wr-debug-title">Tile-Debug</div>' +
        '<div class="wr-debug-line">Provider: ' + wrEscapeHtml(wrTileDebugStats.provider) + '</div>' +
        '<div class="wr-debug-line">Frame: ' + wrEscapeHtml(wrTileDebugStats.frame) + '</div>' +
        '<div class="wr-debug-line">Quelle: ' + wrEscapeHtml(wrTileDebugStats.source || '-') + '</div>' +
        '<div class="wr-debug-line">Geladen um: ' + wrEscapeHtml(wrTileDebugStats.loadedAt || '-') + '</div>' +
        '<div class="wr-debug-line">Start: ' + wrTileDebugStats.started + '</div>' +
        '<div class="wr-debug-line">Geladen: ' + wrTileDebugStats.loaded + '</div>' +
        '<div class="wr-debug-line">Fehler: ' + wrTileDebugStats.errors + '</div>' +
        '<div class="wr-debug-line">Status: ' + (wrTileDebugStats.complete ? 'vollständig' : 'lädt') + '</div>';
}

function wrAttachTileDebug(layer, frame, layerType, cacheKey) {
    if (!layer || !frame) return layer;

    const frameLabel = frame.time ? new Date(Number(frame.time) * 1000).toLocaleTimeString() : '-';
    wrTileDebugStats = {
        frame: frameLabel,
        provider: wrRadarPayload && wrRadarPayload.provider ? wrRadarPayload.provider : '-',
        source: layerType === 'image' ? 'Neu geladen (HDF5 → PNG)' : 'Neu angefordert',
        loadedAt: '-',
        started: 0,
        loaded: 0,
        errors: 0,
        complete: false
    };
    wrUpdateTileDebug();

    if (layerType === 'image') {
        wrTileDebugStats.started = 1;
        wrUpdateTileDebug();
        layer.on('load', function() {
            const loadedAt = new Date().toLocaleTimeString();
            if (cacheKey) wrRadarLoadedAtCache[cacheKey] = loadedAt;
            wrTileDebugStats.loaded = 1;
            wrTileDebugStats.loadedAt = loadedAt;
            wrTileDebugStats.complete = true;
            wrUpdateTileDebug();
        });
        layer.on('error', function() {
            wrTileDebugStats.errors++;
            wrTileDebugStats.complete = true;
            wrUpdateTileDebug();
        });
        return layer;
    }

    layer.on('tileloadstart', function() {
        wrTileDebugStats.started++;
        wrTileDebugStats.complete = false;
        wrUpdateTileDebug();
    });
    layer.on('tileload', function() {
        wrTileDebugStats.loaded++;
        wrUpdateTileDebug();
    });
    layer.on('tileerror', function() {
        wrTileDebugStats.errors++;
        wrUpdateTileDebug();
    });
    layer.on('load', function() {
        const loadedAt = new Date().toLocaleTimeString();
        if (cacheKey) wrRadarLoadedAtCache[cacheKey] = loadedAt;
        wrTileDebugStats.loadedAt = loadedAt;
        wrTileDebugStats.complete = true;
        wrUpdateTileDebug();
    });

    return layer;
}

function wrGetH5Module() {
    if (!wrH5ReadyPromise) {
        wrH5ReadyPromise = h5wasm.ready;
    }
    return wrH5ReadyPromise;
}

function wrLv95FromWgs84(lat, lon) {
    const latp = (lat * 3600 - 169028.66) / 10000;
    const lonp = (lon * 3600 - 26782.5) / 10000;
    const east = 2600072.37 + 211455.93 * lonp - 10938.51 * lonp * latp
        - 0.36 * lonp * latp * latp - 44.54 * lonp * lonp * lonp;
    const north = 1200147.07 + 308807.95 * latp + 3745.25 * lonp * lonp
        + 76.63 * latp * latp - 194.56 * lonp * lonp * latp
        + 119.79 * latp * latp * latp;
    return [east, north];
}

function wrMeteoswissColor(value) {
    // Kräftige, hoch gesättigte Blau–Gelb–Rot–Violett-Palette für die
    // selbst gerenderten MeteoSwiss-HDF5-Daten. Die Intensitätsgrenzen
    // bleiben unverändert; nur Kontrast, Sättigung und Deckkraft sind erhöht.
    if (!Number.isFinite(value) || value < 0.1) return [0, 0, 0, 0];
    if (value < 0.5)  return [0, 210, 255, 255];  // kräftiges cyan
    if (value < 2.5)  return [0, 160, 210, 255];  // kräftiges türkis
    if (value < 10.0) return [0, 90, 170, 255];   // sattes dunkelblau
    if (value < 25.0) return [255, 220, 0, 255];  // kräftiges gelb
    if (value < 50.0) return [255, 130, 0, 255];  // kräftiges orange
    if (value < 75.0) return [235, 30, 40, 255];  // kräftiges rot
    return [190, 0, 170, 255];                    // kräftiges magenta / violett
}

async function wrBuildMeteoswissImage(frame, layer, cacheKey) {
    try {
        const Module = await wrGetH5Module();
        const response = await fetch(frame.url, { cache: 'force-cache' });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const bytes = new Uint8Array(await response.arrayBuffer());
        const filename = '/tmp/' + String(cacheKey).replace(/[^a-zA-Z0-9_.-]/g, '_') + '.h5';
        Module.FS.writeFile(filename, bytes);

        const file = new h5wasm.File(filename, 'r');
        const dataset = file.get('dataset1/data1/data');
        const values = dataset.value;
        const shape = dataset.shape;
        const srcHeight = Number(shape[0]);
        const srcWidth = Number(shape[1]);

        const bounds = frame.bounds || [[43.619, 2.68942], [49.3744, 12.4623]];
        const south = Number(bounds[0][0]);
        const west = Number(bounds[0][1]);
        const north = Number(bounds[1][0]);
        const east = Number(bounds[1][1]);

        const canvas = document.createElement('canvas');
        canvas.width = srcWidth;
        canvas.height = srcHeight;
        const ctx = canvas.getContext('2d');
        const image = ctx.createImageData(srcWidth, srcHeight);
        const rgba = image.data;

        // MeteoSwiss-Raster: LV95, 1 km, E=2255000..2965000, N=840000..1480000.
        // Für Leaflet wird es in ein geographisch rechteckiges WGS84-Bild umgerechnet.
        const srcMinE = 2255000;
        const srcMaxN = 1480000;
        const scale = 1000;

        for (let y = 0; y < srcHeight; y++) {
            const lat = north - ((y + 0.5) / srcHeight) * (north - south);
            for (let x = 0; x < srcWidth; x++) {
                const lon = west + ((x + 0.5) / srcWidth) * (east - west);
                const lv = wrLv95FromWgs84(lat, lon);
                const sx = Math.floor((lv[0] - srcMinE) / scale);
                const sy = Math.floor((srcMaxN - lv[1]) / scale);
                const out = (y * srcWidth + x) * 4;

                if (sx < 0 || sx >= srcWidth || sy < 0 || sy >= srcHeight) continue;
                const value = Number(values[sy * srcWidth + sx]);
                const color = wrMeteoswissColor(value);
                rgba[out] = color[0];
                rgba[out + 1] = color[1];
                rgba[out + 2] = color[2];
                rgba[out + 3] = color[3];
            }
        }

        ctx.putImageData(image, 0, 0);
        file.close();
        try { Module.FS.unlink(filename); } catch (e) {}

        const blob = await new Promise(function(resolve, reject) {
            canvas.toBlob(function(result) {
                if (result) resolve(result); else reject(new Error('PNG-Erzeugung fehlgeschlagen'));
            }, 'image/png');
        });
        const objectUrl = URL.createObjectURL(blob);
        wrRadarObjectUrlCache[cacheKey] = objectUrl;
        wrTileDebugStats.source = 'Neu geladen (HDF5 → PNG)';
        wrUpdateTileDebug();
        layer.setUrl(objectUrl);
    } catch (e) {
        wrTileDebugStats.errors++;
        wrTileDebugStats.complete = true;
        wrUpdateTileDebug();
        console.error('MeteoSwiss Radar konnte nicht geladen werden:', e);
    }
}

function wrBuildRadarLayer(frame) {
    if (!wrRadarPayload || !frame) return null;

    const cacheKey = wrRadarCacheKey(frame);
    let layer = null;

    if (wrRadarPayload.provider === 'rainviewer') {
        const host = wrRadarPayload.host || '';
        const tileSize = window.devicePixelRatio >= 2 ? 512 : 256;
        layer = L.tileLayer(
            host + frame.path + '/' + tileSize + '/{z}/{x}/{y}/2/1_1.png',
            { tileSize: 256, opacity: 0, maxNativeZoom: 7, maxZoom: 7 }
        );
    }

    if (wrRadarPayload.provider === 'rainbow') {
        layer = L.tileLayer(
            frame.url,
            { tileSize: 256, opacity: 0, maxNativeZoom: 12, maxZoom: 12 }
        );
    }

    if (wrRadarPayload.provider === 'meteoswiss') {
        const bounds = frame.bounds || [[43.619, 2.68942], [49.3744, 12.4623]];
        const transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
        layer = L.imageOverlay(transparentPixel, bounds, {
            opacity: 0,
            interactive: false,
            crossOrigin: false
        });
        wrAttachTileDebug(layer, frame, 'image');
        const key = wrRadarCacheKey(frame);
        if (wrRadarObjectUrlCache[key]) {
            wrTileDebugStats.source = 'PNG-Cache';
            wrTileDebugStats.complete = true;
            wrUpdateTileDebug();
            layer.setUrl(wrRadarObjectUrlCache[key]);
        } else {
            wrBuildMeteoswissImage(frame, layer, key);
        }
        return layer;
    }

    return wrAttachTileDebug(layer, frame, 'tile', cacheKey);
}

function wrRadarCacheKey(frame) {
    if (!wrRadarPayload || !frame) return '';

    // Wichtig: nicht nach Index cachen, weil sich die Timeline beim nächsten Radar-Update verschiebt.
    // Der Key enthält Provider + Zeit + Tile-Quelle. Dadurch werden unveränderte Frames wiederverwendet,
    // aber Provider-/Layer-/Farbwechsel oder neue URLs trotzdem sauber neu geladen.
    if (wrRadarPayload.provider === 'rainviewer') {
        return 'rainviewer|' + String(frame.time || '') + '|' + String(frame.path || '');
    }

    if (wrRadarPayload.provider === 'rainbow') {
        return 'rainbow|' + String(frame.time || '') + '|' + String(frame.url || '');
    }

    if (wrRadarPayload.provider === 'meteoswiss') {
        return 'meteoswiss|' + String(frame.time || '') + '|' + String(frame.url || '');
    }

    return String(frame.time || '') + '|' + JSON.stringify(frame);
}

function wrPruneRadarLayerCache(validKeys) {
    for (const key in wrRadarLayerCache) {
        if (Object.prototype.hasOwnProperty.call(wrRadarLayerCache, key) && !validKeys[key]) {
            try { wrMap.removeLayer(wrRadarLayerCache[key]); } catch(e) {}
            if (wrRadarLayer === wrRadarLayerCache[key]) {
                wrRadarLayer = null;
            }
            delete wrRadarLayerCache[key];
            if (wrRadarObjectUrlCache[key]) {
                try { URL.revokeObjectURL(wrRadarObjectUrlCache[key]); } catch(e) {}
                delete wrRadarObjectUrlCache[key];
            }
            delete wrRadarLoadedAtCache[key];
        }
    }
}

function wrShowFrame(index) {
    if (!wrMap || !wrFrames.length) return;

    index = wrWrapFrameIndex(index);
    const frame = wrFrames[index];
    const cacheKey = wrRadarCacheKey(frame);
    const slider = document.getElementById('wr-frame-slider');

    if (wrRadarLayer && wrRadarLayer !== wrRadarLayerCache[cacheKey]) {
        try { wrRadarLayer.setOpacity(0); } catch(e) {}
    }

    let layer = wrRadarLayerCache[cacheKey] || null;

    // Nur dann als Cache-Treffer anzeigen, wenn wirklich auf einen anderen,
    // bereits vorhandenen Frame-Layer gewechselt wird.
    //
    // Beim ersten Öffnen wird der Initial-Frame aus WR_INITIAL aufgebaut und
    // unmittelbar danach nochmals RefreshRadar ausgelöst. Dabei wird derselbe
    // bereits sichtbare Layer erneut ausgewählt. Das ist kein echter
    // Frame-Wechsel aus dem Cache und darf die laufenden Tile-Zähler nicht
    // mit "(Cache)" überschreiben.
    const isDifferentCachedLayer = layer && layer !== wrRadarLayer;
    if (isDifferentCachedLayer && wrConfig && wrConfig.showTileDebug) {
        const frameLabel = frame.time ? new Date(Number(frame.time) * 1000).toLocaleTimeString() : '-';
        wrTileDebugStats = {
            frame: frameLabel + ' (Cache)',
            provider: wrRadarPayload && wrRadarPayload.provider ? wrRadarPayload.provider : '-',
            source: 'JavaScript-Layer-Cache',
            loadedAt: wrRadarLoadedAtCache[cacheKey] || '-',
            started: 0,
            loaded: 0,
            errors: 0,
            complete: true
        };
        wrUpdateTileDebug();
    }
    if (!layer) {
        layer = wrBuildRadarLayer(frame);
        if (!layer) return;
        wrRadarLayerCache[cacheKey] = layer;
        layer.addTo(wrMap);
    }

    layer.setOpacity(0.5);
    wrRadarLayer = layer;
    wrFrameIndex = index;

    if (slider) slider.value = String(index);
    wrSetText('wr-frame-time', wrFrameTime(frame));
}

function wrPlayAnimation() {
    wrStopAnimation();
    wrSetPlayState(true);

    function step() {
        wrShowFrame(wrFrameIndex + 1);
        wrAnimationTimer = setTimeout(step, WR_ANIMATION_DELAY_MS);
    }

    wrAnimationTimer = setTimeout(step, WR_ANIMATION_DELAY_MS);
}

function wrToggleAnimation() {
    if (wrAnimationTimer) {
        wrStopAnimation();
        return;
    }
    wrPlayAnimation();
}

function wrClearRadarLayers() {
    for (const key in wrRadarLayerCache) {
        if (Object.prototype.hasOwnProperty.call(wrRadarLayerCache, key)) {
            try { wrMap.removeLayer(wrRadarLayerCache[key]); } catch(e) {}
        }
    }
    wrRadarLayerCache = {};
    for (const key in wrRadarObjectUrlCache) {
        if (Object.prototype.hasOwnProperty.call(wrRadarObjectUrlCache, key)) {
            try { URL.revokeObjectURL(wrRadarObjectUrlCache[key]); } catch(e) {}
        }
    }
    wrRadarObjectUrlCache = {};
    wrRadarLoadedAtCache = {};
    wrRadarLayer = null;
}

function wrSetupControls() {
    wrSetPlayState(false);

    const prev = document.getElementById('wr-btn-prev');
    const next = document.getElementById('wr-btn-next');
    const play = document.getElementById('wr-btn-play');
    const slider = document.getElementById('wr-frame-slider');

    if (prev) prev.addEventListener('click', function() { wrStopAnimation(); wrShowFrame(wrFrameIndex - 1); });
    if (next) next.addEventListener('click', function() { wrStopAnimation(); wrShowFrame(wrFrameIndex + 1); });
    if (play) play.addEventListener('click', wrToggleAnimation);
    if (slider) slider.addEventListener('input', function(e) {
        wrStopAnimation();
        wrShowFrame(parseInt(e.target.value, 10));
    });
}

function wrRenderForecast(forecast) {
    const box = document.getElementById('wr-forecast');
    if (!box) return;

    box.innerHTML = '';

    if (!Array.isArray(forecast) || forecast.length === 0) {
        box.style.display = 'none';
        return;
    }

    box.style.display = 'flex';

    forecast.forEach(function(f) {
        if (!f || !f.dt) return;

        const d = new Date(Number(f.dt) * 1000);
        const day = d.toLocaleDateString('de-CH', { weekday: 'short' });
        const mappedIcon = WR_ICON_MAP[f.icon] || 'not-available';
        const icon = WR_ICON_URL + mappedIcon + '.svg';

        const entry = document.createElement('div');
        entry.className = 'wr-forecast-entry';
        entry.innerHTML =
            "<div class='wr-day'>" + day + "</div>" +
            "<img alt='' src='" + icon + "'>" +
            "<div class='wr-temp'>" + Math.round(Number(f.max || 0)) + "°/" + Math.round(Number(f.min || 0)) + "°</div>";

        entry.addEventListener('mouseenter', function() {
            wrShowForecastTooltip(entry, f);
        });
        entry.addEventListener('mouseleave', wrRemoveTooltip);

        box.appendChild(entry);
    });
}

function wrEscapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function wrRenderLegend(legend) {
    const box = document.getElementById('wr-legend');
    if (!box) return;

    if (!legend) {
        box.style.display = 'none';
        return;
    }

    box.style.display = 'block';
    box.innerHTML = '';

    if (legend.provider) {
        const providerNames = {
            rainviewer: 'RainViewer',
            rainbow: 'Rainbow',
            meteoswiss: 'MeteoSwiss'
        };
        const currentProvider = (wrConfig && wrConfig.radarProvider)
            ? String(wrConfig.radarProvider)
            : String((wrRadarPayload && wrRadarPayload.provider) || 'rainviewer');
        const providerSelect = document.createElement('select');
        providerSelect.className = 'wr-provider-select';
        providerSelect.title = 'Radar-Provider wechseln';
        providerSelect.setAttribute('aria-label', 'Radar-Provider wechseln');

        Object.keys(providerNames).forEach(function(value) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = providerNames[value];
            option.selected = value === currentProvider;
            providerSelect.appendChild(option);
        });

        providerSelect.addEventListener('change', function() {
            const selectedProvider = providerSelect.value;
            if (selectedProvider === currentProvider) return;
            providerSelect.disabled = true;
            wrStopAnimation();
            wrStopRadarRefreshTimer();
            requestAction('SetRadarProvider', selectedProvider);
        });
        box.appendChild(providerSelect);
    }

    if (legend.title) {
        const title = document.createElement('div');
        title.className = 'wr-legend-title';
        title.textContent = legend.title;
        box.appendChild(title);
    }

    if (legend.type === 'image' && legend.image) {
        const img = document.createElement('img');
        img.className = 'wr-legend-image';
        img.alt = legend.title || 'Radar-Farbskala';
        img.src = legend.image;
        box.appendChild(img);

        const scale = document.createElement('div');
        scale.className = 'wr-legend-scale';
        scale.innerHTML = '<span>leicht</span><span>stark</span>';
        box.appendChild(scale);

        if (legend.note) {
            const note = document.createElement('div');
            note.className = 'wr-legend-note';
            note.textContent = legend.note;
            box.appendChild(note);
        }
        return;
    }

    if (Array.isArray(legend.entries)) {
        legend.entries.forEach(function(entry) {
            const row = document.createElement('div');
            row.className = 'wr-legend-entry';
            row.innerHTML =
                '<div class="wr-legend-color" style="background:' + wrEscapeHtml(entry.color || '#999') + ';"></div> ' +
                wrEscapeHtml(entry.label || '');
            box.appendChild(row);
        });
    }
}

function wrRenderWeather(payload) {
    if (!payload || !payload.current) return;

    wrSetText('wr-temp', payload.current.temperature);
    wrSetText('wr-humidity', payload.current.humidity);
    wrSetText('wr-wind', payload.current.wind);
    wrSetText('wr-rain', payload.current.rain);
    wrSetText('wr-clouds', payload.current.clouds);
    wrRenderForecast(payload.forecast || []);
}

function wrInitMap(config) {
    if (!config || wrMap) return;

    wrRenderLegend(config.legend || null);
    wrSetTileDebugVisible();

    const root = document.getElementById('wetterradar-root');
    if (root && config.theme === 'light') {
        root.classList.add('wr-light');
    }

    wrMap = L.map('map', {
        zoomControl: true,
        attributionControl: true,
        maxZoom: config.radarMaxZoom || 7
    }).setView([config.lat, config.lon], config.zoom || 7);
    window.wrMapRef = wrMap;

    const options = {
        maxZoom: config.radarMaxZoom || 7,
        attribution: config.mapAttribution || ''
    };

    if (Array.isArray(config.mapSubdomains) && config.mapSubdomains.length > 0) {
        options.subdomains = config.mapSubdomains;
    }

    wrBaseLayer = L.tileLayer(config.mapTileUrl, options).addTo(wrMap);
    L.marker([config.lat, config.lon]).addTo(wrMap).bindPopup('Standort');
}

function wrRenderRadar(payload) {
    if (!payload || !wrMap) return;

    // Bei einer laufenden Animation den Zustand über das Radar-Update hinweg erhalten.
    const wasPlaying = wrAnimationTimer !== null;
    wrStopAnimation();
    wrRadarPayload = payload;

    wrFrames = Array.isArray(payload.frames) ? payload.frames.slice() : [];

    // Bestehende Radar-TileLayer behalten, solange der Frame in der neuen Timeline noch existiert.
    // So werden unveränderte Frames beim Timer-Update nicht unnötig neu geladen.
    const validCacheKeys = {};
    wrFrames.forEach(function(frame) {
        const key = wrRadarCacheKey(frame);
        if (key) validCacheKeys[key] = true;
    });
    wrPruneRadarLayerCache(validCacheKeys);

    const slider = document.getElementById('wr-frame-slider');

    if (!wrFrames.length) {
        if (slider) {
            slider.max = '0';
            slider.value = '0';
        }
        wrSetText('wr-frame-time', payload.error || 'Keine Radardaten');
        return;
    }

    if (slider) {
        slider.max = String(wrFrames.length - 1);
        slider.value = '0';
    }

    // RainViewer und MeteoSwiss zeigen den neuesten vorhandenen Messframe.
    // Rainbow startet unabhängig von der konfigurierten Zukunftsdauer exakt beim Snapshot "jetzt".
    if (payload.provider === 'rainviewer' || payload.provider === 'meteoswiss') {
        wrFrameIndex = wrFrames.length - 1;
    } else if (payload.provider === 'rainbow' && payload.snapshot) {
        const currentIndex = wrFrames.findIndex(function(frame) {
            return Number(frame.time) === Number(payload.snapshot);
        });
        wrFrameIndex = currentIndex >= 0 ? currentIndex : 0;
    } else {
        wrFrameIndex = 0;
    }

    wrShowFrame(wrFrameIndex);

    // Konfiguriertes Autoplay startet initial automatisch. Wurde die Animation
    // manuell gestartet, läuft sie nach einem Poll ebenfalls weiter.
    if (wasPlaying || (wrConfig && wrConfig.enableAutoplay)) {
        wrPlayAnimation();
    }
}

function wrHandlePayload(payload) {
    if (!payload || !payload.type) return;
    wrData = payload;
    if (payload.data && payload.data.config) {
        wrConfig = payload.data.config;
        wrRenderLegend(wrConfig.legend || null);
        wrSetTileDebugVisible();
    }

    if (payload.type === 'reload') {
        try {
            window.location.reload();
            return;
        } catch (e) {
            if (payload.data) {
                wrInitMap(payload.data.config);
                wrRenderWeather(payload.data.weather);
                wrRenderRadar(payload.data.radar);
            }
            return;
        }
    }

    if (payload.type === 'init') {
        wrInitMap(payload.data.config);
        wrRenderWeather(payload.data.weather);
        wrRenderRadar(payload.data.radar);
        return;
    }

    if (payload.type === 'weather') {
        wrRenderWeather(payload.data);
        return;
    }

    if (payload.type === 'radar') {
        wrRenderRadar(payload.data);
        return;
    }
}

function handleMessage(message) {
    try {
        const payload = JSON.parse(message);
        wrHandlePayload(payload);
    } catch (e) {
        wrSetText('wr-status', 'Fehler: ' + e.message);
    }
}


function wrGetRadarRefreshMilliseconds() {
    const seconds=Math.max(60,Number(wrConfig&&wrConfig.radarRefreshSeconds)||600);
    return seconds*1000;
}
function wrStartRadarRefreshTimer(){
    wrStopRadarRefreshTimer();
    if(document.visibilityState!=='visible') return;
    wrRadarRefreshTimer=window.setInterval(function(){
        if(document.visibilityState==='visible'){requestAction('RefreshRadar',true);}
    },wrGetRadarRefreshMilliseconds());
}
function wrStopRadarRefreshTimer(){
    if(wrRadarRefreshTimer!==null){clearInterval(wrRadarRefreshTimer);wrRadarRefreshTimer=null;}
}
function wrHandleRadarVisibilityChange(){
    if(document.visibilityState==='visible'){
        requestAction('RefreshRadar',true);
        wrStartRadarRefreshTimer();
    }else{
        wrStopRadarRefreshTimer();
        wrStopAnimation();
    }
}
function wrPlaceControlsOnPhone() {
    const root = document.getElementById('wetterradar-root');
    const controls = document.getElementById('wr-controls');
    const current = document.getElementById('wr-current');
    const debug = document.getElementById('wr-tile-debug');
    if (!root || !controls || !current) return;

    let positionFrame = null;

    function updateNow() {
        const rootRect = root.getBoundingClientRect();
        const isPhone = window.matchMedia('(max-width: 539px)').matches;
        const isSmall = window.matchMedia('(max-width: 700px)').matches;

        if (isPhone) {
            const currentRect = current.getBoundingClientRect();
            const controlsTop = Math.max(8, currentRect.bottom - rootRect.top + 3);
            controls.style.top = controlsTop + 'px';
        } else {
            controls.style.top = '10px';
        }

        if (debug) {
            if (isSmall) {
                const currentRect = current.getBoundingClientRect();
                const controlsRect = controls.getBoundingClientRect();
                const gap = 6;

                // MOBILE TILE-DEBUG FIX v2:
                // Linke Kante = linke Kante der Wetterdatenbox.
                // Obere Kante = obere Kante des Steuerfelds.
                // Rechte Kante endet vor dem Steuerfeld.
                const left = Math.max(8, Math.round(currentRect.left - rootRect.left));
                const top = Math.max(8, Math.round(controlsRect.top - rootRect.top));
                const controlsLeft = Math.round(controlsRect.left - rootRect.left);
                const availableWidth = Math.max(0, controlsLeft - left - gap);

                debug.style.setProperty('left', left + 'px', 'important');
                debug.style.setProperty('right', 'auto', 'important');
                debug.style.setProperty('top', top + 'px', 'important');
                debug.style.setProperty('min-width', '0', 'important');
                debug.style.setProperty('box-sizing', 'border-box', 'important');

                // Zuerst natürliche Textbreite ohne Umbruch bestimmen.
                debug.style.setProperty('width', 'max-content', 'important');
                debug.style.setProperty('max-width', 'none', 'important');
                debug.style.setProperty('white-space', 'nowrap', 'important');
                const naturalWidth = Math.ceil(debug.getBoundingClientRect().width);

                // Nur den tatsächlich benötigten Platz verwenden, jedoch nie ins Steuerfeld ragen.
                const finalWidth = Math.min(naturalWidth, availableWidth);
                debug.style.setProperty('width', Math.max(0, finalWidth) + 'px', 'important');
                debug.style.setProperty('max-width', Math.max(0, availableWidth) + 'px', 'important');
                debug.style.setProperty(
                    'white-space',
                    naturalWidth > availableWidth ? 'normal' : 'nowrap',
                    'important'
                );
                debug.style.setProperty('overflow-wrap', 'anywhere', 'important');
            } else {
                debug.style.removeProperty('right');
                debug.style.setProperty('top', '10px', 'important');
                debug.style.setProperty('left', '58px', 'important');
                debug.style.setProperty('width', 'max-content', 'important');
                debug.style.setProperty('max-width', 'min(240px, calc(100% - 68px))', 'important');
                debug.style.setProperty('white-space', 'nowrap', 'important');
                debug.style.removeProperty('overflow-wrap');
            }
        }

        if (wrMap && wrMap.invalidateSize) {
            setTimeout(function() { wrMap.invalidateSize(); }, 0);
        }
    }

    function update() {
        if (positionFrame !== null) {
            cancelAnimationFrame(positionFrame);
        }
        positionFrame = requestAnimationFrame(function() {
            positionFrame = null;
            updateNow();
        });
    }

    update();
    window.addEventListener('resize', update);
    window.addEventListener('orientationchange', update);

    try {
        const observer = new ResizeObserver(update);
        observer.observe(root);
        observer.observe(current);
        observer.observe(controls);
    } catch(e) {}

    if (debug) {
        try {
            new MutationObserver(update).observe(debug, {
                childList: true,
                subtree: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['style', 'class']
            });
        } catch(e) {}
    }

    // Nach Aufbau und Schrift-/Layoutberechnung nochmals sicher positionieren.
    setTimeout(update, 0);
    setTimeout(update, 100);
    setTimeout(update, 300);
}


wrSetupControls();
wrHandlePayload(WR_INITIAL);
wrPlaceControlsOnPhone();

// WR_INITIAL kann von IP-Symcon aus einem bereits erzeugten HTML stammen.
// Deshalb beim tatsächlichen Öffnen der sichtbaren Visualisierung immer
// einmal aktuelle Radar-Metadaten anfordern. Danach läuft der normale Rhythmus.
wrLastRadarRequestAt = 0;

document.addEventListener('visibilitychange', wrHandleRadarVisibilityChange);
window.addEventListener('pagehide', function() {
    wrStopRadarRefreshTimer();
    wrStopAnimation();
});
window.addEventListener('beforeunload', function() {
    wrStopRadarRefreshTimer();
    wrStopAnimation();
});

requestAction('RefreshRadar', true);
wrStartRadarRefreshTimer();

setTimeout(function() {
    if (wrMap) wrMap.invalidateSize();
}, 250);
</script>
HTML;
    }

    public function UpdateWeather(): void
    {
        $this->SendDebug('UpdateWeather', 'Wetter-Aktualisierung gestartet', 0);
        $this->SendVisualizationMessage('weather', $this->BuildWeatherPayload());
    }

    public function UpdateRadar(): void
    {
        $provider = $this->ReadPropertyString('RadarProvider');
        $payload = $this->BuildRadarPayload();
        $signature = $this->BuildRadarFrameSignature($payload);
        $previousSignature = $this->ReadAttributeString('LastRadarFrameSignature');

        $cacheState = 'keine Frames';
        if ($signature !== '') {
            if ($signature === $previousSignature) {
                $cacheState = 'unverändert – vorhandene Browser-Layer werden aus dem Cache verwendet';
            } else {
                $cacheState = 'neuer Frame – nur neue Tiles bzw. HDF5-Daten werden geladen';
                $this->WriteAttributeString('LastRadarFrameSignature', $signature);
            }
        }

        $this->SendDebug(
            'UpdateRadar',
            'Provider=' . $provider . ', Status=' . $cacheState,
            0
        );

        $this->SendVisualizationMessage('radar', $payload);
    }

    public function ReloadHtml(): void
    {
        $this->SendVisualizationMessage('reload', [
            'config'  => $this->BuildClientConfig(),
            'weather' => $this->BuildWeatherPayload(),
            'radar'   => $this->BuildRadarPayload()
        ]);
    }

    private function SendVisualizationMessage(string $type, array $data): void
    {
        $payload = [
            'type' => $type,
            'data' => $data
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $this->UpdateVisualizationValue($json);
        }
    }

    private function BuildClientConfig(): array
    {
        [$lat, $lon] = $this->GetLocation();
        $provider = $this->ReadPropertyString('RadarProvider');
        $radarMaxZoom = ($provider === 'rainbow' || $provider === 'meteoswiss') ? 12 : 7;
        $zoom = min(max(1, $this->ReadPropertyInteger('Zoom')), $radarMaxZoom);
        [$mapTileUrl, $mapAttribution, $subdomains] = $this->GetMapStyle();

        return [
            'lat' => $lat,
            'lon' => $lon,
            'zoom' => $zoom,
            'theme' => $this->ReadPropertyString('Theme'),
            'radarProvider' => $provider,
            'radarMaxZoom' => $radarMaxZoom,
            'radarRefreshSeconds' => max(60, $this->ReadPropertyInteger('RadarRefreshSeconds')),
            'enableAutoplay' => $this->ReadPropertyBoolean('EnableAutoplay'),
            'showTileDebug' => $this->ReadPropertyBoolean('ShowTileDebug'),
            'legend' => $this->BuildRadarLegendPayload(),
            'mapTileUrl' => $mapTileUrl,
            'mapAttribution' => $mapAttribution,
            'mapSubdomains' => $subdomains
        ];
    }

    private function RefreshWeatherWatchRegistrations(): void
    {
        $oldIDs = json_decode($this->ReadAttributeString('WeatherWatchIDs'), true);
        if (!is_array($oldIDs)) {
            $oldIDs = [];
        }

        foreach (array_unique(array_map('intval', $oldIDs)) as $id) {
            if ($id > 0) {
                $this->UnregisterMessage($id, VM_UPDATE);
            }
        }

        $newIDs = $this->CollectWeatherWatchIDs();
        foreach ($newIDs as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
        }

        $this->WriteAttributeString('WeatherWatchIDs', json_encode($newIDs, JSON_UNESCAPED_SLASHES));
    }

    private function CollectWeatherWatchIDs(): array
    {
        $owmInstID = $this->ReadPropertyInteger('OpenWeatherInstanceID');
        $ids = [];

        // Aktuelle Werte: eigene Variablen oder Fallback aus OpenWeather.
        $ids[] = $this->ResolveVariableID('TemperatureID', 'Temperature', $owmInstID);
        $ids[] = $this->ResolveVariableID('HumidityID', 'Humidity', $owmInstID);
        $ids[] = $this->ResolveVariableID('WindSpeedID', 'WindSpeed', $owmInstID);
        $ids[] = $this->ResolveVariableID('Rain1hID', 'Rain_1h', $owmInstID);
        $ids[] = $this->GetObjectIDByIdentSafe('Cloudiness', $owmInstID);

        // Forecast-Werte aus OpenWeather überwachen.
        if ($owmInstID > 0 && @IPS_ObjectExists($owmInstID)) {
            $count = (int) @IPS_GetProperty($owmInstID, 'daily_forecast_count');
            if ($count <= 0) {
                $count = 5;
            }

            $forecastIdents = [
                'DailyForecastBegin',
                'DailyForecastTemperatureMin',
                'DailyForecastTemperatureMax',
                'DailyForecastWindSpeed',
                'DailyForecastRain',
                'DailyForecastSnow',
                'DailyForecastConditionIcon',
                'DailyForecastConditions',
                'DailyForecastHumidity',
                'DailyForecastCloudiness'
            ];

            for ($i = 0; $i < $count; $i++) {
                $post = '_' . sprintf('%02d', $i);
                foreach ($forecastIdents as $identPrefix) {
                    $ids[] = $this->GetObjectIDByIdentSafe($identPrefix . $post, $owmInstID);
                }
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));

        sort($ids);
        return $ids;
    }

    private function BuildRadarLegendPayload(): array
    {
        $provider = $this->ReadPropertyString('RadarProvider');

        $providerName = match ($provider) {
            'rainbow' => 'Rainbow',
            'meteoswiss' => 'MeteoSwiss',
            default => 'RainViewer',
        };

        // RainViewer und Rainbow behalten ihre bisherige Tile-Legende.
        // MeteoSwiss wird im Browser selbst gerendert und erhält deshalb die
        // kräftigeren Farben aus wrMeteoswissColor(), damit Legende und Bild
        // weiterhin exakt übereinstimmen.
        $entries = $provider === 'meteoswiss'
            ? [
                ['color' => '#00d2ff', 'label' => '0,1–0,5 mm/h'],
                ['color' => '#00a0d2', 'label' => '0,5–2,5 mm/h'],
                ['color' => '#005aaa', 'label' => '2,5–10 mm/h'],
                ['color' => '#ffdc00', 'label' => '10–25 mm/h'],
                ['color' => '#ff8200', 'label' => '25–50 mm/h'],
                ['color' => '#eb1e28', 'label' => '50–75 mm/h'],
                ['color' => '#be00aa', 'label' => '≥ 75 mm/h'],
            ]
            : [
                ['color' => '#8ee6f2', 'label' => '0,1–0,5 mm/h'],
                ['color' => '#45bfd3', 'label' => '0,5–2,5 mm/h'],
                ['color' => '#287f9e', 'label' => '2,5–10 mm/h'],
                ['color' => '#ffe43b', 'label' => '10–25 mm/h'],
                ['color' => '#f59a45', 'label' => '25–50 mm/h'],
                ['color' => '#d95858', 'label' => '50–75 mm/h'],
                ['color' => '#a84f72', 'label' => '≥ 75 mm/h'],
            ];

        return [
            'type' => 'entries',
            'provider' => $providerName,
            'title' => 'Niederschlag (mm/h)',
            'entries' => $entries
        ];
    }

    private function BuildWeatherPayload(): array
    {
        $owmInstID = $this->ReadPropertyInteger('OpenWeatherInstanceID');

        $temperatureID = $this->ResolveVariableID('TemperatureID', 'Temperature', $owmInstID);
        $humidityID = $this->ResolveVariableID('HumidityID', 'Humidity', $owmInstID);
        $windID = $this->ResolveVariableID('WindSpeedID', 'WindSpeed', $owmInstID);
        $rainID = $this->ResolveVariableID('Rain1hID', 'Rain_1h', $owmInstID);
        $cloudsID = $this->GetObjectIDByIdentSafe('Cloudiness', $owmInstID);

        return [
            'current' => [
                'temperature' => $this->GetFormattedValueSafe($temperatureID),
                'humidity' => $this->GetFormattedValueSafe($humidityID),
                'wind' => $this->GetFormattedValueSafe($windID),
                'rain' => $this->GetFormattedValueSafe($rainID),
                'clouds' => $this->GetFormattedValueSafe($cloudsID)
            ],
            'forecast' => $this->BuildForecastPayload($owmInstID),
            'updatedAt' => time()
        ];
    }

    private function BuildForecastPayload(int $owmInstID): array
    {
        if ($owmInstID <= 0 || !@IPS_ObjectExists($owmInstID)) {
            return [];
        }

        $count = 0;
        $location = @IPS_GetProperty($owmInstID, 'daily_forecast_count');
        if ($location !== false && $location !== '') {
            $count = (int) $location;
        }
        if ($count <= 0) {
            $count = 5;
        }

        $forecast = [];
        for ($i = 0; $i < $count; $i++) {
            $post = '_' . sprintf('%02d', $i);
            $beginID = $this->GetObjectIDByIdentSafe('DailyForecastBegin' . $post, $owmInstID);
            if ($beginID === 0) {
                continue;
            }

            $forecast[] = [
                'dt' => $this->GetValueIntegerSafe($beginID),
                'min' => $this->GetValueFloatSafe($this->GetObjectIDByIdentSafe('DailyForecastTemperatureMin' . $post, $owmInstID)),
                'max' => $this->GetValueFloatSafe($this->GetObjectIDByIdentSafe('DailyForecastTemperatureMax' . $post, $owmInstID)),
                'wind' => $this->GetValueFloatSafe($this->GetObjectIDByIdentSafe('DailyForecastWindSpeed' . $post, $owmInstID)),
                'rain' => $this->GetValueFloatSafe($this->GetObjectIDByIdentSafe('DailyForecastRain' . $post, $owmInstID)),
                'snow' => $this->GetValueFloatSafe($this->GetObjectIDByIdentSafe('DailyForecastSnow' . $post, $owmInstID)),
                'icon' => $this->GetValueStringSafe($this->GetObjectIDByIdentSafe('DailyForecastConditionIcon' . $post, $owmInstID)),
                'description' => $this->GetValueStringSafe($this->GetObjectIDByIdentSafe('DailyForecastConditions' . $post, $owmInstID)),
                'humidity' => $this->GetValueFloatSafe($this->GetObjectIDByIdentSafe('DailyForecastHumidity' . $post, $owmInstID)),
                'clouds' => $this->GetValueFloatSafe($this->GetObjectIDByIdentSafe('DailyForecastCloudiness' . $post, $owmInstID))
            ];
        }

        return $forecast;
    }

    private function BuildRadarFrameSignature(array $payload): string
    {
        $frames = $payload['frames'] ?? [];
        if (!is_array($frames) || count($frames) === 0) {
            return '';
        }

        $latest = $frames[count($frames) - 1];
        if (!is_array($latest)) {
            return '';
        }

        return (string) ($payload['provider'] ?? '') . '|' .
            (string) ($latest['time'] ?? '') . '|' .
            (string) ($latest['url'] ?? ($latest['path'] ?? ''));
    }

    private function BuildRadarPayload(): array
    {
        $provider = $this->ReadPropertyString('RadarProvider');
        if ($provider === 'rainbow') {
            return $this->BuildRainbowPayload();
        }
        if ($provider === 'meteoswiss') {
            return $this->BuildMeteoswissPayload();
        }

        return $this->BuildRainviewerPayload();
    }

    private function BuildRainviewerPayload(): array
    {
        $url = 'https://api.rainviewer.com/public/weather-maps.json';
        $json = $this->HttpGet($url, [], 10);
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['host']) || !isset($data['radar']['past']) || !is_array($data['radar']['past'])) {
            $this->SendDebug(
                'BuildRainviewerPayload',
                'RainViewer API liefert ungültige oder unvollständige Daten.',
                0
            );
            return [
                'provider' => 'rainviewer',
                'host' => '',
                'frames' => [],
                'error' => 'Rainviewer-Daten ungültig'
            ];
        }

        $this->SendDebug(
            'BuildRainviewerPayload',
            'RainViewer Frames geladen: ' . count($data['radar']['past']) .
            ', Host=' . (string) $data['host'],
            0
        );

        return [
            'provider' => 'rainviewer',
            'host' => (string) $data['host'],
            'frames' => array_values($data['radar']['past']),
            'updatedAt' => time()
        ];
    }

    private function BuildMeteoswissPayload(): array
    {
        $item = null;
        $itemDate = '';

        // Die Tages-Items werden in UTC geführt. Rund um Mitternacht beide Tage prüfen.
        for ($daysBack = 0; $daysBack <= 2; $daysBack++) {
            $date = gmdate('Ymd', time() - ($daysBack * 86400));
            $url = 'https://data.geo.admin.ch/api/stac/v1/collections/' .
                'ch.meteoschweiz.ogd-radar-precip/items/' . $date . '-ch' .
                '?_=' . time();
            $json = $this->HttpGet(
                $url,
                [
                    'Accept: application/geo+json, application/json',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache'
                ],
                20
            );
            $candidate = json_decode($json, true);
            if (is_array($candidate) && isset($candidate['assets']) && is_array($candidate['assets'])) {
                $item = $candidate;
                $itemDate = $date;
                break;
            }
        }

        if (!is_array($item)) {
            $this->SendDebug('BuildMeteoswissPayload', 'Kein aktuelles MeteoSwiss-STAC-Tagesitem gefunden.', 0);
            return [
                'provider' => 'meteoswiss',
                'frames' => [],
                'error' => 'MeteoSwiss-Daten nicht erreichbar'
            ];
        }

        $frames = [];
        foreach ($item['assets'] as $name => $asset) {
            if (!is_string($name) || !preg_match('/^rzc(\d{2})(\d{3})(\d{2})(\d{2}).*\.h5$/i', $name, $match)) {
                continue;
            }
            if (!is_array($asset) || empty($asset['href'])) {
                continue;
            }

            $year = 2000 + (int) $match[1];
            $dayOfYear = max(1, (int) $match[2]);
            $hour = min(23, (int) $match[3]);
            $minute = min(59, (int) $match[4]);
            $base = gmmktime(0, 0, 0, 1, 1, $year);
            $timestamp = $base + (($dayOfYear - 1) * 86400) + ($hour * 3600) + ($minute * 60);

            $frames[] = [
                'time' => $timestamp,
                'url' => (string) $asset['href'],
                // Gesamtes RZC-Raster; wird im Browser von LV95 nach WGS84 umgerechnet.
                'bounds' => [[43.619, 2.68942], [49.3744, 12.4623]]
            ];
        }

        usort($frames, static function (array $a, array $b): int {
            return ((int) $a['time']) <=> ((int) $b['time']);
        });

        // Für die Animation nur die letzten 12 Bilder (= rund 55 Minuten) übertragen.
        if (count($frames) > 12) {
            $frames = array_slice($frames, -12);
        }

        $latestFrameTime = '-';
        if (!empty($frames)) {
            $latestFrame = $frames[count($frames) - 1];
            $latestFrameTime = gmdate('H:i:s', (int) $latestFrame['time']) . ' UTC';
        }

        $this->SendDebug(
            'BuildMeteoswissPayload',
            'MeteoSwiss RZC Frames geladen: ' . count($frames) .
            ', neuester Frame=' . $latestFrameTime .
            ', Tagesitem=' . $itemDate,
            0
        );

        return [
            'provider' => 'meteoswiss',
            'frames' => array_values($frames),
            'updatedAt' => time(),
            'attribution' => 'MeteoSwiss Open Data (CC BY)'
        ];
    }

    private function BuildRainbowPayload(): array
    {
        $apiKey = trim($this->ReadPropertyString('RainbowApiKey'));
        $layer = 'precip';
        $color = 8; // RainViewer Universal Blue / Original

        if ($apiKey === '') {
            $this->SendDebug(
                'BuildRainbowPayload',
                'Rainbow Snapshot ungültig oder API-Antwort fehlerhaft.',
                0
            );
            return [
                'provider' => 'rainbow',
                'frames' => [],
                'error' => 'Rainbow API-Key fehlt'
            ];
        }

        $snapshotUrl = 'https://api.rainbow.ai/tiles/v1/snapshot?layer=' . rawurlencode($layer);
        $json = $this->HttpGet($snapshotUrl, ['Ocp-Apim-Subscription-Key: ' . $apiKey], 10);
        $data = json_decode($json, true);
        $snapshot = (is_array($data) && isset($data['snapshot'])) ? (int) $data['snapshot'] : 0;

        if ($snapshot <= 0) {
            $this->SendDebug(
                'BuildRainbowPayload',
                'Rainbow Snapshot ungültig oder API-Antwort fehlerhaft.',
                0
            );

            return [
                'provider' => 'rainbow',
                'frames' => [],
                'error' => 'Rainbow Snapshot ungültig'
            ];
        }

        $pastSteps = [-3600, -3000, -2400, -1800, -1200, -600];
        // Rainbow unterstützt Zukunftsframes in 10-Minuten-Schritten bis maximal 4 Stunden.
        $forecastHours = max(1, min(4, $this->ReadPropertyInteger('RainbowForecastHours')));
        $forecastSteps = range(0, $forecastHours * 3600, 600);
        $frames = [];

        foreach ($pastSteps as $step) {
            $frameSnapshot = $snapshot + $step;
            $frames[] = [
                'time' => $frameSnapshot,
                'url' => $this->BuildRainbowTileUrl($layer, $frameSnapshot, 0, $color, $apiKey)
            ];
        }

        foreach ($forecastSteps as $step) {
            $frames[] = [
                'time' => $snapshot + $step,
                'url' => $this->BuildRainbowTileUrl($layer, $snapshot, $step, $color, $apiKey)
            ];
        }

        $this->SendDebug(
            'BuildRainbowPayload',
            'Rainbow Snapshot=' . $snapshot . ', Layer=' . $layer . ', Color=' . $color .
            ', Zukunft=' . $forecastHours . ' h, Frames=' . count($frames),
            0
        );

        return [
            'provider' => 'rainbow',
            'snapshot' => $snapshot,
            'frames' => $frames,
            'updatedAt' => time()
        ];
    }

    private function BuildRainbowTileUrl(string $layer, int $snapshot, int $forecastTime, int $color, string $apiKey): string
    {
        return 'https://api.rainbow.ai/tiles/v1/' . rawurlencode($layer) . '/' . $snapshot . '/' . $forecastTime . '/{z}/{x}/{y}?color=' . $color . '&token=' . rawurlencode($apiKey);
    }

    private function ResolveVariableID(string $propertyName, string $fallbackIdent, int $fallbackParentID): int
    {
        $configuredID = $this->ReadPropertyInteger($propertyName);
        if ($configuredID > 0 && @IPS_ObjectExists($configuredID)) {
            return $configuredID;
        }

        return $this->GetObjectIDByIdentSafe($fallbackIdent, $fallbackParentID);
    }

    private function GetObjectIDByIdentSafe(string $ident, int $parentID): int
    {
        if ($parentID <= 0 || !@IPS_ObjectExists($parentID)) {
            return 0;
        }

        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        return ($id === false) ? 0 : (int) $id;
    }

    private function GetFormattedValueSafe(int $id): string
    {
        if ($id <= 0 || !@IPS_ObjectExists($id)) {
            return '–';
        }

        $value = @GetValueFormatted($id);
        return ($value === false) ? '–' : (string) $value;
    }

    private function GetValueIntegerSafe(int $id): int
    {
        if ($id <= 0 || !@IPS_ObjectExists($id)) {
            return 0;
        }
        return (int) @GetValue($id);
    }

    private function GetValueFloatSafe(int $id): float
    {
        if ($id <= 0 || !@IPS_ObjectExists($id)) {
            return 0.0;
        }
        return (float) @GetValue($id);
    }

    private function GetValueStringSafe(int $id): string
    {
        if ($id <= 0 || !@IPS_ObjectExists($id)) {
            return '';
        }
        return (string) @GetValue($id);
    }

    private function GetLocation(): array
    {
        // 1. Standort aus der gewählten OpenWeatherOneCall-Instanz verwenden.
        $owmInstID = $this->ReadPropertyInteger('OpenWeatherInstanceID');
        if ($owmInstID > 0 && @IPS_ObjectExists($owmInstID)) {
            $locationJson = @IPS_GetProperty($owmInstID, 'location');
            $location = json_decode((string) $locationJson, true);

            if ($this->HasValidCoordinates($location)) {
                $this->SendDebug('GetLocation', 'Standort aus OpenWeatherOneCall verwendet.', 0);
                return [(float) $location['latitude'], (float) $location['longitude']];
            }

            $this->SendDebug(
                'GetLocation',
                'OpenWeatherOneCall enthält keine gültige Location. Symcon Location Control wird verwendet.',
                0
            );
        } else {
            $this->SendDebug(
                'GetLocation',
                'Keine OpenWeatherOneCall-Instanz gewählt. Symcon Location Control wird verwendet.',
                0
            );
        }

        // 2. Fallback auf die Symcon-Kerninstanz "Location Control".
        $locationControlIDs = @IPS_GetInstanceListByModuleID(
            '{45E97A63-F870-408A-B259-2933F7EABF74}'
        );

        if (is_array($locationControlIDs) && count($locationControlIDs) > 0) {
            $locationControlID = (int) $locationControlIDs[0];

            // Seit IP-Symcon 5.0: JSON-Property "Location".
            $locationJson = @IPS_GetProperty($locationControlID, 'Location');
            $location = json_decode((string) $locationJson, true);

            if ($this->HasValidCoordinates($location)) {
                $this->SendDebug(
                    'GetLocation',
                    'Standort aus Symcon Location Control ID ' . $locationControlID . ' verwendet: ' .
                    $location['latitude'] . ', ' . $location['longitude'],
                    0
                );

                return [(float) $location['latitude'], (float) $location['longitude']];
            }

            // Kompatibilitäts-Fallback für sehr alte Symcon-Versionen.
            $latitude = @IPS_GetProperty($locationControlID, 'Latitude');
            $longitude = @IPS_GetProperty($locationControlID, 'Longitude');
            $legacyLocation = [
                'latitude' => $latitude,
                'longitude' => $longitude
            ];

            if ($this->HasValidCoordinates($legacyLocation)) {
                $this->SendDebug(
                    'GetLocation',
                    'Standort aus den alten Location-Control-Properties verwendet.',
                    0
                );

                return [(float) $latitude, (float) $longitude];
            }

            $this->SendDebug(
                'GetLocation',
                'Location Control ID ' . $locationControlID . ' enthält keine gültigen Koordinaten. Location=' .
                (string) $locationJson,
                0
            );
        } else {
            $this->SendDebug(
                'GetLocation',
                'Keine Symcon Location-Control-Instanz mit der erwarteten GUID gefunden.',
                0
            );
        }

        return [0.0, 0.0];
    }

    private function HasValidCoordinates(mixed $location): bool
    {
        if (!is_array($location) ||
            !array_key_exists('latitude', $location) ||
            !array_key_exists('longitude', $location) ||
            !is_numeric($location['latitude']) ||
            !is_numeric($location['longitude'])) {
            return false;
        }

        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];

        if ($latitude < -90.0 || $latitude > 90.0 ||
            $longitude < -180.0 || $longitude > 180.0) {
            return false;
        }

        return !($latitude === 0.0 && $longitude === 0.0);
    }

    private function GetMapStyle(): array
    {
        $mapStyles = [
            'street' => [
                'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
                'attribution' => '&copy; Esri, HERE, Garmin, FAO, NOAA, USGS, OpenStreetMap contributors'
            ],
            'topo' => [
                'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
                'attribution' => '&copy; Esri, HERE, Garmin, FAO, NOAA, USGS, OpenStreetMap contributors'
            ],
            'natgeo' => [
                'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer/tile/{z}/{y}/{x}',
                'attribution' => '&copy; Esri, National Geographic, Garmin, HERE, UNEP-WCMC, USGS, NASA, ESA, METI, NRCAN, GEBCO, NOAA, increment P Corp.'
            ],
            'satellite' => [
                'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                'attribution' => '&copy; Esri, Maxar, Earthstar Geographics, and the GIS User Community'
            ],
            'hot' => [
                'url' => 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
                'attribution' => '&copy; OpenStreetMap contributors, Tiles style by Humanitarian OpenStreetMap Team hosted by OpenStreetMap France',
                'subdomains' => ['a', 'b', 'c']
            ],
            'osmfr' => [
                'url' => 'https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png',
                'attribution' => '&copy; OpenStreetMap contributors, Tiles style by OpenStreetMap France',
                'subdomains' => ['a', 'b', 'c']
            ],
            'opentopo' => [
                'url' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
                'attribution' => '&copy; OpenStreetMap contributors, SRTM | Kartendarstellung: &copy; OpenTopoMap',
                'subdomains' => ['a', 'b', 'c']
            ]
        ];

        $style = $this->ReadPropertyString('MapStyle');
        if (!isset($mapStyles[$style])) {
            $style = 'street';
        }

        return [
            $mapStyles[$style]['url'],
            $mapStyles[$style]['attribution'],
            $mapStyles[$style]['subdomains'] ?? []
        ];
    }

    private function HttpGet(string $url, array $headers = [], int $timeout = 10): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'IP-Symcon Wetterradar HTML-SDK',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers
            ]);
            $result = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false || $httpCode < 200 || $httpCode >= 300) {
                $this->SendDebug('HttpGet', 'HTTP=' . $httpCode . ' Error=' . $error . ' URL=' . $url, 0);
                return '';
            }

            return (string) $result;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers)
            ]
        ]);
        $result = @file_get_contents($url, false, $context);
        return ($result === false) ? '' : (string) $result;
    }
}
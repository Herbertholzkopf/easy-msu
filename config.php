<?php
/**
 * Feste Werte für den Seniorentreff (ermittelt am 16.09.2026, siehe MSU_PORTAL_ANALYSE.md).
 * Alles, was Oma NICHT jedes Mal eingeben soll, steht hier.
 */
return [
    // Portal
    'base_url'        => 'https://termine.oberpfalznetz.de/',
    'app_version'     => '4.7.2',                       // APP_VERSION_CHECK (aus config/msuevent-config.js)
    'browser_check'   => 'html5upload=1&formdata=1',    // APP_BROWSER_CHECK
    'timeout'         => 40,                            // Sekunden pro HTTP-Request

    // Feste Termin-Daten
    'hauptkategorie'  => '506',                         // Vereinstermine
    'promoter_id'     => '41093',                       // "PGR Ursulapoppenricht"
    'promoter_name'   => 'PGR Ursulapoppenricht',
    'location_id'     => '32236',                       // "Sportheim Ursulapoppenricht" (erster Eintrag im Autocomplete)
    'location_name'   => 'Sportheim Ursulapoppenricht',

    // Vorbelegung im Formular
    'default_title'   => 'Seniorentreff',
    'default_time_from' => '14:30',
    'default_time_to'   => '17:00',
    'default_text'    => '',
    'max_text_len'    => 350,                           // Kurztext-Limit des Portals

    // Vorschlag für die Erscheinungstage: Tage VOR dem Veranstaltungstag (0 = der Tag selbst).
    // [4, 2, 0] → z. B. Termin Mi 23.09. → 19.09., 21.09., 23.09.  Sonntage rutschen auf den Samstag.
    'print_offsets'   => [4, 2, 0],

    // Testmodus: alles durchspielen (inkl. Entwurf anlegen), aber NICHT speichern,
    // sondern den Entwurf per DELETE wieder entfernen. Zum Ausprobieren auf true setzen.
    'dry_run'         => false,

    // Protokoll-Datei (Passwort wird maskiert). Leer lassen = kein Log.
    'log_file'        => __DIR__ . '/logs/msu.log',
];

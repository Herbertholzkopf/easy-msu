# easy-msu
Dies ist ein Wrapper-Portal für das MSU Event Portal (termine.oberpfalznetz.de), da die Oberfläche unglaublich kompliziert ist.

# Seniorentreff → Zeitung (MSUevent-Client)

Kleine PHP-Website, die im Hintergrund das Portal `termine.oberpfalznetz.de` bedient.
Oma gibt nur noch Datum, Uhrzeit, Erscheinungstage, Titel und Text ein.

## Dateien

| Datei | Zweck |
|---|---|
| `index.php` | Oberfläche: Anmelden → Termin eingeben → Prüfen → Senden → Bestätigung |
| `MsuClient.php` | Der eigentliche Portal-Client (Login, Formularzustand, AJAX-Protokoll, SAVE) |
| `config.php` | Feste Werte: Veranstalter-ID, Ort-ID, Kategorie, Vorbelegungen, Testmodus, Log |
| `logs/msu.log` | wird automatisch angelegt; Ablaufprotokoll (ohne Passwort) |

Technische Grundlage: `MSU_PORTAL_ANALYSE.md`.

## Voraussetzungen

PHP ≥ 8.1 mit `curl`, `dom`, `mbstring`, `session` (Standard bei Debian/Ubuntu `php-cli php-curl php-xml php-mbstring`).
Webserver beliebig (Apache/nginx/PHP-FPM) oder zum Testen `php -S 0.0.0.0:8080` im Ordner.

## Erste Inbetriebnahme – bitte in dieser Reihenfolge

1. `config.php` ansehen. Die IDs (41093 = PGR Ursulapoppenricht, 32236 = Sportheim Ursulapoppenricht)
   stimmen mit dem TEST-TEST-Termin vom 16.09.2026 überein.
2. **`'dry_run' => true`** setzen und einen Termin durchschicken. Der Client macht dann alles
   (Login, Maske öffnen, Entwurf anlegen), löscht den Entwurf aber wieder statt zu speichern.
   In `logs/msu.log` sieht man jeden Request; im Portal darf danach kein neuer Termin stehen.
3. `'dry_run' => false` setzen, einen echten Test-Termin senden (Titel z. B. "TEST bitte löschen"),
   im Portal unter *Suche → Eventsuche* (Erfassungszeitraum heute) kontrollieren und dort löschen.
4. Fertig – Oma bekommt die Adresse im Heimnetz, meldet sich einmal mit den Portal-Zugangsdaten an.

## Sicherheit / Hinweise

- Die Portal-Zugangsdaten liegen nur in der PHP-Session auf dem Server (nicht auf der Platte).
  Nach "Abmelden" oder Session-Ablauf müssen sie neu eingegeben werden. Wenn das Passwort fest
  hinterlegt werden soll: in `index.php` den Login-Block durch feste Werte aus `config.php` ersetzen.
- Nur im Heimnetz betreiben (kein Port-Forwarding); HTTPS ist im LAN optional.
- Jeder Sende-Vorgang loggt sich frisch ein und danach wieder aus (~15 Requests, 10–20 s).
- "Erfolgreich übertragen" wird nur angezeigt, wenn das Portal `EVENT wurde erfolgreich gespeichert`
  zurückmeldet. Alles andere landet als Fehler mit Portal-Meldung auf der roten Seite.
- Wenn MSU das Portal ändert (neue Version in `javascript/config/msuevent-config.js`), zuerst
  `app_version` in `config.php` anpassen und einen Dry-Run machen.

# MSUevent-Portal (termine.oberpfalznetz.de) – Technische Analyse für einen PHP-Client

Stand: 16.09.2026, analysiert im Browser mit dem Account `mkoller` (Redaktion "Hahnbach extern").
Ziel: Eine einfache interne PHP-Website, die im Hintergrund das MSU-Portal bedient und einen
Termin (z. B. Seniorentreff) anlegt.

> **Nicht verifiziert:** Der eigentliche `SAVE`-Request wurde **nicht** abgeschickt (um keinen
> Testtermin an die Zeitung zu senden). Alles bis inkl. Entwurf-Anlage (`set_pub`) ist live
> beobachtet. Siehe Abschnitt 9, wie der SAVE-Response beim nächsten echten Termin mitgeschnitten wird.

---

## 1. Architektur des Portals in Kürze

- Produkt: **MSUevent V4.7.2** (MSU GmbH, Aschaffenburg), Mandant `DNT`, Charset UTF‑8.
- Eine einzige HTML-Form (`<form name="msuevent" method="post" action="index.php">`) mit sehr
  vielen Feldern (auf der Event-Seite **274 benannte Felder**).
- Zwei Transportwege:
  1. **AJAX**: `POST https://termine.oberpfalznetz.de/msu_ajax_server.php`
     (Content-Type `application/x-www-form-urlencoded`). Damit passiert fast alles
     (Login, Initialisieren, Feldabhängigkeiten, Entwurf anlegen, Speichern).
  2. **Klassischer Form-POST** an `index.php` nur zum **Seitenwechsel** (Navigation).
- **Keine Cookies.** Die Session wird ausschließlich über das Hidden-Feld `SESSIONID`
  (32 Zeichen, alphanumerisch) transportiert, das der Login-Response per `setvar` setzt und
  das danach in *jedem* Request mitgeschickt wird.
- Autocomplete (Veranstalter, Ort, Ortsteil): `GET autocomplete.php?...&term=...` → JSON.
- Der Server antwortet bei AJAX nicht mit JSON, sondern mit einem **proprietären Zeilenformat**
  (siehe 3.2), das der Browser-JS-Code interpretiert (`setvar`, `html`, `javascript`, …).

---

## 2. Endpunkte

| Zweck | Methode/URL | Bemerkung |
|---|---|---|
| Login-Seite laden | `GET /index.php` | liefert die Form mit leerem `SESSIONID` |
| AJAX-Server | `POST /msu_ajax_server.php` | alle Aktionen; `REQUESTID` bestimmt die Aktion |
| Navigation | `POST /index.php` | `ACTION=navigate`, `URLPARAMS=AJX=&NAVIGATIONID=50&PAGEID=event` |
| Autocomplete | `GET /autocomplete.php?...&term=XYZ` | jQuery-UI-Format `[{"id":123,"label":"…"}]` |
| JS des Portals | `/javascript/msuajax.de.js`, `msuglobal.de.js`, `msutabs.de.js`, `msuevent.de.js`, `config/msuevent-config.js` | öffentlich abrufbar, gut zum Nachlesen |

---

## 3. Das AJAX-Protokoll (`msu_ajax_server.php`)

### 3.1 Request

Body (form-urlencoded), immer beginnend mit:

```
AJXTIMESTAMP=<ms seit Epoch>&REQUESTID=<aktion>&<felder...>
```

Zwei Modi (aus `ajx(requestid, feldliste)` in `msuajax.de.js`):

- **Feldliste**: nur die genannten Felder **plus immer** `MYSELF,PAGEID,SESSIONID,TABACTIVE`
  **plus** alle Felder, deren Name mit `_` beginnt. Beispiel Login:
  `REQUESTID=login&login_user=…&login_pass=…&privacy_statement_approved=&MYSELF=index.php&PAGEID=loginpage&SESSIONID=&TABACTIVE=`
- **`*alle`**: **das komplette Formular** (alle Felder mit Name, außer `URLPARAMS`; Felder
  `postgroup_*` würden gebündelt, kommen auf der Event-Seite aber nicht vor). Alle Event-Aktionen
  nutzen `*alle`.

**Encoding-Falle:** Der JS-Code wendet `encodeURIComponent()` pro Wert an **und danach nochmal
`encodeURI()` auf den gesamten String** → Werte kommen **doppelt URL-kodiert** an
(`%` → `%25`). Der Server dekodiert offenbar zweimal. Im PHP-Client daher exakt nachbauen:

```php
function msu_build_body(array $fields): string {
    $parts = [];
    foreach ($fields as $k => $v) {
        $parts[] = $k . '=' . rawurlencode((string)$v);   // = encodeURIComponent
    }
    $s = implode('&', $parts);
    // encodeURI: kodiert alles außer  A-Z a-z 0-9 ; , / ? : @ & = + $ - _ . ! ~ * ' ( ) #
    return preg_replace_callback('/[^A-Za-z0-9;,\/?:@&=+$\-_.!~*\'()#]/', fn($m) => rawurlencode($m[0]), $s);
}
```
(Praktisch heißt das: nach dem ersten Pass bleiben nur unreservierte Zeichen und `%`; der zweite
Pass macht aus `%` ein `%25`.) Beim ersten Test prüfen, ob Umlaute korrekt ankommen; falls der
Server nur einfach dekodiert, den zweiten Pass weglassen.

Checkbox-Semantik (aus `get_form_var`): nicht angehakt → leerer String; angehakt → `value`.
Radio → value der gewählten Option. Select → value der gewählten Option (leer wenn keine Optionen).

### 3.2 Response

Text, Zeilen getrennt durch `\n|||`, Felder pro Zeile durch `~~~`:

```
|||<requestid>~~~<typ>~~~<ziel>~~~<wert>[~~~...]
```

`~TILDE` im Wert steht für ein echtes `~`. Werte sind `unescape()`-kodiert (der Browser ruft
`unescape(datastream)` auf → `%XX` und `%uXXXX` dekodieren).

| typ | Bedeutung | Für den PHP-Client relevant |
|---|---|---|
| `setvar` | Formularfeld `<ziel>` auf `<wert>` setzen | **ja – unbedingt in den eigenen Formular-Zustand übernehmen** (z. B. `SESSIONID`, `event_id`, `event_status_id`, `publication_*`) |
| `html` | innerHTML eines DOM-Bereichs ersetzen | **nicht komplett ignorieren:** einige Bereiche enthalten Formularfelder, die danach Teil von `*alle` sind. Beobachtet: `event_unterkategorie` (`unterkategorie`), `event_texte_pub` (`publication_texte_*`), `geo_codes` (`last_selected`,`lat`,`lng`,`koordx/y/z`,`geodata_id`,`strasse`,`plz`,`ort`), `reitermultimedia` (`_mm_orig_id` = event_id), `toolbar` (nur Buttons). → im Client `<input name value>` / `<select>` aus dem HTML-Wert extrahieren und in den Zustand mergen |
| `javascript` | JS ausführen | ignorieren, **außer** `set_form_var('x','y')` (→ wie setvar behandeln) und Folge-Aufrufe `ajx('…','*alle')` (→ Kette fortsetzen, siehe 5) |
| `success` / `error` / `warning` | Meldungstexte | Für Erfolgs-/Fehlererkennung auswerten |
| `tabreiter`, `clearselect`, `addselect`, `setcookie` | UI | ignorieren |

Beispiel Login-Response (gekürzt):

```
|||login~~~html~~~systemarea~~~&nbsp;
|||login~~~success~~~Login erfolgreich...
|||login~~~setvar~~~SESSIONID~~~<32 Zeichen>
|||login~~~html~~~loginarea~~~Hallo Maria Koller, Ihr letztes Login war am ...
|||login~~~html~~~navigation~~~<table id="pulldownmenu">…</table>
|||login~~~html~~~hinweisarea~~~<div>Achtung! … Samstagsausgaben …</div>
```

Fehlerhafter Login liefert vermutlich `error`-Zeile und **kein** `setvar SESSIONID` → daran erkennen.

---

## 4. Login

1. `GET /index.php` (optional – liefert nur das Formular; keine Cookies, kein CSRF-Token).
2. `POST /msu_ajax_server.php`:

```
AJXTIMESTAMP=<ms>&REQUESTID=login
&login_user=mkoller&login_pass=<pw>&privacy_statement_approved=
&MYSELF=index.php&PAGEID=loginpage&SESSIONID=&TABACTIVE=
```
3. Aus der Antwort `setvar SESSIONID` übernehmen. Ab jetzt in jedem Request mitsenden.

Konstante Hidden-Werte, die der Browser sonst setzt (bei `*alle` mitschicken):
`MYSELF=index.php`, `APP_VERSION_CHECK=4.7.2`, `APP_BROWSER_CHECK=html5upload=1&formdata=1`,
`WINDOW=` (leer), `FORM_IS_DIRTY=0`.

Logout (optional, sauber): `REQUESTID=logout` mit `PAGEID=loginpage&ACTION=logout`.

---

## 5. Zur Event-Seite navigieren und initialisieren

### 5.1 Seitenwechsel (Form-POST)

```
POST /index.php
SESSIONID=<sid>&NAVIGATIONID=&ACTION=navigate&URLPARAMS=AJX%3D%26NAVIGATIONID%3D50%26PAGEID%3Devent
&MYSELF=index.php&PAGEID=loginpage&TABACTIVE=&FORM_IS_DIRTY=0
&APP_VERSION_CHECK=4.7.2&APP_BROWSER_CHECK=html5upload%3D1%26formdata%3D1&WINDOW=
```
(Menüpunkte: Event = `NAVIGATIONID=50&PAGEID=event`, Eventsuche = `55/suche`,
Gottesdienst = `53/gottesdienst`, Mein Benutzer = `94/my_user`.)

Die Antwort ist die **komplette HTML-Seite der Terminerfassung** inkl. aller Hidden-Felder mit
Startwerten. **Empfehlung:** diese HTML-Seite mit DOMDocument parsen und daraus ein
assoziatives Array "Formularzustand" (`name => value`) aller `input/select/textarea` bauen –
genau das, was `*alle` später sendet. Dann müssen die 274 Felder nicht hart kodiert werden und
Portal-Updates brechen weniger.

### 5.2 Init-Kette (AJAX, jeweils `*alle`)

Beim Laden ruft die Seite `ajx('init','*alle')` auf; die Antworten enthalten
`javascript`-Zeilen, die weitere Requests auslösen. Beobachtete Kette:

```
init            → setvar publication_*; javascript: set_form_var('regio_group_id','179'); ajx('regio_group_id','*alle')
regio_group_id  → html: Gemeinde/Veranstalter-Bereiche; javascript: ajx('community','*alle')
community       → html_autocomplete_jq(...) für district_id / promoter_id / event_location_id
```
Danach steht fest: `regio_group_id=179`, `community_id=47` (Gemeinde **Hahnbach**),
`event_category_regio_group_id=179`, Standard-Publikationen `publication_7=7`, `publication_9=9`,
`publication_116=116` (116 = Ausgabe "Hahnbach"), alle anderen `publication_<n>=0`
(gleiche Werte in `publication_texte_<n>`).

Ob die Kette zwingend ist, ist nicht getestet; sie ist billig (3 Requests) – im Client einfach
nachspielen und alle `setvar`/`set_form_var` übernehmen.

---

## 6. Felder der Terminerfassung

### 6.1 Was der Server als Pflicht prüft (clientseitig in `event_checkform()`, Konfiguration dieser Redaktion)

Beim Verlassen von "Allgemein" bzw. beim Speichern muss vorhanden sein:

- **Gemeinde** (`community_id`, vorbelegt 47) – evtl. Ortsteil (`district_id`)
- **Veranstalter oder Veranstaltungsort** (`promoter_id` **oder** `event_location_id` > 0)
- **Hauptkategorie** (`hauptkategorie`) und ggf. Unterkategorie (`unterkategorie`)
- **Datum** (`begin_date`, Format `TT.MM.JJJJ`)
- Uhrzeit ist **nicht** Pflicht (`mussfeld_event_begin_time=0`), muss aber `HH:MM` sein, wenn gesetzt.
- Titel / Kurztext: nicht Pflicht (`mussfeld_event_title=0`, `mussfeld_event_shortdesc=0`),
  Kurztext **max. 350 Zeichen**, Langtext max. 100 Zeichen (!), Online-Text ohne Limit.
- Erscheinungsdaten (`print_date*`) dürfen nicht **nach** dem Veranstaltungsdatum liegen.

### 6.1a Bedeutung der verwirrenden Felder (geklärt anhand Quelltext + Handbuch `MSUevent-BH_01_01_00_EventErfassung.pdf`)

- **Datum** (`begin_date`) + **Uhrzeit(en)** (`begin_time`/`end_time`/`ganztags`) = **wann die
  Veranstaltung stattfindet.** Intern heißen die Felder `begin_date`/`begin_time` – eindeutig Event-Beginn.
- **Termin-Typ** (Reiter Einmal/Täglich/Wöchentlich/…, intern `event_type_id`, 1 = Einmal) =
  **Wiederholungsmuster** der Veranstaltung. Hat inhaltlich nichts mit "Erscheint am" zu tun –
  das Portal blendet nur den zum Typ passenden Zusatzblock *innerhalb* des Reiters ein. Bei
  "Einmal" ist dieser Zusatzblock zufällig der "Erscheint am"-Block (Handbuch: "bis zu 3
  beliebige Daten"), bei "Täglich" wäre es der Intervall usw. Grafisch also nur unglücklich.
- **Erscheint am** (`print_date`, `print_date2`, `print_date3`, Tooltip **"Print-Datum"**) =
  **Erscheinungstage in der Zeitung**, bis zu drei. Validierung: jedes Print-Datum muss
  **≤ `begin_date`** sein (Fehler: "das Beginndatum ist kleiner als das Erscheinungsdatum").
  → Das ist das Feld, das für den Seniorentreff gebraucht wird.
- **Erscheint** im Tab "Zusatzinformationen 1" (`erscheint` = `tage`/`wochentage`/`datum`/
  `event_no_release_date`) = **Regel-Alternative** dazu: "Hinweis zusätzlich n Tage vorher" bzw.
  "an bestimmten Wochentagen vor der Veranstaltung" bzw. konkrete Daten
  (`first/second/third_release_date`). Der Einleitungstext lautet "Die Veranstaltung erscheint
  am Veranstaltungstermin und …" – d. h. ohne jede Angabe erscheint der Hinweis am Tag der
  Veranstaltung selbst. Default `event_no_release_date` = keine zusätzliche Regel.
  **Für den Client: unverändert lassen und stattdessen `print_date*` setzen** (so wie Oma es macht).
- **Texte**: Oma füllt genau zwei Felder: **Titel** = `event_title` (z. B. "Seniorentreff") und
  **Kurztext** = `event_kurz_text_ck` (Fließtext, max. 350 Zeichen). Langtext/Online-Text leer lassen.
- **Ortsteil** (`district_id`, oben rechts): **leer lassen!** Laut Handbuch filtert der Ortsteil
  die Listen für Veranstalter und Veranstaltungsort ("in Abhängigkeit der gewählten Gemeinde und
  evtl. eines Ortsteils"). Live getestet: Mit Ortsteil = Ursulapoppenricht (3227) liefert der
  Server das Veranstalter-Feld **deaktiviert** zurück (kein Veranstalter ist diesem Ortsteil
  zugeordnet) und das Autocomplete für Veranstaltungsort findet **kein** Sportheim mehr.
  "PGR Ursulapoppenricht" und "Sportheim Ursulapoppenricht" sind in der Adressverwaltung der
  Gemeinde Hahnbach *ohne* Ortsteil zugeordnet. Ein gesetzter Ortsteil löscht außerdem einen
  bereits gewählten Veranstalter (`promoter_id` wird leer).
- **Zusatzinfo 2** = nur Google-Maps-Anzeige des Veranstaltungsorts, **Multimedia** = Bilder,
  **Systeminformationen** = Anzeige nach dem Speichern → alle drei ignorieren.
- **Speichern-Button (Diskette)** = `ajx('SAVE','*alle')` → speichert und schickt an die Zeitung.

### 6.1b Feste Werte für den Seniorentreff (per Autocomplete ermittelt)

| Feld | Wert |
|---|---|
| `promoter_id` / `promoter_id_suche` | **41093** / "PGR Ursulapoppenricht" (33159 = "Pfarrgemeinderat Ursulapoppenricht" ist ein zweiter, älterer Eintrag – nicht verwenden) |
| `event_location_id` / `event_location_id_suche` | "Sportheim Ursulapoppenricht" existiert **dreimal**: **32236**, 44779 und 6914 ("… im Sportheim Ursulapoppenricht"). Das Autocomplete zeigt 32236 als erstes → vermutlich das, was Oma anklickt. Beim ersten Test prüfen (Tab Systeminformationen zeigt nach dem Speichern die Veranstaltungsort-ID). |
| `hauptkategorie` | 506 (Vereinstermine), `unterkategorie` leer |
| `community_id` | 47 (Hahnbach, vorbelegt) |
| `event_type_id` | 1 (Einmal) |
| `erscheint` | `event_no_release_date` (Default lassen) |

### 6.2 Feldübersicht (relevanter Ausschnitt)

**Tab Allgemein**

| Feld | Typ | Bedeutung / Werte |
|---|---|---|
| `community_id` / `community_id_suche` | hidden/text | 47 / "Hahnbach" (vorbelegt) |
| `district_id` / `district_id_suche` | hidden/text | Ortsteil, per Autocomplete (z. B. 3227 = Ursulapoppenricht, 3754 = Irlbach, 3801 = Mimbach) |
| `promoter_id` / `promoter_id_suche` | hidden/text | Veranstalter-ID / Anzeigename. Autocomplete-Treffer für "Senior": **40117 = Seniorentreff Ursulapoppenricht**, 33073 = Senioren, 44713 = Seniorenbeauftragte Ursulapoppenricht, 30510 = Pfarrei St. Jakobus - Senioren |
| `promoter_abt_id` | text (disabled) | Abteilung, meist leer |
| `promoter_id_ueberregional` | hidden | 0 |
| `event_location_id` / `event_location_id_suche` | hidden/text | Veranstaltungsort (Autocomplete; für "Senior" kein Treffer) |
| `hauptkategorie` | select | `0`=-, **`506`=Vereinstermine**, `507`=Öffnungszeiten |
| `unterkategorie` | hidden | für 506 leer (keine Unterkategorien) |
| `begin_date` | text | `TT.MM.JJJJ` |
| `end_date` | – | existiert bei Einmaltermin **nicht** im Formular (JS versucht `set_form_var('end_date', …)`, läuft ins Leere); nur bei Serienterminen relevant |
| `begin_time`, `end_time` | text | `HH:MM`, optional |
| `ganztags` | checkbox | `Y` wenn Ganztag |
| `event_type_id` | hidden | **1 = Einmal** (2 täglich, 3 wöchentlich, … 8 freie Serie; Tab `_termin_auswahl_tabreiter=einmal`) |
| `print_date`, `print_date2`, `print_date3` | text | "Erscheint am" – bis zu 3 Erscheinungstermine in der Zeitung, `TT.MM.JJJJ`, jeweils ≤ `begin_date` |

**Tab Texte**

| Feld | Typ | Bedeutung |
|---|---|---|
| `event_title` | text | Titel |
| `event_kurz_text_ck` | textarea | Kurztext, max 350 Zeichen (Zähler-Felder `event_kurz_text_ck_verbleibend`, `_zeilen` sind nur Anzeige) |
| `event_lang_text_ck` | textarea | Langtext, max 100 Zeichen |
| `event_online_text_ck` | textarea (CKEditor) | Online-Text (HTML); JS kopiert vor SAVE per `getEditorFields()` in Hidden `event_online_text` |
| `event_kurz_text`, `event_lang_text`, `event_online_text` | hidden | Spiegel-Felder; sicherheitshalber gleich befüllen wie die `_ck`-Felder |
| `event_clipboard` | textarea | "Zwischenablage", irrelevant |

**Tab Zusatzinformationen 1**

| Feld | Bedeutung |
|---|---|
| `event_rubrik_id` (hidden) + `tmp_sel_*_event_rubrik_id` | Rubriken – Liste ist leer, ignorieren |
| `erscheint` (radio) | `tage` (+ `erscheint_tage` 1–7), `wochentage` (+ `erscheint_mo`…`erscheint_so`), `datum` (+ `first/second/third_release_date`), **`event_no_release_date` (Default)** |
| `begin_preview`, `end_preview` | Vorschau-Zeitraum `00.00.0000` |
| `prioritaet` | `9` (=-) Default; 1 hoch, 2 mittel, 3 bei Bedarf |
| `zusatz_kategorie`, `ticket_agency_str`, `artist_str` | Online-Zusatzkategorien, Vorverkaufsstellen, Künstler – leer lassen |

**Tabs Zusatzinformationen 2 / Multimedia / Systeminformationen**: Geo-Koordinaten
(`lat`,`lng`,`koordx/y/z`,`geodata_id`, `strasse`,`plz`,`ort=Hahnbach`), Uploads, Anzeige – für den
Client nicht relevant, Defaults beibehalten.

**Steuer-/Statusfelder** (Defaults aus der frischen Seite):
`event_id=` (leer, wird durch `set_pub` gesetzt), `event_orig_id=0`, `event_status_id=`
(leer → nach `set_pub` `entwurf`), `eingangskorb=`, `ecn=0`, `duration=0`, `text_person_id=0`,
`max_end_date=0`, `akt_date=JJJJ-MM-TT` (heute), `_event_tabreiter=allgemein`,
`_event_tabreiter_allereiter=allgemein,texte,zusatz1,zusatz2,multimedia,system`,
`_termin_auswahl_tabreiter=einmal`, `free_vacation=also_vacation`, `free_holiday=also_holiday`,
`et2_holiday=Y`, `et4_holiday=Y`, `et6_holiday=Y`, `freie_serie_modus=kalender`,
`idxausnahmen=-1`, `idxoeffnungszeit=-1`, `default_tag_zeit=1`, `last_selected=promoter`
(wird nach Veranstalter-Auswahl gesetzt), `breadcrump_info=hauptkategorie=Vereinstermine`
(nur Anzeige), `_mm_orig_id` (= event_id nach set_pub), `md5pruefsumme` (leer beobachtet).

---

## 7. Ablauf zum Anlegen eines Termins (wie der Browser es macht)

Jeder Schritt = `POST msu_ajax_server.php` mit `REQUESTID=<x>` und **komplettem
Formularzustand** (`*alle`); nach jeder Antwort `setvar`/`set_form_var` in den Zustand übernehmen.

1. **`hauptkategorie`** – nach Setzen von `hauptkategorie=506` (Onchange-Handler).
   Antwort: `html event_unterkategorie` = "-" mit `<input name="unterkategorie" value="">`.
2. **`promoter_id`** – nach Setzen von `promoter_id=40117` und `promoter_id_suche=Seniorentreff Ursulapoppenricht`
   (Select-Callback des Autocomplete). Antwort: Abteilungs-Bereiche, `show_promoter`.
   (Analog `event_location_id` bei Ort, `district` bei Ortsteil.)
3. Felder setzen: `begin_date`, `begin_time`, `end_time`, ggf. `print_date` (+2, +3).
4. **`set_pub`** – wird beim Wechsel von Tab "Allgemein" auf "Texte" ausgelöst
   (`check_event_reiter()` → `event_checkform()` → `ajx('set_pub','*alle')`).
   **Legt serverseitig den Termin als Entwurf an!** Antwort enthält u. a.:
   ```
   javascript~~~set_form_var('event_id','834634')
   javascript~~~set_form_var('event_status_id','entwurf')
   html~~~toolbar~~~…   html~~~event_texte_pub~~~…
   ```
   → `event_id` und `event_status_id` übernehmen (und `_mm_orig_id=event_id`).
   Dabei ggf. `_event_tabreiter=texte`, `TABACTIVE=texte` setzen.
5. Textfelder setzen: `event_title`, `event_kurz_text_ck` (+ `event_kurz_text`),
   ggf. `event_lang_text_ck`, `event_online_text_ck`/`event_online_text`.
6. **`SAVE`** – Speichern-Button (Diskette):
   `getEditorFields('event_online_text_ck'); if(event_checkform('SAVE')) ajx('SAVE','*alle')`.
   Varianten: `SAVE_NEW` (speichern + neu), `SAVE_COPY`, `REFRESH`, `DELETE` (mit Rückfrage).

   **Verifiziert am 16.09.2026 (echter Termin "TEST-TEST", event_id 834635):** Request enthielt
   `event_id=834635`, `event_status_id=entwurf` (aus set_pub), `promoter_id=41093`,
   `event_location_id=32236`, `hauptkategorie=506`, `begin_date`, `begin_time`, `end_time`,
   `print_date(1-3)`, `event_title=TEST-TEST`, `event_kurz_text_ck=TEST-TEST`,
   `erscheint=event_no_release_date`, `TABACTIVE=allgemein`. Antwort (~83 KB), relevante Zeilen:
   ```
   |||SAVE~~~javascript~~~infoToParent(834635)
   |||SAVE~~~javascript~~~successmsg2('Daten wurden erfolgreich eingefügt.')
   |||SAVE~~~javascript~~~successmsg2('EVENT wurde erfolgreich gespeichert')
   |||SAVE~~~html~~~detail~~~<komplette Maske neu, darin event_status_id="" und md5pruefsumme=…>
   ```
   Danach zeigt "Systeminformationen": Interne ID 834635, Status **bestaetigt**, Publikation
   "Amberger Zeitung (AZ)", "Findet statt am" + die drei Print-Daten. → **Erfolgskriterium für
   den Client: Antwort enthält `EVENT wurde erfolgreich gespeichert` (bzw. `infoToParent(<id>)`)
   und keine `error`-Zeile / kein `errormsg(`.**

   `DELETE` (Papierkorb, Request mit `event_id`) antwortet mit `set_form_var('event_id','')` und
   `ajx('regio_group_id','regio_group_id,event_id')` – Feldlisten-Variante, nicht `*alle`.

   Beobachtete Reihenfolge im echten Browser-Ablauf: Autocomplete-GETs → `promoter_id` →
   `event_location_id` → `hauptkategorie` → `dubletten_check` (onBlur der Datumsfelder, nur
   Anzeige) → `set_pub` (Tab-Wechsel) → `set_pub` (nochmal beim Zurückwechseln) → `SAVE`.

---

## 8. Autocomplete (`autocomplete.php`)

Beispiel Veranstalter (URL aus der `community`-Antwort, Session in der URL):

```
GET /autocomplete.php?modul=event&type=promoter&regio_group_id=179&community_id=47
    &city_district_id=&district_id=&address_id=&SESSIONID=<sid>&MYSELF=index.php&term=Senior
→ [{"id":30510,"label":"Pfarrei St. Jakobus - Senioren"},{"id":33073,"label":"Senioren"},
   {"id":44713,"label":"Seniorenbeauftragte Ursulapoppenricht"},{"id":40117,"label":"Seniorentreff Ursulapoppenricht"}]
```
Weitere Typen: `type=event_location` (Veranstaltungsort, sonst gleiche Parameter),
`type=community&sub_type=district&group_id=179&community_id=47&default_list=-1:alle Ortsteile|-2:ohne Ortsteile`
(Ortsteil), `type=promoter_all_regions` (überregional). Mindestens 3 Zeichen (`minLength: 3`,
serverseitig aber auch 1 Zeichen ok). Kein Treffer → Body `null`.

Für den Oma-Client reicht es, die IDs **einmal** zu ermitteln und in der Konfiguration zu
hinterlegen (z. B. `promoter_id=40117`).

---

## 9. Offene Punkte / nächste Schritte

1. ~~SAVE-Response mitschneiden~~ – erledigt (siehe 7.6). Snippet für spätere Mitschnitte: Beim nächsten echten Termin im Browser vor dem Klick auf
   "Speichern" folgendes Snippet in der Konsole ausführen, danach `sessionStorage.__msulog` auslesen:
   ```js
   (()=>{const log=e=>{const a=JSON.parse(sessionStorage.getItem('__msulog')||'[]');a.push(e);sessionStorage.setItem('__msulog',JSON.stringify(a));};
   const o=XMLHttpRequest.prototype.open,s=XMLHttpRequest.prototype.send;
   XMLHttpRequest.prototype.open=function(m,u){this.__u=u;return o.apply(this,arguments)};
   XMLHttpRequest.prototype.send=function(b){const x=this;x.addEventListener('loadend',()=>log({u:x.__u,req:String(b),status:x.status,res:x.responseText}));return s.apply(this,arguments)};})();
   ```
   Interessant: Response von `SAVE` (Statuswechsel? `success`-Text? neue `event_id`?).
2. **Entwurf 834634** ist durch die Analyse im Portal entstanden (Status `entwurf`, Veranstalter
   Seniorentreff Ursulapoppenricht, 14.10.2026 14:00, ohne Titel). Bitte im Portal über das
   Papierkorb-Symbol löschen (oder als Testobjekt für Schritt 1 verwenden).
3. Welcher der drei "Sportheim Ursulapoppenricht"-Einträge ist der richtige (32236 / 44779 / 6914)?
   Beim nächsten echten Termin im Tab "Systeminformationen" die Veranstaltungsort-ID ablesen.
4. Session-Timeout des Portals unbekannt → Client sollte pro Vorgang frisch einloggen
   (billig: 1 Request) und danach ausloggen.
5. Hinweis der Redaktion (Login-Seite): Montagsausgabe nur, wenn bis Freitag 07:00 erfasst;
   in Samstagsausgaben WL/ST/SN keine Vorankündigungen → im Frontend als Hinweis anzeigen
   und `print_date` sinnvoll vorschlagen (z. B. Mo–Fr, mind. 2 Werktage vor dem Termin).

---

## 10. Vorschlag Client-Design (PHP)

```
config.php        Zugangsdaten, feste Werte (promoter_id, hauptkategorie, Publikationen …)
MsuClient.php     Klasse: login(), openEventPage(), ajx($requestId), setField(), save(), logout()
index.php         Oma-Frontend: Datum, Uhrzeit, (Erscheint am), Titel, Kurztext – fertig.
```

Kern der Klasse:

```php
class MsuClient {
    const BASE = 'https://termine.oberpfalznetz.de/';
    private array $form = [];        // aktueller Formularzustand (name => value)
    private string $sid = '';

    public function login(string $user, string $pass): void {
        $res = $this->ajx('login', [
            'login_user' => $user, 'login_pass' => $pass, 'privacy_statement_approved' => '',
            'MYSELF' => 'index.php', 'PAGEID' => 'loginpage', 'SESSIONID' => '', 'TABACTIVE' => '',
        ]);
        $this->applyResponse($res);          // holt SESSIONID aus setvar
        if ($this->sid === '') throw new RuntimeException('Login fehlgeschlagen');
    }

    public function openEventPage(): void {
        $html = $this->post('index.php', [
            'SESSIONID' => $this->sid, 'NAVIGATIONID' => '', 'ACTION' => 'navigate',
            'URLPARAMS' => 'AJX=&NAVIGATIONID=50&PAGEID=event', 'MYSELF' => 'index.php',
            'PAGEID' => 'loginpage', 'TABACTIVE' => '', 'FORM_IS_DIRTY' => '0',
            'APP_VERSION_CHECK' => '4.7.2', 'APP_BROWSER_CHECK' => 'html5upload=1&formdata=1', 'WINDOW' => '',
        ]);
        $this->form = $this->parseForm($html);            // alle input/select/textarea → name=>value
        $this->form['SESSIONID'] = $this->sid;
        $this->form['APP_VERSION_CHECK'] = '4.7.2';
        $this->form['APP_BROWSER_CHECK'] = 'html5upload=1&formdata=1';
        foreach (['init'] as $r) $this->runChain($r);     // init → regio_group_id → community
    }

    /** ajx mit *alle: kompletter Zustand + AJXTIMESTAMP + REQUESTID; Folge-ajx aus javascript-Zeilen ausführen */
    private function runChain(string $requestId): void {
        $res = $this->ajxAll($requestId);
        foreach ($this->applyResponse($res) as $next) $this->runChain($next);
    }

    public function createEvent(array $e): array {
        $this->form['hauptkategorie'] = '506';                  $this->runChain('hauptkategorie');
        $this->form['promoter_id'] = '41093';
        $this->form['promoter_id_suche'] = 'PGR Ursulapoppenricht'; $this->runChain('promoter_id');
        $this->form['event_location_id'] = '32236';
        $this->form['event_location_id_suche'] = 'Sportheim Ursulapoppenricht'; $this->runChain('event_location_id');
        $this->form['begin_date'] = $e['date'];                 // TT.MM.JJJJ
        $this->form['begin_time'] = $e['time_from'] ?? '';
        $this->form['end_time']   = $e['time_to'] ?? '';
        $this->form['print_date'] = $e['print_date'] ?? '';
        $this->form['_event_tabreiter'] = 'texte'; $this->form['TABACTIVE'] = 'texte';
        $this->runChain('set_pub');                              // legt Entwurf an → event_id
        $this->form['event_title'] = $e['title'];
        $this->form['event_kurz_text_ck'] = $this->form['event_kurz_text'] = $e['text'];
        $res = $this->ajxAll('SAVE');
        $rows = $this->parse($res);                              // auf success/error prüfen
        return ['event_id' => $this->form['event_id'], 'rows' => $rows];
    }
}
```

`applyResponse()` parst das Format aus 3.2, übernimmt `setvar` und `javascript: set_form_var('a','b')`
in `$this->form`, sammelt `javascript: ajx('X','*alle')` als Folge-Requests und wirft bei
`error`-Zeilen eine Exception. `parseForm()` liest mit DOMDocument alle Felder; bei Checkboxen
`checked ? value : ''`, bei Radios die gewählte, bei Selects die selektierte Option.

Damit bleibt das Oma-Frontend auf **Datum, Uhrzeit, Titel, Text** reduziert; alles andere ist
Konfiguration.

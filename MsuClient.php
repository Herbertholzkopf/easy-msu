<?php
declare(strict_types=1);

/**
 * MsuClient – bedient das MSUevent-Portal (termine.oberpfalznetz.de) im Hintergrund.
 *
 * Protokoll: siehe MSU_PORTAL_ANALYSE.md.
 *  - Session läuft über das Feld SESSIONID (keine Cookies).
 *  - Fast alles geht über POST msu_ajax_server.php mit REQUESTID=<aktion> und dem kompletten
 *    Formularzustand ("*alle"). Der Client hält diesen Zustand in $this->form nach und übernimmt
 *    aus jeder Antwort die setvar-/set_form_var-Zeilen sowie neue Felder aus html-Zeilen.
 *  - Antwortformat:  \n|||<req>~~~<typ>~~~<ziel>~~~<wert>
 */
class MsuException extends RuntimeException {}

class MsuClient
{
    private array  $cfg;
    private array  $form = [];       // aktueller Formularzustand name => value
    private string $sid  = '';
    private string $pageId = 'loginpage';
    private $ch = null;
    private array $messages = [];    // gesammelte success/error/warning-Texte der letzten Aktion
    private int $chainDepth = 0;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->ch  = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => (int)($cfg['timeout'] ?? 40),
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128 Safari/537.36',
            CURLOPT_ENCODING       => '',
        ]);
    }

    public function __destruct()
    {
        if ($this->ch) curl_close($this->ch);
    }

    /* ------------------------------------------------------------------ */
    /*  Öffentliche API                                                    */
    /* ------------------------------------------------------------------ */

    /** Login; wirft MsuException bei falschen Zugangsdaten. */
    public function login(string $user, string $pass): void
    {
        $html = $this->httpGet('index.php');
        $this->form = $this->parseFormHtml($html);
        $this->pageId = $this->form['PAGEID'] ?? 'loginpage';
        $this->setStaticFields();

        $this->form['login_user'] = $user;
        $this->form['login_pass'] = $pass;
        $this->form['privacy_statement_approved'] = $this->form['privacy_statement_approved'] ?? '';
        $this->form['ACTION'] = 'login';

        $this->messages = [];
        $this->ajx('login', 'login_user,login_pass,privacy_statement_approved');
        $this->form['login_pass'] = '';   // nicht weiter mitschleppen

        // Erfolg: 32-stellige SESSIONID + "Login erfolgreich". Bei Fehler kommt SESSIONID="X"
        // und im html-Bereich "meldungsbereich" steht "Login fehlerhaft, bitte nochmal probieren".
        if (strlen($this->sid) < 16) {
            $this->sid = '';
            $err = $this->errorText() ?: 'Login fehlgeschlagen – bitte Benutzername und Passwort prüfen.';
            throw new MsuException($err);
        }
    }

    /** Sauber abmelden (Fehler werden ignoriert). */
    public function logout(): void
    {
        if ($this->sid === '') return;
        try {
            $this->form['PAGEID'] = 'loginpage';
            $this->form['ACTION'] = 'logout';
            $this->ajx('logout', '');
        } catch (Throwable $e) {
            $this->log('logout ignoriert: ' . $e->getMessage());
        }
        $this->sid = '';
    }

    /**
     * Legt einen Einmal-Termin an und speichert ihn (= an die Zeitung schicken).
     *
     * $e = [
     *   'date'        => 'TT.MM.JJJJ',   Pflicht
     *   'time_from'   => 'HH:MM' | '',
     *   'time_to'     => 'HH:MM' | '',
     *   'all_day'     => bool,
     *   'print_dates' => ['TT.MM.JJJJ', ...]  (max 3)
     *   'title'       => string,
     *   'text'        => string  (Kurztext, max 350 Zeichen)
     * ]
     * Rückgabe: ['ok'=>bool, 'event_id'=>string, 'messages'=>string[], 'dry_run'=>bool]
     */
    public function createEvent(array $e): array
    {
        $this->openEventPage();

        // --- Tab "Allgemein" -------------------------------------------------
        $this->form['hauptkategorie'] = (string)$this->cfg['hauptkategorie'];
        $this->ajx('hauptkategorie');

        $this->form['promoter_id']       = (string)$this->cfg['promoter_id'];
        $this->form['promoter_id_suche'] = (string)$this->cfg['promoter_name'];
        $this->ajx('promoter_id');

        if (!empty($this->cfg['location_id'])) {
            $this->form['event_location_id']       = (string)$this->cfg['location_id'];
            $this->form['event_location_id_suche'] = (string)$this->cfg['location_name'];
            $this->ajx('event_location_id');
        }

        $this->form['event_type_id'] = '1';                // Einmal-Termin
        $this->form['_termin_auswahl_tabreiter'] = 'einmal';
        $this->form['begin_date'] = $e['date'];
        $this->form['begin_time'] = $e['all_day'] ? '' : ($e['time_from'] ?? '');
        $this->form['end_time']   = $e['all_day'] ? '' : ($e['time_to'] ?? '');
        $this->form['ganztags']   = $e['all_day'] ? 'Y' : '';
        $pd = array_values(array_filter($e['print_dates'] ?? []));
        $this->form['print_date']  = $pd[0] ?? '';
        $this->form['print_date2'] = $pd[1] ?? '';
        $this->form['print_date3'] = $pd[2] ?? '';
        $this->form['erscheint']   = $this->form['erscheint'] ?? 'event_no_release_date';

        // Tab-Wechsel Allgemein -> Texte: legt serverseitig den Entwurf an
        $this->form['_event_tabreiter'] = 'allgemein';
        $this->form['TABACTIVE']        = 'allgemein';
        $this->ajx('set_pub');
        $eventId = $this->form['event_id'] ?? '';
        if ($eventId === '' || $eventId === '0') {
            throw new MsuException('Portal hat keinen Entwurf angelegt (keine event_id). ' . $this->errorText());
        }
        $this->form['_mm_orig_id'] = $eventId;

        // --- Tab "Texte" -----------------------------------------------------
        $this->form['_event_tabreiter']  = 'texte';
        $this->form['TABACTIVE']         = 'texte';
        $this->form['event_title']       = $e['title'] ?? '';
        $this->form['event_kurz_text_ck'] = $e['text'] ?? '';
        $this->form['event_kurz_text_ck_verbleibend'] = (string)max(0, (int)($this->cfg['max_text_len'] ?? 350) - mb_strlen($e['text'] ?? ''));
        $this->form['event_kurz_text_ck_zeilen'] = (string)(substr_count($e['text'] ?? '', "\n") + 1);

        // --- Speichern -------------------------------------------------------
        if (!empty($this->cfg['dry_run'])) {
            $this->ajx('DELETE');
            $this->log("DRY RUN: Entwurf $eventId wieder gelöscht");
            return ['ok' => true, 'event_id' => $eventId, 'dry_run' => true,
                    'messages' => ['Testmodus: Entwurf ' . $eventId . ' wurde angelegt und wieder gelöscht – nichts gesendet.']];
        }

        $this->form['_event_tabreiter'] = 'allgemein';
        $this->form['TABACTIVE']        = 'allgemein';
        $this->messages = [];
        $raw = $this->ajx('SAVE');

        // 1) Erfolg? (Antwort enthält successmsg2('EVENT wurde erfolgreich gespeichert') und infoToParent(<id>))
        $ok = $this->isSaveSuccess($raw);

        // 2) Dubletten-Warnung? Dann hat das Portal NICHT gespeichert, sondern html~~~dubletten mit
        //    dem vorhandenen Termin und den Links "Event trotzdem speichern" / "Schließen" geliefert.
        //    (Bei Erfolg kommt html~~~dubletten ebenfalls – aber nur mit "&nbsp;".)
        if (!$ok) {
            $dup = $this->extractDuplicate($raw);
            if ($dup !== null) {
                if (!empty($e['force']) && $dup['ajx'] !== '') {
                    $this->log('Dublette – trotzdem speichern via ajx(' . $dup['ajx'] . ')');
                    $this->messages = [];
                    $raw = $this->ajx($dup['ajx'], $dup['fieldlist']);
                    $ok  = $this->isSaveSuccess($raw);
                } else {
                    $this->cleanupDraft($eventId);
                    return [
                        'ok' => false, 'event_id' => '', 'dry_run' => false, 'duplicate' => true,
                        'messages' => ['Im Portal gibt es diesen Termin schon: ' . $dup['text']],
                    ];
                }
            }
        }

        $err = $this->errorText();
        if (!$ok && $err === '' && $this->messages) $err = implode(' / ', $this->messages);

        // Nur wenn wirklich NICHT gespeichert wurde, den Entwurf wegräumen.
        if (!$ok) $this->cleanupDraft($eventId);

        return [
            'ok'       => $ok,
            'event_id' => $ok ? ($this->form['event_id'] ?? $eventId) : '',
            'dry_run'  => false,
            'messages' => $ok ? $this->messages : ($err ? [$err] : ['Unbekannte Antwort des Portals – bitte im Portal prüfen. (' . $this->shorten($raw, 300) . ')']),
        ];
    }

    /** Erfolg einer SAVE-Antwort erkennen. */
    private function isSaveSuccess(string $raw): bool
    {
        if (stripos($raw, 'erfolgreich gespeichert') !== false) return true;
        if (preg_match('/infoToParent\(\s*\d+\s*\)/', $raw)) return true;
        return false;
    }

    /**
     * Dubletten-Dialog aus einer SAVE-Antwort lesen.
     * @return array{text:string, ajx:string, fieldlist:string}|null
     */
    private function extractDuplicate(string $raw): ?array
    {
        foreach (self::parseResponse($raw) as $d) {
            if ($d[1] !== 'html' || $d[2] !== 'dubletten') continue;
            $html = $d[3] ?? '';
            $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), " \t\r\n\x{a0}");
            $plain = trim(preg_replace('/\x{a0}/u', ' ', $plain));
            // Leerer Bereich (nur "&nbsp;") = keine Dublette. Echte Dublette hat den "trotzdem"-Link.
            if ($plain === '' || stripos($plain, 'trotzdem speichern') === false) continue;
            $this->log('DUBLETTEN-HTML: ' . mb_substr(preg_replace('/\s+/', ' ', $html), 0, 3000));

            // Link "Event trotzdem speichern" → ajx('…','…') aus dem href holen
            $ajx = ''; $fl = '*alle';
            if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $links, PREG_SET_ORDER)) {
                foreach ($links as $lnk) {
                    if (stripos(strip_tags($lnk[2]), 'trotzdem speichern') === false) continue;
                    if (!preg_match('/href=(["\'])javascript:(.*)\1\s*$/is', trim($lnk[1]), $m)
                        && !preg_match('/href=(["\'])javascript:(.*?)\1/is', $lnk[1], $m)) break;
                    $js = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                    if (preg_match("/ajx\\('([^']+)'(?:,\\s*'([^']*)')?/", $js, $a)) {
                        $ajx = $a[1]; $fl = ($a[2] ?? '') !== '' ? $a[2] : '*alle';
                    } else {
                        $this->log('DUBLETTE: trotzdem-Link ohne ajx() – href=' . $js);
                    }
                    break;
                }
            }

            // Lesbare Beschreibung des vorhandenen Termins (erste Zeile = erster Treffer)
            $txt = html_entity_decode(strip_tags(preg_replace('/<\/t[dr]>/i', ' ', $html)), ENT_QUOTES, 'UTF-8');
            $txt = preg_replace('/\s+/', ' ', $txt);
            $txt = str_replace(['Daten übernehmen', 'Event laden'], ' ', $txt);
            $txt = preg_replace('/\s*(Event trotzdem speichern.*)$/u', '', $txt);
            $txt = trim(preg_replace('/(Gemeinde|Veranstalter|Veranstaltungsort|Datum|Uhrzeit|Titel|Kurztext|Langtext):/u', ' $1: ', $txt));
            return ['text' => mb_substr($txt, 0, 400), 'ajx' => $ajx, 'fieldlist' => $fl];
        }
        return null;
    }

    /** Angelegten Entwurf nach Fehlschlag wieder löschen (Fehler dabei werden nur geloggt). */
    private function cleanupDraft(string $eventId): void
    {
        try {
            $this->form['event_id'] = $eventId;
            $this->ajx('DELETE');
            $this->log("Entwurf $eventId gelöscht");
        } catch (Throwable $t) {
            $this->log("Entwurf $eventId konnte nicht gelöscht werden: " . $t->getMessage());
        }
    }

    public function getMessages(): array { return $this->messages; }

    /* ------------------------------------------------------------------ */
    /*  Ablauf-Bausteine                                                   */
    /* ------------------------------------------------------------------ */

    /** Seitenwechsel zur Terminerfassung + Init-Kette. */
    private function openEventPage(): void
    {
        if ($this->sid === '') throw new MsuException('Nicht eingeloggt.');

        $post = $this->form;
        unset($post['login_user'], $post['login_pass'], $post['passwort_user'], $post['passwort_email']);
        $post['SESSIONID'] = $this->sid;
        $post['ACTION']    = 'navigate';
        $post['URLPARAMS'] = 'AJX=&NAVIGATIONID=50&PAGEID=event';
        $post['FORM_IS_DIRTY'] = '0';

        $html = $this->httpPost('index.php', $this->encodeParamsSimple($post));
        $form = $this->parseFormHtml($html, false);
        $pageId = $form['PAGEID'] ?? '';
        $title  = preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? trim(strip_tags($m[1])) : '';
        $this->log("navigate → PAGEID=[$pageId] title=[$title] felder=" . count($form));
        if ($pageId === 'loginpage' || stripos($html, 'name="login_pass"') !== false) {
            throw new MsuException('Portal hat zur Login-Seite zurückgeleitet (Session ungültig?).');
        }
        if ($pageId !== 'event' && stripos($html, 'Terminerfassung') === false) {
            throw new MsuException("Terminerfassung konnte nicht geöffnet werden (PAGEID=\"$pageId\", Titel \"$title\").");
        }
        $this->form = $form;
        $this->pageId = 'event';
        $this->form['PAGEID']    = 'event';
        $this->form['SESSIONID'] = $this->sid;
        $this->form['ACTION']    = '';
        $this->form['URLPARAMS'] = '';
        $this->setStaticFields();

        // wie body onLoad="ajx('init','*alle')"  → init → regio_group_id → community
        // Die eigentliche Maske (html~~~detail) kommt erst mit dieser Antwort und wird
        // per mergeHtmlFields() in den Formularzustand übernommen.
        $this->ajx('init');
        if (!array_key_exists('begin_date', $this->form)) {
            throw new MsuException('Terminmaske wurde nach init nicht geladen (Feld begin_date fehlt). Felder: ' . count($this->form));
        }
    }

    private function setStaticFields(): void
    {
        $this->form['MYSELF']            = 'index.php';
        $this->form['APP_VERSION_CHECK'] = (string)$this->cfg['app_version'];
        $this->form['APP_BROWSER_CHECK'] = (string)$this->cfg['browser_check'];
        $this->form['WINDOW']            = $this->form['WINDOW'] ?? '';
        $this->form['FORM_IS_DIRTY']     = '0';
        $this->form['TABACTIVE']         = $this->form['TABACTIVE'] ?? '';
    }

    /**
     * Ein AJAX-Request wie ajx(requestid, feldliste) im Browser.
     * Übernimmt Antwortwerte in $this->form und führt Folge-ajx-Aufrufe aus.
     * Gibt den rohen Antworttext zurück.
     */
    private function ajx(string $requestId, string $fieldList = '*alle'): string
    {
        $params = $this->buildParams($requestId, $fieldList);
        $raw = $this->httpPost('msu_ajax_server.php', self::encodeParams($params));
        $rows = self::parseResponse($raw);
        if (!$rows) {
            $this->log("ROHANTWORT [$requestId]: " . $this->shorten($raw, 1500));
            throw new MsuException("Leere/unerwartete Antwort auf '$requestId'.");
        }
        if (in_array($requestId, ['set_pub', 'SAVE', 'DELETE', 'login'], true)) {
            $this->log("ANTWORT [$requestId]: " . $this->shorten($raw, 2500));
        }
        $follow = $this->applyRows($rows);

        if (++$this->chainDepth > 12) {
            $this->chainDepth = 0;
            throw new MsuException('Zu tiefe Folge-Request-Kette (Schleife?).');
        }
        foreach ($follow as [$rid, $fl]) {
            if (in_array($rid, ['breadcrump', 'logout', 'SAVE', 'DELETE'], true)) continue;
            $this->ajx($rid, $fl);
        }
        $this->chainDepth = max(0, $this->chainDepth - 1);
        return $raw;
    }

    private function buildParams(string $requestId, string $fieldList): array
    {
        $p = ['AJXTIMESTAMP' => (string)(int)round(microtime(true) * 1000)];
        // Im Portal-JS wird die REQUESTID unkodiert angehängt; "x&a=1" ergibt also zwei Parameter.
        foreach (explode('&', $requestId) as $i => $part) {
            if ($i === 0) { $p['REQUESTID'] = $part; continue; }
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            if ($k !== '') $p[$k] = $v;
        }
        $this->form['SESSIONID'] = $this->sid;
        if ($fieldList === '*alle') {
            foreach ($this->form as $k => $v) {
                if ($k === 'URLPARAMS') continue;
                $p[$k] = (string)$v;
            }
        } else {
            $fields = array_filter(array_map('trim', explode(',', $fieldList . ',MYSELF,PAGEID,SESSIONID,TABACTIVE')));
            foreach ($this->form as $k => $v) {          // Felder mit "_" am Anfang immer mitsenden
                if ($k !== '' && $k[0] === '_') $p[$k] = (string)$v;
            }
            foreach ($fields as $f) $p[$f] = (string)($this->form[$f] ?? '');
        }
        return $p;
    }

    /* ------------------------------------------------------------------ */
    /*  Antwort-Verarbeitung                                               */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array<int,string>>  Zeilen [req, typ, ziel, wert, ...] */
    public static function parseResponse(string $raw): array
    {
        $rows = [];
        foreach (explode("\n|||", $raw) as $line) {
            $line = ltrim($line, "|\r\n");
            if ($line === '') continue;
            $d = explode('~~~', $line);
            if (count($d) < 3) continue;
            $d = array_map(fn($x) => str_replace('~TILDE', '~', $x), $d);
            $rows[] = $d;
        }
        return $rows;
    }

    /** Übernimmt setvar/html/javascript-Zeilen; gibt Folge-Requests [[rid, fieldlist], ...] zurück. */
    private function applyRows(array $rows): array
    {
        $follow = [];
        foreach ($rows as $d) {
            [$req, $type, $target] = [$d[0], $d[1], $d[2]];
            $value = $d[3] ?? '';
            switch ($type) {
                case 'setvar':
                    $this->setVar($target, self::jsUnescape($value));
                    break;
                case 'html':
                    if (preg_match('/^meldungsbere/', $target)) {   // meldungsbereich, meldungsbereich2, "meldungsberech" (Tippfehler im Portal)
                        $txt = trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
                        if ($txt !== '' && $txt !== "\u{a0}") {
                            $isErr = (bool)preg_match('/fehler|nicht|ung(ü|ue)ltig|bitte/i', $txt);
                            $this->messages[] = ($isErr ? 'FEHLER: ' : '') . $txt;
                        }
                    }
                    $this->mergeHtmlFields($value);
                    break;
                case 'javascript':
                    $js = $target;
                    if (preg_match_all("/set_form_var\\('([^']*)',\\s*'((?:[^'\\\\]|\\\\.)*)'\\)/", $js, $m, PREG_SET_ORDER)) {
                        foreach ($m as $mm) $this->setVar($mm[1], self::jsUnescape(stripcslashes($mm[2])));
                    }
                    if (preg_match_all("/reiter\\('([^']+)',\\s*'([^']*)'/", $js, $m, PREG_SET_ORDER)) {
                        foreach ($m as $mm) { $this->form[$mm[1]] = $mm[2]; $this->form['TABACTIVE'] = $mm[2]; }
                    }
                    if (preg_match_all("/(?:successmsg2?|errormsg2?|meldung2?)\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $js, $m, PREG_SET_ORDER)) {
                        foreach ($m as $mm) {
                            $txt = self::jsUnescape(stripcslashes($mm[1]));
                            $this->messages[] = (str_starts_with($mm[0], 'error') ? 'FEHLER: ' : '') . $txt;
                        }
                    }
                    // Folge-Requests: nur echte ajx(...)-Aufrufe, nicht solche, die als String in
                    // html_autocomplete_jq(..., "ajx('promoter_id','*alle')") o. ä. übergeben werden.
                    $jsClean = preg_replace('/html_autocomplete_jq\s*\((?:[^()]|\([^()]*\))*\)\s*;?/', '', $js);
                    if (preg_match_all("/(?<![\"'\\w])ajx\\('([^']+)'(?:,\\s*'([^']*)')?/", $jsClean, $m, PREG_SET_ORDER)) {
                        foreach ($m as $mm) $follow[] = [$mm[1], $mm[2] ?? ''];
                    }
                    break;
                case 'success':
                case 'warning':
                    $this->messages[] = self::jsUnescape($target);
                    break;
                case 'error':
                    $this->messages[] = 'FEHLER: ' . self::jsUnescape($target);
                    break;
                case 'tabreiter':
                    $this->form[$target] = $value; $this->form['TABACTIVE'] = $value;
                    break;
                case 'clearselect':
                    $this->form[$target] = '';
                    break;
                case 'addselect':
                    if (!isset($this->form[$target]) || $this->form[$target] === '') $this->form[$target] = $value;
                    break;
                default: // setcookie u.a.
                    break;
            }
        }
        return $follow;
    }

    private function setVar(string $name, string $value): void
    {
        $this->form[$name] = $value;
        if ($name === 'SESSIONID') $this->sid = $value;
    }

    /** Erster Fehlertext der letzten Aktion(en), sonst ''. */
    private function errorText(): string
    {
        foreach ($this->messages as $m) if (str_starts_with($m, 'FEHLER: ')) return $m;
        return '';
    }

    /** JS unescape(): %uXXXX und %XX dekodieren. */
    public static function jsUnescape(string $s): string
    {
        if (strpos($s, '%') === false) return $s;
        $s = preg_replace_callback('/%u([0-9A-Fa-f]{4})/', fn($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $s);
        $t = preg_replace_callback('/%([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), $s);
        if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
        return $t;
    }

    /** Neue/aktualisierte Formularfelder aus einem HTML-Fragment übernehmen. */
    private function mergeHtmlFields(string $html): void
    {
        if (stripos($html, '<input') === false && stripos($html, '<select') === false && stripos($html, '<textarea') === false) return;
        foreach ($this->parseFormHtml('<div>' . $html . '</div>', false) as $k => $v) {
            if ($k === 'SESSIONID' && $v === '') continue;
            $this->form[$k] = $v;
        }
    }

    /**
     * Alle Formularfelder (input/select/textarea) eines HTML-Dokuments/Fragments → name => value,
     * mit derselben Semantik wie get_form_var() im Portal-JS.
     */
    public function parseFormHtml(string $html, bool $requireForm = true): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $out = [];
        $xp = new DOMXPath($dom);

        foreach ($xp->query('//input[@name]') as $el) {
            /** @var DOMElement $el */
            $name = $el->getAttribute('name');
            $type = strtolower($el->getAttribute('type') ?: 'text');
            $val  = $el->getAttribute('value');
            if ($type === 'button' || $type === 'submit' || $type === 'image' || $type === 'file') {
                // Buttons werden vom Browser bei *alle ebenfalls gesendet (mit ihrem value)
                $out[$name] = $val;
                continue;
            }
            if ($type === 'checkbox') { $out[$name] = $el->hasAttribute('checked') ? $val : ''; continue; }
            if ($type === 'radio') {
                if (!array_key_exists($name, $out)) $out[$name] = '';
                if ($el->hasAttribute('checked')) $out[$name] = $val;
                continue;
            }
            $out[$name] = $val;
        }
        foreach ($xp->query('//select[@name]') as $el) {
            $name = $el->getAttribute('name');
            $sel = null; $first = null;
            foreach ($xp->query('.//option', $el) as $opt) {
                $v = $opt->hasAttribute('value') ? $opt->getAttribute('value') : trim($opt->textContent);
                if ($first === null) $first = $v;
                if ($opt->hasAttribute('selected')) $sel = $v;
            }
            $out[$name] = $sel ?? $first ?? '';
        }
        foreach ($xp->query('//textarea[@name]') as $el) {
            $out[$el->getAttribute('name')] = $el->textContent;
        }
        if ($requireForm && !$out) throw new MsuException('Keine Formularfelder in der Portal-Seite gefunden.');
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Encoding + HTTP                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Exakt wie das Portal-JS: pro Wert encodeURIComponent(), danach encodeURI() über den
     * gesamten String → '%' wird zu '%25' (doppelte Kodierung, der Server dekodiert zweimal).
     */
    public static function encodeParams(array $fields): string
    {
        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = $k . '=' . self::encodeURIComponent((string)$v);
        }
        $s = implode('&', $parts);
        // encodeURI lässt A-Z a-z 0-9 ; , / ? : @ & = + $ - _ . ! ~ * ' ( ) # unangetastet
        return preg_replace_callback('/[^A-Za-z0-9;,\/?:@&=+$\-_.!~*\'()#]/', fn($m) => rawurlencode($m[0]), $s);
    }

    /** JS encodeURIComponent(): wie rawurlencode, aber ! * ' ( ) bleiben stehen. */
    public static function encodeURIComponent(string $v): string
    {
        return strtr(rawurlencode($v), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }

    /** Normaler Form-POST (Browser-Submit, einfache Kodierung). */
    private function encodeParamsSimple(array $fields): string
    {
        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    private function httpGet(string $path): string
    {
        curl_setopt_array($this->ch, [
            CURLOPT_URL => $this->cfg['base_url'] . $path,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [],
        ]);
        $res = curl_exec($this->ch);
        $this->checkHttp($res, 'GET ' . $path);
        $this->log("GET $path → " . strlen($res) . ' Bytes');
        return $res;
    }

    private function httpPost(string $path, string $body): string
    {
        curl_setopt_array($this->ch, [
            CURLOPT_URL => $this->cfg['base_url'] . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: */*',
                                   'Origin: ' . rtrim($this->cfg['base_url'], '/'),
                                   'Referer: ' . $this->cfg['base_url'] . 'index.php'],
        ]);
        $res = curl_exec($this->ch);
        $this->checkHttp($res, 'POST ' . $path);
        $rid = preg_match('/REQUESTID=([^&]+)/', $body, $m) ? $m[1] : (preg_match('/ACTION=([^&]+)/', $body, $m) ? 'form:' . $m[1] : '?');
        $this->log("POST $path [$rid] " . strlen($body) . 'B → ' . strlen($res) . 'B'
            . ($this->messages ? ' | ' . implode(' / ', array_slice($this->messages, -3)) : ''));
        return $res;
    }

    private function checkHttp($res, string $what): void
    {
        if ($res === false) throw new MsuException("$what: " . curl_error($this->ch));
        $code = curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        if ($code >= 400) throw new MsuException("$what: HTTP $code");
    }

    /** Antwort fürs Log lesbar machen: SESSIONID maskieren, HTML entfernen, kürzen. */
    private function shorten(string $raw, int $max): string
    {
        $s = preg_replace('/SESSIONID~~~[A-Za-z0-9]+/', 'SESSIONID~~~<sid>', $raw);
        $s = preg_replace('/\n\|\|\|(\w+)~~~html~~~(\w+)~~~/', "\n|||$1~~~html~~~$2~~~ ", $s);
        $s = strip_tags($s);
        $s = preg_replace('/[ \t]+/', ' ', $s);
        $s = preg_replace('/\n+/', "\n", $s);
        $s = str_replace("\n", ' ⏎ ', trim($s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . ' …' : $s;
    }

    private function log(string $line): void
    {
        $file = $this->cfg['log_file'] ?? '';
        if (!$file) return;
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND);
    }
}

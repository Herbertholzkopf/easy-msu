<?php
declare(strict_types=1);
/**
 * Seniorentreff-Termin an die Zeitung schicken – einfache Oberfläche für das MSUevent-Portal.
 *
 * Ablauf:  Anmelden (Portal-Zugangsdaten)  →  Termin eingeben  →  Prüfen  →  Senden  →  Bestätigung
 */
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');
session_start();

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/MsuClient.php';

/* ---------------------------------------------------------------------- */
/*  Hilfsfunktionen                                                        */
/* ---------------------------------------------------------------------- */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''); }

/** 'JJJJ-MM-TT' (HTML date input) → 'TT.MM.JJJJ'; leer bleibt leer. */
function iso2de(string $d): string {
    $d = trim($d);
    if ($d === '') return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return "$m[3].$m[2].$m[1]";
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $d, $m)) return sprintf('%02d.%02d.%04d', $m[1], $m[2], $m[3]);
    return $d;
}
function de2iso(string $d): string {
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) return "$m[3]-$m[2]-$m[1]";
    return $d;
}
function valid_de_date(string $d): bool {
    return (bool)preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3]);
}
function de_ts(string $d): int { return strtotime(de2iso($d) . ' 00:00:00') ?: 0; }
function norm_time(string $t): string {
    $t = trim($t);
    if ($t === '') return '';
    if (preg_match('/^(\d{1,2})[:.,]?(\d{2})?$/', $t, $m)) {
        $h = (int)$m[1]; $mi = (int)($m[2] ?? 0);
        if ($h <= 23 && $mi <= 59) return sprintf('%02d:%02d', $h, $mi);
    }
    return 'INVALID';
}
function weekday_de(string $isoDate): string {
    $n = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    return $n[(int)date('w', strtotime($isoDate))];
}

/* ---------------------------------------------------------------------- */
/*  Aktionen                                                               */
/* ---------------------------------------------------------------------- */
$step   = $_POST['step'] ?? $_GET['step'] ?? '';
$errors = [];
$result = null;

if ($step === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// --- Anmeldung -----------------------------------------------------------
if ($step === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Sitzung abgelaufen, bitte nochmal.';
    } else {
        $user = trim($_POST['user'] ?? '');
        $pass = (string)($_POST['pass'] ?? '');
        if ($user === '' || $pass === '') {
            $errors[] = 'Bitte Benutzername und Passwort eingeben.';
        } else {
            try {
                $c = new MsuClient($cfg);
                $c->login($user, $pass);           // nur prüfen
                $c->logout();
                $_SESSION['msu_user'] = $user;
                $_SESSION['msu_pass'] = $pass;     // bleibt nur in der Server-Sitzung (nicht auf Platte)
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Anmeldung fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
}

$loggedIn = !empty($_SESSION['msu_user']);

// --- Termin: Eingaben einsammeln + prüfen ----------------------------------
$in = [
    'date'      => iso2de($_POST['date'] ?? ''),
    'time_from' => trim($_POST['time_from'] ?? $cfg['default_time_from']),
    'time_to'   => trim($_POST['time_to'] ?? $cfg['default_time_to']),
    'all_day'   => !empty($_POST['all_day']),
    'print1'    => iso2de($_POST['print1'] ?? ''),
    'print2'    => iso2de($_POST['print2'] ?? ''),
    'print3'    => iso2de($_POST['print3'] ?? ''),
    'title'     => trim($_POST['title'] ?? $cfg['default_title']),
    'text'      => trim(str_replace("\r", '', $_POST['text'] ?? $cfg['default_text'])),
];

if ($loggedIn && in_array($step, ['preview', 'send'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) $errors[] = 'Sitzung abgelaufen, bitte nochmal.';

    if (!valid_de_date($in['date'])) $errors[] = 'Bitte ein gültiges Datum für den Seniorentreff eingeben.';

    $in['time_from'] = norm_time($in['time_from']);
    $in['time_to']   = norm_time($in['time_to']);
    if (!$in['all_day']) {
        if ($in['time_from'] === 'INVALID' || $in['time_from'] === '') $errors[] = 'Bitte eine Uhrzeit eingeben (z. B. 14:00) oder "Ganztags" ankreuzen.';
        if ($in['time_to'] === 'INVALID') $errors[] = 'Die End-Uhrzeit ist ungültig (z. B. 17:00).';
    } else {
        $in['time_from'] = $in['time_to'] = '';
    }

    $printDates = [];
    foreach (['print1', 'print2', 'print3'] as $k) {
        if ($in[$k] === '') continue;
        if (!valid_de_date($in[$k])) { $errors[] = "Erscheinungstag \"{$in[$k]}\" ist kein gültiges Datum."; continue; }
        if (valid_de_date($in['date']) && de_ts($in[$k]) > de_ts($in['date'])) {
            $errors[] = "Erscheinungstag {$in[$k]} liegt NACH dem Seniorentreff ({$in['date']}) – das nimmt die Zeitung nicht an.";
        }
        if (date('w', de_ts($in[$k])) == 0) $errors[] = "Erscheinungstag {$in[$k]} ist ein Sonntag – da erscheint keine Zeitung.";
        $printDates[] = $in[$k];
    }
    $printDates = array_values(array_unique($printDates));
    if (!$printDates) $errors[] = 'Bitte mindestens einen Erscheinungstag angeben.';

    if ($in['title'] === '') $errors[] = 'Bitte einen Titel eingeben.';
    if ($in['text'] === '')  $errors[] = 'Bitte einen Text eingeben.';
    if (mb_strlen($in['text']) > $cfg['max_text_len']) $errors[] = 'Der Text ist zu lang (max. ' . $cfg['max_text_len'] . ' Zeichen).';

    if ($errors) {
        $step = 'form';
    } elseif ($step === 'send') {
        try {
            $client = new MsuClient($cfg);
            $client->login($_SESSION['msu_user'], $_SESSION['msu_pass']);
            $result = $client->createEvent([
                'date'        => $in['date'],
                'time_from'   => $in['time_from'],
                'time_to'     => $in['time_to'],
                'all_day'     => $in['all_day'],
                'print_dates' => $printDates,
                'title'       => $in['title'],
                'text'        => $in['text'],
                'force'       => !empty($_POST['force']),     // Dubletten-Warnung übergehen
            ]);
            $client->logout();
        } catch (Throwable $e) {
            $result = ['ok' => false, 'event_id' => '', 'messages' => [$e->getMessage()], 'dry_run' => false];
        }
        $step = 'result';
    }
}
if ($loggedIn && $step === '') $step = 'form';

$printOffsetsJs = json_encode(array_values(array_map('intval', $cfg['print_offsets'] ?? [4, 2, 0])));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Seniorentreff – Termin an die Zeitung</title>
<style>
  :root { --o:#e8850c; --g:#1a7f37; --r:#c62828; --bg:#fbf8f3; }
  * { box-sizing:border-box; }
  body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; font-size:22px; background:var(--bg); color:#222; }
  header { background:var(--o); color:#fff; padding:14px 24px; display:flex; justify-content:space-between; align-items:center; }
  header h1 { margin:0; font-size:26px; }
  header a { color:#fff; font-size:18px; }
  main { max-width:820px; margin:24px auto; padding:0 16px; }
  .card { background:#fff; border-radius:14px; padding:26px 28px; box-shadow:0 2px 10px rgba(0,0,0,.08); }
  label { display:block; font-weight:600; margin:22px 0 8px; }
  label small { font-weight:400; color:#666; font-size:17px; }
  label.section { margin-top:44px; padding-top:22px; border-top:2px solid #eee; }
  .prints { display:grid; grid-template-columns:auto 1fr; gap:12px 18px; align-items:center; max-width:520px; }
  .prints label { margin:0; white-space:nowrap; }
  input[type=text], input[type=date], input[type=time], input[type=password], textarea {
    width:100%; font-size:24px; padding:12px 14px; border:2px solid #bbb; border-radius:10px; background:#fff; }
  input:focus, textarea:focus { outline:none; border-color:var(--o); box-shadow:0 0 0 3px rgba(232,133,12,.25); }
  textarea { min-height:190px; resize:vertical; line-height:1.4; }
  .row { display:flex; gap:16px; flex-wrap:wrap; }
  .row > div { flex:1 1 200px; }
  .check { display:flex; align-items:center; gap:12px; font-weight:600; margin-top:14px; }
  .check input { width:28px; height:28px; }
  .btn { display:inline-block; font-size:24px; font-weight:700; padding:16px 30px; border:0; border-radius:12px; cursor:pointer; margin-top:26px; }
  .btn-main { background:var(--o); color:#fff; }
  .btn-go { background:var(--g); color:#fff; }
  .btn-back { background:#e5e5e5; color:#222; margin-right:14px; }
  .btn:hover { filter:brightness(1.07); }
  .err { background:#fdecea; border:2px solid var(--r); color:#7a1010; border-radius:10px; padding:14px 18px; margin-bottom:18px; }
  .err ul { margin:6px 0 0 20px; }
  .ok { background:#e6f4ea; border:3px solid var(--g); color:#0f4d1f; border-radius:14px; padding:28px; text-align:center; font-size:26px; }
  .bad { background:#fdecea; border:3px solid var(--r); color:#7a1010; border-radius:14px; padding:28px; font-size:22px; }
  .ok .big, .bad .big { font-size:40px; margin-bottom:12px; }
  table.sum { width:100%; border-collapse:collapse; font-size:22px; }
  table.sum th { text-align:left; width:38%; padding:12px 10px; color:#555; font-weight:600; vertical-align:top; }
  table.sum td { padding:12px 10px; vertical-align:top; white-space:pre-wrap; }
  table.sum tr { border-bottom:1px solid #eee; }
  .hint { color:#666; font-size:17px; margin-top:6px; }
  .counter { text-align:right; color:#666; font-size:17px; }
  .counter.over { color:var(--r); font-weight:700; }
  .fixed { color:#555; font-size:18px; margin-top:20px; border-top:1px dashed #ddd; padding-top:12px; }
</style>
</head>
<body>
<header>
  <h1>Seniorentreff – Termin an die Zeitung</h1>
  <?php if ($loggedIn): ?>
    <a href="?step=logout">Abmelden (<?= h($_SESSION['msu_user']) ?>)</a>
  <?php endif; ?>
</header>
<main>

<?php if ($errors): ?>
  <div class="err"><strong>Bitte noch prüfen:</strong><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$loggedIn): /* ============================ LOGIN ============================ */ ?>
  <div class="card">
    <p style="margin-top:0">Bitte mit den Zugangsdaten vom Zeitungs-Portal anmelden.</p>
    <form method="post" autocomplete="on">
      <input type="hidden" name="step" value="login">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label for="user">Benutzername</label>
      <input type="text" id="user" name="user" value="<?= h($_POST['user'] ?? '') ?>" autocomplete="username" autofocus>
      <label for="pass">Passwort</label>
      <input type="password" id="pass" name="pass" autocomplete="current-password">
      <button class="btn btn-main" type="submit">Anmelden</button>
    </form>
  </div>

<?php elseif ($step === 'result'): /* ============================ ERGEBNIS ============================ */ ?>
  <?php if ($result['ok']): ?>
    <div class="ok">
      <div class="big">✔ Erfolgreich übertragen!</div>
      <?php if (!empty($result['dry_run'])): ?>
        <p><strong>Testmodus</strong> – es wurde nichts an die Zeitung gesendet.</p>
      <?php else: ?>
        <p>Der Termin <strong><?= h($in['title']) ?></strong> am <strong><?= h($in['date']) ?></strong> ist bei der Zeitung angekommen.</p>
        <p>Nummer im Portal: <strong><?= h($result['event_id']) ?></strong></p>
      <?php endif; ?>
      <p class="hint"><?= h(implode(' · ', $result['messages'])) ?></p>
    </div>
  <?php elseif (!empty($result['duplicate'])): ?>
    <div class="bad" style="background:#fff8e1;border-color:#e8850c;color:#5a3a00">
      <div class="big">⚠ Diesen Termin gibt es schon</div>
      <p>Die Zeitung hat für diesen Tag bereits einen Termin mit gleichem Veranstalter und Ort.
         Der neue Termin wurde deshalb <strong>nicht</strong> gesendet.</p>
      <ul><?php foreach ($result['messages'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul>
      <p class="hint">Wenn das wirklich ein zweiter, eigener Termin sein soll, kann er trotzdem gesendet werden:</p>
      <form method="post" style="margin-top:6px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="step" value="send">
        <input type="hidden" name="force" value="1">
        <?php foreach (['date','time_from','time_to','print1','print2','print3','title','text'] as $k): ?>
          <input type="hidden" name="<?= $k ?>" value="<?= h(in_array($k, ['date','print1','print2','print3']) ? de2iso($in[$k]) : $in[$k]) ?>">
        <?php endforeach; ?>
        <?php if ($in['all_day']): ?><input type="hidden" name="all_day" value="1"><?php endif; ?>
        <button class="btn btn-back" type="submit">Trotzdem senden</button>
      </form>
    </div>
  <?php else: ?>
    <div class="bad">
      <div class="big">✖ Das hat leider nicht geklappt</div>
      <p>Der Termin wurde <strong>nicht</strong> übertragen. Meldung:</p>
      <ul><?php foreach ($result['messages'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul>
      <p class="hint">Bitte nochmal versuchen oder Andreas Bescheid geben.</p>
    </div>
  <?php endif; ?>
  <a class="btn btn-main" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Neuen Termin eingeben</a>

<?php elseif ($step === 'preview'): /* ============================ PRÜFEN ============================ */ ?>
  <div class="card">
    <h2 style="margin-top:0">Bitte prüfen – stimmt alles?</h2>
    <table class="sum">
      <tr><th>Seniorentreff am</th><td><?= h(weekday_de(de2iso($in['date'])) . ', ' . $in['date']) ?></td></tr>
      <tr><th>Uhrzeit</th><td><?= $in['all_day'] ? 'ganztags' : h($in['time_from'] . ($in['time_to'] ? ' – ' . $in['time_to'] : '') . ' Uhr') ?></td></tr>
      <tr><th>Steht in der Zeitung am</th><td><?= h(implode("\n", array_map(fn($d) => weekday_de(de2iso($d)) . ', ' . $d, $printDates))) ?></td></tr>
      <tr><th>Titel</th><td><?= h($in['title']) ?></td></tr>
      <tr><th>Text</th><td><?= h($in['text']) ?></td></tr>
    </table>
    <div class="fixed">Fest hinterlegt: Veranstalter <strong><?= h($cfg['promoter_name']) ?></strong>,
      Ort <strong><?= h($cfg['location_name']) ?></strong>, Kategorie Vereinstermine, Gemeinde Hahnbach.</div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php foreach (['date','time_from','time_to','print1','print2','print3','title','text'] as $k): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= h(in_array($k, ['date','print1','print2','print3']) ? de2iso($in[$k]) : $in[$k]) ?>">
      <?php endforeach; ?>
      <?php if ($in['all_day']): ?><input type="hidden" name="all_day" value="1"><?php endif; ?>
      <button class="btn btn-back" type="submit" name="step" value="form">← Zurück, etwas ändern</button>
      <button class="btn btn-go" type="submit" name="step" value="send" id="sendbtn">Jetzt an die Zeitung senden ✔</button>
    </form>
    <p class="hint" style="margin-top:20px">Das Senden dauert ca. 10–20 Sekunden. Bitte nur einmal klicken.</p>
  </div>
  <script>
    document.getElementById('sendbtn').closest('form').addEventListener('submit', function(e){
      var b=document.getElementById('sendbtn');
      if (e.submitter === b) setTimeout(function(){ b.disabled=true; b.textContent='Wird gesendet …'; }, 0);
    });
  </script>

<?php else: /* ============================ FORMULAR ============================ */ ?>
  <div class="card">
    <form method="post" id="f">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="step" value="preview">

      <label for="date">Wann ist der Seniorentreff?</label>
      <input type="date" id="date" name="date" value="<?= h(de2iso($in['date'])) ?>" required>

      <div class="row">
        <div>
          <label for="time_from">Beginn <small>(Uhr)</small></label>
          <input type="time" id="time_from" name="time_from" value="<?= h($in['time_from'] === 'INVALID' ? '' : $in['time_from']) ?>">
        </div>
        <div>
          <label for="time_to">Ende <small>(Uhr, kann leer bleiben)</small></label>
          <input type="time" id="time_to" name="time_to" value="<?= h($in['time_to'] === 'INVALID' ? '' : $in['time_to']) ?>">
        </div>
      </div>
      <label class="check"><input type="checkbox" name="all_day" value="1" <?= $in['all_day'] ? 'checked' : '' ?>> Ganztags (keine Uhrzeit)</label>

      <label class="section">An welchen Tagen soll es in der Zeitung stehen? <small>(bis zu 3 Tage, werden automatisch vorgeschlagen)</small></label>
      <div class="prints">
        <label for="print1">1. Erscheinung</label><input type="date" name="print1" id="print1" value="<?= h(de2iso($in['print1'])) ?>">
        <label for="print2">2. Erscheinung</label><input type="date" name="print2" id="print2" value="<?= h(de2iso($in['print2'])) ?>">
        <label for="print3">3. Erscheinung</label><input type="date" name="print3" id="print3" value="<?= h(de2iso($in['print3'])) ?>">
      </div>
      <p class="hint">Die Zeitung nimmt nur Tage bis zum Seniorentreff selbst. Sonntags erscheint keine Zeitung.
        Für die Montagsausgabe muss der Termin bis Freitag 7 Uhr eingetragen sein.</p>

      <label for="title">Titel</label>
      <input type="text" id="title" name="title" value="<?= h($in['title']) ?>" maxlength="200">

      <label for="text">Text für die Zeitung</label>
      <textarea id="text" name="text" maxlength="<?= (int)$cfg['max_text_len'] ?>"><?= h($in['text']) ?></textarea>
      <div class="counter" id="counter"></div>

      <button class="btn btn-main" type="submit">Weiter zum Prüfen →</button>
    </form>
  </div>
  <script>
    (function(){
      var MAX = <?= (int)$cfg['max_text_len'] ?>, OFFSETS = <?= $printOffsetsJs ?>;
      var date=document.getElementById('date'), p=[1,2,3].map(function(i){return document.getElementById('print'+i)});
      var touched = <?= ($in['print1'] || $in['print2'] || $in['print3']) ? 'true' : 'false' ?>;
      function iso(d){ return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
      function suggest(){
        if (!date.value || touched) return;
        // 1. Feld = 4 Tage davor, 2. = 2 Tage davor, 3. = Veranstaltungstag; Sonntag → Samstag
        var seen = {};
        p.forEach(function(el,i){
          if (OFFSETS[i] === undefined) { el.value=''; return; }
          var d = new Date(date.value+'T12:00:00'); d.setDate(d.getDate()-OFFSETS[i]);
          if (d.getDay()===0) d.setDate(d.getDate()-1);
          var v = iso(d); if (seen[v]) v=''; seen[v]=true; el.value = v;
        });
      }
      date.addEventListener('change', suggest);
      p.forEach(function(el){ el.addEventListener('input', function(){ touched=true; }); });
      var t=document.getElementById('text'), c=document.getElementById('counter');
      function cnt(){ var n=t.value.length; c.textContent = n+' von '+MAX+' Zeichen'; c.className='counter'+(n>MAX?' over':''); }
      t.addEventListener('input', cnt); cnt();
      var tf=document.getElementById('time_from'), tt=document.getElementById('time_to'), ad=document.querySelector('input[name=all_day]');
      function allday(){ tf.disabled=tt.disabled=ad.checked; } ad.addEventListener('change', allday); allday();
    })();
  </script>
<?php endif; ?>
</main>
</body>
</html>

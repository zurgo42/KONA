<?php
/**
 * top_wiederherstellen.php - Versehentlich gelöschte Tagesordnungspunkte (TOPs) wiederherstellen
 *
 * Quellen:
 *  A) Tabelle protokoll, Eintrag "TOP-Loeschen-Sicherung" (seit der Korrektur beim Löschen): vollständige Sicherung, exakt.
 *  B) Datenbank-Protokoll svaudit_log: Rekonstruktion aus Anlege- und Änderungs-Einträgen. ACHTUNG: Der Audit-Log kürzt lange
 *     Texte auf 300 Zeichen; gekürzte Felder sind markiert und sollten vor dem Wiederherstellen ergänzt werden.
 *
 * Geschützt durch das System-Admin-Passwort. Es werden nur INSERTs ausgeführt (nichts wird überschrieben).
 * Nach der Wiederherstellung kann das Skript gelöscht werden.
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once __DIR__ . '/audit_parser.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (isset($_POST['admin_password']) && hash_equals((string)SYSTEM_ADMIN_PASSWORD, (string)$_POST['admin_password'])) {
    $_SESSION['system_admin_authenticated'] = true;
}
echo '<!DOCTYPE html><meta charset="utf-8"><title>TOP wiederherstellen</title><body style="font-family:sans-serif;max-width:1000px;margin:20px auto;padding:0 12px">';
echo '<h2>🛟 Gelöschte TOPs wiederherstellen</h2>';
if (empty($_SESSION['system_admin_authenticated'])) {
    echo '<form method="post"><p>System-Admin-Passwort:</p><input type="password" name="admin_password" autofocus> <button>Weiter</button></form>';
    exit;
}

$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (empty($_SESSION['sv_restore_token'])) $_SESSION['sv_restore_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['sv_restore_token'];

function tabelle_vorhanden(PDO $pdo, string $t): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
}
function spalten(PDO $pdo, string $t): array {   // Spaltenname => Auto-Increment?
    $out = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) $out[$c['Field']] = (stripos($c['Extra'], 'auto_increment') !== false);
    return $out;
}

/** Fügt Zeile ein; nur vorhandene Spalten. Liefert die neue ID (lastInsertId) oder wirft. */
function zeile_einfuegen(PDO $pdo, string $tabelle, array $werte): string {
    $sp = spalten($pdo, $tabelle);
    $felder = []; $params = [];
    foreach ($werte as $k => $v) {
        if (!array_key_exists($k, $sp)) continue;
        $felder[] = "`$k`"; $params[] = $v;
    }
    if (!$felder) throw new Exception("Keine Daten für $tabelle");
    $st = $pdo->prepare("INSERT INTO `$tabelle` (" . implode(',', $felder) . ") VALUES (" . implode(',', array_fill(0, count($felder), '?')) . ")");
    $st->execute($params);
    return $pdo->lastInsertId();
}


/** Zeilen aus der Tabelle protokoll, die zu einem TOP gehören (nach item_id), zeitlich aufsteigend */
function prot_zeilen_zu_top(PDO $pdo, int $item_id): array {
    try {
        $st = $pdo->prepare("SELECT zeit, KurzN, was, string FROM protokoll WHERE string LIKE ? OR string LIKE ? ORDER BY zeit ASC");
        $st->execute(['TOP-' . $item_id . ':%', 'Sitzung %/' . $item_id . ': %']);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Protokolltext aus den Diff-Einträgen "Protokoll-Speichern" nachbauen (beste Näherung) */
function prot_protokolltext_rekonstruieren(array $zeilen): string {
    $text = '';
    foreach ($zeilen as $z) {
        if ($z['was'] !== 'Protokoll-Speichern') continue;
        $d = preg_replace('/^TOP-\d+:\s*/u', '', (string)$z['string']);
        if (preg_match("/^Protokoll='(.*)'→'(.*)'$/su", $d, $m)) {          // kurze Werte: vollständig
            $text = $m[2];
        } elseif (preg_match('/^Protokoll=\[(…?)(.*?)«(.*?)»(.*?)→\1\2«(.*?)»\4(…?)\]$/su', $d, $m)) {   // lange Texte: Teilstück mit Kontext
            $alt = $m[2] . $m[3] . $m[4];
            $neu = $m[2] . $m[5] . $m[4];
            if ($text === '' || $m[3] === '' && $m[2] === '' && $m[4] === '') { if ($text === '') $text = $neu; }
            elseif ($alt !== '' && ($pos = mb_strpos($text, $alt)) !== false) {
                $text = mb_substr($text, 0, $pos) . $neu . mb_substr($text, $pos + mb_strlen($alt));
            }
        }
    }
    return $text;
}

$comment_tables = ['comments' => 'svagenda_comments', 'live_comments' => 'svagenda_live_comments', 'post_comments' => 'svagenda_post_comments'];

// ---------------------------------------------------------------- Wiederherstellen (POST)
if (($_POST['restore'] ?? '') !== '' && hash_equals($token, (string)($_POST['token'] ?? ''))) {
    try {
        $item = $_POST['item'] ?? [];
        $comments = json_decode($_POST['comments_json'] ?? '[]', true) ?: [];
        $meeting_id = (int)($item['meeting_id'] ?? 0);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM svmeetings WHERE meeting_id = ?");
        $chk->execute([$meeting_id]);
        if (!$chk->fetchColumn()) throw new Exception("Sitzung $meeting_id existiert nicht.");
        if (trim((string)($item['title'] ?? '')) === '') throw new Exception("Titel fehlt.");

        $pdo->beginTransaction();
        $alte_id = (int)($item['item_id'] ?? 0);
        $frei = $alte_id > 0;
        if ($frei) { $c = $pdo->prepare("SELECT COUNT(*) FROM svagenda_items WHERE item_id = ?"); $c->execute([$alte_id]); $frei = !$c->fetchColumn(); }
        $werte = $item;
        if (!$frei) unset($werte['item_id']);
        foreach ($werte as $k => $v) if ($v === '' && !in_array($k, ['title', 'description', 'protocol_notes', 'proposal_text'], true)) $werte[$k] = null;
        $neue_id = (int)zeile_einfuegen($pdo, 'svagenda_items', $werte);
        if ($neue_id === 0) $neue_id = $alte_id;

        $n_komm = 0; $komm_fehler = [];
        foreach ($comments as $c) {
            $tab = $comment_tables[$c['typ']] ?? null;
            if (!$tab || !tabelle_vorhanden($pdo, $tab)) continue;
            $zeile = $c['daten'];
            $zeile['item_id'] = $neue_id;
            foreach (spalten($pdo, $tab) as $sp => $auto) if ($auto) unset($zeile[$sp]);
            try { $pdo->exec("SAVEPOINT komm"); zeile_einfuegen($pdo, $tab, $zeile); $n_komm++; }
            catch (Throwable $e) { $pdo->exec("ROLLBACK TO SAVEPOINT komm"); $komm_fehler[] = $e->getMessage(); }
        }
        $pdo->commit();
        error_log("top_wiederherstellen: TOP '" . ($item['title'] ?? '') . "' als item_id $neue_id wiederhergestellt");
        echo '<p style="color:green;font-weight:bold">✅ TOP „' . h($item['title']) . '“ wurde als Eintrag ' . (int)$neue_id . ' in Sitzung ' . $meeting_id . ' wiederhergestellt (' . $n_komm . ' Kommentare).</p>';
        if ($komm_fehler) echo '<p style="color:#b26a00">Einige Kommentare konnten nicht eingefügt werden: ' . h(implode(' | ', array_unique($komm_fehler))) . '</p>';
        echo '<p><a href="../index.php?tab=agenda&meeting_id=' . $meeting_id . '">Zur Sitzung</a></p>';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo '<p style="color:#c00"><b>Fehler:</b> ' . h($e->getMessage()) . '</p>';
    }
}

/** Formular mit bearbeitbaren Feldern und den Kommentaren zeigen */
function formular(array $item, array $comments, array $gekuerzt, string $token, string $quelle) {
    echo '<form method="post" style="border:2px solid #4caf50;border-radius:8px;padding:14px;margin:14px 0;background:#f6fff6">';
    echo '<h3 style="margin-top:0">Wiederherstellen (Quelle: ' . h($quelle) . ')</h3>';
    echo '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="comments_json" value="' . h(json_encode($comments, JSON_UNESCAPED_UNICODE)) . '">';
    $kurz = ['item_id', 'meeting_id', 'top_number', 'category', 'priority', 'estimated_duration', 'is_confidential', 'is_active', 'created_by_member_id', 'antrnr', 'status'];
    foreach ($item as $k => $v) {
        if (in_array($k, ['created_at', 'updated_at'], true)) continue;
        echo '<div style="margin:6px 0"><label style="display:block;font-weight:600">' . h($k)
            . (!empty($gekuerzt[$k]) ? ' <span style="color:#c00">⚠️ vom Audit-Log gekürzt (300 Zeichen) - bitte ergänzen!</span>' : '') . '</label>';
        if (in_array($k, ['title', 'description', 'protocol_notes', 'proposal_text'], true)) {
            echo '<textarea name="item[' . h($k) . ']" rows="' . ($k === 'title' ? 1 : 5) . '" style="width:100%">' . h($v) . '</textarea>';
        } else {
            echo '<input name="item[' . h($k) . ']" value="' . h($v) . '" style="width:' . (in_array($k, $kurz, true) ? '220px' : '100%') . '">';
        }
        echo '</div>';
    }
    echo '<p><b>' . count($comments) . ' Kommentar(e)</b> werden mit wiederhergestellt.</p>';
    foreach ($comments as $c) echo '<div style="font-size:12px;color:#555">• [' . h($c['typ']) . '] ' . h(mb_substr((string)($c['daten']['comment_text'] ?? ''), 0, 120)) . '</div>';
    echo '<p><button name="restore" value="1" onclick="return confirm(\'TOP jetzt wiederherstellen?\')" style="padding:8px 18px;background:#4caf50;color:#fff;border:0;border-radius:4px;font-weight:600">♻️ Wiederherstellen</button></p></form>';
}

// ---------------------------------------------------------------- Quelle A: Sicherung im Protokoll
if (isset($_GET['sicherung'])) {
    $st = $pdo->prepare("SELECT * FROM protokoll WHERE was = 'TOP-Loeschen-Sicherung' AND zeit = ? LIMIT 1");
    $st->execute([$_GET['sicherung']]);
    $row = $st->fetch();
    $d = $row ? json_decode($row['string'], true) : null;
    if (!$d || empty($d['item'])) {
        echo '<p style="color:#c00">Sicherung nicht lesbar.</p>';
    } else {
        $komm = [];
        foreach (['comments', 'live_comments', 'post_comments'] as $typ)
            foreach (($d[$typ] ?? []) as $c) $komm[] = ['typ' => $typ, 'daten' => $c];
        formular($d['item'], $komm, [], $token, 'Protokoll-Sicherung vom ' . $row['zeit']);
    }
}

// ---------------------------------------------------------------- Quelle B: Rekonstruktion aus svaudit_log
if (isset($_GET['audit_item']) && tabelle_vorhanden($pdo, 'svaudit_log')) {
    $item_id = (int)$_GET['audit_item'];
    $del_log = (int)($_GET['del_log'] ?? PHP_INT_MAX);
    $item = []; $gek = []; $meldungen = [];

    // 1. Anlegen: Kommentar-INSERT mit dieser item_id suchen; der TOP-INSERT steht unmittelbar davor
    $st = $pdo->prepare("SELECT log_id, query FROM svaudit_log WHERE table_name = 'svagenda_comments' AND action = 'INSERT' AND log_id < ? AND query LIKE ? ORDER BY log_id ASC LIMIT 1");
    $st->execute([$del_log, 'INSERT%VALUES (' . $item_id . ',%']);
    $erster = $st->fetch();
    $basis = null;
    if ($erster) {
        $st = $pdo->prepare("SELECT query FROM svaudit_log WHERE table_name = 'svagenda_items' AND action = 'INSERT' AND log_id BETWEEN ? AND ? ORDER BY log_id DESC LIMIT 1");
        $st->execute([$erster['log_id'] - 3, $erster['log_id'] - 1]);
        $q = $st->fetchColumn();
        $basis = $q ? audit_parse_insert($q) : null;
    }
    if ($basis) {
        foreach ($basis['data'] as $k => $v) { $item[$k] = $v['value']; if ($v['gekuerzt']) $gek[$k] = true; }
    } else {
        $meldungen[] = 'Der ursprüngliche Anlege-Eintrag ließ sich im Audit-Log nicht eindeutig finden. Bitte Sitzung, Nummer und Titel unten von Hand eintragen.';
        $item = ['meeting_id' => $_GET['meeting_id'] ?? '', 'top_number' => '', 'title' => '', 'description' => '', 'category' => 'information', 'protocol_notes' => ''];
    }
    $item = ['item_id' => $item_id] + $item;

    // 2. Änderungen in zeitlicher Reihenfolge darüberlegen
    $st = $pdo->prepare("SELECT log_id, query FROM svaudit_log WHERE table_name = 'svagenda_items' AND action = 'UPDATE' AND log_id < ? AND query LIKE ? ORDER BY log_id ASC");
    $st->execute([$del_log, '%WHERE item_id = ' . $item_id]);
    $n_upd = 0;
    foreach ($st as $r) {
        $u = audit_parse_update($r['query']);
        if (!$u || audit_where_id($u['where']) !== $item_id) { $meldungen[] = 'Eine Änderung (Log ' . (int)$r['log_id'] . ') war zu lang (Audit-Log kürzt bei 2000 Zeichen) und konnte nicht gelesen werden.'; continue; }
        foreach ($u['data'] as $k => $v) {
            if (in_array($k, ['updated_at', 'lzugriff'], true)) continue;
            $item[$k] = $v['value']; $gek[$k] = $v['gekuerzt'];
        }
        $n_upd++;
    }

    // 3. Kommentare
    $komm = [];
    foreach ($comment_tables as $typ => $tab) {
        $st = $pdo->prepare("SELECT log_id, action, query FROM svaudit_log WHERE table_name = ? AND action IN ('INSERT','UPDATE') AND log_id < ? AND query LIKE ? ORDER BY log_id ASC");
        $st->execute([$tab, $del_log, ($tab === 'svagenda_comments' ? 'INSERT%VALUES (' . $item_id . ',%' : '%item_id%')]);
        foreach ($st as $r) {
            if ($r['action'] !== 'INSERT') continue;
            $p = audit_parse_insert($r['query']);
            if (!$p || (string)($p['data']['item_id']['value'] ?? '') !== (string)$item_id) continue;
            $daten = []; foreach ($p['data'] as $k => $v) $daten[$k] = $v['value'];
            if (trim((string)($daten['comment_text'] ?? '')) === '' && $typ === 'comments') continue;   // leerer Start-Eintrag (nur Bewertung) unwichtig
            $komm[] = ['typ' => $typ, 'daten' => $daten];
        }
    }
    // 3b. Ergänzung aus der Tabelle protokoll (Texte dort sind nicht auf 300 Zeichen gekürzt)
    $pz = prot_zeilen_zu_top($pdo, $item_id);
    $pt = prot_protokolltext_rekonstruieren($pz);
    if ($pt !== '' && mb_strlen($pt) > mb_strlen((string)($item['protocol_notes'] ?? ''))) {
        $item['protocol_notes'] = $pt; unset($gek['protocol_notes']);
        $meldungen[] = 'Protokolltext wurde aus den Einträgen der Tabelle protokoll nachgebaut - bitte auf Vollständigkeit prüfen (bei mehrfacher Bearbeitung nur näherungsweise).';
    }
    if (!empty($_GET['prot_neu'])) {   // Titel/Beschreibung aus einem Eintrag "TOP-Neu" übernehmen
        $st = $pdo->prepare("SELECT string FROM protokoll WHERE zeit = ? AND was LIKE 'TOP-Neu%' LIMIT 1");
        $st->execute([$_GET['prot_neu']]);
        if (($ps = $st->fetchColumn()) && preg_match('/^Sitzung (\d+): (.*)$/su', $ps, $m)) {
            $teile = explode(' | ', $m[2], 2);
            $item['meeting_id'] = $m[1]; $item['title'] = $teile[0]; $item['description'] = $teile[1] ?? ($item['description'] ?? '');
            unset($gek['title'], $gek['description']);
        }
    }
    echo '<p style="background:#fff3cd;padding:8px;border-left:4px solid #ffc107">Rekonstruiert aus dem Audit-Log: Anlege-Eintrag ' . ($basis ? 'gefunden' : '<b>nicht gefunden</b>') . ', ' . $n_upd . ' Änderung(en) eingerechnet. '
        . 'Texte über 300 Zeichen sind dort gekürzt (rot markiert) - bitte, soweit möglich, aus anderer Quelle ergänzen.</p>';
    foreach (array_unique($meldungen) as $m) echo '<p style="color:#b26a00">⚠️ ' . h($m) . '</p>';
    formular($item, $komm, $gek, $token, 'Audit-Log');

    // Zugehörige Einträge aus der Tabelle protokoll (zum Nachlesen und Kopieren)
    echo '<h3>Zugehörige Einträge aus der Tabelle protokoll</h3>';
    if (!$pz) echo '<p style="color:#666">Keine Einträge mit dieser TOP-Nummer.</p>';
    else {
        echo '<table border="1" cellpadding="4" style="border-collapse:collapse;font-size:13px;width:100%"><tr><th>Zeit</th><th>Wer</th><th>Aktion</th><th>Inhalt</th></tr>';
        foreach ($pz as $z) echo '<tr><td>' . h($z['zeit']) . '</td><td>' . h($z['KurzN']) . '</td><td>' . h($z['was']) . '</td><td style="white-space:pre-wrap">' . h($z['string']) . '</td></tr>';
        echo '</table>';
    }
    $st = $pdo->prepare("SELECT zeit, KurzN, string FROM protokoll WHERE was LIKE 'TOP-Neu%' AND zeit <= (SELECT logged_at FROM svaudit_log WHERE log_id = ?) ORDER BY zeit DESC LIMIT 15");
    $st->execute([$del_log]);
    $neu_rows = $st->fetchAll();
    if ($neu_rows) {
        echo '<h4>Zuletzt angelegte TOPs (Titel und Beschreibung ins Formular übernehmen)</h4><ul>';
        foreach ($neu_rows as $z) echo '<li>' . h($z['zeit']) . ' ' . h($z['KurzN']) . ': ' . h(mb_substr($z['string'], 0, 120))
            . ' – <a href="?audit_item=' . $item_id . '&del_log=' . $del_log . '&prot_neu=' . urlencode($z['zeit']) . '">übernehmen</a></li>';
        echo '</ul>';
    }
}

// ---------------------------------------------------------------- Übersichten
echo '<h3>A) Sicherungen beim Löschen (Tabelle protokoll)</h3>';
try {
    $rows = $pdo->query("SELECT zeit, MNr, KurzN, string FROM protokoll WHERE was = 'TOP-Loeschen-Sicherung' ORDER BY zeit DESC LIMIT 50")->fetchAll();
} catch (Throwable $e) { $rows = []; }
if (!$rows) echo '<p style="color:#666">Keine Sicherungen vorhanden (gibt es erst für Löschungen nach dem Update).</p>';
else {
    echo '<table border="1" cellpadding="5" style="border-collapse:collapse"><tr><th>Zeit</th><th>Wer</th><th>TOP</th><th></th></tr>';
    foreach ($rows as $r) {
        $d = json_decode($r['string'], true);
        echo '<tr><td>' . h($r['zeit']) . '</td><td>' . h($r['KurzN']) . '</td><td>Sitzung ' . h($d['item']['meeting_id'] ?? '?') . ': TOP ' . h($d['item']['top_number'] ?? '?') . ' ' . h($d['item']['title'] ?? '') . '</td>'
            . '<td><a href="?sicherung=' . urlencode($r['zeit']) . '">anzeigen</a></td></tr>';
    }
    echo '</table>';
}

echo '<h3>B) Gelöschte TOPs laut Datenbank-Protokoll (svaudit_log)</h3>';
if (!tabelle_vorhanden($pdo, 'svaudit_log')) {
    echo '<p style="color:#c00">Die Tabelle svaudit_log existiert nicht - Rekonstruktion nicht möglich.</p>';
} else {
    $rows = $pdo->query("SELECT log_id, logged_at, member_id, query FROM svaudit_log WHERE table_name = 'svagenda_items' AND action = 'DELETE' ORDER BY log_id DESC LIMIT 100")->fetchAll();
    $ex = $pdo->prepare("SELECT COUNT(*) FROM svagenda_items WHERE item_id = ?");
    echo '<table border="1" cellpadding="5" style="border-collapse:collapse"><tr><th>Zeit</th><th>Mitglied-ID</th><th>Abfrage</th><th></th></tr>';
    $n = 0;
    foreach ($rows as $r) {
        if (!preg_match('/WHERE\s+item_id\s*=\s*(\d+)\s*$/i', $r['query'], $m)) continue;   // Sammel-Löschungen (ganze Sitzung) hier nicht
        $iid = (int)$m[1];
        $ex->execute([$iid]);
        $gibt = $ex->fetchColumn() > 0;
        $n++;
        echo '<tr><td>' . h($r['logged_at']) . '</td><td>' . h($r['member_id']) . '</td><td><code>' . h($r['query']) . '</code></td><td>'
            . ($gibt ? 'existiert noch' : '<a href="?audit_item=' . $iid . '&del_log=' . (int)$r['log_id'] . '">rekonstruieren</a>') . '</td></tr>';
    }
    echo '</table>';
    if (!$n) echo '<p style="color:#666">Keine Einzel-Löschungen von TOPs im Audit-Log gefunden.</p>';
}
echo '<p style="margin-top:24px;color:#666;font-size:13px">Tipp: Der Eintrag „TOP-Loeschen“ in der Tabelle protokoll nennt Sitzung und Titel des tatsächlich gelöschten TOP.</p>';

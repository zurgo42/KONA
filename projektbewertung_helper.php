<?php
/**
 * projektbewertung_helper.php - Bewertung von Projekten nach Aufwand und Nutzen (temporäres Feature)
 *
 * - Eingabe: pro Projekt Aufwand (1-7), Nutzen (1-7) und Kommentar, pro Nutzer gespeichert und änderbar
 * - Bis zum Stichtag: Eingabemaske; danach: Ergebnistabelle (Mittelwerte, Nutzen minus Aufwand, sortiert)
 * - Import der Projektliste aus Excel (.xlsx) oder CSV
 *
 * Einstellungen (hier direkt änderbar):
 *   PB_RUNDE            aktuelle Runde (1; Januar-Runde: 2)
 *   PB_STICHTAG         letzter Eingabetag dieser Runde, 'YYYY-MM-DD'
 *   PB_FREIGABE_ALLE    true = für alle mit aktiv > PB_MIN_AKTIV sichtbar, false = nur Testpersonen
 *   PB_MIN_AKTIV        Mindestwert aktiv (11 = aktiv > 11)
 *   PB_TESTPERSONEN     Mitgliedsnummern der Testpersonen/Verantwortlichen (Import, CSV)
 */
if (!defined('PB_RUNDE'))         define('PB_RUNDE', 1);
if (!defined('PB_STICHTAG'))      define('PB_STICHTAG', '2026-12-04');
if (!defined('PB_FREIGABE_ALLE')) define('PB_FREIGABE_ALLE', false);
if (!defined('PB_MIN_AKTIV'))     define('PB_MIN_AKTIV', 11);
if (!defined('PB_TESTPERSONEN'))  define('PB_TESTPERSONEN', '0495018,0493993,049635');   // Testphase + Export/Import

const PB_GRUPPE_VORSCHLAG = 'Weitere Vorschläge';   // Gruppe der von Teilnehmern ergänzten Projekte (ID W-1, W-2 ...)
const PB_EINFUEHRUNG = 'Wie im Führungteam-Treffen besprochen wollen wir die von der Gruppe Vereinsentwicklung aufgenommenen Projekte nach Aufwand und Nutzen bewerten. Besprochen ist, dass es jetzt eine erste Einschätzung gibt und dann im Januar eine weitere Runde. Ausfüllbar ist das für diese erste Phase also bis zum 4. Dezember.';

/** Tabellen anlegen (falls noch nicht vorhanden) */
function pb_tabellen_sicherstellen(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $mysql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
    $ext = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS svprojekt_items (
        item_id     VARCHAR(40)  NOT NULL PRIMARY KEY,
        gruppe      VARCHAR(200) NULL,
        stichwort   VARCHAR(300) NULL,
        beschreibung TEXT NULL,
        sortierung  INT NOT NULL DEFAULT 0
    )$ext");
    $pdo->exec("CREATE TABLE IF NOT EXISTS svprojekt_ratings (
        runde      INT          NOT NULL,
        item_id    VARCHAR(40)  NOT NULL,
        member_id  INT          NOT NULL,
        aufwand    INT          NULL,
        nutzen     INT          NULL,
        kommentar  TEXT         NULL,
        updated_at VARCHAR(19)  NULL,
        PRIMARY KEY (runde, item_id, member_id)
    )$ext");
    $done = true;
}

/**
 * Zugriff: ['sehen'=>bool, 'admin'=>bool (Import/Einzeldaten/CSV)]
 * - Testpersonen: immer
 * - aktiv > PB_MIN_AKTIV: sobald PB_FREIGABE_ALLE gesetzt ist, in jedem Fall aber für die Ergebnistabelle nach dem Stichtag
 */
function pb_zugriff(array $user): array {
    $mnr = trim((string)($user['membership_number'] ?? ''));
    $tester = in_array($mnr, array_map('trim', explode(',', PB_TESTPERSONEN)), true);
    $berechtigt = (int)($user['aktiv'] ?? 0) > PB_MIN_AKTIV;
    $sehen = $tester || ($berechtigt && (PB_FREIGABE_ALLE || !pb_eingabe_offen()));
    return ['sehen' => $sehen, 'admin' => $tester, 'eingabe_berechtigt' => $sehen];
}

/** true solange Eingaben möglich sind (bis Ende des Stichtags) */
function pb_eingabe_offen(?int $jetzt = null): bool {
    $jetzt = $jetzt ?? time();
    return $jetzt <= strtotime(PB_STICHTAG . ' 23:59:59');
}

function pb_stichtag_text(): string { return date('d.m.Y', strtotime(PB_STICHTAG)); }

function pb_items(PDO $pdo): array {
    return $pdo->query("SELECT * FROM svprojekt_items ORDER BY sortierung, item_id")->fetchAll(PDO::FETCH_ASSOC);
}

function pb_meine_bewertungen(PDO $pdo, int $member_id): array {
    $st = $pdo->prepare("SELECT item_id, aufwand, nutzen, kommentar FROM svprojekt_ratings WHERE runde = ? AND member_id = ?");
    $st->execute([PB_RUNDE, $member_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['item_id']] = $r;
    return $out;
}

/**
 * Neue Projektvorschläge von Teilnehmern speichern (Überschrift + Beschreibung).
 * Sie erscheinen danach für alle in der Gruppe PB_GRUPPE_VORSCHLAG und können wie die übrigen Projekte bewertet werden.
 * Liefert die Anzahl neu angelegter Vorschläge.
 */
function pb_vorschlaege_speichern(PDO $pdo, array $post): int {
    $titel = (array)($post['neu_titel'] ?? []);
    $beschr = (array)($post['neu_beschr'] ?? []);
    $neu = 0;
    $max = $pdo->query("SELECT item_id FROM svprojekt_items WHERE item_id LIKE 'W-%'")->fetchAll(PDO::FETCH_COLUMN);
    $nr = 0;
    foreach ($max as $id) $nr = max($nr, (int)substr($id, 2));
    $dup = $pdo->prepare("SELECT COUNT(*) FROM svprojekt_items WHERE gruppe = ? AND stichwort = ?");
    $ins = $pdo->prepare("INSERT INTO svprojekt_items (item_id, gruppe, stichwort, beschreibung, sortierung) VALUES (?,?,?,?,?)");
    foreach (array_slice($titel, 0, 10, true) as $i => $t) {
        $t = trim((string)$t);
        $b = trim((string)($beschr[$i] ?? ''));
        if ($t === '') continue;                       // ohne Überschrift wird nichts angelegt
        $t = mb_substr($t, 0, 300);
        $b = mb_substr($b, 0, 4000);
        $dup->execute([PB_GRUPPE_VORSCHLAG, $t]);
        if ($dup->fetchColumn() > 0) continue;         // gleiche Überschrift gibt es schon (z. B. Doppelklick)
        $nr++;
        $ins->execute(['W-' . $nr, PB_GRUPPE_VORSCHLAG, $t, $b, 100000 + $nr]);
        $neu++;
    }
    return $neu;
}

/** Eigene Bewertungen speichern. Leere Zeilen werden nicht gewertet (und entfernt). Liefert [gespeichert, gelöscht] */
function pb_bewertungen_speichern(PDO $pdo, int $member_id, array $post): array {
    $items = array_column(pb_items($pdo), 'item_id');
    $gespeichert = 0; $geloescht = 0;
    $now = date('Y-m-d H:i:s');
    $del = $pdo->prepare("DELETE FROM svprojekt_ratings WHERE runde = ? AND item_id = ? AND member_id = ?");
    $rep = $pdo->prepare("REPLACE INTO svprojekt_ratings (runde, item_id, member_id, aufwand, nutzen, kommentar, updated_at) VALUES (?,?,?,?,?,?,?)");
    foreach ($items as $iid) {
        $a = pb_wert($post['aufwand'][$iid] ?? '');
        $n = pb_wert($post['nutzen'][$iid] ?? '');
        $k = trim((string)($post['kommentar'][$iid] ?? ''));
        if ($k !== '' && mb_strlen($k) > 4000) $k = mb_substr($k, 0, 4000);
        if ($a === null && $n === null && $k === '') {
            $del->execute([PB_RUNDE, $iid, $member_id]);
            $geloescht += $del->rowCount();
        } else {
            $rep->execute([PB_RUNDE, $iid, $member_id, $a, $n, $k === '' ? null : $k, $now]);
            $gespeichert++;
        }
    }
    return [$gespeichert, $geloescht];
}

/** '1'..'7' -> int, sonst null */
function pb_wert($v): ?int {
    if (!is_scalar($v) || !preg_match('/^[1-7]$/', trim((string)$v))) return null;
    return (int)trim((string)$v);
}

/** Auswertung: je Projekt Mittelwerte und Differenz, sortiert nach Nutzen minus Aufwand (absteigend) */
function pb_auswertung(PDO $pdo): array {
    $items = pb_items($pdo);
    $st = $pdo->prepare("SELECT item_id, AVG(aufwand) AS a, AVG(nutzen) AS n, COUNT(aufwand) AS na, COUNT(nutzen) AS nn
                         FROM svprojekt_ratings WHERE runde = ? GROUP BY item_id");
    $st->execute([PB_RUNDE]);
    $agg = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $agg[$r['item_id']] = $r;
    foreach ($items as &$it) {
        $r = $agg[$it['item_id']] ?? null;
        $it['aufwand'] = ($r && $r['na'] > 0) ? (float)$r['a'] : null;
        $it['nutzen']  = ($r && $r['nn'] > 0) ? (float)$r['n'] : null;
        $it['anzahl_a'] = (int)($r['na'] ?? 0);
        $it['anzahl_n'] = (int)($r['nn'] ?? 0);
        $it['diff'] = ($it['aufwand'] !== null && $it['nutzen'] !== null) ? $it['nutzen'] - $it['aufwand'] : null;
    }
    unset($it);
    usort($items, function ($x, $y) {
        if ($x['diff'] === null && $y['diff'] === null) return strcmp((string)$x['item_id'], (string)$y['item_id']);
        if ($x['diff'] === null) return 1;
        if ($y['diff'] === null) return -1;
        return $y['diff'] <=> $x['diff'] ?: strcmp((string)$x['item_id'], (string)$y['item_id']);
    });
    return $items;
}

/** Bewerter-Pseudonyme P01, P02 ... (Reihenfolge nicht an Mitgliedsnummer/-id gekoppelt) */
function pb_pseudonyme(array $member_ids): array {
    $ids = array_values(array_unique(array_map('intval', $member_ids)));
    sort($ids);
    mt_srand(crc32('pb' . PB_RUNDE . count($ids)));
    shuffle($ids);
    $pseudo = [];
    foreach ($ids as $i => $mid) $pseudo[$mid] = sprintf('P%02d', $i + 1);
    return $pseudo;
}

/** Alle Einzelbewertungen der Runde als Liste (mit Kommentaren, ohne Mitgliedsangaben), sortiert nach Projekt und Bewerter-Kürzel */
function pb_einzeldaten(PDO $pdo): array {
    $st = $pdo->prepare("SELECT r.item_id, i.gruppe, i.stichwort, i.sortierung, r.member_id, r.aufwand, r.nutzen, r.kommentar
                         FROM svprojekt_ratings r LEFT JOIN svprojekt_items i ON i.item_id = r.item_id
                         WHERE r.runde = ?");
    $st->execute([PB_RUNDE]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $pseudo = pb_pseudonyme(array_column($rows, 'member_id'));
    foreach ($rows as &$r) { $r['bewerter'] = $pseudo[(int)$r['member_id']]; unset($r['member_id']); }
    unset($r);
    usort($rows, function ($a, $b) {
        return ((int)$a['sortierung'] <=> (int)$b['sortierung']) ?: strcmp((string)$a['item_id'], (string)$b['item_id']) ?: strcmp($a['bewerter'], $b['bewerter']);
    });
    return $rows;
}

/** CSV für die Auswertung: eine Zeile je Projekt, je Bewerter zwei Spalten (Aufwand, Nutzen) nebeneinander, ohne Kommentare */
function pb_csv(PDO $pdo): string {
    $items = pb_items($pdo);
    $st = $pdo->prepare("SELECT item_id, member_id, aufwand, nutzen FROM svprojekt_ratings WHERE runde = ?");
    $st->execute([PB_RUNDE]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $pseudo = pb_pseudonyme(array_column($rows, 'member_id'));
    asort($pseudo);   // P01, P02, ... in Spaltenreihenfolge
    $werte = [];
    foreach ($rows as $r) $werte[$r['item_id']][(int)$r['member_id']] = [$r['aufwand'], $r['nutzen']];
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    $kopf = ['ID', 'Gruppe', 'Stichwort'];
    foreach ($pseudo as $mid => $p) { $kopf[] = $p . ' Aufwand'; $kopf[] = $p . ' Nutzen'; }
    fputcsv($f, $kopf, ';', '"', '');
    foreach ($items as $it) {
        $z = [$it['item_id'], $it['gruppe'], $it['stichwort']];
        foreach ($pseudo as $mid => $p) {
            $w = $werte[$it['item_id']][$mid] ?? [null, null];
            $z[] = $w[0] === null ? '' : $w[0];
            $z[] = $w[1] === null ? '' : $w[1];
        }
        fputcsv($f, $z, ';', '"', '');
    }
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return $csv;
}

// ---------------------------------------------------------------- Import

/** Spaltenbuchstaben (A, B, ..., AA) -> 0-basierter Index */
function pb_spalte_index(string $ref): int {
    $ref = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0; $i < strlen($ref); $i++) $n = $n * 26 + (ord($ref[$i]) - 64);
    return $n - 1;
}

/** Liest das erste Tabellenblatt einer .xlsx-Datei als Zeilenarray (ohne externe Bibliothek) */
function pb_xlsx_lesen(string $datei): array {
    if (!class_exists('ZipArchive')) throw new Exception('PHP-Erweiterung zip fehlt - bitte Datei als CSV (Semikolon) speichern.');
    $zip = new ZipArchive();
    if ($zip->open($datei) !== true) throw new Exception('Excel-Datei konnte nicht geöffnet werden.');
    $shared = [];
    if (($x = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $dom = new DOMDocument(); $dom->loadXML($x);
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//*[local-name()="si"]') as $si) {
            $t = '';
            foreach ($xp->query('.//*[local-name()="t"]', $si) as $tn) $t .= $tn->textContent;
            $shared[] = $t;
        }
    }
    // erstes Blatt: kleinste sheetN.xml
    $sheet = null; $best = PHP_INT_MAX;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nm = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $nm, $m) && (int)$m[1] < $best) { $best = (int)$m[1]; $sheet = $nm; }
    }
    if (!$sheet) throw new Exception('Kein Tabellenblatt gefunden.');
    $dom = new DOMDocument(); $dom->loadXML($zip->getFromName($sheet));
    $zip->close();
    $xp = new DOMXPath($dom);
    $rows = [];
    foreach ($xp->query('//*[local-name()="row"]') as $row) {
        $r = [];
        foreach ($xp->query('./*[local-name()="c"]', $row) as $c) {
            $idx = pb_spalte_index($c->getAttribute('r'));
            $type = $c->getAttribute('t');
            $v = '';
            if ($type === 'inlineStr') {
                foreach ($xp->query('.//*[local-name()="t"]', $c) as $tn) $v .= $tn->textContent;
            } else {
                $vn = $xp->query('./*[local-name()="v"]', $c)->item(0);
                $v = $vn ? $vn->textContent : '';
                if ($type === 's') $v = $shared[(int)$v] ?? '';
                elseif ($type === '' && $v !== '' && is_numeric($v) && (float)$v == (int)$v) $v = (string)(int)$v;
            }
            $r[$idx] = $v;
        }
        if ($r) { $max = max(array_keys($r)); $line = []; for ($i = 0; $i <= $max; $i++) $line[] = $r[$i] ?? ''; $rows[] = $line; }
    }
    return $rows;
}

function pb_csv_lesen(string $datei): array {
    $txt = file_get_contents($datei);
    if (substr($txt, 0, 3) === "\xEF\xBB\xBF") $txt = substr($txt, 3);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $first = strtok($txt, "\n");
    $delim = (substr_count($first, ';') >= substr_count($first, ',')) ? ';' : ',';
    $f = fopen('php://temp', 'w+'); fwrite($f, $txt); rewind($f);
    $rows = [];
    while (($r = fgetcsv($f, 0, $delim, '"', '')) !== false) { if ($r !== [null]) $rows[] = $r; }
    fclose($f);
    return $rows;
}

/** Zeilen (Kopfzeile + Daten) -> Projekte [ID, Gruppe, Stichwort, Beschreibung] */
function pb_zeilen_zu_projekten(array $rows): array {
    if (!$rows) throw new Exception('Die Datei enthält keine Zeilen.');
    $kopf = array_map(function ($s) { return mb_strtolower(trim((string)$s)); }, $rows[0]);
    $map = ['id' => 0, 'gruppe' => 1, 'stichwort' => 2, 'beschreibung' => 3];   // Standard: Spaltenreihenfolge
    $hat_kopf = in_array('id', $kopf, true) || in_array('stichwort', $kopf, true) || in_array('gruppe', $kopf, true);
    if ($hat_kopf) {
        foreach (array_keys($map) as $k) { $p = array_search($k, $kopf, true); if ($p !== false) $map[$k] = $p; }
        array_shift($rows);
    }
    $out = []; $seen = [];
    foreach ($rows as $r) {
        $id = trim((string)($r[$map['id']] ?? ''));
        if ($id === '') continue;
        if (isset($seen[$id])) throw new Exception("ID „{$id}“ kommt mehrfach vor.");
        $seen[$id] = true;
        $out[] = ['id' => mb_substr($id, 0, 40),
                  'gruppe' => mb_substr(trim((string)($r[$map['gruppe']] ?? '')), 0, 200),
                  'stichwort' => mb_substr(trim((string)($r[$map['stichwort']] ?? '')), 0, 300),
                  'beschreibung' => trim((string)($r[$map['beschreibung']] ?? ''))];
    }
    if (!$out) throw new Exception('Keine Projekte mit ID gefunden.');
    return $out;
}

/** Projekte in die Tabelle übernehmen (vorhandene IDs werden aktualisiert; Bewertungen bleiben erhalten) */
function pb_projekte_importieren(PDO $pdo, array $projekte): array {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM svprojekt_items WHERE item_id = ?");
    $ins = $pdo->prepare("REPLACE INTO svprojekt_items (item_id, gruppe, stichwort, beschreibung, sortierung) VALUES (?,?,?,?,?)");
    $neu = 0; $akt = 0; $pos = 0;
    $pdo->beginTransaction();
    try {
        foreach ($projekte as $p) {
            $pos++;
            $exists->execute([$p['id']]);
            if ($exists->fetchColumn() > 0) $akt++; else $neu++;
            $ins->execute([$p['id'], $p['gruppe'], $p['stichwort'], $p['beschreibung'], $pos]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return [$neu, $akt];
}

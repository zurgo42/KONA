<?php
/**
 * audit_parser.php - Hilfsfunktionen: SQL-Texte aus svaudit_log wieder in Spalten/Werte zerlegen
 *
 * Der Audit-Log (audit_pdo.php) speichert INSERT/UPDATE/DELETE mit eingesetzten Parametern. Lange Textwerte sind dort
 * auf 300 Zeichen gekürzt (Endung "…"), die ganze Abfrage auf 2000 Zeichen. Der Parser markiert solche Werte als "gekürzt".
 */

/** Zerlegt einen Ausdruck an Kommas der obersten Ebene (außerhalb von Anführungszeichen und Klammern). null = unvollständig. */
function audit_split_top_level(string $s): ?array {
    $parts = []; $cur = ''; $depth = 0; $inq = false; $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($inq) {
            $cur .= $c;
            if ($c === "'") {
                if ($i + 1 < $n && $s[$i + 1] === "'") { $cur .= "'"; $i++; }   // '' = maskiertes Anführungszeichen
                else $inq = false;
            }
            continue;
        }
        if ($c === "'") { $inq = true; $cur .= $c; continue; }
        if ($c === '(') $depth++;
        if ($c === ')') $depth--;
        if ($c === ',' && $depth === 0) { $parts[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if ($inq || $depth !== 0) return null;
    $parts[] = trim($cur);
    return $parts;
}

/** Einzelnen SQL-Wert lesen: ['ok'=>bool, 'value'=>mixed, 'gekuerzt'=>bool] (Funktionen wie NOW() sind nicht ok) */
function audit_sql_value(string $tok): array {
    $t = trim($tok);
    if (strcasecmp($t, 'NULL') === 0) return ['ok' => true, 'value' => null, 'gekuerzt' => false];
    if (preg_match('/^-?\d+(\.\d+)?$/', $t)) return ['ok' => true, 'value' => $t, 'gekuerzt' => false];
    if (strlen($t) >= 2 && $t[0] === "'" && substr($t, -1) === "'") {
        $inner = str_replace("''", "'", substr($t, 1, -1));
        $gek = (mb_substr($inner, -1) === '…');
        if ($gek) $inner = mb_substr($inner, 0, -1);
        return ['ok' => true, 'value' => $inner, 'gekuerzt' => $gek];
    }
    return ['ok' => false, 'value' => null, 'gekuerzt' => false];
}

/** INSERT INTO t (a, b) VALUES (x, y) -> ['table'=>, 'data'=>[col=>['value'=>,'gekuerzt'=>]], 'unvollstaendig'=>[Spalten]] oder null */
function audit_parse_insert(string $q): ?array {
    if (!preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\((.*?)\)\s*VALUES\s*\((.*)\)\s*$/is', $q, $m)) return null;
    $cols = array_map(fn($c) => trim($c, " `\n\r\t"), explode(',', $m[2]));
    $vals = audit_split_top_level($m[3]);
    if ($vals === null || count($vals) !== count($cols)) return null;
    $data = []; $skip = [];
    foreach ($cols as $i => $col) {
        $v = audit_sql_value($vals[$i]);
        if ($v['ok']) $data[$col] = ['value' => $v['value'], 'gekuerzt' => $v['gekuerzt']]; else $skip[] = $col;
    }
    return ['table' => $m[1], 'data' => $data, 'unvollstaendig' => $skip];
}

/** Position des ersten Schlüsselworts WHERE außerhalb von Anführungszeichen (oder null) */
function audit_find_where(string $s): ?int {
    $inq = false; $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($inq) {
            if ($c === "'") { if ($i + 1 < $n && $s[$i + 1] === "'") $i++; else $inq = false; }
            continue;
        }
        if ($c === "'") { $inq = true; continue; }
        if (($c === 'W' || $c === 'w') && preg_match('/\GWHERE\b/i', $s, $mm, 0, $i) && ($i === 0 || ctype_space($s[$i - 1]))) return $i;
    }
    return null;
}

/** UPDATE t SET a = x, b = y WHERE item_id = 5 -> ['table'=>, 'data'=>[...], 'where'=>'item_id = 5', 'unvollstaendig'=>[...]] oder null */
function audit_parse_update(string $q): ?array {
    if (!preg_match('/^\s*UPDATE\s+`?(\w+)`?\s+SET\s+(.*)$/is', $q, $m)) return null;
    $rest = $m[2];
    $w = audit_find_where($rest);
    if ($w === null) return null;
    $set = substr($rest, 0, $w);
    $where = trim(substr($rest, $w + 5));
    $parts = audit_split_top_level($set);
    if ($parts === null) return null;
    $data = []; $skip = [];
    foreach ($parts as $p) {
        if (!preg_match('/^`?(\w+)`?\s*=\s*(.*)$/s', $p, $pm)) return null;
        $v = audit_sql_value($pm[2]);
        if ($v['ok']) $data[$pm[1]] = ['value' => $v['value'], 'gekuerzt' => $v['gekuerzt']]; else $skip[] = $pm[1];
    }
    return ['table' => $m[1], 'data' => $data, 'where' => $where, 'unvollstaendig' => $skip];
}

/** Zahl aus "WHERE item_id = 123" (oder null) */
function audit_where_id(string $where, string $col = 'item_id'): ?int {
    return preg_match('/^\s*`?' . preg_quote($col, '/') . '`?\s*=\s*(\d+)\s*$/i', $where, $m) ? (int)$m[1] : null;
}

<?php
/**
 * tab_projektbewertung.php - Projektbewertung nach Aufwand und Nutzen (temporäres Feature)
 */
require_once __DIR__ . '/projektbewertung_helper.php';
pb_tabellen_sicherstellen($pdo);
$pb_zugriff = pb_zugriff($current_user);

if (!$pb_zugriff['sehen']) {
    echo '<div style="max-width:600px;margin:40px auto;padding:30px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:8px;">'
       . '<h3 style="color:#856404;margin:0 0 12px 0;">🔒 Zugriff nicht möglich</h3>'
       . '<p style="color:#856404;margin:0;">Diese Seite ist derzeit nicht freigeschaltet.</p></div>';
    return;
}

$pb_h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$pb_offen = pb_eingabe_offen();
// Testpersonen können die Ergebnisansicht vorab mit ?ansicht=ergebnis prüfen
$pb_ergebnis = !$pb_offen || (($_GET['ansicht'] ?? '') === 'ergebnis' && $pb_zugriff['admin']);
$pb_msg = $_GET['msg'] ?? '';
$pb_items = pb_items($pdo);
?>
<h2>📊 Projekte bewerten: Aufwand und Nutzen</h2>

<?php if ($pb_msg === 'pb_gespeichert'): ?>
    <div class="message">✅ Deine Bewertung wurde gespeichert (<?php echo (int)($_GET['n'] ?? 0); ?> Projekte bewertet). Du kannst sie bis zum <?php echo $pb_h(pb_stichtag_text()); ?> jederzeit ändern.</div>
<?php elseif ($pb_msg === 'pb_geschlossen'): ?>
    <div class="error">Die Eingabephase ist beendet, es können keine Bewertungen mehr gespeichert werden.</div>
<?php elseif ($pb_msg === 'pb_fehler'): ?>
    <div class="error">Die Bewertung konnte nicht gespeichert werden.</div>
<?php elseif ($pb_msg === 'pb_import'): ?>
    <div class="message">✅ Projektliste importiert: <?php echo (int)($_GET['neu'] ?? 0); ?> neu, <?php echo (int)($_GET['akt'] ?? 0); ?> aktualisiert.</div>
<?php elseif ($pb_msg === 'pb_importfehler'): ?>
    <div class="error">Import fehlgeschlagen: <?php echo $pb_h($_GET['t'] ?? ''); ?></div>
<?php endif; ?>

<?php if ($pb_zugriff['admin']): ?>
<details style="margin:0 0 20px 0;padding:12px 15px;background:#f0f7ff;border:1px solid #2196f3;border-radius:8px;">
    <summary style="cursor:pointer;font-weight:600;color:#1976d2;">🛠️ Verwaltung (Import, Export, Vorschau) – nur für Verantwortliche</summary>
    <form method="POST" action="?tab=projekte" enctype="multipart/form-data" style="margin:12px 0;">
        <p style="margin:0 0 8px 0;">Projektliste aus Excel (.xlsx) oder CSV einlesen. Spalten: <strong>ID, Gruppe, Stichwort, Beschreibung</strong> (mit Kopfzeile). Vorhandene IDs werden aktualisiert, Bewertungen bleiben erhalten.</p>
        <input type="file" name="pb_datei" accept=".xlsx,.csv" required>
        <button type="submit" name="pb_import" value="1" style="padding:6px 14px;background:#2196f3;color:#fff;border:0;border-radius:4px;cursor:pointer;">Einlesen</button>
        <span style="color:#666;font-size:13px;">(derzeit <?php echo count($pb_items); ?> Projekte)</span>
    </form>
    <p style="margin:8px 0 0 0;">
        <a href="?tab=projekte&csv=1">⬇️ CSV: Voten der Teilnehmer nebeneinander (ohne Kommentare)</a> &nbsp;·&nbsp;
        <a href="?tab=projekte&ansicht=daten">Einzeldaten mit Kommentaren (Liste)</a> &nbsp;·&nbsp;
        <a href="?tab=projekte&ansicht=ergebnis">Ergebnisansicht ansehen</a> &nbsp;·&nbsp;
        <a href="?tab=projekte">Eingabemaske</a>
        &nbsp;·&nbsp; <span style="color:#666;">Runde <?php echo (int)PB_RUNDE; ?>, Eingabe bis <?php echo $pb_h(pb_stichtag_text()); ?>
        <?php echo PB_FREIGABE_ALLE ? '(für alle mit aktiv &gt; ' . (int)PB_MIN_AKTIV . ' freigeschaltet)' : '(Testphase: nur Testpersonen)'; ?></span>
    </p>
</details>
<?php endif; ?>

<?php if (!$pb_items): ?>
    <p style="color:#666;">Es sind noch keine Projekte eingelesen.</p>
<?php elseif (($_GET['ansicht'] ?? '') === 'daten' && $pb_zugriff['admin']): ?>
    <?php $pb_daten = pb_einzeldaten($pdo); ?>
    <p style="color:#555;">Alle Einzelbewertungen der Runde <?php echo (int)PB_RUNDE; ?> (<?php echo count($pb_daten); ?> Einträge), ohne Namen: Die Bewerter sind durch Kürzel (P01, P02 ...) ersetzt, dieselben wie in der CSV-Datei.</p>
    <div style="overflow-x:auto;">
    <table style="border-collapse:collapse;width:100%;background:#fff;font-size:14px;">
        <thead><tr style="background:#f0f0f0;text-align:left;">
            <th style="padding:6px;border:1px solid #ddd;">ID</th><th style="padding:6px;border:1px solid #ddd;">Gruppe</th>
            <th style="padding:6px;border:1px solid #ddd;">Stichwort</th><th style="padding:6px;border:1px solid #ddd;">Bewerter</th>
            <th style="padding:6px;border:1px solid #ddd;">Aufwand</th><th style="padding:6px;border:1px solid #ddd;">Nutzen</th>
            <th style="padding:6px;border:1px solid #ddd;">Kommentar</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pb_daten as $r): ?>
            <tr>
                <td style="padding:5px 6px;border:1px solid #ddd;"><?php echo $pb_h($r['item_id']); ?></td>
                <td style="padding:5px 6px;border:1px solid #ddd;"><?php echo $pb_h($r['gruppe']); ?></td>
                <td style="padding:5px 6px;border:1px solid #ddd;"><?php echo $pb_h($r['stichwort']); ?></td>
                <td style="padding:5px 6px;border:1px solid #ddd;"><?php echo $pb_h($r['bewerter']); ?></td>
                <td style="padding:5px 6px;border:1px solid #ddd;text-align:center;"><?php echo $pb_h($r['aufwand']); ?></td>
                <td style="padding:5px 6px;border:1px solid #ddd;text-align:center;"><?php echo $pb_h($r['nutzen']); ?></td>
                <td style="padding:5px 6px;border:1px solid #ddd;"><?php echo nl2br($pb_h($r['kommentar'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php elseif ($pb_ergebnis): ?>
    <?php $pb_erg = pb_auswertung($pdo); ?>
    <p style="color:#555;">Ergebnis der Runde <?php echo (int)PB_RUNDE; ?>: Mittelwerte der Einschätzungen (Skala 1 bis 7), sortiert nach <strong>Nutzen minus Aufwand</strong> (höchster Wert zuerst).</p>
    <div style="overflow-x:auto;">
    <table style="border-collapse:collapse;width:100%;background:#fff;">
        <thead><tr style="background:#f0f0f0;text-align:left;">
            <th style="padding:8px;border:1px solid #ddd;">ID</th><th style="padding:8px;border:1px solid #ddd;">Gruppe</th>
            <th style="padding:8px;border:1px solid #ddd;">Stichwort</th>
            <th style="padding:8px;border:1px solid #ddd;text-align:right;">Aufwand Ø</th>
            <th style="padding:8px;border:1px solid #ddd;text-align:right;">Nutzen Ø</th>
            <th style="padding:8px;border:1px solid #ddd;text-align:right;">Nutzen − Aufwand</th>
            <th style="padding:8px;border:1px solid #ddd;text-align:right;">Anzahl</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pb_erg as $r): ?>
            <tr>
                <td style="padding:6px 8px;border:1px solid #ddd;"><?php echo $pb_h($r['item_id']); ?></td>
                <td style="padding:6px 8px;border:1px solid #ddd;"><?php echo $pb_h($r['gruppe']); ?></td>
                <td style="padding:6px 8px;border:1px solid #ddd;" title="<?php echo $pb_h($r['beschreibung']); ?>"><?php echo $pb_h($r['stichwort']); ?></td>
                <td style="padding:6px 8px;border:1px solid #ddd;text-align:right;"><?php echo $r['aufwand'] === null ? '–' : number_format($r['aufwand'], 2, ',', ''); ?></td>
                <td style="padding:6px 8px;border:1px solid #ddd;text-align:right;"><?php echo $r['nutzen'] === null ? '–' : number_format($r['nutzen'], 2, ',', ''); ?></td>
                <td style="padding:6px 8px;border:1px solid #ddd;text-align:right;font-weight:600;"><?php echo $r['diff'] === null ? '–' : number_format($r['diff'], 2, ',', ''); ?></td>
                <td style="padding:6px 8px;border:1px solid #ddd;text-align:right;color:#666;"><?php echo max($r['anzahl_a'], $r['anzahl_n']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php else: ?>
    <?php
    $pb_meine = pb_meine_bewertungen($pdo, (int)$current_user['member_id']);
    $pb_gruppe = null;
    ?>
    <div style="background:#e8f4fd;border-left:4px solid #2196f3;padding:12px 15px;border-radius:4px;margin-bottom:15px;line-height:1.5;">
        <?php echo $pb_h(PB_EINFUEHRUNG); ?>
        <br><small style="color:#555;">Skala 1 bis 7: Aufwand 1 = sehr gering … 7 = sehr hoch; Nutzen 1 = sehr gering … 7 = sehr hoch. Felder, die du leer lässt, werden nicht gewertet. Du kannst deine Eingaben bis zum <?php echo $pb_h(pb_stichtag_text()); ?> beliebig oft ändern.</small>
    </div>
    <form method="POST" action="?tab=projekte">
        <?php
        $pb_opt = function ($sel) {
            $o = '<option value="">–</option>';
            for ($i = 1; $i <= 7; $i++) $o .= '<option value="' . $i . '"' . ((string)$sel === (string)$i ? ' selected' : '') . '>' . $i . '</option>';
            return $o;
        };
        ?>
        <div style="overflow-x:auto;">
        <table style="border-collapse:collapse;width:100%;background:#fff;">
            <thead><tr style="background:#f0f0f0;text-align:left;position:sticky;top:0;">
                <th style="padding:8px;border:1px solid #ddd;">ID</th>
                <th style="padding:8px;border:1px solid #ddd;">Projekt</th>
                <th style="padding:8px;border:1px solid #ddd;">Aufwand</th>
                <th style="padding:8px;border:1px solid #ddd;">Nutzen</th>
                <th style="padding:8px;border:1px solid #ddd;min-width:220px;">Kommentar</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pb_items as $it):
                $m = $pb_meine[$it['item_id']] ?? ['aufwand' => null, 'nutzen' => null, 'kommentar' => ''];
                if ($it['gruppe'] !== $pb_gruppe): $pb_gruppe = $it['gruppe']; ?>
                    <tr><td colspan="5" style="padding:8px;background:#667eea;color:#fff;font-weight:600;border:1px solid #667eea;"><?php echo $pb_h($pb_gruppe !== '' ? $pb_gruppe : 'Ohne Gruppe'); ?></td></tr>
                <?php endif; ?>
                <tr>
                    <td style="padding:6px 8px;border:1px solid #ddd;vertical-align:top;white-space:nowrap;"><?php echo $pb_h($it['item_id']); ?></td>
                    <td style="padding:6px 8px;border:1px solid #ddd;vertical-align:top;">
                        <strong><?php echo $pb_h($it['stichwort']); ?></strong>
                        <?php if ($it['beschreibung'] !== ''): ?><div style="color:#555;font-size:13px;"><?php echo nl2br($pb_h($it['beschreibung'])); ?></div><?php endif; ?>
                    </td>
                    <td style="padding:6px 8px;border:1px solid #ddd;vertical-align:top;"><select name="aufwand[<?php echo $pb_h($it['item_id']); ?>]"><?php echo $pb_opt($m['aufwand']); ?></select></td>
                    <td style="padding:6px 8px;border:1px solid #ddd;vertical-align:top;"><select name="nutzen[<?php echo $pb_h($it['item_id']); ?>]"><?php echo $pb_opt($m['nutzen']); ?></select></td>
                    <td style="padding:6px 8px;border:1px solid #ddd;vertical-align:top;"><textarea name="kommentar[<?php echo $pb_h($it['item_id']); ?>]" rows="2" style="width:100%;min-width:200px;"><?php echo $pb_h($m['kommentar']); ?></textarea></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="position:sticky;bottom:0;background:#fff;padding:12px 0;border-top:2px solid #667eea;margin-top:10px;">
            <button type="submit" name="pb_speichern" value="1" style="padding:10px 24px;background:#4caf50;color:#fff;border:0;border-radius:4px;font-weight:600;cursor:pointer;">💾 Bewertung speichern</button>
            <span style="color:#666;margin-left:10px;">Eingabe möglich bis <?php echo $pb_h(pb_stichtag_text()); ?></span>
        </div>
    </form>
<?php endif; ?>

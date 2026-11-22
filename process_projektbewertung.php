<?php
/**
 * process_projektbewertung.php - Verarbeitung (Speichern, Import, CSV-Export) - wird VOR der HTML-Ausgabe geladen
 */
require_once __DIR__ . '/projektbewertung_helper.php';
pb_tabellen_sicherstellen($pdo);
$pb_zugriff = pb_zugriff($current_user);
$pb_redirect = 'index.php?tab=projekte';

if (!$pb_zugriff['sehen']) return;

// CSV-Export der Einzeldaten (nur Testpersonen/Verantwortliche)
if (isset($_GET['csv']) && $pb_zugriff['admin']) {
    $csv = pb_csv($pdo);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="projektbewertung_runde' . PB_RUNDE . '_' . date('Ymd') . '.csv"');
    echo $csv;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

// Bewertungen speichern
if (isset($_POST['pb_speichern'])) {
    if (!pb_eingabe_offen()) {
        header('Location: ' . $pb_redirect . '&msg=pb_geschlossen');
        exit;
    }
    try {
        [$n, $d] = pb_bewertungen_speichern($pdo, (int)$current_user['member_id'], $_POST);
        header('Location: ' . $pb_redirect . '&msg=pb_gespeichert&n=' . $n);
    } catch (Throwable $e) {
        error_log('Projektbewertung speichern: ' . $e->getMessage());
        header('Location: ' . $pb_redirect . '&msg=pb_fehler');
    }
    exit;
}

// Import der Projektliste (Excel/CSV)
if (isset($_POST['pb_import']) && $pb_zugriff['admin']) {
    try {
        if (empty($_FILES['pb_datei']['tmp_name']) || $_FILES['pb_datei']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Keine Datei hochgeladen.');
        }
        $name = strtolower($_FILES['pb_datei']['name']);
        $rows = preg_match('/\.xlsx$/', $name) ? pb_xlsx_lesen($_FILES['pb_datei']['tmp_name']) : pb_csv_lesen($_FILES['pb_datei']['tmp_name']);
        $projekte = pb_zeilen_zu_projekten($rows);
        [$neu, $akt] = pb_projekte_importieren($pdo, $projekte);
        header('Location: ' . $pb_redirect . '&msg=pb_import&neu=' . $neu . '&akt=' . $akt);
    } catch (Throwable $e) {
        header('Location: ' . $pb_redirect . '&msg=pb_importfehler&t=' . urlencode($e->getMessage()));
    }
    exit;
}

<?php
/**
 * antragsnummer_helper.php - Gemeinsame Vergabe der laufenden Nummer für Anträge
 *
 * Eine Antragsnummer hat die Form <Präfix><JJMMTT><NN> (A = Entwurf, B = in Abstimmung, X = abgelehnt, Z = zurückgezogen,
 * VS = beschlossen). Beim Statuswechsel bleiben Datum und laufende Nummer erhalten (A26102501 -> B26102501). Damit eine
 * neue Nummer nie mit einer schon vergebenen kollidiert, zählt die laufende Nummer pro Tag über ALLE Präfixe.
 */
function sv_naechste_antragsnummer($pdo, $prefix = 'A', $date = '') {
    $date_part = ($date === '') ? date('ymd') : $date;
    $max = 0;

    // A/B/X/Z: <1 Zeichen><6 Ziffern Datum><2 Ziffern>
    $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(antrnr, 8, 2) AS UNSIGNED)) FROM " . TABLE_ANTRAEGE . "
                         WHERE SUBSTRING(antrnr, 1, 1) IN ('A','B','X','Z') AND SUBSTRING(antrnr, 2, 6) = ?");
    $st->execute([$date_part]);
    $max = max($max, (int)$st->fetchColumn());

    // VS: <VS><6 Ziffern Datum><2 Ziffern> (in der Anträge- und in der Beschluss-Tabelle)
    foreach ([TABLE_ANTRAEGE, TABLE_BESCHLUESSE] as $tabelle) {
        try {
            $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(antrnr, 9, 2) AS UNSIGNED)) FROM " . $tabelle . "
                                 WHERE SUBSTRING(antrnr, 1, 2) = 'VS' AND SUBSTRING(antrnr, 3, 6) = ?");
            $st->execute([$date_part]);
            $max = max($max, (int)$st->fetchColumn());
        } catch (\Throwable $e) {
            // Tabelle nicht vorhanden o. ä.: ignorieren
        }
    }

    $neu = $max + 1;
    if ($neu > 99) {
        throw new Exception("Tageslimit für Anträge erreicht (max. 99 pro Tag)");
    }
    return $prefix . $date_part . str_pad((string)$neu, 2, '0', STR_PAD_LEFT);
}

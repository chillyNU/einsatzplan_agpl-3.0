<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) { http_response_code(403); die("Nicht eingeloggt."); }
if (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer') { http_response_code(403); die("Zugriff verweigert."); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id    = $_POST['id'] ?? null;
    $datum = $_POST['datum'] ?? null;
    $uhrzeit = $_POST['uhrzeit'] ?? null;
    $tech  = $_POST['tech'] ?? null;
    // Eingaben validieren: Datum-Format und erlaubte Werte
    $datumValid   = $datum && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum);
    $uhrzeitValid = $uhrzeit && preg_match('/^\d{2}:\d{2}$/', $uhrzeit);
    // Spalte muss zwischen 1 und der für den Zieltag konfigurierten Anzahl liegen
    $techInt = (int)$tech;
    $anzahlZieltag = getMaxMitarbeiterErlaubt();
    if ($datumValid) {
        $wtag = ['Monday'=>'Montag','Tuesday'=>'Dienstag','Wednesday'=>'Mittwoch',
                 'Thursday'=>'Donnerstag','Friday'=>'Freitag','Saturday'=>'Samstag',
                 'Sunday'=>'Sonntag'][date('l', strtotime($datum))] ?? 'Montag';
        $anzahlZieltag = getAnzahlMitarbeiter($wtag);
    }
    $techValid    = ($techInt >= 1 && $techInt <= $anzahlZieltag);
    if ($id && $datumValid && $uhrzeitValid && $techValid) {
        try {
           $check = $pdo->prepare("SELECT COUNT(*) FROM dienste WHERE datum = ? AND uhrzeit = ? AND techniker_id = ? AND id != ? AND deleted_at IS NULL");
            $check->execute([$datum, $uhrzeit, $tech, $id]);
            if ($check->fetchColumn() > 0) {
                http_response_code(400);
                echo "Fehler: Zielslot bereits belegt.";
                exit;
            }
            $stmt = $pdo->prepare("UPDATE dienste SET datum = ?, uhrzeit = ?, techniker_id = ? WHERE id = ?");
            $stmt->execute([$datum, $uhrzeit, $tech, $id]);
            // Audit-Log
            try {
                $log = $pdo->prepare("INSERT INTO audit_log (dienst_id, user_id, aktion, details, created_at) VALUES (?, ?, ?, ?, " . db_now() . ")");
                $log->execute([$id, $_SESSION['user_id'], 'MOVE', "Verschoben auf $datum $uhrzeit (Tech-ID: $tech)"]);
            } catch (PDOException $e) { /* Logging-Fehler ignorieren */ }
            echo "Erfolg";
        } catch (PDOException $e) {
            http_response_code(500);
            echo "Datenbankfehler: " . $e->getMessage();
        }
    } else {
        http_response_code(400);
        echo "Fehler: Unvollständige Daten.";
    }
}
?>
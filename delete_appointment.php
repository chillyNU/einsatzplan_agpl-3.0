<?php

require 'config.php';

// Authentifizierung: Login-Check zuerst, dann Rollen-Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] === 'viewer') {
    http_response_code(403);
    die("Fehler: Du hast keine Berechtigung, Änderungen vorzunehmen.");
}

// Nur POST erlaubt (CSRF-Schutz)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Methode nicht erlaubt.");
}

csrf_verify();

$user_id   = $_SESSION['user_id'];
$kuerzel   = $_SESSION['kuerzel'] ?? '??';

$termin_id = $_POST['id'] ?? '';
$return_w  = $_POST['w'] ?? date('W');
$return_y  = $_POST['y'] ?? date('Y');
$slot      = $_POST['slot'] ?? '';

if (!empty($termin_id)) {

    try {

        // Termininfos holen
        $stmt_info = $pdo->prepare("
            SELECT
                name,
                einsatzart,
                datum,
                uhrzeit,
                techniker_id
            FROM dienste
            WHERE id = ?
        ");

        $stmt_info->execute([$termin_id]);
        $data = $stmt_info->fetch();

        if ($data) {

            // Soft Delete
            $stmt_soft_del = $pdo->prepare("
                UPDATE dienste
                SET
                    deleted_at = " . db_now() . ",
                    deleted_by = ?
                WHERE id = ?
            ");

            $stmt_soft_del->execute([$kuerzel, $termin_id]);

            // Audit Log
            try {
                $details = "GELÖSCHT (Soft): "
                    . ($data['name'] ?? '')
                    . " ("
                    . ($data['einsatzart'] ?? '')
                    . ")";

                $log_stmt = $pdo->prepare("
                    INSERT INTO audit_log (dienst_id, user_id, aktion, details, created_at)
                    VALUES (?, ?, ?, ?, " . db_now() . ")
                ");

                $log_stmt->execute([$termin_id, $user_id, 'DELETE', $details]);

            } catch (PDOException $e) {
                // Loggingfehler ignorieren
            }

            // Slot entsperren
            $clean_time = str_replace(':', '-', $data['uhrzeit']);

            $current_slot_id =
                "slot_"
                . $data['datum']
                . "_"
                . $data['techniker_id']
                . "_"
                . $clean_time;

            $stmtUnlock = $pdo->prepare("DELETE FROM active_locks WHERE slot_id = ?");
            $stmtUnlock->execute([$current_slot_id]);
        }

    } catch (PDOException $e) {
        die("Fehler beim Verschieben in den Papierkorb.");
    }
}

header(
    "Location: index.php?w="
    . urlencode($return_w)
    . "&y="
    . urlencode($return_y)
    . "&msg=deleted"
    . ($slot ? "#" . $slot : "")
);

exit;

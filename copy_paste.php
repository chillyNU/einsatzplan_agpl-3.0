<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') === 'viewer') {
    http_response_code(403);
    die('Zugriff verweigert.');
}

$user_kuerzel = $_SESSION['kuerzel'] ?? 'SYS';
$action = $_GET['action'] ?? '';
$returnW = $_GET['w'] ?? date('W');
$returnY = $_GET['y'] ?? date('Y');

/**
 * Anker für den Rücksprung: möglichst der konkrete Slot (hält die
 * Scroll-Position beim Kopieren/Einfügen), sonst der Tag, sonst nichts.
 */
function returnAnchor(): string {
    $date = $_GET['date'] ?? '';
    $time = $_GET['time'] ?? '';
    $tech = $_GET['tech'] ?? '';
    if ($date !== '' && $time !== '' && $tech !== '') {
        return '#slot_' . $date . '_' . (int)$tech . '_' . str_replace(':', '-', $time);
    }
    if ($date !== '') {
        return '#day-' . $date;
    }
    return '';
}

// CSRF-Schutz für schreibende Aktionen (paste, clear)
if (in_array($action, ['paste', 'clear'])) {
    $token = $_GET['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Ungültige Anfrage (CSRF-Fehler). Bitte die Seite neu laden.');
    }
}

if ($action === 'copy' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM dienste WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $termin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($termin) {
        $_SESSION['clipboard'] = $termin;
    }
    header("Location: index.php?w=$returnW&y=$returnY" . returnAnchor());
    exit;
}
if ($action === 'paste' && isset($_SESSION['clipboard'])) {
    $c = $_SESSION['clipboard'];
    $datum    = $_GET['date'];
    $uhrzeit  = $_GET['time'];
    $tech_id  = $_GET['tech'];
    // Prüfen ob Zielslot bereits belegt ist
    $chk = $pdo->prepare("SELECT COUNT(*) FROM dienste WHERE datum = ? AND uhrzeit = ? AND techniker_id = ? AND deleted_at IS NULL");
    $chk->execute([$datum, $uhrzeit, $tech_id]);
    if ($chk->fetchColumn() > 0) {
        $_SESSION['flash_error'] = 'Einfügen fehlgeschlagen: Zielslot ist bereits belegt.';
        header("Location: index.php?w=$returnW&y=$returnY" . returnAnchor());
        exit;
    }
    unset($c['id']);
    $c['datum']             = $datum;
    $c['uhrzeit']           = $uhrzeit;
    $c['techniker_id']      = $tech_id;
    $c['ausgefallen']       = 0;
    $c['deleted_at']        = null;
    $now_paste              = date('Y-m-d H:i:s');
    $c['created_at']        = $now_paste;
    $c['updated_at']        = $now_paste;
    $c['edit2_kuerzel']     = null;
    $c['edit2_at']          = null;
    $c['created_by_kuerzel'] = $user_kuerzel;
    $c['updated_by_kuerzel'] = $user_kuerzel;
    $spalten = array_keys($c);
    $platzhalter = array_map(function($s) { return ":$s"; }, $spalten);
    $sql = "INSERT INTO dienste (" . implode(', ', $spalten) . ") 
            VALUES (" . implode(', ', $platzhalter) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($c);
    header("Location: index.php?w=$returnW&y=$returnY#slot_" . $datum . "_" . (int)$tech_id . "_" . str_replace(':', '-', $uhrzeit));
    exit;
}
if ($action === 'clear') {
    unset($_SESSION['clipboard']);
    header("Location: index.php?w=$returnW&y=$returnY" . returnAnchor());
    exit;
}
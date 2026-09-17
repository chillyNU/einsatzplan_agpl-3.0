<?php
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Nicht eingeloggt']);
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer') {
    echo json_encode(['status' => 'error', 'message' => 'Zugriff verweigert']);
    exit;
}

$action   = $_POST['action'] ?? '';
$slot_id  = $_POST['slot_id'] ?? '';
$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Kollege';

// Lock-Lebensdauer: bewusst kurz. Solange der bearbeitende Browser per
// Heartbeat "am Leben" ist, wird der Lock laufend verlängert und der Slot
// bleibt gesperrt. Bricht der Browser weg (geschlossen/abgestürzt), läuft
// der Lock nach LOCK_TTL Sekunden ab und der Slot wird wieder frei.
const LOCK_TTL = '+30 seconds';

// Abgelaufene Sperren immer zuerst aufräumen
$pdo->exec("DELETE FROM active_locks WHERE expires_at < " . db_now());

// Hält ein ANDERER Nutzer den Slot aktuell?
function fremder_lock($pdo, $slot_id, $user_id) {
    $stmt = $pdo->prepare(
        "SELECT username FROM active_locks
         WHERE slot_id = ? AND user_id != ? AND expires_at > " . db_now()
    );
    $stmt->execute([$slot_id, $user_id]);
    return $stmt->fetch();
}

if ($action === 'lock') {
    $lock = fremder_lock($pdo, $slot_id, $user_id);
    if ($lock) {
        echo json_encode(['status' => 'locked', 'user' => $lock['username']]);
        exit;
    }
    // Eigenen Lock setzen/erneuern
    $stmt = $pdo->prepare(
        "REPLACE INTO active_locks (slot_id, user_id, username, expires_at)
         VALUES (?, ?, ?, " . db_now(LOCK_TTL) . ")"
    );
    $stmt->execute([$slot_id, $user_id, $username]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'heartbeat') {
    // Nur verlängern, wenn der Slot mir gehört. Gehört er inzwischen einem
    // anderen (z.B. weil mein Lock ablief), das melden, damit mein Browser
    // reagieren kann.
    $lock = fremder_lock($pdo, $slot_id, $user_id);
    if ($lock) {
        echo json_encode(['status' => 'locked', 'user' => $lock['username']]);
        exit;
    }
    $stmt = $pdo->prepare(
        "REPLACE INTO active_locks (slot_id, user_id, username, expires_at)
         VALUES (?, ?, ?, " . db_now(LOCK_TTL) . ")"
    );
    $stmt->execute([$slot_id, $user_id, $username]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'unlock') {
    $stmt = $pdo->prepare("DELETE FROM active_locks WHERE slot_id = ? AND user_id = ?");
    $stmt->execute([$slot_id, $user_id]);
    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Ungültige Aktion']);

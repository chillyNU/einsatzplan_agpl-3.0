<?php
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit; }

try {
    // Abgelaufene Sperren aufräumen (DB-unabhängig)
    $pdo->exec("DELETE FROM active_locks WHERE expires_at < " . db_now());

    // Aktive Sperren abrufen – inkl. user_id, damit der Client eigene von
    // fremden Sperren unterscheiden kann.
    $stmt = $pdo->query("SELECT slot_id, username, user_id FROM active_locks WHERE expires_at > " . db_now());
    $locks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($locks);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

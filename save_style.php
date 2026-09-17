<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
if (($_SESSION['role'] ?? '') === 'viewer') { http_response_code(403); exit; }
csrf_verify();

$file = __DIR__ . '/column_settings.json';

// Bestehende Daten laden
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$date   = $_POST['date'] ?? '';
$techId = $_POST['tech'] ?? '';
$dark   = $_POST['dark'] ?? '0';

if ($date && $techId) {
    // Wert setzen (true für grau, false für normal)
    $data[$date][$techId] = ($dark === '1');
    
    // In Datei schreiben
    file_put_contents($file, json_encode($data));
}
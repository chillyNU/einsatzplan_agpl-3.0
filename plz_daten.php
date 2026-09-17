<?php
// Datei: plz_daten.php
// ─────────────────────────────────────────────────────────────────────────────
// Serverseitige PLZ-/Ortssuche für die Autovervollständigung.
//
// Die Daten liegen in der Tabelle plz_verzeichnis der aktiven Datenbank
// (SQLite oder MySQL). Ist die Tabelle leer, wird sie beim ersten Aufruf
// automatisch aus der mitgelieferten Datei data/plz_daten.json befüllt
// (komplettes deutsches PLZ-Verzeichnis, Quelle: GeoNames.org, CC BY 4.0).
//
// Aufruf:   plz_daten.php?q=8907      → PLZ-Präfixsuche
//           plz_daten.php?q=Blaubeu   → Ortsnamensuche
// Antwort:  [ { "p": "89073", "o": "Ulm", "l": "BW" }, ... ]  (max. 30 Treffer)
// ─────────────────────────────────────────────────────────────────────────────

require 'config.php';

header('Content-Type: application/json; charset=UTF-8');

// Nur für angemeldete Benutzer
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

// ── Auto-Import: Tabelle beim ersten Aufruf befüllen ─────────────────────────
try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM plz_verzeichnis")->fetchColumn();
    if ($count === 0 && is_file(__DIR__ . '/data/plz_daten.json')) {
        set_time_limit(120);
        plz_import_from_json($pdo);
    }
} catch (Throwable $e) {
    error_log('PLZ-Auto-Import fehlgeschlagen: ' . $e->getMessage());
    // Suche läuft trotzdem weiter (liefert dann ggf. keine Treffer)
}

// ── Suche ────────────────────────────────────────────────────────────────────
$q = trim((string)($_GET['q'] ?? ''));

if ($q === '' || strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    if (preg_match('/^\d+$/', $q)) {
        // Reine Ziffern → PLZ-Präfixsuche
        $stmt = $pdo->prepare("
            SELECT plz AS p, ort AS o, bundesland AS l
            FROM plz_verzeichnis
            WHERE plz LIKE ?
            ORDER BY plz, ort
            LIMIT 30
        ");
        $stmt->execute([$q . '%']);
    } else {
        // Text → Ortsnamensuche (Anfang des Namens bevorzugt, dann enthält)
        $stmt = $pdo->prepare("
            SELECT plz AS p, ort AS o, bundesland AS l
            FROM plz_verzeichnis
            WHERE ort LIKE ? OR ort LIKE ?
            ORDER BY CASE WHEN ort LIKE ? THEN 0 ELSE 1 END, ort, plz
            LIMIT 30
        ");
        $like = '%' . $q . '%';
        $start = $q . '%';
        $stmt->execute([$start, $like, $start]);
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('PLZ-Suche fehlgeschlagen: ' . $e->getMessage());
    echo json_encode([]);
}

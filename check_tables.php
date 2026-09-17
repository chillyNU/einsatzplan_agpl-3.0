<?php
require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

header('Content-Type: text/html; charset=UTF-8');
if (isset($_GET['export']) && $_GET['export'] === 'json') {
    try {
        $tables = [];
        foreach (db_list_tables($pdo) as $tn) {
            $createSql = '';
            if (db_is_mysql()) {
                $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $tn) . '`')->fetch(PDO::FETCH_ASSOC);
                $createSql = $row['Create Table'] ?? '';
            } else {
                $s = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
                $s->execute([$tn]);
                $createSql = (string)$s->fetchColumn();
            }
            $tables[] = ['name' => $tn, 'sql' => $createSql];
        }

        $export = [
            'meta' => [
                'exported_at' => date('c'),
                'source' => 'check_tables.php',
                'db_file' => $dbFile ?? null,
                'php_version' => PHP_VERSION,
                'driver' => db_driver()
            ],
            'tables' => [],
            'table_data' => []
        ];

        foreach ($tables as $table) {
            $tableName = $table['name'];

            $columns = db_table_columns($pdo, $tableName);

            $countStmt = $pdo->query(
                'SELECT COUNT(*) FROM `' . str_replace('`', '', $tableName) . '`'
            );
            $count = (int)$countStmt->fetchColumn();

            $export['tables'][$tableName] = [
                'create_sql' => $table['sql'] ?? '',
                'columns' => $columns,
                'row_count' => $count
            ];
        }

        $dataTables = ['settings', 'einsatzarten', 'terminoptionen', 'technikerzuordnung', 'users'];

        foreach ($dataTables as $tableName) {
            $exists = false;
            foreach ($tables as $t) {
                if (($t['name'] ?? '') === $tableName) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                continue;
            }

            $rows = $pdo->query(
                'SELECT * FROM `' . str_replace('`', '', $tableName) . '`'
            )->fetchAll(PDO::FETCH_ASSOC);

            if ($tableName === 'users') {
                foreach ($rows as &$row) {
                    if (isset($row['passwordhash'])) {
                        $row['passwordhash'] = '__HASH_VORHANDEN__';
                    }
                }
                unset($row);
            }

            $export['table_data'][$tableName] = $rows;
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="db-structure-export-' . date('Y-m-d-His') . '.json"');

        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Export-Fehler: ' . $e->getMessage());
    }
}
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

try {
    $tables = [];
        foreach (db_list_tables($pdo) as $tn) {
            $createSql = '';
            if (db_is_mysql()) {
                $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $tn) . '`')->fetch(PDO::FETCH_ASSOC);
                $createSql = $row['Create Table'] ?? '';
            } else {
                $s = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
                $s->execute([$tn]);
                $createSql = (string)$s->fetchColumn();
            }
            $tables[] = ['name' => $tn, 'sql' => $createSql];
        }
} catch (PDOException $e) {
    die('Fehler beim Laden der Tabellen: ' . h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>DB-Check / Tabellenstruktur</title>
    <link rel="stylesheet" href="theme.css?v=2.2">
    <style>
        body { padding: 24px; }
        .wrap { max-width: 1200px; margin: 0 auto; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 22px 24px; margin-bottom: 24px; box-shadow: var(--shadow-1); }
        h1, h2, h3 { margin-top: 0; }
        h2 { color: var(--ink); border-bottom: 2px solid var(--line); padding-bottom: 8px; margin-bottom: 16px; }
        table { width: 100%; margin-top: 12px; font-size: 0.95rem; background: var(--surface); }
        th, td { border: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; }
        th { background: var(--surface-alt); }
    </style>
</head>
<body>
<div class="wrap">
    <a class="toplink" href="admin.php">&larr; Zurück zur Administration</a>
    <div class="card">
        <h1>Datenbank-Check</h1>
        <p class="muted">
            Diese Seite zeigt die aktuelle Live-Struktur der aktiven Datenbank (SQLite oder MySQL),
            inklusive Tabellen, Spalten, Defaults und – falls vorhanden – die Settings.
        </p>
    </div>
<p style="margin-top:15px;">
    <a href="check_tables.php?export=json"
       style="display:inline-block;background:#0056b3;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;font-weight:bold;">
        Export für Initialisierer herunterladen
    </a>
</p>
    <?php foreach ($tables as $table): ?>
        <?php
        $tableName = $table['name'];

        try {
            $columns = db_table_columns($pdo, $tableName);
        } catch (PDOException $e) {
            $columns = [];
        }
        ?>
        <div class="card">
            <h2>Tabelle: <?php echo h($tableName); ?></h2>

            <h3>CREATE-Statement</h3>
            <pre><?php echo h($table['sql'] ?? ''); ?></pre>

            <h3>Spalten</h3>
            <?php if ($columns): ?>
                <table>
                    <thead>
                        <tr>
                            <th>cid</th>
                            <th>Name</th>
                            <th>Typ</th>
                            <th>NOT NULL</th>
                            <th>Default</th>
                            <th>PK</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($columns as $col): ?>
                            <tr>
                                <td><?php echo h($col['cid'] ?? ''); ?></td>
                                <td><code><?php echo h($col['name'] ?? ''); ?></code></td>
                                <td><?php echo h($col['type'] ?? ''); ?></td>
                                <td><?php echo !empty($col['notnull']) ? '1' : '0'; ?></td>
                                <td><?php echo h($col['dflt_value'] ?? ''); ?></td>
                                <td><?php echo !empty($col['pk']) ? '1' : '0'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Keine Spalteninformationen gefunden.</p>
            <?php endif; ?>

            <?php
            try {
                $countStmt = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $tableName) . '`');
                $count = $countStmt->fetchColumn();
            } catch (PDOException $e) {
                $count = 'Fehler: ' . $e->getMessage();
            }
            ?>
            <p><strong>Datensätze:</strong> <?php echo h($count); ?></p>
        </div>
    <?php endforeach; ?>

    <?php
    $hasSettings = false;
    foreach ($tables as $t) {
        if (($t['name'] ?? '') === 'settings') {
            $hasSettings = true;
            break;
        }
    }
    ?>

    <?php if ($hasSettings): ?>
        <div class="card">
            <h2>Inhalt der settings-Tabelle</h2>
            <?php
            try {
                $settings = $pdo->query('SELECT `key`, `value` FROM settings ORDER BY `key` ASC')->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $settings = [];
                echo '<p>Fehler beim Lesen der settings: ' . h($e->getMessage()) . '</p>';
            }
            ?>

            <?php if (!empty($settings)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>key</th>
                            <th>value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings as $row): ?>
                            <tr>
                                <td><code><?php echo h($row['key'] ?? ''); ?></code></td>
                                <td><?php echo h($row['value'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Keine Settings vorhanden.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
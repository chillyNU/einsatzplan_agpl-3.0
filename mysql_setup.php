<?php
// Datei: mysql_setup.php
// ─────────────────────────────────────────────────────────────────────────────
// Admin-Seite: MySQL-Zugangsdaten eingeben, Verbindung testen und die
// komplette Anwendung vollautomatisch von SQLite auf MySQL migrieren.
//
// Ablauf der Migration:
//   1. Verbindung zum MySQL-Server testen (mit verständlichen Fehlerhilfen)
//   2. Datenbank anlegen, falls gewünscht und nicht vorhanden
//   3. Live-Struktur der SQLite-Datenbank auslesen (alle Tabellen & Spalten,
//      auch solche, die erst später im Projektverlauf dazugekommen sind)
//   4. Struktur nach MySQL übersetzen und Tabellen anlegen
//   5. Sämtliche Daten in Paketen kopieren
//   6. Zeilenzahlen vergleichen (Verifikation)
//   7. data/db_config.php schreiben → App läuft ab sofort auf MySQL
//
// Die SQLite-Datei bleibt unangetastet als Sicherung erhalten.
// Über "Zurück zu SQLite" kann jederzeit wieder umgeschaltet werden.
// ─────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

set_time_limit(600);

$sqliteFile = __DIR__ . '/data/einsatzplan.sqlite';
$configFile = __DIR__ . '/data/db_config.php';

$currentCfg    = db_load_config();
$currentDriver = $currentCfg['driver'];
$savedMysql    = $currentCfg['mysql'] ?? [];

$log      = [];   // [ ['ok'|'warn'|'err'|'info', 'Text'], ... ]
$fatal    = false;
$didWork  = false;

function logline(array &$log, string $level, string $text): void {
    $log[] = [$level, $text];
}

/**
 * Übersetzt typische MySQL-Verbindungsfehler in verständliche deutsche
 * Hinweise mit konkreter Hilfestellung.
 */
function mysql_error_hint(PDOException $e): string {
    $msg  = $e->getMessage();
    $code = (int)$e->getCode();

    if (stripos($msg, 'getaddrinfo') !== false || stripos($msg, 'Name or service not known') !== false
        || stripos($msg, 'No such host') !== false || $code === 2002 && stripos($msg, 'php_network') !== false) {
        return "Der Hostname wurde nicht gefunden. Prüfen Sie das Feld <b>DB-Host</b> auf Tippfehler. "
             . "Bei Webhostern steht der korrekte Host meist im Kundenmenü (z.B. „localhost“, „127.0.0.1“ oder „db12345.hosting.de“).";
    }
    if (stripos($msg, 'Connection refused') !== false || stripos($msg, 'Verbindungsaufbau abgelehnt') !== false) {
        return "Der Server lehnt die Verbindung ab. Läuft der MySQL-Server? Stimmt der <b>Port</b> (Standard: 3306)? "
             . "Bei lokalen Installationen (XAMPP/MAMP) muss MySQL gestartet sein.";
    }
    if (stripos($msg, 'timed out') !== false || stripos($msg, 'Zeitüberschreitung') !== false) {
        return "Zeitüberschreitung beim Verbindungsaufbau. Häufige Ursache: Eine Firewall blockiert den Port, "
             . "oder der MySQL-Server erlaubt keine externen Verbindungen. Viele Hoster erlauben Zugriff nur vom Webserver selbst – "
             . "dann als Host „localhost“ verwenden.";
    }
    if (stripos($msg, 'Access denied') !== false) {
        return "Zugriff verweigert – <b>Benutzername oder Passwort</b> ist falsch, oder der Benutzer hat keine Rechte "
             . "für diese Datenbank / diesen Host. Prüfen Sie die Zugangsdaten im Kundenmenü Ihres Hosters. "
             . "Bei eigenem Server: <code>GRANT ALL ON dbname.* TO 'benutzer'@'%';</code>";
    }
    if (stripos($msg, 'Unknown database') !== false) {
        return "Die angegebene <b>Datenbank existiert noch nicht</b>. Aktivieren Sie unten die Option "
             . "„Datenbank automatisch anlegen“ (der Benutzer braucht dafür CREATE-Rechte) oder legen Sie die "
             . "Datenbank vorher im Kundenmenü / phpMyAdmin an.";
    }
    if (stripos($msg, 'could not find driver') !== false) {
        return "Auf diesem Webserver fehlt die PHP-Erweiterung <b>pdo_mysql</b>. "
             . "Bitte in der php.ini aktivieren (<code>extension=pdo_mysql</code>) bzw. beim Hoster aktivieren lassen.";
    }
    return "Unerwarteter Fehler: " . htmlspecialchars($msg) . " – Prüfen Sie Host, Port, Datenbankname, Benutzer und Passwort.";
}

/** Werte für DATETIME-/DATE-Spalten säubern (leere Strings → NULL usw.) */
function sanitize_value($value, string $mysqlType) {
    if ($value === null) return null;
    $t = strtoupper($mysqlType);
    if ($t === 'DATETIME' || $t === 'DATE' || $t === 'TIME') {
        $v = trim((string)$value);
        if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return null;
    }
    if (strpos($t, 'INT') !== false || $t === 'DOUBLE' || strpos($t, 'DECIMAL') === 0) {
        if ($value === '') return null;
    }
    return $value;
}

// ─── POST-Verarbeitung ───────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';
$form = [
    'host'    => trim($_POST['db_host'] ?? ($savedMysql['host'] ?? 'localhost')),
    'port'    => trim($_POST['db_port'] ?? (string)($savedMysql['port'] ?? '3306')),
    'dbname'  => trim($_POST['db_name'] ?? ($savedMysql['dbname'] ?? '')),
    'user'    => trim($_POST['db_user'] ?? ($savedMysql['user'] ?? '')),
    'pass'    => $_POST['db_pass'] ?? ($savedMysql['pass'] ?? ''),
];
$optCreateDb   = isset($_POST['opt_create_db']) || $action === '';
$optDropTables = isset($_POST['opt_drop_tables']);

if ($action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $didWork = true;
}

// ── Aktion: Zurück zu SQLite wechseln ────────────────────────────────────────
if ($action === 'switch_sqlite') {
    $newCfg = "<?php\nreturn ['driver' => 'sqlite', 'mysql' => " . var_export($savedMysql, true) . "];\n";
    if (@file_put_contents($configFile, $newCfg) !== false) {
        logline($log, 'ok', "Umgeschaltet: Die Anwendung läuft jetzt wieder auf <b>SQLite</b>. Die MySQL-Zugangsdaten bleiben gespeichert.");
        $currentDriver = 'sqlite';
    } else {
        logline($log, 'err', "Konnte data/db_config.php nicht schreiben. Bitte Dateirechte des Ordners <code>data/</code> prüfen (Webserver braucht Schreibrechte).");
    }
}

// ── Aktion: Wieder zu MySQL wechseln (ohne neue Migration) ──────────────────
if ($action === 'switch_mysql') {
    try {
        $testPdo = db_connect_mysql($savedMysql);
        $newCfg = "<?php\nreturn ['driver' => 'mysql', 'mysql' => " . var_export($savedMysql, true) . "];\n";
        if (@file_put_contents($configFile, $newCfg) !== false) {
            logline($log, 'ok', "Umgeschaltet: Die Anwendung läuft jetzt wieder auf <b>MySQL</b> (ohne erneute Datenübernahme).");
            $currentDriver = 'mysql';
        } else {
            logline($log, 'err', "Konnte data/db_config.php nicht schreiben. Bitte Dateirechte prüfen.");
        }
    } catch (PDOException $e) {
        logline($log, 'err', "MySQL nicht erreichbar – Umschalten abgebrochen.<br><b>Hilfe:</b> " . mysql_error_hint($e));
    }
}

// ── Aktion: Verbindung testen ────────────────────────────────────────────────
if ($action === 'test' || $action === 'migrate') {

    if ($form['dbname'] === '' || $form['user'] === '') {
        logline($log, 'err', "Bitte mindestens <b>Datenbankname</b> und <b>Benutzer</b> ausfüllen.");
        $fatal = true;
    }
    if (!$fatal && !extension_loaded('pdo_mysql')) {
        logline($log, 'err', "Die PHP-Erweiterung <b>pdo_mysql</b> ist auf diesem Server nicht installiert/aktiviert. "
            . "Ohne sie kann PHP nicht mit MySQL sprechen. Bitte in der php.ini aktivieren oder den Hoster kontaktieren.");
        $fatal = true;
    }

    $mysqlPdo = null;

    if (!$fatal) {
        // 1) Verbindung zum Server (erst ohne Datenbank, um sie ggf. anlegen zu können)
        try {
            $dsnServer = "mysql:host={$form['host']};port=" . (int)$form['port'] . ";charset=utf8mb4";
            $serverPdo = new PDO($dsnServer, $form['user'], $form['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 8,
            ]);
            $version = $serverPdo->query('SELECT VERSION()')->fetchColumn();
            logline($log, 'ok', "Verbindung zum MySQL-Server hergestellt (Server-Version: " . htmlspecialchars($version) . ").");

            // 2) Existiert die Datenbank?
            $stmt = $serverPdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$form['dbname']]);
            $dbExists = (bool)$stmt->fetchColumn();

            if (!$dbExists) {
                if ($optCreateDb) {
                    try {
                        $dbSafe = str_replace('`', '', $form['dbname']);
                        $serverPdo->exec("CREATE DATABASE `{$dbSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        logline($log, 'ok', "Datenbank <b>" . htmlspecialchars($form['dbname']) . "</b> wurde automatisch angelegt (utf8mb4).");
                        $dbExists = true;
                    } catch (PDOException $e) {
                        logline($log, 'err', "Datenbank konnte nicht angelegt werden.<br><b>Hilfe:</b> Der Benutzer hat vermutlich keine CREATE-Rechte. "
                            . "Bei den meisten Webhostern legt man Datenbanken im Kundenmenü an – danach hier den Namen eintragen. "
                            . "<br><i>Originalmeldung: " . htmlspecialchars($e->getMessage()) . "</i>");
                        $fatal = true;
                    }
                } else {
                    logline($log, 'err', "Die Datenbank <b>" . htmlspecialchars($form['dbname']) . "</b> existiert nicht. "
                        . "Option „Datenbank automatisch anlegen“ aktivieren oder Datenbank beim Hoster anlegen.");
                    $fatal = true;
                }
            }

            // 3) Verbindung direkt zur Zieldatenbank
            if (!$fatal) {
                $mysqlPdo = db_connect_mysql([
                    'host' => $form['host'], 'port' => (int)$form['port'],
                    'dbname' => $form['dbname'], 'user' => $form['user'],
                    'pass' => $form['pass'], 'charset' => 'utf8mb4',
                ]);
                logline($log, 'ok', "Verbindung zur Datenbank <b>" . htmlspecialchars($form['dbname']) . "</b> erfolgreich.");

                // Schreibtest
                try {
                    $mysqlPdo->exec("CREATE TABLE IF NOT EXISTS `_einsatzplan_schreibtest` (id INT) ENGINE=InnoDB");
                    $mysqlPdo->exec("DROP TABLE `_einsatzplan_schreibtest`");
                    logline($log, 'ok', "Schreibtest erfolgreich – der Benutzer darf Tabellen anlegen.");
                } catch (PDOException $e) {
                    logline($log, 'err', "Der Benutzer darf in dieser Datenbank <b>keine Tabellen anlegen</b>. "
                        . "Es werden CREATE/INSERT/UPDATE/DELETE/DROP-Rechte benötigt."
                        . "<br><i>Originalmeldung: " . htmlspecialchars($e->getMessage()) . "</i>");
                    $fatal = true;
                }
            }
        } catch (PDOException $e) {
            logline($log, 'err', "Verbindung fehlgeschlagen.<br><b>Hilfe:</b> " . mysql_error_hint($e));
            $fatal = true;
        }
    }

    if ($action === 'test' && !$fatal) {
        logline($log, 'ok', "<b>Alles bereit!</b> Sie können die Migration jetzt starten.");
    }

    // ── Aktion: Migration ausführen ─────────────────────────────────────────
    if ($action === 'migrate' && !$fatal && $mysqlPdo instanceof PDO) {
        try {
            // Quelle: immer die SQLite-Datei, unabhängig vom aktiven Treiber
            if (!is_file($sqliteFile)) {
                throw new RuntimeException("SQLite-Datei nicht gefunden: data/einsatzplan.sqlite");
            }
            $srcPdo = new PDO('sqlite:' . $sqliteFile);
            $srcPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $srcPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Live-Struktur ermitteln (Ist-Stand zum Zeitpunkt der Migration)
            $tables = $srcPdo->query("
                SELECT name, sql FROM sqlite_master
                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                ORDER BY name
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tables)) {
                throw new RuntimeException("Keine Tabellen in der SQLite-Datenbank gefunden.");
            }
            logline($log, 'info', count($tables) . " Tabellen in der SQLite-Datenbank gefunden. Struktur wird übernommen …");

            $summary = [];

            foreach ($tables as $t) {
                $tableName = $t['name'];
                $tableSafe = str_replace('`', '', $tableName);
                $quoted    = str_replace('"', '""', $tableName);

                $columns = $srcPdo->query('PRAGMA table_info("' . $quoted . '")')->fetchAll(PDO::FETCH_ASSOC);
                if (empty($columns)) {
                    logline($log, 'warn', "Tabelle <b>" . htmlspecialchars($tableName) . "</b> hat keine Spalten – übersprungen.");
                    continue;
                }

                // Zieltabelle ggf. löschen / prüfen
                if (db_table_exists_mysql($mysqlPdo, $tableName)) {
                    if ($optDropTables) {
                        $mysqlPdo->exec("DROP TABLE `{$tableSafe}`");
                        logline($log, 'warn', "Bestehende MySQL-Tabelle <b>" . htmlspecialchars($tableName) . "</b> wurde gelöscht und wird neu angelegt.");
                    } else {
                        logline($log, 'err', "Tabelle <b>" . htmlspecialchars($tableName) . "</b> existiert bereits in MySQL. "
                            . "Aktivieren Sie „Bestehende Tabellen in MySQL überschreiben“, um sie zu ersetzen. Migration abgebrochen – es wurde nichts umgeschaltet.");
                        $fatal = true;
                        break;
                    }
                }

                // Tabelle in MySQL anlegen
                $createSql = db_build_mysql_create($tableName, $columns, (string)($t['sql'] ?? ''));
                $mysqlPdo->exec($createSql);

                // MySQL-Zieltypen je Spalte merken (für Daten-Säuberung)
                $colTypes = [];
                $colNames = [];
                $pkCols   = [];
                foreach ($columns as $c) { if (!empty($c['pk'])) $pkCols[] = $c['name']; }
                $singleIntPk = (count($pkCols) === 1);
                foreach ($columns as $c) {
                    $isPk = !empty($c['pk']);
                    $isAutoInc = $isPk && $singleIntPk && stripos((string)$c['type'], 'INT') !== false;
                    $colTypes[$c['name']] = db_translate_type((string)$c['type'], $isPk, $isAutoInc);
                    $colNames[] = $c['name'];
                }

                // Daten in Paketen kopieren
                $total = (int)$srcPdo->query('SELECT COUNT(*) FROM "' . $quoted . '"')->fetchColumn();
                $copied = 0;
                $batchSize = 200;

                if ($total > 0) {
                    $colList      = implode(', ', array_map(fn($c) => '`' . str_replace('`', '', $c) . '`', $colNames));
                    $placeholders = '(' . implode(', ', array_fill(0, count($colNames), '?')) . ')';

                    $select = $srcPdo->query('SELECT * FROM "' . $quoted . '"');
                    $batch = [];
                    $mysqlPdo->beginTransaction();
                    try {
                        while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                            $vals = [];
                            foreach ($colNames as $cn) {
                                $vals[] = sanitize_value($row[$cn] ?? null, $colTypes[$cn]);
                            }
                            $batch[] = $vals;

                            if (count($batch) >= $batchSize) {
                                insert_batch($mysqlPdo, $tableSafe, $colList, $placeholders, $batch);
                                $copied += count($batch);
                                $batch = [];
                            }
                        }
                        if ($batch) {
                            insert_batch($mysqlPdo, $tableSafe, $colList, $placeholders, $batch);
                            $copied += count($batch);
                        }
                        $mysqlPdo->commit();
                    } catch (Throwable $e) {
                        if ($mysqlPdo->inTransaction()) $mysqlPdo->rollBack();
                        throw new RuntimeException("Fehler beim Kopieren der Tabelle „{$tableName}“: " . $e->getMessage());
                    }
                }

                // Verifikation: Zeilenzahlen vergleichen
                $targetCount = (int)$mysqlPdo->query("SELECT COUNT(*) FROM `{$tableSafe}`")->fetchColumn();
                if ($targetCount === $total) {
                    logline($log, 'ok', "Tabelle <b>" . htmlspecialchars($tableName) . "</b>: Struktur angelegt, {$targetCount} von {$total} Datensätzen übernommen. ✓");
                } else {
                    logline($log, 'err', "Tabelle <b>" . htmlspecialchars($tableName) . "</b>: Zeilenzahl weicht ab (Quelle: {$total}, Ziel: {$targetCount}). Migration abgebrochen.");
                    $fatal = true;
                    break;
                }
                $summary[$tableName] = $total;
            }

            // Umschalten auf MySQL
            if (!$fatal) {
                $mysqlCfg = [
                    'host' => $form['host'], 'port' => (int)$form['port'],
                    'dbname' => $form['dbname'], 'user' => $form['user'],
                    'pass' => $form['pass'], 'charset' => 'utf8mb4',
                ];
                $newCfg = "<?php\nreturn ['driver' => 'mysql', 'mysql' => " . var_export($mysqlCfg, true) . "];\n";

                if (@file_put_contents($configFile, $newCfg) === false) {
                    logline($log, 'err', "Die Daten wurden kopiert, aber <code>data/db_config.php</code> konnte nicht geschrieben werden. "
                        . "Bitte dem Webserver Schreibrechte auf den Ordner <code>data/</code> geben und erneut migrieren "
                        . "(oder nur „Wieder zu MySQL wechseln“ nutzen).");
                } else {
                    $totalRows = array_sum($summary);
                    logline($log, 'ok', "<b>Migration abgeschlossen!</b> " . count($summary) . " Tabellen mit insgesamt {$totalRows} Datensätzen übernommen. "
                        . "Die Anwendung läuft ab sofort auf <b>MySQL</b>. Die bisherige SQLite-Datei bleibt als Sicherung unter "
                        . "<code>data/einsatzplan.sqlite</code> erhalten.");
                    $currentDriver = 'mysql';
                    $savedMysql = $mysqlCfg;
                }
            }
        } catch (Throwable $e) {
            logline($log, 'err', "Migration abgebrochen: " . htmlspecialchars($e->getMessage())
                . "<br><b>Hinweis:</b> Es wurde <u>nicht</u> auf MySQL umgeschaltet – die Anwendung läuft unverändert weiter. "
                . "Fehler beheben und Migration erneut starten (ggf. mit Option „Bestehende Tabellen überschreiben“).");
            $fatal = true;
        }
    }
}

/** Multi-Row-INSERT für ein Datenpaket */
function insert_batch(PDO $pdo, string $tableSafe, string $colList, string $placeholders, array $batch): void {
    $sql = "INSERT INTO `{$tableSafe}` ({$colList}) VALUES "
         . implode(', ', array_fill(0, count($batch), $placeholders));
    $params = [];
    foreach ($batch as $row) foreach ($row as $v) $params[] = $v;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/** Tabellen-Existenz gezielt in der MySQL-Zielverbindung prüfen */
function db_table_exists_mysql(PDO $mysqlPdo, string $table): bool {
    $stmt = $mysqlPdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                                WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

// SQLite-Statistik für die Anzeige
$sqliteStats = null;
try {
    if (is_file($sqliteFile)) {
        $sPdo = new PDO('sqlite:' . $sqliteFile);
        $sPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tbls = $sPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $sqliteStats = [];
        foreach ($tbls as $tn) {
            $q = str_replace('"', '""', $tn);
            $sqliteStats[$tn] = (int)$sPdo->query('SELECT COUNT(*) FROM "' . $q . '"')->fetchColumn();
        }
        $sPdo = null;
    }
} catch (Throwable $e) { /* Anzeige ist optional */ }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datenbank-Konfiguration (MySQL-Migration)</title>
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <style>
        body { padding: 24px; }
        .container { max-width: 900px; margin: auto; }
        .card { background: var(--surface, #fff); border: 1px solid var(--line, #ddd); padding: 22px 24px; border-radius: var(--radius, 10px); box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,.08)); margin-bottom: 25px; }
        h1 { margin-top: 0; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input[type=text], input[type=password], input[type=number] {
            width: 100%; max-width: 420px; padding: 8px 10px; border: 1px solid var(--line, #ccc);
            border-radius: 6px; font-size: 15px; box-sizing: border-box;
        }
        .hint { color: #666; font-size: 13px; margin-top: 3px; }
        .btnrow { margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; }
        button { padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 600; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-danger  { background: #dc2626; color: #fff; }
        .btn-neutral { background: #e5e7eb; color: #111; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; }
        .badge-sqlite { background: #fef3c7; color: #92400e; }
        .badge-mysql  { background: #dcfce7; color: #166534; }
        .log { margin-top: 6px; }
        .log-item { padding: 10px 12px; border-radius: 6px; margin-bottom: 8px; font-size: 14px; line-height: 1.5; }
        .log-ok   { background: #dcfce7; border: 1px solid #86efac; }
        .log-warn { background: #fef9c3; border: 1px solid #fde047; }
        .log-err  { background: #fee2e2; border: 1px solid #fca5a5; }
        .log-info { background: #e0f2fe; border: 1px solid #7dd3fc; }
        table.stats { border-collapse: collapse; font-size: 14px; }
        table.stats td, table.stats th { border: 1px solid var(--line, #ddd); padding: 5px 12px; text-align: left; }
        .checkline { margin: 10px 0; }
        .checkline label { display: inline; font-weight: normal; }
        code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; }
        .top-nav { margin-bottom: 18px; }
        .nav-pill { display: inline-block; padding: 6px 14px; border: 1px solid var(--line, #ccc); border-radius: 999px; text-decoration: none; color: inherit; margin-right: 6px; background: var(--surface, #fff); }
    </style>
</head>
<body>
<div class="container">

    <div class="top-nav">
        <a href="admin.php" class="nav-pill">← Zurück zur Verwaltung</a>
        <a href="datenbank.php" class="nav-pill">Datenbank-Manager</a>
    </div>

    <div class="card">
        <h1>🗄️ Datenbank-Konfiguration</h1>
        <p>
            Aktive Datenbank:
            <?php if ($currentDriver === 'mysql'): ?>
                <span class="badge badge-mysql">MySQL / MariaDB</span>
                <span class="hint" style="display:inline;">(<?= htmlspecialchars(($savedMysql['user'] ?? '') . '@' . ($savedMysql['host'] ?? '') . ' / ' . ($savedMysql['dbname'] ?? '')) ?>)</span>
            <?php else: ?>
                <span class="badge badge-sqlite">SQLite (Standard)</span>
                <span class="hint" style="display:inline;">(data/einsatzplan.sqlite)</span>
            <?php endif; ?>
        </p>
        <p class="hint">
            Hier kann die Anwendung von der eingebauten SQLite-Datenbank auf eine eigene
            MySQL-/MariaDB-Datenbank umgestellt werden. Die Migration übernimmt automatisch die
            <b>komplette aktuelle Struktur und alle Daten</b> (Benutzer, Administratoren, Einsätze,
            Mitarbeiter, Einsatzarten, Zusatzoptionen, Einstellungen usw.).
            Die SQLite-Datei bleibt danach als Sicherung erhalten.
        </p>
    </div>

    <?php if (!empty($log)): ?>
    <div class="card">
        <h2 style="margin-top:0;">Ergebnis</h2>
        <div class="log">
            <?php foreach ($log as [$level, $text]): ?>
                <div class="log-item log-<?= $level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : ($level === 'err' ? 'err' : 'info')) ?>">
                    <?= ($level === 'ok' ? '✅ ' : ($level === 'warn' ? '⚠️ ' : ($level === 'err' ? '❌ ' : 'ℹ️ '))) . $text ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;">MySQL-Zugangsdaten</h2>
        <p class="hint">Diese Daten erhalten Sie von Ihrem Hoster bzw. haben Sie beim Anlegen der Datenbank selbst festgelegt.</p>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>

            <label for="db_host">DB_HOST – Server / Host</label>
            <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($form['host']) ?>" placeholder="localhost">
            <div class="hint">Meist <code>localhost</code> oder <code>127.0.0.1</code>; bei Webhostern z.B. <code>db512345.hosting-data.io</code></div>

            <label for="db_port">DB_PORT – Port</label>
            <input type="number" id="db_port" name="db_port" value="<?= htmlspecialchars($form['port']) ?>" placeholder="3306" style="max-width:140px;">
            <div class="hint">Standard: 3306</div>

            <label for="db_name">DB_NAME – Datenbankname</label>
            <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($form['dbname']) ?>" placeholder="einsatzplan">

            <label for="db_user">DB_USER – Benutzername</label>
            <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($form['user']) ?>" placeholder="einsatzplan_user">

            <label for="db_pass">DB_PASS – Passwort</label>
            <input type="password" id="db_pass" name="db_pass" value="<?= htmlspecialchars($form['pass']) ?>">

            <div class="checkline" style="margin-top:16px;">
                <input type="checkbox" id="opt_create_db" name="opt_create_db" <?= $optCreateDb ? 'checked' : '' ?>>
                <label for="opt_create_db">Datenbank automatisch anlegen, falls sie noch nicht existiert (Benutzer braucht CREATE-Rechte)</label>
            </div>
            <div class="checkline">
                <input type="checkbox" id="opt_drop_tables" name="opt_drop_tables" <?= $optDropTables ? 'checked' : '' ?>>
                <label for="opt_drop_tables">Bestehende gleichnamige Tabellen in MySQL überschreiben (⚠️ vorhandene MySQL-Daten gehen verloren)</label>
            </div>

            <div class="btnrow">
                <button type="submit" name="action" value="test" class="btn-neutral">🔌 Verbindung testen</button>
                <button type="submit" name="action" value="migrate" class="btn-primary"
                        data-confirm="Die <b>komplette Struktur und alle Daten</b> der SQLite-Datenbank werden nach MySQL übernommen. Danach läuft die Anwendung auf MySQL.<br><br>Die SQLite-Datei bleibt als Sicherung erhalten."
                        data-confirm-title="Migration jetzt starten?" data-confirm-type="warning" data-confirm-ok="Migration starten">
                    🚀 Migration starten
                </button>
            </div>
        </form>
    </div>

    <?php if ($sqliteStats !== null): ?>
    <div class="card">
        <h2 style="margin-top:0;">Quelldaten (SQLite, Ist-Stand)</h2>
        <p class="hint">Diese Tabellen und Datenmengen werden bei einer Migration übernommen – ermittelt live zum jetzigen Zeitpunkt:</p>
        <table class="stats">
            <tr><th>Tabelle</th><th>Datensätze</th></tr>
            <?php foreach ($sqliteStats as $tn => $cnt): ?>
                <tr><td><?= htmlspecialchars($tn) ?></td><td><?= $cnt ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;">Umschalten ohne neue Migration</h2>
        <p class="hint">
            Hiermit wird nur die aktive Datenbank gewechselt – es werden <b>keine Daten kopiert</b>.
            Achtung: Änderungen, die nach der Migration in der einen Datenbank gemacht wurden,
            sind in der anderen nicht vorhanden.
        </p>
        <form method="post" class="btnrow">
            <?= csrf_field() ?>
            <?php if ($currentDriver === 'mysql'): ?>
                <button type="submit" name="action" value="switch_sqlite" class="btn-danger"
                        data-confirm="Wirklich zurück zu <b>SQLite</b> wechseln?<br><br>Es werden keine Daten kopiert – Änderungen aus der MySQL-Zeit sind in SQLite nicht enthalten."
                        data-confirm-title="Zu SQLite wechseln" data-confirm-type="warning" data-confirm-ok="Ja, wechseln">↩️ Zurück zu SQLite wechseln</button>
            <?php elseif (!empty($savedMysql['dbname'])): ?>
                <button type="submit" name="action" value="switch_mysql" class="btn-neutral"
                        data-confirm="Wieder zur gespeicherten <b>MySQL-Datenbank</b> wechseln?<br><br>Es findet keine erneute Datenübernahme statt."
                        data-confirm-title="Zu MySQL wechseln" data-confirm-type="warning" data-confirm-ok="Ja, wechseln">↪️ Wieder zu MySQL wechseln</button>
            <?php else: ?>
                <span class="hint">Noch keine MySQL-Konfiguration gespeichert.</span>
            <?php endif; ?>
        </form>
    </div>

</div>
<script src="<?= asset('modal.js') ?>"></script>
</body>
</html>

<?php
// Datei: db_helpers.php
// ─────────────────────────────────────────────────────────────────────────────
// Zentrale Datenbank-Abstraktionsschicht.
// Die Anwendung kann wahlweise auf SQLite (Standard) oder MySQL/MariaDB laufen.
// Welcher Treiber aktiv ist, steht in data/db_config.php (wird von der
// Migrationsseite mysql_setup.php geschrieben). Fehlt die Datei, gilt SQLite.
//
// Alle Funktionen hier liefern SQL-Fragmente, die zum jeweils aktiven
// Treiber passen, damit der restliche Code treiber-neutral bleibt.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Lädt die DB-Konfiguration aus data/db_config.php.
 * Rückgabe: ['driver' => 'sqlite'|'mysql', 'mysql' => [host, port, dbname, user, pass, charset]]
 */
function db_load_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $default = ['driver' => 'sqlite', 'mysql' => []];
    $file = __DIR__ . '/data/db_config.php';

    if (is_file($file)) {
        $loaded = include $file;
        if (is_array($loaded) && in_array(($loaded['driver'] ?? ''), ['sqlite', 'mysql'], true)) {
            $cfg = array_merge($default, $loaded);
            return $cfg;
        }
    }
    $cfg = $default;
    return $cfg;
}

/** Aktiver Treiber: 'sqlite' oder 'mysql' */
function db_driver(): string {
    return db_load_config()['driver'];
}

/** true, wenn die App aktuell auf MySQL läuft */
function db_is_mysql(): bool {
    return db_driver() === 'mysql';
}

/**
 * Baut eine PDO-Verbindung zu MySQL aus einem Konfig-Array auf.
 * Wirft PDOException bei Fehlern (wird vom Aufrufer behandelt).
 */
function db_connect_mysql(array $m): PDO {
    $host    = $m['host']    ?? 'localhost';
    $port    = (int)($m['port'] ?? 3306);
    $dbname  = $m['dbname']  ?? '';
    $charset = $m['charset'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $m['user'] ?? '', $m['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Einheitliches, striktes aber praxistaugliches SQL-Verhalten
    $pdo->exec("SET NAMES {$charset}");
    return $pdo;
}

// ─── SQL-Fragmente (treiberabhängig) ─────────────────────────────────────────

/**
 * Aktueller Zeitstempel als SQL-Ausdruck, optional mit Offset.
 * db_now()              → datetime('now')            bzw. NOW()
 * db_now('+5 minutes')  → datetime('now','+5 minutes') bzw. DATE_ADD(NOW(), INTERVAL 5 MINUTE)
 */
function db_now(string $modifier = ''): string {
    if (!db_is_mysql()) {
        return $modifier === ''
            ? "datetime('now')"
            : "datetime('now', '" . $modifier . "')";
    }
    if ($modifier === '') return 'NOW()';

    // '+5 minutes' / '-2 hours' / '+1 day' → DATE_ADD/DATE_SUB
    if (preg_match('/^([+-])\s*(\d+)\s*(second|minute|hour|day|week|month|year)s?$/i', trim($modifier), $mch)) {
        $fn   = $mch[1] === '-' ? 'DATE_SUB' : 'DATE_ADD';
        $unit = strtoupper($mch[3]);
        return "{$fn}(NOW(), INTERVAL {$mch[2]} {$unit})";
    }
    return 'NOW()';
}

/** SQL-Ausdruck: Jahr einer Datumsspalte als String, z.B. für Vergleich mit '2026' */
function db_year(string $col): string {
    return db_is_mysql()
        ? "DATE_FORMAT({$col}, '%Y')"
        : "strftime('%Y', {$col})";
}

/** SQL-Bedingung: Datum fällt auf Samstag oder Sonntag */
function db_weekend_condition(string $col): string {
    // MySQL: DAYOFWEEK() → 1 = Sonntag, 7 = Samstag
    // SQLite: strftime('%w') → '0' = Sonntag, '6' = Samstag
    return db_is_mysql()
        ? "DAYOFWEEK({$col}) IN (1, 7)"
        : "strftime('%w', {$col}) IN ('0', '6')";
}

/** Prefix für "INSERT, aber Duplikate stillschweigend ignorieren" */
function db_insert_ignore(): string {
    return db_is_mysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
}

/**
 * GROUP_CONCAT mit eigenem Trennzeichen.
 * $expr darf mehrere Teile enthalten, die verkettet werden sollen (Array).
 * Beispiel: db_group_concat(['id', "'|'", 'datum'], ';')
 */
function db_group_concat(array $parts, string $separator): string {
    $sep = "'" . str_replace("'", "''", $separator) . "'";
    if (db_is_mysql()) {
        $expr = count($parts) > 1 ? 'CONCAT(' . implode(', ', $parts) . ')' : $parts[0];
        return "GROUP_CONCAT({$expr} SEPARATOR {$sep})";
    }
    $expr = implode(' || ', $parts);
    return "GROUP_CONCAT({$expr}, {$sep})";
}

// ─── Introspektion (treiberabhängig) ─────────────────────────────────────────

/** Liste aller Tabellen der aktiven Datenbank (ohne interne Tabellen) */
function db_list_tables(PDO $pdo): array {
    if (db_is_mysql()) {
        return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }
    return $pdo->query("
        SELECT name FROM sqlite_master
        WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
        ORDER BY name
    ")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Spalten einer Tabelle, normalisiert:
 * [ ['name' => ..., 'type' => ..., 'notnull' => 0/1, 'dflt_value' => ..., 'pk' => 0/1], ... ]
 */
function db_table_columns(PDO $pdo, string $table): array {
    $tableSafe = str_replace('`', '', $table);
    if (db_is_mysql()) {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$tableSafe}`")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'       => $r['Field'],
                'type'       => $r['Type'],
                'notnull'    => (strtoupper($r['Null'] ?? '') === 'NO') ? 1 : 0,
                'dflt_value' => $r['Default'],
                'pk'         => (strtoupper($r['Key'] ?? '') === 'PRI') ? 1 : 0,
            ];
        }
        return $out;
    }
    $quoted = str_replace('"', '""', $table);
    return $pdo->query('PRAGMA table_info("' . $quoted . '")')->fetchAll(PDO::FETCH_ASSOC);
}

/** Existiert eine Tabelle in der aktiven Datenbank? */
function db_table_exists(PDO $pdo, string $table): bool {
    if (db_is_mysql()) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                               WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetch();
}

/** Setzt den Auto-Increment-Zähler einer Tabelle zurück (nach DELETE FROM) */
function db_reset_autoincrement(PDO $pdo, string $table): void {
    $tableSafe = str_replace('`', '', $table);
    try {
        if (db_is_mysql()) {
            $pdo->exec("ALTER TABLE `{$tableSafe}` AUTO_INCREMENT = 1");
        } else {
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name='{$tableSafe}'");
        }
    } catch (PDOException $e) {
        // sqlite_sequence existiert evtl. nicht – unkritisch
    }
}

// ─── Schema-Übersetzung SQLite → MySQL ───────────────────────────────────────

/**
 * Übersetzt einen SQLite-Spaltentyp in einen MySQL-Typ.
 * $isPk / $isAutoInc steuern Sonderfälle (TEXT-Primärschlüssel → VARCHAR).
 */
function db_translate_type(string $sqliteType, bool $isPk = false, bool $isAutoInc = false): string {
    $t = strtoupper(trim($sqliteType));

    if ($isAutoInc) return 'INT';

    if (strpos($t, 'INT') !== false)   return $isPk ? 'INT' : 'INT';
    if (strpos($t, 'CHAR') !== false || strpos($t, 'CLOB') !== false || strpos($t, 'TEXT') !== false) {
        // TEXT als Primärschlüssel geht in MySQL nicht ohne Längenangabe → VARCHAR
        return $isPk ? 'VARCHAR(191)' : 'TEXT';
    }
    if (strpos($t, 'BLOB') !== false || $t === '') return 'LONGBLOB';
    if (strpos($t, 'REAL') !== false || strpos($t, 'FLOA') !== false || strpos($t, 'DOUB') !== false) return 'DOUBLE';
    if (strpos($t, 'BOOL') !== false)  return 'TINYINT(1)';
    if (strpos($t, 'DATETIME') !== false || strpos($t, 'TIMESTAMP') !== false) return 'DATETIME';
    if (strpos($t, 'DATE') !== false)  return 'DATE';
    if (strpos($t, 'TIME') !== false)  return 'TIME';
    if (strpos($t, 'NUMERIC') !== false || strpos($t, 'DECIMAL') !== false) return 'DECIMAL(20,6)';

    return 'TEXT'; // Fallback
}

/**
 * Baut aus einer SQLite-Tabellendefinition (PRAGMA table_info + CREATE-SQL)
 * ein MySQL-CREATE-TABLE-Statement.
 *
 * @param string $table     Tabellenname
 * @param array  $columns   Ergebnis von PRAGMA table_info
 * @param string $createSql Original CREATE-SQL aus sqlite_master (für AUTOINCREMENT-Erkennung)
 */
function db_build_mysql_create(string $table, array $columns, string $createSql): string {
    $tableSafe = str_replace('`', '', $table);
    $defs = [];
    $pkCols = [];

    // Primärschlüssel-Spalten ermitteln
    foreach ($columns as $col) {
        if (!empty($col['pk'])) $pkCols[] = $col['name'];
    }
    $singleIntPk = (count($pkCols) === 1);

    foreach ($columns as $col) {
        $name    = str_replace('`', '', $col['name']);
        $isPk    = !empty($col['pk']);
        $type    = strtoupper((string)$col['type']);
        // SQLite behandelt "INTEGER PRIMARY KEY" implizit als Auto-Increment (Rowid-Alias),
        // daher übernehmen wir das in MySQL als AUTO_INCREMENT.
        $isAutoInc = $isPk && $singleIntPk && strpos($type, 'INT') !== false;

        $mysqlType = db_translate_type((string)$col['type'], $isPk, $isAutoInc);
        $def = "`{$name}` {$mysqlType}";

        if ($isAutoInc) {
            $def .= ' NOT NULL AUTO_INCREMENT';
        } else {
            if (!empty($col['notnull'])) $def .= ' NOT NULL';

            // Default-Werte übernehmen – aber nicht für TEXT/BLOB (in MySQL nicht erlaubt)
            $dflt = $col['dflt_value'];
            if ($dflt !== null && $dflt !== '' && $mysqlType !== 'TEXT' && $mysqlType !== 'LONGBLOB') {
                $d = trim((string)$dflt);
                if (strcasecmp($d, 'CURRENT_TIMESTAMP') === 0 && $mysqlType === 'DATETIME') {
                    $def .= ' DEFAULT CURRENT_TIMESTAMP';
                } elseif (is_numeric($d)) {
                    $def .= ' DEFAULT ' . $d;
                } elseif (preg_match("/^'(.*)'$/s", $d, $m)) {
                    if ($m[1] !== '' || strpos($mysqlType, 'VARCHAR') === 0) {
                        $def .= " DEFAULT '" . str_replace("'", "''", $m[1]) . "'";
                    }
                }
            }
        }
        $defs[] = $def;
    }

    if (!empty($pkCols)) {
        $quoted = array_map(fn($c) => '`' . str_replace('`', '', $c) . '`', $pkCols);
        $defs[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';
    }

    return "CREATE TABLE `{$tableSafe}` (\n  " . implode(",\n  ", $defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

// ─── PLZ-Verzeichnis ─────────────────────────────────────────────────────────

/**
 * Importiert das deutsche PLZ-Verzeichnis aus data/plz_daten.json in die
 * Tabelle plz_verzeichnis (vorhandene Einträge werden ersetzt).
 * Quelle der Daten: GeoNames.org (Lizenz CC BY 4.0).
 *
 * @return int Anzahl der importierten Einträge
 * @throws RuntimeException wenn die Datei fehlt oder unlesbar ist
 */
function plz_import_from_json(PDO $pdo, ?string $file = null): int {
    $file = $file ?? __DIR__ . '/data/plz_daten.json';

    if (!is_file($file)) {
        throw new RuntimeException('Die Datei data/plz_daten.json wurde nicht gefunden.');
    }
    $payload = json_decode((string)file_get_contents($file), true);
    $entries = $payload['eintraege'] ?? null;
    if (!is_array($entries) || empty($entries)) {
        throw new RuntimeException('data/plz_daten.json enthält keine gültigen Einträge.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM plz_verzeichnis");
        db_reset_autoincrement($pdo, 'plz_verzeichnis');

        $batchSize = 300;
        $count = 0;
        for ($i = 0, $n = count($entries); $i < $n; $i += $batchSize) {
            $batch = array_slice($entries, $i, $batchSize);
            $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, ?)'));
            $params = [];
            foreach ($batch as $e) {
                $params[] = substr((string)($e['p'] ?? ''), 0, 10);
                $params[] = (string)($e['o'] ?? '');
                $params[] = substr((string)($e['l'] ?? ''), 0, 5);
            }
            $stmt = $pdo->prepare("INSERT INTO plz_verzeichnis (plz, ort, bundesland) VALUES {$placeholders}");
            $stmt->execute($params);
            $count += count($batch);
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('PLZ-Import fehlgeschlagen: ' . $e->getMessage());
    }
}

// ─── Konfigurierbare Pflichtfelder im Termin-Buchungsformular ──────────────

/**
 * Alle im Buchungsformular konfigurierbaren Felder mit Anzeige-Label.
 * Der Array-Schlüssel entspricht exakt dem POST-Feldnamen in save_appointment.php.
 */
function terminfeld_katalog(): array {
    return [
        'einsatzart'  => 'Einsatzart',
        'ankunft'     => 'Ankunft (Uhrzeit)',
        'unterart'    => 'Spezifizierung / Unterart',
        'kunde_name'  => 'Name Kunde',
        'strasse'     => 'Straße / Nr.',
        'zusatzinfo'  => 'Zusatz (Adresse)',
        'plz'         => 'PLZ',
        'ort'         => 'Ort',
        'pflegekasse' => 'Geräte-ID',
        'tel1_name'   => 'Name 1 (Telefon)',
        'telefon1'    => 'Telefon 1',
        'tel2_name'   => 'Name 2 (Telefon)',
        'telefon2'    => 'Telefon 2',
        'tel3_name'   => 'Name 3 (Telefon)',
        'telefon3'    => 'Telefon 3',
        'bemerkung'   => 'Bemerkung',
    ];
}

/**
 * Liest die aktuell als Pflichtfeld konfigurierten Felder aus den Einstellungen.
 * Ohne gespeicherte Konfiguration ist die Liste leer (kein Feld ist initial Pflicht).
 * Ungültige/veraltete Feldnamen werden automatisch herausgefiltert.
 */
function get_required_termin_felder(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'required_termin_felder'");
    $stmt->execute();
    $raw = $stmt->fetchColumn();

    $liste = $raw ? json_decode((string)$raw, true) : [];
    if (!is_array($liste)) $liste = [];

    $gueltig = array_keys(terminfeld_katalog());
    $cache = array_values(array_intersect($liste, $gueltig));
    return $cache;
}

/** true, wenn das übergebene Feld aktuell als Pflichtfeld konfiguriert ist */
function ist_termin_feld_pflicht(PDO $pdo, string $feld): bool {
    return in_array($feld, get_required_termin_felder($pdo), true);
}

<?php
// Datei: init_db.php

/**
 * Initialisiert die Datenbanktabellen, falls sie nicht existieren.
 * Funktioniert treiber-neutral fuer SQLite und MySQL (siehe db_helpers.php).
 */
function initDatabase($pdo) {

    // ── SQLite-Schema (Original) ──────────────────────────────────────────
    $schemaSqlite = [
        "users" => "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT, role TEXT DEFAULT 'user', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, kuerzel TEXT)",
        "dienste" => "CREATE TABLE IF NOT EXISTS dienste (id INTEGER PRIMARY KEY AUTOINCREMENT, datum TEXT NOT NULL, uhrzeit TEXT NOT NULL, techniker_id INTEGER NOT NULL, einsatzart TEXT, name TEXT, strasse TEXT, zusatzinfo TEXT, plz TEXT, ort TEXT, telefon1 TEXT, tel1_name TEXT, telefon2 TEXT, tel2_name TEXT, telefon3 TEXT, tel3_name TEXT, pflegegrad TEXT, pflegekasse TEXT, drk_mitglied TEXT, bemerkung TEXT, unterart TEXT, festnetz INTEGER DEFAULT 0, fufi_gewuenscht INTEGER DEFAULT 0, soundboost INTEGER DEFAULT 0, watch_vorstellen INTEGER DEFAULT 0, homego_vorstellen INTEGER DEFAULT 0, polieren INTEGER DEFAULT 0, ankunft TEXT DEFAULT '', ausgefallen INTEGER DEFAULT 0, created_by_kuerzel TEXT, updated_by_kuerzel TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, edit2_kuerzel TEXT, edit2_at DATETIME, updated_at DATETIME, wohnanlage INTEGER DEFAULT 0, deleted_at DATETIME, deleted_by TEXT, schluessel INTEGER DEFAULT 0)",
        "einsatzarten" => "CREATE TABLE IF NOT EXISTS einsatzarten (id INTEGER PRIMARY KEY AUTOINCREMENT, hauptart TEXT NOT NULL, unterart TEXT)",
        "tages_notizen" => "CREATE TABLE IF NOT EXISTS tages_notizen (id INTEGER PRIMARY KEY AUTOINCREMENT, datum TEXT NOT NULL, techniker_id INTEGER NOT NULL, notiz TEXT, vertretung TEXT)",
        "techniker_zuordnung" => "CREATE TABLE IF NOT EXISTS techniker_zuordnung (id INTEGER PRIMARY KEY AUTOINCREMENT, techniker_nummer INTEGER NOT NULL, name TEXT, jahr INTEGER NOT NULL, wochentag TEXT DEFAULT 'Montag', kw_start INTEGER NOT NULL, kw_end INTEGER NOT NULL)",
        "termin_optionen" => "CREATE TABLE IF NOT EXISTS termin_optionen (id INTEGER PRIMARY KEY AUTOINCREMENT, spalten_name TEXT, anzeige_name TEXT, sortierung INTEGER DEFAULT 0)",
        "active_locks" => "CREATE TABLE IF NOT EXISTS active_locks (slot_id TEXT PRIMARY KEY, user_id INTEGER, username TEXT, expires_at DATETIME)",
        "auth_tokens" => "CREATE TABLE IF NOT EXISTS auth_tokens (token TEXT PRIMARY KEY, user_id INTEGER, expires_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "audit_log" => "CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, dienst_id INTEGER, user_id INTEGER, aktion TEXT, details TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "system_status" => "CREATE TABLE IF NOT EXISTS system_status (id INTEGER PRIMARY KEY, last_update DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "settings" => "CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)",
        "plz_verzeichnis" => "CREATE TABLE IF NOT EXISTS plz_verzeichnis (id INTEGER PRIMARY KEY AUTOINCREMENT, plz TEXT NOT NULL, ort TEXT NOT NULL, bundesland TEXT)",
        "login_attempts" => "CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, username TEXT, attempted_at DATETIME NOT NULL)"
    ];

    // ── MySQL-Schema (uebersetzt, fuer Erst-Installation direkt auf MySQL) ──
    $schemaMysql = [
        "users" => "CREATE TABLE IF NOT EXISTS `users` (`id` INT NOT NULL AUTO_INCREMENT, `username` TEXT NOT NULL, `password_hash` TEXT, `role` VARCHAR(50) DEFAULT 'user', `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `kuerzel` TEXT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "dienste" => "CREATE TABLE IF NOT EXISTS `dienste` (`id` INT NOT NULL AUTO_INCREMENT, `datum` TEXT NOT NULL, `uhrzeit` TEXT NOT NULL, `techniker_id` INT NOT NULL, `einsatzart` TEXT, `name` TEXT, `strasse` TEXT, `zusatzinfo` TEXT, `plz` TEXT, `ort` TEXT, `telefon1` TEXT, `tel1_name` TEXT, `telefon2` TEXT, `tel2_name` TEXT, `telefon3` TEXT, `tel3_name` TEXT, `pflegegrad` TEXT, `pflegekasse` TEXT, `drk_mitglied` TEXT, `bemerkung` TEXT, `unterart` TEXT, `festnetz` INT DEFAULT 0, `fufi_gewuenscht` INT DEFAULT 0, `soundboost` INT DEFAULT 0, `watch_vorstellen` INT DEFAULT 0, `homego_vorstellen` INT DEFAULT 0, `polieren` INT DEFAULT 0, `ankunft` TEXT, `ausgefallen` INT DEFAULT 0, `created_by_kuerzel` TEXT, `updated_by_kuerzel` TEXT, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `edit2_kuerzel` TEXT, `edit2_at` DATETIME, `updated_at` DATETIME, `wohnanlage` INT DEFAULT 0, `deleted_at` DATETIME, `deleted_by` TEXT, `schluessel` INT DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "einsatzarten" => "CREATE TABLE IF NOT EXISTS `einsatzarten` (`id` INT NOT NULL AUTO_INCREMENT, `hauptart` TEXT NOT NULL, `unterart` TEXT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "tages_notizen" => "CREATE TABLE IF NOT EXISTS `tages_notizen` (`id` INT NOT NULL AUTO_INCREMENT, `datum` TEXT NOT NULL, `techniker_id` INT NOT NULL, `notiz` TEXT, `vertretung` TEXT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "techniker_zuordnung" => "CREATE TABLE IF NOT EXISTS `techniker_zuordnung` (`id` INT NOT NULL AUTO_INCREMENT, `techniker_nummer` INT NOT NULL, `name` TEXT, `jahr` INT NOT NULL, `wochentag` VARCHAR(20) DEFAULT 'Montag', `kw_start` INT NOT NULL, `kw_end` INT NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "termin_optionen" => "CREATE TABLE IF NOT EXISTS `termin_optionen` (`id` INT NOT NULL AUTO_INCREMENT, `spalten_name` TEXT, `anzeige_name` TEXT, `sortierung` INT DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "active_locks" => "CREATE TABLE IF NOT EXISTS `active_locks` (`slot_id` VARCHAR(191) NOT NULL, `user_id` INT, `username` TEXT, `expires_at` DATETIME, PRIMARY KEY (`slot_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "auth_tokens" => "CREATE TABLE IF NOT EXISTS `auth_tokens` (`token` VARCHAR(191) NOT NULL, `user_id` INT, `expires_at` DATETIME, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`token`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "audit_log" => "CREATE TABLE IF NOT EXISTS `audit_log` (`id` INT NOT NULL AUTO_INCREMENT, `dienst_id` INT, `user_id` INT, `aktion` TEXT, `details` TEXT, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "system_status" => "CREATE TABLE IF NOT EXISTS `system_status` (`id` INT NOT NULL, `last_update` DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "settings" => "CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(191) NOT NULL, `value` TEXT, PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "plz_verzeichnis" => "CREATE TABLE IF NOT EXISTS `plz_verzeichnis` (`id` INT NOT NULL AUTO_INCREMENT, `plz` VARCHAR(10) NOT NULL, `ort` TEXT NOT NULL, `bundesland` VARCHAR(5), PRIMARY KEY (`id`), INDEX `idx_plz` (`plz`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "login_attempts" => "CREATE TABLE IF NOT EXISTS `login_attempts` (`id` INT NOT NULL AUTO_INCREMENT, `ip` VARCHAR(64) NOT NULL, `username` VARCHAR(191), `attempted_at` DATETIME NOT NULL, PRIMARY KEY (`id`), INDEX `idx_la_ip` (`ip`), INDEX `idx_la_user` (`username`), INDEX `idx_la_time` (`attempted_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    $schema = db_is_mysql() ? $schemaMysql : $schemaSqlite;

    try {
        foreach ($schema as $tableName => $sql) {
            if (!db_table_exists($pdo, $tableName)) {
                $pdo->exec($sql);
            }
        }
        if (!db_is_mysql()) {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_plz ON plz_verzeichnis (plz)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_la_ip ON login_attempts (ip)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_la_user ON login_attempts (username)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_la_time ON login_attempts (attempted_at)");
        }
        // install_date einmalig setzen (Installationsdatum, z.B. fuer Anzeige "installiert seit")
        if (db_is_mysql()) {
            $pdo->exec("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('install_date', CURDATE())");
        } else {
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('install_date', date('now'))");
        }
    } catch (PDOException $e) {
        error_log("Datenbank-Initialisierung fehlgeschlagen: " . $e->getMessage());
    }
}
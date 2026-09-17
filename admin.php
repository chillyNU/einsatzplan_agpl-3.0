<?php
require 'config.php';

// Zugriffsschutz
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$selectedYear = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
$message = "";

// CSRF fuer alle POST-Requests global pruefen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// ==========================================
// START: NEU DUPLIKAT-SUCHE LOGIK (MIT TOLERANZ)
// ==========================================
$duplicates = [];
$dupYear = $_GET['dup_year'] ?? date('Y');
$availableFields = [
    'name' => 'name',
    'strasse' => 'strasse',
    'telefon1' => 'telefon1',
    'plz' => 'plz'
];
$compareFields = $_GET['compare'] ?? ['name'];

if (isset($_GET['run_dup_check'])) {
    $selectFields = [];
    $groupFields = [];
    $whereParts = [db_year('datum') . " = :year", "deleted_at IS NULL"];
    
    foreach ($compareFields as $f) {
        if (array_key_exists($f, $availableFields)) {
            $realCol = $availableFields[$f];
            
            // Falls nach Name gesucht wird, entfernen wir Komma und Leerzeichen beim Vergleich
            if ($f === 'name') {
                // SQLite Syntax: REPLACE(REPLACE(spalte, ',', ''), ' ', '')
                $cleanCol = "REPLACE(REPLACE($realCol, ',', ''), ' ', '')";
                $selectFields[] = "MAX($realCol) AS name"; // Zeigt einen der echten Namen an
                $groupFields[] = "LOWER($cleanCol)";
            } else {
                $selectFields[] = $realCol;
                $groupFields[] = $realCol;
            }
            $whereParts[] = "($realCol != '' AND $realCol IS NOT NULL)";
        }
    }

    if (!empty($selectFields)) {
        $sqlSelect = implode(', ', $selectFields);
        $sqlGroup = implode(', ', $groupFields);
        $sqlWhere = implode(' AND ', $whereParts);

        // Wir nutzen GROUP_CONCAT, um die IDs der gefundenen Duplikate zu sammeln
        $sql = "SELECT $sqlSelect, COUNT(*) as anzahl, 
                " . db_group_concat(['id', "'|'", 'datum'], ';') . " as termin_infos
                FROM dienste 
                WHERE $sqlWhere
                GROUP BY $sqlGroup
                HAVING anzahl > 1
                ORDER BY anzahl DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['year' => $dupYear]);
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $message = "error|Fehler bei Duplikat-Suche: " . $e->getMessage();
        }
    }
}
// ==========================================
// ENDE: NEU DUPLIKAT-SUCHE LOGIK
// ==========================================

if (isset($_POST['saveweekendconfig'])) {
    $wochenendeAnzeigen = isset($_POST['wochenende_anzeigen']) ? '1' : '0';

    $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('wochenende_anzeigen', ?)");
    $stmt->execute([$wochenendeAnzeigen]);

    $message = "success|Wochenend-Anzeige wurde aktualisiert.";
}

// --- Mitarbeiter-Anzahl pro Wochentag speichern ---
if (isset($_POST['save_mitarbeiter_anzahl'])) {
    csrf_verify();
    $alleTage = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
    $eingaben = (array)($_POST['mitarbeiter_anzahl'] ?? []);
    $neu = [];
    foreach ($alleTage as $tag) {
        $n = isset($eingaben[$tag]) ? (int)$eingaben[$tag] : DEFAULT_MITARBEITER;
        // hart auf 1..technisches Maximum begrenzen (serverseitig – ein
        // manipuliertes Formular kann das Limit nicht überschreiten)
        $neu[$tag] = max(1, min(getMaxMitarbeiterErlaubt(), $n));
    }
    $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('mitarbeiter_pro_wochentag', ?)");
    $stmt->execute([json_encode($neu)]);

    $message = "success|Mitarbeiter-Anzahl pro Wochentag wurde gespeichert.";
}

// --- QR-Code-Token erneuern (macht alte QR-Codes/Links ungültig) ---
if (isset($_POST['renew_qr_token'])) {
    csrf_verify();
    $neuerToken = bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('mobil_qr_token', ?)");
    $stmt->execute([$neuerToken]);
    $message = "success|QR-Codes wurden erneuert. Bereits verteilte Links funktionieren weiterhin, sofern kein zusätzlicher Tokenschutz aktiv ist.";
}

// Aktuellen QR-Token laden (einmalig erzeugen, falls noch keiner existiert)
$qrToken = '';
try {
    $qrToken = $pdo->query("SELECT value FROM settings WHERE `key` = 'mobil_qr_token'")->fetchColumn() ?: '';
    if ($qrToken === '') {
        $qrToken = bin2hex(random_bytes(8));
        $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('mobil_qr_token', ?)")->execute([$qrToken]);
    }
} catch (Exception $e) {}

// --- Pflichtfelder im Terminformular konfigurieren ---
if (isset($_POST['save_required_felder'])) {
    csrf_verify();
    $gueltig = array_keys(terminfeld_katalog());
    $ausgewaehlt = array_values(array_intersect((array)($_POST['required_felder'] ?? []), $gueltig));

    $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('required_termin_felder', ?)");
    $stmt->execute([json_encode($ausgewaehlt)]);

    $anzahl = count($ausgewaehlt);
    $message = $anzahl > 0
        ? "success|Pflichtfelder gespeichert: {$anzahl} Feld(er) sind jetzt im Buchungsformular Pflicht."
        : "success|Pflichtfelder gespeichert: Aktuell ist kein Feld im Buchungsformular Pflicht.";
}

// --- NEU: LOGIK ALTE TERMINE LÖSCHEN ---
if (isset($_POST['delete_before_date'])) {
    $stichtag = $_POST['stichtag'] ?? ''; // Format: YYYY-MM-DD
    // Eingabe auf gueltiges Datumsformat validieren (verhindert Header-Injection)
    if (!empty($stichtag) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $stichtag)) {
        $stmt = $pdo->prepare("DELETE FROM dienste WHERE datum < ? AND deleted_at IS NULL");
        $stmt->execute([$stichtag]);
        $msg = urlencode('Alle Termine vor dem ' . $stichtag . ' wurden gelöscht.');
        header("Location: admin.php?success=$msg");
        exit;
    }
}


// --- NEU: LOGIK PAPIERKORB LEEREN ---
if (isset($_POST['empty_trash'])) {
    $pdo->exec("DELETE FROM dienste WHERE deleted_at IS NOT NULL");
    header("Location: admin.php?success=Papierkorb wurde komplett geleert");
    exit;
}
// --- BESTEHENDE LOGIK: BENUTZER LÖSCHEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_user'])) {
    $uid = (int)$_POST['del_user'];
    if ($uid !== $_SESSION['user_id']) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        header("Location: admin.php?success=Benutzer gelöscht");
        exit;
    } else {
        $message = "error|Man kann sich nicht selbst löschen!";
    }
}

// --- BESTEHENDE LOGIK: BENUTZER ANLEGEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $kuerzel  = strtoupper(trim($_POST['kuerzel'])); 
    $role     = $_POST['role'];
    if (!empty($username) && !empty($password)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // MariaDB Anpassung: INSERT IGNORE
        $stmt = $pdo->prepare(db_insert_ignore() . " INTO users (username, password_hash, kuerzel, role) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$username, $hash, $kuerzel, $role])) {
            $message = "success|Benutzer '$username' ($kuerzel) angelegt.";
        } else {
            $message = "error|Fehler beim Anlegen.";
        }
    }
}
// --- LOGIK: BENUTZER AKTUALISIEREN ---
if (isset($_POST['change_user_data']) && !isset($_POST['action_user'])) {
    $uid = (int)$_POST['user_id'];
    $new_kuerzel = strtoupper(trim($_POST['new_kuerzel']));
    $allowed_roles = ['admin', 'user', 'viewer'];
    $new_role = in_array($_POST['role'], $allowed_roles) ? $_POST['role'] : 'user';

    // Rolle und Kuerzel aktualisieren
    $stmt = $pdo->prepare("UPDATE users SET kuerzel = ?, role = ? WHERE id = ?");
    $stmt->execute([$new_kuerzel, $new_role, $uid]);

    // Passwort nur wenn eingegeben
    if (!empty($_POST['new_password'])) {
        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $uid]);
    }

    // Session aktualisieren wenn eigenes Konto geaendert
    if ($uid == $_SESSION['user_id']) {
        $_SESSION['kuerzel'] = $new_kuerzel;
    }

    header("Location: admin.php?success=Benutzer aktualisiert");
    exit;
}
// --- BESTEHENDE LOGIK: TECHNIKER LÖSCHEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_tech'])) {
    $stmt = $pdo->prepare("DELETE FROM techniker_zuordnung WHERE id = ?");
    $stmt->execute([(int)$_POST['del_tech']]);
    header("Location: admin.php?y=".$selectedYear."&success=Eintrag gelöscht");
    exit;
}

// --- BESTEHENDE LOGIK: TECHNIKER UPDATE/ADD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tech'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $year = (int)$_POST['jahr'];
    $wochentag = $_POST['wochentag'];
    $techNum = (int)$_POST['techniker_nummer'];
    $name = trim($_POST['name'] ?? '');
    $kw_start = (int)$_POST['kw_start'];
    $kw_end = (int)$_POST['kw_end'];
    if ($kw_start > $kw_end) {
        $message = "error|Start-KW kann nicht größer als End-KW sein!";
    } elseif ($name === '') {
        $message = "error|Bitte einen Namen eingeben!";
    } else {
        $checkSql = "SELECT COUNT(*) FROM techniker_zuordnung WHERE wochentag = ? AND techniker_nummer = ? AND jahr = ? AND NOT (kw_end < ? OR kw_start > ?)";
        $params = [$wochentag, $techNum, $year, $kw_start, $kw_end];
        if ($id) { $checkSql .= " AND id != ?"; $params[] = $id; }
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() > 0) {
            $message = "error|Überschneidung erkannt!";
        } else {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE techniker_zuordnung SET name=?, kw_start=?, kw_end=?, wochentag=?, techniker_nummer=?, jahr=? WHERE id=?");
                $stmt->execute([$name, $kw_start, $kw_end, $wochentag, $techNum, $year, $id]);
                $message = "success|Änderungen gespeichert.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO techniker_zuordnung (wochentag, techniker_nummer, name, kw_start, kw_end, jahr) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$wochentag, $techNum, $name, $kw_start, $kw_end, $year]);
                $message = "success|Neu angelegt.";
            }
        }
    }
}

// --- BESTEHENDE LOGIK: EINSATZARTEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_art'])) {
    $stmt = $pdo->prepare("DELETE FROM einsatzarten WHERE id = ?");
    $stmt->execute([(int)$_POST['del_art']]);
    header("Location: admin.php?success=Einsatzart gelöscht");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_art'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $haupt = trim($_POST['hauptart']);
    $unter = trim($_POST['unterart']) ?: null;
    if ($haupt !== '') {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE einsatzarten SET hauptart = ?, unterart = ? WHERE id = ?");
            $stmt->execute([$haupt, $unter, $id]);
            $message = "success|Einsatzart aktualisiert.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO einsatzarten (hauptart, unterart) VALUES (?, ?)");
            $stmt->execute([$haupt, $unter]);
            $message = "success|Einsatzart hinzugefügt.";
        }
    }
}

// --- BESTEHENDE LOGIK: OPTIONEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_opt'])) {
    $stmt = $pdo->prepare("DELETE FROM termin_optionen WHERE id = ?");
    $stmt->execute([(int)$_POST['del_opt']]);
    header("Location: admin.php?success=Option entfernt");
    exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_opt'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $anzeige = trim($_POST['anzeige_name']);
    $sort = (int)$_POST['sortierung'];
    
    if ($id) {
        // Update-Logik
        $stmt = $pdo->prepare("UPDATE termin_optionen SET anzeige_name = ?, sortierung = ? WHERE id = ?");
        $stmt->execute([$anzeige, $sort, $id]);
        $message = "success|Option aktualisiert.";
    } else {
        // Neu-Anlegen-Logik
        $spalte = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['spalten_name']));
        if (!empty($spalte) && !empty($anzeige)) {
            $stmt = $pdo->prepare("INSERT INTO termin_optionen (spalten_name, anzeige_name, sortierung) VALUES (?, ?, ?)");
            $stmt->execute([$spalte, $anzeige, $sort]);
            
            // Spaltenprüfung (treiberneutral: SQLite oder MySQL)
            $cols = array_column(db_table_columns($pdo, 'dienste'), 'name');
            
            if (!in_array($spalte, $cols)) { 
                $pdo->exec("ALTER TABLE dienste ADD COLUMN $spalte INTEGER DEFAULT 0"); 
            }
            $message = "success|Option hinzugefügt.";
        }
    }
}

if (isset($_GET['success'])) $message = "success|".htmlspecialchars($_GET['success']);
if (isset($_POST['save_maps_config'])) {
    $neueAdresse = trim($_POST['start_adresse']);
    if (!empty($neueAdresse)) {
        $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('start_adresse', ?)");
        $stmt->execute([$neueAdresse]);
        $message = "success|Startadresse wurde aktualisiert.";
    }
}
// Ganz oben im PHP-Bereich der admin.php einfügen:
// --- PLZ-Verzeichnis importieren / aktualisieren ---
if (isset($_POST['import_plz'])) {
    try {
        set_time_limit(120);
        $anzahl = plz_import_from_json($pdo);
        $message = "success|PLZ-Verzeichnis importiert: {$anzahl} Einträge stehen jetzt für die Autovervollständigung bereit.";
    } catch (Throwable $e) {
        $message = "error|PLZ-Import fehlgeschlagen: " . htmlspecialchars($e->getMessage());
    }
}

if (isset($_POST['save_footer_config'])) {
    $neuerFooter = trim($_POST['footer_text']);
    $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('footer_text', ?)");
    $stmt->execute([$neuerFooter]);
    $message = "success|Footer-Text wurde aktualisiert."; // Hier wird die Meldung gesetzt
}

$stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'wochenende_anzeigen'");
$stmt->execute();
$wochenendeAnzeigen = $stmt->fetchColumn() ?: '0';

// DATEN FÜR DIE ANZEIGE
$zuordnungen = $pdo->prepare("
    SELECT * FROM techniker_zuordnung
    WHERE jahr = ?
    ORDER BY CASE wochentag
        WHEN 'Montag' THEN 1
        WHEN 'Dienstag' THEN 2
        WHEN 'Mittwoch' THEN 3
        WHEN 'Donnerstag' THEN 4
        WHEN 'Freitag' THEN 5
        WHEN 'Samstag' THEN 6
        WHEN 'Sonntag' THEN 7
    END, techniker_nummer, kw_start
");
$zuordnungen->execute([$selectedYear]);
$zuordnungen = $zuordnungen->fetchAll(PDO::FETCH_ASSOC);

$einsatzarten = $pdo->query("SELECT * FROM einsatzarten ORDER BY hauptart ASC, unterart ASC")->fetchAll(PDO::FETCH_ASSOC);
$optionen = $pdo->query("SELECT * FROM termin_optionen ORDER BY sortierung ASC")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT * FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// NEU: GELÖSCHTE TERMINE LADEN
$trash = $pdo->query("SELECT * FROM dienste WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'wochenende_anzeigen'");
$stmt->execute();
$wochenendeAnzeigen = $stmt->fetchColumn() ?: '0';

$wochentage = $wochenendeAnzeigen === '1'
    ? ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag']
    : ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];

// Aktuelle Mitarbeiter-Anzahl je Wochentag (für das Konfigurationsformular)
$mitarbeiterProTag = getMitarbeiterProWochentag();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Admin - Planer Konfiguration</title>
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <style>
        body { padding: 24px; }
        .container { max-width: 1100px; margin: auto; }
        .form-box { border-color: var(--brand-tint); background: #fdf8f8; }
        .trash-table td { font-size: 0.85em; }
        .details-row { background: var(--warn-tint); display: none; transition: all 0.3s ease; }
        .details-content { padding: 10px; font-size: 0.85em; border: 1px dashed var(--warn-line); border-radius: var(--radius-sm); }
        .details-row td { border-top: none !important; }
        .danger-zone { border: 1px solid var(--danger-line); background: var(--danger-tint); border-radius: var(--radius-sm); padding: 4px 18px 14px; margin-top: 30px; }
        .danger-zone h3 { margin-top: 14px; }
        /* KW-Bereichseingabe (von/bis) */
        .kw-range { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; }
        .kw-input { width: 72px; text-align: center; font-variant-numeric: tabular-nums; }
        .kw-sep { color: var(--ink-soft); font-weight: 600; font-size: 0.9em; }
        .kw-label-sm { color: var(--ink-soft); font-weight: 700; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.05em; }
        /* Kopfbereich: Überschrift oben, Navigations-Pills in eigener Zeile darunter */
        .page-head h1 { margin: 0 0 6px 0; padding-bottom: 10px; border-bottom: 2px solid var(--line); }
        .page-head .top-nav { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }

        /* Admin-Navigation als Dropdown-Menü */
        .admin-nav-row { margin-bottom: 20px; }
        .admin-dropdown { position: relative; display: inline-block; }
        /* Kein Aufblitzen beim Laden (FOUC): Alles ab dem ersten Sektions-Marker
           ist bereits per CSS ausgeblendet, bevor das JavaScript startet. Sobald
           das Skript die Steuerung übernommen hat (inline display gesetzt),
           markiert es den body mit .admin-js und diese Blanko-Regel tritt zurück. */
        .tab-marker, body:not(.admin-js) .tab-marker ~ * { display: none; }
        .admin-dropdown-btn {
            display: inline-flex; align-items: center; gap: 10px;
            min-width: 280px; justify-content: space-between;
            padding: 12px 18px; font-size: 15px; font-weight: 700;
            color: var(--ink); background: var(--surface);
            border: 1px solid var(--line-strong); border-radius: 10px;
            cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s;
        }
        .admin-dropdown-btn:hover { border-color: var(--brand); }
        .admin-dropdown.open .admin-dropdown-btn { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(200,16,46,0.12); }
        .admin-dropdown.open .admin-dropdown-btn svg { transform: rotate(180deg); }
        .admin-dropdown-btn svg { transition: transform 0.15s; flex-shrink: 0; }
        .admin-dropdown-menu {
            display: none; position: absolute; top: calc(100% + 6px); left: 0;
            min-width: 400px; max-width: min(480px, calc(100vw - 40px));
            max-height: 70vh; overflow-y: auto; z-index: 200;
            background: var(--surface); border: 1px solid var(--line-strong);
            border-radius: 10px; box-shadow: 0 10px 32px rgba(0,0,0,0.16);
            padding: 6px;
        }
        .admin-dropdown.open .admin-dropdown-menu { display: block; }
        .admin-menu-item {
            display: block; width: 100%; text-align: left; text-decoration: none;
            padding: 9px 14px; font-size: 14px; font-weight: 600; color: var(--ink);
            background: none; border: none; border-radius: 7px; cursor: pointer;
        }
        .admin-menu-item .ami-desc {
            display: block; margin-top: 1px;
            font-size: 12px; font-weight: 400; line-height: 1.35;
            color: var(--ink-faint);
        }
        .admin-menu-item:hover { background: var(--surface-alt); color: var(--brand); }
        .admin-menu-item.active { background: var(--brand); color: #fff; }
        .admin-menu-item.active .ami-desc { color: rgba(255,255,255,0.85); }
        .admin-menu-sep { height: 1px; background: var(--line); margin: 6px 8px; }
        .admin-menu-extern { color: var(--ink-soft); }
        .admin-menu-extern .ami-title::after { content: " ↗"; font-size: 0.85em; opacity: 0.6; }

        /* Reiter-Navigation (klassische Tabs mit Unterstrich beim aktiven) */
        .admin-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 2px solid var(--line-strong); margin-bottom: 24px; }
        .admin-tab {
            background: none; border: none; cursor: pointer;
            padding: 12px 20px; font-size: 15px; font-weight: 600;
            color: var(--ink-soft); position: relative; top: 2px;
            border-bottom: 3px solid transparent;
            transition: color 0.15s, border-color 0.15s;
        }
        .admin-tab:hover { color: var(--ink); }
        .admin-tab.active { color: var(--brand); border-bottom-color: var(--brand); }
        .admin-tab-hint {
            background: var(--warn-tint); border: 1px solid var(--warn-line);
            color: #7a5c00; padding: 16px 20px; border-radius: var(--radius-sm);
            font-weight: 600; text-align: center; margin-bottom: 30px;
        }

        /* Info-Icon mit Popup (Erreichbarkeits-Hinweise) */
        .info-icon {
            position: relative; cursor: help; font-size: 0.8em;
            margin-left: 8px; vertical-align: middle;
        }
        .info-icon .info-pop {
            display: none; position: absolute; left: 0; top: 130%;
            width: 340px; max-width: 80vw; z-index: 100;
            background: #fff; border: 1px solid var(--line-strong);
            box-shadow: 0 8px 28px rgba(0,0,0,0.18); border-radius: 10px;
            padding: 16px 18px; font-size: 13px; font-weight: 400;
            line-height: 1.55; color: var(--ink); text-align: left;
        }
        .info-icon:hover .info-pop, .info-icon:focus .info-pop { display: block; }
        .qr-liste img, .qr-liste canvas { display: block; }
    </style>
</head>
<body>
<div class="container">
    <div class="page-head" style="margin-bottom:20px;">
          
          <h1>System-Konfiguration</h1>
        
        <div class="top-nav">
            <a href="index.php" class="nav-pill">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Zum Dienstplan
            </a>
        </div>
    </div>

    <?php if ($message): $parts = explode('|', $message); ?>
        <div class="msg <?= $parts[0] == 'success' ? 'msg-success' : 'msg-error' ?>"><?= $parts[1] ?></div>
    <?php endif; ?>

    <div class="admin-nav-row">
        <div class="admin-dropdown">
            <button type="button" class="admin-dropdown-btn" id="adminDropdownBtn" onclick="toggleAdminMenu(event)">
                <span id="adminDropdownLabel">Bereich wählen …</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="admin-dropdown-menu" id="adminDropdownMenu"></div>
        </div>
    </div>
    <div class="admin-tab-hint" id="adminTabHint">Bitte oben einen Bereich auswählen.</div>

<div class="tab-marker" data-section="db-bereinigen" data-desc="Alte Termine bis zu einem Stichtag endgültig löschen" data-title="Datenbank bereinigen"></div>
<div class="danger-zone" style="border-color: var(--brand);">
    <br>
	<h3>🧹 Datenbank bereinigen (Archivierung)</h3>
    <p>Hier kannst du alle Termine, die <strong>vor</strong> einem bestimmten Datum liegen, unwiderruflich aus der Datenbank entfernen:</p>
    
    <form method="POST" data-confirm="<b>ACHTUNG:</b> Alle Termine <b>vor dem gewählten Stichtag</b> werden permanent aus der Datenbank gelöscht.<br><br>Dieser Vorgang kann nicht rückgängig gemacht werden." data-confirm-title="Termine bereinigen" data-confirm-type="danger" data-confirm-ok="Ja, bereinigen">
                        <?= csrf_field() ?>
        <div style="display:flex; gap:10px; align-items:center;">
            <label for="stichtag">Stichtag:</label>
            <input type="date" name="stichtag" id="stichtag" required value="<?= date('Y-m-d', strtotime('-1 year')) ?>">
            <button type="submit" name="delete_before_date" class="btn-add" style="background:var(--brand); color:white; padding: 8px 16px; border:none; cursor:pointer;">
                Termine bereinigen
            </button>
        </div>
    </form>
    <p style="font-size: 0.8em; color: #666; margin-top: 10px;">* Nur nicht bereits gelöschte Termine werden erfasst.</p>
</div>
<br>
  <h3 style="border-left-color: var(--brand); display: flex; justify-content: space-between; align-items: center;">
    🗑️ Papierkorb (gelöschte Termine)
    <?php if ($trash): ?>
    <form method="POST" style="margin:0;" data-confirm="Wirklich den <b>gesamten Papierkorb</b> unwiderruflich leeren?" data-confirm-title="Papierkorb leeren" data-confirm-type="danger">
                        <?= csrf_field() ?>
        <button type="submit" name="empty_trash" style="background:var(--brand); color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:0.7em;">
            Papierkorb komplett leeren
        </button>
    </form>
    <?php endif; ?>
</h3>

<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
    <table class="trash-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Termin</th>
                <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Kunde / Ort</th>
                <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Gelöscht am/von</th>
                
            </tr>
        </thead>
        <tbody>
            <?php if (!$trash): ?>
                <tr><td colspan="4" style="padding: 20px; text-align:center; color:#999;">Keine gelöschten Termine vorhanden.</td></tr>
            <?php else: ?>
                <?php foreach ($trash as $t): ?>
                <tr style="cursor: pointer; border-bottom: 1px solid #eee;" onclick="toggleDetails('details_<?= $t['id'] ?>')">
                    <td style="padding: 10px;">
                        <strong><?= date('d.m.Y', strtotime($t['datum'])) ?></strong><br>
                        <?= htmlspecialchars($t['uhrzeit']) ?> (T<?= htmlspecialchars($t['techniker_id']) ?>)
                    </td>
                    <td style="padding: 10px;">
                        <strong><?= htmlspecialchars($t['name']) ?></strong><br>
                        <span class="meta" style="font-size: 0.9em;"><?= htmlspecialchars($t['ort']) ?> <small>(🔍 Details)</small></span>
                    </td>
                    <td style="padding: 10px;" class="meta">
                        <?= htmlspecialchars($t['deleted_by'] ?? '??') ?><br>
                        <?= date('d.m.y H:i', strtotime($t['deleted_at'])) ?>
                    </td>
                   
                </tr>

                <tr id="details_<?= $t['id'] ?>" class="details-row" style="display:none; background: #fffcf0;">
                    <td colspan="4" style="padding: 15px; border: 1px solid #ffeeba;">
                        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 250px;">
                                <h4 style="margin: 0 0 10px 0; color: #856404; font-size: 0.9em; border-bottom: 1px solid #ffeeba;">📍 Adresse & Kontakt</h4>
                                <strong><?= htmlspecialchars($t['name']) ?></strong><br>
                                <?= htmlspecialchars($t['strasse'] ?? '') ?><br>
                                <?= htmlspecialchars($t['plz'] ?? '') ?> <?= htmlspecialchars($t['ort'] ?? '') ?><br><br>
                                📞 <?= htmlspecialchars($t['telefon1'] ?? '-') ?> / <?= htmlspecialchars($t['telefon2'] ?? '-') ?>
                            </div>

                            <div style="flex: 1; min-width: 250px;">
                                <h4 style="margin: 0 0 10px 0; color: #856404; font-size: 0.9em; border-bottom: 1px solid #ffeeba;">📝 Einsatz-Details</h4>
                                <strong>Einsatz:</strong> <?= htmlspecialchars($t['einsatzart'] ?? '') ?><br>
                                <strong>Ankunft:</strong> <?= htmlspecialchars($t['ankunft'] ?? '-') ?> Uhr<br><br>
                                <strong>Optionen:</strong>
                                <div style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;">
                                    <?php 
                                    $hasOpt = false;
                                    foreach($optionen as $opt): 
                                        if(!empty($t[$opt['spalten_name']])): $hasOpt = true; ?>
                                        <span style="background:#eee; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; border: 1px solid #ccc;">✅ <?= htmlspecialchars($opt['anzeige_name']) ?></span>
                                    <?php endif; endforeach; 
                                    if(!$hasOpt) echo "<small style='color:#999;'>Keine Optionen</small>"; ?>
                                </div>
                            </div>

                            <div style="flex: 2; min-width: 300px;">
                                <h4 style="margin: 0 0 10px 0; color: #856404; font-size: 0.9em; border-bottom: 1px solid #ffeeba;">ℹ️ Bemerkung</h4>
                                <div style="white-space: pre-wrap; font-style: italic; font-size: 0.9em;"><?= nl2br(htmlspecialchars($t['bemerkung'] ?? 'Keine Bemerkung')) ?></div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<br>
<div class="tab-marker" data-section="benutzer" data-desc="Logins anlegen, Rollen und Passwörter verwalten" data-title="Benutzerverwaltung"></div>
    <h3>Benutzerverwaltung (Logins)</h3>
    <form method="POST" class="form-box" style="background: #fdfaea; border-color: #f5e79e;">
                        <?= csrf_field() ?>
        <input type="hidden" name="action_user" value="1">
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <input type="text" name="username" placeholder="Benutzername" required style="width:150px;">
            <input type="password" name="password" placeholder="Passwort" required style="width:150px;">
            <input type="text" name="kuerzel" placeholder="Kürzel" required maxlength="5" style="width:100px;">
            <select name="role">
                <option value="admin">Administrator</option>
                <option value="user">Einfacher Benutzer</option>
            </select>
            <button type="submit" class="btn-add" style="background:#856404;">Nutzer anlegen</button>
        </div>
    </form>
    <table>
    <thead><tr><th>ID</th><th>Benutzername</th><th>Kürzel</th><th>Rolle</th><th>Daten aktualisieren</th><th>Aktion</th></tr></thead>
    <tbody>
        <?php foreach($users as $u): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
            <form method="POST">
                        <?= csrf_field() ?>
                <input type="hidden" name="change_user_data" value="1">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                
                <td><input type="text" name="new_kuerzel" value="<?= htmlspecialchars($u['kuerzel'] ?? '') ?>" maxlength="5" style="width:60px; text-transform:uppercase;" required></td>
                
                <td>
                    <select name="role" style="padding: 2px;">
                        <option value="admin" <?= $u['role']=='admin'?'selected':'' ?>>Admin</option>
                        <option value="user" <?= $u['role']=='user'?'selected':'' ?>>User</option>
                        <option value="viewer" <?= $u['role']=='viewer'?'selected':'' ?>>Viewer</option>
                    </select>
                </td>
                
                <td>
                    <div style="display:flex; gap:5px;">
                        <input type="password" name="new_password" placeholder="Passwort neu" style="width:120px;">
                        <button type="submit" class="btn-update">Speichern</button>
                    </div>
                </td>
            </form>
            <td>
                <?php if($u['id'] != $_SESSION['user_id']): ?>
                    <form method="POST" style="display:inline;" data-confirm="Diesen Benutzer wirklich löschen?" data-confirm-title="Benutzer löschen" data-confirm-type="danger">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="del_user" value="<?= $u['id'] ?>">
                                    <button type="submit" style="background:none;border:none;cursor:pointer;color:red;font-size:1.2em;padding:0;">🗑</button>
                                </form>
                <?php else: ?>
                    <small style="color:#999;">(Du)</small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<br>
<div class="tab-marker" data-section="ma-namen" data-desc="Mitarbeiter-Namen je Wochentag und Zeitraum (KW) zuordnen" data-title="Mitarbeiter-Namen & Zeiträume"></div>
    <h3>Mitarbeiter-Namen & Zeiträume</h3>
    <form method="GET" style="margin-bottom: 15px;">
        <strong>Jahr:</strong> 
        <select name="y" onchange="this.form.submit()">
            <?php $nowY=(int)date('Y'); for($y=min($nowY-2,$selectedYear); $y<=max($nowY+4,$selectedYear); $y++): ?><option value="<?= $y ?>" <?= $y==$selectedYear?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
        </select>
    </form>
    <form method="POST" class="form-box">
                        <?= csrf_field() ?>
        <input type="hidden" name="action_tech" value="1">
        <input type="hidden" name="jahr" value="<?= $selectedYear ?>">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <select name="wochentag"><?php foreach($wochentage as $tag): ?><option value="<?= $tag ?>"><?= $tag ?></option><?php endforeach; ?></select>
            <select name="techniker_nummer"><?php for($mn=1;$mn<=getMaxMitarbeiterErlaubt();$mn++): ?><option value="<?= $mn ?>"><?= htmlspecialchars(getLabel('mitarbeiter_'.$mn, 'Mitarbeiter '.$mn)) ?></option><?php endfor; ?></select>
            <input type="text" name="name" placeholder="Name des Mitarbeiters" required>
            <span class="kw-range"><label class="kw-label-sm">KW</label><input type="number" name="kw_start" min="1" max="53" value="1" class="kw-input"><span class="kw-sep">bis</span><input type="number" name="kw_end" min="1" max="53" value="53" class="kw-input"></span>
            <button type="submit" class="btn-add">Hinzufügen</button>
        </div>
    </form>
    <table>
        <thead><tr><th>Tag</th><th>Slot</th><th>Name</th><th>KW</th><th>Aktion</th></tr></thead>
        <tbody>
            <?php foreach($zuordnungen as $z): ?>
            <tr>
                <form method="POST">
                        <?= csrf_field() ?>
                    <input type="hidden" name="action_tech" value="1"><input type="hidden" name="id" value="<?= $z['id'] ?>"><input type="hidden" name="jahr" value="<?= $z['jahr'] ?>">
                    <td><select name="wochentag"><?php foreach($wochentage as $tag): ?><option <?= $tag==$z['wochentag']?'selected':'' ?>><?= $tag ?></option><?php endforeach; ?></select></td>
                    <td><select name="techniker_nummer"><?php for($mn=1;$mn<=getMaxMitarbeiterErlaubt();$mn++): ?><option value="<?= $mn ?>" <?= $z['techniker_nummer']==$mn?'selected':'' ?>><?= htmlspecialchars(getLabel('mitarbeiter_'.$mn, 'Mitarbeiter '.$mn)) ?></option><?php endfor; ?></select></td>
                    <td><input type="text" name="name" value="<?= htmlspecialchars($z['name']) ?>"></td>
                    <td style="white-space:nowrap;"><span class="kw-range"><input type="number" name="kw_start" min="1" max="53" value="<?= $z['kw_start'] ?>" class="kw-input"><span class="kw-sep">bis</span><input type="number" name="kw_end" min="1" max="53" value="<?= $z['kw_end'] ?>" class="kw-input"></span></td>
                    <td><button type="submit" class="btn-update">💾</button> <form method="POST" style="display:inline;" data-confirm="Diesen Eintrag wirklich löschen?" data-confirm-type="danger">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="del_tech" value="<?= $z['id'] ?>">
                                    <button type="submit" style="background:none;border:none;cursor:pointer;color:red;font-size:1.2em;margin-left:10px;padding:0;">🗑</button>
                                </form></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<br>
    <h3>Einsatzarten & Spezifizierungen</h3>
    <form method="POST" class="form-box" style="background: #eef7ff; border-color: #bee5eb;">
                        <?= csrf_field() ?>
        <input type="hidden" name="action_art" value="1">
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <input type="text" name="hauptart" placeholder="Hauptart" required style="width:250px;">
            <input type="text" name="unterart" placeholder="Unterart (optional)" style="width:250px;">
            <button type="submit" class="btn-add" style="background:#0056b3;">Hinzufügen</button>
        </div>
    </form>
    <table>
        <thead><tr><th>Hauptart</th><th>Spezifizierung</th><th>Aktion</th></tr></thead>
        <tbody>
            <?php foreach($einsatzarten as $art): ?>
            <tr>
                <form method="POST">
                        <?= csrf_field() ?>
                    <input type="hidden" name="action_art" value="1"><input type="hidden" name="id" value="<?= $art['id'] ?>">
                    <td><input type="text" name="hauptart" value="<?= htmlspecialchars($art['hauptart']) ?>" style="width:90%; font-weight:bold;"></td>
                    <td><input type="text" name="unterart" value="<?= htmlspecialchars($art['unterart'] ?? '') ?>" style="width:90%;"></td>
                    <td><button type="submit" class="btn-update">💾</button> <form method="POST" style="display:inline;" data-confirm="Diese Einsatzart wirklich löschen?" data-confirm-title="Einsatzart löschen" data-confirm-type="danger">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="del_art" value="<?= $art['id'] ?>">
                                    <button type="submit" style="background:none;border:none;cursor:pointer;color:red;font-size:1.2em;margin-left:10px;padding:0;">🗑</button>
                                </form></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<br>
    <h3>Zusatzoptionen (Checkboxen im Modal)</h3>
    <form method="POST" class="form-box" style="background: #f0fdf4; border-color: #bbf7d0;">
                        <?= csrf_field() ?>
        <input type="hidden" name="action_opt" value="1">
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <input type="text" name="anzeige_name" placeholder="Anzeigename" required style="width:250px;">
            <input type="text" name="spalten_name" placeholder="db_spaltenname" required style="width:200px;">
            <input type="number" name="sortierung" value="10" style="width:60px;">
            <button type="submit" class="btn-add" style="background:var(--green);">Hinzufügen</button>
        </div>
    </form>
    <table>
        <thead><tr><th>Anzeigename</th><th>Datenbank-Spalte</th><th>Sort.</th><th>Aktion</th></tr></thead>
        <tbody>
            <?php foreach($optionen as $o): ?>
            <tr>
                <form method="POST">
                        <?= csrf_field() ?>
                    <input type="hidden" name="action_opt" value="1"><input type="hidden" name="id" value="<?= $o['id'] ?>">
                    <td><input type="text" name="anzeige_name" value="<?= htmlspecialchars($o['anzeige_name']) ?>" style="width:90%;"></td>
                    <td><code><?= htmlspecialchars($o['spalten_name']) ?></code></td>
                    <td><input type="number" name="sortierung" value="<?= $o['sortierung'] ?>" style="width:50px;"></td>
                    <td>
                        <button type="submit" class="btn-update">💾</button> 
                        <form method="POST" style="display:inline;" data-confirm="Diese Zusatzoption wirklich entfernen?" data-confirm-title="Option entfernen" data-confirm-type="danger">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="del_opt" value="<?= $o['id'] ?>">
                                    <button type="submit" style="background:none;border:none;cursor:pointer;color:red;font-size:1.2em;margin-left:10px;padding:0;">🗑</button>
                                </form>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
			
			<script>
                function toggleDetails(id) {
                    var element = document.getElementById(id);
                    if (element.style.display === "table-row") {
                        element.style.display = "none";
                    } else {
                        element.style.display = "table-row";
                    }
                }
            </script>
        </tbody>
    </table>
	<br>
<div class="tab-marker" data-section="duplikate" data-desc="Doppelt erfasste Termine aufspüren" data-title="Duplikate finden"></div>
<h3 id="dup-check" style="border-left-color: var(--brand); margin-top: 40px;">🔍 Duplikate finden (Doppelte Einträge)</h3>
    <div style="background: #eef7ff; border: 1px solid #bee5eb; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
        <p style="margin-top:0; font-size: 0.9em;">Sucht nach Terminen im gleichen Jahr, bei denen die gewählten Felder identisch sind.</p>
        
        <form method="GET" action="admin.php#dup-check">
            <input type="hidden" name="y" value="<?= htmlspecialchars($selectedYear) ?>">
            
            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">
                <div>
                    <label><strong>Prüf-Jahr:</strong></label><br>
                    <input type="number" name="dup_year" value="<?= htmlspecialchars($dupYear) ?>" style="width: 85px; padding: 5px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div>
                    <label><strong>Vergleichen nach:</strong></label><br>
                    <label style="display:block; margin-bottom:3px;"><input type="checkbox" name="compare[]" value="name" <?= in_array('name', $compareFields) ? 'checked' : '' ?>> Name</label>
                    <label style="display:block; margin-bottom:3px;"><input type="checkbox" name="compare[]" value="strasse" <?= in_array('strasse', $compareFields) ? 'checked' : '' ?>> Straße</label>
                    <label style="display:block; margin-bottom:3px;"><input type="checkbox" name="compare[]" value="plz" <?= in_array('plz', $compareFields) ? 'checked' : '' ?>> PLZ</label>
                    <label style="display:block; margin-bottom:3px;"><input type="checkbox" name="compare[]" value="telefon1" <?= in_array('telefon1', $compareFields) ? 'checked' : '' ?>> Telefon 1</label>
                </div>
                <div>
                    <br>
                    <button type="submit" name="run_dup_check" value="1" style="background: #0056b3; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold;">
                        🔍 Jetzt prüfen
                    </button>
                </div>
            </div>
        </form>

        <?php if (isset($_GET['run_dup_check'])): ?>
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #bee5eb;">
            <h4 style="margin-bottom:10px;">Ergebnisse für <?= htmlspecialchars($dupYear) ?>:</h4>
            
            <?php if (empty($duplicates)): ?>
                <p style="color: #28a745; font-weight: bold; background: white; padding: 10px; border-radius: 4px; border: 1px solid #28a745;">✔ Keine Duplikate mit diesen Kriterien gefunden.</p>
            <?php else: ?>
                <table style="background: white; width: 100%; border-collapse: collapse; margin-top: 5px;">
                    <thead>
                        <tr style="background: #f4f4f4;">
                            <th style="border: 1px solid #dee2e6; padding: 10px; width: 40%;">Identische Daten</th>
                            <th style="border: 1px solid #dee2e6; padding: 10px; text-align:center; width: 10%;">Anzahl</th>
                            <th style="border: 1px solid #dee2e6; padding: 10px; width: 50%;">Termine öffnen (Woche/Jahr)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($duplicates as $row): ?>
                            <tr>
                                <td style="border: 1px solid #dee2e6; padding: 10px; line-height: 1.4;">
                                    <?php foreach ($compareFields as $f) {
                                        echo "<span style='font-size:0.75em; color:#666; text-transform:uppercase;'>".htmlspecialchars($f).":</span> <strong>".htmlspecialchars($row[$availableFields[$f]])."</strong><br>";
                                    } ?>
                                </td>
                                <td style="border: 1px solid #dee2e6; padding: 10px; text-align:center; font-weight:bold; font-size: 1.3em; color: var(--brand);"><?= $row['anzahl'] ?></td>
                                <td style="border: 1px solid #dee2e6; padding: 10px;">
                                    <?php 
                                    $items = explode(';', $row['termin_infos']);
                                    foreach ($items as $item) {
                                        list($tId, $tDatum) = explode('|', $item);
                                        $d = new DateTime($tDatum);
                                        $w = $d->format('W');
                                        $y = $d->format('o');
                                        echo "<a href='index.php?w=$w&y=$y&open_id=$tId' target='_blank' style='display: inline-block; padding: 4px 10px; background: #e3f2fd; color: #1976d2; text-decoration: none; border-radius: 4px; border: 1px solid #bbdefb; font-size: 0.85em; margin: 3px; font-weight: bold;'>📅 ".$d->format('d.m.Y')."</a> ";
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
</tbody>
</table>
<br>
<?php
$stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'start_adresse'");
$aktuelleAdresse = $stmt->fetchColumn() ?: "Münsterplatz 1, 89073 Ulm";
?>
<?php
// Status des PLZ-Verzeichnisses ermitteln
try {
    $plzAnzahl = (int)$pdo->query("SELECT COUNT(*) FROM plz_verzeichnis")->fetchColumn();
} catch (Throwable $e) { $plzAnzahl = 0; }
$plzDateiVorhanden = is_file(__DIR__ . '/data/plz_daten.json');
?>
<div class="tab-marker" data-section="plz" data-desc="PLZ-/Ortsdaten für die Adress-Autovervollständigung pflegen" data-title="PLZ-Verzeichnis"></div>
<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-top: 30px; margin-bottom: 30px;">
    <h3 style="margin-top: 0;">📮 PLZ-Verzeichnis (Autovervollständigung)</h3>
    <p style="font-size: 0.9em; color: #666; margin-top: 10px;">
        Grundlage der PLZ-/Orts-Vorschläge bei der Terminanlage. Das komplette deutsche
        PLZ-Verzeichnis wird mitgeliefert (<code>data/plz_daten.json</code>, Quelle: GeoNames.org, Lizenz CC BY 4.0)
        und beim ersten Aufruf automatisch in die Datenbank übernommen.
    </p>
    <p style="font-size: 0.95em;">
        Aktuell in der Datenbank: <strong><?= number_format($plzAnzahl, 0, ',', '.') ?> Einträge</strong>
        <?php if (!$plzDateiVorhanden): ?>
            <br><span style="color:#b91c1c;">⚠️ Die Datei <code>data/plz_daten.json</code> fehlt – Import nicht möglich.</span>
        <?php endif; ?>
    </p>
    <?php if ($plzDateiVorhanden): ?>
    <form method="POST" style="margin:0;"
          data-confirm="Das PLZ-Verzeichnis wird aus <b>data/plz_daten.json</b> neu in die Datenbank importiert.<br><br>Vorhandene Einträge im PLZ-Verzeichnis werden dabei ersetzt (Termine und andere Daten sind nicht betroffen)."
          data-confirm-title="PLZ-Verzeichnis importieren"
          data-confirm-type="warning" data-confirm-ok="Jetzt importieren">
        <?= csrf_field() ?>
        <button type="submit" name="import_plz" class="btn-add" style="background:#007bff; color:white; border:none; padding:8px 16px; border-radius:4px; cursor:pointer;">
            <?= $plzAnzahl > 0 ? 'PLZ-Verzeichnis neu importieren' : 'PLZ-Verzeichnis jetzt importieren' ?>
        </button>
    </form>
    <?php endif; ?>
</div>
<br>
<div class="tab-marker" data-section="routen" data-desc="Startadresse für Routenberechnungen (Google Maps) festlegen" data-title="Routen-Konfiguration"></div>
<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-top: 30px; margin-bottom: 30px;">
    
    <h3 style="margin-top: 0;">📍 Routen-Konfiguration</h3>
    
    <p style="font-size: 0.9em; color: #666; margin-top: 10px;">Diese Adresse wird als Startpunkt für alle Routenberechnungen in Google Maps verwendet.</p>
    
    <form method="POST">
                        <?= csrf_field() ?>
        <input type="text" name="start_adresse" value="<?= htmlspecialchars($aktuelleAdresse) ?>" style="width: 100%; max-width: 400px; padding: 8px; margin-right: 10px;">
        <button type="submit" name="save_maps_config" class="btn-add" style="background:#28a745; color:white; border:none; padding:8px 16px; border-radius:4px; cursor:pointer;">Adresse speichern</button>
    </form>
</div>
<?php

// Aktuellen Footer laden
$stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'footer_text'");
$aktuellerFooter = $stmt->fetchColumn() ?: "Firmenname";
?>
<br>
<div class="tab-marker" data-section="footer" data-desc="Text auf der Login-Seite (z. B. Firmenname) ändern" data-title="Footer-Konfiguration"></div>
<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-top: 30px; margin-bottom: 30px;">
    <h3 style="margin-top: 0;">📄 Footer-Konfiguration</h3>
    
    <p style="font-size: 0.9em; color: #666; margin-top: 10px;">Dieser Text erscheint unten auf der Login-Seite.</p>
    
    <form method="POST">
                        <?= csrf_field() ?>
        <input type="text" name="footer_text" value="<?= htmlspecialchars($aktuellerFooter) ?>" style="width: 100%; max-width: 400px; padding: 8px; margin-right: 10px;">
        <button type="submit" name="save_footer_config" class="btn-add" style="background:#28a745; color:white; border:none; padding:8px 16px; border-radius:4px; cursor:pointer;">Speichern</button>
    </form>
</div>
<br>
<div class="tab-marker" data-section="texte" data-desc="Impressum direkt im Browser bearbeiten" data-title="Website-Texte"></div>
<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;margin-top:30px;margin-bottom:30px;">
    <h3 style="margin-top:0;">⚙️ Website-Texte</h3>
    <p>Hier kannst du das Impressum direkt im Browser bearbeiten:</p>
    <a href="edit_impressum.php" class="btn-add" style="display:inline-block; text-decoration:none; background:#007bff; color:white; padding:10px 20px; border-radius:6px;">Impressum bearbeiten</a>
</div>
<br>
<div class="tab-marker" data-section="bezeichnungen" data-desc="Anzeigenamen „Mitarbeiter 1–4“ umbenennen" data-title="Mitarbeiter-Bezeichnungen"></div>
<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;margin-top:30px;margin-bottom:30px;">
    <h3 style="margin-top:0;">🏷️ Mitarbeiter-Bezeichnungen</h3>
    <p>Hier kannst du die Anzeigenamen für die Mitarbeiter im Einsatzplan anpassen:</p>
    <a href="label_settings.php" class="btn-add" style="display:inline-block; text-decoration:none; background:#007bff; color:white; padding:10px 20px; border-radius:6px;">Bezeichnungen bearbeiten</a>
</div>
<br>
<div class="tab-marker" data-section="wochenende" data-desc="Samstag/Sonntag im Plan ein- oder ausblenden" data-title="Wochenend-Anzeige"></div>
<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;margin-top:30px;margin-bottom:30px;">
    <h3 style="margin-top:0;">Wochenend-Anzeige</h3>
    <p style="font-size:0.9em;color:#666;margin-top:10px;">
        Hier kannst du festlegen, ob Samstag und Sonntag im Einsatzplan zusätzlich angezeigt werden.
    </p>

    <form method="POST">
                        <?= csrf_field() ?>
        <label style="display:flex;align-items:center;gap:10px;font-weight:bold;">
            <input type="checkbox" name="wochenende_anzeigen" value="1"
                <?php echo $wochenendeAnzeigen === '1' ? 'checked' : ''; ?>>
            Samstag und Sonntag im Plan anzeigen
        </label>

        <button type="submit" name="saveweekendconfig" class="btn-add" style="margin-top:15px;">
            Speichern
        </button>
    </form>
</div>

<div class="tab-marker" data-section="ma-pro-tag" data-desc="Mitarbeiter-Anzahl je Wochentag; QR-Codes der Mobil-Seiten" data-title="Mitarbeiter pro Tag"></div>
<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;margin-top:30px;margin-bottom:30px;">
    <h3 style="margin-top:0;">👥 Mitarbeiter pro Tag
        <span class="info-icon" tabindex="0" title="Info zur Erreichbarkeit">ℹ️
            <span class="info-pop">
                <strong>Mobile Mitarbeiter-Seiten &amp; QR-Codes</strong><br><br>
                Es gibt eine <strong>Gesamtübersicht</strong> (QR-Code unten), die auf alle
                Tage und Mitarbeiter verlinkt – ideal für dich als Planer. Zusätzlich hat
                jeder Mitarbeiter-Platz einen <strong>eigenen QR-Code</strong>, der direkt
                nur zu seiner Tagesseite führt. So kannst du einem Mitarbeiter gezielt nur
                seinen eigenen Plan geben, ohne ihm alle anderen Tage zu zeigen.
                Jede Seite zeigt die Termine Woche für Woche durchwischbar und lässt sich
                als Symbol auf den Startbildschirm legen.<br><br>
                <strong>Wichtig zur Erreichbarkeit:</strong> Das funktioniert unterwegs
                nur, wenn der Einsatzplan aus dem Internet erreichbar ist. Liegt der
                Plan aus Datenschutzgründen geschützt im firmeneigenen Netzwerk
                (z.&nbsp;B. auf einem internen Linux-Server), brauchen die Dienst-Handys
                bzw. -Laptops einen gesicherten Zugang von außen – etwa per VPN –,
                um die Daten unterwegs abrufen zu können.<br><br>
                Ein Login bleibt immer nötig. Nach dem ersten Login auf dem Gerät
                ist für rund 30 Tage keine erneute Anmeldung erforderlich.
            </span>
        </span>
    </h3>
    <p style="font-size:0.9em;color:#666;margin-top:10px;">
        Lege für jeden Wochentag fest, wie viele Mitarbeiter nebeneinander geplant
        werden (1 bis <?= getMaxMitarbeiterErlaubt() ?>).
        So kannst du z.&nbsp;B. montags mit zwei und freitags mit
        mehr Mitarbeitern planen. Bereits eingetragene Termine bleiben erhalten –
        wird ein Tag verkleinert, werden Termine höherer Spalten nur ausgeblendet,
        nicht gelöscht, und erscheinen wieder, sobald der Tag erneut vergrößert wird.
    </p>

    <!-- Gesamtübersicht: verlinkt auf alle mobilen Seiten -->
    <div class="qr-uebersicht" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;
         background:var(--surface-alt); border:1px solid var(--line); border-radius:10px;
         padding:14px 16px; margin:6px 0 20px;">
        <div id="qrUebersicht" style="background:#fff; padding:6px; border:1px solid #e0e0e0; border-radius:8px; flex-shrink:0;"></div>
        <div>
            <div style="font-weight:700; font-size:15px; margin-bottom:4px;">📱 Gesamtübersicht (alle Mitarbeiter &amp; Tage)</div>
            <div style="font-size:0.88em; color:#666; margin-bottom:6px;">
                Für dich als Planer: Diese Seite verlinkt auf alle Tages- und Mitarbeiter-Seiten.
            </div>
            <a id="qrUebersichtLink" href="#" target="_blank" style="font-size:0.85em; color:#2f5eb3;">Übersicht öffnen</a>
        </div>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <div style="display:flex; flex-wrap:wrap; gap:14px; margin-top:12px;">
            <?php
            $farbVorschau = [];
            for ($fv = 1; $fv <= MAX_MITARBEITER; $fv++) { $farbVorschau[$fv] = "var(--t{$fv})"; }
            $maxErlaubt = getMaxMitarbeiterErlaubt();
            $alleTageForm = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
            // Kürzel für die Mobil-Dateinamen
            $tagKuerzel = ['Montag'=>'mo','Dienstag'=>'di','Mittwoch'=>'mi','Donnerstag'=>'do',
                           'Freitag'=>'fr','Samstag'=>'sa','Sonntag'=>'so'];
            // Basis-URL zum mobil-Ordner (für QR-Codes / Links)
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basisDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $mobilBasis = $scheme . '://' . $host . $basisDir . '/mobil/';
            foreach ($alleTageForm as $tag):
                $aktuell = $mitarbeiterProTag[$tag] ?? DEFAULT_MITARBEITER;
            ?>
            <div style="border:1px solid var(--line); border-radius:8px; padding:10px 12px; min-width:200px; flex:1 1 200px;">
                <label style="display:block; font-weight:700; margin-bottom:6px;"><?= htmlspecialchars($tag) ?></label>
                <select name="mitarbeiter_anzahl[<?= htmlspecialchars($tag) ?>]"
                        style="width:100%; padding:6px; margin-bottom:8px;"
                        data-tag="<?= htmlspecialchars($tag) ?>"
                        data-kuerzel="<?= $tagKuerzel[$tag] ?>"
                        onchange="updateFarbVorschau(this); renderQrFor(this)">
                    <?php for ($n = 1; $n <= $maxErlaubt; $n++): ?>
                        <option value="<?= $n ?>" <?= $n === $aktuell ? 'selected' : '' ?>>
                            <?= $n ?> Mitarbeiter
                        </option>
                    <?php endfor; ?>
                </select>
                <div class="farb-vorschau" style="display:flex; gap:4px;">
                    <?php for ($n = 1; $n <= $maxErlaubt; $n++): ?>
                        <span data-stufe="<?= $n ?>"
                              style="flex:1; height:8px; border-radius:2px; background:<?= $farbVorschau[$n] ?>;
                                     opacity:<?= $n <= $aktuell ? '1' : '0.15' ?>;"></span>
                    <?php endfor; ?>
                </div>
                <!-- QR-Codes je eingeplantem Mitarbeiter -->
                <div class="qr-liste" data-kuerzel="<?= $tagKuerzel[$tag] ?>" data-anzahl="<?= (int)$aktuell ?>"
                     style="margin-top:12px; display:flex; flex-wrap:wrap; gap:10px;"></div>
            </div>
            <?php endforeach; ?>
        </div>

        <button type="submit" name="save_mitarbeiter_anzahl" class="btn-add" style="margin-top:18px;">
            Speichern
        </button>
    </form>

    <div style="margin-top:18px; padding-top:16px; border-top:1px solid var(--line);">
        <form method="POST" style="display:inline;"
              onsubmit="return confirm('QR-Codes wirklich erneuern? Es wird ein neuer Zugriffs-Token erzeugt.');">
            <?= csrf_field() ?>
            <button type="submit" name="renew_qr_token" class="btn-secondary" style="font-size:0.9em;">
                🔄 QR-Codes erneuern
            </button>
        </form>
        <span style="font-size:0.82em; color:#888; margin-left:10px;">
            Erzeugt einen neuen Zugriffs-Token – nützlich, um alte QR-Codes turnusmäßig auszutauschen.
        </span>
    </div>

    <!-- Schlanke QR-Bibliothek (lokal, kein externer Dienst) -->
    <script src="qrcode.min.js"></script>
    <script>
        const MOBIL_BASIS = <?= json_encode($mobilBasis) ?>;
        const QR_TOKEN    = <?= json_encode($qrToken) ?>;
        const MA_LABELS   = <?= json_encode(array_map(fn($n) => getLabel('mitarbeiter_'.$n, 'Mitarbeiter '.$n), range(1, getMaxMitarbeiterErlaubt()))) ?>;

        function mobilUrl(kuerzel, ma) {
            return MOBIL_BASIS + kuerzel + '_' + ma + '.php?t=' + encodeURIComponent(QR_TOKEN);
        }

        function renderQrList(container) {
            const kuerzel = container.getAttribute('data-kuerzel');
            const anzahl  = parseInt(container.getAttribute('data-anzahl'), 10) || 0;
            container.innerHTML = '';
            for (let ma = 1; ma <= anzahl; ma++) {
                const url = mobilUrl(kuerzel, ma);
                const box = document.createElement('div');
                box.style.cssText = 'text-align:center; font-size:11px; color:#555;';
                const qrDiv = document.createElement('div');
                qrDiv.style.cssText = 'background:#fff; padding:4px; border:1px solid #e0e0e0; border-radius:6px; display:inline-block;';
                box.appendChild(qrDiv);
                const label = document.createElement('div');
                label.textContent = MA_LABELS[ma-1] || ('Mitarbeiter ' + ma);
                label.style.cssText = 'margin-top:4px; font-weight:600; max-width:90px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;';
                box.appendChild(label);
                const link = document.createElement('a');
                link.href = url; link.target = '_blank';
                link.textContent = 'Link öffnen';
                link.style.cssText = 'display:block; font-size:10px; color:#2f5eb3; margin-top:2px;';
                box.appendChild(link);
                container.appendChild(box);
                try {
                    new QRCode(qrDiv, { text: url, width: 84, height: 84, correctLevel: QRCode.CorrectLevel.M });
                } catch (e) {
                    qrDiv.textContent = 'QR-Fehler';
                }
            }
        }

        // Beim Ändern der Anzahl die QR-Liste des Tages aktualisieren
        function renderQrFor(sel) {
            const box = sel.closest('div').querySelector('.qr-liste');
            if (box) { box.setAttribute('data-anzahl', sel.value); renderQrList(box); }
        }

        // Übersichts-QR-Code (verlinkt auf die Gesamtübersicht)
        function renderUebersichtQr() {
            const box = document.getElementById('qrUebersicht');
            if (!box) return;
            const url = MOBIL_BASIS + 'index.php?t=' + encodeURIComponent(QR_TOKEN);
            const link = document.getElementById('qrUebersichtLink');
            if (link) link.href = url;
            box.innerHTML = '';
            try {
                new QRCode(box, { text: url, width: 110, height: 110, correctLevel: QRCode.CorrectLevel.M });
            } catch (e) { box.textContent = 'QR-Fehler'; }
        }

        document.addEventListener('DOMContentLoaded', function () {
            renderUebersichtQr();
            document.querySelectorAll('.qr-liste').forEach(renderQrList);
        });
    </script>
</div>

<script>
// Farbvorschau live an die gewählte Mitarbeiterzahl anpassen
function updateFarbVorschau(sel) {
    var anzahl = parseInt(sel.value, 10);
    var box = sel.closest('div');
    box.querySelectorAll('.farb-vorschau span').forEach(function (s) {
        var stufe = parseInt(s.getAttribute('data-stufe'), 10);
        s.style.opacity = (stufe <= anzahl) ? '1' : '0.15';
    });
}

// Scroll-Position über das Speichern hinweg erhalten:
// Vor jedem Absenden merken, nach dem Neuladen wiederherstellen. So landet man
// nach "Speichern" wieder an der bearbeiteten Stelle statt ganz oben.
(function () {
    var KEY = 'adminScrollY';
    document.addEventListener('submit', function () {
        try { sessionStorage.setItem(KEY, String(window.scrollY)); } catch (e) {}
    }, true);
    window.addEventListener('load', function () {
        try {
            var y = sessionStorage.getItem(KEY);
            if (y !== null) {
                sessionStorage.removeItem(KEY);
                // erst nach dem Rendern springen, damit die Position stimmt
                window.requestAnimationFrame(function () {
                    window.scrollTo(0, parseInt(y, 10) || 0);
                });
            }
        } catch (e) {}
    });
})();

// ── Navigations-Dropdown für den Admin-Bereich ───────────────────────────────
// Jede Sektion (per data-section-Marker) ist ein eigener Menüpunkt. Zusätzlich
// stehen die externen Verwaltungsseiten (Wochenend-Korrektur etc.) im selben
// Menü. Beim Öffnen ist nichts gewählt; ein Hinweis fordert zur Auswahl auf.
// Die gewählte Sektion bleibt über das Speichern hinweg erhalten.
var toggleAdminMenu; // global für onclick
document.addEventListener('DOMContentLoaded', function () {
    var SEC_KEY = 'adminActiveSection';

    var menu   = document.getElementById('adminDropdownMenu');
    var btn    = document.getElementById('adminDropdownBtn');
    var label  = document.getElementById('adminDropdownLabel');
    var dropdown = btn ? btn.closest('.admin-dropdown') : null;
    var hint   = document.getElementById('adminTabHint');
    if (!menu || !dropdown) return;

    // 1) Sektionsblöcke sammeln: jeder Marker + alle folgenden Geschwister bis
    //    zum nächsten Marker gehören zu einer Sektion.
    var marker = Array.prototype.slice.call(document.querySelectorAll('.tab-marker'));
    var sektionen = [];   // [{id, titel, elemente:[]}]
    marker.forEach(function (m) {
        var id = m.getAttribute('data-section');
        var titel = m.getAttribute('data-title') || id;
        var desc  = m.getAttribute('data-desc') || '';
        var elemente = [];
        var el = m.nextElementSibling;
        while (el && !el.classList.contains('tab-marker')) {
            elemente.push(el);
            el = el.nextElementSibling;
        }
        m.style.display = 'none';
        if (id) sektionen.push({ id: id, titel: titel, desc: desc, elemente: elemente });
    });

    // 2) Alle Sektionen zunächst ausblenden. Danach übernimmt das Inline-Styling
    //    die Kontrolle; die CSS-Blanko-Regel (FOUC-Schutz) wird abgeschaltet.
    sektionen.forEach(function (s) {
        s.elemente.forEach(function (el) { el.style.display = 'none'; });
    });
    document.body.classList.add('admin-js');

    function zeige(id) {
        var gewaehlt = null;
        sektionen.forEach(function (s) {
            var an = (s.id === id);
            if (an) gewaehlt = s;
            s.elemente.forEach(function (el) { el.style.display = an ? '' : 'none'; });
        });
        Array.prototype.forEach.call(menu.querySelectorAll('.admin-menu-item[data-section]'), function (mi) {
            mi.classList.toggle('active', mi.getAttribute('data-section') === id);
        });
        if (gewaehlt && label) label.textContent = gewaehlt.titel;
        if (hint) hint.style.display = 'none';
        dropdown.classList.remove('open');
        try { sessionStorage.setItem(SEC_KEY, id); } catch (e) {}
    }

    // Hilfsfunktion: Menüpunkt mit Titel und kleiner Beschreibungszeile füllen
    function fuelleMenuItem(item, titel, desc) {
        var t = document.createElement('span');
        t.className = 'ami-title';
        t.textContent = titel;
        item.appendChild(t);
        if (desc) {
            var d = document.createElement('span');
            d.className = 'ami-desc';
            d.textContent = desc;
            item.appendChild(d);
        }
    }

    // 3) Menüpunkte erzeugen – erst alle Sektionen, dann Trenner, dann externe Links
    sektionen.forEach(function (s) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'admin-menu-item';
        item.setAttribute('data-section', s.id);
        fuelleMenuItem(item, s.titel, s.desc);
        item.addEventListener('click', function () { zeige(s.id); });
        menu.appendChild(item);
    });

    var sep = document.createElement('div');
    sep.className = 'admin-menu-sep';
    menu.appendChild(sep);

    var externe = [
        ['wochenende.php',   'Wochenend-Korrektur',      'Fehleinträge an Samstagen/Sonntagen finden und korrigieren'],
        ['datenbank.php',    'Datenbank-Manager',        'Tabellen einsehen, leeren und Inhalte prüfen'],
        ['mysql_setup.php',  'DB-Konfiguration (MySQL)', 'Von SQLite auf MySQL/MariaDB umstellen (Migration)']
    ];
    externe.forEach(function (paar) {
        var a = document.createElement('a');
        a.className = 'admin-menu-item admin-menu-extern';
        a.href = paar[0];
        fuelleMenuItem(a, paar[1], paar[2]);
        menu.appendChild(a);
    });

    // Menü öffnen/schließen
    toggleAdminMenu = function (ev) {
        if (ev) ev.stopPropagation();
        dropdown.classList.toggle('open');
    };
    document.addEventListener('click', function (ev) {
        if (!dropdown.contains(ev.target)) dropdown.classList.remove('open');
    });

    // 4) Nach einem Speichern dieselbe Sektion wieder aktivieren; sonst Hinweis.
    var warSpeichern = false;
    try { warSpeichern = sessionStorage.getItem('adminSaved') === '1'; } catch (e) {}
    var gewuenscht = null;
    try { gewuenscht = sessionStorage.getItem(SEC_KEY); } catch (e) {}
    var existiert = sektionen.some(function (s) { return s.id === gewuenscht; });

    if (warSpeichern && gewuenscht && existiert) {
        try { sessionStorage.removeItem('adminSaved'); } catch (e) {}
        zeige(gewuenscht);
    } else {
        if (hint) hint.style.display = '';
    }

    document.addEventListener('submit', function () {
        try { sessionStorage.setItem('adminSaved', '1'); } catch (e) {}
    }, true);
});
</script>

<?php $aktuellePflichtfelder = get_required_termin_felder($pdo); ?>
<div class="tab-marker" data-section="pflichtfelder" data-desc="Pflichtfelder für das Buchungsformular festlegen" data-title="Pflichtfelder im Buchungsformular"></div>
<div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;margin-top:30px;margin-bottom:30px;">
    <h3 style="margin-top:0;">📋 Pflichtfelder im Buchungsformular</h3>
    <p style="font-size:0.9em;color:#666;margin-top:10px;">
        Standardmäßig ist beim Anlegen eines Termins <strong>kein</strong> Feld verpflichtend auszufüllen.
        Hier legst du fest, welche Felder im Buchungsfenster mit einem roten Stern markiert und vor dem
        Speichern zwingend ausgefüllt sein müssen (die Prüfung erfolgt sowohl im Formular als auch serverseitig).
    </p>
    <form method="POST">
        <?= csrf_field() ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); gap:10px 16px; margin:15px 0;">
            <?php foreach (terminfeld_katalog() as $feldName => $label): ?>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
                    <input type="checkbox" name="required_felder[]" value="<?= htmlspecialchars($feldName) ?>"
                        <?= in_array($feldName, $aktuellePflichtfelder, true) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" name="save_required_felder" class="btn-add" style="margin-top:5px;">
            Pflichtfelder speichern
        </button>
    </form>
</div>
</div>
<?php include 'footer.php'; ?> <script src="<?= asset('modal.js') ?>"></script>
</body>
</html>
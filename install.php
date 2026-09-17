<?php
// ═════════════════════════════════════════════════════════════════════════════
// EINSATZPLAN – Geführter Web-Installer
//
// Der Kunde lädt alle Dateien auf seinen Webspace und ruft diese Datei einmal
// im Browser auf (z.B. https://mein-server.de/install.php). Der Assistent führt
// durch: Systemcheck → Datenbank → Admin-Konto → Firma → Fertig.
//
// Nach Abschluss sperrt sich der Installer selbst (Marker data/.installed) und
// verweigert weiteren Zugriff. Aus Sicherheitsgründen sollte install.php danach
// vom Server gelöscht werden – der Assistent weist am Ende darauf hin.
// ═════════════════════════════════════════════════════════════════════════════

session_start();
header('Content-Type: text/html; charset=utf-8');

$ROOT       = __DIR__;
$DATA_DIR   = $ROOT . '/data';
$MARKER     = $DATA_DIR . '/.installed';
$DBCONFIG   = $DATA_DIR . '/db_config.php';
$SQLITEFILE = $DATA_DIR . '/einsatzplan.sqlite';

// ── Ist bereits installiert? ────────────────────────────────────────────────
$bereitsInstalliert = file_exists($MARKER);

// Erlaubt trotzdem einen "Neu starten"-Aufruf nur, wenn ausdrücklich gewünscht
if ($bereitsInstalliert && !isset($_GET['show'])) {
    ?>
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
    <title>Einsatzplan – bereits installiert</title>
    <?php echo installer_styles(); ?></head><body>
    <div class="wrap"><div class="card">
        <div class="logo">EINSATZPLAN</div>
        <h1>Bereits installiert</h1>
        <p>Diese Installation wurde bereits abgeschlossen. Aus Sicherheitsgründen ist der
        Installer deaktiviert.</p>
        <p class="warn">Bitte löschen Sie die Datei <code>install.php</code> von Ihrem Server.</p>
        <a class="btn" href="index.php">Zur Anwendung</a>
    </div></div></body></html>
    <?php
    exit;
}

$schritt = (int)($_GET['schritt'] ?? 1);
$fehler  = [];
$erfolg  = [];

// ─────────────────────────────────────────────────────────────────────────────
// SYSTEMCHECK
// ─────────────────────────────────────────────────────────────────────────────
function systemcheck($ROOT, $DATA_DIR) {
    $checks = [];
    // PHP-Version
    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $checks[] = ['PHP-Version ' . PHP_VERSION, $phpOk, $phpOk ? '' : 'PHP 8.0 oder neuer wird benötigt.'];
    // PDO
    $checks[] = ['PDO-Erweiterung', extension_loaded('pdo'), 'Die PHP-Erweiterung PDO fehlt.'];
    // pdo_sqlite
    $sqliteOk = extension_loaded('pdo_sqlite');
    $checks[] = ['SQLite-Unterstützung (pdo_sqlite)', $sqliteOk, 'Für die einfache Variante (SQLite) wird pdo_sqlite benötigt.'];
    // pdo_mysql (optional)
    $mysqlOk = extension_loaded('pdo_mysql');
    $checks[] = ['MySQL-Unterstützung (pdo_mysql)', $mysqlOk, 'Nur nötig, wenn Sie MySQL statt SQLite nutzen möchten.', true];
    // mbstring
    $checks[] = ['Zeichen-Erweiterung (mbstring)', extension_loaded('mbstring'), 'Empfohlen für korrekte Umlaut-Darstellung.', true];
    // data/ schreibbar
    $dataWritable = is_writable($DATA_DIR) || (is_dir($DATA_DIR) && @touch($DATA_DIR . '/.probe') && @unlink($DATA_DIR . '/.probe'));
    $checks[] = ['Ordner <code>data/</code> beschreibbar', $dataWritable, 'Der Webserver braucht Schreibrechte im Ordner data/. Bitte per FTP auf 755 oder 775 setzen.'];
    // Wurzel schreibbar (für column_settings.json)
    $rootWritable = is_writable($ROOT);
    $checks[] = ['Hauptordner beschreibbar', $rootWritable, 'Empfohlen, damit Spalten-Markierungen gespeichert werden können.', true];

    return $checks;
}

// ─────────────────────────────────────────────────────────────────────────────
// SCHRITT-VERARBEITUNG (POST)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';

    // ── DB-Auswahl verarbeiten ──────────────────────────────────────────────
    if ($aktion === 'datenbank') {
        $driver = $_POST['driver'] ?? 'sqlite';
        if ($driver === 'sqlite') {
            // db_config.php für SQLite schreiben (oder gar keine – SQLite ist Default)
            $cfg = "<?php\nreturn ['driver' => 'sqlite', 'mysql' => []];\n";
            if (@file_put_contents($DBCONFIG, $cfg) === false) {
                $fehler[] = 'Konnte <code>data/db_config.php</code> nicht schreiben. Bitte Schreibrechte im Ordner data/ prüfen.';
            } else {
                $_SESSION['inst_driver'] = 'sqlite';
                header('Location: install.php?schritt=3'); exit;
            }
        } else {
            // MySQL: Verbindung testen
            $m = [
                'host'   => trim($_POST['db_host'] ?? 'localhost'),
                'port'   => (int)($_POST['db_port'] ?? 3306),
                'dbname' => trim($_POST['db_name'] ?? ''),
                'user'   => trim($_POST['db_user'] ?? ''),
                'pass'   => $_POST['db_pass'] ?? '',
            ];
            try {
                $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['dbname']};charset=utf8mb4";
                $test = new PDO($dsn, $m['user'], $m['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                // Erfolg → Konfiguration schreiben
                $cfg = "<?php\nreturn " . var_export(['driver' => 'mysql', 'mysql' => $m], true) . ";\n";
                if (@file_put_contents($DBCONFIG, $cfg) === false) {
                    $fehler[] = 'Verbindung ok, aber <code>data/db_config.php</code> konnte nicht geschrieben werden. Schreibrechte im Ordner data/ prüfen.';
                } else {
                    $_SESSION['inst_driver'] = 'mysql';
                    header('Location: install.php?schritt=3'); exit;
                }
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $hint = 'Bitte Zugangsdaten prüfen.';
                if (stripos($msg, 'Access denied') !== false)      $hint = 'Benutzername oder Passwort falsch.';
                elseif (stripos($msg, 'Unknown database') !== false) $hint = 'Die angegebene Datenbank existiert nicht. Bitte im Hosting-Panel anlegen.';
                elseif (stripos($msg, 'getaddrinfo') !== false || stripos($msg, 'refused') !== false) $hint = 'Host oder Port nicht erreichbar.';
                $fehler[] = 'MySQL-Verbindung fehlgeschlagen: ' . $hint;
            }
        }
        $schritt = 2;
    }

    // ── Admin-Konto verarbeiten ─────────────────────────────────────────────
    if ($aktion === 'admin') {
        require_once $ROOT . '/config.php';   // stellt $pdo bereit (nutzt db_config.php)
        $username = trim($_POST['username'] ?? '');
        $pass1    = $_POST['password'] ?? '';
        $pass2    = $_POST['password2'] ?? '';
        $kuerzel  = strtoupper(trim($_POST['kuerzel'] ?? ''));
        if ($kuerzel === '') $kuerzel = strtoupper(substr($username, 0, 3));

        if ($username === '' || $pass1 === '') {
            $fehler[] = 'Bitte Benutzername und Passwort ausfüllen.';
        } elseif ($pass1 !== $pass2) {
            $fehler[] = 'Die beiden Passwörter stimmen nicht überein.';
        } elseif (strlen($pass1) < 6) {
            $fehler[] = 'Das Passwort sollte mindestens 6 Zeichen haben.';
        } else {
            try {
                // Gibt es schon einen Admin? (z.B. bei Wiederholung)
                $anzahl = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                if ($anzahl > 0) {
                    $_SESSION['inst_admin_ok'] = true;
                    header('Location: install.php?schritt=4'); exit;
                }
                $hash = password_hash($pass1, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, kuerzel) VALUES (?, ?, 'admin', ?)");
                $stmt->execute([$username, $hash, $kuerzel]);
                $_SESSION['inst_admin_ok'] = true;
                header('Location: install.php?schritt=4'); exit;
            } catch (Exception $e) {
                $fehler[] = 'Konnte den Admin nicht anlegen: ' . htmlspecialchars($e->getMessage());
            }
        }
        $schritt = 3;
    }

    // ── Firma / Grundeinstellungen verarbeiten ──────────────────────────────
    if ($aktion === 'firma') {
        require_once $ROOT . '/config.php';
        $firma = trim($_POST['firma'] ?? '');
        try {
            if ($firma !== '') {
                $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('footer_text', ?)");
                $stmt->execute([$firma]);
            }
            // Installation abschließen: Marker setzen
            @file_put_contents($MARKER, date('c') . "\n");
            $_SESSION['inst_fertig'] = true;
            header('Location: install.php?schritt=5'); exit;
        } catch (Exception $e) {
            $fehler[] = 'Konnte die Einstellungen nicht speichern: ' . htmlspecialchars($e->getMessage());
            $schritt = 4;
        }
    }
}

// Für Schritt 3+ muss config.php ladbar sein und die DB initialisiert werden
if ($schritt >= 3) {
    require_once $ROOT . '/config.php';  // initialisiert Tabellen via init_db.php
}

// ─────────────────────────────────────────────────────────────────────────────
// AUSGABE
// ─────────────────────────────────────────────────────────────────────────────
function installer_styles() {
    return '<style>
        :root { --brand:#c8102e; --brand-dark:#a50e26; --ink:#1d2733; --soft:#6b7480; --line:#e2e6ea; }
        * { box-sizing:border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#eef1f5; color:var(--ink); margin:0; padding:20px; }
        .wrap { max-width:640px; margin:30px auto; }
        .card { background:#fff; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,0.08); padding:32px 34px; }
        .logo { color:var(--brand); font-weight:800; font-size:22px; letter-spacing:1px; margin-bottom:4px; }
        h1 { font-size:20px; margin:6px 0 18px; }
        p { line-height:1.55; }
        .steps { display:flex; gap:6px; margin-bottom:26px; }
        .steps .s { flex:1; height:6px; border-radius:3px; background:var(--line); }
        .steps .s.done { background:var(--brand); }
        .steps .s.now { background:var(--brand); opacity:0.5; }
        label { display:block; font-weight:600; font-size:14px; margin:14px 0 4px; }
        input[type=text], input[type=password], input[type=number] {
            width:100%; padding:11px 13px; border:1px solid #ccd2d8; border-radius:8px; font-size:15px;
        }
        input:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(200,16,46,0.12); }
        .btn { display:inline-block; background:var(--brand); color:#fff; border:none; border-radius:9px;
            padding:12px 22px; font-size:15px; font-weight:700; cursor:pointer; text-decoration:none; margin-top:22px; }
        .btn:hover { background:var(--brand-dark); }
        .btn.secondary { background:#fff; color:var(--ink); border:1px solid #ccd2d8; }
        .row { display:flex; gap:12px; align-items:center; justify-content:space-between; margin-top:22px; }
        .check { display:flex; align-items:flex-start; gap:10px; padding:9px 0; border-bottom:1px solid var(--line); }
        .check .ic { font-weight:800; font-size:16px; flex-shrink:0; width:20px; text-align:center; }
        .check .ok { color:#1a9d54; } .check .bad { color:var(--brand); } .check .opt { color:#e0a100; }
        .check .txt { font-size:14px; } .check .hint { font-size:12px; color:var(--soft); margin-top:2px; }
        .err { background:#fdecec; border:1px solid #f5b5b5; color:#a11; border-radius:8px; padding:12px 14px; margin:14px 0; font-size:14px; }
        .warn { background:#fff7e6; border:1px solid #ffe0a3; color:#7a5c00; border-radius:8px; padding:12px 14px; font-size:14px; }
        .ok-box { background:#e9f9ef; border:1px solid #b5e6c8; color:#186c3b; border-radius:8px; padding:12px 14px; margin:14px 0; font-size:14px; }
        code { background:#f2f4f6; border:1px solid var(--line); border-radius:4px; padding:1px 5px; font-size:0.9em; }
        .db-choice { display:flex; gap:12px; margin:16px 0; }
        .db-choice label { flex:1; border:2px solid var(--line); border-radius:10px; padding:14px; cursor:pointer; margin:0; text-align:center; font-weight:700; }
        .db-choice input { display:none; }
        .db-choice input:checked + span { color:var(--brand); }
        .db-choice label:has(input:checked) { border-color:var(--brand); background:#fdf3f4; }
        .small { font-size:13px; color:var(--soft); }
        .mysql-fields { display:none; margin-top:10px; }
        .mysql-fields.show { display:block; }
    </style>';
}

function step_bar($aktuell) {
    $out = '<div class="steps">';
    for ($i = 1; $i <= 5; $i++) {
        $cls = $i < $aktuell ? 'done' : ($i === $aktuell ? 'now' : '');
        $out .= '<div class="s ' . $cls . '"></div>';
    }
    return $out . '</div>';
}

echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Einsatzplan – Installation</title>' . installer_styles() . '</head><body><div class="wrap"><div class="card">';
echo '<div class="logo">EINSATZPLAN</div>';
echo step_bar($schritt);

foreach ($fehler as $f) echo '<div class="err">' . $f . '</div>';
foreach ($erfolg as $e) echo '<div class="ok-box">' . $e . '</div>';

// ═══════════════════════════ SCHRITT 1: WILLKOMMEN + CHECK ═══════════════════
if ($schritt === 1):
    $checks = systemcheck($ROOT, $DATA_DIR);
    $blocker = false;
    foreach ($checks as $c) { if (!$c[1] && empty($c[3])) $blocker = true; }
?>
    <h1>Willkommen zur Einrichtung</h1>
    <p>Dieser Assistent richtet Ihren Einsatzplan in wenigen Schritten ein. Zunächst prüfen wir,
    ob Ihr Server alle Voraussetzungen erfüllt.</p>
    <div style="margin:20px 0;">
        <?php foreach ($checks as $c):
            $optional = !empty($c[3]);
            $icon = $c[1] ? '<span class="ic ok">✓</span>' : ($optional ? '<span class="ic opt">!</span>' : '<span class="ic bad">✕</span>');
        ?>
        <div class="check">
            <?= $icon ?>
            <div class="txt"><?= $c[0] ?><?= $optional && !$c[1] ? ' <span class="small">(optional)</span>' : '' ?>
                <?php if (!$c[1]): ?><div class="hint"><?= $c[2] ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($blocker): ?>
        <div class="err">Es fehlen erforderliche Voraussetzungen (rot markiert). Bitte beheben Sie diese
        und laden Sie die Seite neu.</div>
        <a class="btn secondary" href="install.php?schritt=1">Erneut prüfen</a>
    <?php else: ?>
        <p class="ok-box">Alle erforderlichen Voraussetzungen sind erfüllt.</p>
        <a class="btn" href="install.php?schritt=2">Weiter zur Datenbank →</a>
    <?php endif; ?>

<?php
// ═══════════════════════════ SCHRITT 2: DATENBANK ═══════════════════════════
elseif ($schritt === 2): ?>
    <h1>Datenbank wählen</h1>
    <p>Der Einsatzplan kann seine Daten in einer einfachen Datei (SQLite) oder in einer
    MySQL-/MariaDB-Datenbank speichern.</p>
    <form method="POST">
        <input type="hidden" name="aktion" value="datenbank">
        <div class="db-choice">
            <label>
                <input type="radio" name="driver" value="sqlite" checked onclick="document.getElementById('mysqlF').classList.remove('show')">
                <span>SQLite<br><span class="small">Empfohlen · keine Einrichtung nötig</span></span>
            </label>
            <label>
                <input type="radio" name="driver" value="mysql" onclick="document.getElementById('mysqlF').classList.add('show')">
                <span>MySQL / MariaDB<br><span class="small">Für größere Installationen</span></span>
            </label>
        </div>
        <div class="mysql-fields" id="mysqlF">
            <label>Host</label><input type="text" name="db_host" value="localhost">
            <label>Port</label><input type="number" name="db_port" value="3306">
            <label>Datenbankname</label><input type="text" name="db_name" placeholder="z. B. einsatzplan">
            <label>Benutzer</label><input type="text" name="db_user">
            <label>Passwort</label><input type="password" name="db_pass">
            <p class="small">Diese Daten erhalten Sie von Ihrem Webhoster. Die Datenbank muss bereits existieren.</p>
        </div>
        <div class="row">
            <a class="btn secondary" href="install.php?schritt=1">← Zurück</a>
            <button class="btn" type="submit">Weiter →</button>
        </div>
    </form>
    <script>
        // MySQL-Felder wieder einblenden, falls nach Fehler MySQL gewählt war
        if (document.querySelector('input[value=mysql]').checked) document.getElementById('mysqlF').classList.add('show');
    </script>

<?php
// ═══════════════════════════ SCHRITT 3: ADMIN-KONTO ═════════════════════════
elseif ($schritt === 3): ?>
    <h1>Administrator-Konto</h1>
    <p>Legen Sie das Haupt-Konto an, mit dem Sie den Einsatzplan verwalten. Das Kürzel
    erscheint an jedem Termin als Änderungsnachweis.</p>
    <form method="POST">
        <input type="hidden" name="aktion" value="admin">
        <label>Benutzername</label>
        <input type="text" name="username" required autofocus>
        <label>Kürzel <span class="small">(z. B. ADM, optional)</span></label>
        <input type="text" name="kuerzel" maxlength="4" placeholder="ADM">
        <label>Passwort</label>
        <input type="password" name="password" required>
        <label>Passwort wiederholen</label>
        <input type="password" name="password2" required>
        <div class="row">
            <a class="btn secondary" href="install.php?schritt=2">← Zurück</a>
            <button class="btn" type="submit">Weiter →</button>
        </div>
    </form>

<?php
// ═══════════════════════════ SCHRITT 4: FIRMA ══════════════════════════════
elseif ($schritt === 4): ?>
    <h1>Ihr Betrieb</h1>
    <p>Wie heißt Ihr Betrieb? Der Name erscheint auf der Anmeldeseite und den mobilen
    Mitarbeiter-Seiten. Sie können ihn später jederzeit im Admin-Bereich ändern.</p>
    <form method="POST">
        <input type="hidden" name="aktion" value="firma">
        <label>Firmen-/Betriebsname</label>
        <input type="text" name="firma" placeholder="z. B. Sanitätshaus Mustermann GmbH" autofocus>
        <div class="row">
            <a class="btn secondary" href="install.php?schritt=3">← Zurück</a>
            <button class="btn" type="submit">Installation abschließen →</button>
        </div>
    </form>

<?php
// ═══════════════════════════ SCHRITT 5: FERTIG ═════════════════════════════
elseif ($schritt === 5): ?>
    <h1>Fertig – viel Erfolg!</h1>
    <p class="ok-box">Ihr Einsatzplan wurde erfolgreich eingerichtet.</p>
    <p><strong>Wichtig zur Sicherheit:</strong></p>
    <div class="warn">
        Bitte löschen Sie jetzt die Datei <code>install.php</code> von Ihrem Server
        (per FTP oder Datei-Manager). So kann niemand die Einrichtung erneut aufrufen.
    </div>
    <a class="btn" href="index.php">Zum Einsatzplan →</a>

<?php endif; ?>

</div>
<p class="small" style="text-align:center; margin-top:16px;">Einsatzplan · Installations-Assistent</p>
</div></body></html>

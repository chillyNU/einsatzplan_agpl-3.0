<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require 'config.php';
require_once 'init_db.php';
// session_start() wird bereits in config.php aufgerufen – kein doppelter Aufruf nötig

// Footer Text holen
$stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'footer_text'");
$footerVal = $stmt->fetchColumn();
$footerText = ($footerVal !== false && $footerVal !== null) ? $footerVal : "Firmenname";

// Wurde der Login von einer Mobil-Seite aus aufgerufen? Dann Mobil vorauswählen.
$vorauswahlMobil = isset($_GET['mobil']) || (($_POST['login_ziel'] ?? '') === 'mobil');

$error = '';

// ── Brute-Force-Bremse (datenbankgestützt) ────────────────────────────────────
// Fehlversuche werden serverseitig in der Tabelle login_attempts protokolliert –
// je Absender-IP und je Benutzername. Damit lässt sich der Schutz nicht mehr
// durch Verwerfen des Session-Cookies umgehen:
//   • max. 5 Fehlversuche pro IP innerhalb von 15 Minuten
//   • max. 10 Fehlversuche pro Benutzername (IP-übergreifend, gegen verteilte
//     Angriffe auf ein einzelnes Konto)
// Nach Überschreiten ist die Anmeldung für die jeweilige IP bzw. den Benutzer
// bis zum Ablauf des Zeitfensters gesperrt. Erfolgreiche Anmeldung setzt die
// Zähler zurück. Alte Einträge werden bei jedem Aufruf aufgeräumt.
// Hinweis: Läuft der Server hinter einem Reverse-Proxy/Load-Balancer, liefert
// REMOTE_ADDR ggf. die Proxy-IP; dann greift praktisch nur die Benutzer-Sperre.
$maxAttemptsIp   = 5;        // Fehlversuche pro IP im Zeitfenster
$maxAttemptsUser = 10;       // Fehlversuche pro Benutzername im Zeitfenster
$windowSeconds   = 15 * 60;  // Zeitfenster & Sperrdauer (15 Minuten)

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unbekannt';
$now      = time();
$cutoff   = date('Y-m-d H:i:s', $now - $windowSeconds);

/** Verbleibende Sperrzeit in Minuten (0 = nicht gesperrt). */
function login_lock_minutes(PDO $pdo, string $cutoff, int $windowSeconds, int $now, string $spalte, string $wert, int $max): int {
    // $spalte ist fest 'ip' oder 'username' (kein Benutzereingabe-Bezeichner)
    $stmt = $pdo->prepare("SELECT COUNT(*), MAX(attempted_at) FROM login_attempts WHERE $spalte = ? AND attempted_at >= ?");
    $stmt->execute([$wert, $cutoff]);
    [$anzahl, $letzter] = $stmt->fetch(PDO::FETCH_NUM);
    if ((int)$anzahl < $max || !$letzter) { return 0; }
    $freiAb = strtotime($letzter) + $windowSeconds;
    return max(1, (int)ceil(($freiAb - $now) / 60));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF-Token prüfen (schützt vor untergeschobenen Login-Formularen)
    csrf_verify();

    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    try {
        // Abgelaufene Einträge aufräumen
        $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < ?")->execute([$cutoff]);

        // Sperren prüfen (IP und – falls angegeben – Benutzername)
        $wartezeit = login_lock_minutes($pdo, $cutoff, $windowSeconds, $now, 'ip', $clientIp, $maxAttemptsIp);
        if ($wartezeit === 0 && $user !== '') {
            $wartezeit = login_lock_minutes($pdo, $cutoff, $windowSeconds, $now, 'username', $user, $maxAttemptsUser);
        }
    } catch (Exception $e) {
        // Bremse darf den Login nie komplett lahmlegen (z.B. Tabelle fehlt noch)
        error_log('Brute-Force-Bremse: ' . $e->getMessage());
        $wartezeit = 0;
    }

    if ($wartezeit > 0) {
        $error = 'Zu viele Fehlversuche. Bitte in etwa ' . $wartezeit . ' Minute(n) erneut versuchen.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$user]);
        $userData = $stmt->fetch();

        // Auch bei unbekanntem Benutzer einen Hash-Vergleich durchführen, damit
        // die Antwortzeit keinen Rückschluss auf existierende Konten zulässt.
        $dummyHash = '$2y$10$abcdefghijklmnopqrstuvC13GRTYnLI17T8Ppf6Cm/1aP0/0dW9K';
        $passwortOk = $userData
            ? password_verify($pass, $userData['password_hash'])
            : (password_verify($pass, $dummyHash) && false);

    if ($passwortOk) {
        // Erfolg: Fehlversuche dieser IP und dieses Benutzers löschen
        try {
            $pdo->prepare("DELETE FROM login_attempts WHERE ip = ? OR username = ?")
                ->execute([$clientIp, $user]);
        } catch (Exception $e) { /* unkritisch */ }
        // Neue Session-ID vergeben: verhindert Session-Fixation und sorgt dafür,
        // dass eine evtl. übrig gebliebene alte Session nicht weiterverwendet wird
        session_regenerate_id(true);

        $_SESSION['user_id']  = $userData['id'];
        $_SESSION['username'] = $userData['username'];
        $_SESSION['role']     = $userData['role'];
        $_SESSION['kuerzel']  = $userData['kuerzel'] ?? ''; 

        // 30-Tage-Login für mobile Geräte: langlebigen Token setzen, damit ein
        // erneuter Login auf dem Handy für ~30 Tage nicht nötig ist.
        remember_login_create($pdo, (int)$userData['id']);

        // Ziel je nach Auswahl im Login-Formular
        $ziel = $_POST['login_ziel'] ?? 'haupt';
        if ($ziel === 'mobil') {
            header("Location: mobil/index.php");
        } else {
            header("Location: index.php");
        }
        exit;
    } else {
        // Fehlschlag protokollieren und Angreifer künstlich ausbremsen
        try {
            $pdo->prepare("INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)")
                ->execute([$clientIp, ($user !== '' ? $user : null), date('Y-m-d H:i:s', $now)]);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?");
            $stmt->execute([$clientIp, $cutoff]);
            $fehlversuche = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $fehlversuche = 1;
        }
        usleep(500000); // 0,5 s Verzögerung je Fehlversuch

        if ($fehlversuche >= $maxAttemptsIp) {
            $error = 'Zu viele Fehlversuche. Die Anmeldung ist für 15 Minuten gesperrt.';
        } else {
            $verbleibend = $maxAttemptsIp - $fehlversuche;
            $error = 'Ungültiger Benutzername oder Passwort. Noch ' . $verbleibend . ' Versuch(e).';
        }
    }
    } // Ende: nicht gesperrt
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Login - Einsatzplan</title>
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <style>
        body {
            min-height: 100vh; display: flex; flex-direction: column;
            justify-content: center; align-items: center;
            background: linear-gradient(rgba(255,255,255,0.72), rgba(238,241,245,0.88)), url('pics/banner.png') no-repeat center center;
            background-size: 80% auto;
            background-color: var(--bg);
            padding: 20px;
        }
        .header-title {
            margin-bottom: 6px; text-align: center; color: var(--brand);
            font-size: 2.6em; font-weight: 800; letter-spacing: 0.01em;
            text-shadow: 0 0 18px rgba(255,255,255,0.95);
        }
        .header-sub { margin-bottom: 24px; font-weight: 600; color: var(--ink-soft); }
        .login-card {
            background: var(--surface); padding: 38px 40px;
            border: 1px solid var(--line);
            border-radius: 14px; box-shadow: var(--shadow-2);
            width: 360px; text-align: center;
            border-top: 5px solid var(--brand);
        }
        .login-card h2 {
            color: var(--ink-soft); margin: 0 0 22px; font-size: 0.95em;
            text-transform: uppercase; letter-spacing: 0.14em; font-weight: 700;
        }
        .login-card input { width: 100%; padding: 12px 14px; margin: 8px 0; font-size: 1em; }
        .login-ziel-label { display:block; text-align:left; font-size:0.82em; color:#888; margin:10px 2px 2px; }
        .login-card select.login-ziel {
            width: 100%; padding: 12px 14px; margin: 2px 0 4px; font-size: 1em;
            border: 1px solid #ccc; border-radius: 8px; background: #fff; color: #333;
        }
        .login-card button {
            width: 100%; padding: 12px; background: var(--brand); color: #fff;
            border: none; border-radius: var(--radius-sm); cursor: pointer;
            font-size: 1.05em; font-weight: 700; margin-top: 16px;
            transition: background 0.15s;
        }
        .login-card button:hover { background: var(--brand-dark); }
        .error { color: var(--danger); font-size: 0.9em; background: var(--danger-tint); border: 1px solid var(--danger-line); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px; font-weight: 600; }
    </style>
</head>
<body>

  <div class="header-title">Einsatzplan</div>
<div class="header-sub">
    <?= htmlspecialchars($footerText) ?>
</div>

    <div class="login-card">
        <h2>Anmelden</h2>
        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="text" name="username" placeholder="Benutzername" required autofocus>
            <input type="password" name="password" placeholder="Passwort" required>
            <label class="login-ziel-label" for="login_ziel">Nach dem Login öffnen:</label>
            <select name="login_ziel" id="login_ziel" class="login-ziel">
                <option value="haupt"<?= ($vorauswahlMobil ? '' : ' selected') ?>>Hauptprogramm (Einsatzplan)</option>
                <option value="mobil"<?= ($vorauswahlMobil ? ' selected' : '') ?>>Mobile Mitarbeiter-Seite</option>
            </select>
            <button type="submit">Einloggen</button>
        </form>
    </div>

    <?php include 'footer.php'; ?>
</body>
</html>
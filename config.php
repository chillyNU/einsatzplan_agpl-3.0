<?php

// ── Session zentralisiert starten ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Browser-Caching für alle Seiten unterbinden ───────────────────────────────
// Ohne diese Header kann ein Browser (insbesondere mobile Browser beim
// erneuten Öffnen der reinen Domain-URL) eine zuvor angezeigte, eingeloggte
// Seite aus dem Cache anzeigen, OHNE den Server erneut zu kontaktieren.
// Nach einem Logout würde dadurch scheinbar wieder Zugriff bestehen, obwohl
// die Session serverseitig längst beendet ist. Deshalb wird jede Seite als
// "niemals zwischenspeicherbar" markiert.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

// ── Fehlerausgabe im Produktivbetrieb deaktivieren ───────────────────────────
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$dbDir = __DIR__ . '/data';

require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/init_db.php';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

$dbFile = $dbDir . '/einsatzplan.sqlite';

// ── Datenbankverbindung (SQLite oder MySQL, je nach data/db_config.php) ──────
try {
    if (db_is_mysql()) {
        $pdo = db_connect_mysql(db_load_config()['mysql']);
    } else {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
} catch (PDOException $e) {
    error_log('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
    if (db_is_mysql()) {
        // Verständliche Fehlerseite mit Hilfestellung, ohne Zugangsdaten preiszugeben
        http_response_code(503);
        die(
            '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Datenbank nicht erreichbar</title></head>'
            . '<body style="font-family:sans-serif;max-width:640px;margin:60px auto;line-height:1.6;">'
            . '<h1>&#9888;&#65039; MySQL-Datenbank nicht erreichbar</h1>'
            . '<p>Die Anwendung ist auf MySQL konfiguriert, aber die Verbindung ist fehlgeschlagen.</p>'
            . '<p><b>M&ouml;gliche Ursachen:</b></p>'
            . '<ul><li>Der MySQL-Server l&auml;uft nicht oder ist &uuml;berlastet</li>'
            . '<li>Zugangsdaten oder Host haben sich ge&auml;ndert</li>'
            . '<li>Eine Firewall blockiert die Verbindung</li></ul>'
            . '<p><b>Sofort-L&ouml;sung:</b> Die Datei <code>data/db_config.php</code> auf dem Server l&ouml;schen oder umbenennen &ndash; '
            . 'dann l&auml;uft die Anwendung wieder auf der lokalen SQLite-Datenbank (Stand der letzten Migration).</p>'
            . '</body></html>'
        );
    }
    die('Datenbankverbindung fehlgeschlagen.');
}

initDatabase($pdo);

// Prüfen, ob schon ein Benutzer existiert
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$userCount = $stmt->fetchColumn();

// Wenn kein User existiert und wir nicht auf einer Einrichtungs-Seite sind,
// zur Ersteinrichtung leiten. install.php ist der geführte Installer und
// übernimmt die Rolle von setup.php, daher hier ebenfalls ausgenommen.
$einrichtungsSeiten = ['setup.php', 'install.php', 'mysql_setup.php'];
if ($userCount == 0 && !in_array(basename($_SERVER['PHP_SELF']), $einrichtungsSeiten, true)) {
    header('Location: setup.php');
    exit;
}

// ── CSRF-Schutz ────────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Gibt ein verstecktes Input-Feld mit dem CSRF-Token aus */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Prüft den CSRF-Token und bricht bei Fehler ab */
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Ungültige Anfrage (CSRF-Fehler). Bitte die Seite neu laden.');
    }
}
// ───────────────────────────────────────────────────────────────────────────────

function getLabel($key, $default) {
    global $pdo;
    static $labels = null;

    if ($labels === null) {
        $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'label_%'");
        $labels = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return $labels['label_' . $key] ?? $default;
}

// ── Mitarbeiter-Anzahl pro Wochentag ──────────────────────────────────────────
// Wie viele Mitarbeiter-Spalten pro Tag angezeigt/beplant werden, ist pro
// Wochentag einstellbar (Admin-Bereich). Gespeichert als JSON in der
// settings-Tabelle unter dem Schlüssel 'mitarbeiter_pro_wochentag', z.B.
//   {"Montag":2,"Dienstag":4,...}
// Erlaubt sind 1 bis zum technischen Maximum (getMaxMitarbeiterErlaubt()).
// Fehlt ein Eintrag, gilt der Standard (2).

const MAX_MITARBEITER = 12;     // technisches Maximum der Software (Farben t1..t12 vorhanden)
const DEFAULT_MITARBEITER = 2;  // Vorgabe, solange nichts eingestellt ist

/**
 * Wie viele Mitarbeiter diese Installation maximal nutzen darf. Diese
 * Funktion ist die EINZIGE Stelle, gegen die Eingaben und Anzeigen begrenzt
 * werden – sowohl im Formular als auch serverseitig beim Speichern. Aktuell
 * schlicht das technische Maximum; hier ließe sich bei Bedarf leicht ein
 * eigenes Limit (z. B. aus einer Konfigurationsdatei) einhängen.
 */
function getMaxMitarbeiterErlaubt(): int {
    return MAX_MITARBEITER;
}

// Programmversion – zentral hier pflegen, wird an allen Stellen angezeigt.
// APP_DOMAIN und APP_VERSION_INFO werden auf der Hauptseite getrennt
// dargestellt (Domain hervorgehoben); APP_VERSION bleibt als zusammengesetzte
// Zeichenkette für alle übrigen Stellen (Mobil-Seiten, Info-Popup) erhalten.
const APP_DOMAIN       = 'beimkunden.de';
const APP_VERSION_INFO = '(V2.0 / Juli 2026)';
const APP_VERSION      = APP_DOMAIN . '  ' . APP_VERSION_INFO;

// ── Hersteller-/Entwickler-Angaben ───────────────────────────────────────────
// Werden im Info-Fenster (kleines "i" neben der Version) angezeigt. Der Kunde
// pflegt sein eigenes Impressum separat; dies hier ist die Herstellerangabe.
// Bei Bedarf einfach hier anpassen.
const HERSTELLER_NAME    = 'Markus Wiedemann';
const HERSTELLER_PRODUKT = 'EINSATZPLAN';
const HERSTELLER_EMAIL   = 'chilly112@gmail.com';

/** Liefert die konfigurierte Mitarbeiterzahl je Wochentag als Array. */
function getMitarbeiterProWochentag(): array {
    global $pdo;
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $alleTage = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
    $cfg = array_fill_keys($alleTage, DEFAULT_MITARBEITER);

    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'mitarbeiter_pro_wochentag'");
        $raw = $stmt->fetchColumn();
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                // Anzeige/Nutzung hart auf den lizenzierten Umfang begrenzen.
                // Wichtig für Downgrade-Fälle (z. B. Testphase mit gespeicherten
                // 8 Spalten): Termine höherer Spalten werden dann nur
                // ausgeblendet, nicht gelöscht – nach einem Upgrade erscheinen
                // sie wieder.
                $limit = getMaxMitarbeiterErlaubt();
                foreach ($alleTage as $tag) {
                    if (isset($decoded[$tag])) {
                        $n = (int) $decoded[$tag];
                        $cfg[$tag] = max(1, min($limit, $n));
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Bei Fehler bleibt der Standard bestehen
    }
    return $cfg;
}

/** Mitarbeiterzahl für einen konkreten Wochentag (z.B. 'Montag'). */
function getAnzahlMitarbeiter(string $wochentag): int {
    $cfg = getMitarbeiterProWochentag();
    return $cfg[$wochentag] ?? DEFAULT_MITARBEITER;
}

// ── 30-Tage-Login für mobile Geräte ("Angemeldet bleiben") ───────────────────
// Beim Login wird ein langlebiger Zufalls-Token erzeugt, als Cookie gesetzt und
// (nur als Hash) in der Tabelle auth_tokens gespeichert. Solange der Token
// gültig ist, stellt remember_login() die Session ohne erneute Passworteingabe
// wieder her. Der Token ist nicht an eine bestimmte Unterseite gebunden – ein
// gültiges Login als admin, user oder viewer genügt.

const REMEMBER_COOKIE = 'ep_remember';
const REMEMBER_DAYS   = 30;
/** Erstellt einen Remember-Token für den Nutzer und setzt den Cookie. */
function remember_login_create(PDO $pdo, int $user_id): void {
    try {
        $selector = bin2hex(random_bytes(9));   // öffentlicher Teil
        $secret   = bin2hex(random_bytes(32));  // geheimer Teil
        $token    = $selector . ':' . $secret;
        $hash     = hash('sha256', $secret);

        $stmt = $pdo->prepare(
            "REPLACE INTO auth_tokens (token, user_id, expires_at)
             VALUES (?, ?, " . db_now('+' . REMEMBER_DAYS . ' days') . ")"
        );
        $stmt->execute([$selector . ':' . $hash, $user_id]);

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(REMEMBER_COOKIE, $token, [
            'expires'  => time() + REMEMBER_DAYS * 86400,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Exception $e) { /* ohne Token weiter, nur kein 30-Tage-Login */ }
}

/** Prüft den Remember-Cookie und stellt ggf. die Session wieder her. */
function remember_login_check(PDO $pdo): bool {
    if (isset($_SESSION['user_id'])) return true;
    if (empty($_COOKIE[REMEMBER_COOKIE])) return false;

    $raw = $_COOKIE[REMEMBER_COOKIE];
    if (strpos($raw, ':') === false) return false;
    [$selector, $secret] = explode(':', $raw, 2);

    try {
        // Abgelaufene Tokens aufräumen
        $pdo->exec("DELETE FROM auth_tokens WHERE expires_at < " . db_now());

        $stmt = $pdo->prepare("SELECT token, user_id FROM auth_tokens WHERE token LIKE ? AND expires_at > " . db_now());
        $stmt->execute([$selector . ':%']);
        $row = $stmt->fetch();
        if (!$row) return false;

        [, $storedHash] = explode(':', $row['token'], 2);
        if (!hash_equals($storedHash, hash('sha256', $secret))) return false;

        // Nutzer laden und Session herstellen
        $u = $pdo->prepare("SELECT id, username, role, kuerzel FROM users WHERE id = ?");
        $u->execute([$row['user_id']]);
        $user = $u->fetch();
        if (!$user) return false;

        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['kuerzel']  = $user['kuerzel'] ?? '';
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/** Entfernt den Remember-Token (beim Logout). */
function remember_login_clear(PDO $pdo): void {
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $raw = $_COOKIE[REMEMBER_COOKIE];
        $selector = explode(':', $raw, 2)[0] ?? '';
        if ($selector !== '') {
            try {
                $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE token LIKE ?");
                $stmt->execute([$selector . ':%']);
            } catch (Exception $e) {}
        }
        setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    }
}

// ── Cache-Busting für CSS/JS ─────────────────────────────────────────────────
// Hängt an eine Asset-Datei automatisch deren letzte Änderungszeit als
// Versions-Parameter an. Ändert sich die Datei, ändert sich die URL – der
// Browser (auch auf Mobilgeräten) lädt dann garantiert die neue Version,
// ohne dass der Cache manuell geleert werden muss.
// Der übergebene Pfad ist die URL (z.B. 'style.css' oder '../theme.css');
// die echte Datei wird relativ zum Basisverzeichnis der App aufgelöst.
function asset(string $pfad): string {
    // Dateiname ohne '../' für die Suche im App-Verzeichnis
    $datei = basename(parse_url($pfad, PHP_URL_PATH) ?: $pfad);
    $voll  = __DIR__ . '/' . $datei;
    $v = @filemtime($voll);
    return htmlspecialchars($pfad) . '?v=' . ($v ?: '1');
}

<?php
require 'config.php';

// Authentifizierung: Zuerst prüfen, dann Daten verwenden
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer') {
    die("Zugriff verweigert.");
}

$user_id      = $_SESSION['user_id'];
$user_kuerzel = $_SESSION['kuerzel'] ?? '';

// FALLBACK: Wenn das Kürzel leer ist, versuche es aus der DB zu laden
if (empty($user_kuerzel)) {
    $stmt = $pdo->prepare("SELECT kuerzel FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_kuerzel = $stmt->fetchColumn() ?: 'SYS';
    $_SESSION['kuerzel'] = $user_kuerzel;
}

$user_kuerzel = $user_kuerzel ?: 'SYS';
$now          = date('Y-m-d H:i:s');

// CSRF-Token prüfen
csrf_verify();

$id           = $_POST['id'] ?? $_POST['termin_id'] ?? '';
$datum        = $_POST['datum'] ?? '';
$uhrzeit      = $_POST['uhrzeit'] ?? '';
$tech_id      = $_POST['techniker_id'] ?? '';

/**
 * Baut einen sicheren URL-Anker zum betroffenen Slot, damit die Ansicht nach
 * dem Redirect nicht nach oben springt, sondern zum bearbeiteten Termin
 * zurückkehrt (Scroll + kurzes Aufleuchten übernimmt script.js).
 * Bei unerwartetem Format wird bewusst kein Anker gesetzt.
 */
function slot_anchor(string $datum, string $uhrzeit, $tech): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) { return ''; }
    if (!preg_match('/^\d{2}:\d{2}$/', $uhrzeit))     { return ''; }
    $t = (int)$tech;
    if ($t < 1 || $t > MAX_MITARBEITER)               { return ''; }
    return '#slot_' . $datum . '_' . $t . '_' . str_replace(':', '-', $uhrzeit);
}

// Dynamische Felder erfassen
$opt_stmt = $pdo->query("SELECT spalten_name FROM termin_optionen");
$dynamischeSpalten = $opt_stmt->fetchAll(PDO::FETCH_COLUMN);

// Konfigurierte Pflichtfelder serverseitig prüfen (Schutz auch bei
// deaktiviertem JavaScript / manipuliertem HTML)
$pflichtKatalog = terminfeld_katalog();
$fehlend = [];
foreach (get_required_termin_felder($pdo) as $feld) {
    $wert = trim((string)($_POST[$feld] ?? ''));
    if ($wert === '') {
        $fehlend[] = $pflichtKatalog[$feld] ?? $feld;
    }
}
if (!empty($fehlend)) {
    $_SESSION['flash_error'] = 'Bitte folgende Pflichtfelder ausfüllen: ' . implode(', ', $fehlend) . '.';
    $return_w = $_POST['return_w'] ?? date('W');
    $return_y = $_POST['return_y'] ?? date('Y');
    header("Location: index.php?w=" . urlencode($return_w) . "&y=" . urlencode($return_y)
        . slot_anchor($_POST['new_datum'] ?? $datum, $_POST['new_uhrzeit'] ?? $uhrzeit, $tech_id));
    exit;
}

// Basis-Datenarray (Nur Datenbankspalten!)
$data = [
    'datum'         => $_POST['new_datum'] ?? $datum,
    'uhrzeit'       => $_POST['new_uhrzeit'] ?? $uhrzeit,
    'techniker_id'  => $_POST['new_tech'] ?? $tech_id,
    'einsatzart'    => $_POST['einsatzart'] ?? '',
    'ankunft'       => $_POST['ankunft'] ?? '',
    'unterart'      => $_POST['unterart'] ?? '',
    'name'          => $_POST['kunde_name'] ?? '',
    'strasse'       => $_POST['strasse'] ?? '',
    'zusatzinfo'    => $_POST['zusatzinfo'] ?? '',
    'plz'           => $_POST['plz'] ?? '',
    'ort'           => $_POST['ort'] ?? '',
    'telefon1'      => $_POST['telefon1'] ?? '',
    'tel1_name'     => $_POST['tel1_name'] ?? '',
    'telefon2'      => $_POST['telefon2'] ?? '',
    'tel2_name'     => $_POST['tel2_name'] ?? '',
    'telefon3'      => $_POST['telefon3'] ?? '',
    'tel3_name'     => $_POST['tel3_name'] ?? '',
    'pflegekasse'   => $_POST['pflegekasse'] ?? '',
    'bemerkung'     => $_POST['bemerkung'] ?? '',
    'ausgefallen'   => isset($_POST['ausgefallen']) ? 1 : 0
];

foreach ($dynamischeSpalten as $spalte) {
    $data[$spalte] = isset($_POST[$spalte]) ? 1 : 0;
}

// ── Mitarbeiter-Spalte serverseitig prüfen ──────────────────────────────────
// Die Spalte muss zwischen 1 und der für den Zieltag konfigurierten Anzahl
// liegen. So kann auch ein manipuliertes Formular keine Termine in Spalten
// anlegen, die für diesen Tag nicht aktiviert sind.
$zielTech = (int)$data['techniker_id'];
$zielAnzahl = getMaxMitarbeiterErlaubt();
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['datum'])) {
    $wtagMap = ['Monday'=>'Montag','Tuesday'=>'Dienstag','Wednesday'=>'Mittwoch',
                'Thursday'=>'Donnerstag','Friday'=>'Freitag','Saturday'=>'Samstag',
                'Sunday'=>'Sonntag'];
    $wtag = $wtagMap[date('l', strtotime($data['datum']))] ?? 'Montag';
    $zielAnzahl = getAnzahlMitarbeiter($wtag);
}
if ($zielTech < 1 || $zielTech > $zielAnzahl) {
    $_SESSION['flash_error'] = 'Der Termin konnte nicht gespeichert werden: Die gewählte Mitarbeiter-Spalte ist an diesem Tag nicht verfügbar (Tages-Einstellung).';
    $return_w = $_POST['return_w'] ?? date('W');
    $return_y = $_POST['return_y'] ?? date('Y');
    header("Location: index.php?w=" . urlencode($return_w) . "&y=" . urlencode($return_y));
    exit;
}

// ── Schutz gegen gleichzeitiges Überschreiben ────────────────────────────────
// Bevor gespeichert wird, prüfen, ob ein ANDERER Nutzer diesen Slot gerade
// bearbeitet (aktiver Lock). Falls ja, wird das Speichern abgelehnt – so kann
// niemand die Eingaben eines Kollegen überschreiben, der den Slot noch offen hat.
$slot_id = 'slot_' . $data['datum'] . '_' . (int)$data['techniker_id'] . '_' . str_replace(':', '-', $data['uhrzeit']);
$pdo->exec("DELETE FROM active_locks WHERE expires_at < " . db_now());
$lockStmt = $pdo->prepare(
    "SELECT username FROM active_locks
     WHERE slot_id = ? AND user_id != ? AND expires_at > " . db_now()
);
$lockStmt->execute([$slot_id, $user_id]);
if ($fremd = $lockStmt->fetch()) {
    $_SESSION['flash_error'] = 'Dieser Termin wird gerade von ' . $fremd['username']
        . ' bearbeitet und konnte nicht gespeichert werden. Bitte kurz warten und erneut versuchen.';
    $return_w = $_POST['return_w'] ?? date('W');
    $return_y = $_POST['return_y'] ?? date('Y');
    header("Location: index.php?w=" . urlencode($return_w) . "&y=" . urlencode($return_y)
        . slot_anchor($data['datum'], $data['uhrzeit'], $data['techniker_id']));
    exit;
}

// 1. UPDATE-LOGIK
if (!empty($id)) {
    // Alte Werte für Historisierung holen
    $stmtOld = $pdo->prepare("SELECT updated_by_kuerzel, updated_at FROM dienste WHERE id = ?");
    $stmtOld->execute([$id]);
    $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
    
// Historie verschieben - explizit NULL statt leerer String, falls Wert fehlt
$data['edit2_kuerzel'] = !empty($old['updated_by_kuerzel']) ? $old['updated_by_kuerzel'] : null;
$data['edit2_at']     = !empty($old['updated_at']) ? $old['updated_at'] : $now;
    
    // Neue Werte setzen
    $data['updated_by_kuerzel'] = $user_kuerzel;
    $data['updated_at']         = $now;
    
    $setTeil = [];
    foreach ($data as $key => $val) { $setTeil[] = "$key = :$key"; }
    
    $sql = "UPDATE dienste SET " . implode(', ', $setTeil) . " WHERE id = :id";
    $params = $data;
    $params['id'] = $id;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $aktion = "UPDATE";
} 
// 2. INSERT-LOGIK
else {
    $data['created_by_kuerzel'] = $user_kuerzel;
    $data['created_at']         = $now;
    $data['updated_by_kuerzel'] = $user_kuerzel;
    $data['updated_at']         = $now;
    $data['edit2_kuerzel']      = null;
    $data['edit2_at']           = null;
    
    $spalten = implode(', ', array_keys($data));
    $platzhalter = ':' . implode(', :', array_keys($data));
    
    $sql = "INSERT INTO dienste ($spalten) VALUES ($platzhalter)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    $id = $pdo->lastInsertId();
    $aktion = "INSERT";
}

// Audit Log & Redirect
$log_stmt = $pdo->prepare("INSERT INTO audit_log (dienst_id, user_id, aktion, details) VALUES (?, ?, ?, ?)");
$log_stmt->execute([$id, $user_id, $aktion, "Termin " . $data['name']]);

$return_w = $_POST['return_w'] ?? date('W');
$return_y = $_POST['return_y'] ?? date('Y');
// Zurück zum gespeicherten Termin (bei Verschiebung: zur neuen Position)
header("Location: index.php?w=" . urlencode($return_w) . "&y=" . urlencode($return_y)
    . slot_anchor($data['datum'], $data['uhrzeit'], $data['techniker_id']));
exit;
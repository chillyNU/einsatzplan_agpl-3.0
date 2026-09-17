<?php
require_once 'init_db.php';
require_once 'config.php';
// CSRF-Token wird über csrf_token() in config.php verwaltet
// Login-Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Ab hier läuft der Rest des Codes normal weiter
$styleFile = __DIR__ . '/column_settings.json';
$remoteStyles = file_exists($styleFile) ? json_decode(file_get_contents($styleFile), true) : [];
$isViewer = (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer');
$message = "";


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_profile'])) {
    csrf_verify();
    $new_username = trim((string) $_POST['p_username']);
    $new_kuerzel = strtoupper(trim((string) $_POST['p_kuerzel']));
    $new_password = $_POST['p_password'];
    $current_password = $_POST['p_current_password'] ?? '';
    $uid = $_SESSION['user_id'];

    if ($new_username !== '' && $new_username !== '0') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$new_username, $uid]);
        if ($stmt->fetch()) {
            $message = "error|Benutzername bereits vergeben!";
        } else {
            if (!empty($new_password)) {
                // Altes Passwort pruefen bevor neues gesetzt wird
                $stmtPw = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmtPw->execute([$uid]);
                $currentHash = $stmtPw->fetchColumn();
                if (!password_verify($current_password, $currentHash)) {
                    $message = "error|Aktuelles Passwort ist falsch.";
                } else {
                    $hash = password_hash((string) $new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, kuerzel = ?, password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_username, $new_kuerzel, $hash, $uid]);
                    $_SESSION['username'] = $new_username;
                    $_SESSION['kuerzel'] = $new_kuerzel;
                    $message = "success|Profil aktualisiert.";
                }
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, kuerzel = ? WHERE id = ?");
                $stmt->execute([$new_username, $new_kuerzel, $uid]);
                $_SESSION['username'] = $new_username;
                $_SESSION['kuerzel'] = $new_kuerzel;
                $message = "success|Profil aktualisiert.";
            }
        }
    }
}

$selectedWeek = isset($_GET['w']) ? (int)$_GET['w'] : (int)date('W');
$selectedYear = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
if ($selectedWeek < 1) {
    $selectedYear--;
    // Letztes KW des Vorjahres dynamisch ermitteln (kann 52 oder 53 sein)
    $dec28 = new DateTime($selectedYear . '-12-28');
    $selectedWeek = (int)$dec28->format('W');
} elseif ($selectedWeek > 53) {
    $selectedWeek = 1;
    $selectedYear++;
}
$dto = new DateTime();
$dto->setISODate($selectedYear, $selectedWeek);
$mondayThisWeek = $dto->getTimestamp();

$stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'wochenende_anzeigen'");
$stmt->execute();
$wochenendeAnzeigen = $stmt->fetchColumn() ?: '0';

$startdate = date('Y-m-d', $mondayThisWeek);
$enddate = $wochenendeAnzeigen === '1'
    ? date('Y-m-d', strtotime('sunday', $mondayThisWeek))
    : date('Y-m-d', strtotime('friday', $mondayThisWeek));

$stmt = $pdo->prepare("SELECT * FROM dienste WHERE (datum BETWEEN ? AND ?) AND deleted_at IS NULL");
$stmt->execute([$startdate, $enddate]);
$plan = [];
while ($t = $stmt->fetch(PDO::FETCH_ASSOC)) { 
    $plan[$t['datum']][$t['uhrzeit']][$t['techniker_id']] = $t; 
}

$activeLocks = [];
try {
    $lock_stmt = $pdo->query("SELECT slot_id, username FROM active_locks");
    $activeLocks = $lock_stmt->fetchAll(PDO::FETCH_KEY_PAIR); 
} catch (Exception) {}

$notiz_stmt = $pdo->prepare("SELECT * FROM tages_notizen WHERE datum BETWEEN ? AND ?");
$notiz_stmt->execute([$startdate, $enddate]);
$tagesDaten = [];
while ($n = $notiz_stmt->fetch(PDO::FETCH_ASSOC)) { 
    $tagesDaten[$n['datum']][$n['techniker_id']] = [
        'notiz' => $n['notiz'],
        'vertretung' => $n['vertretung'] ?? '' 
    ]; 
}

$arten_stmt = $pdo->query("SELECT * FROM einsatzarten ORDER BY hauptart, unterart");
$einsatzArtenKonfig = [];
while ($row = $arten_stmt->fetch(PDO::FETCH_ASSOC)) {
    $einsatzArtenKonfig[$row['hauptart']][] = $row['unterart'];
}

$opt_stmt = $pdo->query("SELECT * FROM termin_optionen ORDER BY sortierung ASC");
$dynamischeOptionen = $opt_stmt->fetchAll(PDO::FETCH_ASSOC);
function generateTimeSlots() {
    $slots = []; $start = new DateTime('08:00'); $end = new DateTime('17:30'); 
    while ($start < $end) { $slots[] = $start->format('H:i'); $start->modify('+30 minutes'); }
    return $slots;
}
$timeSlots = generateTimeSlots();
$wochentage = $wochenendeAnzeigen === '1'
    ? ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag']
    : ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
function getTechnikerName($tag, $tId, $jahr, $kw, $pdo) {
    $stmt = $pdo->prepare("SELECT name FROM techniker_zuordnung WHERE wochentag=? AND techniker_nummer=? AND jahr=? AND (? BETWEEN kw_start AND kw_end) LIMIT 1");
    $stmt->execute([$tag, $tId, $jahr, $kw]);
    return $stmt->fetchColumn() ?: "Nicht zugewiesen";
}
if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// Aktuell als Pflichtfeld konfigurierte Felder des Buchungsformulars laden
$requiredTerminFelder = get_required_termin_felder($pdo);

/** Gibt das HTML-Attribut required="" aus, wenn $feld als Pflichtfeld konfiguriert ist */
function reqAttr(string $feld): string {
    global $requiredTerminFelder;
    return in_array($feld, $requiredTerminFelder, true) ? ' required' : '';
}

/** Gibt die CSS-Klasse "req" (roter Stern am Label) aus, wenn $feld Pflichtfeld ist */
function reqClass(string $feld): string {
    global $requiredTerminFelder;
    return in_array($feld, $requiredTerminFelder, true) ? 'req' : '';
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <link rel="stylesheet" href="<?= asset('style.css') ?>">
    <title>Einsatzplan</title>
    <style>
        /* Farb-/Design-Variablen kommen zentral aus theme.css */
		#bookingModal input:not([type="checkbox"]):not([type="date"]), 
    #bookingModal select, 
    #bookingModal textarea {
        color: #1f3a7a !important;    /* Marine (Eingabe-Hervorhebung) */
        font-weight: bold !important; /* Fett */
    }
    #bookingModal input[type="date"] {
        color: #1f3a7a;
        font-weight: bold;
    }
    /* Neue Klasse für rote KW im Kalender */
    .kw-label { color: var(--drk-red); font-weight: bold; font-size: 0.8em; padding-right: 5px; }
    
/* --- Finales Dunkelgrau-Styling mit gelbem Info-Feld --- */

/* 1. Die gesamte Spalte */
.day-dark-mode { 
    background-color: #8f98a3 !important; 
}

/* 2. Der Header-Bereich (T1/T2 Zeile) */
.day-dark-mode .tech-header-block { 
    background-color: #6e7885 !important; 
    border-bottom: 1px solid #5d6672;
    color: #fff !important;
}

/* 3. Die Terminkarten in der grauen Spalte */
.day-dark-mode .termin-card {
    background-color: #9aa3ad !important; /* Mittleres Grau für die Karte */
    border: 1px solid #5d6672 !important;
    color: #000 !important;            /* Text SCHWARZ für gute Lesbarkeit */
}

/* 4. Text-Elemente innerhalb der Karte auf Schwarz zwingen */
.day-dark-mode .termin-card span, 
.day-dark-mode .termin-card div,
.day-dark-mode .termin-card strong,
.day-dark-mode .termin-card small {
    color: #000 !important;
}

/* 5. DAS INFO-FELD (Bleibt Gelb mit roter Schrift) */
/* Wir erzwingen hier die Originalfarben, auch wenn .day-dark-mode aktiv ist */
.day-dark-mode .info-input { 
    background-color: #fffdf2 !important; /* Notiz-Gelb bleibt auch im Grau-Modus */
    color: var(--brand) !important;
    border: 1px dashed var(--warn-line) !important;
}

/* Toggle-Button in einer aktiven (grauen) Spalte */
.day-dark-mode .btn-color-toggle {
    background-color: #6e7885 !important;
    border-color: #5d6672 !important;
    color: #fff !important;
}
    
    </style>
</head>
<body>
    <?php if (isset($_SESSION['flash_error'])): ?>
    <div style="background:var(--danger); color:#fff; padding:16px 20px; text-align:center; font-weight:bold; position:fixed; top:20px; left:0; z-index:9999; width:100%; box-shadow:var(--shadow-2);">
        <?= htmlspecialchars($_SESSION['flash_error']) ?>
        <br><button onclick="this.parentElement.style.display='none'">OK</button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<nav class="sticky-day-nav">
    <a href="javascript:void(0)" onclick="openYearModal()" class="nav-cal-btn">📅<span>Kalender</span></a>
    <a href="javascript:void(0)" onclick="document.getElementById('planSearchInput').focus(); window.scrollTo(0,0);" class="nav-search-btn">🔍<span>Suche</span></a>
    <?php foreach ($wochentage as $i => $tagName): $tagDate = date('Y-m-d', strtotime(sprintf('+%s days', $i), $mondayThisWeek)); ?>
        <a href="#day-<?= $tagDate ?>"><?= substr($tagName, 0, 2) ?><span><?= $tagName ?></span></a>
    <?php endforeach; ?>
</nav>
<a href="index.php?w=<?= $selectedWeek-1 ?>&y=<?= $selectedYear ?>" class="float-nav-btn float-nav-left" title="Vorherige Woche" onclick="return gotoWeek(event, <?= $selectedWeek-1 ?>, <?= $selectedYear ?>)">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
  <polyline points="15 18 9 12 15 6" />
</svg>
</a>
<a href="index.php?w=<?= $selectedWeek+1 ?>&y=<?= $selectedYear ?>" class="float-nav-btn float-nav-right" title="Nächste Woche" onclick="return gotoWeek(event, <?= $selectedWeek+1 ?>, <?= $selectedYear ?>)">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
  <polyline points="9 18 15 12 9 6" />
</svg>
</a>
<button type="button" id="scrollTopBtn" class="scroll-top-btn" title="Nach oben" aria-label="Nach oben scrollen" onclick="window.scrollTo({top:0, behavior:'smooth'})">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
  <polyline points="17 11 12 6 7 11" />
  <polyline points="17 18 12 13 7 18" />
</svg>
</button>
<div class="main-wrapper">
    <?php if ($message !== ''): $parts = explode('|', $message); ?>
        <div class="msg-banner <?= $parts[0] == 'success' ? 'msg-success' : 'msg-error' ?>"><?= $parts[1] ?></div>
    <?php endif; ?>
<header class="header-area header-grid">

    <!-- Zone 1: Marke -->
    <div class="brand-block">
        <a href="index.php" class="brand-wordmark">EINSATZPLAN</a>
        <div class="brand-sub">
            <span class="brand-domain"><?= htmlspecialchars(APP_DOMAIN) ?></span> <?= htmlspecialchars(APP_VERSION_INFO) ?>
            <span class="hersteller-info" tabindex="0" title="Herstellerinformation">
                <span class="hersteller-i">i</span>
                <span class="hersteller-pop">
                    <strong>Software „<?= htmlspecialchars(HERSTELLER_PRODUKT) ?>“</strong><br>
                    Entwicklung &amp; Bereitstellung:<br>
                    <?= htmlspecialchars(HERSTELLER_NAME) ?><br>
                    <a href="mailto:<?= htmlspecialchars(HERSTELLER_EMAIL) ?>"><?= htmlspecialchars(HERSTELLER_EMAIL) ?></a><br>
                    <span class="hersteller-version"><?= htmlspecialchars(APP_VERSION) ?></span>
                </span>
            </span>
        </div>
    </div>

    <!-- Zone 2: Wochen-Navigation -->
    <nav class="hdr-center">
        <div class="week-display">
            <a href="index.php?w=<?= $selectedWeek-1 ?>&y=<?= $selectedYear ?>" class="btn-nav-circle" title="Vorherige Woche">❮</a>
            <select class="nav-dropdown" onchange="location.href='index.php?y=<?= $selectedYear ?>&w='+this.value">
                <?php $maxKw = (int)(new DateTime($selectedYear . '-12-28'))->format('W'); ?>
                <?php for($k=1; $k<=$maxKw; $k++): ?><option value="<?= $k ?>" <?= $k === $selectedWeek ? 'selected' : '' ?>>KW <?= $k ?></option><?php endfor; ?>
            </select>
            <select class="nav-dropdown" onchange="location.href='index.php?w=<?= $selectedWeek ?>&y='+this.value">
                <?php $nowY = (int)date('Y'); $yVon = min($nowY - 2, $selectedYear); $yBis = max($nowY + 4, $selectedYear); ?>
                <?php for($y=$yVon; $y<=$yBis; $y++): ?><option value="<?= $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
            </select>
            <a href="index.php?w=<?= $selectedWeek+1 ?>&y=<?= $selectedYear ?>" class="btn-nav-circle" title="Nächste Woche">❯</a>
        </div>
        <a href="index.php?w=<?= (int)date('W') ?>&y=<?= (int)date('o') ?>#day-<?= date('Y-m-d') ?>" class="btn-hdr">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><circle cx="12" cy="16" r="1.5" fill="currentColor" stroke="none"/></svg>
            Heute
        </a>
        <button type="button" class="btn-hdr" onclick="openWeekPreview(<?= $selectedWeek ?>, <?= $selectedYear ?>)" title="Wochenansicht">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Wochenansicht
        </button>
        <?php if (!$isViewer): ?>
        <a href="mobil/index.php" target="_blank" rel="noopener" class="btn-hdr btn-hdr-mobil" title="Mobile Mitarbeiter-Seiten (öffnet in neuem Tab)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
            Mobil
        </a>
        <?php endif; ?>
    </nav>

    <!-- Zone 3: Suche & Benutzer -->
    <div class="hdr-right">
        <div class="search-box">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: var(--ink-faint); flex-shrink:0;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="planSearchInput" placeholder="Name, Ort, Tel..." onkeyup="if(event.key === 'Enter') performSearch()">
            <select id="searchYearSelect">
                <?php for($y=$yVon; $y<=$yBis; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="button" class="search-btn-inline" onclick="performSearch()">Suchen</button>
        </div>

        <div class="hdr-divider"></div>

        <div class="user-cluster">
            <a href="javascript:void(0)" class="user-profile-link" title="Profil bearbeiten" onclick="document.getElementById('profileModal').style.display='block'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= h($_SESSION['username']) ?>
            </a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="admin.php" class="icon-btn" title="Einstellungen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </a>
            <?php endif; ?>
            <a href="logout.php" class="icon-btn" title="Abmelden">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </div>
</header>
    <?php if (isset($_SESSION['clipboard'])): ?>
        <div class="clipboard-bar">
            <span><strong>📋 In Ablage:</strong> <?= h($_SESSION['clipboard']['einsatzart']) ?> für <?= h($_SESSION['clipboard']['name']) ?></span>
            <a href="copy_paste.php?action=clear&w=<?= $selectedWeek ?>&y=<?= $selectedYear ?>&csrf_token=<?= csrf_token() ?>" class="btn-clear-clipboard" onclick="sessionStorage.setItem('scrollPosition', window.scrollY)">Ablage leeren ✖</a>
        </div>
    <?php endif; ?>
    <?php foreach ($wochentage as $i => $tagName): $tagDate = date('Y-m-d', strtotime(sprintf('+%s days', $i), $mondayThisWeek)); ?>
    <?php $anzahlHeute = getAnzahlMitarbeiter($tagName); ?>
    <div id="day-<?= $tagDate ?>" class="day-row" data-maxtech="<?= $anzahlHeute ?>">
        <div class="day-header">
            <strong><?= $tagName ?>, <?= date('d.m.Y', strtotime($tagDate)) ?></strong>
            <div class="day-header-buttons">
                <?php for ($pId = 1; $pId <= $anzahlHeute; $pId++): ?>
                <button onclick="window.open('print_day.php?datum=<?= $tagDate ?>&tech=<?= $pId ?>','_blank')"
                        title="<?= h(getLabel('mitarbeiter_' . $pId, 'Mitarbeiter ' . $pId)) ?> drucken">
    <span class="db-label"><?= getLabel('mitarbeiter_' . $pId, 'Mitarbeiter ' . $pId) ?></span> 🖨️
</button>
                <?php endfor; ?>
            </div>
        </div>
        <div class="tech-columns-wrap">
<?php for($tId=1; $tId<=$anzahlHeute; $tId++): 
    $techNameStr = getTechnikerName($tagName, $tId, $selectedYear, $selectedWeek, $pdo);
    $data = $tagesDaten[$tagDate][$tId] ?? ['notiz' => '', 'vertretung' => ''];
    $vName = trim($data['vertretung'] ?? '');
    
    // NEU: Wenn $vName existiert, nutze ihn, sonst den Stamm-Namen
    $anzeigeName = !empty($vName) ? $vName : $techNameStr;
?>
               <?php 
    // Prüfen, ob für dieses Datum und diesen Techniker (1 oder 2) Grau gespeichert ist
    $isDarkClass = ($remoteStyles[$tagDate][$tId] ?? false) ? 'day-dark-mode' : ''; 
?>
<div class="tech-column-<?= $tId ?> <?= $isDarkClass ?>">
    <div class="tech-header-block">
        <div class="tech-name">
            <?php if (!$isViewer): ?>
                <button type="button" class="btn-color-toggle" onclick="toggleDayColor('<?= $tagDate ?>', <?= $tId ?>)">◐</button>
            <?php endif; ?>

            <?php
            // Ist ein echter Name hinterlegt (zugewiesen oder Vertretung)?
            $hatName = (!empty($vName)) || (!empty($techNameStr) && $techNameStr !== 'Nicht zugewiesen');
            ?>
            <?php if ($hatName): ?>
                <?php // Name im Badge, Label klein dahinter zur Orientierung ?>
                <span class="tech-badge"><?= h($anzeigeName) ?><?= !empty($vName) ? ' (Vertretung)' : '' ?></span>&nbsp;
                <span class="tech-badge-sub"><?= h(getLabel('mitarbeiter_' . $tId, 'Mitarbeiter ' . $tId)) ?></span>
            <?php else: ?>
                <span class="tech-badge">
                    <?= h(getLabel('mitarbeiter_' . $tId, 'Mitarbeiter ' . $tId)) ?>:
                </span>&nbsp;
                <span style="color:#999;">Nicht zugewiesen</span>
            <?php endif; ?>
            
            <button class="btn-v-toggle" onclick="openVertretungModal('<?= $tagDate ?>', <?= $tId ?>, '<?= addslashes((string) $techNameStr) ?>', '<?= addslashes((string) $vName) ?>')">±</button>
        </div>
                        <div class="info-input" style="cursor:pointer;" onclick='openNoteModal("<?= $tagDate ?>", <?= $tId ?>, <?= h(json_encode($data['notiz'])) ?>)'>
                            <?= empty($data['notiz']) ? '<span style="color:#666;">+ Info hinzufügen</span>' : nl2br((string) h($data['notiz'])) ?>
                        </div>
                    </div>
                    <?php foreach($timeSlots as $slot): 
                        $t = $plan[$tagDate][$slot][$tId] ?? null; 
                        $current_slot_id = "slot_" . $tagDate . "_" . $tId . "_" . str_replace(':', '-', $slot);
                        $isLockedByOther = isset($activeLocks[$current_slot_id]) && $activeLocks[$current_slot_id] !== $_SESSION['username'];
                        $isAusgefallen = ($t && isset($t['ausgefallen']) && $t['ausgefallen'] == 1);
                        $cssClass = $t ? 'status-booked' : '';
                        if ($isAusgefallen) {
                            $cssClass .= ' status-ausgefallen';
                        }
                        if ($isLockedByOther) {
                            $cssClass .= ' is-locked-by-other';
                        }
                    ?>
<div id="<?= $current_slot_id ?>" 
     class="slot <?= $cssClass ?>" 
     <?= (!$t && !$isLockedByOther) ? 'ondragover="handleDragOver(event)" ondragleave="this.classList.remove(\'drag-over\')" ondrop="handleDrop(event, this)"' : '' ?>
     data-id="<?= $t['id']??'' ?>" 
     data-booked="<?= $t?'1':'0' ?>" 
     data-datum="<?= $tagDate ?>" 
     data-uhrzeit="<?= $slot ?>" 
     data-tech="<?= $tId ?>"
     data-maname="<?= h(($techNameStr && $techNameStr !== 'Nicht zugewiesen') ? $techNameStr : '') ?>"
     data-vertretung="<?= h($vName ?? '') ?>"
     data-ausgefallen="<?= $isAusgefallen ? '1' : '0' ?>"
     data-art="<?= h($t['einsatzart']??'') ?>" 
     data-ankunft="<?= h($t['ankunft']??'') ?>" 
     data-unterart="<?= h($t['unterart']??'') ?>" 
     data-name="<?= h($t['name']??'') ?>"
     data-strasse="<?= h($t['strasse']??'') ?>" 
     data-zusatz="<?= h($t['zusatzinfo']??'') ?>" 
     data-plz="<?= h($t['plz']??'') ?>" 
     data-ort="<?= h($t['ort']??'') ?>"
     data-tel1="<?= h($t['telefon1']??'') ?>" 
     data-tel1n="<?= h($t['tel1_name']??'') ?>"
     data-tel2="<?= h($t['telefon2']??'') ?>" 
     data-tel2n="<?= h($t['tel2_name']??'') ?>"
     data-tel3="<?= h($t['telefon3']??'') ?>" 
     data-tel3n="<?= h($t['tel3_name']??'') ?>"
     data-bem="<?= h($t['bemerkung']??'') ?>"
     data-pk="<?= h($t['pflegekasse']??'') ?>"
     <?php foreach($dynamischeOptionen as $opt) { echo 'data-'.h($opt['spalten_name']).'="'.h($t[$opt['spalten_name']] ?? '0').'" '; } ?>
>
<?php if($t): ?>
        <?php if (!$isViewer): ?>
            <div class="slot-action-buttons">
                <a href="copy_paste.php?action=copy&id=<?= $t['id'] ?>&w=<?= $selectedWeek ?>&y=<?= $selectedYear ?>&date=<?= $tagDate ?>&time=<?= $slot ?>&tech=<?= $tId ?>" 
                   title="Kopieren" onclick="event.stopPropagation();" style="text-decoration:none; font-size:1.1em;">📋</a>
                <span class="btn-slot-edit" onclick="handleSlotClick(this.closest('.slot'))">EDIT</span>
            </div>
        <?php endif; ?>
        <div class="slot-time" draggable="true" ondragstart="handleDragStart(event, this.parentElement)"><?= $slot ?></div>
    <?php else: ?>
        <div class="slot-time" style="display:flex; align-items:center;">
            <?= $slot ?>
            <button type="button" 
                    onclick="handleSlotClick(this.closest('.slot'))" 
                    style="margin-left:10px; cursor:pointer; border:1px solid #ccc; background:#fff; border-radius:3px; padding:0 5px; font-weight:bold;">+</button>
            <?php if (isset($_SESSION['clipboard'])): ?>
                <a href="copy_paste.php?action=paste&date=<?= $tagDate ?>&time=<?= $slot ?>&tech=<?= $tId ?>&w=<?= $selectedWeek ?>&y=<?= $selectedYear ?>&csrf_token=<?= csrf_token() ?>" 
                   title="Hier einfügen" onclick="event.stopPropagation();" class="paste-btn">📥</a>
                <a href="copy_paste.php?action=clear&w=<?= $selectedWeek ?>&y=<?= $selectedYear ?>&date=<?= $tagDate ?>&time=<?= $slot ?>&tech=<?= $tId ?>&csrf_token=<?= csrf_token() ?>" 
                   title="Abbrechen" onclick="event.stopPropagation();" class="clear-btn-small">✖</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
                            <div class="slot-content">
                                <?php if($t): ?>
                                    <div class="badge-row">
                                       <span class="badge-art">
                                            <?= h($t['einsatzart']) ?> 
                                           <?= empty($t['ankunft']) ? '' : '<span style="font-weight:normal; font-size:0.9em;"> (ca. ' . (preg_match('/^\d{4}$/', $t['ankunft']) ? substr($t['ankunft'],0,2).':'.substr($t['ankunft'],2) : h($t['ankunft'])) . ' Uhr)</span>' ?>
                                        </span>
                                        <?php if(!empty($t['unterart']) && $t['unterart'] !== '-'): ?>
                                            <span class="badge-unterart"><?= h($t['unterart']) ?></span>
                                        <?php endif; ?>
                                        <?php foreach($dynamischeOptionen as $opt) { if(!empty($t[$opt['spalten_name']])) { echo '<span class="badge-opt"><span class="check-green">✔</span> '.h($opt['anzeige_name']).'</span> '; } } ?>
                                    </div>
                                    <div class="customer-header">
                                        <strong><?= h($t['name']) ?></strong>
                                        <?php $zielAddr = ($t['strasse']??'') . ", " . ($t['plz']??'') . " " . ($t['ort']??''); ?>
                                        <span class="customer-address">
                                            <a href="javascript:void(0)" 
   onclick="event.stopPropagation(); openMapsModal(this.getAttribute('data-adresse'))" 
   data-adresse="<?= htmlspecialchars($zielAddr, ENT_QUOTES) ?>" 
   title="Karte anzeigen" 
   style="text-decoration:none; color:inherit;">
   
   📍 <?= h($t['strasse']??'') ?><?= empty($t['zusatzinfo']) ? "" : " (".h($t['zusatzinfo']).")" ?>, 
   <strong><?= h($t['plz']??'') ?> <?= h($t['ort']??'') ?></strong>
</a>
                                        </span>
                                    </div>
                                    <div class="slot-details">
                                        <?php if(!empty($t['pflegekasse'])): ?><span class="badge-kasse">🆔 <?= h($t['pflegekasse']) ?></span> |<?php endif; ?>
                                        <?php 
                                            $contacts = [];
                                            for($n=1;$n<=3;$n++) {
                                                if(!empty($t['telefon' . $n])) {
                                                    $label = empty($t[sprintf('tel%d_name', $n)]) ? "" : h($t[sprintf('tel%d_name', $n)]).": ";
                                                    $contacts[] = "📞 " . $label . '<span class="tel-box" onclick="event.stopPropagation(); copyAndGreen(this)">'.h($t['telefon' . $n]).'</span>';
                                                }
                                            }
                                            if ($contacts !== []) {
                                                echo implode(" | ", $contacts);
                                            }
                                        ?>
                                    </div>
                                    <?php if(!empty($t['bemerkung'])): ?><div class="slot-bemerkung">📝 <?= nl2br(h($t['bemerkung'])) ?></div><?php endif; ?>
                                    <div class="slot-footer-kuerzel">
                                        <?php 
                                            $e_date = empty($t['created_at']) ? 'Unbekannt' : date('d.m.Y H:i', strtotime((string) $t['created_at']));
                                            $v_date = empty($t['edit2_at']) ? '-' : date('d.m.Y H:i', strtotime((string) $t['edit2_at']));
                                            $l_date = empty($t['updated_at']) ? 'Unbekannt' : date('d.m.Y H:i', strtotime((string) $t['updated_at']));
                                        ?>
                                        <span title="Erstellt am: <?= h($e_date) ?>">erstellt: <strong><?= h($t['created_by_kuerzel'] ?: '-') ?></strong></span>
                                        <?php if(!empty($t['edit2_kuerzel'])): ?>
                                            <span title="Vorletzte Änderung am: <?= h($v_date) ?>">vorletzte Änderung: <strong><?= h($t['edit2_kuerzel']) ?></strong></span>
                                        <?php endif; ?>
                                        <span title="Letzte Änderung am: <?= h($l_date) ?>">letzte Änderung: <strong><?= h($t['updated_by_kuerzel'] ?: '-') ?></strong></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div id="profileModal" class="modal">
    <div class="modal-content modal-content-small">
        <h3>👤 Profil bearbeiten</h3>
        <form method="POST">
            <input type="hidden" name="action_update_profile" value="1">
            <?= csrf_field() ?>
            <label>Benutzername</label>
            <input type="text" name="p_username" value="<?= h($_SESSION['username']) ?>" required>
            <label style="margin-top:15px; display:block;">Dein Kürzel</label>
            <input type="text" name="p_kuerzel" value="<?= h($_SESSION['kuerzel'] ?? '') ?>" maxlength="5">
            <label style="margin-top:15px; display:block;">Aktuelles Passwort <small style="color:#888;">(nur nötig bei Passwortänderung)</small></label>
            <input type="password" name="p_current_password" placeholder="Aktuelles Passwort">
            <label style="margin-top:15px; display:block;">Neues Passwort <small style="color:#888;">(leer lassen = unverandert)</small></label>
            <input type="password" name="p_password" placeholder="••••••••">
            <div class="modal-footer-btns">
                <button type="submit" class="btn-primary">Aktualisieren</button>
                <button type="button" class="btn-cancel" onclick="document.getElementById('profileModal').style.display='none'">Abbrechen</button>
            </div>
        </form>
    </div>
</div>
<div id="yearModal" class="modal">
    <div class="modal-content" style="max-width: 95%;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0; color:var(--drk-red);">Jahreskalender <?= $selectedYear ?></h2>
            <button type="button" onclick="document.getElementById('yearModal').style.display='none'" style="padding:10px 20px;">Schließen</button>
        </div>
        <div class="year-grid">
            <?php 
            $monate = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
            $today = date('Y-m-d');
            for($m=1; $m<=12; $m++):
                $firstDayOfMonth = $selectedYear . '-' . str_pad((string)$m, 2, "0", STR_PAD_LEFT) . "-01";
                $daysInMonth = date('t', strtotime($firstDayOfMonth));
                $firstDayWoechentag = date('N', strtotime($firstDayOfMonth));
            ?>
                <div class="month-box">
                    <h4 style="margin:5px 0; text-align:center;"><?= $monate[$m-1] ?></h4>
                    <table class="month-table">
                        <tr>
                            <th style="color:var(--drk-red); font-size:0.7em;">KW</th> <th>Mo</th><th>Di</th><th>Mi</th><th>Do</th><th>Fr</th>
                            <th style="color:#999;">Sa</th><th style="color:#999;">So</th>
                        </tr>
                        <tr>
                        <?php 
                        // Erste Zeile: KW ermitteln
                        $firstDateObj = new DateTime($firstDayOfMonth);
                        echo "<td style='color:var(--drk-red); font-weight:bold; font-size:0.8em; border-right:1px solid #eee;'>" . $firstDateObj->format('W') . "</td>";

                        // Leere Zellen bis zum ersten Wochentag
                        for($p=1; $p<$firstDayWoechentag; $p++) echo "<td></td>";

                        for($d=1; $d<=$daysInMonth; $d++):
                            $loopDate = $selectedYear . '-' . str_pad((string)$m, 2, "0", STR_PAD_LEFT) . "-" . str_pad((string)$d, 2, "0", STR_PAD_LEFT);
                            $wDay = date('N', strtotime($loopDate));
                            $dateObj = new DateTime($loopDate);
                            $kw = (int)$dateObj->format('W');
                            $yKw = (int)$dateObj->format('o'); 
                            $isToday = ($loopDate === $today);
                            $class = $isToday ? 'today' : '';

                            if($wDay <= 5) {
                                echo sprintf("<td class='%s'><a href='index.php?y=%d&w=%d#day-%s'>%d</a></td>", $class, $yKw, $kw, $loopDate, $d);
                            } elseif ($wochenendeAnzeigen === '1') {
                                // Wochenende wird im Plan angezeigt -> Tag ist auch im Kalender ansteuerbar
                                echo sprintf("<td class='%s' style='background:#f9f9f9;'><a style='color:#888;' href='index.php?y=%d&w=%d#day-%s'>%d</a></td>", $class, $yKw, $kw, $loopDate, $d);
                            } else {
                                echo sprintf("<td class='%s' style='color:#ccc; background:#f9f9f9;'>%d</td>", $class, $d);
                            }

                            // Wenn Sonntag erreicht ist und der Monat noch nicht zu Ende ist, neue Zeile mit KW beginnen
                            if ($wDay == 7 && $d < $daysInMonth) {
                                $nextDate = new DateTime($loopDate);
                                $nextDate->modify('+1 day');
                                echo "</tr><tr>";
                                echo "<td style='color:var(--drk-red); font-weight:bold; font-size:0.8em; border-right:1px solid #eee;'>" . $nextDate->format('W') . "</td>";
                            }
                        endfor;

                        // Restliche Zellen auffüllen, falls der Monat nicht am Sonntag endet
                        $lastDayWoechentag = date('N', strtotime($selectedYear . '-' . str_pad((string)$m, 2, "0", STR_PAD_LEFT) . "-" . $daysInMonth));
                        for($p=$lastDayWoechentag; $p<7; $p++) echo "<td></td>";
                        ?>
                        </tr>
                    </table>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>
<div id="bookingModal" class="modal">
    <div class="modal-content modal-content-scroll">
        <div class="modal-sticky-top">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0;">
                <h3 id="modalTitle" style="margin-top:0; color:var(--drk-red);">Termin</h3>
                <button type="button" onclick="unlockCurrentSlot(); closeModal()" title="Schließen"
                    style="background:none; border:none; font-size:24px; cursor:pointer; color:#888; line-height:1; padding:0 4px;">&times;</button>
            </div>
            <div class="modal-actions-top">
                <button type="submit" form="bookingForm" class="btn-primary" style="flex:2; margin:0;">Speichern</button>
                <button type="button" id="btnDel" onclick="openDeleteConfirmModal()" class="btn-modal-delete" style="flex:1; display:none;">Löschen</button>
                <button type="button" onclick="unlockCurrentSlot(); closeModal()" class="btn-modal-cancel" style="flex:1;">Abbrechen</button>
            </div>
        </div>
        <form action="save_appointment.php" method="POST" id="bookingForm">
		<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" id="f_id" name="termin_id">
            <input type="hidden" id="f_datum" name="datum">
            <input type="hidden" id="f_uhrzeit" name="uhrzeit">
            <input type="hidden" id="f_tech" name="techniker_id">
            <input type="hidden" name="return_w" value="<?= $selectedWeek ?>">
            <input type="hidden" name="return_y" value="<?= $selectedYear ?>">            
           <details style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 15px;">
    <summary style="cursor: pointer; padding: 10px; color: #555; font-weight: bold; font-size: 0.9em;">
        📅 Termin verschieben / Zeit / Mitarbeiter ändern
    </summary>
    <div style="padding: 10px; border-top: 1px solid #ddd; display: flex; flex-wrap: wrap; gap: 10px;">
        <div class="form-group" style="flex:2; min-width: 150px;">
            <label style="color: #666; font-weight: bold;">Neues Datum</label>
            <input type="date" name="new_datum" id="f_new_datum">
        </div>
        <div class="form-group" style="flex:1; min-width: 100px;">
            <label style="color: #666; font-weight: bold;">Uhrzeit</label>
            <select name="new_uhrzeit" id="f_new_uhrzeit">
                <?php foreach($timeSlots as $ts) echo sprintf('<option value="%s">%s</option>', $ts, $ts); ?>
            </select>
        </div>
        <div class="form-group" style="flex:1; min-width: 100px;">
            <label style="color: #666; font-weight: bold;">Mitarbeiter</label>
            <select name="new_tech" id="f_new_tech">
               <?php for ($mi = 1; $mi <= getMaxMitarbeiterErlaubt(); $mi++): ?>
               <option value="<?= $mi ?>">
    <?php echo h(getLabel('mitarbeiter_' . $mi, 'Mitarbeiter ' . $mi)); ?>
</option>
               <?php endfor; ?>
            </select>
        </div>
    </div>
</details>
            <div style="display:flex; gap:10px; margin-bottom:10px;">
              <div class="form-group" style="flex:1;">
    <label class="<?= reqClass('einsatzart') ?>">Einsatzart</label>
    <select name="einsatzart" id="f_art" onchange="updateSubTypes()"<?= reqAttr('einsatzart') ?>>
        <option value="">-- Bitte wählen --</option>
        <?php foreach(array_keys($einsatzArtenKonfig) as $haupt): ?>
            <option value="<?= h($haupt) ?>"><?= h($haupt) ?></option>
        <?php endforeach; ?>
    </select>
</div>
                <div class="form-group" style="flex:1;"><label class="<?= reqClass('ankunft') ?>">geplante Ankunft</label><input type="text" name="ankunft" id="f_ankunft" placeholder="z. B. 10:00-10:30"<?= reqAttr('ankunft') ?>></div>
            </div>
            <div class="form-group" id="sub_type_container" style="margin-bottom:10px;"><label class="<?= reqClass('unterart') ?>">Spezifizierung</label><select name="unterart" id="f_unterart"<?= reqAttr('unterart') ?>></select></div>
            <div class="form-group"><label class="<?= reqClass('kunde_name') ?>">Name Kunde</label><input type="text" name="kunde_name" id="f_name"<?= reqAttr('kunde_name') ?>></div>            
            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:2;"><label class="<?= reqClass('strasse') ?>">Straße / Nr.</label><input type="text" name="strasse" id="f_strasse"<?= reqAttr('strasse') ?>></div>
                <div class="form-group" style="flex:1;"><label class="<?= reqClass('zusatzinfo') ?>">Zusatz</label><input type="text" name="zusatzinfo" id="f_zusatz"<?= reqAttr('zusatzinfo') ?>></div>
            </div>
           <div class="triple-row">
    <div class="form-group col-plz" style="position: relative;">
        <label class="<?= reqClass('plz') ?>">PLZ</label>
        <input type="text" name="plz" id="f_plz" autocomplete="off"<?= reqAttr('plz') ?> oninput="handlePlzInput(this.value)">
        <div id="plz_suggestions" style="position: absolute; top: 100%; left: 0; z-index: 9999; background: white; border: 1px solid #ccc; width: 250px; max-height: 200px; overflow-y: auto; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
    </div>
    
    <div class="form-group col-ort"><label class="<?= reqClass('ort') ?>">Ort</label><input type="text" name="ort" id="f_ort"<?= reqAttr('ort') ?>></div>
    <div class="form-group col-pk"><label class="<?= reqClass('pflegekasse') ?>">Kd.Nr./(Geräte-)ID/etc.</label><input type="text" name="pflegekasse" id="f_pk"<?= reqAttr('pflegekasse') ?>></div>
</div>
         <div class="options-grid">
    <?php foreach($dynamischeOptionen as $opt): ?>
        <div class="opt-item">
            <input type="checkbox" name="<?= h($opt['spalten_name']) ?>" id="f_<?= h($opt['spalten_name']) ?>" value="1">
            <label for="f_<?= h($opt['spalten_name']) ?>"><?= $opt['anzeige_name'] ?></label>
        </div>
    <?php endforeach; ?>
</div>

<div class="opt-item" style="margin-top: 10px; padding: 10px; border: 1px solid #ddd; background: #fff5f5;">
    <input type="checkbox" name="ausgefallen" id="f_ausgefallen" value="1">
    <label for="f_ausgefallen" style="color:red; font-weight:bold;">⚠️ Termin ausgefallen</label>
</div>
          <label style="display: block; margin-top: 15px; margin-bottom: 5px; font-weight: bold; color: #555; font-size: 0.9em;">
    Telefon<?php if (in_array('telefon1', $requiredTerminFelder, true) || in_array('tel1_name', $requiredTerminFelder, true) || in_array('telefon2', $requiredTerminFelder, true) || in_array('tel2_name', $requiredTerminFelder, true) || in_array('telefon3', $requiredTerminFelder, true) || in_array('tel3_name', $requiredTerminFelder, true)): ?> <span style="color: red;">*</span><?php endif; ?>
</label>

<div style="display:flex; gap:5px; margin-bottom: 5px;">
    <input type="text" name="tel1_name" id="f_tel1n" placeholder="Name 1" class="form-control" style="flex:1;"<?= reqAttr('tel1_name') ?>>
    <input type="text" name="telefon1" id="f_tel1" placeholder="Telefon 1" class="form-control" style="flex:1;"<?= reqAttr('telefon1') ?> inputmode="text">
</div>
<div style="display:flex; gap:5px; margin-bottom: 5px;">
    <input type="text" name="tel2_name" id="f_tel2n" placeholder="Name 2" class="form-control" style="flex:1;"<?= reqAttr('tel2_name') ?>>
    <input type="text" name="telefon2" id="f_tel2" placeholder="Telefon 2" class="form-control" style="flex:1;"<?= reqAttr('telefon2') ?> inputmode="text">
</div>
<div style="display:flex; gap:5px; margin-bottom: 5px;">
    <input type="text" name="tel3_name" id="f_tel3n" placeholder="Name 3" class="form-control" style="flex:1;"<?= reqAttr('tel3_name') ?>>
    <input type="text" name="telefon3" id="f_tel3" placeholder="Telefon 3" class="form-control" style="flex:1;"<?= reqAttr('telefon3') ?> inputmode="text">
</div>
            <div class="form-group">
    <label class="<?= reqClass('bemerkung') ?>">Bemerkung</label>
    <textarea name="bemerkung" id="f_bem" rows="4" style="font-size: 1.2em; line-height: 1.4; padding: 10px;"<?= reqAttr('bemerkung') ?>></textarea>
</div>
        </form>
    </div>
</div>
<div id="deleteConfirmModal" class="modal" style="display:none; z-index:2000; background:rgba(0,0,0,0.6);">
    <div class="modal-content modal-content-small" style="text-align:center;">
        <h3 style="color:var(--drk-red);">Termin löschen?</h3>
        <p>Soll dieser Termin wirklich entfernt werden?</p>
        <div class="modal-footer-btns">
            <button class="btn-primary" style="background:var(--drk-red);" onclick="confirmDelete()">Ja, Löschen</button>
            <button class="btn-cancel" onclick="document.getElementById('deleteConfirmModal').style.display='none'">Abbrechen</button>
        </div>
    </div>
</div>
<div id="vertretungModal" class="modal">
    <div class="modal-content modal-content-small">
        <h3>🔄 Vertretung setzen</h3>
        <p id="v_info_text"></p>
        <input type="hidden" id="v_f_datum">
        <input type="hidden" id="v_f_tech">
        
        <input type="text" id="v_f_name" placeholder="Name der Vertretung" <?= $isViewer ? 'readonly' : '' ?>>
        
        <div class="modal-footer-btns">
            <?php if (!$isViewer): ?>
                <button type="button" class="btn-primary" onclick="submitVertretung()">Speichern</button>
            <?php endif; ?>
            <button type="button" class="btn-cancel" onclick="closeVModal()">Abbrechen</button>
        </div>
    </div>
</div>

<div id="noteModal" class="modal">
    <div class="modal-content modal-content-small">
        <h3 id="n_title">📝 Tages-Info</h3>
        <input type="hidden" id="n_f_datum">
        <input type="hidden" id="n_f_tech">
        
        <textarea id="n_f_text" rows="5" <?= $isViewer ? 'readonly' : '' ?>></textarea>
        
        <div class="modal-footer-btns">
            <?php if (!$isViewer): ?>
                <button type="button" class="btn-primary" onclick="submitNote()">Speichern</button>
            <?php endif; ?>
            <button type="button" class="btn-cancel" onclick="closeNoteModal()">Abbrechen</button>
        </div>
    </div>
</div>
<div id="lockWarningModal" class="modal" style="z-index: 3000;">
    <div class="modal-content modal-content-small" style="text-align: center;">
        <div style="font-size: 3em; margin-bottom: 10px;">⚠️</div>
        <h3 style="color: #444;">Slot belegt</h3>
        <p id="lockWarningText"></p>
        <button type="button" class="btn-primary" onclick="document.getElementById('lockWarningModal').style.display='none'">Verstanden</button>
    </div>
</div>
<div id="searchResultModal" class="modal">
    <div class="modal-content-modern">
        <div class="modal-header">
            <h3>🔍 Suchergebnisse</h3>
            <button onclick="document.getElementById('searchResultModal').style.display='none'" style="border:none; cursor:pointer;">&times;</button>
        </div>
        <div id="searchResultContent" style="padding: 20px;"></div>
    </div>
</div>
<div id="mapsModal" class="modal">
    <div class="modal-content" style="max-width: 800px; width: 90%;">
        <span class="close" onclick="closeMapsModal()">&times;</span>
        <h3>Standort / Route</h3>
        <iframe id="mapsIframe" width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
        <div id="mapsAppBtn" style="text-align: center; margin-top: 10px;">
            </div>
        <div style="margin-top:15px; text-align:right;">
            <button type="button" class="btn-secondary" onclick="closeMapsModal()">Schließen</button>
        </div>
    </div>
</div>
<script>
    const artenMap = <?= json_encode($einsatzArtenKonfig ?? []) ?>;
    const dynOptions = <?= json_encode($dynamischeOptionen ?? []) ?>;
    const selectedWeek = "<?= $selectedWeek ?>";
    const selectedYear = "<?= $selectedYear ?>";
	const isViewer = <?= ($isViewer ? 'true' : 'false') ?>;
	window.currentUserId = "<?= (int)($_SESSION['user_id'] ?? 0) ?>";
</script>
<script src="<?= asset('script.js') ?>"></script>

<script>
function toggleDayColor(date, techId) {
    // 1. Spalte im Browser umschalten
    const col = document.querySelector('#day-' + date + ' .tech-column-' + techId);
    if (!col) return;
    col.classList.toggle('day-dark-mode');
    
    const isNowDark = col.classList.contains('day-dark-mode') ? '1' : '0';

    // 2. An den Server senden, damit es dauerhaft bleibt
    const fd = new FormData();
    fd.append('date', date);
    fd.append('tech', techId);
    fd.append('dark', isNowDark);
    fd.append('csrf_token', csrfToken);  // CSRF-Schutz

    fetch('save_style.php', { method: 'POST', body: fd })
    .catch(err => console.error('Fehler beim Speichern der Farbe:', err));
}

// --- NEU: AUTOMATISCHES ÖFFNEN BEI DUPLIKAT-KLICK ---
window.addEventListener('load', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const openId = urlParams.get('open_id');

    if (openId) {
        setTimeout(() => {
            // Wir suchen das Element mit der ID des Termins
            const targetSlot = document.querySelector(`.slot[data-id="${openId}"]`);
            if (targetSlot) {
                // Zum Termin scrollen
                targetSlot.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Den Termin "anklicken", um das Fenster zu öffnen
                targetSlot.click(); 
            }
        }, 800); // 800ms warten, damit der Kalender sicher fertig geladen ist
    }
});

// "Nach oben"-Button ein-/ausblenden, sobald weit genug gescrollt wurde
(function() {
    const btn = document.getElementById('scrollTopBtn');
    if (!btn) return;
    const toggle = function() {
        if (window.scrollY > 300) { btn.classList.add('is-visible'); }
        else { btn.classList.remove('is-visible'); }
    };
    window.addEventListener('scroll', toggle, { passive: true });
    toggle();
})();

// Beim Wochenwechsel den aktuell betrachteten Wochentag mitnehmen, damit man
// in der Ziel-Woche wieder beim selben Tag landet, von dem man kommt.
function gotoWeek(ev, woche, jahr) {
    if (ev) ev.preventDefault();
    var idx = aktuellerWochentagIndex();
    var url = 'index.php?w=' + woche + '&y=' + jahr + (idx !== null ? '&d=' + idx : '');
    window.location.href = url;
    return false;
}

// Ermittelt den Wochentag-Index (0=Montag ... 6=Sonntag) des Tages, der aktuell
// am weitesten oben im Sichtbereich steht.
function aktuellerWochentagIndex() {
    var rows = document.querySelectorAll('.day-row[id^="day-"]');
    var beste = null, besterAbstand = Infinity;
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i].getBoundingClientRect();
        // Tag gilt als "aktuell", wenn seine Oberkante am nächsten an ~120px unter
        // dem oberen Rand liegt (unterhalb der fixierten Kopfzeile).
        var abstand = Math.abs(r.top - 120);
        // Nur Tage berücksichtigen, die noch nicht komplett nach oben raus sind
        if (r.bottom > 60 && abstand < besterAbstand) {
            besterAbstand = abstand;
            beste = rows[i];
        }
    }
    if (!beste) return null;
    var datum = beste.id.replace('day-', '');       // YYYY-MM-DD
    var d = new Date(datum + 'T00:00:00');
    // JS: getDay() 0=Sonntag..6=Samstag -> auf 0=Montag..6=Sonntag umrechnen
    return (d.getDay() + 6) % 7;
}

// Nach dem Laden: falls ein Ziel-Wochentag (&d=) übergeben wurde, dorthin scrollen.
window.addEventListener('load', function() {
    var params = new URLSearchParams(window.location.search);
    var d = params.get('d');
    if (d === null) return;
    var rows = document.querySelectorAll('.day-row[id^="day-"]');
    for (var i = 0; i < rows.length; i++) {
        var datum = rows[i].id.replace('day-', '');
        var dt = new Date(datum + 'T00:00:00');
        var idx = (dt.getDay() + 6) % 7;
        if (String(idx) === String(d)) {
            rows[i].scrollIntoView({ behavior: 'auto', block: 'start' });
            window.scrollBy(0, -100);   // etwas Luft unter der fixierten Kopfzeile
            break;
        }
    }
});
const csrfToken = "<?= $_SESSION['csrf_token'] ?>";
</script>
<?php
// Diesen Code-Schnipsel in index.php (oder der Datei, in der script.js geladen wird) einfügen
$stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'start_adresse'");
$mapStartAddr = $stmt->fetchColumn() ?: "Münsterplatz 1, 89073 Ulm";
?>
<div id="maps-config" data-start-addr="<?= htmlspecialchars($mapStartAddr) ?>" style="display:none;"></div>

<!-- ===== WOCHENVORSCHAU MODAL ===== -->
<div id="weekPreviewModal" style="display:none; position:fixed; inset:0; z-index:4000; background:rgba(0,0,0,0.55); align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:10px; width:92vw; height:88vh; display:flex; flex-direction:column; box-shadow:0 8px 40px rgba(0,0,0,0.35); overflow:hidden;">

    <!-- Modal-Header -->
    <div style="display:flex; align-items:center; gap:8px; padding:10px 14px; background:#f5f5f5; border-bottom:1px solid #ddd; flex-shrink:0;">
      <button id="wpPrevBtn" onclick="navigateWeekPreview(-1)" title="Vorherige Woche"
        style="flex-shrink:0; background:none; border:1px solid #ccc; border-radius:50%; width:34px; height:34px; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#555; transition:background .15s;"
        onmouseover="this.style.background='#eee'" onmouseout="this.style.background='none'">❮</button>

      <div style="flex:1; text-align:center;">
        <strong id="wpTitle" style="font-size:1.1em; color:#333;">KW – / –</strong>
        <div id="wpDateRange" style="font-size:0.75em; color:#888; margin-top:1px;"></div>
      </div>

      <button id="wpNextBtn" onclick="navigateWeekPreview(1)" title="Nächste Woche"
        style="flex-shrink:0; background:none; border:1px solid #ccc; border-radius:50%; width:34px; height:34px; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#555; transition:background .15s;"
        onmouseover="this.style.background='#eee'" onmouseout="this.style.background='none'">❯</button>

      <!-- Drucken (Querformat) -->
      <button onclick="printWeekPreview()" title="Wochenansicht drucken (Querformat)"
        style="flex-shrink:0; background:none; border:1px solid #ccc; border-radius:6px; width:34px; height:34px; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#555; transition:background .15s;"
        onmouseover="this.style.background='#eee'" onmouseout="this.style.background='none'">🖨️</button>

      <!-- Schließen-Button – Teil des Flex-Layouts, kein absolute -->
      <button onclick="closeWeekPreview()" title="Schließen"
        style="flex-shrink:0; background:var(--brand); border:none; border-radius:6px; width:32px; height:32px; font-size:20px; cursor:pointer; color:#fff; display:flex; align-items:center; justify-content:center; line-height:1; box-shadow:0 2px 6px rgba(0,0,0,0.2); transition:background .15s;"
        onmouseover="this.style.background='#ae1420'" onmouseout="this.style.background='#d81e2c'">&times;</button>
    </div>

    <div style="display:flex; flex-wrap:wrap; gap:8px 14px; align-items:center; padding:5px 16px; background:#fafafa; border-bottom:1px solid #eee; flex-shrink:0; font-size:11px; color:#555;">
      <?php for ($lg = 1; $lg <= getMaxMitarbeiterErlaubt(); $lg++): ?>
      <span><span style="display:inline-block; width:10px; height:10px; background:var(--t<?= $lg ?>); border-radius:2px; margin-right:4px; vertical-align:middle;"></span><?= h(getLabel('mitarbeiter_' . $lg, 'Mitarbeiter ' . $lg)) ?></span>
      <?php endfor; ?>
      <span><span style="display:inline-block; width:10px; height:10px; background:#e8e8e8; border-radius:2px; margin-right:4px; vertical-align:middle; border:1px solid #ccc;"></span>Frei</span>
      <span><span style="display:inline-block; width:10px; height:10px; background:#e0e0e0; border-radius:50%; margin-right:4px; vertical-align:middle;"></span>Ausgefallen</span>
      <span style="margin-left:auto; color:#aaa; font-style:italic;">Hover für Details</span>
    </div>

    <!-- Inhalt -->
    <div id="weekPreviewContent" style="flex:1; overflow:auto; padding:10px;">
      <div style="text-align:center; padding:40px; color:#aaa;">Lade Vorschau…</div>
    </div>
  </div>
</div>

<style>
/* Wochenvorschau: Slot-Ebene (Rahmen/Spalten-Styles liegen in style.css) */
.btn-preview-week { margin-left: 4px; }
.pw-v {
    display: inline-block;
    background: var(--brand);
    color: #fff;
    font-size: 7px;
    border-radius: 2px;
    padding: 0 2px;
    margin-left: 2px;
    vertical-align: middle;
}
.pw-slot {
    flex: 1;
    min-height: 3px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-bottom: 1px solid rgba(29,39,51,0.05);
    cursor: default;
    transition: background .1s;
    position: relative;
}
.pw-free  { background: var(--surface-alt); }
.pw-free:hover { background: var(--t2-tint); }
.pw-booked { background: var(--t2); }
/* Belegte Slots tragen die Leitfarbe ihres Mitarbeiters */
.pw-tech-1 .pw-booked { background: var(--t1); }
.pw-tech-1 .pw-booked:hover { background: #0a6152; }
.pw-tech-2 .pw-booked { background: var(--t2); }
.pw-tech-2 .pw-booked:hover { background: #254c93; }
.pw-tech-3 .pw-booked { background: var(--t3); }
.pw-tech-3 .pw-booked:hover { background: #0a7080; }
.pw-tech-4 .pw-booked { background: var(--t4); }
.pw-tech-4 .pw-booked:hover { background: #9c6015; }
.pw-tech-5 .pw-booked { background: var(--t5); }
.pw-tech-5 .pw-booked:hover { background: #573f8f; }
.pw-tech-6 .pw-booked { background: var(--t6); }
.pw-tech-6 .pw-booked:hover { background: #8c2458; }
.pw-tech-7 .pw-booked { background: var(--t7); }
.pw-tech-7 .pw-booked:hover { background: #616816; }
.pw-tech-8 .pw-booked { background: var(--t8); }
.pw-tech-8 .pw-booked:hover { background: #903718; }
.pw-tech-9 .pw-booked { background: var(--t9); }
.pw-tech-9 .pw-booked:hover { background: #445970; }
.pw-tech-10 .pw-booked { background: var(--t10); }
.pw-tech-10 .pw-booked:hover { background: #31551c; }
.pw-tech-11 .pw-booked { background: var(--t11); }
.pw-tech-11 .pw-booked:hover { background: #6e4824; }
.pw-tech-12 .pw-booked { background: var(--t12); }
.pw-tech-12 .pw-booked:hover { background: #3b3f70; }
.pw-aus   { background: var(--line-strong); }
.pw-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--t2); flex-shrink: 0; display: none; }
.pw-dot-aus { background: var(--ink-faint); }
.pw-slot-text {
    font-size: 8px;
    color: #fff;
    line-height: 1.2;
    text-align: left;
    padding: 1px 3px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    width: 100%;
}
.pw-slot-text.aus { color: var(--ink-soft); }
.pw-dark .pw-slot { border-bottom-color: rgba(0,0,0,0.12); }
.pw-dark .pw-free { background: #a4acb6; }
.pw-dark .pw-booked { background: #4a5568 !important; }
</style>

<script>
var _wpWeek = <?= $selectedWeek ?>;
var _wpYear = <?= $selectedYear ?>;

function openWeekPreview(w, y) {
    _wpWeek = w;
    _wpYear = y;
    document.getElementById('weekPreviewModal').style.display = 'flex';
    loadWeekPreview(_wpWeek, _wpYear);
}

function closeWeekPreview() {
    document.getElementById('weekPreviewModal').style.display = 'none';
}

function printWeekPreview() {
    // Druckt die aktuelle Woche über einen versteckten iframe – KEIN neuer Tab.
    var alt = document.getElementById('wpPrintFrame');
    if (alt) alt.remove();
    var frame = document.createElement('iframe');
    frame.id = 'wpPrintFrame';
    frame.style.position = 'fixed';
    frame.style.right = '0'; frame.style.bottom = '0';
    frame.style.width = '0'; frame.style.height = '0';
    frame.style.border = '0';
    frame.src = 'preview_week.php?print=1&w=' + _wpWeek + '&y=' + _wpYear;
    frame.onload = function () {
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch (e) {}
    };
    document.body.appendChild(frame);
}

function navigateWeekPreview(dir) {
    _wpWeek += dir;
    // Jahreswechsel
    if (_wpWeek < 1) {
        _wpYear--;
        var dec28 = new Date(_wpYear, 11, 28);
        var tmp = new Date(dec28);
        tmp.setDate(dec28.getDate() - ((dec28.getDay() + 6) % 7));
        // Letzte KW des Vorjahres: einfach 52 oder 53
        var jan1 = new Date(_wpYear, 0, 1);
        var jan1Day = (jan1.getDay() + 6) % 7; // 0=Mo
        _wpWeek = (jan1Day <= 3) ? 52 : 52; // sichere Fallback
        // Korrekte Ermittlung
        _wpWeek = getMaxKW(_wpYear);
    } else if (_wpWeek > 53) {
        _wpYear++;
        _wpWeek = 1;
    }
    loadWeekPreview(_wpWeek, _wpYear);
}

function getMaxKW(year) {
    var dec28 = new Date(year, 11, 28);
    var dayOfWeek = (dec28.getDay() + 6) % 7;
    var monday = new Date(dec28);
    monday.setDate(dec28.getDate() - dayOfWeek);
    var jan1 = new Date(year, 0, 1);
    var diff = (monday - jan1) / 86400000;
    return Math.round(diff / 7) + 1;
}

function loadWeekPreview(w, y) {
    document.getElementById('weekPreviewContent').innerHTML =
        '<div style="text-align:center; padding:40px; color:#aaa; font-size:0.9em;">⏳ Lade Vorschau…</div>';

    // Titel & Datumsbereich
    var monday = getMonday(w, y);
    var friday = new Date(monday); friday.setDate(monday.getDate() + 4);
    document.getElementById('wpTitle').textContent = 'KW ' + w + ' / ' + y;
    document.getElementById('wpDateRange').textContent =
        formatDate(monday) + ' – ' + formatDate(friday);

    fetch('preview_week.php?w=' + w + '&y=' + y)
        .then(function(r){ return r.text(); })
        .then(function(html){
            var content = document.getElementById('weekPreviewContent');
            content.innerHTML = html;
            initWeekPreviewLayout(content);
        })
        .catch(function(e){
            document.getElementById('weekPreviewContent').innerHTML =
                '<div style="color:red; padding:20px;">Fehler beim Laden der Vorschau.</div>';
        });
}

function getMonday(w, y) {
    var jan4 = new Date(y, 0, 4);
    var dayOfWeek = (jan4.getDay() + 6) % 7;
    var monday = new Date(jan4);
    monday.setDate(jan4.getDate() - dayOfWeek + (w - 1) * 7);
    return monday;
}

function formatDate(d) {
    return ('0'+d.getDate()).slice(-2) + '.' + ('0'+(d.getMonth()+1)).slice(-2) + '.' + d.getFullYear();
}

// Modal per ESC schließen
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeWeekPreview();
});
// Klick außerhalb schließt Modal
document.getElementById('weekPreviewModal').addEventListener('click', function(e){
    if (e.target === this) closeWeekPreview();
});

// ===== DETAILPOPUP für Wochenvorschau =====
(function(){
  // Popup-Div dynamisch erzeugen
  var popup = document.createElement('div');
  popup.id = 'pw-detail-popup';
  popup.style.cssText = 'display:none; position:fixed; z-index:99999; pointer-events:none; transition:opacity .1s;';
  popup.innerHTML = '<div style="background:#fff; border-radius:10px; box-shadow:0 8px 32px rgba(0,0,0,0.28); padding:16px 20px; min-width:240px; max-width:320px; font-family:\'Segoe UI\',sans-serif; border:1px solid #e0e0e0;">'
    + '<div id="pw-pp-ausgefallen" style="display:none; background:#ffeaea; color:#c00; border-radius:5px; padding:4px 10px; margin-bottom:10px; font-size:12px; font-weight:bold;">⚠️ Ausgefallen</div>'
    + '<div id="pw-pp-time"     style="font-size:15px; font-weight:700; color:#1a1a1a; margin-bottom:8px;"></div>'
    + '<div id="pw-pp-art"      style="font-size:13px; color:#2563eb; font-weight:600; margin-bottom:2px;"></div>'
    + '<div id="pw-pp-unterart" style="font-size:12px; color:#555; margin-bottom:10px; padding-left:8px;"></div>'
    + '<div id="pw-pp-name"     style="font-size:13px; font-weight:700; color:#111; margin-bottom:4px;"></div>'
    + '<div id="pw-pp-adresse"  style="font-size:12px; color:#444; margin-bottom:4px; line-height:1.5; white-space:pre-line;"></div>'
    + '<div id="pw-pp-telefon"  style="font-size:12px; color:#2563eb; margin-bottom:6px;"></div>'
    + '<div id="pw-pp-bemerkung" style="font-size:11px; color:#666; border-top:1px solid #eee; padding-top:8px; margin-top:6px; white-space:pre-wrap; display:none;"></div>'
    + '</div>';
  document.body.appendChild(popup);

  var hideTimer = null;

  window.showPwPopup = function(e, el) {
    clearTimeout(hideTimer);
    var d = el.dataset;
    function set(id, text, show) {
      var el2 = document.getElementById(id);
      el2.textContent = text;
      el2.style.display = (show !== undefined ? show : !!text) ? 'block' : 'none';
    }
    document.getElementById('pw-pp-ausgefallen').style.display = d.ausgefallen === '1' ? 'block' : 'none';
    set('pw-pp-time',     d.uhrzeit   ? '⏰ ' + d.uhrzeit + ' Uhr' : '');
    set('pw-pp-art',      d.einsatzart || '');
    set('pw-pp-unterart', d.unterart  ? '↳ ' + d.unterart : '');
    set('pw-pp-name',     d.name      ? '👤 ' + d.name : '');
    var adrParts = [];
    if (d.strasse) adrParts.push('📍 ' + d.strasse);
    var ort = ((d.plz || '') + ' ' + (d.ort || '')).trim();
    if (ort) adrParts.push('    ' + ort);
    set('pw-pp-adresse', adrParts.join('\n'), adrParts.length > 0);
    set('pw-pp-telefon',  d.telefon   ? '📞 ' + d.telefon : '');
    set('pw-pp-bemerkung', d.bemerkung || '');
    popup.style.display = 'block';
    positionPopup(e);
  };

  window.hidePwPopup = function() {
    hideTimer = setTimeout(function(){ popup.style.display = 'none'; }, 80);
  };

  function positionPopup(e) {
    popup.style.left = '-9999px';
    popup.style.top  = '-9999px';
    var pw2 = popup.offsetWidth;
    var ph2 = popup.offsetHeight;
    var vw  = window.innerWidth;
    var vh  = window.innerHeight;
    var x = e.clientX + 16;
    var y = e.clientY - ph2 / 2;
    if (x + pw2 > vw - 10) x = e.clientX - pw2 - 16;
    if (y < 10) y = 10;
    if (y + ph2 > vh - 10) y = vh - ph2 - 10;
    popup.style.left = x + 'px';
    popup.style.top  = y + 'px';
  }

  document.addEventListener('mousemove', function(e){
    if (popup.style.display !== 'none') positionPopup(e);
  });
})();
</script>

<?php include 'footer.php'; ?>
<script>
/* ── Zusätzliche Bildlaufleiste OBERHALB horizontal scrollender Bereiche ──
   Erzeugt vor dem übergebenen Element eine schmale Scroll-Leiste, die
   synchron zur (unteren) Leiste des Elements läuft. Sie ist nur sichtbar,
   wenn der Inhalt tatsächlich breiter ist als der sichtbare Bereich. */
function attachTopScrollbar(el) {
    if (!el || el.dataset.hasTopbar === '1') return;
    el.dataset.hasTopbar = '1';
    var top = document.createElement('div');
    top.className = 'hscroll-top';
    top.appendChild(document.createElement('div'));
    el.parentNode.insertBefore(top, el);
    function sync() {
        top.firstChild.style.width = el.scrollWidth + 'px';
        top.style.display = (el.scrollWidth > el.clientWidth + 2) ? '' : 'none';
    }
    top.addEventListener('scroll', function () { el.scrollLeft = top.scrollLeft; });
    el.addEventListener('scroll', function () { top.scrollLeft = el.scrollLeft; });
    if (window.ResizeObserver) {
        var ro = new ResizeObserver(sync);
        ro.observe(el);
        Array.prototype.forEach.call(el.children, function (c) { ro.observe(c); });
    }
    window.addEventListener('resize', sync);
    sync();
}

/* Tagesansicht: jede Tages-Karte bekommt die obere Leiste */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tech-columns-wrap').forEach(attachTopScrollbar);
});

/* Wochenvorschau: Mindestbreite je Mitarbeiter-Spalte = Breite einer Spalte
   an einem Tag mit VIER Mitarbeitern; Tage mit mehr Spalten scrollen
   horizontal. Zusätzlich obere Scroll-Leiste über dem Raster. */
function initWeekPreviewLayout(content) {
    var grid = content.querySelector('.pw-grid');
    if (!grid) return;
    var days = parseInt(grid.dataset.days || '5', 10);
    var gap = 4;
    function apply() {
        var minMa = Math.max(48, Math.floor((grid.clientWidth - (days - 1) * gap) / (days * 4)));
        grid.querySelectorAll('.pw-tech').forEach(function (t) {
            t.style.minWidth = minMa + 'px';
            t.style.flex = '1 0 ' + minMa + 'px';
        });
    }
    content.style.display = 'flex';
    content.style.flexDirection = 'column';
    grid.style.flex = '1 1 auto';
    grid.style.height = 'auto';
    grid.style.minHeight = '0';
    apply();
    attachTopScrollbar(grid);
    window.addEventListener('resize', apply);
}
</script>
</body>
</html>
<?php
// ─────────────────────────────────────────────────────────────────────────────
// Mobile Übersichtsseite: listet alle Wochentage und die jeweils eingeplanten
// Mitarbeiter auf. Von hier gelangt man zur passenden Tages-/Mitarbeiterseite.
// ─────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/../config.php';

// Login: aktive Session ODER gültiger 30-Tage-Token
if (!isset($_SESSION['user_id'])) {
    remember_login_check($pdo);
}
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?mobil=index.php');
    exit;
}

if (!function_exists('h')) {
    function h($t) { return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8'); }
}

// Firmenname aus der Footer-Konfiguration
$firmenname = 'Firmenname';
try {
    $val = $pdo->query("SELECT value FROM settings WHERE `key` = 'footer_text'")->fetchColumn();
    if ($val !== false && $val !== null && $val !== '') $firmenname = $val;
} catch (Exception $e) {}

$version = defined('APP_VERSION') ? APP_VERSION : '';

$tage = [
    'mo' => 'Montag', 'di' => 'Dienstag', 'mi' => 'Mittwoch', 'do' => 'Donnerstag',
    'fr' => 'Freitag', 'sa' => 'Samstag', 'so' => 'Sonntag',
];

// Wochenende nur zeigen, wenn in der Konfiguration aktiviert
$wochenendeAn = false;
try {
    $we = $pdo->query("SELECT value FROM settings WHERE `key` = 'wochenende_anzeigen'")->fetchColumn();
    $wochenendeAn = ($we === '1');
} catch (Exception $e) {}
if (!$wochenendeAn) {
    unset($tage['sa'], $tage['so']);
}

$heuteWochentag = ['Monday'=>'Montag','Tuesday'=>'Dienstag','Wednesday'=>'Mittwoch',
    'Thursday'=>'Donnerstag','Friday'=>'Freitag','Saturday'=>'Samstag','Sunday'=>'Sonntag'][date('l')] ?? '';

// Aktuelle Kalenderwoche/Jahr – für die zugewiesenen Namen dieser Woche
$aktKW   = (int)date('W');
$aktJahr = (int)date('o');

// Hilfsfunktion: für diese Woche zugewiesener Name (techniker_zuordnung)
function mobilZugewiesenerName(PDO $pdo, string $wochentag, int $ma, int $jahr, int $kw): string {
    try {
        $stmt = $pdo->prepare(
            "SELECT name FROM techniker_zuordnung
             WHERE wochentag = ? AND techniker_nummer = ? AND jahr = ?
             AND (? BETWEEN kw_start AND kw_end) LIMIT 1"
        );
        $stmt->execute([$wochentag, $ma, $jahr, $kw]);
        return trim((string)$stmt->fetchColumn());
    } catch (Exception $e) { return ''; }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#c8102e">
<title>Einsatzplan · Mobile Übersicht</title>
<link rel="stylesheet" href="../<?= asset('theme.css') ?>">
<!-- PWA: Übersicht als installierbare App (Standard-Einsatzplan-Icon) -->
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Einsatzplan">
<style>
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body {
        margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #eef1f5; color: #1d2733; padding-bottom: 40px;
    }
    .m-head {
        background: #c8102e; color: #fff; text-align: center;
        padding: max(18px, env(safe-area-inset-top)) 16px 18px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .m-head .titel { font-size: 26px; font-weight: 800; letter-spacing: 0.02em; }
    .m-head .firma { font-size: 15px; opacity: 0.95; margin-top: 2px; }
    .m-head .version { font-size: 11px; opacity: 0.75; margin-top: 6px; }

    .m-body { padding: 16px 12px; max-width: 640px; margin: 0 auto; }
    .m-intro { text-align:center; color:#55606d; font-size:14px; margin: 4px 0 18px; }

    .tag-block {
        background: #fff; border-radius: 14px; margin-bottom: 14px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08); overflow: hidden;
    }
    .tag-titel {
        font-size: 16px; font-weight: 800; padding: 12px 16px;
        background: #f4f6f9; border-bottom: 1px solid #e6eaef;
        display: flex; align-items: center; justify-content: space-between;
    }
    .tag-titel .heute {
        font-size: 11px; font-weight: 800; background: #c8102e; color: #fff;
        padding: 2px 10px; border-radius: 20px;
    }
    .ma-liste { display: flex; flex-wrap: wrap; gap: 10px; padding: 14px 16px; }
    .ma-btn {
        flex: 1 1 calc(50% - 5px); min-width: 120px;
        display: flex; align-items: center; gap: 10px;
        padding: 12px 14px; border-radius: 10px; text-decoration: none;
        color: #1d2733; font-weight: 700; font-size: 15px;
        background: #f7f8fa; border: 1px solid #e6eaef;
    }
    .ma-btn:active { background: #eef1f5; }
    .ma-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
    .ma-txt { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
    .ma-label { font-weight: 700; }
    .ma-person { font-size: 12px; font-weight: 500; color: #55606d; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ma-1 .ma-dot { background: var(--t1); }
    .ma-2 .ma-dot { background: var(--t2); }
    .ma-3 .ma-dot { background: var(--t3); }
    .ma-4 .ma-dot { background: var(--t4); }
    .ma-5 .ma-dot { background: var(--t5); }
    .ma-6 .ma-dot { background: var(--t6); }
    .ma-7 .ma-dot { background: var(--t7); }
    .ma-8 .ma-dot { background: var(--t8); }
    .ma-9 .ma-dot { background: var(--t9); }
    .ma-10 .ma-dot { background: var(--t10); }
    .ma-11 .ma-dot { background: var(--t11); }
    .ma-12 .ma-dot { background: var(--t12); }

    .m-foot { text-align:center; color:#9aa4b0; font-size:12px; margin-top: 22px; }
    .m-foot a { color:#9aa4b0; }
</style>
</head>
<body>

<div class="m-head">
    <div class="titel">Einsatzplan</div>
    <div class="firma"><?= h($firmenname) ?></div>
    <?php if ($version): ?><div class="version"><?= h($version) ?></div><?php endif; ?>
</div>

<div class="m-body">
    <div class="m-intro">Wähle deinen Tag und Mitarbeiter-Platz:</div>

    <?php foreach ($tage as $kuerzel => $tagName):
        $anzahl = getAnzahlMitarbeiter($tagName);
        $istHeute = ($tagName === $heuteWochentag);
    ?>
    <div class="tag-block">
        <div class="tag-titel">
            <span><?= h($tagName) ?></span>
            <?php if ($istHeute): ?><span class="heute">HEUTE</span><?php endif; ?>
        </div>
        <div class="ma-liste">
            <?php for ($ma = 1; $ma <= $anzahl; $ma++):
                $label = getLabel('mitarbeiter_' . $ma, 'Mitarbeiter ' . $ma);
                $person = mobilZugewiesenerName($pdo, $tagName, $ma, $aktJahr, $aktKW);
            ?>
                <a class="ma-btn ma-<?= $ma ?>" href="<?= $kuerzel ?>_<?= $ma ?>.php">
                    <span class="ma-dot"></span>
                    <span class="ma-txt">
                        <span class="ma-label"><?= h($label) ?></span>
                        <?php if ($person !== ''): ?><span class="ma-person"><?= h($person) ?></span><?php endif; ?>
                    </span>
                </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="m-foot">
        Eingeloggt als <?= h($_SESSION['username'] ?? '') ?> ·
        <a href="../index.php">Zum Hauptprogramm</a> ·
        <a href="../logout.php">Abmelden</a>
    </div>
</div>

<div class="m-update-app" id="appUpdate">
    ✨ Neue Version verfügbar
    <button type="button" id="btnApplyUpdate" class="m-update-btn">Jetzt aktualisieren</button>
</div>
<style>
    .m-update-app {
        position: fixed; left: 12px; right: 12px; top: 16px; z-index: 50;
        background: #24282A; color: #fff; border: 1px solid #7A5E03; border-radius: 12px;
        padding: 14px 18px; font-weight: 700; font-size: 15px;
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        transform: translateY(-140%); transition: transform 0.3s ease;
        box-shadow: 0 4px 16px rgba(0,0,0,0.3);
    }
    .m-update-app.show { transform: translateY(0); }
    .m-update-btn {
        background: #fff; color: #24282A; border: none; border-radius: 8px;
        padding: 8px 14px; font-weight: 700; font-size: 14px; cursor: pointer; flex-shrink: 0;
    }
</style>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const reg = await navigator.serviceWorker.register('sw.js');
            reg.update().catch(() => {});
            const appBanner = document.getElementById('appUpdate');
            function checkWaiting() {
                if (reg.waiting && navigator.serviceWorker.controller) appBanner.classList.add('show');
            }
            checkWaiting();
            reg.addEventListener('updatefound', () => {
                const sw = reg.installing;
                if (sw) sw.addEventListener('statechange', checkWaiting);
            });
            const btn = document.getElementById('btnApplyUpdate');
            if (btn) btn.addEventListener('click', () => {
                if (reg.waiting) reg.waiting.postMessage({ cmd: 'skipWaiting' });
            });
            let reloaded = false;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (!reloaded) { reloaded = true; location.reload(); }
            });
        } catch (e) {}
    });
}
</script>

</body>
</html>

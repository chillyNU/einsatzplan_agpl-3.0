<?php
// ─────────────────────────────────────────────────────────────────────────────
// Gemeinsame Logik für die mobilen Mitarbeiter-Tagesseiten.
//
// Jede der 84 Einzelseiten (mo_1.php … so_12.php) setzt vor dem Einbinden dieser
// Datei zwei Variablen:
//     $MOBIL_WOCHENTAG  z.B. 'Montag'
//     $MOBIL_MITARBEITER  1..12 (nutzbar bis zum lizenzierten Umfang)
// und bindet dann diese Datei ein. So gibt es echte, aufrufbare Dateien pro
// Tag/Mitarbeiter, aber nur EINE Stelle, die gepflegt werden muss.
// ─────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/../config.php';

// Login: entweder aktive Session ODER gültiger 30-Tage-Token.
if (!isset($_SESSION['user_id'])) {
    remember_login_check($pdo);
}
if (!isset($_SESSION['user_id'])) {
    // Nach dem Login zurück auf genau diese Seite leiten
    $ziel = urlencode(basename($_SERVER['SCRIPT_NAME']));
    header('Location: ../login.php?mobil=' . $ziel);
    exit;
}

if (!function_exists('h')) {
    function h($t) { return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8'); }
}

$wochentag   = $MOBIL_WOCHENTAG ?? 'Montag';
$mitarbeiter = (int)($MOBIL_MITARBEITER ?? 1);

// Wochentag -> Offset ab Montag (0..6)
$tagOffset = [
    'Montag' => 0, 'Dienstag' => 1, 'Mittwoch' => 2, 'Donnerstag' => 3,
    'Freitag' => 4, 'Samstag' => 5, 'Sonntag' => 6,
][$wochentag] ?? 0;

// Gewählte Woche/Jahr (per Wisch/Navigation), Standard = aktuelle Woche
$selWeek = isset($_GET['w']) ? (int)$_GET['w'] : (int)date('W');
$selYear = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('o');

// Montag der gewählten Woche berechnen, dann den Ziel-Wochentag
$monday = new DateTime();
$monday->setISODate($selYear, $selWeek);
$zielDatum = clone $monday;
$zielDatum->modify("+{$tagOffset} days");
$datumStr = $zielDatum->format('Y-m-d');

// Ist der Mitarbeiter an diesem Wochentag überhaupt eingeplant?
$anzahlHeute = getAnzahlMitarbeiter($wochentag);
$eingeplant  = ($mitarbeiter <= $anzahlHeute);

// Mitarbeiter-Bezeichnung (ggf. umbenannt)
$maLabel = getLabel('mitarbeiter_' . $mitarbeiter, 'Mitarbeiter ' . $mitarbeiter);

// Für diese Woche zugewiesener Name aus "Mitarbeiter-Namen & Zeiträume"
// (techniker_zuordnung) – plus evtl. Vertretung (hat Vorrang).
$zugewiesenerName = '';
try {
    $kwStmt = $pdo->prepare(
        "SELECT name FROM techniker_zuordnung
         WHERE wochentag = ? AND techniker_nummer = ? AND jahr = ?
         AND (? BETWEEN kw_start AND kw_end) LIMIT 1"
    );
    $kwStmt->execute([$wochentag, $mitarbeiter, $selYear, $selWeek]);
    $zugewiesenerName = trim((string)$kwStmt->fetchColumn());
} catch (Exception $e) {}

// Vertretung für diesen Tag/Mitarbeiter (überschreibt den Namen)
$vertretung = '';
try {
    $vStmt = $pdo->prepare(
        "SELECT vertretung FROM tages_notizen WHERE datum = ? AND techniker_id = ? LIMIT 1"
    );
    $vStmt->execute([$datumStr, $mitarbeiter]);
    $vertretung = trim((string)$vStmt->fetchColumn());
} catch (Exception $e) {}

$anzeigeName = $vertretung !== '' ? $vertretung : $zugewiesenerName;

// Termine für diesen Tag + Mitarbeiter laden
$termine = [];
if ($eingeplant) {
    $stmt = $pdo->prepare(
        "SELECT * FROM dienste
         WHERE datum = ? AND techniker_id = ? AND deleted_at IS NULL
         ORDER BY uhrzeit ASC"
    );
    $stmt->execute([$datumStr, $mitarbeiter]);
    $termine = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Dynamische Optionen (Checkbox-Spalten) für die Anzeige
$dynOptionen = [];
try {
    $dynOptionen = $pdo->query("SELECT spalten_name, anzeige_name FROM termin_optionen ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {}

// „Fingerabdruck" der aktuellen Daten – für die Update-Erkennung.
// Ändert sich etwas an den Terminen dieses Tages, ändert sich der Hash.
$fingerprint = md5(json_encode($termine));

// AJAX-Endpunkt: liefert nur den aktuellen Fingerprint zurück (fürs Polling)
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    echo json_encode(['fp' => $fingerprint, 'count' => count($termine)]);
    exit;
}

// Navigations-Ziele
$prevWeek = (clone $monday)->modify('-7 days');
$nextWeek = (clone $monday)->modify('+7 days');
$prevW = (int)$prevWeek->format('W'); $prevY = (int)$prevWeek->format('o');
$nextW = (int)$nextWeek->format('W'); $nextY = (int)$nextWeek->format('o');

$selbst = basename($_SERVER['SCRIPT_NAME']);
$istHeute = ($datumStr === date('Y-m-d'));

// Firmenname (Footer-Konfiguration) und Programmversion für den Kopf
$firmenname = 'Firmenname';
try {
    $fval = $pdo->query("SELECT value FROM settings WHERE `key` = 'footer_text'")->fetchColumn();
    if ($fval !== false && $fval !== null && $fval !== '') $firmenname = $fval;
} catch (Exception $e) {}
$appVersion = defined('APP_VERSION') ? APP_VERSION : '';

function uhrzeitFmt($u) {
    if (preg_match('/^\d{4}$/', $u)) return substr($u,0,2).':'.substr($u,2);
    return $u;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#c8102e">
<title><?= h($maLabel) ?> · <?= h($wochentag) ?></title>
<link rel="stylesheet" href="../<?= asset('theme.css') ?>">
<?php
// Wochentag-Kürzel für PWA-Manifest/Icon (mo, di, mi, ...)
$__kuerzelMap = ['Montag'=>'mo','Dienstag'=>'di','Mittwoch'=>'mi','Donnerstag'=>'do',
                 'Freitag'=>'fr','Samstag'=>'sa','Sonntag'=>'so'];
$__tk = $__kuerzelMap[$wochentag] ?? 'mo';
?>
<!-- PWA: eigenes Manifest & Icon pro Mitarbeiter-Tagesseite (z.B. "Mo.1") -->
<link rel="manifest" href="manifest.php?tag=<?= $__tk ?>&ma=<?= $mitarbeiter ?>">
<link rel="apple-touch-icon" href="icon.php?tag=<?= $__tk ?>&ma=<?= $mitarbeiter ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= h(ucfirst($__tk) . '.' . $mitarbeiter) ?>">
<style>
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body {
        margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #eef1f5; color: #1d2733;
        padding-bottom: 40px;
    }
    .m-head {
        position: sticky; top: 0; z-index: 20;
        background: #c8102e; color: #fff;
        padding: 14px 16px calc(14px + env(safe-area-inset-top)); 
        padding-top: max(14px, env(safe-area-inset-top));
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .m-head .ma { font-size: 14px; font-weight: 500; opacity: 0.9; letter-spacing: 0.01em; }
    .m-head .ma-person { display: block; font-size: 24px; font-weight: 800; opacity: 1; margin-top: 1px; }
    .m-head .ma-vertretung { font-size: 13px; font-weight: 500; opacity: 0.85; }
    .m-head .tag { font-size: 14px; opacity: 0.92; margin-top: 6px; }
    /* Marke oben: Einsatzplan + Firmenname als klickbare Pille (löst Reload aus),
       heller Hintergrund abgesetzt vom roten Kopf. */
    .m-brand {
        display: inline-flex; align-items: center; gap: 10px; margin-bottom: 12px;
        background: rgba(255,255,255,0.16); border: 1px solid rgba(255,255,255,0.28);
        border-radius: 12px; padding: 8px 14px; text-decoration: none; color: #fff;
    }
    .m-brand:active { background: rgba(255,255,255,0.30); }
    .m-brand-txt { line-height: 1.15; }
    .m-app { font-size: 18px; font-weight: 800; letter-spacing: 0.02em; }
    .m-firma { font-size: 12px; opacity: 0.92; margin-top: 1px; }
    .m-nav {
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px; margin-top: 12px;
    }
    .m-nav a, .m-nav .kw {
        flex: 0 0 auto; text-decoration: none;
    }
    .m-nav .arrow {
        width: 44px; height: 44px; border-radius: 50%;
        background: rgba(255,255,255,0.18); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; font-weight: 700;
    }
    .m-nav .arrow:active { background: rgba(255,255,255,0.32); }
    .m-nav .kw {
        flex: 1 1 auto; text-align: center; font-weight: 700; font-size: 15px;
    }
    .m-nav .kw small { display:block; font-weight: 500; opacity: 0.85; font-size: 12px; margin-top: 2px; }

    .m-body { padding: 14px 12px; max-width: 640px; margin: 0 auto; }

    .m-today-badge {
        display: inline-block; background: #fff; color: #c8102e;
        font-size: 12px; font-weight: 800; padding: 2px 10px; border-radius: 20px;
        margin-top: 8px;
    }

    .card {
        background: #fff; border-radius: 14px; padding: 14px 16px;
        margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        border-left: 5px solid var(--t<?= $mitarbeiter ?>, #c8102e);
    }
    .card .zeit {
        font-size: 18px; font-weight: 800; color: #1d2733;
        display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;
    }
    .card .art { font-size: 14px; font-weight: 600; color: var(--t<?= $mitarbeiter ?>, #c8102e); }
    .card .kunde { font-size: 17px; font-weight: 700; margin: 8px 0 2px; }
    .card .adr { font-size: 14px; color: #55606d; line-height: 1.4; }
    .card .tel { margin-top: 8px; font-size: 14px; }
    .card .tel a { color: #c8102e; text-decoration: none; font-weight: 600; }
    .card .tel div { margin-top: 2px; }
    .card .bem {
        margin-top: 8px; font-size: 13px; color: #444;
        background: #f7f8fa; border-radius: 8px; padding: 8px 10px;
        white-space: pre-wrap;
    }
    .card .opts { margin-top: 8px; }
    .card .opt {
        display: inline-block; font-size: 12px; font-weight: 600;
        background: #eef4ff; color: #2f5eb3; border-radius: 6px;
        padding: 2px 8px; margin: 2px 4px 2px 0;
    }
    .card.ausgefallen { opacity: 0.6; }
    .card.ausgefallen .zeit::after { content: " · ausgefallen"; color: #c8102e; font-size: 13px; font-weight: 700; }

    .m-empty {
        text-align: center; color: #7a8592; padding: 50px 20px; font-size: 16px;
    }
    .m-empty .icon { font-size: 42px; display: block; margin-bottom: 12px; }

    /* Update-Banner */
    .m-update {
        position: fixed; left: 12px; right: 12px; bottom: 16px; z-index: 50;
        background: #c8102e; color: #fff; border-radius: 12px;
        padding: 14px 18px; text-align: center; font-weight: 700; font-size: 15px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.3); cursor: pointer;
        transform: translateY(120%); transition: transform 0.3s ease;
    }
    .m-update.show { transform: translateY(0); }
    .m-update small { display:block; font-weight: 500; opacity: 0.9; margin-top: 2px; }
    /* App-Update-Banner (neue PWA-Version) – oben, mit Aktions-Button */
    .m-update-app {
        top: 16px; bottom: auto; transform: translateY(-140%);
        background: #24282A; border: 1px solid #7A5E03;
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
    }
    .m-update-app.show { transform: translateY(0); }
    .m-update-btn {
        background: #fff; color: #24282A; border: none; border-radius: 8px;
        padding: 8px 14px; font-weight: 700; font-size: 14px; cursor: pointer; flex-shrink: 0;
    }

    .m-foot { text-align:center; color:#9aa4b0; font-size:12px; margin-top: 20px; }
    .m-foot a { color:#9aa4b0; }
</style>
</head>
<body data-fp="<?= h($fingerprint) ?>" data-self="<?= h($selbst) ?>" data-w="<?= $selWeek ?>" data-y="<?= $selYear ?>">

<div class="m-head">
    <a href="<?= h($selbst) ?>?w=<?= $selWeek ?>&y=<?= $selYear ?>" class="m-brand" title="Aktualisieren">
        <div class="m-brand-txt">
            <div class="m-app">Einsatzplan</div>
            <div class="m-firma"><?= h($firmenname) ?></div>
        </div>
    </a>
    <div class="ma"><?= h($maLabel) ?><?php if ($anzeigeName !== ''): ?><span class="ma-person"><?= h($anzeigeName) ?><?php if ($vertretung !== ''): ?> <span class="ma-vertretung">(Vertretung)</span><?php endif; ?></span><?php endif; ?></div>
    <div class="tag"><?= h($wochentag) ?>, <?= $zielDatum->format('d.m.Y') ?></div>
    <?php if ($istHeute): ?><span class="m-today-badge">HEUTE</span><?php endif; ?>
    <div class="m-nav">
        <a class="arrow" href="?w=<?= $prevW ?>&y=<?= $prevY ?>" aria-label="Vorherige Woche">‹</a>
        <div class="kw">KW <?= $selWeek ?> / <?= $selYear ?><small>zum Wechseln wischen oder tippen</small></div>
        <a class="arrow" href="?w=<?= $nextW ?>&y=<?= $nextY ?>" aria-label="Nächste Woche">›</a>
    </div>
</div>

<div class="m-body">
<?php if (!$eingeplant): ?>
    <div class="m-empty">
        <span class="icon">📴</span>
        An diesem Tag ist <?= h($maLabel) ?> nicht eingeplant
        (Tagesstärke: <?= (int)$anzahlHeute ?> Mitarbeiter).
    </div>
<?php elseif (empty($termine)): ?>
    <div class="m-empty">
        <span class="icon">🗓️</span>
        Keine Termine an diesem Tag.
    </div>
<?php else: ?>
    <?php foreach ($termine as $t): ?>
        <div class="card <?= !empty($t['ausgefallen']) ? 'ausgefallen' : '' ?>">
            <div class="zeit">
                <?= h(uhrzeitFmt($t['uhrzeit'])) ?>
                <?php if (!empty($t['ankunft'])): ?>
                    <span class="art">Ankunft: <?= h(uhrzeitFmt($t['ankunft'])) ?></span>
                <?php endif; ?>
            </div>
            <div class="art"><?= h($t['einsatzart']) ?><?= (!empty($t['unterart']) && $t['unterart'] !== '-') ? ' · ' . h($t['unterart']) : '' ?></div>

            <?php if (!empty($t['name'])): ?><div class="kunde"><?= h($t['name']) ?></div><?php endif; ?>

            <?php if (!empty($t['strasse']) || !empty($t['ort'])): ?>
                <?php
                    $adr = trim($t['strasse'] ?? '');
                    if (!empty($t['zusatzinfo'])) $adr .= ' (' . $t['zusatzinfo'] . ')';
                    $ortzeile = trim(($t['plz'] ?? '') . ' ' . ($t['ort'] ?? ''));
                    $mapsQuery = urlencode(trim($adr . ', ' . $ortzeile));
                ?>
                <div class="adr">
                    📍 <a href="https://maps.google.com/?q=<?= $mapsQuery ?>" style="color:inherit; text-decoration:none;">
                        <?= h($adr) ?><?= $ortzeile ? ', ' . h($ortzeile) : '' ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php
                $telAusgaben = [];
                for ($n = 1; $n <= 3; $n++) {
                    if (!empty($t['telefon' . $n])) {
                        $label = $t['tel' . $n . '_name'] ?: 'Tel ' . $n;
                        $telAusgaben[] = ['label' => $label, 'nr' => $t['telefon' . $n]];
                    }
                }
            ?>
            <?php if ($telAusgaben): ?>
                <div class="tel">
                    <?php foreach ($telAusgaben as $tel): ?>
                        <div><strong><?= h($tel['label']) ?>:</strong>
                            <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $tel['nr'])) ?>"><?= h($tel['nr']) ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
                $optAusgaben = [];
                foreach ($dynOptionen as $opt) {
                    $col = $opt['spalten_name'];
                    if (!empty($t[$col])) $optAusgaben[] = $opt['anzeige_name'];
                }
            ?>
            <?php if ($optAusgaben): ?>
                <div class="opts">
                    <?php foreach ($optAusgaben as $o): ?><span class="opt">✔ <?= h($o) ?></span><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($t['bemerkung'])): ?>
                <div class="bem"><?= h($t['bemerkung']) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

    <div class="m-foot">
        Eingeloggt als <?= h($_SESSION['username'] ?? '') ?> ·
        <a href="../logout.php">Abmelden</a>
    </div>
</div>

<div class="m-update" id="mUpdate" onclick="location.reload()">
    🔄 Neue Daten verfügbar
    <small>Tippen zum Aktualisieren</small>
</div>

<div class="m-update m-update-app" id="appUpdate">
    ✨ Neue Version verfügbar
    <button type="button" id="btnApplyUpdate" class="m-update-btn">Jetzt aktualisieren</button>
</div>

<script>
(function () {
    const body = document.body;
    const self = body.getAttribute('data-self');
    const w = body.getAttribute('data-w');
    const y = body.getAttribute('data-y');
    const startFp = body.getAttribute('data-fp');
    const banner = document.getElementById('mUpdate');

    // --- Update-Erkennung: regelmäßig den Fingerprint prüfen ---
    let updateGemeldet = false;
    async function checkUpdate() {
        if (updateGemeldet) return;
        try {
            const r = await fetch(`${self}?check=1&w=${w}&y=${y}`, { cache: 'no-store' });
            const d = await r.json();
            if (d && d.fp && d.fp !== startFp) {
                banner.classList.add('show');
                updateGemeldet = true;
            }
        } catch (e) {}
    }
    setInterval(checkUpdate, 8000);
    // Sofort prüfen, wenn die Seite wieder in den Vordergrund kommt
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) checkUpdate();
    });
    window.addEventListener('focus', checkUpdate);

    // --- Wisch-Navigation zwischen den Wochen ---
    let touchX = null, touchY = null;
    const links = {
        left:  document.querySelector('.m-nav a.arrow[aria-label="Nächste Woche"]'),
        right: document.querySelector('.m-nav a.arrow[aria-label="Vorherige Woche"]')
    };
    document.addEventListener('touchstart', e => {
        if (e.touches.length !== 1) return;
        touchX = e.touches[0].clientX; touchY = e.touches[0].clientY;
    }, { passive: true });
    document.addEventListener('touchend', e => {
        if (touchX === null) return;
        const dx = e.changedTouches[0].clientX - touchX;
        const dy = e.changedTouches[0].clientY - touchY;
        touchX = touchY = null;
        // nur horizontale, deutliche Wische werten
        if (Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
        if (dx < 0 && links.left)  { window.location.href = links.left.href; }
        if (dx > 0 && links.right) { window.location.href = links.right.href; }
    }, { passive: true });

    // --- PWA: Service Worker registrieren & Versions-Update erkennen ---
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async () => {
            try {
                const reg = await navigator.serviceWorker.register('sw.js');
                reg.update().catch(() => {});

                const appBanner = document.getElementById('appUpdate');
                function checkWaiting() {
                    // reg.waiting = neue Version steht bereit.
                    // controller-Prüfung: beim ALLERERSTEN Besuch kein Banner.
                    if (reg.waiting && navigator.serviceWorker.controller) {
                        appBanner.classList.add('show');
                    }
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

                // Neue Version hat übernommen -> Seite genau EINMAL neu laden
                let reloaded = false;
                navigator.serviceWorker.addEventListener('controllerchange', () => {
                    if (!reloaded) { reloaded = true; location.reload(); }
                });
            } catch (e) {}
        });
    }
})();
</script>
</body>
</html>

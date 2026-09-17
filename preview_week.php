<?php
require_once 'init_db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$selectedWeek = isset($_GET['w']) ? (int)$_GET['w'] : (int)date('W');
$selectedYear = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');

// KW-Grenzen
if ($selectedWeek < 1) {
    $selectedYear--;
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

$notiz_stmt = $pdo->prepare("SELECT * FROM tages_notizen WHERE datum BETWEEN ? AND ?");
$notiz_stmt->execute([$startdate, $enddate]);
$tagesDaten = [];
while ($n = $notiz_stmt->fetch(PDO::FETCH_ASSOC)) {
    $tagesDaten[$n['datum']][$n['techniker_id']] = [
        'notiz' => $n['notiz'],
        'vertretung' => $n['vertretung'] ?? ''
    ];
}

$styleFile = __DIR__ . '/column_settings.json';
$remoteStyles = file_exists($styleFile) ? json_decode(file_get_contents($styleFile), true) : [];

function getTechnikerNamePrev($tag, $tId, $jahr, $kw, $pdo) {
    $stmt = $pdo->prepare("SELECT name FROM techniker_zuordnung WHERE wochentag=? AND techniker_nummer=? AND jahr=? AND (? BETWEEN kw_start AND kw_end) LIMIT 1");
    $stmt->execute([$tag, $tId, $jahr, $kw]);
    return $stmt->fetchColumn() ?: "–";
}

if (!function_exists('h')) {
    function h($text) { return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8'); }
}

function getLabel2($key, $default, $pdo) {
    $s = $pdo->prepare("SELECT value FROM settings WHERE `key` = ?");
    $s->execute([$key]);
    return $s->fetchColumn() ?: $default;
}

$timeSlots = [];
$start = new DateTime('08:00');
$end   = new DateTime('17:30');
while ($start < $end) {
    $timeSlots[] = $start->format('H:i');
    $start->modify('+30 minutes');
}

$wochentage = $wochenendeAnzeigen === '1'
    ? ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag']
    : ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag'];

$numDays = count($wochentage);
$label1 = getLabel2('mitarbeiter_1', 'MA 1', $pdo);
$label2 = getLabel2('mitarbeiter_2', 'MA 2', $pdo);

// ─────────────────────────────────────────────────────────────────────────────
// DRUCKANSICHT (&print=1): übersichtliche Tabelle im Querformat.
// Pro Tag eine Sektion, darin je Mitarbeiter dessen Termine chronologisch.
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['print'])) {
    $mondayFmt = date('d.m.Y', $mondayThisWeek);
    $lastFmt   = date('d.m.Y', strtotime("+" . ($numDays - 1) . " days", $mondayThisWeek));
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8">
<title>Wochenansicht KW <?= $selectedWeek ?> / <?= $selectedYear ?></title>
<style>
    @page { size: A4 landscape; margin: 10mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #111; font-size: 11px; }
    .p-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; border-bottom: 2px solid #c8102e; padding-bottom: 6px; }
    .p-head h1 { font-size: 17px; margin: 0; color: #c8102e; }
    .p-head .range { font-size: 12px; color: #333; }
    .p-days { display: grid; grid-template-columns: repeat(<?= min($numDays, 4) ?>, 1fr); gap: 8px; }
    .p-day { border: 1px solid #bbb; border-radius: 5px; overflow: hidden; break-inside: avoid; }
    .p-day-h { background: #eee; font-weight: bold; padding: 4px 6px; font-size: 12px; border-bottom: 1px solid #bbb; }
    .p-day-h .today { color: #c8102e; }
    .p-ma { padding: 4px 6px; border-bottom: 1px dashed #ddd; }
    .p-ma:last-child { border-bottom: none; }
    .p-ma-name { font-weight: bold; font-size: 11px; margin-bottom: 2px; padding: 1px 4px; border-radius: 3px; display: inline-block; }
    .p-ma-1 .p-ma-name { background: #dff0ea; color: #0e7c66; }
    .p-ma-2 .p-ma-name { background: #e4ecfb; color: #2f5eb3; }
    .p-ma-3 .p-ma-name { background: #dcf2f5; color: #0e91a6; }
    .p-ma-4 .p-ma-name { background: #f6ecd9; color: #c07a1e; }
    .p-ma-5 .p-ma-name { background: #f0ecf9; color: #6d4fb3; }
    .p-ma-6 .p-ma-name { background: #f9e9f1; color: #b02d6e; }
    .p-ma-7 .p-ma-name { background: #f3f4e3; color: #7a821c; }
    .p-ma-8 .p-ma-name { background: #faece6; color: #b5451f; }
    .p-ma-9 .p-ma-name { background: #ecf0f5; color: #56708c; }
    .p-ma-10 .p-ma-name { background: #eaf2e4; color: #3e6b23; }
    .p-ma-11 .p-ma-name { background: #f4ede5; color: #8a5a2e; }
    .p-ma-12 .p-ma-name { background: #ebecf5; color: #4a4f8c; }
    .p-appt { margin: 2px 0 3px; padding-left: 4px; border-left: 2px solid #ccc; }
    .p-appt .t { font-weight: bold; }
    .p-appt .sub { color: #444; }
    .p-appt.aus { opacity: 0.55; text-decoration: line-through; }
    .p-empty { color: #999; font-style: italic; font-size: 10px; }
    .p-frei { background: #e4e7eb; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .p-frei .p-ma-name { background: #cfd4da !important; color: #4a5058 !important; }
    .p-frei-hint { color: #6b7178; font-style: italic; font-size: 10px; font-weight: bold; }
    @media print { .p-day { break-inside: avoid; } }
</style></head><body>
<div class="p-head">
    <h1>Wochenansicht KW <?= $selectedWeek ?> / <?= $selectedYear ?></h1>
    <span class="range"><?= $mondayFmt ?> – <?= $lastFmt ?></span>
</div>
<div class="p-days">
<?php foreach ($wochentage as $i => $tagName):
    $tagDate = date('Y-m-d', strtotime("+$i days", $mondayThisWeek));
    $isToday = ($tagDate === date('Y-m-d'));
    $anzahlHeute = getAnzahlMitarbeiter($tagName);
?>
    <div class="p-day">
        <div class="p-day-h"><?= h($tagName) ?><?= $isToday ? ' <span class="today">(heute)</span>' : '' ?>, <?= date('d.m.Y', strtotime($tagDate)) ?></div>
        <?php for ($tId = 1; $tId <= $anzahlHeute; $tId++):
            $techNameStr = getTechnikerNamePrev($tagName, $tId, $selectedYear, $selectedWeek, $pdo);
            $data = $tagesDaten[$tagDate][$tId] ?? ['vertretung' => ''];
            $vName = trim($data['vertretung'] ?? '');
            $anzeigeName = !empty($vName) ? $vName : $techNameStr;
            $maLabel = getLabel2('mitarbeiter_' . $tId, 'MA ' . $tId, $pdo);
            $hatName = (!empty($vName)) || ($techNameStr !== '–' && $techNameStr !== '');
            $kopf = $hatName ? ($anzeigeName . (!empty($vName) ? ' (Vertretung)' : '')) : $maLabel;
            // "Frei"-Markierung (grau) aus den gespeicherten Spalten-Einstellungen
            $isFrei = ($remoteStyles[$tagDate][$tId] ?? false);
            // Termine dieses MA an diesem Tag sammeln (chronologisch)
            $appts = [];
            foreach ($timeSlots as $slot) {
                if (isset($plan[$tagDate][$slot][$tId])) $appts[] = $plan[$tagDate][$slot][$tId];
            }
        ?>
        <div class="p-ma p-ma-<?= $tId ?><?= $isFrei ? ' p-frei' : '' ?>">
            <div class="p-ma-name"><?= h($kopf) ?><?= $hatName ? ' · ' . h($maLabel) : '' ?></div>
            <?php if ($isFrei): ?>
                <div class="p-frei-hint">Frei / nicht im Dienst</div>
            <?php elseif (empty($appts)): ?>
                <div class="p-empty">keine Termine</div>
            <?php else: foreach ($appts as $a):
                $aus = (!empty($a['ausgefallen']) && (int)$a['ausgefallen'] === 1);
                $ort = trim(($a['plz'] ?? '') . ' ' . ($a['ort'] ?? ''));
            ?>
                <div class="p-appt<?= $aus ? ' aus' : '' ?>">
                    <span class="t"><?= h($a['uhrzeit'] ?? '') ?></span>
                    <?php if (!empty($a['einsatzart'])): ?><span class="sub"> · <?= h($a['einsatzart']) ?></span><?php endif; ?>
                    <?php if (!empty($a['name'])): ?><br><?= h($a['name']) ?><?php endif; ?>
                    <?php if (!empty($a['strasse']) || $ort): ?><br><span class="sub"><?= h(trim(($a['strasse'] ?? '') . ($ort ? ', ' . $ort : ''))) ?></span><?php endif; ?>
                    <?php if ($aus): ?> <span class="sub">(ausgefallen)</span><?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php endfor; ?>
    </div>
<?php endforeach; ?>
</div>
</body></html>
    <?php
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<div class="pw-grid" data-days="<?= $numDays ?>" style="display:grid; grid-template-columns: repeat(<?= $numDays ?>, minmax(max-content, 1fr)); gap: 4px; width:100%; height:100%; overflow:auto;">
<?php foreach ($wochentage as $i => $tagName):
    $tagDate = date('Y-m-d', strtotime(sprintf('+%s days', $i), $mondayThisWeek));
    $isToday = ($tagDate === date('Y-m-d'));
?>
  <?php $anzahlHeute = getAnzahlMitarbeiter($tagName); ?>
  <div class="pw-day<?= $isToday ? ' pw-today' : '' ?>">
    <div class="pw-day-header"><?= (function_exists('mb_substr') ? mb_substr($tagName, 0, 2) : substr($tagName, 0, 2)) ?><br><small><?= date('d.m', strtotime($tagDate)) ?></small></div>
    <div style="display:flex; gap:2px; flex:1; min-height:0;">
<?php for ($tId = 1; $tId <= $anzahlHeute; $tId++):
    $techNameStr = getTechnikerNamePrev($tagName, $tId, $selectedYear, $selectedWeek, $pdo);
    $data = $tagesDaten[$tagDate][$tId] ?? ['notiz' => '', 'vertretung' => ''];
    $vName = trim($data['vertretung'] ?? '');
    $anzeigeName = !empty($vName) ? $vName : $techNameStr;
    $isDark = ($remoteStyles[$tagDate][$tId] ?? false);
    $maLabel = getLabel2('mitarbeiter_' . $tId, 'MA ' . $tId, $pdo);
?>
      <div class="pw-tech pw-tech-<?= $tId ?><?= $isDark ? ' pw-dark' : '' ?>">
        <div class="pw-tech-header" title="<?= h($anzeigeName) ?>">
          <?php $hatNamePw = (!empty($vName)) || ($techNameStr !== '–' && $techNameStr !== ''); ?>
          <?php if ($hatNamePw): ?>
            <span class="pw-tech-name"><?= h($anzeigeName) ?></span>
          <?php else: ?>
            <?= h($maLabel) ?>
          <?php endif; ?>
          <?php if (!empty($vName)): ?><span class="pw-v">V</span><?php endif; ?>
        </div>
<?php foreach ($timeSlots as $slot):
    $termin = $plan[$tagDate][$slot][$tId] ?? null;
    $isAusgefallen = ($termin && !empty($termin['ausgefallen']) && (int)$termin['ausgefallen'] === 1);
?>
        <?php if ($termin): ?>
          <?php
            $tooltipParts = [];
            if (!empty($termin['uhrzeit']))    $tooltipParts[] = '⏰ ' . h($termin['uhrzeit']) . ' Uhr';
            if (!empty($termin['einsatzart'])) $tooltipParts[] = '📋 ' . h($termin['einsatzart']);
            if (!empty($termin['unterart']) && $termin['unterart'] !== '-')
                                           $tooltipParts[] = '↳ ' . h($termin['unterart']);
            if (!empty($termin['name']))       $tooltipParts[] = '👤 ' . h($termin['name']);
            if (!empty($termin['strasse']))    $tooltipParts[] = '📍 ' . h($termin['strasse']);
            if (!empty($termin['plz']) || !empty($termin['ort']))
                                           $tooltipParts[] = '&nbsp;&nbsp;&nbsp;' . h(trim(($termin['plz'] ?? '') . ' ' . ($termin['ort'] ?? '')));
            if (!empty($termin['telefon1']))   $tooltipParts[] = '📞 ' . h($termin['telefon1']);
            if (!empty($termin['bemerkung']))  $tooltipParts[] = '📝 ' . nl2br(h($termin['bemerkung']));
            if ($isAusgefallen)                $tooltipParts[] = '<span style="color:#f88;">⚠️ Ausgefallen</span>';
            $tooltip = implode('<br>', $tooltipParts);

            $shortOrt = h($termin['ort'] ?? '');
            $shortTime = h($termin['uhrzeit'] ?? '');
            $textClass = $isAusgefallen ? 'pw-slot-text aus' : 'pw-slot-text';
            $slotClass = $isAusgefallen ? 'pw-slot pw-aus' : 'pw-slot pw-booked';
          ?>
          <div class="<?= $slotClass ?>"
            data-uhrzeit="<?= h($termin['uhrzeit'] ?? '') ?>"
            data-einsatzart="<?= h($termin['einsatzart'] ?? '') ?>"
            data-unterart="<?= h(($termin['unterart'] ?? '') !== '-' ? ($termin['unterart'] ?? '') : '') ?>"
            data-name="<?= h($termin['name'] ?? '') ?>"
            data-strasse="<?= h($termin['strasse'] ?? '') ?>"
            data-plz="<?= h($termin['plz'] ?? '') ?>"
            data-ort="<?= h($termin['ort'] ?? '') ?>"
            data-telefon="<?= h($termin['telefon1'] ?? '') ?>"
            data-bemerkung="<?= h($termin['bemerkung'] ?? '') ?>"
            data-ausgefallen="<?= $isAusgefallen ? '1' : '0' ?>"
            onmouseenter="showPwPopup(event,this)"
            onmouseleave="hidePwPopup()">
            <div class="<?= $textClass ?>"><?= $shortTime ?> <?= $shortOrt ?></div>
          </div>
        <?php else: ?>
          <div class="pw-slot pw-free"></div>
        <?php endif; ?>
<?php endforeach; ?>
      </div>
<?php endfor; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
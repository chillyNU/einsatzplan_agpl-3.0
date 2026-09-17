<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function getTechnikerName($tag, $tId, $jahr, $kw, $pdo) {
    $stmt = $pdo->prepare("SELECT name FROM techniker_zuordnung 
                           WHERE wochentag=? AND techniker_nummer=? AND jahr=? 
                           AND (? BETWEEN kw_start AND kw_end) LIMIT 1");
    $stmt->execute([$tag, $tId, $jahr, $kw]);
    $name = $stmt->fetchColumn();
    return $name ?: "Nicht zugewiesen";
}

$dynamischeOptionen = [];
try {
    $stmtOpt = $pdo->query("SELECT spalten_name, anzeige_name FROM termin_optionen ORDER BY id ASC");
    if ($stmtOpt) {
        $dynamischeOptionen = $stmtOpt->fetchAll();
    }
} catch (Exception $e) {}

$datum  = $_GET['datum'] ?? date('Y-m-d');
$techId = (int)($_GET['tech'] ?? 1);
$dateObj = new DateTime($datum);
$kw      = (int)$dateObj->format('W');
$year    = (int)$dateObj->format('o'); 
$deTag = [
    'Monday'    => 'Montag',
    'Tuesday'   => 'Dienstag',
    'Wednesday' => 'Mittwoch',
    'Thursday'  => 'Donnerstag',
    'Friday'    => 'Freitag',
    'Saturday'  => 'Samstag',
    'Sunday'    => 'Sonntag'
][$dateObj->format('l')] ?? 'Montag';

$stammName = getTechnikerName($deTag, $techId, $year, $kw, $pdo);
$stmtN = $pdo->prepare("SELECT notiz, vertretung FROM tages_notizen WHERE datum = ? AND techniker_id = ?");
$stmtN->execute([$datum, $techId]);
$tagesDaten = $stmtN->fetch();
$tagesNotiz = trim($tagesDaten['notiz'] ?? '');
$vName      = trim($tagesDaten['vertretung'] ?? '');

$hauptName = !empty($vName) ? $vName : $stammName;
$subInfo   = !empty($vName) ? "i. v. für " . $stammName : "";

$stmt = $pdo->prepare("SELECT * FROM dienste WHERE datum = ? AND techniker_id = ? AND deleted_at IS NULL ORDER BY uhrzeit ASC");
$stmt->execute([$datum, $techId]);
$results = $stmt->fetchAll();
$dayPlan = [];
foreach ($results as $r) { $dayPlan[$r['uhrzeit']] = $r; }

function getPrintSlots() {
    $slots = [];
    $start = new DateTime('08:00');
    $end = new DateTime('17:30'); 
    while ($start < $end) { $slots[] = $start->format('H:i'); $start->modify('+30 minutes'); }
    return $slots;
}
$timeSlots = getPrintSlots();
$formatedDate = date('d.m.Y', strtotime($datum));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Druck_<?= $datum ?>_T<?= $techId ?></title>
    <style>
    html, body { width: 100%; margin: 0; padding: 0; font-family: Arial, sans-serif; font-size: 13px; color: #000; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-sizing: border-box; }
    .no-print { width: 100%; background: #eee; padding: 15px; border-bottom: 1px solid #ccc; text-align: center; }
    .print-container { width: 100%; max-width: 190mm; margin: 10mm auto; padding: 0 5mm; }
    .header { border-bottom: 3px solid #d81e2c; margin-bottom: 15px; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-end; }
    .header h1 { margin: 0; color: #d81e2c; font-size: 22px; text-transform: uppercase; }
    .tech-main-name { font-size: 22px; font-weight: bold; display: block; line-height: 1.1; }
    table { width: 100%; border-collapse: collapse; border: 1px solid #000; table-layout: fixed; }
    th, td { border: 1px solid #000; padding: 6px 10px; text-align: left; vertical-align: top; overflow-wrap: break-word; }
    .time-col { width: 60px; white-space: nowrap; font-weight: bold; font-size: 16px; text-align: center; background: #fafafa; }
    .details-col { width: auto; position: relative; }
    .badge-wrapper { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; margin-bottom: 4px; }
    .badge-art { font-size: 10px; font-weight: bold; border: 1px solid #000; padding: 1px 4px; background: #eee; }
    .badge-unterart { font-size: 10px; font-weight: bold; border: 1px solid #d81e2c; color: #d81e2c; padding: 1px 4px; }
    .option-print-item { font-size: 11px; font-weight: normal; color: #000; background: #f9f9f9; border: 1px solid #ccc; padding: 1px 3px; border-radius: 2px; }
    .customer-name { font-weight: bold; font-size: 15px; margin-right: 5px; }
    .main-info-line { line-height: 1.3; }
    .tel-row { font-size: 12px; margin-top: 5px; color: #333; display: flex; flex-wrap: wrap; }
    .bemerkung { font-size: 11px; margin-top: 6px; border-left: 2px solid #ddd; padding: 4px; background: #f9f9f9; color: #444; }
    .print-kuerzel-row { margin-top: 8px; padding-top: 4px; border-top: 1px dotted #ccc; font-size: 9px; color: #777; display: flex; justify-content: flex-end; gap: 10px; }
    .strikethrough { text-decoration: line-through; color: #888; opacity: 0.8; }
    @media print { .no-print { display: none; } @page { size: A4 portrait; margin: 10mm; } .print-container { margin: 0 auto; width: 100%; max-width: 100%; } }
    </style>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()" style="padding: 10px 20px; background: #d81e2c; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">🖨️ Plan jetzt drucken</button>
    <button onclick="window.close()" style="padding: 10px 20px; margin-left: 10px; cursor: pointer;">Schließen</button>
</div>
<div class="print-container">
    <div class="header">
        <div>
            <h1>Einsatzplan</h1>
            <div class="tech-box">
                <span class="tech-label" style="font-size:11px; color:#666; text-transform:uppercase;">
                    <?= getLabel('mitarbeiter_' . $techId, 'Mitarbeiter ' . $techId) ?>
                </span>
                <span class="tech-main-name"><?= h($hauptName) ?></span>
                <?php if($subInfo): ?>
                    <span style="font-size: 13px; font-style: italic; color: #555;"><?= h($subInfo) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 1.4em; font-weight: bold;"><?= $formatedDate ?></div>
            <div style="font-size: 1.1em;"><?= $deTag ?> (KW <?= $kw ?>)</div>
        </div>
    </div>
    <?php if (!empty($tagesNotiz)): ?>
        <div style="background: #fff9c4; border: 1px dashed #fbc02d; padding: 10px; margin-bottom: 15px; text-align: center; font-weight: bold; font-size: 15px; color: #000;">
            ⚠️ <?= h($tagesNotiz) ?>
        </div>
    <?php endif; ?>
    <table>
        <tbody>
            <?php foreach ($timeSlots as $slot): $t = $dayPlan[$slot] ?? null; ?>
                <tr>
                    <td class="time-col"><?= $slot ?></td>
                    <td class="details-col">
                    <?php if ($t): ?>
                        <div class="<?= ($t['ausgefallen'] == 1) ? 'strikethrough' : '' ?>">
                            <div class="badge-wrapper">
                                <?php if (!empty($t['einsatzart']) || !empty($t['ankunft'])): ?>
                                <span class="badge-art">
                                    <?= h($t['einsatzart']) ?> 
                                    <?php if(!empty($t['ankunft'])): ?> 
                                        (ca. <?= preg_match('/^\d{4}$/', $t['ankunft']) ? substr($t['ankunft'],0,2).':'.substr($t['ankunft'],2) : h($t['ankunft']) ?> Uhr) 
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($t['unterart']) && $t['unterart'] !== '-'): ?><span class="badge-unterart"><?= h($t['unterart']) ?></span><?php endif; ?>
                                <?php 
                                    foreach($dynamischeOptionen as $opt) {
                                        $col = $opt['spalten_name'];
                                        if(!empty($t[$col])) { echo '<span class="option-print-item">✔ '.h($opt['anzeige_name']).'</span>'; }
                                    }
                                ?>
                            </div>
                            <div class="main-info-line">
                                <span class="customer-name"><?= h($t['name']) ?></span>
                                <span class="address-part">📍 <?= h($t['strasse']) ?><?= !empty($t['zusatzinfo']) ? " (".h($t['zusatzinfo']).")" : "" ?>, <?= h($t['plz'] ?? '') ?> <?= h($t['ort']??'') ?></span>
                            </div>
                            <div class="tel-row">
                                <?php if(!empty($t['pflegekasse'])): ?><span style="margin-right:12px;"><strong>Geräte-ID:</strong> <?= h($t['pflegekasse']) ?></span><?php endif; ?>
                                <?php for($n=1;$n<=3;$n++): if(!empty($t['telefon'.$n])): ?>
                                    <span style="margin-right: 12px;"><strong><?= h($t['tel'.$n.'_name'] ?: "Tel $n") ?>:</strong> <?= h($t['telefon'.$n]) ?></span>
                                <?php endif; endfor; ?>
                            </div>
                            <?php if(!empty($t['bemerkung'])): ?><div class="bemerkung"><?= nl2br(h($t['bemerkung'])) ?></div><?php endif; ?>
                            <div class="print-kuerzel-row">
                                <span>erstellt: <strong><?= h($t['created_by_kuerzel'] ?: '-') ?></strong></span>
                                <?php if(!empty($t['edit2_kuerzel'])): ?>
                                    <span>vorletzte Änderung: <strong><?= h($t['edit2_kuerzel']) ?></strong></span>
                                <?php endif; ?>
                                <span>letzte Änderung: <strong><?= h($t['updated_by_kuerzel'] ?: '-') ?></strong></span>
                            </div>
                        </div> 
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
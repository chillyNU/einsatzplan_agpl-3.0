<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$q = $_GET['q'] ?? '';
$y = (int)($_GET['y'] ?? date('Y'));   // erzwungen numerisch (verhindert HTML-Einschleusung bei der Ausgabe)
if ($y < 2000 || $y > 2100) { $y = (int)date('Y'); }
$limit = 10;

if (strlen($q) < 2) {
    echo "<p style='padding:20px; text-align:center;'>Bitte mindestens 2 Zeichen eingeben.</p>";
    exit;
}

// 1. Suche in Wörter zerlegen für strikte UND-Verknüpfung
$words = explode(' ', $q);
$whereParts = [];
$params = [];

foreach ($words as $word) {
    $word = trim($word);
    if (empty($word)) continue;
    $searchTerm = "%$word%";
    $whereParts[] = "(name LIKE ? OR strasse LIKE ? OR ort LIKE ? OR bemerkung LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSql = implode(" AND ", $whereParts);
$yearSql = "datum LIKE ?";
$params[] = "$y-%";

// 2. Berechnung der Seite für "heute"
$today = date('Y-m-d');
$stmtCountPast = $pdo->prepare("SELECT COUNT(*) FROM dienste WHERE datum < ? AND $whereSql AND deleted_at IS NULL AND $yearSql");
$stmtCountPast->execute(array_merge([$today], $params));
$countPast = (int)$stmtCountPast->fetchColumn();

if (!isset($_GET['page'])) {
    $page = (int)ceil(($countPast + 1) / $limit);
} else {
    $page = (int)$_GET['page'];
}

// 3. Gesamtzahl für Paginierung
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM dienste WHERE $whereSql AND deleted_at IS NULL AND $yearSql");
$stmtCount->execute($params);
$total = $stmtCount->fetchColumn();
$totalPages = ceil($total / $limit);

if ($page < 1) $page = 1;
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $limit;

// 4. Ergebnisse laden (Aufsteigend: Heute -> Zukunft)
$dataSql = "SELECT * FROM dienste WHERE $whereSql AND deleted_at IS NULL AND $yearSql ORDER BY datum ASC, uhrzeit ASC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($dataSql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$results) {
    echo "<p style='padding:20px; text-align:center;'>Keine Ergebnisse für '" . htmlspecialchars($q) . "' im Jahr $y gefunden.</p>";
    exit;
}
?>

<style>
    .search-table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
    .search-table th { background: var(--surface-alt, #f6f8fa); text-align: left; padding: 12px; border-bottom: 2px solid var(--brand, #d81e2c); color: var(--ink-soft, #5b6875); font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.05em; }
    .search-table td { padding: 10px 12px; border-bottom: 1px solid var(--line, #dde3ea); vertical-align: top; }
    .search-table tr:hover { background: var(--brand-tint, #fdecee); }
    .res-ausgefallen td { opacity: 0.6; }
    .res-ausgefallen .res-link { text-decoration: line-through; }

    .pagination-container {
        display: flex; justify-content: space-between; align-items: center;
        padding: 15px; border-top: 2px solid var(--brand, #d81e2c); background: var(--surface, #fff); margin-top: 15px;
    }
    .pg-btn {
        padding: 8px 16px; border: 1px solid var(--brand, #d81e2c); background: var(--surface, #fff);
        color: var(--brand, #d81e2c); cursor: pointer; border-radius: var(--radius-sm, 6px); font-weight: bold;
    }
    .pg-btn:hover:not(:disabled) { background: var(--brand, #d81e2c); color: #fff; }
    .pg-btn:disabled { border-color: var(--line-strong, #c8d1db); color: var(--line-strong, #c8d1db); cursor: not-allowed; }
    .pg-select { padding: 8px; border: 1px solid var(--brand, #d81e2c); border-radius: var(--radius-sm, 6px); font-weight: bold; color: var(--brand, #d81e2c); }

    .res-link { text-decoration: none; color: var(--brand, #d81e2c); font-weight: bold; }
    .res-link:hover { text-decoration: underline; }
    .badge-aus { color: #fff; background: var(--ink-soft, #5b6875); padding: 2px 6px; border-radius: 4px; font-size: 0.75em; text-transform: uppercase; }
    .detail-label { font-size: 0.85em; color: var(--ink-soft, #5b6875); }
    .small-info { font-size: 0.85em; color: var(--ink-soft, #5b6875); }
</style>

<table class="search-table">
    <thead>
        <tr>
            <th>Datum / Zeit</th>
            <th>Name / Ort / Straße</th>
            <th>Einsatzart / Info</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($results as $r): 
            $isAus = ($r['ausgefallen'] ?? 0) == 1;
            $dateTimestamp = strtotime($r['datum']);
        ?>
        <tr class="<?= $isAus ? 'res-ausgefallen' : '' ?>">
            <td>
                <a class="res-link" href="index.php?w=<?= date('W', $dateTimestamp) ?>&y=<?= date('o', $dateTimestamp) ?>#day-<?= htmlspecialchars($r['datum']) ?>" 
                   onclick="document.getElementById('searchResultModal').style.display='none';">
                    <?= date('d.m.y', $dateTimestamp) ?><br><small><?= htmlspecialchars($r['uhrzeit']) ?></small>
                </a>
            </td>
            <td>
                <strong><?= htmlspecialchars($r['name']) ?></strong><br>
                <span class="small-info">
                    <?= htmlspecialchars($r['strasse']) ?><br>
                    <?= htmlspecialchars($r['plz']) ?> <?= htmlspecialchars($r['ort']) ?>
                </span>
            </td>
            <td>
                <span style="font-weight:bold;"><?= htmlspecialchars($r['einsatzart'] ?: '-') ?></span>
                <?php if($isAus): ?><br><span class="badge-aus">AUSGEFALLEN</span><?php endif; ?>
                <?php if(!empty($r['bemerkung'])): ?>
                    <span class="detail-label"><br>Bem: <?= htmlspecialchars(substr($r['bemerkung'], 0, 30)) ?><?= strlen($r['bemerkung']) > 30 ? '...' : '' ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="pagination-container">
    <button class="pg-btn" <?= ($page <= 1) ? 'disabled' : '' ?> 
            onclick="performSearch(<?= $page - 1 ?>)">← Zurück (Früher)</button>

    <select class="pg-select" onchange="performSearch(this.value)">
        <?php for($i=1; $i<=$totalPages; $i++): ?>
            <option value="<?= $i ?>" <?= $i == $page ? 'selected' : '' ?>>
                Seite <?= $i ?> von <?= $totalPages ?>
            </option>
        <?php endfor; ?>
    </select>

    <button class="pg-btn" <?= ($page >= $totalPages) ? 'disabled' : '' ?> 
            onclick="performSearch(<?= $page + 1 ?>)">Weiter (Später) →</button>
</div>
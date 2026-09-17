<?php
require 'config.php';

// Falls der Benutzer nicht eingeloggt ist, zum Login leiten
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// CSRF-Token bereitstellen (wird in Formularen benötigt)
csrf_token();

$message = "";
if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $message = "success|Termin erfolgreich verschoben / korrigiert!";
}

// Treiberabhängige Wochenend-Bedingung (SQLite: strftime('%w'), MySQL: DAYOFWEEK)
try {
    $stmt = $pdo->query("
        SELECT * FROM dienste 
        WHERE " . db_weekend_condition('datum') . " 
        AND deleted_at IS NULL 
        ORDER BY datum DESC, uhrzeit ASC
    ");
    $termine = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Fehler beim Laden der Wochenend-Termine: " . $e->getMessage());
}

// Zeit-Slots für das Dropdown generieren (analog zur index.php)
$timeSlots = [];
$start = new DateTime('08:00');
while ($start < new DateTime('17:30')) {
    $timeSlots[] = $start->format('H:i');
    $start->modify('+30 minutes');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Wochenend-Korrektur</title>
    <link rel="stylesheet" href="<?= asset('style.css') ?>">
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <style>
        .container-we { max-width: 1000px; margin: 40px auto; }
        .we-table th { cursor: pointer; user-select: none; position: relative; padding-right: 24px; }
        .we-table th:hover { background: #eef2f7; }
        .we-table th::after { content: ' \2195'; font-size: 0.8em; color: var(--ink-faint); position: absolute; right: 8px; }
        .we-table th.sort-asc::after { content: ' \2191'; color: var(--brand); }
        .we-table th.sort-desc::after { content: ' \2193'; color: var(--brand); }
        .btn-action { padding: 8px 14px; }
    </style>
</head>
<body>
<div class="container-we">
    <a href="admin.php" class="nav-pill" style="margin-bottom:16px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Zurück zur Administration</a>
    
    <h1>⚠️ Fehleinträge am Wochenende korrigieren</h1>
    <p>Hier siehst du alle Termine, die versehentlich auf einen Samstag oder Sonntag gebucht wurden. Klicke auf die Spaltenüberschriften, um die Liste zu sortieren.</p>

    <?php if ($message !== ''): $parts = explode('|', $message); ?>
        <div class="msg-banner <?= $parts[0] == 'success' ? 'msg-success' : 'msg-error' ?>"><?= $parts[1] ?></div>
    <?php endif; ?>

    <?php if (empty($termine)): ?>
        <p style="color: green; font-weight: bold; background: #e7f3eb; padding: 15px; border-radius: 6px; border: 1px solid green;">
            ✔ Keine Fehlbuchungen am Wochenende vorhanden! Alle Termine liegen sauber von Montag bis Freitag.
        </p>
    <?php else: ?>
        <table class="we-table" id="weTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0, 'date')">Datum & Uhrzeit</th>
                    <th onclick="sortTable(1, 'text')">Techniker</th>
                    <th onclick="sortTable(2, 'text')">Kunde / Name</th>
                    <th onclick="sortTable(3, 'text')">Einsatzart</th>
                    <th style="cursor: default; background: #f4f4f4;">Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($termine as $t): 
                    $wochentagName = (date('N', strtotime($t['datum'])) == 6) ? 'Samstag' : 'Sonntag';
                ?>
                    <tr data-date="<?= $t['datum'] . ' ' . $t['uhrzeit'] ?>">
                        <td>
                            <span style="background: #444; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; font-weight: bold; margin-right: 5px;"><?= $wochentagName ?></span>
                            <strong><?= date('d.m.Y', strtotime($t['datum'])) ?></strong> – <?= htmlspecialchars($t['uhrzeit']) ?> Uhr
                        </td>
                        <td><strong>T<?= htmlspecialchars($t['techniker_id']) ?></strong></td>
                        <td><strong><?= htmlspecialchars($t['name']) ?></strong><br><small><?= htmlspecialchars($t['strasse']) ?>, <?= htmlspecialchars($t['ort']) ?></small></td>
                        <td><?= htmlspecialchars($t['einsatzart']) ?></td>
                        <td>
                            <button type="button" class="btn-action" onclick="openEditModal(<?= htmlspecialchars(json_encode($t)) ?>)">✏️ Korrigieren</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div id="bookingModal" class="modal">
    <div class="modal-content">
        <h3 id="modalTitle" style="margin-top:0; color:var(--drk-red);">Termin korrigieren</h3>
        <form action="save_appointment.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" id="f_id" name="termin_id">
            <input type="hidden" id="f_datum" name="datum">
            <input type="hidden" id="f_uhrzeit" name="uhrzeit">
            <input type="hidden" id="f_tech" name="techniker_id">
            <input type="hidden" name="return_w" id="f_return_w" value="<?= date('W') ?>">
            <input type="hidden" name="return_y" id="f_return_y" value="<?= date('Y') ?>">
            
            <div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 15px; padding: 15px;">
                <strong style="color: #555; display: block; margin-bottom: 10px;">📅 Neues Datum für Wochentag (Mo–Fr) wählen:</strong>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <div class="form-group" style="flex:2; min-width: 150px;">
                        <label style="color: #666; font-weight: bold;">Neues Datum</label>
                        <input type="date" name="new_datum" id="f_new_datum" required>
                    </div>
                    <div class="form-group" style="flex:1; min-width: 100px;">
                        <label style="color: #666; font-weight: bold;">Uhrzeit</label>
                        <select name="new_uhrzeit" id="f_new_uhrzeit">
                            <?php foreach($timeSlots as $ts) echo sprintf('<option value="%s">%s</option>', $ts, $ts); ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1; min-width: 100px;">
                        <label style="color: #666; font-weight: bold;">Techniker</label>
                        <select name="new_tech" id="f_new_tech">
                            <?php for ($mi = 1; $mi <= getMaxMitarbeiterErlaubt(); $mi++): ?>
                            <option value="<?= $mi ?>"><?= h(getLabel('mitarbeiter_' . $mi, 'Mitarbeiter ' . $mi)) ?> (T<?= $mi ?>)</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>

            <input type="hidden" name="einsatzart" id="f_art">
            <input type="hidden" name="ankunft" id="f_ankunft">
            <input type="hidden" name="kunde_name" id="f_name">
            <input type="hidden" name="strasse" id="f_strasse">
            <input type="hidden" name="plz" id="f_plz">
            <input type="hidden" name="ort" id="f_ort">
            <input type="hidden" name="telefon1" id="f_tel1">

            <button type="submit" class="btn-primary">Korrektur abspeichern</button>
            <button type="button" onclick="document.getElementById('bookingModal').style.display='none';" style="width:100%; background:none; border:none; color:#666; cursor:pointer; margin-top:10px;">Abbrechen</button>
        </form>
    </div>
</div>

<script>
// Blitzschnelle Client-seitige Sortierfunktion
function sortTable(colIndex, type) {
    const table = document.getElementById("weTable");
    if (!table) return;
    
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);
    const ths = table.querySelectorAll("thead th");
    const currentTh = ths[colIndex];
    
    // Richtung bestimmen
    const isAsc = currentTh.classList.contains("sort-asc");
    const direction = isAsc ? -1 : 1;
    
    // CSS-Klassen für Pfeile zurücksetzen
    ths.forEach(th => th.classList.remove("sort-asc", "sort-desc"));
    currentTh.classList.add(isAsc ? "sort-desc" : "sort-asc");
    
    rows.sort((rowA, rowB) => {
        let valA, valB;
        
        if (type === 'date') {
            // Nutzen das versteckte ISO-Datum-Attribut für die perfekte chronologische Sortierung
            valA = rowA.getAttribute("data-date");
            valB = rowB.getAttribute("data-date");
        } else {
            // Standard Text-Sortierung
            valA = rowA.cells[colIndex].textContent.trim().toLowerCase();
            valB = rowB.cells[colIndex].textContent.trim().toLowerCase();
        }
        
        if (valA < valB) return -1 * direction;
        if (valA > valB) return 1 * direction;
        return 0;
    });
    
    // Sortierte Zeilen wieder einhängen
    rows.forEach(row => tbody.appendChild(row));
}

function openEditModal(data) {
    document.getElementById('f_id').value = data.id;
    document.getElementById('f_datum').value = data.datum;
    document.getElementById('f_uhrzeit').value = data.uhrzeit;
    document.getElementById('f_tech').value = data.techniker_id;
    
    // Richtige KW und Jahr für den Redirect setzen
    const d = new Date(data.datum);
    const jan4 = new Date(d.getFullYear(), 0, 4);
    const week = Math.ceil(((d - jan4) / 86400000 + jan4.getDay() + 1) / 7);
    document.getElementById('f_return_w').value = week;
    document.getElementById('f_return_y').value = d.getFullYear();
    
    document.getElementById('f_new_datum').value = data.datum;
    document.getElementById('f_new_uhrzeit').value = data.uhrzeit;
    document.getElementById('f_new_tech').value = data.techniker_id;
    
    document.getElementById('f_art').value = data.einsatzart || '';
    document.getElementById('f_ankunft').value = data.ankunft || '';
    document.getElementById('f_name').value = data.name || '';
    document.getElementById('f_strasse').value = data.strasse || '';
    document.getElementById('f_plz').value = data.plz || '';
    document.getElementById('f_ort').value = data.ort || '';
    document.getElementById('f_tel1').value = data.telefon1 || '';

    document.getElementById('bookingModal').style.display = 'block';
}
</script>
<?php include 'footer.php'; ?>
</body>
</html>
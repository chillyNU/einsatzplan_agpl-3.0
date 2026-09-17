<?php
require 'config.php';

// Zugriffsschutz: Nur Admins dürfen hier rein
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$msg = "";
$limit = 50; // Einträge pro Seite

// ── Hilfsfunktionen ──────────────────────────────────────────────────────────

/** Prüft das eingegebene Admin-Passwort gegen den angemeldeten Benutzer. */
function verifyAdminPassword(PDO $pdo, string $password): bool {
    if ($password === '') return false;
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();
    return $hash && password_verify($password, $hash);
}

/** Liefert die Primärschlüssel-Spalte einer Tabelle (nur Einzel-PK), sonst null. */
function getPrimaryKeyColumn(PDO $pdo, string $table): ?string {
    $pk = [];
    foreach (db_table_columns($pdo, $table) as $col) {
        if (!empty($col['pk'])) $pk[] = $col['name'];
    }
    return count($pk) === 1 ? $pk[0] : null;
}

// Tabellen der aktiven Datenbank auslesen (SQLite oder MySQL)
try {
    $tabellen = db_list_tables($pdo);
    sort($tabellen);
} catch (Exception $e) {
    $tabellen = [];
    $msg = "error|Fehler beim Laden der Tabellen: " . $e->getMessage();
}

// ── Löschaktionen ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_action'])) {
    csrf_verify();

    $action    = $_POST['db_action'];
    $table     = $_POST['table'] ?? '';
    $password  = $_POST['admin_password'] ?? '';

    // Tabellenname strikt gegen die echte Tabellenliste prüfen (Whitelist)
    if (!in_array($table, $tabellen, true)) {
        $msg = "error|Ungültige Tabelle.";
    } elseif (!verifyAdminPassword($pdo, $password)) {
        $msg = "error|Das eingegebene Admin-Passwort ist falsch. Es wurde nichts gelöscht.";
    } else {
        $tableSafe = str_replace('`', '', $table);
        try {
            if ($action === 'truncate_table') {
                $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$tableSafe}`")->fetchColumn();
                $pdo->exec("DELETE FROM `{$tableSafe}`");
                db_reset_autoincrement($pdo, $tableSafe);

                // Sicherheitsnetz: eigenen Admin-Account sofort wiederherstellen,
                // damit man sich nicht selbst aussperrt
                if ($table === 'users') {
                    $msg = "warning|Alle {$count} Einträge der Tabelle '{$table}' wurden gelöscht. "
                         . "Achtung: Damit wurden auch alle Benutzerkonten entfernt – beim nächsten Aufruf "
                         . "startet die Ersteinrichtung (setup.php).";
                } else {
                    $msg = "success|Alle {$count} Einträge der Tabelle '{$table}' wurden gelöscht.";
                }
            } elseif ($action === 'delete_row') {
                $pkCol = getPrimaryKeyColumn($pdo, $table);
                $pkVal = $_POST['pk_value'] ?? '';
                if ($pkCol === null) {
                    $msg = "error|Für diese Tabelle ist kein einzelnes Löschen möglich (kein eindeutiger Primärschlüssel).";
                } elseif ($table === 'users' && (string)$pkVal === (string)$_SESSION['user_id']) {
                    $msg = "error|Sie können Ihr eigenes Benutzerkonto hier nicht löschen.";
                } else {
                    $pkColSafe = str_replace('`', '', $pkCol);
                    $stmt = $pdo->prepare("DELETE FROM `{$tableSafe}` WHERE `{$pkColSafe}` = ?");
                    $stmt->execute([$pkVal]);
                    if ($stmt->rowCount() > 0) {
                        $msg = "success|Eintrag ({$pkCol} = " . htmlspecialchars((string)$pkVal) . ") aus Tabelle '{$table}' wurde gelöscht.";
                    } else {
                        $msg = "error|Kein passender Eintrag gefunden – es wurde nichts gelöscht.";
                    }
                }
            }
        } catch (PDOException $e) {
            $msg = "error|Löschen fehlgeschlagen: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Funktionen für Paginierung
function getTableData($pdo, $tableName, $page, $limit) {
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare("SELECT * FROM `" . str_replace('`', '', $tableName) . "` LIMIT ? OFFSET ?");
    $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTotalCount($pdo, $tableName) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '', $tableName) . "`");
    return (int)$stmt->fetchColumn();
}

/** Kürzt einen Wert für die Anzeige (nutzt mbstring, falls vorhanden). */
function shorten($value, int $len): string {
    $s = (string)$value;
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>System DB-Viewer</title>
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <style>
        body { padding: 24px; }
        .container { max-width: 1200px; margin: auto; position: relative; background: none; border: none; box-shadow: none; padding: 0; }
        .card { background: var(--surface); border: 1px solid var(--line); padding: 22px 24px; border-radius: var(--radius); box-shadow: var(--shadow-1); margin-bottom: 25px; }
        .header-area { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; border-bottom: 2px solid var(--line); padding-bottom: 12px; }
        .back-link { text-decoration: none; color: var(--ink-soft); font-size: 0.9em; font-weight: 600; }
        .back-link:hover { color: var(--brand); }
        h1 { margin: 0; font-size: 1.5em; border: none; padding: 0; }
        h2 { border-bottom: 2px solid var(--line); padding-bottom: 10px; margin-top: 0; display: flex; justify-content: space-between; align-items: center; font-size: 1.15em; gap: 12px; flex-wrap: wrap; }
        table { width: 100%; font-size: 0.8em; }
        th, td { border: 1px solid var(--line); padding: 6px 8px; text-align: left; }
        th { background: var(--surface-alt); position: sticky; top: 0; }
        .btn { padding: 8px 16px; border: none; display: inline-block; font-size: 0.85em; }
        .scroll-box { max-height: 600px; overflow: auto; border: 1px solid var(--line); border-radius: var(--radius-sm); margin-top: 10px; }
        .pagination { margin-top: 15px; text-align: center; display: flex; justify-content: center; align-items: center; gap: 10px; }
        .btn-truncate {
            background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;
            border-radius: 6px; padding: 5px 12px; font-size: 0.72em; font-weight: 700;
            cursor: pointer; white-space: nowrap;
        }
        .btn-truncate:hover { background: #fecaca; }
        .btn-row-del {
            background: none; border: none; cursor: pointer; font-size: 1em;
            padding: 2px 6px; border-radius: 4px; line-height: 1;
        }
        .btn-row-del:hover { background: #fee2e2; }
        td.del-cell, th.del-cell { width: 34px; text-align: center; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-area">
        <h1>🗄️ Datenbank-Manager</h1>
        <a href="admin.php" class="nav-pill"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Zurück zur Administration</a>
    </div>

    <?php foreach ($tabellen as $tabelle):
        $currPage = isset($_GET['p_'.$tabelle]) ? (int)$_GET['p_'.$tabelle] : 1;
        $total = getTotalCount($pdo, $tabelle);
        $pages = ceil($total / $limit);
        $data = ($total > 0) ? getTableData($pdo, $tabelle, $currPage, $limit) : [];
        $pkCol = getPrimaryKeyColumn($pdo, $tabelle);
    ?>
    <div class="card" id="tbl_<?= $tabelle ?>">
        <h2>
            <span>Tabelle: <?= htmlspecialchars($tabelle) ?> <span class="tag"><?= $total ?> Einträge</span></span>
            <?php if ($total > 0): ?>
            <form method="POST" style="margin:0;"
                  data-confirm-password="Sie sind dabei, <b>ALLE <?= $total ?> Einträge</b> der Tabelle <b>&bdquo;<?= htmlspecialchars($tabelle) ?>&ldquo;</b> unwiderruflich zu löschen.<br><br>Dieser Vorgang kann <u>nicht</u> rückgängig gemacht werden!<?= $tabelle === 'users' ? '<br><br>⚠️ Diese Tabelle enthält alle Benutzerkonten – danach startet die Ersteinrichtung neu!' : '' ?>"
                  data-confirm-title="Gesamte Tabelle leeren"
                  data-confirm-type="danger"
                  data-confirm-ok="Ja, alle <?= $total ?> Einträge löschen">
                <?= csrf_field() ?>
                <input type="hidden" name="db_action" value="truncate_table">
                <input type="hidden" name="table" value="<?= htmlspecialchars($tabelle) ?>">
                <button type="submit" class="btn-truncate">🗑️ Tabelle leeren (<?= $total ?>)</button>
            </form>
            <?php endif; ?>
        </h2>

        <?php if ($total > 0): ?>
            <div class="scroll-box">
                <table>
                    <thead>
                        <tr>
                            <?php if ($pkCol !== null): ?><th class="del-cell"></th><?php endif; ?>
                            <?php foreach (array_keys($data[0]) as $k): ?>
                                <th><?= htmlspecialchars($k) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): ?>
                        <tr>
                            <?php if ($pkCol !== null): ?>
                            <td class="del-cell">
                                <form method="POST" style="margin:0;"
                                      data-confirm-password="Diesen Eintrag aus der Tabelle <b>&bdquo;<?= htmlspecialchars($tabelle) ?>&ldquo;</b> unwiderruflich löschen?<br><br><b><?= htmlspecialchars($pkCol) ?>:</b> <?= htmlspecialchars((string)$row[$pkCol]) ?><?php
                                          // Etwas Kontext im Hinweis anzeigen (erste aussagekräftige Spalten)
                                          $shown = 0;
                                          foreach ($row as $ck => $cv) {
                                              if ($ck === $pkCol || $cv === null || $cv === '') continue;
                                              if (stripos($ck, 'password') !== false || stripos($ck, 'hash') !== false) continue;
                                              echo '<br><b>' . htmlspecialchars($ck) . ':</b> ' . htmlspecialchars(shorten($cv, 60));
                                              if (++$shown >= 3) break;
                                          }
                                      ?>"
                                      data-confirm-title="Eintrag löschen"
                                      data-confirm-type="danger">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="db_action" value="delete_row">
                                    <input type="hidden" name="table" value="<?= htmlspecialchars($tabelle) ?>">
                                    <input type="hidden" name="pk_value" value="<?= htmlspecialchars((string)$row[$pkCol]) ?>">
                                    <button type="submit" class="btn-row-del" title="Eintrag löschen">🗑️</button>
                                </form>
                            </td>
                            <?php endif; ?>
                            <?php foreach ($row as $ck => $v): ?>
                                <td><?= htmlspecialchars((stripos($ck, 'password') !== false || stripos($ck, 'hash') !== false) && $v ? '••••••' : substr((string)$v, 0, 80)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php if ($currPage > 1): ?>
                    <a href="?p_<?= $tabelle ?>=<?= $currPage - 1 ?>#tbl_<?= $tabelle ?>" class="btn" style="background:#666; color:white;">« Zurück</a>
                <?php endif; ?>

                <select onchange="window.location.href='?p_<?= $tabelle ?>='+this.value+'#tbl_<?= $tabelle ?>'" style="padding: 5px;">
                    <?php for($i=1; $i<=$pages; $i++): ?>
                        <option value="<?= $i ?>" <?= $i == $currPage ? 'selected' : '' ?>>Seite <?= $i ?></option>
                    <?php endfor; ?>
                </select>

                <?php if ($currPage < $pages): ?>
                    <a href="?p_<?= $tabelle ?>=<?= $currPage + 1 ?>#tbl_<?= $tabelle ?>" class="btn" style="background:#666; color:white;">Vor »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p style="color: #999; font-style: italic;">Keine Daten vorhanden.</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div style="text-align: center; margin-bottom: 50px;">
        <a href="index.php" class="nav-pill nav-pill-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Zurück zum Einsatzplan</a>
    </div>
</div>
<?php include 'footer.php'; ?>
<script src="<?= asset('modal.js') ?>"></script>
<?php if ($msg): $m = explode('|', $msg, 2);
    $type = $m[0] === 'success' ? 'success' : ($m[0] === 'warning' ? 'warning' : 'danger');
    $title = $m[0] === 'success' ? 'Erledigt' : ($m[0] === 'warning' ? 'Hinweis' : 'Fehler');
?>
<script>
    appModal.alert({
        type: <?= json_encode($type) ?>,
        title: <?= json_encode($title) ?>,
        message: <?= json_encode($m[1] ?? '') ?>
    });
</script>
<?php endif; ?>
</body>
</html>

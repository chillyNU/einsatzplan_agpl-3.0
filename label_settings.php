<?php
require_once 'config.php';

// Admin-Zugriff prüfen
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $allowed_label_keys = [];
    for ($i = 1; $i <= MAX_MITARBEITER; $i++) { $allowed_label_keys[] = 'mitarbeiter_' . $i; }
    foreach ($_POST['labels'] as $key => $value) {
        if (!in_array($key, $allowed_label_keys, true)) continue; // Whitelist
        $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES (?, ?)");
        $stmt->execute(['label_' . $key, htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8')]);
    }
    $message = "Bezeichnungen wurden aktualisiert!";
}

// Aktuelle Labels laden
$stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'label_%'");
$current_labels = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Bezeichnungen anpassen</title>
    <link rel="stylesheet" href="theme.css?v=2.2">
    <style>
        body { max-width: 620px; margin: 40px auto; padding: 20px; }
        .box input { width: 100%; margin: 8px 0; }
        .box button { background: var(--brand); color: #fff; padding: 10px 20px; border: 1px solid var(--brand); }
        .box button:hover { background: var(--brand-dark); }
    </style>
</head>
<body>
    <div class="box">
        <h1>Bezeichnungen ändern</h1>
        <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
        <form method="POST">
            <?= csrf_field() ?>
            <?php
            $farben = [1 => 'var(--t1)', 2 => 'var(--t2)', 3 => 'var(--t3)', 4 => 'var(--t4)'];
            for ($i = 1; $i <= getMaxMitarbeiterErlaubt(); $i++):
                $wert = $current_labels['label_mitarbeiter_' . $i] ?? 'Mitarbeiter ' . $i;
            ?>
            <label style="display:flex; align-items:center; gap:8px;">
                <span style="display:inline-block; width:12px; height:12px; border-radius:3px; background:<?= $farben[$i] ?>;"></span>
                Mitarbeiter <?= $i ?> Bezeichnung:
            </label>
            <input type="text" name="labels[mitarbeiter_<?= $i ?>]" value="<?php echo htmlspecialchars($wert); ?>">
            <?php endfor; ?>

            <div style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                <button type="submit">Speichern</button>
                <a href="admin.php" style="text-decoration: none; color: #666; font-size: 14px;">← Zurück zur Admin-Übersicht</a>
            </div>
        </form>
    </div>
</body>
</html>
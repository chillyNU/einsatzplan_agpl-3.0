<?php
require 'config.php';

// Zugriffsschutz (wie in admin.php)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$file = 'impressum.php';
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_impressum'])) {
    csrf_verify();
    if (file_put_contents($file, $_POST['impressum_content'])) {
        $message = "success|Impressum wurde erfolgreich aktualisiert.";
    } else {
        $message = "error|Fehler beim Speichern. Prüfe die Schreibrechte (CHMOD).";
    }
}

$content = file_exists($file) ? file_get_contents($file) : "";
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Impressum bearbeiten</title>
    <link rel="stylesheet" href="theme.css?v=2.2">
    <style>
        body { padding: 24px; }
        .container { max-width: 900px; margin: auto; }
        textarea { width: 100%; height: 500px; padding: 15px; font-family: ui-monospace, Consolas, monospace; }
    </style>
</head>
<body>
<div class="container">
    <h1>Impressum bearbeiten</h1>

    <?php if ($message): $parts = explode('|', $message); ?>
        <div class="msg <?= $parts[0] == 'success' ? 'msg-success' : 'msg-error' ?>"><?= $parts[1] ?></div>
    <?php endif; ?>

    <form method="POST">
            <?= csrf_field() ?>
        <textarea name="impressum_content"><?= htmlspecialchars($content) ?></textarea>
        <div style="margin-top: 20px;">
            <button type="submit" name="save_impressum" class="btn-add">Änderungen speichern</button>
            <a href="admin.php" class="btn-back">Zurück zur Admin</a>
        </div>
    </form>
</div>
</body>
</html>
<?php
require_once 'config.php';

// Prüfung: Wenn schon User da sind, Zugriff verbieten
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() > 0) {
    die("Setup bereits abgeschlossen.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Kürzel vergeben: Manuelle Eingabe oder erste 3 Buchstaben
    $kuerzel = !empty($_POST['kuerzel']) ? $_POST['kuerzel'] : strtoupper(substr($username, 0, 3));
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, kuerzel) VALUES (?, ?, 'admin', ?)");
    $stmt->execute([$username, $password, $kuerzel]);
    
    $success = "Admin-Account wurde erfolgreich erstellt (Kürzel: " . htmlspecialchars($kuerzel) . ").";
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Ersteinrichtung Einsatzplan</title>
    <link rel="stylesheet" href="theme.css?v=2.2">
    <style>
        body { max-width: 800px; margin: 40px auto; padding: 20px; }
        .box input[type="text"], .box input[type="password"] { width: 100%; margin: 8px 0; }
        .box button { background: var(--brand); color: #fff; padding: 10px 20px; border: 1px solid var(--brand); }
        .box button:hover { background: var(--brand-dark); }
        .success { font-weight: bold; margin-bottom: 20px; color: var(--ok); }
    </style>
</head>
<body>
    <div class="box">
        <h1>Ersteinrichtung Einsatzplan</h1>
        
        <?php if (isset($success)): ?>
            <p class="success"><?php echo $success; ?></p>
            <a href="index.php" class="btn-back">Jetzt einloggen</a>
        <?php else: ?>
            <h3>Admin-Account erstellen</h3>
            <p>Lege bitte den Administrator-Account und ein Kürzel für die Dokumentation in der Datenbank fest.</p>
            
            <form method="POST">
                <?= csrf_field() ?>
                <input type="text" name="username" placeholder="Admin-Benutzername" required>
                <input type="text" name="kuerzel" placeholder="Kürzel (z.B. ADM)" maxlength="3">
                <input type="password" name="password" placeholder="Passwort" required>
                <button type="submit">Admin erstellen</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
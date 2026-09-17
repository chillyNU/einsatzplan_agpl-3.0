<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
if (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer') { die("Zugriff verweigert."); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['datum'])) {
    csrf_verify();
    $datum = $_POST['datum'];
    $tech  = (int)($_POST['tech_id'] ?? 0);
    $notiz = trim($_POST['notiz'] ?? '');
    $return_w = (int)($_POST['return_w'] ?? date('W'));
    $return_y = (int)($_POST['return_y'] ?? date('Y'));
    if ($datum && $tech) {
        $stmt = $pdo->prepare("SELECT vertretung FROM tages_notizen WHERE datum = ? AND techniker_id = ?");
        $stmt->execute([$datum, $tech]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (empty($notiz) && (empty($existing['vertretung']) || trim($existing['vertretung']) === "")) {
                $stmt = $pdo->prepare("DELETE FROM tages_notizen WHERE datum = ? AND techniker_id = ?");
                $stmt->execute([$datum, $tech]);
            } else {
                $stmt = $pdo->prepare("UPDATE tages_notizen SET notiz = ? WHERE datum = ? AND techniker_id = ?");
                $stmt->execute([$notiz, $datum, $tech]);
            }
        } else {
            if (!empty($notiz)) {
                $stmt = $pdo->prepare("INSERT INTO tages_notizen (datum, techniker_id, notiz, vertretung) VALUES (?, ?, ?, '')");
                $stmt->execute([$datum, $tech, $notiz]);
            }
        }
    }
    // Zurück zum betroffenen Tag springen statt an den Seitenanfang
    $anker = preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? "#day-$datum" : '';
    header("Location: index.php?w=$return_w&y=$return_y$anker");
    exit;
} else {
    echo "Fehler: Ungültiger Aufruf.";
}
?>
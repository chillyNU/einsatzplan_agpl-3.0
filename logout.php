<?php
// Session laden – ohne session_start() wäre session_destroy() wirkungslos
// und die Anmeldung bliebe serverseitig bestehen!
require 'config.php';

// 30-Tage-Login-Token entfernen (sonst würde die Anmeldung über den
// Remember-Cookie automatisch wiederhergestellt)
remember_login_clear($pdo);

// 1. Alle Session-Daten leeren
$_SESSION = [];

// 2. Session-Cookie im Browser ungültig machen
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// 3. Session serverseitig zerstören
session_destroy();

header("Location: login.php");
exit;

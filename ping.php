<?php
// Session-Ping: hält die Session am Leben, nur für eingeloggte Nutzer
require 'config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo "unauthorized";
    exit;
}

echo "pong";

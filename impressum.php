<?php require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Impressum</title>
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <style>
        body { max-width: 800px; margin: 40px auto; padding: 20px; }
        .btn-back { margin-top: 30px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Impressum</h1>
        
        <h3>Hinweis für den Betreiber dieses Einsatzplans:</h3>
        <p>Diese Software wurde auf diesem Server installiert. Da du der Betreiber dieses Dienstes bist, bist du gemäß den gesetzlichen Bestimmungen (z.B. § 5 DDG in Deutschland) dazu verpflichtet, ein rechtskonformes Impressum und eine Datenschutzerklärung bereitzustellen.</p>
        <p><strong>Bitte ersetze diesen Text durch deine eigenen Unternehmensdaten (Name, Anschrift, Kontaktmöglichkeiten, Handelsregister, Steuernummer etc.).</strong></p>

        <h3>Angaben gemäß § 5 DDG</h3>
        <p>[Hier deine Firmenbezeichnung / Name des Betreibers einfügen]</p>
        <p>[Straße, Hausnummer]</p>
        <p>[PLZ, Ort]</p>
        
        <h3>Kontakt</h3>
        <p>E-Mail: [Deine E-Mail-Adresse]</p>
        <p>Telefon: [Deine Telefonnummer]</p>

        <a href="javascript:history.back()" class="btn-back">← Zurück</a>
    </div>
</body>
</html>
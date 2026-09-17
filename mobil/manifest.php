<?php
// ─────────────────────────────────────────────────────────────────────────────
// Dynamisches PWA-Manifest pro Mitarbeiter-Tagesseite.
// Erzeugt Name und Icons passend zu Wochentag + Mitarbeiter, sodass beim
// "Zum Homescreen hinzufügen" ein eigenes Icon (z.B. "Mo.1") entsteht.
// Wird von der jeweiligen Seite über <link rel="manifest"> eingebunden.
// ─────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/../config.php';

$tagName = [
    'mo' => 'Montag', 'di' => 'Dienstag', 'mi' => 'Mittwoch', 'do' => 'Donnerstag',
    'fr' => 'Freitag', 'sa' => 'Samstag', 'so' => 'Sonntag',
];

$tag = strtolower($_GET['tag'] ?? '');
$ma  = (int)($_GET['ma'] ?? 0);

header('Content-Type: application/manifest+json');

// Firmenname für den App-Namen
$firma = 'Einsatzplan';
try {
    $v = $pdo->query("SELECT value FROM settings WHERE `key` = 'footer_text'")->fetchColumn();
    if ($v) $firma = $v;
} catch (Exception $e) {}

// Startseite: die konkrete Mitarbeiterseite (oder die Übersicht, wenn keine Angabe)
if (isset($tagName[$tag]) && $ma >= 1 && $ma <= 12) {
    $label   = getLabel('mitarbeiter_' . $ma, 'Mitarbeiter ' . $ma);
    $name    = $tagName[$tag] . ' · ' . $label;
    $short   = ucfirst($tag) . '.' . $ma;
    $start   = $tag . '_' . $ma . '.php';
    $iconBase = 'icon.php?tag=' . urlencode($tag) . '&ma=' . $ma;
    $icons = [
        ['src' => $iconBase, 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'any'],
        ['src' => $iconBase . '&maskable=1', 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
    ];
} else {
    // Übersicht
    $name    = 'Einsatzplan · Übersicht';
    $short   = 'Einsatzplan';
    $start   = 'index.php';
    $icons = [
        ['src' => 'icons/icon-192.png',  'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icons/icon-512.png',  'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icons/maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => 'icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];
}

echo json_encode([
    'name'             => $name,
    'short_name'       => $short,
    'start_url'        => $start,
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#c8102e',
    'theme_color'      => '#c8102e',
    'lang'             => 'de',
    'icons'            => $icons,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

<?php
// ─────────────────────────────────────────────────────────────────────────────
// Dynamischer PWA-Icon-Generator (SVG – benötigt keine Bildbibliothek).
// Erzeugt ein Icon mit dem Kürzel des Wochentags und der Mitarbeiter-Nummer,
// z.B. "Mo.1", "Mi.4". Wird vom manifest.php der jeweiligen Seite referenziert.
//
// Parameter:
//   tag  = mo|di|mi|do|fr|sa|so   (Wochentag-Kürzel)
//   ma   = 1..12                  (Mitarbeiter-Nummer)
//   maskable = 1                  (optional: maskable-Variante mit mehr Rand)
// ─────────────────────────────────────────────────────────────────────────────

$tagKuerzel = [
    'mo' => 'Mo', 'di' => 'Di', 'mi' => 'Mi', 'do' => 'Do',
    'fr' => 'Fr', 'sa' => 'Sa', 'so' => 'So',
];
$farben = [
    1  => '#0e7c66', // Grün
    2  => '#2f5eb3', // Blau
    3  => '#0e91a6', // Teal
    4  => '#c07a1e', // Bernstein
    5  => '#6d4fb3', // Violett
    6  => '#b02d6e', // Beere
    7  => '#7a821c', // Oliv
    8  => '#b5451f', // Ziegel
    9  => '#56708c', // Schieferblau
    10 => '#3e6b23', // Laubgrün
    11 => '#8a5a2e', // Nussbraun
    12 => '#4a4f8c', // Indigo
];

$tag = strtolower($_GET['tag'] ?? 'mo');
$ma  = (int)($_GET['ma'] ?? 1);
$maskable = isset($_GET['maskable']);

$kuerzel = $tagKuerzel[$tag] ?? 'Mo';
$ma = max(1, min(12, $ma));
$farbe = $farben[$ma];
$text = $kuerzel . '.' . $ma;

header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=604800'); // 7 Tage cachen

// maskable: Motiv kleiner in der "safe zone" (~80%), Vollflächen-Hintergrund.
$radius = $maskable ? 0 : 96;
$fontSize = strlen($text) > 4 ? 150 : 170;
?>
<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
  <rect width="512" height="512" rx="<?= $radius ?>" fill="<?= $farbe ?>"/>
  <text x="256" y="256" text-anchor="middle" dominant-baseline="central"
        font-family="Arial, Helvetica, sans-serif" font-weight="bold"
        font-size="<?= $fontSize ?>" fill="#ffffff" letter-spacing="-4"><?= htmlspecialchars($text) ?></text>
</svg>

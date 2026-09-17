# Beitragen zu Einsatzplan

Danke für dein Interesse! Ein paar kurze Hinweise, damit Beiträge reibungslos
laufen.

## Bugs melden

Bitte ein Issue mit folgenden Angaben eröffnen:

- Was war erwartet, was ist tatsächlich passiert?
- Schritte zum Reproduzieren
- PHP-Version, Datenbank (SQLite/MySQL), Browser (falls UI-relevant)
- Relevante Fehlermeldung/Log-Auszug, falls vorhanden

## Feature-Wünsche

Gerne als Issue mit kurzer Beschreibung des Anwendungsfalls (nicht nur der
gewünschten Lösung) – das hilft, die beste Umsetzung zu finden.

## Pull Requests

1. Issue oder kurze Absprache vor größeren Änderungen, um Doppelarbeit zu
   vermeiden.
2. Ein PR pro Thema, nach Möglichkeit klein und fokussiert.
3. PHP-Stil orientiert sich am bestehenden Code (siehe z. B. `save_appointment.php`,
   `db_helpers.php`): sprechende deutsche Bezeichner für fachliche Begriffe,
   Kommentare bei nicht offensichtlicher Logik.
4. Vor dem PR lokal `php -l` auf geänderte Dateien laufen lassen (Syntax-Check).
5. Beschreibung im PR: was ändert sich und warum.

## Lizenz deines Beitrags

Mit einem Pull Request stimmst du zu, dass dein Beitrag unter der AGPL-3.0
(siehe [`LICENSE`](LICENSE)) veröffentlicht wird – derselben Lizenz wie der
Rest des Projekts.

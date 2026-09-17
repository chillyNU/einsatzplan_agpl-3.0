# Update-Anleitung

Diese Anleitung gilt, wenn du bereits eine laufende EINSATZPLAN-Installation hast
und auf eine neuere Version aktualisieren möchtest.

## Kurzfassung

1. Backup von `data/` ziehen
2. Neue Dateien aus dem Repository per **Merge/Überschreiben** hochladen (nicht spiegeln/synchronisieren)
3. Seite einmal aufrufen und kurz durchklicken

Wenn du diese drei Schritte befolgst, bleiben deine Datenbank, deine Zugangsdaten
und alle bisherigen Termine unangetastet.

## Warum das sicher ist

Alle nutzerspezifischen Daten liegen ausschließlich im Ordner `data/` – und genau
diese Dateien sind über [`.gitignore`](.gitignore) bewusst **nicht** Teil des
Repositorys:

- `data/*.sqlite` – deine komplette Datenbank (Termine, Mitarbeiter, Benutzer)
- `data/db_config.php` – deine MySQL-Zugangsdaten, falls du MySQL statt SQLite nutzt
- `data/.installation` – interne Installations-Kennung

Wenn du den neuen Code herunterlädst (ZIP oder `git pull`), sind diese Dateien
darin schlicht nicht enthalten. Kopierst du die neuen Dateien auf deinen Server,
werden diese drei Punkte also gar nicht berührt – vorausgesetzt, du kopierst im
**Merge-Modus** (siehe unten).

Alle Datenbanktabellen werden zudem mit `CREATE TABLE IF NOT EXISTS` angelegt
(siehe `init_db.php`) – bei einer bestehenden Installation passiert beim erneuten
Ausführen an vorhandenen Tabellen nichts.

## Schritt für Schritt

### 1. Backup ziehen (auch wenn's eigentlich sicher ist)

Lade dir vorher einmal den kompletten `data/`-Ordner deiner aktuellen Installation
herunter (per FTP) und sichere ihn lokal. Ein Backup kostet zwei Minuten, ein
Datenverlust ohne Backup kostet deutlich mehr Nerven.

### 2. Neue Version besorgen

Aktuelle Version von GitHub laden: **Code → Download ZIP** oder per
`git clone`/`git pull`, falls du das Repository direkt auf dem Server geklont hast.

### 3. Richtig hochladen: Merge, nicht Spiegeln

Das ist der einzige Schritt, bei dem du aufpassen musst:

- ✅ **Richtig:** Dateien einzeln oder als Ordner hochladen und dabei vorhandene
  gleichnamige Dateien **überschreiben lassen**. So werden nur Code-Dateien
  ersetzt – `data/*.sqlite` und `data/db_config.php` kommen in der neuen Version
  gar nicht vor und werden folglich nicht angefasst.
- ❌ **Falsch:** Eine „Synchronisieren"- oder „Spiegeln"-Funktion deines
  FTP-Programms verwenden, die auf dem Server alles löscht, was lokal nicht
  vorhanden ist. Das würde auch `data/*.sqlite` und `data/db_config.php` löschen,
  weil diese Dateien im heruntergeladenen ZIP nicht existieren.

Die meisten FTP-Programme (FileZilla, WinSCP etc.) fragen bei einem normalen
Upload ohnehin nach, ob vorhandene Dateien überschrieben werden sollen – das ist
der richtige, sichere Weg. Nur die explizite Sync-/Spiegel-Funktion ist die
Gefahrenstelle.

### 4. Kurz durchklicken

Seite einmal aufrufen, einloggen, einen Blick auf die Wochenansicht werfen.
Sollte alles wie gewohnt aussehen – fertig.

## Falls doch mal ein Datenbank-Update nötig wird

Aktuell ändert sich an der Datenbankstruktur zwischen Versionen nichts. Sollte
eine künftige Version neue Spalten oder Tabellen benötigen, wird das im
jeweiligen GitHub-Release oder in den Release-Notizen des betreffenden Commits
gesondert vermerkt – ein einfaches Überschreiben der Dateien reicht dann in der
Regel trotzdem, da neue Spalten in der Regel automatisch ergänzt werden, sobald
das im Code entsprechend vorgesehen ist.

## Bei MySQL statt SQLite

Für MySQL-Installationen gilt dasselbe Prinzip: `data/db_config.php` enthält
deine Zugangsdaten und ist nicht im Repository enthalten, wird also beim Update
nicht überschrieben. Schema-Änderungen an der MySQL-Datenbank würden – analog zu
SQLite – ebenfalls über `CREATE TABLE IF NOT EXISTS` beim nächsten Seitenaufruf
automatisch nachgezogen.

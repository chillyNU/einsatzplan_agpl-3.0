<?php
require __DIR__ . '/config.php';

// FAQ nur für angemeldete Benutzer (Inhalte beschreiben interne Abläufe/Funktionen)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Hilfe & FAQ – Einsatzplan</title>
    <link rel="stylesheet" href="<?= asset('theme.css') ?>">
    <style>
        body { padding: 24px; }
        .container { max-width: 860px; margin: auto; }
        .intro { color: var(--ink-soft); margin-bottom: 28px; }
        .toc { background: var(--surface-alt); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 14px 18px; margin-bottom: 30px; font-size: 0.92em; }
        .toc a { margin-right: 14px; white-space: nowrap; text-decoration: none; font-weight: 600; color: var(--t2); }
        .toc a:hover { color: var(--brand); }
        h2 { border-bottom: 2px solid var(--line); padding-bottom: 8px; margin: 42px 0 18px; font-size: 1.15em; scroll-margin-top: 20px; }
        .faq-box { border-left: 4px solid var(--brand); background: var(--surface-alt); border-radius: 0 var(--radius-sm) var(--radius-sm) 0; padding: 4px 16px 8px; margin-bottom: 16px; }
        .faq-box h3 { background: none; border: none; padding: 0; margin: 12px 0 6px; color: var(--brand); font-size: 1em; }
        .faq-box p { margin: 6px 0; }
        kbd, .sym { background: var(--surface); border: 1px solid var(--line-strong); border-radius: 4px; padding: 0 6px; font-size: 0.9em; font-weight: 600; white-space: nowrap; }
        .hint { font-size: 0.85em; color: var(--ink-soft); }
        .nav-pill { margin-top: 24px; }
        .nav-pill-top { margin: 0 0 18px; }
        /* "Nach oben"-Button wie in der Hauptansicht (style.css wird hier nicht geladen) */
        .scroll-top-btn {
            position: fixed; right: 19px; bottom: 24px;
            width: 37px; height: 37px; border-radius: 50%;
            background: #e7ebf0; color: var(--ink-soft);
            border: 1px solid var(--line-strong);
            box-shadow: var(--shadow-1);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; z-index: 9999;
            opacity: 0; visibility: hidden; transform: translateY(8px);
            transition: opacity 0.25s ease, transform 0.25s ease, background 0.25s ease, color 0.25s ease;
        }
        .scroll-top-btn.is-visible { opacity: 0.9; visibility: visible; transform: translateY(0); }
        .scroll-top-btn:hover { background: var(--brand); border-color: var(--brand); color: #fff; opacity: 1; }
        .scroll-top-btn svg { display: block; }
    </style>
</head>
<body>
<div class="container">
    <a href="index.php" class="nav-pill nav-pill-top" onclick="if (history.length > 1) { history.back(); return false; }">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Zurück
    </a>
    <span class="eyebrow">Einsatzplan</span>
    <h1>Hilfe & häufige Fragen</h1>
    <p class="intro">Diese Seite erklärt alle Funktionen des Einsatzplans – von der Terminplanung im Alltag bis zur Verwaltung im Admin-Bereich.</p>

    <div class="toc">
        <a href="#termine">Termine</a>
        <a href="#verschieben">Verschieben & Kopieren</a>
        <a href="#tag">Tagesorganisation</a>
        <a href="#navigation">Navigation & Suche</a>
        <a href="#mobil">Mobile Seiten (unterwegs)</a>
        <a href="#drucken">Drucken</a>
        <a href="#admin">Verwaltung (Admin)</a>
        <a href="#konto">Konto & Sicherheit</a>
        <a href="#datenbank">Datenbank & Migration</a>
        <a href="#rechtliches">Rechtliches & Support</a>
    </div>

    <h2 id="termine">Termine planen</h2>

    <div class="faq-box">
        <h3>Wie lege ich einen neuen Termin an?</h3>
        <p>Klicke im Plan einfach auf das + in einem freien Zeit-Slot beim gewünschten Mitarbeiter. Es öffnet sich das Buchungsfenster – in der Überschrift siehst du zur Kontrolle Wochentag, Datum, Zeitfenster und Mitarbeiter (mit Namen, falls hinterlegt). Dort hinterlegst du Kundenname, Adresse (PLZ/Ort werden beim Tippen vorgeschlagen), Telefonnummern, Einsatzart, die geplante Ankunft, eine Kundennummer bzw. (Geräte-)ID und Bemerkungen. Über die Checkboxen wählst du Zusatzoptionen (z. B. mitzubringendes Material) aus. „Speichern“, „Löschen“ und „Abbrechen“ sitzen oben im Fenster nebeneinander und bleiben beim Ausfüllen längerer Termine immer sichtbar, auch wenn du weiter nach unten im Formular scrollst.</p>
    </div>

    <div class="faq-box">
        <h3>Muss ich bei einem neuen Termin immer alle Felder ausfüllen?</h3>
        <p>Im Ausliefer-Zustand ist <strong>kein</strong> Feld verpflichtend – du kannst also auch mit wenigen Angaben speichern. Administratoren können im Admin-Bereich unter „Pflichtfelder im Buchungsformular“ festlegen, welche Felder künftig mit einem roten Stern markiert und zwingend auszufüllen sind (z. B. Name und Telefonnummer). Diese Prüfung greift sowohl im Formular als auch beim Speichern selbst.</p>
    </div>

    <div class="faq-box">
        <h3>Wie bearbeite ich einen bestehenden Termin?</h3>
        <p>Klicke auf die Terminkarte bzw. den Bearbeiten-Knopf (EDIT) am Termin. Es öffnet sich dasselbe Fenster wie beim Anlegen, vorbefüllt mit allen Daten. Unten im Termin siehst du übrigens an den Kürzeln, wer den Eintrag angelegt und zuletzt geändert hat.</p>
    </div>

    <div class="faq-box">
        <h3>Was bedeutet es, wenn ein Slot rot umrandet ist und sich nicht öffnen lässt?</h3>
        <p>Dann bearbeitet gerade eine andere Person genau diesen Termin. Im Slot steht dann in Rot, wer ihn gerade bearbeitet, und er lässt sich nicht öffnen – das verhindert zuverlässig, dass sich zwei Änderungen gegenseitig überschreiben. Die Sperre wird in Echtzeit angezeigt und bleibt bestehen, solange das Bearbeitungsfenster der anderen Person geöffnet ist. Sobald sie es schließt, wird der Slot bei dir automatisch wieder frei bzw. zeigt sofort den neu eingetragenen Termin an – ganz ohne manuelles Neuladen. Bricht die Verbindung der anderen Person ab (Browser geschlossen, Absturz), löst sich die Sperre nach wenigen Sekunden von selbst. Ein blau umrandeter Slot ist deine eigene aktive Bearbeitung. Zusätzlich prüft der Server beim Speichern noch einmal, ob der Termin frei ist, und lehnt das Speichern ab, falls ihn jemand anderes belegt hält.</p>
    </div>

    <div class="faq-box">
        <h3>Wie markiere ich einen Termin als ausgefallen?</h3>
        <p>Öffne den Termin und setze den Haken bei „Ausgefallen". Der Eintrag bleibt sichtbar, wird aber ausgegraut und durchgestrichen dargestellt – so bleibt nachvollziehbar, was geplant war.</p>
    </div>

    <div class="faq-box">
        <h3>Was passiert mit gelöschten Terminen?</h3>
        <p>Gelöschte Termine landen zuerst im Papierkorb. Administratoren können sie dort im Admin-Bereich einsehen. Endgültig entfernt werden sie erst beim Leeren des Papierkorbs oder bei der Datenbank-Bereinigung.</p>
    </div>

    <h2 id="verschieben">Verschieben & Kopieren</h2>

    <div class="faq-box">
        <h3>Wie verschiebe ich einen Termin?</h3>
        <p>Fasse den Termin an seiner <strong>Uhrzeit</strong> an (der Mauszeiger wird zur Greifhand) und ziehe ihn per Drag&amp;Drop auf einen freien Slot – auch tag- und spaltenübergreifend. Der Ziel-Slot wird beim Darüberziehen blau hervorgehoben. Willst du einen Termin in eine andere Kalenderwoche verschieben, nutze dafür das entsprechende Feld "Termin verschieben" im Bearbeitungsfenster (Button EDIT).</p>
    </div>

    <div class="faq-box">
        <h3>Wie kopiere ich einen Termin (z. B. für Wiederholungen)?</h3>
        <p>Klicke am Termin auf das Kopier-Symbol. Der Termin liegt dann in der Ablage – sichtbar an der eingeblendeten Leiste oben im Plan. Anschließend erscheint an jedem freien Slot ein Einfügen-Symbol <span class="sym">📥</span>: Ein Klick darauf legt dort eine Kopie an. Die Ablage bleibt gefüllt, du kannst denselben Termin also mehrfach einfügen. Über <span class="sym">✖</span> bzw. „Ablage leeren" beendest du den Kopiermodus. Die Ansicht bleibt dabei immer beim betreffenden Eintrag stehen.</p>
    </div>

    <h2 id="tag">Tagesorganisation</h2>

    <div class="faq-box">
        <h3>Wofür ist das gelbe Feld unter dem Mitarbeiternamen?</h3>
        <p>Das ist die Tagesnotiz je Mitarbeiter – ideal für Hinweise wie „ab 12 Uhr im Lager" oder Tourenhinweise. Einfach anklicken, Text eintragen, speichern. Die Notiz gilt nur für diesen Tag und diese Spalte.</p>
    </div>

    <div class="faq-box">
        <h3>Wie trage ich eine Vertretung ein?</h3>
        <p>Klicke neben dem Mitarbeiternamen auf <span class="sym">±</span> und trage den Namen der Vertretung ein. Er wird rot hervorgehoben angezeigt, damit sofort erkennbar ist, dass an diesem Tag jemand anderes fährt. Zum Entfernen das Feld einfach wieder leeren.</p>
    </div>

    <div class="faq-box">
        <h3>Was macht der <span class="sym">◐</span>-Knopf in der Spaltenüberschrift?</h3>
        <p>Er färbt die komplette Tagesspalte grau ein – z. B. um „kein Dienst", Urlaub oder eine gesperrte Spalte zu kennzeichnen. Die Markierung gilt pro Tag und Mitarbeiter, ist für alle sichtbar und lässt sich mit erneutem Klick wieder aufheben. Termine und Notizen bleiben dabei erhalten und lesbar.</p>
    </div>

    <div class="faq-box">
        <h3>Warum kann ich Telefonnummern im Termin anklicken?</h3>
        <p>Ein Klick auf eine Telefonnummer markiert sie und färbt grün – praktisch um sie z. B. direkt mit deiner Telefon-Software anwählen zu können. Die Nummer ist als <span class="sym">tel:</span>-Link hinterlegt und die Adresse mit einem Routen-Link zu GoogleMaps verknüpft (Startadresse stellt der Admin ein, kann aber auch manuell abgeändert werden).</p>
    </div>

    <h2 id="navigation">Navigation & Suche</h2>

    <div class="faq-box">
        <h3>Wie wechsle ich die Woche oder das Jahr?</h3>
        <p>Oben in der Mitte: mit den roten Pfeilen blätterst du wochenweise, über die Auswahlfelder springst du direkt zu einer Kalenderwoche oder einem Jahr. <strong>Heute</strong> bringt dich immer in die aktuelle Woche und scrollt direkt zum heutigen Tag (kurz rot markiert). Die runden Schaltflächen links am Rand springen zu den Tagen der angezeigten Woche, der rote Kalender-Knopf öffnet den Jahreskalender.</p>
    </div>

    <div class="faq-box">
        <h3>Wie funktioniert die Suche?</h3>
        <p>Ins Suchfeld oben rechts Name, Ort oder Telefonnummer eingeben (mindestens 2 Zeichen), Jahr wählen, Enter oder „Suchen". Die Trefferliste startet auf der Seite mit dem nächstliegenden Termin; ein Klick auf einen Treffer springt direkt zum Tag im Plan und hebt ihn kurz hervor. Ausgefallene Termine sind in der Liste durchgestrichen.</p>
    </div>

    <div class="faq-box">
        <h3>Was zeigt die Wochenansicht?</h3>
        <p>Der Knopf <strong>Wochenansicht</strong> öffnet eine kompakte Übersicht der ganzen Woche: pro Tag alle eingeplanten Mitarbeiterspalten in ihren Leitfarben (Grün, Blau, Türkis, Bernstein), belegte Zeiten farbig gefüllt. Ist für einen Mitarbeiter ein Name hinterlegt, steht dieser statt der Bezeichnung in der Spalte. Mit den Pfeilen blätterst du direkt durch die Wochen – ideal, um freie Kapazitäten zu finden, ohne die Hauptansicht zu verlassen. Die Details der Termine sind entweder als Hoover-Effekt beim überfahren mit der PC-Maus oder durch anklicken auf Tablet etc. lesbar. Über den Druck-Knopf <span class="sym">🖨️</span> oben rechts erzeugst du eine übersichtliche Druckansicht der ganzen Woche im Querformat; sie öffnet sich im selben Fenster, ohne einen neuen Tab. Als „frei" markierte Mitarbeiter werden dort grau gekennzeichnet.</p>
    </div>

    <div class="faq-box">
        <h3>Wie springe ich über den Jahreskalender zu einem Datum?</h3>
        <p>Jahreskalender öffnen (Kalender-Knopf links), Tag anklicken – der Plan lädt die passende Woche und scrollt zum gewählten Tag. Wochenendtage sind nur anklickbar, wenn die Wochenend-Anzeige aktiviert ist.</p>
    </div>

    <div class="faq-box">
        <h3>Kann ich auch Samstag und Sonntag planen?</h3>
        <p>Ja – der Administrator kann die Wochenend-Anzeige in der System-Konfiguration einschalten. Der Plan zeigt dann sieben Tage, und auch Jahreskalender und Wochenvorschau nehmen die Wochenendtage mit auf. Landet ein Suchtreffer auf einem ausgeblendeten Wochenendtag, erscheint ein entsprechender Hinweis. Für den Fall, dass ein Termin bei ausblendeten Wochenenden versehentlich auf einen Samstag oder Sonntag gespeichert wird, kann er im Einstellungsmenü durch einen Admin wieder sichtbar auf einen Wochentag verschoben werden ("Wochenend-Korrektur").</p>
    </div>

    <h2 id="mobil">Mobile Seiten (unterwegs)</h2>
    <p class="hint">Speziell für Mitarbeiter im Außendienst gibt es schlanke, fürs Smartphone optimierte Seiten – je Wochentag und Mitarbeiter eine eigene. Mitarbeiter, die nicht aktiv an der Einsatzplanung mitarbeiten, werden hierbei sinnvollerweise "nur" als Viewer angelegt.</p>

    <div class="faq-box">
        <h3>Wie kommen die Mitarbeiter an ihre mobile Tagesansicht?</h3>
        <p>Über das Zahnrad → „Mitarbeiter pro Tag" findest du für jeden Wochentag und Mitarbeiter einen eigenen QR-Code sowie einen direkten Link. Der Mitarbeiter scannt seinen QR-Code (oder öffnet den Link) und legt sich die Seite als Symbol auf den Startbildschirm. Er sieht dann ausschließlich seine eigenen Termine für diesen Wochentag – Woche für Woche durchwischbar – und keine Daten der Kollegen. Zusätzlich gibt es einen QR-Code für die Gesamtübersicht (für dich als Planer), die auf alle Tage und Mitarbeiter verlinkt.</p>
    </div>

    <div class="faq-box">
        <h3>Kann ich die mobile Seite als App installieren?</h3>
        <p>Ja. Die mobilen Seiten sind als <strong>PWA</strong> (Progressive Web App) angelegt und lassen sich über „Zum Startbildschirm hinzufügen" wie eine App installieren. Das Symbol zeigt dann den jeweiligen Tag und Mitarbeiter (z. B. „Mo.1" für Montag, Mitarbeiter 1) in der Mitarbeiter-Farbe.</p>
    </div>

    <div class="faq-box">
        <h3>Muss man sich auf dem Handy jedes Mal neu anmelden?</h3>
        <p>Nein. Nach dem ersten Login auf einem Gerät bleibt man rund 30 Tage angemeldet – ein erneutes Passwort ist in dieser Zeit nicht nötig. Ein Login bleibt aber grundsätzlich Voraussetzung: Ohne Anmeldung sieht niemand Termindaten. Beim Login lässt sich über eine Auswahl direkt entscheiden, ob das Hauptprogramm oder die mobile Übersicht geöffnet wird.</p>
    </div>

    <div class="faq-box">
        <h3>Aktualisiert sich die mobile Seite, wenn im Büro etwas geändert wird?</h3>
        <p>Ja. Ändert sich am Plan etwas (neuer Termin, Änderung, Löschung), erscheint auf der mobilen Seite ein auffälliges Banner „Neue Daten verfügbar" mit der Aufforderung zum Aktualisieren. Die Seite prüft das regelmäßig automatisch und auch sofort, sobald man sie wieder in den Vordergrund holt.</p>
    </div>

    <div class="faq-box">
        <h3>Was muss ich zur Erreichbarkeit unterwegs beachten?</h3>
        <p>Die mobilen Seiten funktionieren nur, wenn der Einsatzplan aus dem Internet erreichbar ist. Liegt die Anwendung aus Datenschutzgründen geschützt im firmeneigenen Netzwerk (z. B. auf einem internen Server), brauchen die Dienst-Handys einen gesicherten Zugang von außen – etwa per <strong>VPN</strong> –, um die Daten unterwegs abrufen zu können. Für das Installieren als App ist außerdem eine verschlüsselte Verbindung (HTTPS) nötig. Über den Knopf „QR-Codes erneuern" lässt sich der Zugangs-Token bei Bedarf turnusmäßig wechseln.</p>
    </div>

    <h2 id="drucken">Drucken</h2>

    <div class="faq-box">
        <h3>Wie drucke ich den Tagesplan für einen Mitarbeiter?</h3>
        <p>In der Tagesleiste rechts findest du je Mitarbeiter einen Druck-Knopf <span class="sym">🖨️</span>. Er öffnet eine druckoptimierte A4-Ansicht des Tages mit allen Terminen, Adressen, Telefonnummern, Einsatzarten und Bemerkungen – ausgefallene Termine durchgestrichen. Von dort direkt drucken oder als PDF speichern.</p>
    </div>

    <h2 id="admin">Verwaltung (Admin-Bereich)</h2>
    <p class="hint">Der Admin-Bereich (Zahnrad-Symbol oben rechts) ist nur für Benutzer mit Administrator-Rolle sichtbar.</p>

    <div class="faq-box">
        <h3>Wie finde ich mich im Admin-Bereich zurecht?</h3>
        <p>Alle Bereiche sind über ein einziges Auswahlmenü oben erreichbar – von der Benutzerverwaltung über Einsatzarten, Mitarbeiter pro Tag und PLZ-Verzeichnis bis zu den externen Werkzeugen (Wochenend-Korrektur, Datenbank-Manager, DB-Konfiguration). Du wählst einen Punkt aus, und nur dieser Bereich wird angezeigt. Über „Zum Dienstplan" oben links geht es zurück zur Planansicht.</p>
    </div>

    <div class="faq-box">
        <h3>Wie lege ich fest, wer wann fährt?</h3>
        <p>Unter „Mitarbeiter-Namen & Zeiträume" ordnest du je Wochentag und Spalte einen Namen zu – gültig für einen KW-Bereich (von/bis) im gewählten Jahr. So lassen sich Rotationen, Elternzeiten oder Wechsel sauber abbilden; der Plan zeigt automatisch den für die jeweilige Woche gültigen Namen. Ist ein Name hinterlegt, steht er direkt in der Spaltenüberschrift des Tages (statt „Mitarbeiter 1" usw.) – sowohl im Plan als auch in der Wochenansicht und auf den mobilen Seiten.</p>
    </div>

    <div class="faq-box">
        <h3>Kann ich die Bezeichnungen „Mitarbeiter 1, 2, 3 …" umbenennen?</h3>
        <p>Ja, über die Beschriftungs-Einstellungen. Die neuen Bezeichnungen erscheinen überall: in den Spaltenköpfen, auf den Druck-Buttons und im Druck-Layout. Wie viele Mitarbeiter pro Wochentag angezeigt werden, legst du im Admin-Bereich unter „Mitarbeiter pro Tag" fest – bis zu 12 Mitarbeiter pro Tag sind möglich.</p>
    </div>

    <div class="faq-box">
        <h3>Wie verwalte ich Einsatzarten und Zusatzoptionen?</h3>
        <p>Im Admin-Bereich pflegst du Haupt- und Unterarten der Einsätze sowie die Checkbox-Optionen des Buchungsfensters (inklusive Sortierung). Beides steht danach sofort in allen Terminen zur Auswahl.</p>
    </div>

    <div class="faq-box">
        <h3>Welche Benutzerrollen gibt es?</h3>
        <p><strong>Admin</strong> darf alles inklusive System-Konfiguration. <strong>User</strong> plant Termine im Alltag. <strong>Viewer</strong> hat reinen Lesezugriff – kann also nichts anlegen, ändern, verschieben oder löschen. Benutzer legst du im Admin-Bereich an (Name, Passwort, Kürzel, Rolle); das Kürzel erscheint an jedem Termin als Änderungsnachweis.</p>
    </div>

    <div class="faq-box">
        <h3>Wie lege ich Pflichtfelder für die Terminanlage fest?</h3>
        <p>Unter „Pflichtfelder im Buchungsformular“ wählst du per Checkbox aus, welche der im Buchungsfenster verfügbaren Felder (z. B. Name, Adresse, Telefon) künftig zwingend ausgefüllt werden müssen. Ohne Auswahl ist – wie im Ausliefer-Zustand – kein Feld Pflicht. Die Einstellung wirkt sofort für alle Benutzer und wird zusätzlich beim Speichern serverseitig geprüft, sodass sie sich nicht umgehen lässt.</p>
    </div>

    <div class="faq-box">
        <h3>Wozu dient die Wochenend-Korrektur?</h3>
        <p>Sie listet alle Termine, die auf einem Samstag oder Sonntag liegen – hilfreich, wenn die Wochenend-Anzeige deaktiviert ist und solche Einträge sonst unsichtbar wären (etwa nach einem Tippfehler beim Datum). Von dort kannst du die Termine direkt korrigieren.</p>
    </div>

    <div class="faq-box">
        <h3>Wie halte ich die Datenbank schlank?</h3>
        <p>Über „Datenbank bereinigen" entfernst du alle Termine vor einem Stichtag endgültig (mit Sicherheitsabfrage). Der Papierkorb lässt sich separat leeren, und die Duplikat-Suche findet doppelt angelegte Einträge (gleicher Name, Datum und Uhrzeit).</p>
    </div>

    <h2 id="konto">Konto & Sicherheit</h2>

    <div class="faq-box">
        <h3>Wie ändere ich mein Passwort?</h3>
        <p>Klicke oben rechts auf deinen Benutzernamen – im Profilfenster kannst du dein Passwort selbst ändern. Alternativ kann der Administrator in der Benutzerverwaltung ein neues Passwort setzen.</p>
    </div>

    <div class="faq-box">
        <h3>Wer sieht, was ich geändert habe?</h3>
        <p>Jeder Termin speichert die Kürzel von Ersteller und letztem Bearbeiter (unten rechts an der Terminkarte). Löschungen werden zusätzlich mit Zeitpunkt und Kürzel im Papierkorb protokolliert.</p>
    </div>

    <h2 id="datenbank">Datenbank & Migration (SQLite / MySQL)</h2>

    <div class="faq-box">
        <h3>Welche Datenbank nutzt der Einsatzplan?</h3>
        <p>Standardmäßig eine <strong>SQLite</strong>-Datenbank – eine einzelne Datei unter <kbd>data/einsatzplan.sqlite</kbd>. Sie funktioniert ohne jede Einrichtung und ist für die allermeisten Installationen völlig ausreichend. Alternativ kann die Software auf einer eigenen <strong>MySQL-/MariaDB-Datenbank</strong> laufen, z. B. der Datenbank deines Webhosting-Pakets.</p>
    </div>

    <div class="faq-box">
        <h3>Wie stelle ich auf MySQL um?</h3>
        <p>Als Admin: <em>Verwaltung → DB-Konfiguration (MySQL)</em>. Dort trägst du Host, Port, Datenbankname, Benutzer und Passwort ein (die Daten bekommst du von deinem Hoster), testest die Verbindung und startest die Migration. Alles Weitere läuft automatisch: Die komplette aktuelle Struktur und sämtliche Daten (Benutzer, Termine, Mitarbeiter, Einsatzarten, Zusatzoptionen, Einstellungen, PLZ-Verzeichnis …) werden übernommen und die Zeilenzahlen zur Kontrolle verglichen. Erst bei vollständigem Erfolg schaltet die Anwendung um.</p>
    </div>

    <div class="faq-box">
        <h3>Was passiert bei der Migration mit meinen SQLite-Daten?</h3>
        <p>Nichts – die Datei <kbd>data/einsatzplan.sqlite</kbd> bleibt unverändert als Sicherung liegen. Über die DB-Konfigurationsseite kannst du jederzeit wieder auf SQLite zurückschalten. Wichtig: Beim Umschalten werden <strong>keine Daten synchronisiert</strong>; Änderungen aus der MySQL-Zeit sind in der SQLite-Datei nicht enthalten (und umgekehrt).</p>
    </div>

    <div class="faq-box">
        <h3>Die Migration meldet einen Fehler – was nun?</h3>
        <p>Zu jedem Fehler zeigt die Seite eine konkrete Hilfestellung an (falscher Host, falsches Passwort, fehlende Rechte, Datenbank existiert nicht …). Solange die Migration nicht vollständig durchgelaufen ist, wird <strong>nicht umgeschaltet</strong> – die Anwendung läuft unverändert weiter. Fehler beheben und einfach erneut starten.</p>
    </div>

    <div class="faq-box">
        <h3>MySQL ist plötzlich nicht erreichbar – die Seite zeigt nur noch einen Hinweis.</h3>
        <p>Läuft die Anwendung auf MySQL und der Datenbankserver ist nicht erreichbar, erscheint eine Hinweisseite mit den häufigsten Ursachen. Sofort-Lösung im Notfall: Die Datei <kbd>data/db_config.php</kbd> auf dem Server löschen oder umbenennen – die Anwendung läuft dann wieder mit der lokalen SQLite-Sicherung (Stand der letzten Migration).</p>
    </div>

    <div class="faq-box">
        <h3>Was kann der Datenbank-Manager?</h3>
        <p>Er zeigt Administratoren alle Tabellen mit Inhalt an. Zusätzlich lassen sich dort einzelne Einträge oder ganze Tabellen löschen. Jeder Löschvorgang zeigt eine deutliche Warnung und muss mit dem <strong>Admin-Passwort</strong> bestätigt werden – ohne korrektes Passwort wird nichts gelöscht. Löschungen sind endgültig und können nicht rückgängig gemacht werden.</p>
    </div>

    <div class="faq-box">
        <h3>Woher kommen die PLZ-/Orts-Vorschläge bei der Terminanlage?</h3>
        <p>Aus dem mitgelieferten deutschen PLZ-Verzeichnis (Quelle: GeoNames.org, Lizenz CC BY 4.0). Es wird beim ersten Aufruf automatisch in die Datenbank übernommen. In der System-Konfiguration siehst du unter „PLZ-Verzeichnis", wie viele Einträge geladen sind, und kannst die Daten bei Bedarf neu importieren.</p>
    </div>

    <h2 id="rechtliches">Rechtliches & Support</h2>

    <div class="faq-box">
        <h3>Unter welcher Lizenz steht die Software?</h3>
        <p>Einsatzplan ist freie Software und steht unter der GNU Affero General Public License v3.0 (AGPL-3.0). Quelltext, Lizenztext und Mitwirkungshinweise findest du im Projekt-Repository. Du darfst die Software frei nutzen, verändern und weitergeben; wer eine veränderte Version öffentlich über ein Netzwerk anbietet, muss den veränderten Quellcode ebenfalls unter der AGPL-3.0 bereitstellen.</p>
    </div>

    <div class="faq-box">
        <h3>Wo passe ich das Impressum an?</h3>
        <p>Als Betreiber bist du für ein rechtskonformes Impressum verantwortlich. Administratoren können den Inhalt der Impressum-Seite direkt im System bearbeiten; der Firmenname auf der Anmeldeseite lässt sich in der System-Konfiguration hinterlegen.</p>
    </div>

    <div class="faq-box">
        <h3>An wen wende ich mich bei technischen Problemen?</h3>
        <p>Zuerst an deinen Administrator. Hilfreich zur Eingrenzung: Der Datenbank-Manager und der DB-Struktur-Check zeigen, ob Daten und Tabellen vollständig sind.</p>
    </div>

    <a href="index.php" class="nav-pill" onclick="if (history.length > 1) { history.back(); return false; }">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Zurück
    </a>
</div>

<button type="button" id="scrollTopBtn" class="scroll-top-btn" title="Nach oben" aria-label="Nach oben scrollen" onclick="window.scrollTo({top:0, behavior:'smooth'})">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="17 11 12 6 7 11" />
        <polyline points="17 18 12 13 7 18" />
    </svg>
</button>
<script>
// "Nach oben"-Button ein-/ausblenden, sobald weit genug gescrollt wurde
(function() {
    const btn = document.getElementById('scrollTopBtn');
    if (!btn) return;
    const toggle = function() {
        if (window.scrollY > 300) { btn.classList.add('is-visible'); }
        else { btn.classList.remove('is-visible'); }
    };
    window.addEventListener('scroll', toggle, { passive: true });
    toggle();
})();

// Anker-Sprünge (Inhaltsverzeichnis) ohne neue Verlaufseinträge:
// Standardmäßig legt jeder Klick auf #termine, #mobil usw. einen eigenen
// Browser-History-Eintrag an. history.back() (Zurück-Button) würde dann nur
// zum vorherigen Anker DIESER Seite springen statt die Seite zu verlassen.
// Deshalb: selbst scrollen und den aktuellen Eintrag per replaceState
// ersetzen – so führt "Zurück" immer mit einem Klick aus der FAQ heraus.
(function() {
    document.addEventListener('click', function (ev) {
        const a = ev.target.closest('a[href^="#"]');
        if (!a) return;
        const ziel = document.getElementById(a.getAttribute('href').substring(1));
        if (!ziel) return;
        ev.preventDefault();
        ziel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        try { history.replaceState(null, '', a.getAttribute('href')); } catch (e) {}
    });
})();
</script>
</body>
</html>

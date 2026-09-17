
/* eslint-disable no-unused-vars */
/* global artenMap, dynOptions, selectedWeek, selectedYear, isViewer */

// --- KONFIGURATION ---
const artenOhneOptionen = ["Geräteabbau", "Krankheit", "Fortbildung"]; // Hier Einsatzarten ergänzen

// --- Initialisierung beim Laden der Seite ---
window.addEventListener('load', function() {
    // 1. Scroll-Verhalten: Expliziter Zustand (Slot-Reload etc.) hat Vorrang,
    //    ansonsten gewinnt ein Anker in der URL (#day-YYYY-MM-DD),
    //    damit Sprünge aus Suche & Kalender zuverlässig funktionieren.
    const scrollPos = sessionStorage.getItem('scrollPosition');
    const hashTarget = window.location.hash ? document.getElementById(window.location.hash.substring(1)) : null;
    if (scrollPos !== null) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem('scrollPosition');
    } else if (hashTarget) {
        // Browser-Sprung kann durch spät geladenes Layout verrutschen -> explizit nachziehen
        hashTarget.scrollIntoView({ behavior: 'auto', block: 'start' });
        hashTarget.classList.add('jump-highlight');
        setTimeout(() => hashTarget.classList.remove('jump-highlight'), 2500);
    } else if (/^#day-\d{4}-\d{2}-\d{2}$/.test(window.location.hash)) {
        // Zieltag existiert nicht in dieser Ansicht (z.B. Sa/So bei ausgeblendetem Wochenende).
        // Hinweis bewusst allgemein gehalten: Der Sprung kann von einem Termin, dem
        // "Heute"-Button, der Tagesleiste oder dem Jahreskalender ausgelöst worden sein –
        // ob an diesem Tag überhaupt ein Termin existiert, wissen wir an dieser Stelle nicht.
        const dParts = window.location.hash.substring(5).split('-');
        const jsDate = new Date(Number(dParts[0]), Number(dParts[1]) - 1, Number(dParts[2]));
        const wochentag = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][jsDate.getDay()];
        const hint = document.createElement('div');
        hint.textContent = 'Hinweis: Der ' + wochentag + ', ' + dParts[2] + '.' + dParts[1] + '.' + dParts[0] +
            ' wird aktuell nicht angezeigt (Wochenende ausgeblendet). Aktiviere in den Einstellungen ' +
            '"Wochenende anzeigen", um diesen Tag im Plan zu sehen.';
        hint.style.cssText = 'position:fixed; top:15px; left:50%; transform:translateX(-50%); z-index:99999;' +
            'background:var(--drk-red, #e60005); color:#fff; padding:12px 20px; border-radius:8px;' +
            'box-shadow:0 4px 12px rgba(0,0,0,0.3); font-weight:bold; max-width:90vw;';
        document.body.appendChild(hint);
        setTimeout(() => hint.remove(), 6000);
    }

    // 2. AUTOMATIK NACH REFRESH: Slot wieder öffnen
    const slotIdToOpen = sessionStorage.getItem('openSlotAfterRefresh');
    if (slotIdToOpen) {
        sessionStorage.removeItem('openSlotAfterRefresh');
        const el = document.getElementById(slotIdToOpen);
        if (el) {
            openModalLogic(el);
        }
    }

    // 2b. Horizontale Mitarbeiter-Scroll-Position des betroffenen Tages wiederherstellen
    //     (sonst springt die Ansicht nach dem Reload immer auf Mitarbeiter 1-3 zurück,
    //     auch wenn vorher z. B. Mitarbeiter 4-12 sichtbar waren).
    const techScrollDay = sessionStorage.getItem('techScrollDay');
    const techScrollLeft = sessionStorage.getItem('techScrollLeft');
    if (techScrollDay !== null && techScrollLeft !== null) {
        sessionStorage.removeItem('techScrollDay');
        sessionStorage.removeItem('techScrollLeft');
        const dayRow = document.getElementById(techScrollDay);
        const techWrap = dayRow ? dayRow.querySelector('.tech-columns-wrap') : null;
        if (techWrap) {
            techWrap.scrollLeft = parseInt(techScrollLeft, 10);
        }
    }

    // 3. Numerische Felder Validierung
    const numericFields = ['f_tel1', 'f_tel2', 'f_tel3', 'f_plz'];
    numericFields.forEach(id => {
        const field = document.getElementById(id);
        if(field) {
            field.setAttribute('inputmode', 'text');
            field.addEventListener('input', function() {
                // Ändere diese Zeile:
                const regex = (id === 'f_plz') ? /[^0-9]/g : /[^\d\-\/]/g;
                this.value = this.value.replace(regex, '');
            });
        }
    });
});

// Hinweis: Der frühere globale 'beforeunload'-Handler wurde entfernt.
// Er speicherte die Scroll-Position bei JEDER Navigation und überschrieb dadurch
// den Anker-Sprung (#day-...) aus Suchergebnissen und Jahreskalender.
// Der Scroll-Restore wird jetzt gezielt vor location.reload() gesetzt.

// PLZ-Suche läuft jetzt serverseitig (plz_daten.php?q=…) – kein Vorab-Download mehr nötig.
let plzSearchTimer = null;
const plzSearchCache = {};

// --- Modal Steuerung ---
function openYearModal() { document.getElementById('yearModal').style.display = 'block'; }
function closeModal() { document.getElementById('bookingModal').style.display = 'none'; }
function closeVModal() { document.getElementById('vertretungModal').style.display = 'none'; }
function closeNoteModal() { document.getElementById('noteModal').style.display = 'none'; }
function closeMapsModal() { 
    document.getElementById('mapsIframe').src = ''; 
    document.getElementById('mapsModal').style.display = 'none'; 
}

function closeAllModals() {
    const modals = ['bookingModal', 'vertretungModal', 'noteModal', 'mapsModal', 'searchResultModal', 'yearModal', 'profileModal', 'deleteConfirmModal', 'lockWarningModal'];
    modals.forEach(m => {
        const el = document.getElementById(m);
        if(el) el.style.display = 'none';
    });
    unlockCurrentSlot();
}


// Schließen bei Klick ins Graue (DIESEN BLOCK ENTFERNEN ODER AUSKOMMENTIEREN)
/*let clickStartTarget = null;
window.addEventListener('mousedown', function(e) { clickStartTarget = e.target; });
window.addEventListener('mouseup', function(e) {
    if (clickStartTarget && clickStartTarget.classList.contains('modal') && e.target.classList.contains('modal')) {
        closeAllModals();
    }
    clickStartTarget = null;
});
*/


// --- Termin & Slot Logik ---

function handleSlotClick(el) {
    if (isViewer) return;
    // Fremd gesperrter Slot: sofort Hinweis zeigen, nicht öffnen
    if (el.classList.contains('is-locked-by-other')) {
        const name = (el.title || '').replace('In Bearbeitung bei ', '').trim() || 'einem Kollegen';
        const warning = document.getElementById('lockWarningText');
        if (warning) warning.innerHTML =
            `Dieser Termin ist aktuell in Bearbeitung bei <b>${name}</b> und kann derzeit nicht von Dir beplant werden.`;
        document.getElementById('lockWarningModal').style.display = 'block';
        return;
    }
    sessionStorage.setItem('openSlotAfterRefresh', el.id);
    sessionStorage.setItem('scrollPosition', window.scrollY);
    // Horizontale Scroll-Position der Mitarbeiter-Spalten dieses Tages merken
    // (z. B. wenn Mitarbeiter 4-12 durch Scrollen sichtbar gemacht wurden),
    // damit der Reload nicht wieder auf Mitarbeiter 1-3 zurückspringt.
    const dayRowForScroll = el.closest('.day-row');
    const techWrapForScroll = el.closest('.tech-columns-wrap');
    if (dayRowForScroll && techWrapForScroll) {
        sessionStorage.setItem('techScrollDay', dayRowForScroll.id);
        sessionStorage.setItem('techScrollLeft', techWrapForScroll.scrollLeft);
    } else {
        sessionStorage.removeItem('techScrollDay');
        sessionStorage.removeItem('techScrollLeft');
    }
    location.reload();
}

async function openModalLogic(el) {
    // 1. Erst den Lock beim Server anfragen
    const formData = new FormData();
    formData.append('action', 'lock');
    formData.append('slot_id', el.id);
    
    const resp = await fetch('ajax_lock.php', { method: 'POST', body: formData });
    const result = await resp.json();

    if (result.status === 'locked') {
        const warning = document.getElementById('lockWarningText');
        if (warning) {
            let userFromTitle = el.title.replace('Bearbeitet von ', '').trim();
            const username = (result.user && result.user !== '0') ? result.user : (userFromTitle || 'einem anderen Nutzer');
            warning.innerHTML = `Slot belegt durch <b>${username}</b>.`;
        }
        document.getElementById('lockWarningModal').style.display = 'block';
        return;
    }

    el.classList.add('is-my-lock');
    startHeartbeat(el.id);
    const isBooked = el.getAttribute('data-booked') === '1';

    // Überschrift mit Kontext: Wochentag, Datum, Uhrzeit und Mitarbeiter des Slots
    (function () {
        const datumRaw = el.getAttribute('data-datum') || '';
        const uhrzeit  = el.getAttribute('data-uhrzeit') || '';
        const techId   = el.getAttribute('data-tech') || '';
        // Datum TT.MM.JJJJ + Wochentag
        let datumStr = datumRaw;
        let wochentag = '';
        const m = datumRaw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (m) {
            datumStr = m[3] + '.' + m[2] + '.' + m[1];
            const wt = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
            const d = new Date(datumRaw + 'T00:00:00');
            wochentag = wt[d.getDay()] || '';
        }
        // Mitarbeiter-Bezeichnung (Label, ggf. umbenannt) aus dem Spaltenkopf
        let maLabel = 'Mitarbeiter ' + techId;
        const col = el.closest('[class*="tech-column-"]');
        if (col) {
            const badge = col.querySelector('.tech-badge');
            if (badge) maLabel = badge.textContent.trim().replace(/:$/, '').trim();
        }
        // Echter, zugewiesener Name (falls definiert); Vertretung hat Vorrang
        const vertretung = (el.getAttribute('data-vertretung') || '').trim();
        const maName     = (el.getAttribute('data-maname') || '').trim();
        const person = vertretung !== '' ? vertretung : maName;
        // Anzeige: Label plus – falls vorhanden – der Name in Klammern
        let maAnzeige = maLabel;
        if (person !== '') {
            maAnzeige = maLabel + ' (' + person + (vertretung !== '' ? ', Vertretung' : '') + ')';
        }

        const titel = document.getElementById('modalTitle');
        const basis = isBooked ? 'Termin bearbeiten:' : 'Neuer Termin:';
        const datumTeil = (wochentag ? wochentag + ', ' : '') + datumStr;
        titel.innerHTML = basis
            + '<span class="modal-subtitle">' + datumTeil
            + ', Zeitfenster ' + uhrzeit + ' Uhr, ' + maAnzeige + '</span>';
    })();

    const fields = ['id','datum','uhrzeit','tech','art','ankunft','name','strasse','zusatz','plz','ort','pk','tel1','tel1n','tel2','tel2n','tel3','tel3n','bem'];
    fields.forEach(f => {
        const field = document.getElementById('f_' + f);
        if(field) field.value = el.getAttribute('data-' + f) || '';
    });

    document.getElementById('f_new_datum').value = el.getAttribute('data-datum');
    document.getElementById('f_new_uhrzeit').value = el.getAttribute('data-uhrzeit');
    
    const techSelect = document.getElementById('f_new_tech');
    if (techSelect) {
        // Nur so viele Mitarbeiter-Optionen zeigen, wie der jeweilige Tag hat
        const dayRow = el.closest('.day-row');
        const maxTech = dayRow ? parseInt(dayRow.getAttribute('data-maxtech'), 10) : 4;
        Array.from(techSelect.options).forEach(opt => {
            const val = parseInt(opt.value, 10);
            opt.hidden = (val > maxTech);
            opt.disabled = (val > maxTech);
        });
        techSelect.value = el.getAttribute('data-tech');
    }

    const fallen = document.getElementById('f_ausgefallen');
    if(fallen) fallen.checked = (el.getAttribute('data-ausgefallen') === '1');

    dynOptions.forEach(opt => {
        const field = document.getElementById('f_' + opt.spalten_name);
        if(field) field.checked = (el.getAttribute('data-' + opt.spalten_name) === '1');
    });

    updateSubTypes(el.getAttribute('data-unterart') || '');
    
    // Optionen Sichtbarkeit prüfen
    checkOptionsVisibility(document.getElementById('f_art').value);

    const btnDel = document.getElementById('btnDel');
    if(btnDel) btnDel.style.display = isBooked ? 'block' : 'none';

    document.getElementById('bookingModal').style.display = 'block';
}

function updateSubTypes(selectedUnterart = "") { 
    const haupt = document.getElementById('f_art').value; 
    const subSelect = document.getElementById('f_unterart'); 
    const container = document.getElementById('sub_type_container'); 
    if(!subSelect || !container) return;
    
    // Sichtbarkeit der Optionen anpassen
    checkOptionsVisibility(haupt);
    
    subSelect.innerHTML = ''; 
    const subs = artenMap[haupt] || []; 
    const validSubs = subs.filter(s => s !== null && s !== ""); 
    if (validSubs.length > 0) { 
        container.style.display = 'block'; 
        validSubs.forEach(s => { 
            const opt = document.createElement('option'); 
            opt.value = s; 
            opt.text = (s === '-') ? '-- Keine --' : s; 
            if(s === selectedUnterart) opt.selected = true; 
            subSelect.add(opt); 
        }); 
    } else { 
        container.style.display = 'none'; 
    } 
}

// Hilfsfunktion zur Steuerung der Sichtbarkeit
function checkOptionsVisibility(hauptArt) {
    const optionsGrid = document.querySelector('.options-grid');
    if (!optionsGrid) return;
    
    if (artenOhneOptionen.includes(hauptArt)) {
        optionsGrid.style.display = 'none';
        
        // ZUSATZ: Hier entfernen wir alle Häkchen
        const checkboxes = optionsGrid.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => {
            cb.checked = false;
        });
    } else {
        optionsGrid.style.display = 'grid';
    }
}

// --- Hilfsfunktionen ---
function openDeleteConfirmModal() { document.getElementById('deleteConfirmModal').style.display = 'block'; }
function confirmDelete() {
    const id = document.getElementById('f_id').value;
    // csrfToken ist als globale JS-Variable in index.php definiert
    const slotInput = document.getElementById('f_slot');
    const slotId = slotInput ? slotInput.value : '';
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'delete_appointment.php';
    const fields = { id: id, csrf_token: csrfToken, w: selectedWeek, y: selectedYear, slot: slotId };
    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
}

function openMapsModal(adresse) {
    // 1. Startadresse aus dem HTML-Element laden
    const configDiv = document.getElementById('maps-config');
    const startAddr = configDiv ? configDiv.dataset.startAddr : "Frauenstr. 125, 89073 Ulm";
    
    // 2. Offizielle Google Maps Directions-URL (api=1 Format)
    const encodedStart = encodeURIComponent(startAddr);
    const encodedZiel = encodeURIComponent(adresse);
    
    // Korrektes Format für Routen: origin -> destination
    const navUrl = `https://www.google.com/maps/dir/?api=1&origin=${encodedStart}&destination=${encodedZiel}&travelmode=driving`;

    // 3. Button im Modal erzeugen
    const btnContainer = document.getElementById('mapsAppBtn');
    if(btnContainer) {
        btnContainer.innerHTML = 
            `<a href="${navUrl}" 
                target="_blank" 
                onclick="closeMapsModal()" 
                style="padding:15px 30px; background:#d71920; color:white; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;">
                Route bei Google Maps starten
            </a>`;
    }

    // 4. Modal anzeigen und das leere Iframe ausblenden
    const modal = document.getElementById('mapsModal');
    const iframe = document.getElementById('mapsIframe');
    
    if(iframe) iframe.style.display = 'none';
    if(modal) modal.style.display = 'block';
}
function copyAndGreen(el, event) {
    if (event) event.stopPropagation();
    navigator.clipboard.writeText(el.innerText.trim()).then(() => {
        document.querySelectorAll('.tel-box').forEach(box => box.classList.remove('is-marked'));
        el.classList.add('is-marked');
    });
}

function handlePlzInput(val) {
    const list = document.getElementById('plz_suggestions');
    const clean = val.replace(/[^0-9]/g, '');

    if (clean.length < 2) {
        list.style.display = 'none';
        return;
    }

    // Kurz warten, bis der Nutzer zu Ende getippt hat (entlastet den Server)
    clearTimeout(plzSearchTimer);
    plzSearchTimer = setTimeout(() => {
        const render = (matches) => {
            if (matches.length > 0) {
                let newHtml = '';
                matches.forEach(match => {
                    const land = match.l ? ` <span style="color:#999; font-size:0.85em;">(${match.l})</span>` : '';
                    newHtml += `<div style="padding:10px; cursor:pointer; border-bottom:1px solid #eee;"
                                 onmouseover="this.style.backgroundColor='#f0f0f0'"
                                 onmouseout="this.style.backgroundColor='white'"
                                 onclick="selectPlz('${match.p}', '${match.o.replace(/'/g, "\\'")}')">
                                 ${match.p} ${match.o}${land}
                                </div>`;
                });
                list.innerHTML = newHtml;
                list.style.display = 'block';
            } else {
                list.style.display = 'none';
            }
        };

        if (plzSearchCache[clean]) {
            render(plzSearchCache[clean]);
            return;
        }
        fetch('plz_daten.php?q=' + encodeURIComponent(clean))
            .then(r => r.json())
            .then(data => {
                plzSearchCache[clean] = data;
                render(data);
            })
            .catch(() => { list.style.display = 'none'; });
    }, 200);
}

// Neue Hilfsfunktion, die beim Klick auf einen Ort die Werte einträgt
function selectPlz(plz, ort) {
    document.getElementById('f_plz').value = plz;
    document.getElementById('f_ort').value = ort;
    document.getElementById('plz_suggestions').style.display = 'none';
}

// --- Vertretung & Notizen ---
function openVertretungModal(datum, techId, techName, vName) {
    if (isViewer) return;
    const dateParts = datum.split('-');
    document.getElementById('v_f_datum').value = datum;
    document.getElementById('v_f_tech').value = techId;
    document.getElementById('v_f_name').value = vName || '';
    document.getElementById('v_info_text').innerHTML = `Vertretung für <b>${techName}</b> am ${dateParts[2]}.${dateParts[1]}.${dateParts[0]}`;
    document.getElementById('vertretungModal').style.display = 'block';
}

function submitVertretung() {
    if (isViewer) return;
    const form = document.createElement('form'); 
    form.method = 'POST'; 
    form.action = 'save_vertretung.php';
    const fields = { datum: document.getElementById('v_f_datum').value, tech_id: document.getElementById('v_f_tech').value, v_name: document.getElementById('v_f_name').value, return_w: selectedWeek, return_y: selectedYear, csrf_token: csrfToken };
    for (const [k, v] of Object.entries(fields)) { 
        const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = k; inp.value = v; form.appendChild(inp); 
    }
    document.body.appendChild(form); 
    form.submit();
}

function openNoteModal(datum, techId, currentNote) {
    if (isViewer) return;
    document.getElementById('n_f_datum').value = datum;
    document.getElementById('n_f_tech').value = techId;
    document.getElementById('n_f_text').value = currentNote || '';
    document.getElementById('noteModal').style.display = 'block';
}

function submitNote() {
    if (isViewer) return;
    const form = document.createElement('form'); 
    form.method = 'POST'; 
    form.action = 'save_note.php';
    const fields = { datum: document.getElementById('n_f_datum').value, tech_id: document.getElementById('n_f_tech').value, notiz: document.getElementById('n_f_text').value, return_w: selectedWeek, return_y: selectedYear, csrf_token: csrfToken };
    for (const [k, v] of Object.entries(fields)) { 
        const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = k; inp.value = v; form.appendChild(inp); 
    }
    document.body.appendChild(form); 
    form.submit();
}

// --- Sperr-System (Echtzeit) ---
// Merkt sich, welche fremden Slots zuletzt gesperrt waren. Verschwindet eine
// fremde Sperre (der Kollege hat den Slot verlassen), wird die Ansicht neu
// geladen, damit ein neu eingetragener Termin erscheint bzw. der Slot wieder
// frei und leer ist.
let zuletztFremdGesperrt = new Set();
let ersteLockPruefung = true;

async function refreshLocks() {
    try {
        const response = await fetch('ajax_check_locks.php');
        const locks = await response.json();
        if (!Array.isArray(locks)) return;

        const jetztFremd = new Set();

        // Erst alle fremden Markierungen zurücksetzen
        document.querySelectorAll('.slot.is-locked-by-other').forEach(el => {
            el.classList.remove('is-locked-by-other');
            el.title = "";
            const hinweis = el.querySelector('.lock-hinweis');
            if (hinweis) hinweis.remove();
        });

        locks.forEach(lock => {
            const slotEl = document.getElementById(lock.slot_id);
            if (!slotEl) return;
            // Eigene Sperre nicht als "fremd" behandeln
            if (slotEl.classList.contains('is-my-lock')) return;
            if (String(lock.user_id) === String(window.currentUserId)) return;

            jetztFremd.add(lock.slot_id);
            slotEl.classList.add('is-locked-by-other');
            slotEl.title = `In Bearbeitung bei ${lock.username}`;

            // Roten Hinweistext in den Slot setzen (nur einmal)
            if (!slotEl.querySelector('.lock-hinweis')) {
                const div = document.createElement('div');
                div.className = 'lock-hinweis';
                div.textContent = `Dieser Termin ist aktuell in Bearbeitung bei ${lock.username} `
                    + `und kann derzeit nicht von Dir beplant werden.`;
                slotEl.appendChild(div);
            }
        });

        // Ist eine zuvor fremde Sperre jetzt weg? Dann hat der Kollege den Slot
        // verlassen -> neu laden, damit sein Ergebnis sichtbar wird.
        if (!ersteLockPruefung) {
            let verschwunden = false;
            zuletztFremdGesperrt.forEach(id => { if (!jetztFremd.has(id)) verschwunden = true; });
            if (verschwunden) {
                // Position/geöffneten Slot merken wie bei einem normalen Reload
                try {
                    sessionStorage.setItem('scrollPosition', window.scrollY);
                } catch (e) {}
                location.reload();
                return;
            }
        }

        zuletztFremdGesperrt = jetztFremd;
        ersteLockPruefung = false;
    } catch { }
}
setInterval(refreshLocks, 4000);
refreshLocks();

// --- Heartbeat: hält den eigenen Lock am Leben, solange ein Slot offen ist ---
let heartbeatTimer = null;

function startHeartbeat(slotId) {
    stopHeartbeat();
    heartbeatTimer = setInterval(async () => {
        try {
            const fd = new FormData();
            fd.append('action', 'heartbeat');
            fd.append('slot_id', slotId);
            const r = await fetch('ajax_lock.php', { method: 'POST', body: fd });
            const res = await r.json();
            // Falls der eigene Lock verloren ging und ein anderer übernommen hat:
            // Bearbeitung abbrechen, um Überschreiben zu verhindern.
            if (res.status === 'locked') {
                stopHeartbeat();
                const warning = document.getElementById('lockWarningText');
                if (warning) warning.innerHTML =
                    `Die Bearbeitung wurde beendet, weil <b>${res.user}</b> diesen Termin nun bearbeitet.`;
                closeAllModals();
                document.getElementById('lockWarningModal').style.display = 'block';
            }
        } catch (e) {}
    }, 10000); // alle 10s, Lock lebt 30s -> übersteht auch mal einen Aussetzer
}

function stopHeartbeat() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
}

function unlockCurrentSlot() {
    stopHeartbeat();
    const myLock = document.querySelector('.is-my-lock');
    if (myLock) {
        const formData = new FormData();
        formData.append('action', 'unlock');
        formData.append('slot_id', myLock.id);
        // sendBeacon ist zuverlässiger beim Schließen/Verlassen der Seite
        try {
            if (navigator.sendBeacon) {
                const fd = new FormData();
                fd.append('action', 'unlock');
                fd.append('slot_id', myLock.id);
                navigator.sendBeacon('ajax_lock.php', fd);
            } else {
                fetch('ajax_lock.php', { method: 'POST', body: formData, keepalive: true });
            }
        } catch (e) {
            fetch('ajax_lock.php', { method: 'POST', body: formData });
        }
        myLock.classList.remove('is-my-lock');
    }
}

// Lock zuverlässig freigeben, wenn der Tab/Browser geschlossen wird
window.addEventListener('pagehide', unlockCurrentSlot);
window.addEventListener('beforeunload', unlockCurrentSlot);

// --- Drag & Drop ---
function handleDragStart(e, el) { 
    if (isViewer) { e.preventDefault(); return; }
    e.dataTransfer.setData("termin_id", el.getAttribute('data-id')); 
}
function handleDragOver(e) { e.preventDefault(); e.currentTarget.classList.add('drag-over'); }
function handleDrop(e, el) { 
    if (isViewer) return;
    e.preventDefault(); 
    el.classList.remove('drag-over'); 
    const id = e.dataTransfer.getData("termin_id"); 
    if (id) { 
        fetch('move_appointment.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: new URLSearchParams({ 'id': id, 'datum': el.getAttribute('data-datum'), 'uhrzeit': el.getAttribute('data-uhrzeit'), 'tech': el.getAttribute('data-tech'), 'csrf_token': csrfToken }) 
        }).then(() => { sessionStorage.setItem('scrollPosition', window.scrollY); location.reload(); });
    } 
}

// --- CHAT FUNKTIONEN ---
let lastMsgCount = -1;

function scrollToBottom() {
    const chatBox = document.getElementById('chat-box');
    if (!chatBox) return;
    setTimeout(() => {
        chatBox.scrollTop = chatBox.scrollHeight;
    }, 50);
}

function toggleChat() {
    const container = document.getElementById('chat-container');
    if (!container) return;
    const isVisible = (container.style.display === 'block');
    container.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) fetchChat();
}

function fetchChat() {
    fetch('chat_handler.php?action=fetch')
        .then(r => r.text())
        .then(html => {
            const chatBox = document.getElementById('chat-box');
            if (chatBox) {
                chatBox.innerHTML = html;
                scrollToBottom();
            }
        });
}

function sendChat() {
    const input = document.getElementById('chat-input');
    const container = document.getElementById('chat-container');
    if (!input || input.value.trim() === "") return;

    const formData = new FormData();
    formData.append('msg', input.value);

    fetch('chat_handler.php?action=send', { method: 'POST', body: formData })
        .then(() => {
            input.value = ""; 
            if (container.style.display !== 'block') container.style.display = 'block';
            fetchChat(); 
        });
}

setInterval(() => {
    const container = document.getElementById('chat-container');
    if (!container) return;

    fetch('chat_handler.php?action=fetch')
        .then(r => r.text())
        .then(html => {
            const chatBox = document.getElementById('chat-box');
            if (!chatBox) return;

            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const currentMsgCount = tempDiv.querySelectorAll('.chat-msg').length;

            if (lastMsgCount === -1) {
                lastMsgCount = currentMsgCount;
                chatBox.innerHTML = html;
                return;
            }

            if (currentMsgCount > lastMsgCount) {
                chatBox.innerHTML = html;
                lastMsgCount = currentMsgCount;
                if (window.getComputedStyle(container).display === 'none') {
                    container.style.display = 'block';
                }
                scrollToBottom();
            }
        });
}, 10000);

// --- SUCH-FUNKTION ---
function performSearch(p = null) {
    const query = document.getElementById('planSearchInput').value;
    const year = document.getElementById('searchYearSelect').value;
    if (!query || query.trim() === "") return;

    // Ohne page-Parameter berechnet das Backend automatisch die Seite, auf der 'heute' liegt
    const pageParam = (p === null) ? '' : `&page=${encodeURIComponent(p)}`;
    fetch(`search_ajax.php?q=${encodeURIComponent(query)}&y=${encodeURIComponent(year)}${pageParam}`)
        .then(response => response.text())
        .then(html => {
            const content = document.getElementById('searchResultContent');
            if (content) {
                content.innerHTML = html;
                document.getElementById('searchResultModal').style.display = 'block';
            }
        });
}



// Heartbeat: Alle 5 Minuten den Server anpingen, um den Logout zu verhindern
setInterval(function() {
    fetch('ping.php').then(response => {
        console.log('Session aktiv gehalten...');
    }).catch(err => console.error('Heartbeat fehlgeschlagen'));
}, 300000); // 300000 ms = 5 Minuten

// (move_appointment wird nur per handleDrop aufgerufen)
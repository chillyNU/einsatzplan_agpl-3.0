// ─────────────────────────────────────────────────────────────────────────────
// Service Worker für die mobilen Einsatzplan-Seiten (PWA).
//
// WICHTIG: Bei jedem Release die VERSION hochzählen (z.B. '1.0.0' -> '1.0.1').
// Nur eine byteweise geänderte sw.js löst beim Browser den Update-Vorgang aus.
// Die Seite zeigt dann das Banner "Neue Version verfügbar"; erst auf Knopfdruck
// übernimmt die neue Version (kein Auto-skipWaiting -> kein Bruch mitten drin).
// ─────────────────────────────────────────────────────────────────────────────

const VERSION = '1.0.0';
const SHELL_CACHE  = 'einsatzplan-shell-' + VERSION;
const VENDOR_CACHE = 'einsatzplan-vendor-' + VERSION;

// App-Shell: Grundausstattung fürs Offline-Laden (Network-First).
const SHELL = [
  './',
  './index.php',
  '../theme.css',
];

// Große/unveränderliche Dateien: einmal laden, dann aus dem Cache (Cache-First).
const VENDOR_PATTERNS = [/\/icons\//, /icon\.php/, /theme\.css/];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((c) => c.addAll(SHELL)).catch(() => {})
  );
  // KEIN self.skipWaiting() – die Seite entscheidet per Banner!
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys.filter((k) => k !== SHELL_CACHE && k !== VENDOR_CACHE)
          .map((k) => caches.delete(k))
    );
    await self.clients.claim();
  })());
});

// Der Knopf im Banner schickt diese Nachricht -> neue Version übernimmt sofort.
self.addEventListener('message', (event) => {
  if (event.data && event.data.cmd === 'skipWaiting') self.skipWaiting();
});

function istVendor(url) {
  return VENDOR_PATTERNS.some((re) => re.test(url.pathname + url.search));
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;   // Fremd-Domains nicht anfassen
  if (event.request.method !== 'GET') return;
  // Dynamische Prüf-Endpunkte (Update-Erkennung) nie cachen
  if (url.search.includes('check=1')) return;

  // Cache-First für große, unveränderliche Dateien
  if (istVendor(url)) {
    event.respondWith((async () => {
      const cached = await caches.match(event.request);
      if (cached) return cached;
      try {
        const res = await fetch(event.request);
        if (res && res.ok) {
          const cache = await caches.open(VENDOR_CACHE);
          cache.put(event.request, res.clone());
        }
        return res;
      } catch (err) {
        if (cached) return cached;
        throw err;
      }
    })());
    return;
  }

  // Network-First für die App-Shell (immer aktueller Stand, Cache als Fallback)
  event.respondWith((async () => {
    try {
      const res = await fetch(event.request);
      if (res && res.ok) {
        const cache = await caches.open(SHELL_CACHE);
        cache.put(event.request, res.clone());
      }
      return res;
    } catch (err) {
      const cached = await caches.match(event.request);
      if (cached) return cached;
      throw err;
    }
  })());
});

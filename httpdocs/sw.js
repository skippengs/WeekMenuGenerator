/*
 * Service worker voor Weekmenu.
 *
 * Doel is bescheiden: de app moet openen en het laatste weekmenu laten
 * zien als je in de supermarkt even geen bereik hebt.
 *
 * Verhoog CACHE bij een nieuwe versie van de css of js, anders blijven
 * mensen de oude bestanden houden.
 */

const CACHE = 'weekmenu-v12.1';

const SHELL = [
    'assets/app.css',
    'assets/app.js',
    'assets/icon-192.png',
    'assets/icon-512.png',
    'manifest.json'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            // Faalt er één bestand, dan hoeft de hele installatie niet te stranden.
            .then((cache) => Promise.allSettled(SHELL.map((url) => cache.add(url))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Alleen gewone GET-verkeer. Genereren en opnieuw gooien zijn POSTs
    // die echt naar de server moeten.
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Api-antwoorden en de back-up (met wachtwoord-hashes) nooit bewaren.
    if (url.pathname.includes('/api/') || url.pathname.endsWith('backup.php')) {
        return;
    }

    // Het menu zelf: eerst het net, want een verse week is het punt.
    // Lukt dat niet, dan de laatst opgehaalde versie.
    if (req.mode === 'navigate' || url.pathname.endsWith('.php')) {
        event.respondWith(
            fetch(req)
                .then((res) => {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy));
                    return res;
                })
                .catch(() => caches.match(req).then((hit) => hit || caches.match('index.php')))
        );
        return;
    }

    // Plaatjes, css en js: meteen uit de cache, maar op de achtergrond
    // wel verversen. Anders blijf je na een wijziging op de oude css en
    // js hangen tot iemand eraan denkt de cachenaam te verhogen.
    event.respondWith(
        caches.match(req).then((hit) => {
            const fresh = fetch(req)
                .then((res) => {
                    if (res && res.ok) {
                        const copy = res.clone();
                        caches.open(CACHE).then((c) => c.put(req, copy));
                    }
                    return res;
                })
                .catch(() => hit);

            return hit || fresh;
        })
    );
});

/* ---------- pushmeldingen ---------- */

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Weekmenu', {
            body: data.body || '',
            icon: 'assets/icon-192.png',
            badge: 'assets/icon-192.png',
            data: { url: data.url || 'index.php' }
        })
    );
});

// Tik op de melding: een open venster van de app hergebruiken, anders een nieuw.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data.url, self.registration.scope).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const client of list) {
                if ('navigate' in client) {
                    return client.focus().then(() => client.navigate(target));
                }
            }
            return self.clients.openWindow(target);
        })
    );
});

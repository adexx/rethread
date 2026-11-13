// Rethread service worker: makes the site installable, shows an offline page, caches static files
// and photos, and displays push notifications. Pages themselves are never cached (they hold
// personal data and must always be fresh).
const VERSION = 'rt-v1';
const STATIC = 'static-' + VERSION;
const PHOTOS = 'photos-' + VERSION;
const scoped = (p) => new URL(p, self.registration.scope).href;
const PRECACHE = ['offline.html', 'assets/style.css?v=5', 'assets/app.js?v=5', 'assets/icons/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE.map(scoped))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k !== STATIC && k !== PHOTOS).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

async function trimPhotos() {
  const c = await caches.open(PHOTOS);
  const keys = await c.keys();
  for (let i = 0; i < keys.length - 300; i++) await c.delete(keys[i]);   // keep the newest 300
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(scoped('offline.html'))));
    return;
  }
  if (url.origin !== location.origin) return;
  // Photos and versioned assets never change at the same URL: serve from cache, fill on first use.
  if (/\/(uploads|assets)\//.test(url.pathname)) {
    const bucket = url.pathname.includes('/uploads/') ? PHOTOS : STATIC;
    e.respondWith(caches.open(bucket).then(async (c) => {
      const hit = await c.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) { c.put(req, res.clone()); if (bucket === PHOTOS) trimPhotos(); }
      return res;
    }));
  }
});

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data && e.data.text() }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Rethread', {
    body: d.body || '',
    icon: scoped('assets/icons/icon-192.png'),
    badge: scoped('assets/icons/icon-192.png'),
    data: { url: d.url || scoped('inbox.php') },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = e.notification.data && e.notification.data.url ? e.notification.data.url : scoped('');
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const w of list) if (w.url === target && 'focus' in w) return w.focus();
    return self.clients.openWindow(target);
  }));
});

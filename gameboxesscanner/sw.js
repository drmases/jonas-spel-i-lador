const CACHE = 'gameboxesscanner-v22';
const ASSETS = [
  '/gameboxesscanner/',
  '/gameboxesscanner/index.html',
  '/gameboxesscanner/games.js',
  '/gameboxesscanner/manifest.json',
  'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
];

// Cache entries one at a time: addAll rejects the whole install if a single
// URL fails, which would leave the app with no service worker at all.
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => Promise.all(ASSETS.map(u => c.add(u).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network first: always take a fresh copy when online, fall back to cache
// offline. Cache-first meant deployed fixes could sit unseen for days.
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    fetch(e.request).then(res => {
      const copy = res.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
      return res;
    }).catch(() => caches.match(e.request))
  );
});

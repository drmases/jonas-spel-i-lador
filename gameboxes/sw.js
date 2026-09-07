const CACHE = 'gameboxes-v1';
const ASSETS = [
  '/gameboxes/',
  '/gameboxes/index.html',
  '/gameboxes/games.js',
  '/gameboxes/manifest.json',
  'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request))
  );
});

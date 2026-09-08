// This service worker intentionally unregisters itself
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', () => {
  self.registration.unregister();
  caches.keys().then(keys => keys.forEach(k => caches.delete(k)));
});

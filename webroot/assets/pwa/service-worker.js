const CACHE_NAME = 'formr-v1';
const ASSETS_TO_CACHE = [
  '/',
  '/assets/pwa/manifest.json',
  '/favicon.ico',
  '/assets/build/css/formr.min.css',
  '/assets/build/js/formr.min.js',
  '/assets/pwa/maskable_icon_x48.png',
  '/assets/pwa/maskable_icon_x72.png',
  '/assets/pwa/maskable_icon_x96.png',
  '/assets/pwa/maskable_icon_x128.png',
  '/assets/pwa/maskable_icon_x192.png',
  '/assets/pwa/maskable_icon_x384.png',
  '/assets/pwa/maskable_icon_x512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        return cache.addAll(ASSETS_TO_CACHE);
      })
  );
});

self.addEventListener('fetch', (event) => {
  event.respondWith(
    caches.match(event.request)
      .then((response) => {
        if (response) {
          return response;
        }
        return fetch(event.request)
          .then((response) => {
            if (!response || response.status !== 200 || response.type !== 'basic') {
              return response;
            }
            const responseToCache = response.clone();
            caches.open(CACHE_NAME)
              .then((cache) => {
                cache.put(event.request, responseToCache);
              });
            return response;
          });
      })
  );
}); 
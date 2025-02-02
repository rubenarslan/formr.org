const CACHE_NAME = 'formr-v1';
const ASSETS_TO_CACHE = [
  '/assets/build/css/formr.min.css',
  '/assets/build/js/formr.min.js'
];

// Add message event listener to handle asset caching
self.addEventListener('message', (event) => {
  if (event.data.type === 'CACHE_ASSETS') {
    event.waitUntil(
      caches.open(CACHE_NAME)
        .then((cache) => {
          const assets = [...new Set([...ASSETS_TO_CACHE, ...event.data.assets])];
          return cache.addAll(assets);
        })
        .catch(error => {
          console.error('Error caching page assets:', error);
        })
    );
  }
});

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
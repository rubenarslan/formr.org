const CACHE_NAME = 'formr-v1';
const ASSETS_TO_CACHE = [
  '/assets/build/css/formr.min.css',
  '/assets/build/js/formr.min.js'
];

let manifestData = null;

// Add message event listener to handle asset caching and manifest path
self.addEventListener('message', (event) => {
  if (event.data.type === 'CACHE_ASSETS') {
    // Add manifest to assets to cache
    const assetsWithManifest = [...new Set([...ASSETS_TO_CACHE, ...event.data.assets, event.data.manifestPath])];
    
    event.waitUntil(
      Promise.all([
        // Cache assets
        caches.open(CACHE_NAME).then((cache) => {
          return cache.addAll(assetsWithManifest);
        }),
        // Fetch and store manifest data
        fetch(event.data.manifestPath)
          .then(response => response.json())
          .then(manifest => {
            manifestData = manifest;
          })
      ]).catch(error => {
        console.error('Error during initialization:', error);
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

// Helper function to get the best icon from manifest
function getBestIcon(purpose = 'any') {
  if (!manifestData || !manifestData.icons) {
    return '/assets/pwa/maskable_icon_x192.png'; // fallback
  }

  // Filter icons by purpose
  const icons = manifestData.icons.filter(icon => {
    if (!icon.purpose) return purpose === 'any';
    return icon.purpose.split(' ').includes(purpose);
  });

  if (icons.length === 0) {
    return '/assets/pwa/maskable_icon_x192.png'; // fallback
  }

  // Sort by size (descending) and get the largest
  const sortedIcons = icons.sort((a, b) => {
    const sizeA = parseInt(a.sizes.split('x')[0]);
    const sizeB = parseInt(b.sizes.split('x')[0]);
    return sizeB - sizeA;
  });

  return sortedIcons[0].src;
}

// Handle incoming push events
self.addEventListener('push', (event) => {
  if (!event.data) {
    console.log('Push event received but no data');
    return;
  }

  try {
    const data = event.data.json();
    const options = {
      body: data.body || '',
      icon: data.icon || getBestIcon('any'),
      badge: getBestIcon('badge') || getBestIcon('maskable') || getBestIcon('any'),
      vibrate: [100, 50, 100],
      data: {
        dateOfArrival: Date.now(),
        primaryKey: 1,
        clickTarget: data.clickTarget || '/'
      },
      actions: data.actions || []
    };

    event.waitUntil(
      self.registration.showNotification(data.title || 'Notification', options)
    );
  } catch (error) {
    console.error('Error showing notification:', error);
  }
});

// Handle notification clicks
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  // Get the click target URL from the notification data
  const clickTarget = event.notification.data.clickTarget || '/';

  // This ensures the browser doesn't kill the service worker before the page is opened
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((clientList) => {
        // If we have a matching window, focus it
        for (const client of clientList) {
          if (client.url === clickTarget && 'focus' in client) {
            return client.focus();
          }
        }
        // If no matching window, open new one
        if (clients.openWindow) {
          return clients.openWindow(clickTarget);
        }
      })
  );
}); 
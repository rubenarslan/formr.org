const CACHE_NAME = 'formr-' + self.location.hostname + self.location.pathname.split('/').slice(0, -1).join('-');
const ASSETS_TO_CACHE = [
  '/assets/build/css/formr.min.css',
  '/assets/build/js/formr.min.js'
];

let manifestData = null;
console.log("SW ACTIVATED");

// Helper function to validate URLs
function isValidUrl(url) {
  try {
    const urlObj = new URL(url);
    
    // Check if this is a manifest URL
    if (url.includes('manifest') && url.endsWith('.json')) {
      // Allow manifest URLs from our origin or HTTPS
      return (urlObj.protocol === 'https:' || urlObj.origin === self.location.origin);
    }
    
    // For other assets, only allow URLs from our origin or HTTPS URLs, and only paths containing /assets/
    return (urlObj.protocol === 'https:' || urlObj.origin === self.location.origin) 
           && urlObj.pathname.includes('/assets/');
  } catch {
    return false;
  }
}

async function fetchManifest(url) {
  const response = await fetch(url);
  const manifest = await response.json();
  const icons = manifest.icons;

  console.log('Start URL:', manifest.start_url);
  console.log('Icons:', icons);

  // Example: caching assets from manifest
  const cache = await caches.open(CACHE_NAME);
  const assets = [...new Set([url, ...icons.map(icon => icon.src)])];

  await cache.addAll(assets);

  return manifest;
}

// Add message event listener to handle asset caching and manifest path
self.addEventListener('message', (event) => {
  // Handle SET_MANIFEST_PATH message
  if (event.data.type === 'SET_MANIFEST_PATH') {
    console.log('Service worker received manifest path:', event.data.manifestPath);
    
    // Use a Promise chain to properly handle the async fetchManifest
    fetchManifest(event.data.manifestPath)
    .then(manifest => {
      manifestData = manifest;
      console.log('Manifest data loaded successfully:', manifestData);
    })
    .catch(error => {
        console.error('Error loading manifest:', error);
      });
  }
  
  // Handle CACHE_ASSETS message
  if (event.data.type === 'CACHE_ASSETS') {
    // Filter and deduplicate assets
    const validAssets = [...new Set(
      [
        ...ASSETS_TO_CACHE,
        ...event.data.assets.filter(isValidUrl)
      ]
    )].filter(isValidUrl);
    
    console.log('Service worker received assets to cache:', validAssets);
    
    // Return the Promise chain directly instead of using event.waitUntil
    event.ports[0]?.postMessage(
      caches.open(CACHE_NAME).then((cache) => {
        console.log("SW: Caching assets:", validAssets);
        return cache.addAll(validAssets);
      }).catch(error => {
        console.error('Error caching assets:', error);
      })
    );
  }
});

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        // Filter ASSETS_TO_CACHE through isValidUrl as well
        const validAssets = ASSETS_TO_CACHE.filter(isValidUrl);
        return cache.addAll(validAssets);
      })
  );
});

self.addEventListener('fetch', (event) => {
  // Only handle GET requests
  if (event.request.method !== 'GET') {
    return;
  }

  // Only handle valid URLs (which now must include /assets/)
  if (!isValidUrl(event.request.url)) {
    return fetch(event.request);  // Don't cache, just fetch normally
  }

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
            
            // URL is already validated by isValidUrl above
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

// Add a helper function for badge management at the top of the file
/**
 * Manages the app badge using the Badging API if available
 * @param {number|null} count - The badge count to set, or null to clear
 * @returns {Promise<void>}
 */
async function manageBadge(count) {
  if (!('setAppBadge' in navigator)) {
    console.log('Badging API not supported in this environment');
    return;
  }

  try {
    if (count && count > 0) {
      await navigator.setAppBadge(count);
    } else {
      await navigator.clearAppBadge();
    }
  } catch (error) {
    console.error('Error managing badge:', error);
  }
}

// Add a function to check and close expired notifications
async function checkAndCloseExpiredNotifications() {
  return;
  try {
    const notifications = await self.registration.getNotifications();
    const now = Date.now();
    let expiredCount = 0;
    
    for (const notification of notifications) {
      // Check if notification has timestamp (TTL) data
      if (notification.data && notification.data.timestamp) {
        if (now >= notification.data.timestamp) {
          notification.close();
          expiredCount++;
          
          // If the expired notification had a badge count, we need to update the badge
          if (notification.data.badgeCount !== undefined) {
            // Get all remaining notifications to recalculate badge count
            const remainingNotifications = await self.registration.getNotifications();
            const totalBadgeCount = remainingNotifications.reduce((count, n) => {
              return count + (n.data?.badgeCount || 0);
            }, 0);
            
            // Update the badge count
            await manageBadge(totalBadgeCount);
          }
        }
      }
    }
    
    if (expiredCount > 0) {
      console.log(`Closed ${expiredCount} expired notification(s)`);
    }
  } catch (error) {
    console.error('Error checking expired notifications:', error);
  }
}

// Add activation event listener to check for expired notifications
self.addEventListener('activate', (event) => {
  event.waitUntil(checkAndCloseExpiredNotifications());
});

// Handle incoming push events
self.addEventListener('push', event => {
  event.waitUntil((async () => {
    if (!event.data) {
      console.warn('Push event received without data');
      return;
    }

    try {
      const data = event.data.json();
      console.log('Push notification data:', data);

      const timestamp = Date.now();
      const tag = data.tag || `notification-${timestamp}`;

      const options = {
        body: data.body || '',
        icon: data.icon ? new URL(data.icon, self.location.origin).href : getBestIcon('any'),
        badge: data.badge ? new URL(data.badge, self.location.origin).href : getBestIcon('badge'),
        tag,
        urgency: data.priority || 'normal',
        vibrate: data.vibrate === false ? undefined : [100, 50, 100],
        requireInteraction: !!data.requireInteraction,
        renotify: !!data.renotify,
        silent: !!data.silent,
        data: {
          dateOfArrival: timestamp,
          clickTarget: data.clickTarget || manifestData?.start_url,
          badgeCount: Number.isInteger(data.badgeCount) ? data.badgeCount : undefined,
          timestamp: Number.isInteger(data.timeToLive) ? timestamp + data.timeToLive * 1000 : undefined
        }
      };

      console.log('Notification options:', options);

      await self.registration.showNotification(data.title || 'Notification', options);
      console.log('Notification displayed');

      await manageBadge(options.data.badgeCount);
      console.log('Badge updated');
      
    } catch (error) {
      console.error('Push notification error:', error);

      // Safe fallback notification
      await self.registration.showNotification('New Message', {
        body: 'You have a new notification.',
        icon: getBestIcon('any'),
        tag: 'fallback-notification'
      });
    }
  })());
});

self.addEventListener('notificationclick', (event) => {
  console.log("SW: Notification clicked:", event);

  /*
  if(event.notification.tag == "test-notification") {
    console.log("SW: Test notification clicked");
    event.notification.close();
    return;
  }
  */
  
  console.log("SW: Manifest data:", manifestData);
  console.log("SW: Self location:", self.location);
  
  // Get the target URL, with multiple fallbacks
  const targetUrl = event.notification.data?.clickTarget || manifestData?.start_url || self.location.href.replace('service-worker', '');
  if(targetUrl === undefined) {
    console.error("SW: No target URL found");
    return;
  }
  console.log('Notification clicked with target:', targetUrl);
  
  event.waitUntil((async () => {
    try {
      const allClients = await clients.matchAll({ 
        type: 'window', 
        includeUncontrolled: true 
      });
      
      console.log('Found window clients:', allClients.length);
    

      const normalizedTargetUrl = new URL(targetUrl, self.location.origin).href;
      console.log('Normalized URL:', normalizedTargetUrl);


      console.log("SW: All clients:", allClients);
      // First, try finding an exact match and focus it.
      for (const client of allClients) {
        console.log('Checking client URL:', client.url);
        if (client.url === normalizedTargetUrl && 'focus' in client) {
          console.log('Exact match found, focusing');
           // Send a message to the client to reload the page
          client.postMessage({
            type: 'NOTIFICATION_CLICK',
            action: 'reload'
          });
          return client.focus();
        }
      }

      // If no exact match, navigate the first client within scope.
      if (allClients.length > 0) {
        const client = allClients[0];
        console.log('No exact match, trying to navigate first client');
        // Send a message to the client to reload the page
        client.postMessage({
          type: 'NOTIFICATION_CLICK',
          action: 'reload'
        });
        if ('navigate' in client) {
//          await client.navigate(normalizedTargetUrl);
          return client.focus();
        }
      }

      // If no clients at all, open a new window.
      console.log('No clients found, opening new window');
      return clients.openWindow(normalizedTargetUrl);
    } catch (error) {
      console.error('Error handling notification click:', error);
    }
  })());
});
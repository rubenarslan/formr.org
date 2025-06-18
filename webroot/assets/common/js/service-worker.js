const sw_version = 'v6';
const CACHE_NAME = 'formr-' + sw_version + '-' + self.location.hostname + self.location.pathname.split('/').slice(0, -1).join('-');

console.log("SW loaded", CACHE_NAME);

// Helper function to validate URLs
function isValidUrl(url) {
  try {
    const urlObj = new URL(url);
    
    // For other assets, only allow URLs from our origin or HTTPS URLs, and only paths containing /assets/
    return (urlObj.protocol === 'https:' || urlObj.origin === self.location.origin) 
           && (urlObj.pathname.includes('/assets/') || urlObj.pathname.includes('/manifest'));
  } catch {
    return false;
  }
}

/* 
 * Fetch the manifest file and cache the assets
 */
let manifestData = null;
async function fetchManifest() {
  if (!manifestData) {
    try {
      const url = self.location.href.replace(/\/service-worker$/, '/manifest');
      console.log("SW: Fetching manifest from", url);
      
      const response = await fetch(url);
      if (!response.ok) throw new Error('Failed to fetch manifest.');
      
      manifestData = await response.json();
    } catch (err) {
      manifestData = null;
      console.error('Error fetching manifest:', err);
      throw err;
    }
  }
  return manifestData;
}

// Async helper function to get best icon from manifest
async function getBestIcon(purpose = 'any') {
  try {
    const manifestData = await fetchManifest();

    if (!manifestData.icons) {
      return '/assets/pwa/icon.png'; // fallback
    }

    // Filter icons by purpose
    const icons = manifestData.icons.filter(icon => {
      if (!icon.purpose) return purpose === 'any';
      return icon.purpose.split(' ').includes(purpose);
    });

    if (icons.length === 0) {
      return '/assets/pwa/icon.png'; // fallback
    }

    // Sort by size (descending) to get the largest icon
    icons.sort((a, b) => {
      const sizeA = parseInt(a.sizes.split('x')[0], 10);
      const sizeB = parseInt(b.sizes.split('x')[0], 10);
      return sizeB - sizeA;
    });

    return icons[0].src;

  } catch (error) {
    // Fallback on fetch failure
    return '/assets/pwa/icon.png';
  }
}

// Helper function for badge management 
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

// Helper function to find and sort clients, prioritizing PWA clients
async function findAndSortClients() {
  const clientsArr = await clients.matchAll({ type: 'window', includeUncontrolled: true });
  return clientsArr.sort((a, b) => b.url.includes('_pwa=true') - a.url.includes('_pwa=true'));
}

// Add a function to check and close expired notifications
async function checkAndCloseExpiredNotifications() {
  try {
    const notifications = await self.registration.getNotifications();
    const now = Date.now();

    const expired = notifications.filter(n => n.data?.expires && now >= n.data.expires);
    expired.forEach(n => n.close());

    // If all notifications are expired, clear the badge
    if (expired.length == notifications.length) {
      await manageBadge(null);
      console.log(`SW: All ${expired.length} notifications expired, badge cleared`);
    }
  } catch (error) {
    console.error('Error checking expired notifications:', error);
  }
}

/* 
 * Service worker event listeners
 */

/* 
 * Install event listener to cache the manifest and assets
 */
self.addEventListener('install', (event) => {
  console.log('SW: Starting install');
  const pre_cache = async () => {
    try {
      const manifest = await fetchManifest();
      const assetsToCache = [...new Set([manifest.start_url, ...manifest.icons.map(icon => icon.src)])];
      const cache = await caches.open(CACHE_NAME);
      console.log('SW: Caching assets:', assetsToCache);
      return await cache.addAll(assetsToCache);
    } catch (error) {
      console.error('Install failed:', error);
      throw error;
    }
  };
  event.waitUntil(pre_cache());
  console.log('SW: Install complete');
});


// Add activation event listener to check for expired notifications
self.addEventListener('activate', event => {
  console.log('SW: Starting activation');
  const activate = async () => {
    try {
      await clients.claim();
      await checkAndCloseExpiredNotifications();
      console.log("SW: Activation complete, clients claimed");
    } catch (error) {
      console.error("Error during activation:", error);
      throw error;
    }
  };
  event.waitUntil(activate());
  console.log('SW: Activation complete');
});

/* 
 * Fetch event listener to cache assets
 */
self.addEventListener('fetch', (event) => {
  // Only handle GET requests
  if (event.request.method !== 'GET') {
    return;
  }

  // Only handle valid URLs (which now must include /assets/)
  if (!isValidUrl(event.request.url)) {
    return fetch(event.request);  // Don't cache, just fetch normally
  }

  event.respondWith((async () => {
    try {
      // Check cache first
      const cachedResponse = await caches.match(event.request);
      if (cachedResponse) {
        return cachedResponse;
      }

      // Fetch from network
      const networkResponse = await fetch(event.request);
      
      // Validate response before caching
      if (!networkResponse || 
          networkResponse.status !== 200 || 
          networkResponse.type !== 'basic') {
        return networkResponse;
      }

      // Clone response for caching
      const responseToCache = networkResponse.clone();
      
      // Update cache in background
      const cache = await caches.open(CACHE_NAME);
      await cache.put(event.request, responseToCache);

      return networkResponse;

    } catch (error) {
      console.error('Fetch handler error:', error);
      throw error; // Let event.respondWith handle the rejection
    }
  })());
});


// Add message event listener to handle asset caching
self.addEventListener('message', (event) => {
  // Handle CACHE_ASSETS message
  if (event.data.type === 'CACHE_ASSETS') {
    // Filter and deduplicate assets
    const validAssets = [...new Set(
      [
        ...event.data.assets.filter(isValidUrl)
      ]
    )].filter(isValidUrl);
    
    console.log('Service worker received assets to cache:', validAssets);
    
    // Return the Promise chain directly instead of using event.waitUntil
    event.ports[0]?.postMessage(
      (async () => {
        try {
          const cache = await caches.open(CACHE_NAME);
          console.log("SW: Caching assets:", validAssets);
          return await cache.addAll(validAssets);
        } catch (error) {
          console.error('Error caching assets:', error);
          throw error; // Propagate to caller
        }
      })()
    );
  }
});

// Handle incoming push events
self.addEventListener('push', event => {
    if (!event.data) {
      console.warn('Push event received without data');
      return;
    }
    
    event.waitUntil((async () => {
    try {
      const best_icon_any = await getBestIcon('any').catch(() => '/assets/pwa/icon.png');

      const data = event.data.json();
      console.log('Push notification data:', data);

      const timestamp = Date.now();
      const tag = data.tag || `notification-${timestamp}`;
      
      const options = {
        body: data.body || '',
        icon: data.icon ? new URL(data.icon, self.location.origin).href : best_icon_any,
        badge: data.badge || 1,
        tag,
        urgency: data.priority || 'normal',
        vibrate: data.vibrate === false ? undefined : [100, 50, 100],
        requireInteraction: !!data.requireInteraction,
        renotify: !!data.renotify,
        silent: !!data.silent,
        data: {
          clickTarget: data.clickTarget || self.location.href.replace(/\/service-worker$/, '/'),
          badgeCount: Number.isInteger(data.badgeCount) ? data.badgeCount : undefined,
          timestamp: timestamp,
          expires: Number.isInteger(data.timeToLive) ? timestamp + data.timeToLive * 1000 : undefined
        }
      };

      // First show the notification and wait for it to complete
      console.log('SW: Start notification display');
      self.registration.showNotification(data.title || 'Notification', options).then(async () => {
        console.log('Notification displayed');
        
        // Notify all clients about state invalidation
        const allClients = await findAndSortClients();
        for (const client of allClients) {
          try {
            await client.postMessage({
              type: 'STATE_INVALIDATED',
              tag,
              timestamp
            });
            console.log(`Successfully sent STATE_INVALIDATED message to client ${client.id} ${client.url}`);
          } catch (error) {
            console.error(`Failed to send STATE_INVALIDATED message to client ${client.id} ${client.url}:`, error);
          }
        }

        console.log('Finished sending STATE_INVALIDATED messages to all clients');

        await manageBadge(options.data.badgeCount);
        console.log('Badge updated');
        
      });
    } catch (error) {
      console.error('Push notification error:', error);
      // Ensure we at least show a basic notification
      self.registration.showNotification('New Message', {
        body: 'You have a new notification.',
        icon: '/assets/pwa/icon.png',
        tag: 'fallback-notification'
      });
    }
  })());
});

/* 
 * Notification click event listener
 */
self.addEventListener('notificationclick', (event) => {
  console.log("SW: Notification clicked:", event);
  event.notification.close();
  
  console.log("SW: Self location:", self.location);
  
  // Get the target URL, with multiple fallbacks
  const targetUrl = event.notification.data?.clickTarget || self.location.href.replace(/\/service-worker$/, '/');
  if(targetUrl === undefined) {
    console.error("SW: No target URL found");
    return;
  }
  console.log('Notification clicked with target:', targetUrl);
  
  event.waitUntil((async () => {
    try {
      const allClients = await findAndSortClients();

      console.log('Found window clients:', allClients.length);
      console.log('Is there a PWA client?', allClients[0]?.url.includes('_pwa=true'));

      const normalizedTargetUrl = new URL(targetUrl, self.location.origin).href;
      console.log('Normalized URL:', normalizedTargetUrl);


      console.log("SW: All clients:", allClients);
      // Navigate/Focus the first client within scope.
      if (allClients.length > 0) {
        const client = allClients[0];
        console.log('Trying to navigate first client');
        // Send a message to the client to reload the page
        try {
          client.postMessage({
            type: 'NOTIFICATION_CLICK',
            action: 'reload',
            timestamp: Date.now()
          });
        } catch (error) {
          console.error('Failed to send message to client:', error);
        }
        /*
        if ('navigate' in client) {
          await client.navigate(normalizedTargetUrl);
        }
        */
        return await client.focus();
      }

      // If no clients at all, open a new window.
      console.log('No clients found, opening new window');
      return clients.openWindow(normalizedTargetUrl);
    } catch (error) {
      console.error('Notification click failed:', error);
      throw error;
    }
  })());
});
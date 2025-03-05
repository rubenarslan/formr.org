const CACHE_NAME = 'formr-' + self.location.hostname + self.location.pathname.split('/').slice(0, -1).join('-');
const ASSETS_TO_CACHE = [
  '/assets/build/css/formr.min.css',
  '/assets/build/js/formr.min.js'
];

let manifestData = null;

// Helper function to validate URLs
function isValidUrl(url) {
  try {
    const urlObj = new URL(url);
    // Only allow URLs from our origin or HTTPS URLs, and only paths containing /assets/
    return (urlObj.protocol === 'https:' || urlObj.origin === self.location.origin) 
           && urlObj.pathname.includes('/assets/');
  } catch {
    return false;
  }
}

// Add message event listener to handle asset caching and manifest path
self.addEventListener('message', (event) => {
  if (event.data.type === 'CACHE_ASSETS') {
    // Filter and deduplicate assets
    const validAssets = [...new Set([
      ...ASSETS_TO_CACHE,
      ...event.data.assets.filter(isValidUrl),
      event.data.manifestPath
    ])].filter(isValidUrl);
    
    // Return the Promise chain directly instead of using event.waitUntil
    event.ports[0]?.postMessage(
      Promise.all([
        // Cache assets
        caches.open(CACHE_NAME).then((cache) => {
          return cache.addAll(validAssets);
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
 * @param {string} action - 'set', 'clear', or 'decrement'
 * @returns {Promise<void>}
 */
async function manageBadge(count, action = 'set') {
  // Check if Badging API is supported
  if (!('setAppBadge' in navigator)) {
    console.log('Badging API not supported in this browser');
    return;
  }

  try {
    if (action === 'clear') {
      await navigator.clearAppBadge();
      console.log('App badge cleared');
    } else if (action === 'set' && count !== null && count !== undefined) {
      const numCount = parseInt(count, 10);
      if (numCount > 0) {
        await navigator.setAppBadge(numCount);
        console.log(`App badge set to ${numCount}`);
      } else if (numCount === 0) {
        await navigator.clearAppBadge();
        console.log('App badge cleared (count was 0)');
      }
    } else if (action === 'decrement' && count !== null && count !== undefined) {
      const numCount = parseInt(count, 10);
      if (numCount > 1) {
        await navigator.setAppBadge(numCount - 1);
        console.log(`App badge decremented to ${numCount - 1}`);
      } else {
        await navigator.clearAppBadge();
        console.log('App badge cleared (count was ≤ 1)');
      }
    }
  } catch (error) {
    console.error('Error managing app badge:', error);
  }
}

// Handle incoming push events
self.addEventListener('push', (event) => {
  if (!event.data) {
    console.log('Push event received but no data');
    return;
  }

  try {
    const data = event.data.json();
    console.log('Push notification data received:', JSON.stringify(data));
    
    // Generate unique tag if not provided
    const tag = data.tag || `notification-${Date.now()}`;
    const timestamp = Date.now();
    
    // Log the vibrate value specifically for debugging
    console.log('Vibrate value:', {
      raw: data.vibrate,
      type: typeof data.vibrate,
      willVibrate: !(data.vibrate === false || data.vibrate === 0)
    });
    
    // Log all notification options for debugging
    console.log('All notification options:', {
      requireInteraction: data.requireInteraction,
      renotify: data.renotify,
      silent: data.silent,
      timeToLive: data.timeToLive,
      badgeCount: data.badgeCount,
      topic: data.topic
    });
    
    // Update app badge if badgeCount is provided and Badging API is supported
    if (data.badgeCount !== undefined && data.badgeCount !== null) {
      manageBadge(data.badgeCount, 'set');
    }

    const options = {
      body: data.body || '',
      icon: data.icon || getBestIcon('any'),
      badge: getBestIcon('badge') || getBestIcon('maskable') || getBestIcon('any'),
      tag: tag,
      // Map priority to urgency
      urgency: data.priority || 'normal',
      // Check vibrate explicitly - handle both boolean false and falsy values like 0
      vibrate: data.vibrate === false || data.vibrate === 0 ? undefined : [100, 50, 100],
      // Add additional configurable options - use explicit checking for boolean values
      requireInteraction: data.requireInteraction === false ? false : (data.requireInteraction === true ? true : false),
      renotify: data.renotify === false ? false : (data.renotify === true ? true : false),
      silent: data.silent === false ? false : (data.silent === true ? true : false),
      data: {
        dateOfArrival: timestamp,
        primaryKey: 1,
        clickTarget: data.clickTarget || (manifestData?.start_url || '/'),
        topic: data.topic || undefined,
        tag: tag,
        // Move badgeCount here as custom data
        badgeCount: data.badgeCount !== undefined && data.badgeCount !== null ? parseInt(data.badgeCount, 10) : undefined
      },
      actions: data.actions || []
    };

    // Set time to live if provided (handle 0 as valid value)
    if (data.timeToLive !== undefined && data.timeToLive !== null) {
      options.timestamp = Date.now() + (data.timeToLive * 1000);
    }

    // Show notification and notify clients
    event.waitUntil(
      Promise.all([
        self.registration.showNotification(data.title || 'Notification', options),
        // Notify all clients about the new notification
        clients.matchAll({ type: 'window' }).then(windowClients => {
          windowClients.forEach(client => {
            client.postMessage({
              type: 'NEW_NOTIFICATION',
              tag: tag
            });
          });
        })
      ])
    );
  } catch (error) {
    console.error('Error showing notification:', error);
  }
});

// Handle notification click
self.addEventListener('notificationclick', (event) => {
  console.log('Notification clicked:', event.notification);
  
  // Get the custom data from the notification
  const data = event.notification.data || {};
  
  // Handle badge count with Badging API if available
  manageBadge(null, 'clear');

  // Close the notification
  event.notification.close();
  
  event.waitUntil(
    // Send reload message to all clients
    clients.matchAll({ type: 'window' }).then(windowClients => {
      windowClients.forEach(client => {
        client.postMessage({
          type: 'NOTIFICATION_CLICK',
          action: 'reload'
        });
      });

      // Focus or open window after sending reload message
      const targetUrl = data.clickTarget || self.registration.scope;
      
      // Try to find and focus existing window
      const matchingClient = windowClients.find(client => 
        client.url === targetUrl && 'focus' in client
      );
      
      if (matchingClient) {
        return matchingClient.focus();
      }
      
      // If no existing window, open new one
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
}); 
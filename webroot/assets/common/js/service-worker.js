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
 * @param {string} action - 'set', 'clear'
 * @returns {Promise<void>}
 */
async function manageBadge(count, action = 'set') {
  if (!('setAppBadge' in self.registration)) {
    console.log('Badging API not supported in service worker');
    return;
  }

  try {
    if (action === 'clear') {
      await self.registration.clearAppBadge();
    } else if (action === 'set' && count > 0) {
      await self.registration.setAppBadge(count);
    } else {
      await self.registration.clearAppBadge();
    }
  } catch (error) {
    console.error('Error managing badge:', error);
  }
}

// Add a function to check and close expired notifications
async function checkAndCloseExpiredNotifications() {
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
            if (totalBadgeCount > 0) {
              await manageBadge(totalBadgeCount, 'set');
            } else {
              await manageBadge(null, 'clear');
            }
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
self.addEventListener('push', (event) => {
  if (!event.data) {
    console.log('Push event received but no data');
    return;
  }

  const pushEventHandler = async () => {
    try {
      const data = event.data.json();
      console.log('Push notification data received:', JSON.stringify(data));
      
      // Generate unique tag if not provided
      const tag = data.tag || `notification-${Date.now()}`;
      const timestamp = Date.now();

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
          badgeCount: data.badgeCount !== undefined && data.badgeCount !== null ? parseInt(data.badgeCount, 10) : undefined,
          // Store expiry timestamp if timeToLive is provided
          timestamp: data.timeToLive !== undefined && data.timeToLive !== null ? Date.now() + (data.timeToLive * 1000) : undefined
        },
        actions: data.actions || []
      };

      // Set time to live if provided (handle 0 as valid value)
      if (data.timeToLive !== undefined && data.timeToLive !== null) {
        options.timestamp = Date.now() + (data.timeToLive * 1000);
      }

      // Show notification and notify clients
      await Promise.all([
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
      ]);

      // Check for expired notifications
      await checkAndCloseExpiredNotifications();

      // Update app badge if badgeCount is provided and Badging API is supported
      if (data.badgeCount !== undefined && data.badgeCount !== null) {
        await manageBadge(data.badgeCount, 'set');
      }
    } catch (error) {
      console.error('Error processing push notification:', error);
      // Ensure we show at least a basic notification even if processing fails
      await self.registration.showNotification('New Message', {
        body: 'A new message has arrived.',
        icon: getBestIcon('any')
      });
    }
  };

  event.waitUntil(pushEventHandler());
});

// Handle notification click
self.addEventListener('notificationclick', (event) => {
  const targetUrl = event.notification.data?.clickTarget || self.registration.scope;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      // Focus existing client matching targetUrl
      for (const client of windowClients) {
        if (client.url.includes(targetUrl) && 'navigate' in client) {
          return client.navigate(targetUrl).then((client) => client.focus());
        }
      }
      // No matching client; open new window
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});
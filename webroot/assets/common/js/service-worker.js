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

/**
 * cache.addAll() is atomic — if any single fetch returns non-2xx the
 * whole call rejects and the caller (install handler, asset-cache
 * postMessage) treats that as a fatal failure. For an install event
 * that means the SW transitions to "redundant" and never claims clients;
 * the participant ends up with no SW, no offline cache, no push.
 *
 * safeAddAll caches each URL independently and swallows individual
 * failures. We log the misses so we can find them in dev console but
 * the SW still installs cleanly. Mirrors the resilience posture of
 * Workbox's `addAll({ignoreSearchParams})` family.
 */
async function safeAddAll(cache, urls) {
  const tasks = urls.map(async (url) => {
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) return { url, ok: false, status: res.status };
      await cache.put(url, res);
      return { url, ok: true };
    } catch (err) {
      return { url, ok: false, error: String(err && err.message || err) };
    }
  });
  const results = await Promise.all(tasks);
  const failed = results.filter((r) => !r.ok);
  if (failed.length) {
    console.warn('SW: skipped uncacheable assets', failed);
  }
  return results;
}

/*
 * Install event listener to cache the manifest and assets
 */
// Beacon SW lifecycle failures back to the server. SW errors otherwise
// die silently in the participant's browser console where we never see
// them; this routes a single POST to the run scope so the failure shows
// up in formr's PHP error log and we can fix what's actually breaking
// in production.
async function beaconLifecycleFailure(stage, err) {
  try {
    const beaconUrl = new URL('./pwa-beacon', self.location.href).toString();
    const body = JSON.stringify({
      stage,
      sw_version,
      cache: CACHE_NAME,
      error: String((err && err.message) || err),
      ts: new Date().toISOString(),
    });
    // keepalive in case the SW is going redundant immediately after.
    await fetch(beaconUrl, {
      method: 'POST',
      body,
      headers: { 'Content-Type': 'application/json' },
      keepalive: true,
    });
  } catch (_) {
    // Beacon is best-effort; never let it surface a second error.
  }
}

self.addEventListener('install', (event) => {
  console.log('SW: Starting install');
  const pre_cache = async () => {
    try {
      const manifest = await fetchManifest();
      const assetsToCache = [...new Set([manifest.start_url, ...manifest.icons.map(icon => icon.src)])];
      const cache = await caches.open(CACHE_NAME);
      console.log('SW: Caching assets:', assetsToCache);
      return await safeAddAll(cache, assetsToCache);
    } catch (error) {
      console.error('Install failed:', error);
      // Wait on the beacon so it actually goes out before the SW
      // transitions to redundant — once the install handler rejects,
      // the SW has roughly no time to make outbound requests.
      await beaconLifecycleFailure('install', error);
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
          // Use the same per-URL resilience as the install handler so
          // one missing CSS file doesn't reject the whole batch and
          // leave the postMessage caller hanging on a never-resolved
          // promise.
          return await safeAddAll(cache, validAssets);
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
    event.waitUntil((async () => {
    try {
      if (!event.data) {
        console.warn('Push event received without data');
        // iOS requires every push event to show a notification, otherwise
        // the subscription gets terminated after ~3 "silent" pushes.
        await self.registration.showNotification('New Message', {
          body: 'You have a new notification.',
          icon: '/assets/pwa/icon.png',
          tag: 'fallback-no-data'
        });
        return;
      }

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

      // Show the notification and AWAIT it — iOS Safari terminates push
      // subscriptions if showNotification() doesn't complete inside waitUntil().
      console.log('SW: Start notification display');
      await self.registration.showNotification(data.title || 'Notification', options);
      console.log('Notification displayed');

      // Post-notification work (non-critical, best-effort)
      try {
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
      } catch (postError) {
        console.error('Post-notification work failed (non-critical):', postError);
      }
    } catch (error) {
      console.error('Push notification error:', error);
      // Ensure we ALWAYS show a notification — await the fallback too
      await self.registration.showNotification('New Message', {
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


/*
 * Push subscription rotation handler.
 *
 * Browsers fire `pushsubscriptionchange` when they invalidate and
 * re-issue the participant's push endpoint — token expiry, push-server
 * migration, browser update. Without this handler the server's stored
 * subscription points at the old endpoint, every send bounces, and
 * after a few bounces the push service marks the subscription dead.
 * The participant silently stops receiving notifications.
 *
 * Re-subscribe with the previous options if the browser didn't already
 * hand us a new subscription, then POST it to the run's existing
 * ajax_save_push_subscription endpoint. The endpoint resolves the
 * participant via cookie + RunSession (loginUser flow), so we don't
 * need to thread a participant code through the SW; if the cookie has
 * since evicted, the save fails 401 and the page-side
 * initializePushNotifications recovers on next launch.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    try {
      let newSub = event.newSubscription;
      // Firefox fires with newSubscription=null and expects the SW to
      // resubscribe with the previous options. Chrome usually hands us
      // a populated newSubscription. Cover both.
      if (!newSub) {
        const oldOpts = event.oldSubscription && event.oldSubscription.options;
        if (oldOpts && oldOpts.applicationServerKey) {
          newSub = await self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: oldOpts.applicationServerKey,
          });
        }
      }
      if (!newSub) {
        console.warn('SW pushsubscriptionchange: no new subscription available; page-side init will recover.');
        return;
      }
      const saveUrl = self.registration.scope.replace(/\/+$/, '') + '/ajax_save_push_subscription';
      const body = new URLSearchParams();
      body.set('subscription', JSON.stringify(newSub));
      const res = await fetch(saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body,
      });
      if (!res.ok) {
        console.warn('SW pushsubscriptionchange: save failed', res.status);
      }
    } catch (err) {
      console.error('SW pushsubscriptionchange: refresh failed', err);
    }
  })());
});
// Bump sw_version whenever a change must reach already-installed PWAs:
// without it they keep serving the old bundle out of the SW cache.
// v7 — time-bound handling-reload fix in PWAInstaller.js.
// v8 — stop precaching manifest.start_url (see the install handler): the
//      old worker fetched the participant's own run URL, with their
//      session code and credentials, on every install. Bumping also
//      evicts the v7 cache still holding that response.
const sw_version = 'v8';
// One cache per run (its path) and version: on a path-routed study domain
// every run's worker shares the origin's CacheStorage.
const CACHE_SCOPE = self.location.hostname + self.location.pathname.split('/').slice(0, -1).join('-');
const CACHE_NAME = 'formr-' + sw_version + '-' + CACHE_SCOPE;

// A cache an earlier version of this run's worker left: dropped on
// activation. Another run's cache is not this worker's to delete; deleting
// it emptied that run's offline assets on every install or update here.
function isStaleCache(name) {
  const m = /^formr-v\d+-(.*)$/.exec(name);
  return name !== CACHE_NAME && !!m && m[1] === CACHE_SCOPE;
}

console.log("SW loaded", CACHE_NAME);

// Helper function to validate URLs
function isValidUrl(url) {
  try {
    const urlObj = new URL(url);
    
    // For other assets, only allow URLs from our origin or HTTPS URLs, and only paths containing /assets/
    // `/manifest` must be the last path segment: a bare substring match
    // made every page of a run named e.g. "manifesto" cache-first.
    return (urlObj.protocol === 'https:' || urlObj.origin === self.location.origin)
           && (urlObj.pathname.includes('/assets/') || /\/manifest(\.json)?\/?$/.test(urlObj.pathname));
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
      if (!response.ok) {
        const err = new Error('Failed to fetch manifest.');
        err.status = response.status;
        throw err;
      }
      
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

// The options of the notification a push shows. `badge` is the URL of the
// small monochrome icon, never a count (the count is data.badgeCount, for
// the Badging API): a number was taken as a URL relative to the worker, so
// every notification made the browser fetch <run>/1 with the participant's
// cookies, which runs their run session like a page load.
function notificationOptions(data, bestIcon, timestamp) {
  return {
    body: data.body || '',
    icon: data.icon ? new URL(data.icon, self.location.origin).href : bestIcon,
    badge: typeof data.badge === 'string' && data.badge ? new URL(data.badge, self.location.origin).href : undefined,
    tag: data.tag || `notification-${timestamp}`,
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
}

// This run's windows. matchAll() with includeUncontrolled returns every
// window of the origin, and on a path-routed study domain that is every
// run's: a tap on run B's notification focused (and reloaded) run A's
// window and never opened run B, and every push reloaded the other runs'
// pages.
function inScope(windows) {
  const scope = self.registration.scope;
  return windows.filter((c) => typeof c.url === 'string' && c.url.startsWith(scope));
}

// Helper function to find and sort clients, prioritizing PWA clients
async function findAndSortClients() {
  const clientsArr = inScope(await clients.matchAll({ type: 'window', includeUncontrolled: true }));
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
  // skipWaiting so a sw_version bump activates immediately rather than
  // sitting in 'waiting' until every old PWA window closes. Paired with
  // the cache-eviction step in 'activate' below — together they get the
  // new bundle to participants without requiring them to manually
  // force-quit the PWA. Safe here because we don't change request/response
  // shapes between SW versions; the only intra-version change at activation
  // is dropping stale caches.
  self.skipWaiting();
  const pre_cache = async () => {
    try {
      const manifest = await fetchManifest();
      // v1.8.0: precache ICONS ONLY — never manifest.start_url.
      //
      // start_url is not a static asset: RunController::personalizeManifest
      // sets it to the participant's own run URL WITH their session code
      // (run_url($run) . '?code=' . $code). safeAddAll fetches every entry
      // with credentials:'same-origin', so precaching it fired an
      // authenticated GET on the participant's run — i.e.
      // RunController::indexAction -> RunSession::execute() in a WEB
      // context (isCron() is purely $user->cron, which only the queue sets).
      // Every service-worker install therefore silently advanced the
      // participant's run: a parked Wait saw !isExecutedByCron(), took its
      // "participant came back early" branch and jumped to `body`. Reported
      // from the field as a Wait after a PushMessage ending by itself — a
      // push wakes the SW, skipWaiting() installs the new one, and the
      // install fetch lands within a second of the push. It also stamped
      // activity, created unit sessions and rendered surveys unbidden; Wait
      // was merely where it became visible.
      //
      // Nothing is lost by dropping it: the fetch handler only serves URLs
      // passing isValidUrl() (paths containing /assets/ or /manifest), and
      // the run URL matches neither — so the cached entry could never be
      // read. It was write-only. Navigations go to the network exactly as
      // they did before.
      const assetsToCache = [...new Set((manifest.icons || []).map(icon => icon.src))];
      const cache = await caches.open(CACHE_NAME);
      console.log('SW: Caching assets:', assetsToCache);
      return await safeAddAll(cache, assetsToCache);
    } catch (error) {
      // Pre-caching is best-effort: never fail the install over it. A run
      // without a manifest (/manifest 404s) can still have this worker — an
      // earlier bundle registered it on every v2 study with the offline
      // queue on. Rejecting here made the install fail, the registration
      // vanish, and the next page load re-register, fail and beacon again —
      // and left navigator.serviceWorker.ready (push) pending forever.
      // Beacon so the maintainer still gets a signal — except for the
      // ordinary "this run has no manifest" 404, which is not a failure.
      console.warn('SW: pre-cache skipped', error);
      if (!(error && error.status === 404)) {
        await beaconLifecycleFailure('install', error);
      }
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
      // Drop caches from previous sw_versions. Without this, bumping
      // sw_version creates a new CACHE_NAME but the unscoped
      // `caches.match()` in the fetch handler still hits the old cache
      // and serves stale assets — which is how an installed PWA can
      // keep running yesterday's frontend.bundle.js indefinitely.
      const allCacheNames = await caches.keys();
      await Promise.all(allCacheNames.filter(isStaleCache).map((name) => caches.delete(name)));

      await clients.claim();
      await checkAndCloseExpiredNotifications();
      console.log("SW: Activation complete, clients claimed");

      // Tell every already-open client to reload, otherwise pages that
      // were loaded against the OLD sw_version keep running their old
      // PWAInstaller.js (loaded before this SW activated). The page-side
      // STATE_INVALIDATED handler is what we re-use here — it's been in
      // every shipped sw_version, so the message is understood even by
      // pages running pre-fix code. A fresh Date.now() timestamp passes
      // the dedup check in reload_invalidated.
      try {
        const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        const reloadTs = Date.now();
        for (const client of allClients) {
          try {
            client.postMessage({ type: 'STATE_INVALIDATED', tag: 'sw-activate', timestamp: reloadTs });
          } catch (err) {
            console.warn('SW: activation reload broadcast failed for', client.id, err);
          }
        }
      } catch (broadcastErr) {
        console.warn('SW: activation reload broadcast skipped:', broadcastErr);
      }
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

  // Only handle valid URLs (which now must include /assets/). Everything
  // else is left to the browser by NOT calling respondWith(). Never call
  // fetch(event.request) here without respondWith: the browser still makes
  // its own request, so that second fetch duplicated every in-scope GET —
  // a navigation to the run URL (RunSession::execute twice),
  // /form-bot-challenge mints, cross-origin subresources.
  if (!isValidUrl(event.request.url)) {
    return;
  }

  event.respondWith((async () => {
    try {
      // Scope to the current CACHE_NAME. Without this, an unscoped
      // caches.match() falls back to ANY cache the browser holds,
      // including older sw_version caches that activate is supposed to
      // have evicted but might still exist if activation hasn't run yet.
      const cachedResponse = await caches.match(event.request, { cacheName: CACHE_NAME });
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
  const msg = event.data;
  if (!msg || typeof msg !== 'object') return;
  const reply = event.ports && event.ports[0];
  // Test diagnostic: dump cache state via reply port. The page-side
  // `caches.keys()` is partitioned away from the SW's caches under iOS
  // Safari + automation, so the e2e cache test (tests/e2e/helpers/pwa.js
  // swReportedCaches) asks the SW directly.
  if (msg.type === 'FMR_DUMP_CACHES') {
    event.waitUntil((async () => {
      try {
        const keys = await caches.keys();
        const entries = {};
        for (const name of keys) {
          const c = await caches.open(name);
          entries[name] = (await c.keys()).length;
        }
        reply?.postMessage({ keys, entries, cacheName: CACHE_NAME });
      } catch (err) {
        reply?.postMessage({ error: String((err && err.message) || err) });
      }
    })());
    return;
  }
  // Handle CACHE_ASSETS message
  if (msg.type === 'CACHE_ASSETS') {
    // Filter and deduplicate assets
    const validAssets = [...new Set(Array.isArray(msg.assets) ? msg.assets : [])].filter(isValidUrl);

    console.log('Service worker received assets to cache:', validAssets);

    // Do the caching unconditionally and keep the worker alive for it.
    // (It used to be the ARGUMENT of `event.ports[0]?.postMessage(...)`:
    // pwa-register posts without a port, so the optional chain
    // short-circuited and the caching never ran — and with a port, posting
    // a Promise throws DataCloneError.) The reply, when a port was given,
    // is a plain result.
    event.waitUntil((async () => {
      try {
        const cache = await caches.open(CACHE_NAME);
        console.log("SW: Caching assets:", validAssets);
        // Same per-URL resilience as the install handler: one missing CSS
        // file doesn't reject the whole batch.
        const results = await safeAddAll(cache, validAssets);
        reply?.postMessage({ ok: true, results });
      } catch (error) {
        console.error('Error caching assets:', error);
        reply?.postMessage({ error: String((error && error.message) || error) });
      }
    })());
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
      const options = notificationOptions(data, best_icon_any, timestamp);
      const tag = options.tag;

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
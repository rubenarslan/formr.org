const sw_version = 'v7';
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
      // Pre-caching is best-effort: a missing PWA manifest (studies not
      // configured for install) or an offline install shouldn't discard
      // the SW. v2 forms need the SW registered for Background Sync even
      // when the study is NOT installable. Swallow and continue.
      console.warn('SW: pre-cache skipped', error);
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

/* -------------------------------------------------------------------------
 * form_v2: Background Sync drain hook
 *
 * The SW used to be GET-caching + push only; v2 extends it with a drain
 * handler for the IndexedDB queue the page-JS populates when a POST to
 * /form-page-submit fails (see webroot/assets/form/js/main.js). When the
 * participant closes the tab offline, the page-level `online`-event drain
 * can't run — so the page asks the SW to register a `form-v2-drain` sync
 * tag, and here we respond to the sync event by walking the same IDB store
 * (`formrQueue`, object store `queue`) and POSTing each entry to the
 * captured sync URL.
 *
 * iOS Safari doesn't implement Background Sync; that path falls back to
 * the page's own `online` listener + initial-load drain. Everything here
 * is best-effort.
 * ---------------------------------------------------------------------- */

const FMR_QUEUE_DB = 'formrQueue';
const FMR_QUEUE_STORE = 'queue';
const FMR_SYNC_TAG = 'form-v2-drain';
// The page stashes its sync URL here so the SW knows where to POST entries
// on a background wake-up. Lives only for the SW's current execution
// context — browsers terminate idle SWs (Chromium ~30s) and a Background
// Sync event later spins up a fresh SW with no controlling clients to
// repost the URL. fmrResolveSyncUrl below derives a fallback from
// self.registration.scope, which IS stable across SW restarts.
let fmrSyncUrl = null;

// Derive the form-sync URL from the SW registration scope. Path-based
// deploys register the SW with scope=/<runName>/, so scope+form-sync gives
// /<runName>/form-sync (matches run_url($run, 'form-sync') server-side).
// Subdomain deploys use scope=/ so we get /form-sync, which the browser
// resolves against the participant subdomain it loaded the SW from.
function fmrResolveSyncUrl() {
  if (fmrSyncUrl) return fmrSyncUrl;
  try {
    const scope = self.registration && self.registration.scope;
    if (!scope) return null;
    return scope.replace(/\/+$/, '') + '/form-sync';
  } catch (e) {
    return null;
  }
}

function fmrOpenIDB() {
  return new Promise((resolve, reject) => {
    const req = self.indexedDB.open(FMR_QUEUE_DB, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(FMR_QUEUE_STORE)) {
        const store = db.createObjectStore(FMR_QUEUE_STORE, { keyPath: 'uuid' });
        store.createIndex('client_ts', 'client_ts');
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function fmrQueueGetAll(db) {
  return new Promise((resolve) => {
    const tx = db.transaction(FMR_QUEUE_STORE, 'readonly');
    const store = tx.objectStore(FMR_QUEUE_STORE);
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => resolve([]);
  });
}

function fmrQueueDelete(db, uuid) {
  return new Promise((resolve) => {
    const tx = db.transaction(FMR_QUEUE_STORE, 'readwrite');
    tx.objectStore(FMR_QUEUE_STORE).delete(uuid);
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
    tx.onabort = () => resolve();
  });
}

// Wipe every queued entry. Used on logout / account deletion / push-
// subscription revocation so plaintext answers don't outlive the
// participant's relationship with the run.
function fmrQueueWipe(db) {
  return new Promise((resolve) => {
    const tx = db.transaction(FMR_QUEUE_STORE, 'readwrite');
    tx.objectStore(FMR_QUEUE_STORE).clear();
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
    tx.onabort = () => resolve();
  });
}

function fmrBuildSyncFormData(entry) {
  const fd = new FormData();
  fd.append('uuid', entry.uuid);
  fd.append('page', String(entry.page));
  if (entry.client_ts) fd.append('client_ts', entry.client_ts);
  const data = entry.data || {};
  Object.keys(data).forEach((k) => {
    const v = data[k];
    if (Array.isArray(v)) {
      v.forEach((vv) => fd.append(`data[${k}][]`, vv == null ? '' : String(vv)));
    } else if (v != null) {
      fd.append(`data[${k}]`, String(v));
    }
  });
  const views = entry.item_views || {};
  Object.keys(views).forEach((bucket) => {
    const m = views[bucket] || {};
    Object.keys(m).forEach((id) => fd.append(`item_views[${bucket}][${id}]`, String(m[id])));
  });
  const files = entry.files || {};
  Object.keys(files).forEach((name) => {
    const f = files[name];
    if (f) fd.append(`files[${name}]`, f, f.name || name);
  });
  return fd;
}

async function fmrDrainBackground() {
  const syncUrl = fmrResolveSyncUrl();
  if (!syncUrl) return;
  let db;
  try { db = await fmrOpenIDB(); } catch (e) { return; }
  const entries = await fmrQueueGetAll(db);
  entries.sort((a, b) => (a.client_ts || '').localeCompare(b.client_ts || ''));
  for (const entry of entries) {
    const hasFiles = entry.files && Object.keys(entry.files).length > 0;
    let res;
    try {
      res = await fetch(syncUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: hasFiles
          ? { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
          : { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: hasFiles ? fmrBuildSyncFormData(entry) : JSON.stringify(entry),
      });
    } catch (e) {
      // Still offline; fail the sync so the browser retries later.
      throw e;
    }
    const body = await res.json().catch(() => null);
    if (res.ok || (body && body.already_applied)) {
      await fmrQueueDelete(db, entry.uuid);
      continue;
    }
    if (body && body.drop_entry) {
      await fmrQueueDelete(db, entry.uuid);
      continue;
    }
    // 4xx rejection — don't keep retrying. Leave entry; page-JS will
    // surface the error banner when the user reopens.
    return;
  }
}

self.addEventListener('sync', (event) => {
  if (event.tag !== FMR_SYNC_TAG) return;
  event.waitUntil(fmrDrainBackground());
});

/*
 * Fetch event listener to cache assets
 */
self.addEventListener('fetch', (event) => {
  // Only handle GET requests
  if (event.request.method !== 'GET') {
    return;
  }

  // Wipe the offline queue when the participant navigates to /<run>/logout.
  // The server destroys their session on that endpoint, so any queued
  // entries belong to a relationship that no longer exists. We don't
  // intercept the response — let the navigation proceed normally — but we
  // do schedule the IDB clear in waitUntil so the SW stays alive for it.
  try {
    const u = new URL(event.request.url);
    if (event.request.mode === 'navigate' && /\/[^/]+\/logout\/?$/.test(u.pathname)) {
      event.waitUntil((async () => {
        try {
          const db = await fmrOpenIDB();
          await fmrQueueWipe(db);
          try { db.close(); } catch {}
        } catch (err) {
          console.warn('SW logout-wipe: queue wipe failed', err);
        }
      })());
    }
  } catch {
    // URL parse failed — ignore and fall through
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
  // form_v2: stash the sync URL so sync events know where to POST. The
  // URL is origin-specific (participant subdomain), so we can't derive it
  // server-side at SW install time.
  if (event.data && event.data.type === 'FMR_REGISTER_SYNC_URL' && typeof event.data.url === 'string') {
    fmrSyncUrl = event.data.url;
    return;
  }
  // form_v2: wipe the offline-queue IDB. Triggered from the page on
  // logout (so plaintext answers don't outlive the participant's
  // session) and as a safety net from the pushsubscriptionchange
  // handler below. Wait the wipe before posting back so the page can
  // confirm before navigating away.
  if (event.data && event.data.type === 'FMR_WIPE_QUEUE') {
    (async () => {
      try {
        const db = await fmrOpenIDB();
        await fmrQueueWipe(db);
        try { db.close(); } catch {}
        event.ports[0]?.postMessage({ ok: true });
      } catch (err) {
        event.ports[0]?.postMessage({ error: String(err && err.message || err) });
      }
    })();
    return;
  }
  // Test diagnostic: dump cache state via reply port. The page-side
  // `caches.keys()` is partitioned away from the SW's caches under iOS
  // Safari + automation, so the e2e cache test asks the SW directly.
  if (event.data && event.data.type === 'FMR_DUMP_CACHES') {
    (async () => {
      try {
        const keys = await caches.keys();
        const entries = {};
        for (const name of keys) {
          const c = await caches.open(name);
          const reqs = await c.keys();
          entries[name] = reqs.length;
        }
        event.ports[0]?.postMessage({ keys, entries, cacheName: CACHE_NAME });
      } catch (err) {
        event.ports[0]?.postMessage({ error: String(err && err.message || err) });
      }
    })();
    return;
  }
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

// `pushsubscriptionchange` fires when the browser invalidates the existing
// push subscription — most commonly when the user uninstalls the PWA or
// revokes notification permission. Take the chance to wipe the offline
// queue (those answers were tied to the participant's relationship with
// this run, which is now ending) and the cached subscription endpoint.
// Best-effort: if IDB is unavailable we silently move on.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    try {
      const db = await fmrOpenIDB();
      await fmrQueueWipe(db);
      try { db.close(); } catch {}
    } catch (err) {
      console.warn('SW pushsubscriptionchange: queue wipe failed', err);
    }
  })());
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
// Teams Elevated Service Worker
// Enables PWA installation capability with minimal caching

// Bumped 2026-08-26 with the push diagnostics below, so an updated worker is
// unambiguous rather than depending on a byte-diff of a file that changes rarely.
const CACHE_NAME = 'teams-elevated-v3';

// Assets to cache for app shell
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/manifest.json',
  '/favicon.png',
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.log('Cache addAll failed:', err);
      });
    })
  );
  // Activate immediately
  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    })
  );
  // Take control of all clients immediately
  self.clients.claim();
});

// Fetch event - network first, fallback to cache for navigation
self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Skip cross-origin requests
  if (!request.url.startsWith(self.location.origin)) {
    return;
  }

  // For navigation requests, try network first
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          return response;
        })
        .catch(() => {
          return caches.match('/index.html');
        })
    );
    return;
  }

  // For static assets, try cache first then network
  if (request.destination === 'image' ||
      request.url.includes('/icons/') ||
      request.url.includes('/static/')) {
    event.respondWith(
      caches.match(request).then((cachedResponse) => {
        if (cachedResponse) {
          return cachedResponse;
        }
        return fetch(request).then((response) => {
          // Cache the response for future
          if (response.status === 200) {
            const responseClone = response.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(request, responseClone);
            });
          }
          return response;
        });
      })
    );
    return;
  }

  // For API and other requests, always use network (no caching)
});

// Handle messages from the main app
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// ─────────────────────────────────────────────────────────────────────────────
// Web push
//
// Added 2026-08-26 (docs/chat-notifications-scope.md phase 4). Everything above
// this line is the original cache-only worker; these two handlers are what make
// the app reachable when nobody has a tab open.
//
// ⚠️ On iPhone these only ever fire for a PWA the person has added to their home
// screen — Safari does not deliver web push to a normal tab. That is why email
// remains the fallback rather than being replaced by this.
// ─────────────────────────────────────────────────────────────────────────────

self.addEventListener('push', (event) => {
  // Never let a malformed payload throw here. A push event that rejects shows
  // the browser's own generic "This site has been updated in the background"
  // notification, which is worse than showing nothing.
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = {};
  }

  const title = payload.title || 'Teams Elevated';
  const options = {
    body: payload.body || 'You have a new message.',
    icon: '/icons/icon-192x192.png',
    badge: '/icons/icon-monochrome.png',
    // Collapses repeat notifications for the same conversation into one entry
    // rather than stacking six of them on the lock screen.
    tag: payload.tag || 'teams-elevated',
    renotify: true,
    data: { url: payload.url || '/' },
  };

  // ⚠️ showNotification() can REJECT, and a rejected promise inside waitUntil()
  // reports nothing anywhere — no console error, no notification, no clue. That
  // silence cost a long diagnosis on 2026-08-26 where the push was accepted by
  // the push service every time and simply never appeared.
  //
  // So: log what arrived, and if the rich notification is refused, fall back to
  // the plainest possible one. A notification with no icon is far better than
  // none, and the fallback also tells us WHICH option was the problem —
  // `renotify` and `badge` are the usual suspects, and both are decoration.
  event.waitUntil(
    self.registration
      .showNotification(title, options)
      .then(() => {
        console.log('[push] shown:', title);
      })
      .catch((err) => {
        console.error('[push] showNotification failed with full options:', err);
        return self.registration
          .showNotification(title, {
            body: options.body,
            data: options.data,
          })
          .then(() => {
            console.log('[push] shown via minimal fallback');
          })
          .catch((err2) => {
            console.error('[push] minimal notification ALSO failed:', err2);
          });
      })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const target = (event.notification.data && event.notification.data.url) || '/';

  // ⚠️ Do NOT rely on client.navigate(). It only works on windows this worker
  // actually CONTROLS, and it rejects otherwise — silently, inside waitUntil,
  // with nothing logged anywhere. That is why clicking a notification appeared
  // to do nothing on 2026-08-26.
  //
  // Focus the window and MESSAGE the app instead. postMessage reaches any
  // same-origin client, controlled or not, and the app opens the conversation
  // in place — no reload, and the chat is open before the page would have
  // finished navigating.
  event.waitUntil(
    self.clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then((clientList) => {
        const sameOrigin = clientList.filter((c) => {
          try {
            return new URL(c.url).origin === self.location.origin;
          } catch (e) {
            return false;
          }
        });

        console.log('[notificationclick] target', target, 'windows', sameOrigin.length);

        if (sameOrigin.length === 0) {
          // Nothing open — a fresh window carries the parameter in the URL, and
          // the app reads it on load.
          return self.clients.openWindow ? self.clients.openWindow(target) : undefined;
        }

        // Prefer a focused window if there is one, else the first.
        const client = sameOrigin.find((c) => c.focused) || sameOrigin[0];

        return Promise.resolve(client.focus ? client.focus() : client)
          .catch((err) => {
            console.error('[notificationclick] focus failed', err);
            return client;
          })
          .then((focused) => {
            const t = focused && focused.postMessage ? focused : client;
            try {
              t.postMessage({ type: 'OPEN_CHAT', url: target });
              console.log('[notificationclick] posted OPEN_CHAT');
            } catch (err) {
              console.error('[notificationclick] postMessage failed', err);
              // Last resort: a new window with the parameter in the URL.
              if (self.clients.openWindow) return self.clients.openWindow(target);
            }
            return undefined;
          });
      })
      .catch((err) => {
        console.error('[notificationclick] failed', err);
      })
  );
});

// A subscription can be rotated by the browser without the user doing anything.
// Without this the device goes quiet permanently and nothing anywhere reports a
// problem — the server keeps sending to an endpoint that no longer exists, and
// only learns when it starts returning 410.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    self.registration.pushManager
      .subscribe({ userVisibleOnly: true, applicationServerKey: event.oldSubscription?.options?.applicationServerKey })
      .then((subscription) =>
        fetch('/api/push-subscriptions.php?action=subscribe', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(subscription),
        })
      )
      .catch(() => undefined)
  );
});

import { useCallback, useEffect, useState } from 'react';

const API_URL = process.env.REACT_APP_API_URL || '';

export type PushState =
  | 'unsupported'   // browser has no push at all
  | 'unconfigured'  // server has no VAPID keys
  | 'denied'        // the person said no; only they can undo it
  | 'off'           // supported and available, not enabled on this device
  | 'on';           // enabled on this device

interface UsePushNotifications {
  state: PushState;
  busy: boolean;
  /**
   * Why the last attempt failed, in words a person can act on.
   *
   * The first version swallowed every failure into `setState('off')`, so the
   * control flashed and returned to "Turn on" with nothing in the console and
   * nothing on screen. That is unusable for the person hitting it and worse for
   * whoever has to support them — reported 2026-08-26 after a long diagnosis
   * that this would have answered in one click.
   */
  error: string | null;
  enable: () => Promise<void>;
  disable: () => Promise<void>;
}

/**
 * The push key arrives base64url-encoded; applicationServerKey needs raw bytes.
 *
 * Not decorative — `atob` rejects the URL-safe alphabet outright, so without the
 * substitution every subscribe attempt throws InvalidCharacterError.
 */
function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4);
  const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(normalized);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i += 1) {
    output[i] = raw.charCodeAt(i);
  }
  return output;
}

/**
 * Which browser this is, for unblock instructions.
 *
 * Only used to choose wording. Order matters: Chrome and Edge both contain
 * "Safari" and "Chrome" in their user agent strings, so the more specific
 * checks have to come first.
 */
function detectBrowser(): 'chrome' | 'safari' | 'firefox' | 'edge' | 'other' {
  const ua = navigator.userAgent;
  if (/Edg\//.test(ua)) return 'edge';
  if (/Firefox\//.test(ua)) return 'firefox';
  if (/Chrome\//.test(ua)) return 'chrome';
  if (/Safari\//.test(ua)) return 'safari';
  return 'other';
}

/**
 * What to do when the browser has BLOCKED notifications for this site.
 *
 * ⚠️ Once blocked, no button can re-prompt — browsers refuse, deliberately, so
 * sites cannot nag. The only way back is the person changing it themselves, so
 * the text has to be specific enough to follow. "Check your browser settings" is
 * useless, which is what this replaces.
 */
function blockedInstructions(): string {
  switch (detectBrowser()) {
    case 'chrome':
    case 'edge':
      return 'Notifications are blocked for this site. Click the icon just left of the web address, '
        + 'set Notifications to Allow, then reload the page and try again.';
    case 'safari':
      return 'Notifications are blocked for this site. In Safari, open Settings > Websites > '
        + 'Notifications, set this site to Allow, then reload the page.';
    case 'firefox':
      return 'Notifications are blocked for this site. Click the padlock left of the web address, '
        + 'clear the Notifications setting, then reload the page and try again.';
    default:
      return 'Notifications are blocked for this site in your browser settings. Allow them for this '
        + 'site, then reload the page and try again.';
  }
}

/** The prompt appeared and was dismissed — this one CAN be retried. */
function dismissedInstructions(): string {
  switch (detectBrowser()) {
    case 'chrome':
    case 'edge':
      return 'The permission request was dismissed. Press Turn on again, and choose Allow — or click '
        + 'the icon just left of the web address to set it.';
    case 'safari':
      return 'The permission request was dismissed. Press Turn on again and choose Allow.';
    default:
      return 'The permission request was dismissed. Press Turn on again and choose Allow.';
  }
}

function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('auth_token');
  return token
    ? { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }
    : { 'Content-Type': 'application/json' };
}

/**
 * Enable or disable web push on THIS device.
 *
 * Per device, deliberately: a person's phone, laptop and tablet each hold their
 * own subscription, and turning it on in one place must not claim to have turned
 * it on everywhere. The server stores one row per endpoint for the same reason.
 *
 * ⚠️ On iPhone, `PushManager` only exists inside a PWA that has been added to the
 * home screen. In a normal Safari tab this hook reports `unsupported`, which is
 * accurate — and is why the install prompt matters as much as this does, and why
 * email stays the fallback rather than being replaced.
 *
 * Permission is requested only from `enable()`, never on mount. A permission
 * prompt that appears unasked is the reliable way to get a permanent "no", and
 * `denied` cannot be undone by the site — only by the person, in browser
 * settings.
 */
export function usePushNotifications(): UsePushNotifications {
  const [state, setState] = useState<PushState>('unsupported');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const supported =
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

  const refresh = useCallback(async () => {
    if (!supported) {
      setState('unsupported');
      return;
    }

    if (Notification.permission === 'denied') {
      setState('denied');
      return;
    }

    try {
      const res = await fetch(`${API_URL}/api/push-subscriptions.php?action=vapid-public-key`);
      const data = await res.json();
      if (!data.success || !data.configured) {
        setState('unconfigured');
        return;
      }
    } catch {
      setState('unconfigured');
      return;
    }

    try {
      const registration = await navigator.serviceWorker.ready;
      const existing = await registration.pushManager.getSubscription();

      if (!existing) {
        setState('off');
        return;
      }

      // ⚠️ RE-SEND IT. Finding a local subscription is not evidence the server
      // has the same one.
      //
      // The browser mints a NEW endpoint whenever the service worker
      // registration is replaced — an unregister, a reinstall, clearing site
      // data. This hook used to just render "on" at that point, so the client
      // and the server drifted apart permanently and silently: the UI said
      // notifications were on, the server kept pushing to an endpoint that no
      // longer belonged to this browser, and the push service accepted every
      // one of them. A dead endpoint only starts returning 410 once the push
      // service gives up on it, which can take days — until then everything
      // looks healthy and nothing arrives.
      //
      // Cost a long diagnosis on 2026-08-26. The save is an UPSERT on endpoint,
      // so re-sending on every load is cheap and idempotent.
      try {
        await fetch(`${API_URL}/api/push-subscriptions.php?action=subscribe`, {
          method: 'POST',
          headers: authHeaders(),
          body: JSON.stringify(existing),
        });
      } catch {
        // A failed re-sync must not flip a working toggle to "off" — the
        // subscription is still valid here, we just could not confirm it.
      }

      setState('on');
    } catch {
      setState('off');
    }
  }, [supported]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const enable = useCallback(async () => {
    if (!supported) return;
    setBusy(true);
    setError(null);

    try {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        setState(permission === 'denied' ? 'denied' : 'off');
        setError(permission === 'denied' ? blockedInstructions() : dismissedInstructions());
        return;
      }

      const keyRes = await fetch(`${API_URL}/api/push-subscriptions.php?action=vapid-public-key`);
      const keyData = await keyRes.json();
      if (!keyData.success || !keyData.public_key) {
        setState('unconfigured');
        setError('Notifications are not configured on the server.');
        return;
      }

      const registration = await navigator.serviceWorker.ready;

      let subscription: PushSubscription;
      try {
        subscription = await registration.pushManager.subscribe({
          // Required by Chrome: a push must always result in something the
          // person can see. Everything sent here does.
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(keyData.public_key) as BufferSource,
        });
      } catch (e: any) {
        // The common one is InvalidStateError: this browser already holds a
        // subscription created with a DIFFERENT server key, and it must be
        // dropped before a new one can be made. Say so rather than flashing.
        if (e?.name === 'InvalidStateError') {
          const stale = await registration.pushManager.getSubscription();
          await stale?.unsubscribe().catch(() => undefined);
          setError('This browser held an old notification registration. It has been cleared — press Turn on once more.');
        } else {
          setError(`Your browser refused to register for notifications (${e?.name || 'unknown error'}).`);
        }
        // eslint-disable-next-line no-console
        console.error('[push] subscribe failed', e);
        setState('off');
        return;
      }

      const res = await fetch(`${API_URL}/api/push-subscriptions.php?action=subscribe`, {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify(subscription),
      });

      if (!res.ok) {
        // The browser now holds a subscription the server does not know about.
        // Leaving it would mean the UI says "on" while nothing is ever sent, so
        // roll it back and report honestly.
        await subscription.unsubscribe().catch(() => undefined);
        setState('off');
        setError(
          res.status === 401
            ? 'Your session has expired. Sign in again, then turn notifications on.'
            : `Could not save the registration (error ${res.status}).`
        );
        // eslint-disable-next-line no-console
        console.error('[push] save failed', res.status, await res.text().catch(() => ''));
        return;
      }

      setState('on');
    } catch (e: any) {
      setState('off');
      setError(`Something went wrong turning notifications on (${e?.name || 'unknown error'}).`);
      // eslint-disable-next-line no-console
      console.error('[push] enable failed', e);
    } finally {
      setBusy(false);
    }
  }, [supported]);

  const disable = useCallback(async () => {
    if (!supported) return;
    setBusy(true);

    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();

      if (subscription) {
        // Tell the server BEFORE dropping it locally: once unsubscribe() runs
        // the endpoint is gone from this side and the row could never be
        // matched again, leaving a dead subscription the server keeps trying.
        await fetch(`${API_URL}/api/push-subscriptions.php?action=unsubscribe`, {
          method: 'POST',
          headers: authHeaders(),
          body: JSON.stringify({ endpoint: subscription.endpoint }),
        }).catch(() => undefined);

        await subscription.unsubscribe().catch(() => undefined);
      }

      setState('off');
    } finally {
      setBusy(false);
    }
  }, [supported]);

  return { state, busy, error, enable, disable };
}

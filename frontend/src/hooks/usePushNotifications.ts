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
      setState(existing ? 'on' : 'off');
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

    try {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        setState(permission === 'denied' ? 'denied' : 'off');
        return;
      }

      const keyRes = await fetch(`${API_URL}/api/push-subscriptions.php?action=vapid-public-key`);
      const keyData = await keyRes.json();
      if (!keyData.success || !keyData.public_key) {
        setState('unconfigured');
        return;
      }

      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.subscribe({
        // Required by Chrome: a push must always result in something the person
        // can see. Everything sent here does.
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(keyData.public_key) as BufferSource,
      });

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
        return;
      }

      setState('on');
    } catch {
      setState('off');
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

  return { state, busy, enable, disable };
}

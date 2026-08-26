import React from 'react';
import { usePushNotifications } from '../hooks/usePushNotifications';
import { usePWAInstall } from '../hooks/usePWAInstall';

/**
 * Turn chat notifications on for this device.
 *
 * Deliberately says "this device" everywhere. A push subscription belongs to one
 * browser on one machine, so a control that implied an account-wide setting
 * would be lying to anyone who also uses a phone.
 *
 * ⚠️ The iOS branch is the reason this component is worth its length. On iPhone,
 * `PushManager` does not exist in a normal Safari tab — only inside a PWA added
 * to the home screen. Without an explanation the toggle would simply be missing,
 * and the person would conclude the feature is broken. Telling them the one step
 * that unlocks it is the difference between push reaching most families and
 * almost none.
 */
export const PushNotificationToggle: React.FC = () => {
  const { state, busy, enable, disable } = usePushNotifications();
  const { isIOS, isInstalled } = usePWAInstall();

  // Nothing the server can do about this, and nothing worth showing a toggle for.
  if (state === 'unconfigured') return null;

  const iosNeedsInstall = state === 'unsupported' && isIOS && !isInstalled;

  return (
    <div className="border border-brand-secondary rounded-lg p-4">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h3 className="text-sm font-semibold text-brand-primary">Chat notifications on this device</h3>
          <p className="mt-1 text-sm text-gray-600">
            Get an alert when someone messages you, even when the app is closed. If notifications are
            off, we email you instead.
          </p>
        </div>

        {state === 'on' && (
          <button
            type="button"
            onClick={disable}
            disabled={busy}
            className="shrink-0 px-3 py-1.5 text-sm font-medium rounded-md border border-brand-secondary text-brand-primary disabled:opacity-50"
          >
            {busy ? 'Working…' : 'Turn off'}
          </button>
        )}

        {state === 'off' && (
          <button
            type="button"
            onClick={enable}
            disabled={busy}
            className="shrink-0 px-3 py-1.5 text-sm font-medium rounded-md bg-brand-primary text-white disabled:opacity-50"
          >
            {busy ? 'Working…' : 'Turn on'}
          </button>
        )}
      </div>

      {state === 'on' && (
        <p className="mt-3 text-sm text-green-700">Notifications are on for this device.</p>
      )}

      {/* Only the person can undo a denial, and only in browser settings — so say
          that plainly instead of offering a button that cannot work. */}
      {state === 'denied' && (
        <p className="mt-3 text-sm text-gray-600">
          Notifications are blocked for this site in your browser settings. You will need to allow
          them there before this can be turned on. We will keep emailing you in the meantime.
        </p>
      )}

      {iosNeedsInstall && (
        <div className="mt-3 rounded-md bg-brand-secondary/40 p-3">
          <p className="text-sm text-brand-primary font-medium">One step first on iPhone and iPad</p>
          <p className="mt-1 text-sm text-gray-700">
            Apple only sends notifications to apps saved to your Home Screen. Tap the Share button,
            then <span className="font-semibold">Add to Home Screen</span>, and open Teams Elevated
            from there — this option will be waiting for you.
          </p>
        </div>
      )}

      {state === 'unsupported' && !iosNeedsInstall && (
        <p className="mt-3 text-sm text-gray-600">
          This browser does not support notifications. We will email you instead.
        </p>
      )}
    </div>
  );
};

export default PushNotificationToggle;

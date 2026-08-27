import React from 'react';
import { usePushNotifications } from '../hooks/usePushNotifications';
import { usePWAInstall } from '../hooks/usePWAInstall';

const DISMISSED_KEY = 'te_push_prompt_dismissed_until';

/** Ask again after this long if they dismiss it. */
const SNOOZE_DAYS = 14;

/**
 * A one-tap way to turn notifications on.
 *
 * The toggle in Profile → Notifications works, and no family will ever find it.
 * This is the discoverable path: it appears once for people who could enable
 * notifications and have not, and gets out of the way permanently once they do.
 *
 * Deliberate limits:
 *
 * - **Only where it can succeed.** Hidden when notifications are already on,
 *   unsupported, or BLOCKED. A blocked browser cannot be re-prompted by any
 *   button — browsers refuse so sites cannot nag — so showing one would be a
 *   control that provably does nothing.
 * - **Dismissal is remembered**, for two weeks rather than forever. A parent
 *   dismissing this in August may well want it in September when the season
 *   starts, and permanently silencing on one tap is how a useful prompt becomes
 *   a wasted one.
 * - **iPhone gets the install step instead**, because on iOS the permission
 *   does not exist at all until the app is on the Home Screen. Offering "Turn
 *   on" there would fail with no explanation.
 */
export const EnableNotificationsPrompt: React.FC = () => {
  const { state, busy, error, enable } = usePushNotifications();
  const { isIOS, isInstalled } = usePWAInstall();
  const [dismissed, setDismissed] = React.useState(false);

  React.useEffect(() => {
    try {
      const until = localStorage.getItem(DISMISSED_KEY);
      if (until && Number(until) > Date.now()) setDismissed(true);
    } catch {
      // Private browsing and blocked site data both throw. Showing the prompt is
      // the right failure: worst case they dismiss it again.
    }
  }, []);

  const dismiss = () => {
    setDismissed(true);
    try {
      localStorage.setItem(DISMISSED_KEY, String(Date.now() + SNOOZE_DAYS * 86400000));
    } catch {
      /* the dismissal just will not persist */
    }
  };

  if (dismissed) return null;

  // Nothing to offer: already on, no server keys, or blocked at browser level.
  if (state === 'on' || state === 'unconfigured' || state === 'denied') return null;

  const iosNeedsInstall = state === 'unsupported' && isIOS && !isInstalled;

  // Unsupported and not an iPhone that could be fixed by installing — there is
  // no action to offer.
  if (state === 'unsupported' && !iosNeedsInstall) return null;

  return (
    <div className="mx-4 my-3 rounded-lg border border-brand-secondary bg-brand-secondary/30 p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-brand-primary">
            {iosNeedsInstall ? 'Get message alerts on this iPhone' : 'Get alerted to new messages'}
          </p>
          <p className="mt-1 text-sm text-gray-700">
            {iosNeedsInstall
              ? 'Tap the Share button, then Add to Home Screen. Open Teams Elevated from there and this option will be waiting for you.'
              : 'Know straight away when your coach messages you, even when this app is closed.'}
          </p>
          {error && (
            <p className="mt-2 text-sm text-red-700" role="alert">{error}</p>
          )}
        </div>

        <button
          type="button"
          onClick={dismiss}
          className="shrink-0 text-sm text-gray-500 hover:text-gray-700 px-1"
          aria-label="Dismiss"
        >
          ✕
        </button>
      </div>

      {!iosNeedsInstall && (
        <button
          type="button"
          onClick={enable}
          disabled={busy}
          className="mt-3 w-full sm:w-auto px-4 py-2 rounded-md bg-brand-primary text-white text-sm font-semibold disabled:opacity-50"
        >
          {busy ? 'Working…' : 'Turn on notifications'}
        </button>
      )}
    </div>
  );
};

export default EnableNotificationsPrompt;

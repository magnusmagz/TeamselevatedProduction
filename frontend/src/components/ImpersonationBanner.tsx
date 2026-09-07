import React, { useEffect, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import Button from './ui/Button';

/**
 * Persistent marker that the current session is a super admin acting as someone
 * else.
 *
 * **It has no dismiss control, on purpose.** Everything on screen is the target
 * user's real data, and an admin who forgets which account they are in will send
 * a real email or edit a real record as that person. The banner is the only thing
 * distinguishing this session from an ordinary login, so it stays up for the
 * whole hour. (`DemoModeBanner` is dismissible because its worst case is a
 * missed test card number.)
 *
 * The countdown is not decoration either: the token dies at `exp`, so an admin
 * mid-edit needs to see that coming rather than discover it as a failed save.
 */
export const ImpersonationBanner: React.FC = () => {
  const { user, impersonation, stopImpersonation } = useAuth();
  const [now, setNow] = useState(() => Math.floor(Date.now() / 1000));
  const [exiting, setExiting] = useState(false);

  useEffect(() => {
    if (!impersonation) return;
    const timer = setInterval(() => setNow(Math.floor(Date.now() / 1000)), 1000);
    return () => clearInterval(timer);
  }, [impersonation]);

  if (!impersonation) {
    return null;
  }

  const secondsLeft = Math.max(0, impersonation.exp - now);
  const minutes = Math.floor(secondsLeft / 60);
  const seconds = secondsLeft % 60;
  const expiringSoon = secondsLeft <= 300;

  const handleExit = async () => {
    setExiting(true);
    try {
      await stopImpersonation();
    } finally {
      setExiting(false);
    }
  };

  return (
    <div
      role="status"
      aria-live="polite"
      className="bg-amber-500 border-b-2 border-amber-700 px-4 py-2 sticky top-0 z-50"
    >
      <div className="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-3 min-w-0">
          <span aria-hidden="true" className="text-xl">👤</span>
          <p className="text-sm text-amber-950 truncate">
            <span className="font-bold uppercase">Viewing as</span>{' '}
            <span className="font-semibold">{user?.name || 'user'}</span>
            {user?.email ? <span className="opacity-80"> ({user.email})</span> : null}
            {impersonation.by_name ? (
              <span className="opacity-80"> — signed in as {impersonation.by_name}</span>
            ) : null}
          </p>
        </div>
        <div className="flex items-center gap-3">
          <span className={`text-sm tabular-nums ${expiringSoon ? 'font-bold text-red-900' : 'text-amber-950'}`}>
            {secondsLeft > 0
              ? `Ends in ${minutes}:${String(seconds).padStart(2, '0')}`
              : 'Session ended'}
          </span>
          <Button variant="secondary" onClick={handleExit} loading={exiting}>
            Exit
          </Button>
        </div>
      </div>
    </div>
  );
};

export default ImpersonationBanner;

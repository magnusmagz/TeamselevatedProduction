import React from 'react';
import { useAuth } from '../../contexts/AuthContext';

/**
 * "Nobody has connected you to a child yet" — said out loud.
 *
 * Identity in this product is an email string: a parent's standing is derived by
 * matching `users.email` against `guardians.email`. When the address they signed
 * in with is not the address their club typed into the guardian record, nothing
 * links them to their own child and the portal has literally nothing to show —
 * an empty dashboard, an empty athlete list, an empty schedule, and no hint that
 * anything is wrong. Families read that as "the app is broken"; staff read the
 * support ticket the same way.
 *
 * So the empty answer is stated as an answer, and it names the one fact the club
 * admin needs to fix it: the email this person actually signed in with. That is
 * the value being matched, and it is the value nobody can see from the outside.
 *
 * WHY THERE IS NO "CONTACT YOUR CLUB" BUTTON
 * There would be one if a phone number or address were to hand. Nothing the
 * parent portal loads carries club contact details: the branding endpoint
 * (`api/organization-branding.php`) returns name, logo and colours only, and no
 * portal screen fetches `club_profile`. Adding a fetch to put a mailto behind
 * this sentence would mean a new request on every page that can render it, so
 * the sentence stands alone until a club contact is already in hand.
 *
 * EMPTY IS NOT THE SAME AS UNKNOWN
 * This renders on an EMPTY children list, which `FinancialPermissionsContext`
 * distinguishes from an ABSENT one with `??` — see the comment there. An absent
 * `my_children` means an old backend and falls back to the wider list, so a
 * family with children never lands here by accident.
 */
export const NoAthletesLinked: React.FC<{ className?: string }> = ({ className = '' }) => {
  const { user } = useAuth();
  const email = user?.email;

  return (
    <div className={`text-center py-12 px-4 ${className}`} data-testid="no-athletes-linked">
      <svg
        className="mx-auto h-12 w-12 text-gray-400"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
        />
      </svg>

      <h3 className="mt-3 text-lg font-medium text-brand-primary">
        No athletes connected yet
      </h3>

      <p className="mt-2 text-sm text-gray-600 max-w-sm mx-auto">
        No athletes are connected to your account yet. Ask your club administrator
        to connect you to your athlete
        {email ? ' — mention the email you signed in with:' : '.'}
      </p>

      {email && (
        <>
          {/* Selectable, and never truncated: this string is the thing the admin
              has to match character for character. */}
          <p className="mt-3 text-sm font-medium text-gray-900 break-all select-all">
            {email}
          </p>
          <p className="mt-3 text-xs text-gray-500 max-w-sm mx-auto">
            If your club has a different email address for you, that is why this is
            empty.
          </p>
        </>
      )}
    </div>
  );
};

export default NoAthletesLinked;

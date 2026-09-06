import React from 'react';
import { useAuth } from '../contexts/AuthContext';
import { fetchMyOrgUnits, type OrgStandingUnit } from './rollupApi';

/**
 * The org units the signed-in person has standing at (GOTR G5).
 *
 * WHY A FETCH AND NOT THE TOKEN
 * The JWT carries club roles only; `user_org_access` is not minted into it
 * (lib/JWT.php is on the do-not-modify list, and the G2 token diet is busy
 * making the token smaller, not larger). So the nav has to ask. It asks once
 * per signed-in user and the answer is cached in module scope — every page
 * mounts AppContent, and refetching on each route change would be a request
 * per click for a list that changes when a super admin edits a grant.
 *
 * WHAT IT IS NOT
 * A convenience for the nav. `api/compliance-rollup.php` re-checks standing on
 * every view it answers; a fabricated entry here reaches a 403, not data.
 *
 * A failed read answers "no units" — a nav entry that leads to a 403 is
 * worse than a missing one, and the page itself is reachable by URL.
 */

let cache: { userId: number | string | null; units: OrgStandingUnit[] } | null = null;
let inflight: Promise<OrgStandingUnit[]> | null = null;

/** For tests, and for a sign-out. */
export function resetOrgStandingCache(): void {
  cache = null;
  inflight = null;
}

export function useOrgStanding(): { units: OrgStandingUnit[]; loaded: boolean } {
  const { user } = useAuth();
  const userId = user?.id ?? null;
  const [units, setUnits] = React.useState<OrgStandingUnit[]>(() =>
    cache && cache.userId === userId ? cache.units : []
  );
  const [loaded, setLoaded] = React.useState<boolean>(() => !!cache && cache.userId === userId);

  React.useEffect(() => {
    if (!userId) {
      resetOrgStandingCache();
      setUnits([]);
      setLoaded(false);
      return;
    }
    if (cache && cache.userId === userId) {
      setUnits(cache.units);
      setLoaded(true);
      return;
    }

    let cancelled = false;
    if (!inflight) {
      inflight = fetchMyOrgUnits()
        .then((body) => (Array.isArray(body.units) ? body.units : []))
        .catch(() => [] as OrgStandingUnit[])
        .then((list) => {
          cache = { userId, units: list };
          inflight = null;
          return list;
        });
    }
    inflight.then((list) => {
      if (cancelled) return;
      setUnits(list);
      setLoaded(true);
    });
    return () => {
      cancelled = true;
    };
  }, [userId]);

  return { units, loaded };
}

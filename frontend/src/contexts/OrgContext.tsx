import React, { createContext, useState, useEffect, useContext, useRef, useCallback } from 'react';
import { useAuth } from './AuthContext';

interface RoleContext {
  role: 'club_admin' | 'coach' | 'parent' | 'player';
  scope_type: 'club' | 'team';
  scope_id: number;
  /**
   * Absent on a slim token (G2, TE_FEATURE_SLIM_TOKEN). Optional here because
   * it genuinely is — a type that promises a field the runtime does not send is
   * worse than no type, and that is exactly how the senderId string/number bug
   * hid for months.
   */
  scope_name?: string;
}

interface OrgContextType {
  activeContext: RoleContext | null;
  availableContexts: RoleContext[];
  switchToContext: (scopeId: number, scopeType: 'club') => Promise<void>;
  isClubAdmin: boolean;
  currentClubId: number | null;
}

const OrgContext = createContext<OrgContextType | undefined>(undefined);

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

/** A club's name is never derivable from its id, so say the id rather than lie. */
export function contextLabel(ctx: RoleContext | null | undefined): string {
  if (!ctx) return '';
  return ctx.scope_name || `Club ${ctx.scope_id}`;
}

export function OrgProvider({ children }: { children: React.ReactNode }) {
  const { user, switchContext } = useAuth();
  const [activeContext, setActiveContext] = useState<RoleContext | null>(null);
  const [availableContexts, setAvailableContexts] = useState<RoleContext[]>([]);

  /**
   * scope_id → club name, learned from whichever source had it.
   *
   * A slim token drops `scope_name` from `roles` but keeps it on
   * `active_context`, and every context switch re-mints the token — so without
   * a cache the app would re-fetch the whole name list on every switch just to
   * relabel clubs it has already seen.
   */
  const namesRef = useRef<Map<number, string>>(new Map());
  /** The user id we have already fetched names for, so it happens once. */
  const fetchedForRef = useRef<number | null>(null);

  const withKnownNames = useCallback((roles: RoleContext[]): RoleContext[] =>
    roles.map((r) => (r.scope_name ? r : { ...r, scope_name: namesRef.current.get(r.scope_id) })), []);

  useEffect(() => {
    if (!user) {
      setActiveContext(null);
      setAvailableContexts([]);
      namesRef.current.clear();
      fetchedForRef.current = null;
      localStorage.removeItem('active_org_context');
      return;
    }

    const userRoles: RoleContext[] = user.roles || [];
    const userActiveRole = user.activeRole || null;

    // If user has roles but no active role set, use the first available role
    const active = userActiveRole || (userRoles.length > 0 ? userRoles[0] : null);

    // Learn every name this payload happens to carry. On a slim token that is
    // just the active context; on an old one it is the whole list. Either way
    // the app relabels what it can before deciding to ask the server.
    [...userRoles, ...(active ? [active] : [])].forEach((r) => {
      if (r && r.scope_name) namesRef.current.set(r.scope_id, r.scope_name);
    });

    setActiveContext(active ? { ...active, scope_name: contextLabel(active) } : null);
    setAvailableContexts(withKnownNames(userRoles));

    if (active) {
      localStorage.setItem('active_org_context', JSON.stringify(active));
    }

    /**
     * Backfill from api/my-context.php.
     *
     * Two reasons the token's list can be incomplete, and this one call fixes
     * both: a slim token drops the names, and it caps the array at
     * JWT::TOKEN_ROLE_CAP entries. Because the endpoint returns the WHOLE list
     * with names, we do not need to know which of the two happened — a missing
     * name is the tell for either.
     *
     * An OLD token (names present, nothing capped) never triggers this, so the
     * app behaves identically against a backend that has not been switched over.
     */
    const needsNames = userRoles.some((r) => !r.scope_name && !namesRef.current.has(r.scope_id));
    if (!needsNames || fetchedForRef.current === user.id) {
      return;
    }
    fetchedForRef.current = user.id;

    let cancelled = false;
    const token = localStorage.getItem('auth_token');
    if (!token) return;

    fetch(`${API_URL}/api/my-context.php`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (cancelled || !data || !Array.isArray(data.roles)) return;
        (data.roles as RoleContext[]).forEach((r) => {
          if (r.scope_name) namesRef.current.set(r.scope_id, r.scope_name);
        });
        // The server's list is authoritative and complete; the token's may be a
        // 40-entry prefix. Replace rather than merge.
        setAvailableContexts(data.roles as RoleContext[]);
      })
      .catch(() => {
        // Names are decoration. A failed backfill leaves the picker labelling
        // clubs by id, which is worse-looking and still usable — never a
        // blocked app. Allow a retry on the next user change.
        fetchedForRef.current = null;
      });

    return () => {
      cancelled = true;
    };
  }, [user, withKnownNames]);

  /**
   * Switch the active club.
   *
   * Delegates to AuthContext.switchContext, which posts to
   * auth-gateway.php?action=switch-context and stores the re-minted token. That
   * is deliberately the only place a token is written: an in-flight
   * impersonation rides along on the new token via te_carry_impersonation(), and
   * a second, parallel implementation here would be a second place to forget it.
   *
   * This used to be `console.log('Context switch not yet implemented')` — the
   * backend has worked since it was written.
   */
  const switchToContext = useCallback(async (scopeId: number, scopeType: 'club') => {
    await switchContext(scopeId, scopeType);
  }, [switchContext]);

  // Helper: Check if user is club admin in active context
  const isClubAdmin = activeContext?.role === 'club_admin' && activeContext.scope_type === 'club';

  // Helper: Get current club ID
  const currentClubId = (() => {
    if (!activeContext) return null;
    if (activeContext.scope_type === 'club') return activeContext.scope_id;
    return null;
  })();

  return (
    <OrgContext.Provider
      value={{
        activeContext,
        availableContexts,
        switchToContext,
        isClubAdmin,
        currentClubId,
      }}
    >
      {children}
    </OrgContext.Provider>
  );
}

export function useOrg() {
  const context = useContext(OrgContext);
  if (context === undefined) {
    throw new Error('useOrg must be used within an OrgProvider');
  }
  return context;
}

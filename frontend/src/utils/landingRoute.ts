/**
 * Where a freshly signed-in account lands. ONE answer for Login.tsx,
 * VerifyMagicLink.tsx and ParentRedirect, read off the JWT's roles.
 *
 *   super_admin              → /super-admin
 *   ONLY referee role(s)     → /referee  (their games and contact card; no staff nav,
 *                                         no parent portal — a referee is not staff)
 *   any parent role          → /parent
 *   otherwise                → /dashboard
 *
 * A referee who is ALSO a coach or admin lands on the staff dashboard (the
 * order in lib/JWT.php ranks referee below volunteer); a referee who is also
 * a parent lands in the parent portal, and reaches /referee from there.
 */
export interface LandingUserLike {
  system_role?: string | null;
  roles?: Array<{ role: string }> | null;
}

export function isRefereeOnly(user: LandingUserLike | null | undefined): boolean {
  const roles = user?.roles ?? [];
  return roles.length > 0 && roles.every((r) => r.role === 'referee');
}

export function hasRefereeRole(user: LandingUserLike | null | undefined): boolean {
  return (user?.roles ?? []).some((r) => r.role === 'referee');
}

export function landingRouteFor(user: LandingUserLike | null | undefined): string {
  if (user?.system_role === 'super_admin') return '/super-admin';
  if (isRefereeOnly(user)) return '/referee';
  if ((user?.roles ?? []).some((r) => r.role === 'parent')) return '/parent';
  return '/dashboard';
}

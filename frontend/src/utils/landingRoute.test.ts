import { landingRouteFor, isRefereeOnly, hasRefereeRole } from './landingRoute';

describe('landingRouteFor — one rule for Login, VerifyMagicLink and ParentRedirect', () => {
  it('lands a referee-only account on /referee', () => {
    expect(landingRouteFor({ roles: [{ role: 'referee' }] })).toBe('/referee');
    expect(landingRouteFor({ roles: [{ role: 'referee' }, { role: 'referee' }] })).toBe('/referee');
  });

  it('a referee who is also staff lands on the staff dashboard', () => {
    expect(landingRouteFor({ roles: [{ role: 'referee' }, { role: 'coach' }] })).toBe('/dashboard');
  });

  it('a referee who is also a parent lands in the parent portal', () => {
    expect(landingRouteFor({ roles: [{ role: 'referee' }, { role: 'parent' }] })).toBe('/parent');
  });

  it('keeps the existing answers for everyone else', () => {
    expect(landingRouteFor({ system_role: 'super_admin', roles: [{ role: 'referee' }] })).toBe('/super-admin');
    expect(landingRouteFor({ roles: [{ role: 'parent' }] })).toBe('/parent');
    expect(landingRouteFor({ roles: [{ role: 'coach' }] })).toBe('/dashboard');
    expect(landingRouteFor({ roles: [] })).toBe('/dashboard');
    expect(landingRouteFor(null)).toBe('/dashboard');
  });

  it('isRefereeOnly needs at least one role', () => {
    expect(isRefereeOnly({ roles: [] })).toBe(false);
    expect(isRefereeOnly(undefined)).toBe(false);
    expect(hasRefereeRole({ roles: [{ role: 'coach' }, { role: 'referee' }] })).toBe(true);
  });
});

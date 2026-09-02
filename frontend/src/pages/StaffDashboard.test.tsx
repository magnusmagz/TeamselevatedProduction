import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import { StaffDashboard } from './StaffDashboard';

/**
 * The staff home page (CKU R88).
 *
 * Two things are worth pinning. The first is that the overview covers all four
 * areas rather than revenue alone — that was the report. The second is the one
 * that would actually leak: a coach must not see a Revenue tile. `can_view_revenue`
 * is derived server-side (club_admin / treasurer / league_admin in
 * api/financial-permissions.php), so the tile follows that flag and not a local
 * role guess.
 *
 * The counts themselves come from the SAME endpoints as the pages the tiles link
 * to, which is what keeps a coach's numbers scoped to their teams — the scoping
 * is server-side in teams-gateway / athletes-gateway / programs-api, so the
 * assertions here are about which endpoints get called, not about re-deriving it.
 */

let mockOrg = { currentClubId: 32, isClubAdmin: true };
let mockPermissions = { permissions: { can_view_revenue: true }, loading: false };

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => mockOrg,
}));

jest.mock('../contexts/FinancialPermissionsContext', () => ({
  useFinancialPermissions: () => mockPermissions,
}));

const json = (body: unknown) => ({ ok: true, json: async () => body });

const routeFetch = (url: string) => {
  if (url.includes('teams-gateway')) {
    return Promise.resolve(json({ teams: [{ id: 1 }, { id: 2 }, { id: 3 }] }));
  }
  if (url.includes('athletes-gateway')) {
    // Duplicate id on purpose — the athlete list dedupes, so the tile must too.
    return Promise.resolve(
      json({ athletes: [{ id: 10 }, { id: 11 }, { id: 11 }, { id: 12 }] })
    );
  }
  if (url.includes('programs-api')) {
    return Promise.resolve(json([{ id: 5 }, { id: 6 }]));
  }
  if (url.includes('revenue-summary')) {
    return Promise.resolve(json({ success: true, summary: { collected: '9301.00' } }));
  }
  return Promise.reject(new Error(`unexpected fetch: ${url}`));
};

/**
 * Tiles are located by the page they link to, not by a test id: `src/__mocks__/
 * react-router-dom.tsx` renders Link as an anchor carrying only `to` and
 * `className`, so a data-testid never reaches the DOM under test. Querying the
 * href is also the stronger assertion — "the tile links to its page" is the
 * requirement.
 */
let container: HTMLElement;

const renderDashboard = () => {
  const result = render(
    <MemoryRouter>
      <StaffDashboard />
    </MemoryRouter>
  );
  container = result.container;
  return result;
};

const tile = (href: string): HTMLElement => {
  const el = container.querySelector(`a[href="${href}"]`);
  if (!el) throw new Error(`no dashboard tile linking to ${href}`);
  return el as HTMLElement;
};

const tileOrNull = (href: string) => container.querySelector(`a[href="${href}"]`);

describe('StaffDashboard', () => {
  beforeEach(() => {
    global.fetch = jest.fn((url: any) => routeFetch(String(url))) as any;
    mockOrg = { currentClubId: 32, isClubAdmin: true };
    mockPermissions = { permissions: { can_view_revenue: true }, loading: false };
  });

  test('a club admin gets four tiles, with real counts, each linking to its page', async () => {
    renderDashboard();

    // Four tiles, one per area — Teams, Athletes, Programs, Revenue.
    await waitFor(() => expect(tile('/teams')).toHaveTextContent('3'));
    expect(container.querySelectorAll('a').length).toBe(4);

    expect(tile('/teams')).toHaveTextContent('Teams');
    expect(tile('/athletes')).toHaveTextContent('3');
    expect(tile('/program-management')).toHaveTextContent('2');
    await waitFor(() => expect(tile('/payment/revenue')).toHaveTextContent('$9,301'));
  });

  test('a coach gets three tiles and NO revenue tile, and revenue is never fetched', async () => {
    mockOrg = { currentClubId: 32, isClubAdmin: false };
    mockPermissions = { permissions: { can_view_revenue: false }, loading: false };

    renderDashboard();

    await waitFor(() => expect(tile('/teams')).toHaveTextContent('3'));

    expect(tile('/teams')).toHaveTextContent('My Teams');
    expect(tile('/athletes')).toBeInTheDocument();
    expect(tile('/program-management')).toBeInTheDocument();
    expect(tileOrNull('/payment/revenue')).toBeNull();
    expect(container.querySelectorAll('a').length).toBe(3);
    expect(screen.queryByText(/Revenue/i)).toBeNull();

    const called = (global.fetch as jest.Mock).mock.calls.map((c) => String(c[0]));
    expect(called.some((u) => u.includes('revenue-summary'))).toBe(false);
  });

  test('the counts come from the same endpoints as the pages the tiles link to', async () => {
    renderDashboard();

    await waitFor(() => {
      expect((global.fetch as jest.Mock).mock.calls.length).toBeGreaterThanOrEqual(4);
    });

    const called = (global.fetch as jest.Mock).mock.calls.map((c) => String(c[0]));
    expect(called.some((u) => u.includes('/legacy/teams-gateway.php'))).toBe(true);
    expect(called.some((u) => u.includes('/legacy/athletes-gateway.php'))).toBe(true);
    // club-scoped, and archived programs excluded server-side (no include_archived).
    expect(
      called.some(
        (u) =>
          u.includes('/registration/programs-api.php?path=list&club_id=32') &&
          !u.includes('include_archived')
      )
    ).toBe(true);
    expect(called.some((u) => u.includes('/api/revenue-summary.php?club_id=32'))).toBe(true);
  });

  test('a refused read shows Unavailable, never a zero', async () => {
    global.fetch = jest.fn((url: any) => {
      if (String(url).includes('athletes-gateway')) {
        return Promise.resolve({ ok: false, status: 403, json: async () => ({}) });
      }
      return routeFetch(String(url));
    }) as any;

    renderDashboard();

    await waitFor(() => expect(tile('/athletes')).toHaveTextContent('Unavailable'));
    expect(tile('/athletes')).not.toHaveTextContent('0');
  });
});

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { FinancialPermissionsProvider } from '../../contexts/FinancialPermissionsContext';
import { MyAthletesPage } from '../pages/MyAthletesPage';

/**
 * A parent whose sign-in address does not match their guardian record has no
 * children linked, so every list in the portal is empty and nothing says why.
 * These tests pin the two answers apart:
 *
 *   my_children: []   -> a real answer. Say "nobody has connected you yet", and
 *                        print the email the club admin has to match.
 *   my_children absent -> an old backend. Fall back to accessible_athletes and
 *                        behave exactly as before. `??`, not `||`.
 *
 * The wider list is named here deliberately — ParentPortalChildScopeTest skips
 * __tests__ for precisely this reason: proving the empty answer wins over a
 * coach's roster is only expressible by supplying one.
 */

jest.mock('../../components/BrandingLogo', () => ({
  __esModule: true,
  default: () => <div data-testid="branding-logo">Logo</div>,
}));

jest.mock('react-router-dom', () => ({
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
  useNavigate: () => jest.fn(),
}));

jest.mock('../../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));

import { useAuth } from '../../contexts/AuthContext';

const mockUseAuth = useAuth as unknown as jest.Mock;

const PARENT = {
  id: 370,
  email: 'emilygovier0@gmail.com',
  name: 'Emily Govier',
};

/** A teammate's child — what a coach-parent's finance-scoped list drags in. */
const SOMEONE_ELSES_KID = { id: 812, first_name: 'Luis', last_name: 'Escamilla' };

const PERMISSIONS = {
  can_view_revenue: false,
  can_view_all_payments: false,
  can_view_athlete_payment_status: false,
  can_view_own_payments: true,
  can_send_reminders: false,
  can_process_payments: false,
  can_view_transactions: false,
  can_export_reports: false,
  can_view_roster_fees: false,
  view_amounts: false,
};

const ROLES = {
  is_club_admin: false,
  is_treasurer: false,
  is_coach: false,
  is_parent: true,
};

const json = (body: unknown) =>
  Promise.resolve({ ok: true, json: () => Promise.resolve(body) } as Response);

/** Serve the permissions payload verbatim, so a test can OMIT a key. */
function mockApi(permissionsBody: Record<string, unknown>) {
  (global.fetch as jest.Mock).mockImplementation((url: string) => {
    if (url.includes('financial-permissions.php')) {
      return json(permissionsBody);
    }
    if (url.includes('/api/athletes/')) {
      const id = Number(new URL(url, 'http://x').searchParams.get('id'));
      return json({
        success: true,
        athlete: {
          id,
          first_name: SOMEONE_ELSES_KID.first_name,
          last_name: SOMEONE_ELSES_KID.last_name,
          teams: [],
        },
      });
    }
    return json({ success: true });
  });
}

const renderPortal = () =>
  render(
    <FinancialPermissionsProvider>
      <MyAthletesPage />
    </FinancialPermissionsProvider>
  );

describe('NoAthletesLinked (empty my_children)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
    global.fetch = jest.fn();
    mockUseAuth.mockReturnValue({ user: PARENT });
  });

  it('explains the empty portal and prints the email they signed in with', async () => {
    mockApi({
      success: true,
      authenticated: true,
      permissions: PERMISSIONS,
      roles: ROLES,
      accessible_athlete_ids: [],
      accessible_athletes: [],
      my_children_ids: [],
      my_children: [],
    });

    renderPortal();

    expect(await screen.findByTestId('no-athletes-linked')).toBeInTheDocument();
    expect(screen.getByText('No athletes connected yet')).toBeInTheDocument();
    expect(
      screen.getByText(
        /No athletes are connected to your account yet\. Ask your club administrator to connect you to your athlete — mention the email you signed in with:/
      )
    ).toBeInTheDocument();
    // The whole point: the admin needs the address being matched, verbatim.
    expect(screen.getByText(PARENT.email)).toBeInTheDocument();
  });

  it('does not fall back to the finance-scoped list when my_children is EMPTY', async () => {
    // A coach-parent with no children of their own: the wider list is populated,
    // and must not be presented as their family. `||` would do exactly that.
    mockApi({
      success: true,
      authenticated: true,
      permissions: PERMISSIONS,
      roles: { ...ROLES, is_coach: true },
      accessible_athlete_ids: [SOMEONE_ELSES_KID.id],
      accessible_athletes: [SOMEONE_ELSES_KID],
      my_children_ids: [],
      my_children: [],
    });

    renderPortal();

    expect(await screen.findByTestId('no-athletes-linked')).toBeInTheDocument();
    expect(screen.queryByText(/Escamilla/)).not.toBeInTheDocument();
  });

  it('omits the contact action — the portal loads no club contact details', async () => {
    mockApi({
      success: true,
      authenticated: true,
      permissions: PERMISSIONS,
      roles: ROLES,
      accessible_athlete_ids: [],
      accessible_athletes: [],
      my_children_ids: [],
      my_children: [],
    });

    renderPortal();

    await screen.findByTestId('no-athletes-linked');
    // No mailto/tel is invented, and no extra request is made to go find one.
    expect(document.querySelector('a[href^="mailto:"]')).toBeNull();
    expect(document.querySelector('a[href^="tel:"]')).toBeNull();
    const urls = (global.fetch as jest.Mock).mock.calls.map((c) => String(c[0]));
    expect(urls.some((u) => u.includes('club-profile') || u.includes('branding'))).toBe(
      false
    );
  });
});

describe('an ABSENT my_children still falls back to the wider list', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
    global.fetch = jest.fn();
    mockUseAuth.mockReturnValue({ user: PARENT });
  });

  it('renders the athletes it is given, and never the empty state', async () => {
    // No my_children key at all: this frontend is live ahead of the backend that
    // serves it. Today's behaviour is the wider list — visibly wrong for minutes,
    // rather than silently telling every family they have no children.
    mockApi({
      success: true,
      authenticated: true,
      permissions: PERMISSIONS,
      roles: ROLES,
      accessible_athlete_ids: [SOMEONE_ELSES_KID.id],
      accessible_athletes: [SOMEONE_ELSES_KID],
    });

    renderPortal();

    expect(await screen.findByText(/Escamilla/)).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.queryByTestId('no-athletes-linked')).not.toBeInTheDocument()
    );
  });
});

import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import { OrgProvider, useOrg } from './OrgContext';

/**
 * OrgContext against BOTH token shapes.
 *
 * The G2 token diet drops `scope_name` from the `roles` claim and caps the
 * array, because a 300-council admin's fat token exceeds the router's header
 * limit and they cannot sign in at all. The frontend has to ship FIRST and read
 * both shapes, or the deploy order has no safe ordering: an old frontend
 * against a slim backend renders a club picker full of blanks.
 *
 * So the property under test is "identical behaviour on either shape", not
 * "handles the new shape".
 */

const mockSwitchContext = jest.fn();
const mockUseAuth = jest.fn();
jest.mock('./AuthContext', () => ({ useAuth: () => mockUseAuth() }));

function Probe() {
  const { activeContext, availableContexts, isClubAdmin, currentClubId, switchToContext } = useOrg();
  return (
    <div>
      <span data-testid="active">{activeContext ? `${activeContext.role}:${activeContext.scope_name}` : 'none'}</span>
      <span data-testid="count">{availableContexts.length}</span>
      <span data-testid="labels">{availableContexts.map((c) => c.scope_name ?? '?').join('|')}</span>
      <span data-testid="admin">{String(isClubAdmin)}</span>
      <span data-testid="club">{String(currentClubId)}</span>
      <button onClick={() => switchToContext(51, 'club')}>switch</button>
    </div>
  );
}

const OLD_TOKEN_USER = {
  id: 7,
  email: 'a@b.c',
  name: 'Ada',
  roles: [
    { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Teams Elevated' },
    { role: 'coach', scope_type: 'club', scope_id: 51, scope_name: 'Central Kansas United' },
  ],
  activeRole: { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Teams Elevated' },
};

/** Slim: no names in `roles`, name present on `activeRole`, list capped at 2. */
const SLIM_TOKEN_USER = {
  id: 7,
  email: 'a@b.c',
  name: 'Ada',
  roles: [
    { role: 'club_admin', scope_type: 'club', scope_id: 32 },
    { role: 'coach', scope_type: 'club', scope_id: 51 },
  ],
  activeRole: { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Teams Elevated' },
};

const MY_CONTEXT_BODY = {
  success: true,
  user_id: '7',
  system_role: 'user',
  roles: [
    { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Teams Elevated' },
    { role: 'coach', scope_type: 'club', scope_id: 51, scope_name: 'Central Kansas United' },
    // Beyond the token's cap — only this endpoint knows about it.
    { role: 'coach', scope_type: 'club', scope_id: 99, scope_name: 'Girls on the Run of Kansas' },
  ],
};

const renderWith = (user: any) => render(<OrgProvider><Probe /></OrgProvider>);

beforeEach(() => {
  jest.clearAllMocks();
  localStorage.clear();
  localStorage.setItem('auth_token', 'a.b.c');
  mockUseAuth.mockReturnValue({ user: null, switchContext: mockSwitchContext });
  global.fetch = jest.fn(() =>
    Promise.resolve({ ok: true, json: () => Promise.resolve(MY_CONTEXT_BODY) })
  ) as any;
});

describe('an OLD token, with names in roles', () => {
  it('labels every context without asking the server', async () => {
    mockUseAuth.mockReturnValue({ user: OLD_TOKEN_USER, switchContext: mockSwitchContext });
    renderWith(OLD_TOKEN_USER);

    expect(screen.getByTestId('labels')).toHaveTextContent('Teams Elevated|Central Kansas United');
    expect(screen.getByTestId('count')).toHaveTextContent('2');
    // The whole point of shipping the frontend first: it must not depend on an
    // endpoint the backend may not have yet.
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('derives the active context and admin flag as before', () => {
    mockUseAuth.mockReturnValue({ user: OLD_TOKEN_USER, switchContext: mockSwitchContext });
    renderWith(OLD_TOKEN_USER);

    expect(screen.getByTestId('active')).toHaveTextContent('club_admin:Teams Elevated');
    expect(screen.getByTestId('admin')).toHaveTextContent('true');
    expect(screen.getByTestId('club')).toHaveTextContent('32');
  });
});

describe('a SLIM token, with no names in roles', () => {
  it('fetches the names once and merges them', async () => {
    mockUseAuth.mockReturnValue({ user: SLIM_TOKEN_USER, switchContext: mockSwitchContext });
    renderWith(SLIM_TOKEN_USER);

    await waitFor(() =>
      expect(screen.getByTestId('labels')).toHaveTextContent(
        'Teams Elevated|Central Kansas United|Girls on the Run of Kansas'
      )
    );
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect((global.fetch as jest.Mock).mock.calls[0][0]).toContain('/api/my-context.php');
    expect((global.fetch as jest.Mock).mock.calls[0][1].headers.Authorization).toBe('Bearer a.b.c');
  });

  /**
   * The server's list is complete; the token's may be a 40-entry prefix. A
   * merge that kept only the token's ids would leave the picker silently
   * listing a prefix, which is the failure `roles_truncated` exists to prevent.
   */
  it('takes the whole server list, including clubs the cap dropped', async () => {
    mockUseAuth.mockReturnValue({ user: SLIM_TOKEN_USER, switchContext: mockSwitchContext });
    renderWith(SLIM_TOKEN_USER);

    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('3'));
  });

  it('reads the active context name straight off the token', async () => {
    mockUseAuth.mockReturnValue({ user: SLIM_TOKEN_USER, switchContext: mockSwitchContext });
    renderWith(SLIM_TOKEN_USER);

    // Synchronously, before any fetch resolves — active_context keeps its name
    // precisely so the nav never renders blank while a backfill is in flight.
    expect(screen.getByTestId('active')).toHaveTextContent('club_admin:Teams Elevated');
    expect(screen.getByTestId('admin')).toHaveTextContent('true');

    // Let the backfill settle so its state update lands inside the test.
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('3'));
  });

  it('falls back to the club id when the backfill fails', async () => {
    (global.fetch as jest.Mock).mockRejectedValue(new Error('offline'));
    mockUseAuth.mockReturnValue({ user: SLIM_TOKEN_USER, switchContext: mockSwitchContext });
    renderWith(SLIM_TOKEN_USER);

    // Names are decoration; the app stays usable and the active context is
    // still correct.
    await waitFor(() => expect(screen.getByTestId('active')).toHaveTextContent('Teams Elevated'));
    expect(screen.getByTestId('count')).toHaveTextContent('2');
  });

  it('does not re-fetch on a re-render of the same user', async () => {
    mockUseAuth.mockReturnValue({ user: SLIM_TOKEN_USER, switchContext: mockSwitchContext });
    const { rerender } = render(<OrgProvider><Probe /></OrgProvider>);
    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));

    rerender(<OrgProvider><Probe /></OrgProvider>);

    expect(global.fetch).toHaveBeenCalledTimes(1);
  });
});

describe('switching context', () => {
  it('delegates to AuthContext so the re-minted token is stored in one place', async () => {
    mockUseAuth.mockReturnValue({ user: OLD_TOKEN_USER, switchContext: mockSwitchContext });
    renderWith(OLD_TOKEN_USER);

    await act(async () => {
      screen.getByText('switch').click();
    });

    expect(mockSwitchContext).toHaveBeenCalledWith(51, 'club');
  });
});

describe('signing out', () => {
  it('clears the stored context', () => {
    localStorage.setItem('active_org_context', '{"role":"club_admin"}');
    mockUseAuth.mockReturnValue({ user: null, switchContext: mockSwitchContext });
    renderWith(null);

    expect(screen.getByTestId('count')).toHaveTextContent('0');
    expect(localStorage.getItem('active_org_context')).toBeNull();
  });
});

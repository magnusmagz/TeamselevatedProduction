import React from 'react';
import { render, screen, within } from '@testing-library/react';
import OnboardingFunnel from './OnboardingFunnel';

/**
 * OnboardingFunnel — the national onboarding report (GOTR G6).
 *
 * The page is a mirror of api/onboarding-funnel.php: one row per council,
 * five counts, a totals row, and an "Import coaches" tile that opens the
 * multi-council importer with THIS org unit preselected. What is pinned:
 *
 * - `compliant: null` renders as "n/a", never as 0 — a council with no
 *   requirements is not a council with nobody compliant.
 * - A 403 reads as "you do not administer this organization", not as an empty
 *   funnel; the API is the access control and the page must not soften it.
 * - The import tile carries the org unit in its link.
 *
 * react-router-dom is mocked outright (not requireActual): under the worktree's
 * symlinked node_modules a second module instance makes useParams() resolve
 * from a different Router context and return undefined — the same reason
 * AthleteProfileEnhanced.test.tsx mocks it.
 */

let mockId = '2';
jest.mock('react-router-dom', () => ({
  useParams: () => ({ id: mockId }),
  Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>,
}));

global.fetch = jest.fn();

const FUNNEL = {
  success: true,
  standing: 'org_admin',
  available: true,
  org_unit: { id: 2, name: 'West', type: 'division', external_code: 'WEST' },
  councils: [
    { club_id: 100, club_name: 'GOTR Kansas', org_unit_id: 3, org_unit_name: 'Kansas', council_code: 'KS',
      accounts: 4, invited: 2, accepted: 1, signed_in: 2, compliant: null },
    { club_id: 101, club_name: 'GOTR California', org_unit_id: 4, org_unit_name: 'California', council_code: 'CA',
      accounts: 10, invited: 8, accepted: 6, signed_in: 5, compliant: 3 },
  ],
  totals: { accounts: 14, invited: 10, accepted: 7, signed_in: 7, compliant: 3 },
  compliance_capped: false,
};

function installFetch(body: unknown, ok = true, status = 200) {
  (fetch as jest.Mock).mockImplementation(() =>
    Promise.resolve({ ok, status, json: async () => body })
  );
}

beforeEach(() => {
  (fetch as jest.Mock).mockReset();
  localStorage.setItem('auth_token', 'tok');
  mockId = '2';
});

test('renders one row per council with the five counts and a totals row', async () => {
  installFetch(FUNNEL);
  render(<OnboardingFunnel />);

  const table = await screen.findByRole('table', { name: /onboarding funnel/i });
  const kansas = within(table).getByRole('row', { name: /GOTR Kansas/ });
  expect(within(kansas).getByText('KS')).toBeInTheDocument();
  expect(within(kansas).getAllByRole('cell').map((c) => c.textContent)).toEqual(
    expect.arrayContaining(['4', '2', '1', '2', 'n/a'])
  );

  const totals = within(table).getByRole('row', { name: /totals/i });
  expect(within(totals).getAllByRole('cell').map((c) => c.textContent)).toEqual(
    expect.arrayContaining(['14', '10', '7', '7', '3'])
  );

  expect(screen.getByRole('heading', { name: /West/ })).toBeInTheDocument();
  expect((fetch as jest.Mock).mock.calls[0][0]).toContain('onboarding-funnel.php?org_unit_id=2');
});

test('a council with no requirements reads n/a, not zero', async () => {
  installFetch(FUNNEL);
  render(<OnboardingFunnel />);
  const table = await screen.findByRole('table', { name: /onboarding funnel/i });
  const kansas = within(table).getByRole('row', { name: /GOTR Kansas/ });
  expect(within(kansas).queryByText('0')).not.toBeInTheDocument();
  expect(within(kansas).getByText('n/a')).toBeInTheDocument();
});

test('the import tile opens the multi-council importer with this org unit preselected', async () => {
  installFetch(FUNNEL);
  render(<OnboardingFunnel />);
  const link = await screen.findByRole('link', { name: /import coaches/i });
  expect(link).toHaveAttribute('href', '/imports/national-coaches?org_unit_id=2');
});

test('a refusal is shown as a refusal, not as an empty funnel', async () => {
  installFetch({ error: 'You do not administer this organization' }, false, 403);
  render(<OnboardingFunnel />);
  expect(await screen.findByText(/do not administer this organization/i)).toBeInTheDocument();
  expect(screen.queryByRole('table')).not.toBeInTheDocument();
});

test('an org unit that is not set up says so', async () => {
  mockId = '9';
  installFetch({ success: true, standing: 'org_admin', available: false, org_unit: null, councils: [], totals: {} });
  render(<OnboardingFunnel />);
  expect(await screen.findByText(/not set up/i)).toBeInTheDocument();
});

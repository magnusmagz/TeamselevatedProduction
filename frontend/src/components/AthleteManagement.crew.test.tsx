import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AthleteManagement from './AthleteManagement';

/**
 * The athlete list shows the WHOLE family.
 *
 * Crew members are equal (product rule, 2026-09-02). The column used to be
 * "Primary Crew" and rendered `primary_guardian_name` — one guardian chosen by
 * `athlete_guardians.is_primary`, with the rest of the family invisible on the
 * screen staff spend the most time on. The gateway now returns a `guardians`
 * array and this page renders it.
 *
 * ⚠️ The row is narrow, so only the first two names are drawn. That makes
 * SEARCH the thing worth pinning: a parent hidden behind "+1 more" must still be
 * findable by typing their name, or the filter quietly reports that someone is
 * not in the club.
 */

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 100, activeContext: null }),
}));
jest.mock('./AthleteForm', () => () => <div data-testid="athlete-form" />);
jest.mock('./GuardianManagement', () => () => <div data-testid="guardian-management" />);
jest.mock('./communications/EmailCompose', () => () => <div data-testid="email-compose" />);
jest.mock('./communications/SmsCompose', () => () => <div data-testid="sms-compose" />);

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

const crew = (id: number, first: string, last: string) => ({
  guardian_id: id,
  first_name: first,
  last_name: last,
  name: `${first} ${last}`,
  email: `${first.toLowerCase()}@example.com`,
  mobile_phone: `555-010${id}`,
  relationship: 'Parent',
});

const athletes = [
  // Two parents: both are drawn. Neither is labelled, and neither is dropped.
  {
    id: 1,
    first_name: 'Alice',
    last_name: 'Anders',
    gender: 'Female',
    grade_level: 6,
    date_of_birth: '2013-01-01',
    guardians: [crew(1, 'Dana', 'Anders'), crew(2, 'Eli', 'Anders')],
  },
  // A blended family of three — the case a fixed "Guardian 1 / Guardian 2" pair
  // silently truncated.
  {
    id: 2,
    first_name: 'Bob',
    last_name: 'Brown',
    gender: 'Male',
    grade_level: 8,
    date_of_birth: '2011-01-01',
    guardians: [crew(3, 'Fay', 'Brown'), crew(4, 'Gus', 'Brown'), crew(5, 'Hana', 'Reyes')],
  },
  // No crew at all. A real state, and it must not read as an error.
  {
    id: 3,
    first_name: 'Carol',
    last_name: 'Clark',
    gender: 'Female',
    grade_level: 6,
    date_of_birth: '2013-06-06',
    guardians: [],
  },
];

beforeEach(() => {
  mockFetch.mockReset();
  mockFetch.mockImplementation((url: string) => {
    if (url.includes('athletes-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, athletes }) });
    }
    if (url.includes('team-players-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, team_players: [] }) });
    }
    if (url.includes('teams-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ teams: [] }) });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
  });
  window.localStorage.setItem('auth_token', 'test-token');
});

const renderPage = () =>
  render(
    <MemoryRouter>
      <AthleteManagement />
    </MemoryRouter>
  );

describe('AthleteManagement crew column', () => {
  it('is headed "Crew", not "Primary Crew"', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Alice Anders')).toBeInTheDocument());

    expect(screen.getByRole('columnheader', { name: /crew/i })).toBeInTheDocument();
    expect(screen.queryByText(/primary crew/i)).not.toBeInTheDocument();
  });

  it('lists every crew member on a two-parent family, unranked', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Alice Anders')).toBeInTheDocument());

    expect(screen.getByText('Dana Anders')).toBeInTheDocument();
    expect(screen.getByText('Eli Anders')).toBeInTheDocument();
    expect(screen.queryByText('PRIMARY')).not.toBeInTheDocument();

    // Both are reachable — the second parent is not a display-only extra.
    expect(screen.getByText('dana@example.com')).toBeInTheDocument();
    expect(screen.getByText('eli@example.com')).toBeInTheDocument();
  });

  it('counts the rest rather than choosing two of three', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Bob Brown')).toBeInTheDocument());

    expect(screen.getByText('Fay Brown')).toBeInTheDocument();
    expect(screen.getByText('Gus Brown')).toBeInTheDocument();
    expect(screen.getAllByText('+1 more').length).toBeGreaterThan(0);
  });

  it('finds a crew member who is hidden behind "+1 more"', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Bob Brown')).toBeInTheDocument());

    // Hana is the third guardian and is not drawn — but the filter reads every
    // name, so searching for her must still surface her athlete.
    expect(screen.queryByText('Hana Reyes')).not.toBeInTheDocument();

    const filter = within(screen.getByRole('columnheader', { name: /crew/i }))
      .getByPlaceholderText('Filter…');
    fireEvent.change(filter, { target: { value: 'hana' } });

    expect(screen.getByText('Bob Brown')).toBeInTheDocument();
    expect(screen.queryByText('Alice Anders')).not.toBeInTheDocument();
  });

  it('shows an athlete with no crew without pretending it is an error', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Carol Clark')).toBeInTheDocument());

    expect(screen.getByText('No contact info')).toBeInTheDocument();
  });
});

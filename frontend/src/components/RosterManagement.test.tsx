import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import RosterManagement from './RosterManagement';

jest.mock('./AthletePhotoUpload', () => () => null);

// RosterManagement now renders RosterDownloadButton, which reads the signed-in
// user's roles from AuthContext. This suite renders the component without an
// AuthProvider, so supply the hook rather than the whole provider tree — the
// download control has its own suite (RosterDownloadButton.test.tsx).
jest.mock('../hooks/useAuth', () => ({
  useAuth: () => ({
    user: { id: 1, email: 'coach@club.test', name: 'Coach', roles: [{ role: 'coach' }] },
    isLoading: false,
  }),
}));

const team = { id: 5, name: 'U12 Blue', age_group: 'U12' };

// id 4 is already on THIS team, so it comes back in the roster fetch and must be
// filtered out of Available entirely. The rest split across the two pills.
const athletes = [
  { id: 1, first_name: 'Zoe', last_name: 'Adams', email: 'zoe@example.com', teams: [] },
  { id: 2, first_name: 'Max', last_name: 'Brown', email: 'max@example.com', teams: [{ id: 9, name: 'Cheetahs' }] },
  {
    id: 3,
    first_name: 'Amy',
    last_name: 'Zych',
    email: 'amy@example.com',
    teams: [{ id: 9, name: 'Cheetahs' }, { id: 12, name: 'Rabbits' }],
  },
  { id: 4, first_name: 'Rostered', last_name: 'Kid', email: 'kid@example.com', teams: [{ id: 5, name: 'U12 Blue' }] },
];

const teamMembers = [
  { id: 100, athlete_id: 4, first_name: 'Rostered', last_name: 'Kid', email: 'kid@example.com' },
];

beforeEach(() => {
  (global as any).fetch = jest.fn((url: any) => {
    const u = String(url);
    const json = (body: any) => Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
    if (u.includes('team-players-gateway')) return json({ success: true, team_members: teamMembers });
    if (u.includes('athletes-gateway')) return json({ success: true, athletes });
    return json({});
  });
});

async function renderRoster() {
  render(
    <MemoryRouter>
      <RosterManagement team={team} />
    </MemoryRouter>
  );
  await waitFor(() => expect(screen.getByText('All (3)')).toBeInTheDocument());
}

test('pill counts split available athletes by team assignment', async () => {
  await renderRoster();

  // Athlete 4 is on this team already — excluded from all three counts.
  expect(screen.getByText('All (3)')).toBeInTheDocument();
  expect(screen.getByText('Needs a team (1)')).toBeInTheDocument();
  expect(screen.getByText('On a team (2)')).toBeInTheDocument();
});

test('defaults to All so nothing is hidden on load', async () => {
  await renderRoster();

  expect(screen.getByText('Zoe Adams')).toBeInTheDocument();
  expect(screen.getByText('Max Brown')).toBeInTheDocument();
  expect(screen.getByText('Amy Zych')).toBeInTheDocument();
});

test('"Needs a team" shows only athletes with no team at all', async () => {
  await renderRoster();

  fireEvent.click(screen.getByText('Needs a team (1)'));

  expect(screen.getByText('Zoe Adams')).toBeInTheDocument();
  expect(screen.queryByText('Max Brown')).not.toBeInTheDocument();
  expect(screen.queryByText('Amy Zych')).not.toBeInTheDocument();
});

test('"On a team" shows rostered athletes with their team badges', async () => {
  await renderRoster();

  fireEvent.click(screen.getByText('On a team (2)'));

  expect(screen.queryByText('Zoe Adams')).not.toBeInTheDocument();
  expect(screen.getByText('Max Brown')).toBeInTheDocument();
  expect(screen.getByText('Amy Zych')).toBeInTheDocument();

  // Amy is on two teams; both badges render.
  expect(screen.getAllByText('Cheetahs')).toHaveLength(2);
  expect(screen.getByText('Rabbits')).toBeInTheDocument();
});

test('search matches name or email and updates the pill counts', async () => {
  await renderRoster();

  const box = screen.getByLabelText('Search available athletes');

  fireEvent.change(box, { target: { value: 'brown' } });
  expect(screen.getByText('Max Brown')).toBeInTheDocument();
  expect(screen.queryByText('Zoe Adams')).not.toBeInTheDocument();

  // Counts describe the search result, not the whole club.
  expect(screen.getByText('All (1)')).toBeInTheDocument();
  expect(screen.getByText('Needs a team (0)')).toBeInTheDocument();
  expect(screen.getByText('On a team (1)')).toBeInTheDocument();

  fireEvent.change(box, { target: { value: 'zoe@example.com' } });
  expect(screen.getByText('Zoe Adams')).toBeInTheDocument();
  expect(screen.queryByText('Max Brown')).not.toBeInTheDocument();
});

test('search and pill compose, with a distinct empty state', async () => {
  await renderRoster();

  fireEvent.click(screen.getByText('Needs a team (1)'));
  fireEvent.change(screen.getByLabelText('Search available athletes'), { target: { value: 'brown' } });

  // Max Brown matches the search but is already on a team, so nothing shows.
  expect(screen.queryByText('Max Brown')).not.toBeInTheDocument();
  expect(screen.getByText('No athletes match this search and filter')).toBeInTheDocument();
});

test('athletes already on this team stay out of the available list', async () => {
  await renderRoster();

  // Present once — in the Team Roster panel, not in Available.
  expect(screen.getAllByText('Rostered Kid')).toHaveLength(1);
});

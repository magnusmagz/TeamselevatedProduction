import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import RefereeHome from './RefereeHome';

jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 300, name: 'Ray Whistle', email: 'ref@whistle.test', roles: [{ role: 'referee' }] }, logout: jest.fn() }),
}));

const clubs = [
  { referee_id: 3, club_id: 200, club_name: 'Away United', primary_color: '#445566', grade: 'National', certification_level: null },
  { referee_id: 1, club_id: 100, club_name: 'Home FC', primary_color: '#112233', grade: 'Regional', certification_level: null },
];
const game = (over: Record<string, unknown>) => ({
  id: 500, club_id: 100, club_name: 'Home FC', primary_color: '#112233', name: 'League match', event_date: '2026-09-20',
  start_time: '10:00', end_time: null, opponent_name: 'Rivals FC', location: null, status: 'scheduled',
  venue_name: 'North Park', venue_address: '1 Park Rd', venue_city: 'Wichita',
  teams: [{ id: 10, name: 'U12 Blue', primary_color: null }], role: 'center', self_assigned: false, referee_id: 1, ...over,
});

function mockApi(opts: { clubs?: unknown[]; upcoming?: unknown[]; past?: unknown[]; open?: unknown[] }) {
  (global.fetch as jest.Mock).mockImplementation((url: string, init?: RequestInit) => {
    if (url.includes('action=my-games')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, today: '2026-09-08', clubs: opts.clubs ?? clubs, upcoming: opts.upcoming ?? [], past: opts.past ?? [] }) });
    }
    if (url.includes('action=open-games')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, games: opts.open ?? [], roles: ['referee', 'center', 'assistant', 'fourth'] }) });
    }
    if (url.includes('action=set-my-grade')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, grade: JSON.parse(String(init?.body ?? '{}')).grade, clubs_updated: 1 }) });
    }
    if (url.includes('action=claim') || url.includes('action=release')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, referees: [] }) });
    }
    if (url.includes('user-profile.php') && (!init || init.method === undefined)) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, user: { first_name: 'Ray', last_name: 'Whistle', email: 'ref@whistle.test', phone: '+13165550100' } }) });
    }
    if (url.includes('user-profile.php')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true }) });
    }
    return Promise.resolve({ ok: true, status: 200, json: async () => ({}) });
  });
}

beforeEach(() => {
  global.fetch = jest.fn();
  localStorage.setItem('auth_token', 'tok');
});
afterEach(() => {
  delete (global as any).fetch;
});

describe('RefereeHome — the referee\'s own page', () => {
  it('names the pitch: "Venue · Field" when the game has one, venue and directions otherwise', async () => {
    mockApi({ upcoming: [game({ field_name: 'Field 2' }), game({ id: 501, name: 'Away tie', field_name: null, location: 'Behind the school' })] });
    render(<RefereeHome />);
    expect(await screen.findByText('North Park · Field 2')).toBeInTheDocument();
    expect(screen.getByText('North Park · Behind the school')).toBeInTheDocument();
  });

  it('shows the empty state when no games are assigned, and the contact card', async () => {
    mockApi({ upcoming: [], past: [] });
    render(<RefereeHome />);
    expect(await screen.findByTestId('upcoming-empty')).toHaveTextContent('No games assigned yet — your club will let you know.');
    expect(screen.getByTestId('open-empty')).toBeInTheDocument();
    expect(screen.getByTestId('contact-card')).toHaveTextContent('+13165550100');
    expect(screen.getByTestId('contact-card')).toHaveTextContent('Grade at Home FC: Regional');
    // The clubs strip lists every club, with the club's grade — read-only here.
    expect(screen.getByTestId('club-chip-100')).toHaveTextContent('Home FC');
    expect(screen.getByTestId('club-chip-200')).toHaveTextContent('grade National');
  });

  it('tells an unconnected account to ask their club admin', async () => {
    mockApi({ clubs: [] });
    render(<RefereeHome />);
    expect(await screen.findByTestId('not-connected')).toHaveTextContent('No club has connected you yet');
  });

  it('lists games across clubs, filters by the clubs strip, collapses past games, and releases a claimed game', async () => {
    mockApi({
      upcoming: [
        game({ id: 503, club_id: 200, club_name: 'Away United', name: 'Other league', event_date: '2026-09-15', teams: [], opponent_name: 'Elsewhere', self_assigned: true, referee_id: 3 }),
        game({ id: 500 }),
      ],
      past: [game({ id: 501, name: 'Played match', event_date: '2026-09-01' })],
    });
    render(<RefereeHome />);

    expect(await screen.findByTestId('upcoming-game-503')).toHaveTextContent('Away United');
    expect(screen.getByTestId('upcoming-game-500')).toHaveTextContent('Sep 20, 2026 · 10:00');
    expect(screen.getByTestId('upcoming-game-500')).toHaveTextContent('North Park');
    expect(screen.getByTestId('upcoming-game-500')).toHaveTextContent('U12 Blue vs Rivals FC');
    expect(screen.getByTestId('upcoming-game-500')).toHaveTextContent('Your role: Center');
    expect(screen.getByTestId('upcoming-game-503')).toHaveTextContent('You claimed this');
    expect(screen.queryByTestId('past-game-501')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Past games \(1\)/ }));
    expect(screen.getByTestId('past-game-501')).toHaveTextContent('Played match');

    fireEvent.change(screen.getByLabelText(/Filter by club/), { target: { value: '200' } });
    expect(screen.queryByTestId('upcoming-game-500')).not.toBeInTheDocument();
    expect(screen.getByTestId('upcoming-game-503')).toBeInTheDocument();

    // Release is offered on the claimed game only.
    expect(screen.getAllByRole('button', { name: /^Release$/ })).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: /^Release$/ }));
    await waitFor(() => {
      const call = (global.fetch as jest.Mock).mock.calls.find(([u]) => String(u).includes('action=release'));
      expect(call).toBeTruthy();
      expect(JSON.parse(call![1].body)).toEqual({ event_id: 503 });
    });
  });

  it('lists open games and claims one with the chosen role', async () => {
    mockApi({
      open: [game({ id: 504, name: 'Cup final', event_date: '2026-09-25', min_referee_grade: 'National', role: undefined, self_assigned: undefined,
        referees: [{ id: 2, name: 'Nora Flag', role: 'assistant', grade: 'Grade 5' }], open_roles: ['referee', 'center', 'fourth'] })],
    });
    render(<RefereeHome />);

    const card = await screen.findByTestId('open-game-504');
    expect(card).toHaveTextContent('Minimum grade: National');
    expect(card).toHaveTextContent('Already on it: Nora Flag (Assistant)');
    fireEvent.change(screen.getByLabelText(/Role for Cup final/), { target: { value: 'fourth' } });
    fireEvent.click(screen.getByRole('button', { name: /Take this game/ }));

    await waitFor(() => {
      const call = (global.fetch as jest.Mock).mock.calls.find(([u]) => String(u).includes('action=claim'));
      expect(call).toBeTruthy();
      expect(JSON.parse(call![1].body)).toEqual({ event_id: 504, role: 'fourth' });
    });
  });

  it('greys a conflicting open game with the reason and disables Take', async () => {
    mockApi({
      open: [game({ id: 510, name: 'Morning clash', role: undefined, self_assigned: undefined, referees: [], open_roles: ['center'],
        conflict: true, conflict_reason: 'You are already on League match from 10:00, which overlaps this game.' })],
    });
    render(<RefereeHome />);
    const card = await screen.findByTestId('open-game-510');
    expect(card).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByTestId('conflict-510')).toHaveTextContent('already on League match');
    expect(screen.getByRole('button', { name: /Take this game/ })).toBeDisabled();
  });

  it('edits the contact card through user-profile.php', async () => {
    mockApi({});
    render(<RefereeHome />);
    // The Edit control appears once the profile has loaded, not when the card mounts.
    fireEvent.click(await screen.findByRole('button', { name: /^Edit$/ }));
    fireEvent.change(screen.getByLabelText(/^Phone/), { target: { value: '316-555-0101' } });
    fireEvent.submit(screen.getByRole('button', { name: /^Save$/ }).closest('form') as HTMLFormElement);

    await waitFor(() => {
      const call = (global.fetch as jest.Mock).mock.calls.find(([u, i]) => String(u).includes('user-profile.php') && i?.method === 'PUT');
      expect(call).toBeTruthy();
      expect(JSON.parse(call![1].body)).toEqual({ first_name: 'Ray', last_name: 'Whistle', email: 'ref@whistle.test', phone: '316-555-0101' });
    });
    expect(await screen.findByText('316-555-0101')).toBeInTheDocument();
  });

  it('lets the referee change their own grade, posted to set-my-grade and applied to every club', async () => {
    mockApi({});
    render(<RefereeHome />);
    fireEvent.click(await screen.findByRole('button', { name: /^Edit$/ }));
    fireEvent.change(screen.getByLabelText(/^Grade/), { target: { value: 'National Assistant Referee' } });
    fireEvent.submit(screen.getByRole('button', { name: /^Save$/ }).closest('form') as HTMLFormElement);

    await waitFor(() => {
      const call = (global.fetch as jest.Mock).mock.calls.find(([u]) => String(u).includes('action=set-my-grade'));
      expect(call).toBeTruthy();
      expect(JSON.parse(call![1].body)).toEqual({ grade: 'National Assistant Referee' });
    });
    expect(screen.getByTestId('contact-card')).toHaveTextContent('Grade at Home FC: National Assistant Referee');
  });
});

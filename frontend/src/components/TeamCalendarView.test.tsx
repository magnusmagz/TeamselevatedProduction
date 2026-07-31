import React from 'react';
import { render, screen, waitFor, within, fireEvent } from '@testing-library/react';
import TeamCalendarView from './TeamCalendarView';

// CA-14: events on the team calendar must render color-coded by event_type
// (game / practice / dinner / tournament etc.) — each type a DISTINCT color,
// not the old uniform brand-secondary treatment.

// Mock child components that aren't under test.
jest.mock('./AttendanceModal', () => () => <div data-testid="attendance-modal" />);
jest.mock('./CalendarSubscriptionManager', () => () => <div data-testid="sub-manager" />);

// Mock auth context (TeamCalendarView calls useAuth()).
jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ user: { activeRole: { role: 'club_admin', scope_id: 1 } } }),
}));

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

// Events spanning the four headline types so we can assert distinct colors.
const today = new Date();
const dateStr = (d: number) =>
  new Date(today.getFullYear(), today.getMonth(), d).toISOString().split('T')[0];

const mockEvents = [
  { id: 1, name: 'League Game', type: 'game', event_date: dateStr(5), status: 'scheduled', teams: [] },
  { id: 2, name: 'Team Practice', type: 'practice', event_date: dateStr(6), status: 'scheduled', teams: [] },
  { id: 3, name: 'Season Tournament', type: 'tournament', event_date: dateStr(7), status: 'scheduled', teams: [] },
  { id: 4, name: 'Team Dinner', type: 'event', event_date: dateStr(8), status: 'scheduled', teams: [] },
];

beforeEach(() => {
  mockFetch.mockReset();
  localStorage.setItem('auth_token', 'test-token');
  mockFetch.mockImplementation((url: string) => {
    if (url.includes('events-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ events: mockEvents }) });
    }
    if (url.includes('venues-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ venues: [] }) });
    }
    if (url.includes('teams-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ teams: [] }) });
    }
    if (url.includes('/api/coach/teams')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ teams: [] }) });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
  });
});

// Find the calendar cell <div> that wraps a given event name.
const eventTile = (name: string): HTMLElement => {
  const label = screen.getAllByText(name)[0];
  // The tile div carries the color classes; climb to the element whose title
  // includes the event name (set on the colored tile).
  let el: HTMLElement | null = label;
  while (el && !(el.getAttribute('title') || '').includes(name)) {
    el = el.parentElement;
  }
  if (!el) throw new Error(`Could not find colored tile for "${name}"`);
  return el;
};

describe('TeamCalendarView color-coding (CA-14)', () => {
  it('renders each event_type with a distinct color class', async () => {
    render(<TeamCalendarView />);

    await waitFor(() => expect(screen.getAllByText('League Game').length).toBeGreaterThan(0));

    const game = eventTile('League Game');
    const practice = eventTile('Team Practice');
    const tournament = eventTile('Season Tournament');
    const dinner = eventTile('Team Dinner');

    // Each type gets its own hue.
    expect(game.className).toContain('bg-red-100');
    expect(practice.className).toContain('bg-blue-100');
    expect(tournament.className).toContain('bg-purple-100');
    expect(dinner.className).toContain('bg-green-100');

    // And critically NOT the old uniform brand-secondary background.
    expect(game.className).not.toContain('bg-brand-secondary');
    expect(practice.className).not.toContain('bg-brand-secondary');

    // The four colors are mutually distinct.
    const bgClass = (el: HTMLElement) =>
      el.className.split(' ').find(c => c.startsWith('bg-'));
    const colors = new Set([
      bgClass(game),
      bgClass(practice),
      bgClass(tournament),
      bgClass(dinner),
    ]);
    expect(colors.size).toBe(4);
  });

  it('shows an event-type legend with all types', async () => {
    render(<TeamCalendarView />);
    await waitFor(() => expect(screen.getByText('Event Types')).toBeInTheDocument());

    const legend = screen.getByText('Event Types').parentElement as HTMLElement;
    expect(within(legend).getByText('Game')).toBeInTheDocument();
    expect(within(legend).getByText('Practice')).toBeInTheDocument();
    expect(within(legend).getByText('Tournament')).toBeInTheDocument();
    expect(within(legend).getByText('Meeting')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Practice counts (2026-07-30)
//
// The calendar carried a parallel `practices` state array, left over from before
// practices moved into `calendar_events`. Nothing ever populated it, yet two
// pieces of UI read it and nothing else: the practices stat tile and the
// Schedule view's list. Both were therefore pinned at 0 / "none scheduled"
// forever, sitting beside a month grid that rendered those same practices
// correctly from `events`. Production had 337 practice events (18 upcoming).
//
// An empty array renders a plausible "0" and a plausible empty state, which is
// exactly why nothing caught it. These assert against known fixture counts so a
// silent zero fails loudly.
// ---------------------------------------------------------------------------

const isoDay = (d: Date) => d.toLocaleDateString('en-CA');

describe('TeamCalendarView practice counts', () => {
  const now = new Date();
  // Deliberately local-date formatted, never toISOString(): these fixtures are
  // asserted against month buckets, and toISOString() on a local midnight lands
  // on the previous day in any negative-UTC-offset timezone.
  const todayInMonth = isoDay(now);
  const firstOfMonth = isoDay(new Date(now.getFullYear(), now.getMonth(), 1));
  const lastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 15);

  const practiceFixtures = [
    { id: 11, name: 'Upcoming Practice', type: 'practice', event_date: todayInMonth,
      start_time: '17:00', end_time: '18:30', status: 'scheduled',
      teams: [{ id: 7, name: 'U12 Strikers' }], venue_name: 'North Field' },
    { id: 12, name: 'Earlier This Month', type: 'practice', event_date: firstOfMonth,
      start_time: '17:00', end_time: '18:30', status: 'scheduled',
      teams: [{ id: 7, name: 'U12 Strikers' }], venue_name: 'North Field' },
    { id: 13, name: 'Last Month Practice', type: 'practice', event_date: isoDay(lastMonth),
      start_time: '17:00', end_time: '18:30', status: 'scheduled',
      teams: [{ id: 7, name: 'U12 Strikers' }], venue_name: 'North Field' },
    { id: 14, name: 'Rivals Game', type: 'game', event_date: dateStr(12),
      start_time: '10:00', end_time: '12:00', status: 'scheduled',
      teams: [{ id: 7, name: 'U12 Strikers' }], venue_name: 'Stadium' },
  ];

  const serve = (events: any[]) =>
    mockFetch.mockImplementation((url: string) => {
      if (url.includes('events-gateway.php')) {
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ events }) });
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ teams: [], venues: [] }) });
    });

  const tileValue = (label: RegExp) => {
    const caption = screen.getByText(label);
    const tile = caption.parentElement as HTMLElement;
    return (tile.querySelector('div') as HTMLElement).textContent;
  };

  it('counts practice events in the visible month, not the dead array', async () => {
    serve(practiceFixtures);
    render(<TeamCalendarView />);

    await waitFor(() => expect(screen.getByText(/Practices This Month/i)).toBeInTheDocument());

    // Two of the three practices are in the current month; the third is last
    // month and the game is not a practice. The old code rendered 0 here.
    await waitFor(() => expect(tileValue(/Practices This Month/i)).toBe('2'));
  });

  it('lists upcoming practices in the Schedule view instead of claiming none', async () => {
    serve(practiceFixtures);
    render(<TeamCalendarView />);

    await waitFor(() => expect(screen.getByText(/Practices This Month/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /^schedule$/i }));
    await waitFor(() => expect(screen.getByText(/All Scheduled Practices/i)).toBeInTheDocument());

    expect(screen.queryByText(/No upcoming practices scheduled/i)).not.toBeInTheDocument();
    expect(screen.getAllByText('Upcoming Practice').length).toBeGreaterThan(0);
    // A past practice must not appear in an "upcoming" list...
    expect(screen.queryByText('Last Month Practice')).not.toBeInTheDocument();
    // ...nor should a non-practice event.
    const scheduleSection = screen.getByText(/All Scheduled Practices/i).closest('div')!;
    expect(within(scheduleSection).queryByText('Rivals Game')).not.toBeInTheDocument();
  });

  it('still shows the empty state when there genuinely are no practices', async () => {
    serve([practiceFixtures[3]]); // the game only
    render(<TeamCalendarView />);

    await waitFor(() => expect(screen.getByText(/Practices This Month/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /^schedule$/i }));
    await waitFor(() => expect(screen.getByText(/All Scheduled Practices/i)).toBeInTheDocument());

    expect(screen.getByText(/No upcoming practices scheduled/i)).toBeInTheDocument();
    expect(tileValue(/Practices This Month/i)).toBe('0');
  });
});

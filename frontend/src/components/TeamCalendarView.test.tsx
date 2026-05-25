import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
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

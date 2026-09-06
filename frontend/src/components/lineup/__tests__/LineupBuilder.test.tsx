import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import LineupBuilder from '../LineupBuilder';

/**
 * The coach's lineup screen (slice 8.5). The server decides everything twice;
 * these pin what the screen does between taps: place, swap, the count guard,
 * absent players greyed, and the Publish button only once a game lineup exists.
 */

const API = 'https://api.test';

const roster = [
  { athlete_id: 1, first_name: 'Ana', last_name: 'Keeper', name: 'Ana Keeper', jersey_number: 1, primary_position: 'Goalkeeper', status: 'active' },
  { athlete_id: 2, first_name: 'Ben', last_name: 'Back', name: 'Ben Back', jersey_number: 2, primary_position: 'Left Back', status: 'active' },
  { athlete_id: 3, first_name: 'Cal', last_name: 'Centre', name: 'Cal Centre', jersey_number: 4, primary_position: 'Center Back', status: 'active' },
  { athlete_id: 4, first_name: 'Dee', last_name: 'Defender', name: 'Dee Defender', jersey_number: 5, primary_position: 'Right Back', status: 'active' },
  { athlete_id: 5, first_name: 'Eli', last_name: 'Mid', name: 'Eli Mid', jersey_number: 6, primary_position: 'Central Midfielder', status: 'active' },
  { athlete_id: 6, first_name: 'Jon', last_name: 'Injured', name: 'Jon Injured', jersey_number: 11, primary_position: 'Center Back', status: 'injured' },
];

function getResponse(over: Record<string, unknown> = {}) {
  return {
    ok: true,
    status: 200,
    json: async () => ({
      success: true,
      available: true,
      can_edit: true,
      team: { id: 10, name: 'U8 Blue', age_group: 'U8', field_size: '4v4', field_size_from_age_group: true },
      event: { id: 501, name: 'League match', type: 'game', event_date: '2026-09-12', start_time: '10:00', opponent_name: 'Salina', status: 'scheduled' },
      lineup: null,
      is_template: false,
      has_template: false,
      last_game: null,
      formations: ['1-2-1', '2-2'],
      roster,
      attendance: { '3': 'absent', '4': 'excused' },
      ...over,
    }),
  };
}

const savedLineup = {
  id: 7, team_id: 10, calendar_event_id: 501, is_template: false, name: 'vs Salina', formation: '1-2-1',
  field_size: '4v4', published_at: null,
  slots: [
    { athlete_id: 1, slot: 'D1', sort_order: 0, captain: false, note: null },
    { athlete_id: 2, slot: 'M1', sort_order: 0, captain: true, note: null },
    { athlete_id: 5, slot: 'BENCH', sort_order: 1, captain: false, note: null },
  ],
};

beforeEach(() => {
  global.fetch = jest.fn();
  localStorage.setItem('auth_token', 'tok');
});

afterEach(() => {
  delete (global as any).fetch;
});

const slot = (label: string) => screen.getByRole('button', { name: new RegExp(`^${label}`) });
const player = (id: number) => within(screen.getByTestId(`player-${id}`)).getAllByRole('button')[0];

describe('LineupBuilder', () => {
  it('tap a slot, tap a player: the player is placed and the count moves', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse());
    render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');

    expect(screen.getByTestId('field-count')).toHaveTextContent('0/4 on field');
    fireEvent.click(slot('CB slot, empty'));
    fireEvent.click(player(1));

    expect(screen.getByRole('button', { name: 'CB: Ana Keeper #1' })).toBeInTheDocument();
    expect(screen.getByTestId('field-count')).toHaveTextContent('1/4 on field');
    // Off the bench once placed.
    expect(screen.queryByTestId('player-1')).not.toBeInTheDocument();
  });

  it('tap a player first, then a slot, also places; tapping two occupied slots swaps them', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse({ lineup: savedLineup }));
    render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');

    expect(screen.getByRole('button', { name: 'CB: Ana Keeper #1' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'LM: Ben Back #2' })).toBeInTheDocument();

    fireEvent.click(slot('CB: Ana Keeper'));
    fireEvent.click(slot('LM: Ben Back'));
    expect(screen.getByRole('button', { name: 'CB: Ben Back #2' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'LM: Ana Keeper #1' })).toBeInTheDocument();

    // Player first: Eli from the bench into the empty CF slot.
    fireEvent.click(player(5));
    fireEvent.click(slot('CF slot, empty'));
    expect(screen.getByRole('button', { name: 'CF: Eli Mid #6' })).toBeInTheDocument();
    expect(screen.getByTestId('field-count')).toHaveTextContent('3/4 on field');
  });

  it('the count guard: with every slot filled, tapping a bench player asks for a swap rather than adding a fifth', async () => {
    const full = {
      ...savedLineup,
      slots: [
        { athlete_id: 1, slot: 'D1', sort_order: 0, captain: false, note: null },
        { athlete_id: 2, slot: 'M1', sort_order: 0, captain: false, note: null },
        { athlete_id: 3, slot: 'M2', sort_order: 0, captain: false, note: null },
        { athlete_id: 4, slot: 'F1', sort_order: 0, captain: false, note: null },
        { athlete_id: 5, slot: 'BENCH', sort_order: 1, captain: false, note: null },
      ],
    };
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse({ lineup: full }));
    render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');
    expect(screen.getByTestId('field-count')).toHaveTextContent('4/4 on field');

    fireEvent.click(player(5));
    expect(screen.getByRole('status')).toHaveTextContent('All 4 field slots are filled');
    expect(screen.getByTestId('field-count')).toHaveTextContent('4/4 on field');

    // The swap the notice asks for: tap an occupied slot, Eli goes in, Dee comes out.
    fireEvent.click(slot('CF: Dee Defender'));
    expect(screen.getByRole('button', { name: 'CF: Eli Mid #6' })).toBeInTheDocument();
    expect(screen.getByTestId('field-count')).toHaveTextContent('4/4 on field');
    expect(screen.getByTestId('player-4')).toBeInTheDocument();
  });

  it('absent and excused players are greyed with the reason, and start off the sheet; injured players are badged', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse());
    render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');

    const absent = player(3);
    expect(absent).toHaveClass('opacity-50');
    expect(absent).toHaveTextContent('absent');
    expect(player(4)).toHaveTextContent('excused');
    // A fresh sheet benches only those here and fit.
    expect(screen.getByText(/Bench \(3\)/)).toBeInTheDocument();
    expect(screen.getByText(/Not on the sheet \(3\)/)).toBeInTheDocument();
    expect(player(6)).toHaveTextContent('injured');
    expect(player(1)).not.toHaveClass('opacity-50');
  });

  it('Publish to crew appears only once a lineup is saved for this game, and toggles to Unpublish', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse());
    const { unmount } = render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');
    expect(screen.queryByRole('button', { name: 'Publish to crew' })).not.toBeInTheDocument();
    unmount();

    // A template fallback is not a saved game lineup either.
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse({ lineup: { ...savedLineup, calendar_event_id: null, is_template: true }, is_template: true, has_template: true }));
    const r2 = render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');
    expect(screen.getByText(/Starting from your default/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Publish to crew' })).not.toBeInTheDocument();
    r2.unmount();

    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse({ lineup: savedLineup }));
    render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');
    const publishBtn = screen.getByRole('button', { name: 'Publish to crew' });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ success: true, lineup: { ...savedLineup, published_at: '2026-09-10 12:00:00' } }),
    });
    fireEvent.click(publishBtn);
    await waitFor(() => expect(screen.getByRole('button', { name: 'Unpublish' })).toBeInTheDocument());
    const call = (global.fetch as jest.Mock).mock.calls.slice(-1)[0];
    expect(call[0]).toBe(`${API}/api/lineups.php?action=publish`);
    expect(JSON.parse(call[1].body)).toEqual({ team_id: 10, event_id: 501 });
    expect(screen.getByText('Published to families')).toBeInTheDocument();
  });

  it('Save posts the field slots and the bench in order, with the captain', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse({ lineup: savedLineup }));
    render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true, status: 200, json: async () => ({ success: true, lineup: savedLineup, warnings: ['Jon Injured is marked injured and is on the bench'] }),
    });
    fireEvent.click(screen.getByRole('button', { name: /^Saved?$/ }));
    await waitFor(() => expect(screen.getByText(/Jon Injured is marked injured/)).toBeInTheDocument());

    const body = JSON.parse((global.fetch as jest.Mock).mock.calls[1][1].body);
    expect(body.team_id).toBe(10);
    expect(body.event_id).toBe(501);
    expect(body.formation).toBe('1-2-1');
    expect(body.field_size).toBe('4v4');
    expect(body.slots).toEqual(expect.arrayContaining([
      expect.objectContaining({ athlete_id: 1, slot: 'D1', captain: false }),
      expect.objectContaining({ athlete_id: 2, slot: 'M1', captain: true }),
      expect.objectContaining({ athlete_id: 5, slot: 'BENCH', sort_order: 1 }),
    ]));
    expect(body.slots).toHaveLength(3);
  });

  it('a refusal from the server is shown as its sentence, not swallowed', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(getResponse({ lineup: savedLineup }));
    render(<LineupBuilder teamId={10} eventId={501} apiUrl={API} />);
    await screen.findByText('U8 Blue');
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false, status: 422, json: async () => ({ success: false, error: 'Jon Injured is marked injured — move them to the bench or update the roster' }),
    });
    fireEvent.click(screen.getByRole('button', { name: /^Saved?$/ }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Jon Injured is marked injured'));
  });
});

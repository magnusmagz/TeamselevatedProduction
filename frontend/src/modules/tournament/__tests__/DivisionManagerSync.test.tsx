import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import DivisionManager from '../components/DivisionManager';

const mockFetch = jest.fn();
global.fetch = mockFetch;

// The Register Team division dropdown reads tournament.divisions, which is
// loaded once when the page mounts. DivisionManager owns the live list, so it
// has to hand it back or divisions created here stay invisible to every other
// tab until a full reload.
describe('DivisionManager', () => {
  beforeEach(() => jest.clearAllMocks());

  test('reports the loaded divisions back to the parent', async () => {
    const divisions = [
      { id: 10, name: 'U10', age_group: 'U10', gender: 'boys', format: 'round_robin',
        game_duration_minutes: 40, half_duration_minutes: 20, min_roster_size: 5,
        max_roster_size: 10, tournament_id: 7 },
    ];
    mockFetch.mockResolvedValue({ ok: true, json: async () => ({ divisions }) });

    const onDivisionsChange = jest.fn();
    render(
      <DivisionManager tournamentId={7} sport="soccer" isAdmin={true} onDivisionsChange={onDivisionsChange} />
    );

    await waitFor(() => expect(screen.getByText('U10')).toBeInTheDocument());
    expect(onDivisionsChange).toHaveBeenCalledWith(divisions);
  });

  test('renders without a parent callback', async () => {
    mockFetch.mockResolvedValue({ ok: true, json: async () => ({ divisions: [] }) });
    render(<DivisionManager tournamentId={7} sport="soccer" isAdmin={true} />);
    await waitFor(() => expect(screen.getByText(/No divisions yet/)).toBeInTheDocument());
  });
});

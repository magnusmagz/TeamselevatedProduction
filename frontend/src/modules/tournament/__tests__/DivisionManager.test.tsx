import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import DivisionManager from '../components/DivisionManager';

// Mock fetch
const mockFetch = jest.fn();
global.fetch = mockFetch;

describe('DivisionManager', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test('renders division list with format badges', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        divisions: [
          {
            id: 1,
            name: 'U12 Boys Gold',
            age_group: 'U12',
            gender: 'boys',
            format: 'group_knockout',
            game_duration_minutes: 60,
            half_duration_minutes: 30,
            max_roster_size: 18,
            min_roster_size: 11,
            max_players_on_field: 9,
            sport_rule_notes: ['No heading (U11)', 'Size 4 ball'],
            registration_count: 8,
            group_count: 2,
            tiebreaker_rules: ['points', 'head_to_head', 'goal_difference'],
          },
          {
            id: 2,
            name: 'U14 Girls',
            age_group: 'U14',
            gender: 'girls',
            format: 'round_robin',
            game_duration_minutes: 70,
            half_duration_minutes: 35,
            max_roster_size: 22,
            min_roster_size: 11,
            max_players_on_field: 11,
            sport_rule_notes: null,
            registration_count: 4,
            group_count: 1,
            tiebreaker_rules: ['points', 'goal_difference'],
          },
        ],
      }),
    });

    render(<DivisionManager tournamentId={1} sport="soccer" isAdmin={true} />);

    await waitFor(() => {
      expect(screen.getByText('U12 Boys Gold')).toBeInTheDocument();
    });

    expect(screen.getByText('U14 Girls')).toBeInTheDocument();
    expect(screen.getByText('Group + Knockout')).toBeInTheDocument();
    expect(screen.getByText('Round Robin')).toBeInTheDocument();
  });

  test('shows rule note chips when present', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        divisions: [
          {
            id: 1,
            name: 'U12 Boys',
            age_group: 'U12',
            gender: 'boys',
            format: 'group_knockout',
            game_duration_minutes: 60,
            half_duration_minutes: 30,
            max_roster_size: 18,
            min_roster_size: 11,
            max_players_on_field: 9,
            sport_rule_notes: ['No heading (U11)', 'Size 4 ball'],
            registration_count: 0,
            group_count: 0,
            tiebreaker_rules: ['points'],
          },
        ],
      }),
    });

    render(<DivisionManager tournamentId={1} sport="soccer" isAdmin={true} />);

    await waitFor(() => {
      expect(screen.getByText('No heading (U11)')).toBeInTheDocument();
    });
    expect(screen.getByText('Size 4 ball')).toBeInTheDocument();
  });
});

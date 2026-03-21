import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import RegistrationManager from '../components/RegistrationManager';

const mockFetch = jest.fn();
global.fetch = mockFetch;

const mockDivisions = [
  { id: 1, name: 'U12 Boys', age_group: 'U12', gender: 'boys' as const, format: 'group_knockout' as const,
    game_duration_minutes: 60, half_duration_minutes: 30, max_roster_size: 18, min_roster_size: 11,
    max_teams: 16, teams_per_group: 4, teams_advancing_per_group: 2, goal_differential_cap: null,
    tiebreaker_rules: ['points'], points_for_win: 3, points_for_draw: 1, points_for_loss: 0,
    max_players_on_field: 9, sport_rule_notes: null, overtime_rules: null, sort_order: 0,
    tournament_id: 1, created_at: '', updated_at: '' },
];

describe('RegistrationManager', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test('renders registration list with status badges', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        registrations: [
          {
            id: 1, display_name: 'FC Lightning U12', division_name: 'U12 Boys',
            status: 'pending', payment_status: 'unpaid', payment_amount_cents: 50000,
            registered_by_name: 'Jane Admin', club_name_override: null,
          },
          {
            id: 2, display_name: 'Thunder SC', division_name: 'U12 Boys',
            status: 'accepted', payment_status: 'paid', payment_amount_cents: 50000,
            registered_by_name: 'Coach Bob', club_name_override: 'Thunder Club',
          },
        ],
        counts: { pending: 1, accepted: 1 },
      }),
    });

    render(
      <RegistrationManager
        tournamentId={1}
        divisions={mockDivisions}
        isAdmin={true}
        clubId={47}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('FC Lightning U12')).toBeInTheDocument();
    });

    expect(screen.getByText('Thunder SC')).toBeInTheDocument();
    expect(screen.getByText('Thunder Club')).toBeInTheDocument();
    expect(screen.getByText('pending')).toBeInTheDocument();
    expect(screen.getByText('accepted')).toBeInTheDocument();
  });

  test('accept/reject buttons shown for pending registrations', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        registrations: [
          {
            id: 1, display_name: 'FC Lightning U12', division_name: 'U12 Boys',
            status: 'pending', payment_status: 'unpaid', payment_amount_cents: 50000,
            registered_by_name: 'Jane Admin', club_name_override: null,
          },
        ],
        counts: { pending: 1 },
      }),
    });

    render(
      <RegistrationManager
        tournamentId={1}
        divisions={mockDivisions}
        isAdmin={true}
        clubId={47}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Accept')).toBeInTheDocument();
    });
    expect(screen.getByText('Reject')).toBeInTheDocument();
  });
});

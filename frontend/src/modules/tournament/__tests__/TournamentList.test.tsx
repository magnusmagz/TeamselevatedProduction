import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import TournamentList from '../pages/TournamentList';
import { useNavigate } from 'react-router-dom';

// Mock auth context
jest.mock('../../../contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 118, system_role: 'user', organization: { orgId: 47 } },
  }),
}));

// Mock org context
jest.mock('../../../contexts/OrgContext', () => ({
  useOrg: () => ({ isClubAdmin: true, currentClubId: 47 }),
}));

// Mock fetch
const mockFetch = jest.fn();
global.fetch = mockFetch;

describe('TournamentList', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test('renders list of tournaments with status badges', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        tournaments: [
          {
            id: 1,
            name: 'Spring Classic 2026',
            status: 'draft',
            start_date: '2026-05-15',
            end_date: '2026-05-17',
            location_name: 'Memorial Park',
            entry_fee_cents: 50000,
            division_count: 4,
            registration_count: 12,
            sport: 'soccer',
          },
          {
            id: 2,
            name: 'Fall Cup',
            status: 'in_progress',
            start_date: '2026-09-10',
            end_date: '2026-09-12',
            location_name: 'Central Fields',
            entry_fee_cents: 0,
            division_count: 2,
            registration_count: 8,
            sport: 'soccer',
          },
        ],
      }),
    });

    render(<TournamentList />);

    await waitFor(() => {
      expect(screen.getByText('Spring Classic 2026')).toBeInTheDocument();
    });

    expect(screen.getByText('Fall Cup')).toBeInTheDocument();
    // Status badges render as chips on each card
    expect(screen.getAllByText('Draft').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('In Progress').length).toBeGreaterThanOrEqual(1);
  });

  test('shows empty state with create CTA when no tournaments', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ tournaments: [] }),
    });

    render(<TournamentList />);

    await waitFor(() => {
      expect(screen.getByText('No tournaments yet')).toBeInTheDocument();
    });

    expect(screen.getByText('Create your first tournament to get started.')).toBeInTheDocument();
    const createLinks = screen.getAllByText('Create Tournament');
    expect(createLinks.length).toBeGreaterThanOrEqual(1);
  });
});

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import TournamentCreate from '../pages/TournamentCreate';
import { useNavigate, useParams } from 'react-router-dom';

// Mock auth context
jest.mock('../../../contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 118, system_role: 'user', organization: { orgId: 47 } },
  }),
}));

jest.mock('../../../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 47, activeContext: { scope_id: 47 } }),
}));

// Mock fetch
const mockFetch = jest.fn();
global.fetch = mockFetch;

describe('TournamentCreate', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // Re-set useParams mock after clearAllMocks
    (useParams as jest.Mock).mockReturnValue({});
  });

  test('validates required fields before submission', async () => {
    render(<TournamentCreate />);

    const submitButton = screen.getByRole('button', { name: /Create Tournament/i });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText('Tournament name is required')).toBeInTheDocument();
    });
    expect(screen.getByText('Start date is required')).toBeInTheDocument();
    expect(screen.getByText('End date is required')).toBeInTheDocument();

    // fetch should NOT have been called
    expect(mockFetch).not.toHaveBeenCalled();
  });

  test('successful create navigates to detail page', async () => {
    const mockNav = jest.fn();
    (useNavigate as jest.Mock).mockReturnValue(mockNav);

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ id: 5, message: 'Tournament created successfully' }),
    });

    render(<TournamentCreate />);

    // Fill required fields
    fireEvent.change(screen.getByPlaceholderText('Spring Classic 2026'), {
      target: { value: 'Test Tournament', name: 'name' },
    });

    // Find date inputs by their name attribute
    const inputs = document.querySelectorAll('input');
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput = document.querySelector('input[name="end_date"]');

    if (startInput) fireEvent.change(startInput, { target: { value: '2026-06-01', name: 'start_date' } });
    if (endInput) fireEvent.change(endInput, { target: { value: '2026-06-03', name: 'end_date' } });

    const submitButton = screen.getByRole('button', { name: /Create Tournament/i });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(mockNav).toHaveBeenCalledWith('/tournaments/5');
    });
  });
});

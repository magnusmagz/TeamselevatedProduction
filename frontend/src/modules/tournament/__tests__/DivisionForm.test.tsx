import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import DivisionForm from '../components/DivisionForm';

// Mock fetch
const mockFetch = jest.fn();
global.fetch = mockFetch;

describe('DivisionForm', () => {
  const mockOnSave = jest.fn();
  const mockOnCancel = jest.fn();

  beforeEach(() => {
    jest.clearAllMocks();
    // Mock sport presets fetch
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        presets: [
          {
            id: 1, sport: 'soccer', age_group: 'U10',
            game_duration_minutes: 50, half_duration_minutes: 25,
            max_roster_size: 14, min_roster_size: 7,
            max_players_on_field: 7,
            rule_notes: ['No heading', 'Build-out line', 'Size 4 ball'],
          },
          {
            id: 2, sport: 'soccer', age_group: 'U12',
            game_duration_minutes: 60, half_duration_minutes: 30,
            max_roster_size: 18, min_roster_size: 11,
            max_players_on_field: 9,
            rule_notes: ['No heading (U11)', 'Size 4 ball'],
          },
        ],
      }),
    });
  });

  test('selecting age group auto-fills duration and roster fields', async () => {
    render(
      <DivisionForm
        tournamentId={1}
        sport="soccer"
        division={null}
        onSave={mockOnSave}
        onCancel={mockOnCancel}
      />
    );

    // Wait for presets to load
    await waitFor(() => {
      expect(mockFetch).toHaveBeenCalled();
    });

    // Select U10 age group
    const ageSelect = screen.getByDisplayValue('Select...');
    fireEvent.change(ageSelect, { target: { value: 'U10' } });

    // Verify auto-filled values
    await waitFor(() => {
      const gameDurationInput = document.querySelector('input[value="50"]');
      expect(gameDurationInput).toBeInTheDocument();
    });

    // Check roster auto-filled
    const maxRosterInput = document.querySelector('input[value="14"]');
    expect(maxRosterInput).toBeInTheDocument();
  });

  test('format change shows/hides group-specific fields', async () => {
    render(
      <DivisionForm
        tournamentId={1}
        sport="soccer"
        division={null}
        onSave={mockOnSave}
        onCancel={mockOnCancel}
      />
    );

    await waitFor(() => {
      expect(mockFetch).toHaveBeenCalled();
    });

    // Default format is group_knockout — should show "Teams per Group"
    expect(screen.getByText('Teams per Group')).toBeInTheDocument();

    // Switch to single_elimination — should hide group fields
    const formatSelect = screen.getByDisplayValue('Group Stage + Knockout');
    fireEvent.change(formatSelect, { target: { value: 'single_elimination' } });

    expect(screen.queryByText('Teams per Group')).not.toBeInTheDocument();
  });

  test('tiebreaker list is reorderable', async () => {
    render(
      <DivisionForm
        tournamentId={1}
        sport="soccer"
        division={null}
        onSave={mockOnSave}
        onCancel={mockOnCancel}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Tiebreaker Order')).toBeInTheDocument();
    });

    // Verify tiebreaker items render with position numbers
    expect(screen.getByText('Points')).toBeInTheDocument();
    expect(screen.getByText('Head-to-Head')).toBeInTheDocument();
    expect(screen.getByText('Goal Difference')).toBeInTheDocument();

    // Find all down arrow buttons (▼ unicode char)
    const allButtons = screen.getAllByRole('button');
    const downButtons = allButtons.filter((btn) => btn.textContent === '\u25BC');
    expect(downButtons.length).toBeGreaterThan(0);

    // Click first down button to move "Points" down
    fireEvent.click(downButtons[0]);

    // After reorder, get all numbered items - Head-to-Head should now be at position 1
    const firstPositionEl = screen.getByText('1.');
    const firstItem = firstPositionEl.parentElement;
    expect(firstItem?.textContent).toContain('Head-to-Head');
  });
});

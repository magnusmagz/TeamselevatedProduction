import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import RegistrationForm from '../components/RegistrationForm';
import { TournamentDivision } from '../types';

const mockFetch = jest.fn();
global.fetch = mockFetch;

const division = {
  id: 1, name: 'U12', age_group: 'U12', gender: 'girls', format: 'round_robin',
  tournament_id: 7,
} as unknown as TournamentDivision;

const teams = [
  { id: 1, name: 'Lightning', age_group: 'U12', gender: 'girls' },
  { id: 2, name: 'Thunder', age_group: 'U12', gender: 'boys' },
  { id: 3, name: 'Comets', age_group: 'U10', gender: 'girls' },
];

function renderForm(divisions: TournamentDivision[] = [division]) {
  return render(
    <RegistrationForm
      tournamentId={7}
      divisions={divisions}
      clubId={51}
      isAdmin={true}
      registrationOpenDate={null}
      onSave={() => {}}
      onCancel={() => {}}
    />
  );
}

describe('RegistrationForm team picker', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockFetch.mockResolvedValue({ ok: true, json: async () => ({ teams }) });
  });

  test('shows each team gender alongside its age group', async () => {
    renderForm();
    await waitFor(() => expect(screen.getByText('Lightning')).toBeInTheDocument());
    // Scoped to the badge, since the gender filter renders the same labels.
    expect(screen.getAllByText('Girls', { selector: 'span' })).toHaveLength(2);
    expect(screen.getByText('Boys', { selector: 'span' })).toBeInTheDocument();
  });

  test('filters by gender and by age group', async () => {
    renderForm();
    await waitFor(() => expect(screen.getByText('Lightning')).toBeInTheDocument());

    fireEvent.change(screen.getByLabelText('Filter by gender'), { target: { value: 'girls' } });
    expect(screen.queryByText('Thunder')).not.toBeInTheDocument();
    expect(screen.getByText('Comets')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Filter by age group'), { target: { value: 'U12' } });
    expect(screen.queryByText('Comets')).not.toBeInTheDocument();
    expect(screen.getByText('Lightning')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Clear filters'));
    expect(screen.getByText('Thunder')).toBeInTheDocument();
  });

  test('gender options come from the teams returned, not a fixed list', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => ({ teams: [{ id: 9, name: 'Mixed FC', age_group: 'U10', gender: 'Mixed' }] }),
    });
    renderForm();
    await waitFor(() => expect(screen.getByText('Mixed FC')).toBeInTheDocument());

    const genderSelect = screen.getByLabelText('Filter by gender') as HTMLSelectElement;
    expect(Array.from(genderSelect.options).map((o) => o.value)).toEqual(['', 'Mixed']);
  });

  test('says why there is nothing to pick when the tournament has no divisions', async () => {
    renderForm([]);
    await waitFor(() => expect(screen.getByText('Lightning')).toBeInTheDocument());
    expect(screen.queryByText('Select division...')).not.toBeInTheDocument();
    expect(screen.getByText(/No divisions have been added/)).toBeInTheDocument();
  });
});

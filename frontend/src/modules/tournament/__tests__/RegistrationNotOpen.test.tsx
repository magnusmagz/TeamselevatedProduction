import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import RegistrationManager from '../components/RegistrationManager';
import { TournamentDivision, TournamentStatus } from '../types';

const mockFetch = jest.fn();
global.fetch = mockFetch;

const divisions = [
  { id: 10, name: 'U10', age_group: 'U10', gender: 'boys', format: 'round_robin', tournament_id: 7 },
] as unknown as TournamentDivision[];

function renderManager(opts: {
  divisions?: TournamentDivision[];
  status?: TournamentStatus;
  registrationOpenDate?: string | null;
}) {
  return render(
    <RegistrationManager
      tournamentId={7}
      divisions={opts.divisions ?? divisions}
      isAdmin={true}
      clubId={51}
      status={opts.status}
      registrationOpenDate={opts.registrationOpenDate ?? null}
    />
  );
}

describe('RegistrationManager registration-state helper', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockFetch.mockResolvedValue({ ok: true, json: async () => ({ registrations: [], counts: {} }) });
  });

  test('with no divisions it says so and disables Register Team', async () => {
    renderManager({ divisions: [] });
    await waitFor(() => expect(screen.getByText(/No divisions yet/)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Register Team' })).toBeDisabled();
  });

  test('a draft tournament explains that teams cannot sign up yet', async () => {
    renderManager({ status: 'draft' });
    await waitFor(() => expect(screen.getByText(/still a draft/)).toBeInTheDocument());
    // Not a block — an admin can still add teams by hand.
    expect(screen.getByRole('button', { name: 'Register Team' })).toBeEnabled();
  });

  test('an open tournament shows no helper', async () => {
    renderManager({ status: 'registration_open' });
    await waitFor(() => expect(screen.getByText(/Registrations \(/)).toBeInTheDocument());
    expect(screen.queryByText(/still a draft/)).not.toBeInTheDocument();
    expect(screen.queryByText(/No divisions yet/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Registration opens/)).not.toBeInTheDocument();
  });

  test('before the open date it points at the waitlist rather than the status', async () => {
    const future = new Date(Date.now() + 7 * 24 * 3600 * 1000).toISOString();
    renderManager({ status: 'registration_open', registrationOpenDate: future });
    await waitFor(() => expect(screen.getByText(/Registration opens/)).toBeInTheDocument());
  });
});

import React from 'react';
import { render, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

// CA-96 regression guard: ProgramManagement must fetch programs scoped to the
// ACTIVE club (useOrg().currentClubId), never the old hardcoded `club_id=1`.

// --- Mock the active-club source ---
let mockCurrentClubId: number | null = 32;
jest.mock('../../../../contexts/OrgContext', () => ({
  useOrg: () => ({
    currentClubId: mockCurrentClubId,
    activeContext: mockCurrentClubId
      ? { role: 'club_admin', scope_type: 'club', scope_id: mockCurrentClubId, scope_name: 'Active Club' }
      : null,
    availableContexts: [],
    switchToContext: jest.fn(),
    isClubAdmin: true,
  }),
}));

// --- Mock useAuth (page reads user but does not depend on its shape here) ---
jest.mock('../../../../hooks/useAuth', () => ({
  useAuth: () => ({ user: { id: 50, roles: [] } }),
}));

// --- Stub heavy child components so the test isolates the fetch behavior ---
jest.mock('../../components/ProgramFormBuilder', () => () => null);
jest.mock('../../components/EmbedCodeModal', () => () => null);
jest.mock('../../components/RegistrationsModal', () => () => null);
jest.mock('../../components/TryoutCreationWizard', () => () => null);
jest.mock('../../components/TryoutManagement', () => () => null);

import ProgramManagement from '../ProgramManagement';

describe('ProgramManagement club scoping (CA-96)', () => {
  beforeEach(() => {
    mockCurrentClubId = 32;
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => [],
    }) as jest.Mock;
  });

  afterEach(() => {
    jest.clearAllMocks();
  });

  const firstFetchUrl = (): string => {
    const calls = (global.fetch as jest.Mock).mock.calls;
    expect(calls.length).toBeGreaterThan(0);
    return calls[0][0] as string;
  };

  it('fetches programs-api with the active club id, not club_id=1', async () => {
    render(<ProgramManagement />);

    await waitFor(() => {
      expect(global.fetch).toHaveBeenCalled();
    });

    const url = firstFetchUrl();
    expect(url).toContain('programs-api.php');
    expect(url).toContain('club_id=32');
    expect(url).not.toContain('club_id=1');
  });

  it('does not call programs-api when no active club is resolved', async () => {
    mockCurrentClubId = null;

    render(<ProgramManagement />);

    // Give the effect a chance to run; it should bail out without fetching.
    await new Promise((r) => setTimeout(r, 0));

    expect(global.fetch).not.toHaveBeenCalled();
  });
});

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import VolunteerManagement from './VolunteerManagement';

// CA-114: volunteer dashboard — totals, background-check status, per-team compliance.

jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 50, email: 'admin@example.com' } }),
}));

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ isClubAdmin: true, currentClubId: 100 }),
}));

global.fetch = jest.fn();

const COMPLIANCE = {
  success: true,
  summary: {
    total_volunteers: 3,
    cleared: 1,
    pending: 1,
    expired: 1,
    never_checked: 0,
    active_count: 3,
    compliance_rate: 33.3,
    pending_signups: 2,
  },
  team_breakdown: [
    {
      team_id: 10,
      team_name: 'Team Ten',
      age_group: 'U12',
      division: 'A',
      volunteer_count: 2,
      cleared: 1,
      pending_bg: 1,
      expired_bg: 0,
      compliance_rate: 50,
    },
    {
      team_id: 11,
      team_name: 'Team Eleven',
      age_group: 'U14',
      division: 'B',
      volunteer_count: 1,
      cleared: 0,
      pending_bg: 0,
      expired_bg: 1,
      compliance_rate: 0,
    },
  ],
  needs_attention: [],
};

const VOLUNTEERS = {
  success: true,
  volunteers: [
    {
      id: 1,
      user_id: 50,
      first_name: 'Jane',
      last_name: 'Doe',
      email: 'jane@example.com',
      phone: '5551234567',
      team_id: 10,
      team_name: 'Team Ten',
      background_check_status: 'cleared',
      start_date: '2026-01-01',
      end_date: null,
      status: 'active',
      notes: '',
    },
  ],
};

function installFetch() {
  (fetch as jest.Mock).mockImplementation((url: string) => {
    if (url.includes('action=compliance')) {
      return Promise.resolve({ ok: true, json: async () => COMPLIANCE });
    }
    if (url.includes('action=club-volunteers')) {
      return Promise.resolve({ ok: true, json: async () => VOLUNTEERS });
    }
    if (url.includes('action=available-teams')) {
      return Promise.resolve({
        ok: true,
        json: async () => ({ success: true, teams: [{ id: 10, name: 'Team Ten' }, { id: 11, name: 'Team Eleven' }] }),
      });
    }
    return Promise.resolve({ ok: true, json: async () => ({ success: true }) });
  });
}

describe('VolunteerManagement dashboard (CA-114)', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
    localStorage.setItem('auth_token', 'test-token');
    installFetch();
  });

  test('renders total / pending-signups / bg-check metric cards', async () => {
    render(<VolunteerManagement />);

    await waitFor(() => expect(screen.getByText('Total Active Volunteers')).toBeInTheDocument());

    expect(screen.getByText('Pending Signups')).toBeInTheDocument();
    expect(screen.getByText('BG Checks Cleared')).toBeInTheDocument();
    // active_count = 3
    const totalCard = screen.getByText('Total Active Volunteers').closest('div') as HTMLElement;
    expect(within(totalCard).getByText('3')).toBeInTheDocument();
    // pending_signups = 2
    const pendingCard = screen.getByText('Pending Signups').closest('div') as HTMLElement;
    expect(within(pendingCard).getByText('2')).toBeInTheDocument();
  });

  test('renders the volunteer table with per-volunteer background-check status', async () => {
    render(<VolunteerManagement />);
    await waitFor(() => expect(screen.getByText('Jane Doe')).toBeInTheDocument());
    expect(screen.getByText('jane@example.com')).toBeInTheDocument();
    // bg badge "Cleared" appears for the volunteer row
    expect(screen.getAllByText('Cleared').length).toBeGreaterThan(0);
  });

  test('renders the per-team compliance breakdown (CA-114 core gap)', async () => {
    render(<VolunteerManagement />);

    await waitFor(() => expect(screen.getByText('Per-Team Compliance')).toBeInTheDocument());

    const heading = screen.getByText('Per-Team Compliance');
    const section = heading.closest('div')?.parentElement as HTMLElement;

    // Both teams from team_breakdown render with their compliance rates.
    expect(within(section).getByText('Team Ten')).toBeInTheDocument();
    expect(within(section).getByText('Team Eleven')).toBeInTheDocument();
    expect(within(section).getByText('50%')).toBeInTheDocument();
    expect(within(section).getByText('0%')).toBeInTheDocument();
  });
});

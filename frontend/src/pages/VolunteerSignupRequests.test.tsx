import React from 'react';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import VolunteerSignupRequests from './VolunteerSignupRequests';

// CA-115: signup-requests page — approve/reject, bulk, and filters.

jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 50, email: 'admin@example.com' } }),
}));

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ isClubAdmin: true, currentClubId: 100 }),
}));

global.fetch = jest.fn();

const TEAMS = [{ id: 10, name: 'Team Ten' }];

const makeSignup = (overrides: Partial<any> = {}) => ({
  id: 1,
  team_id: 10,
  user_id: 50,
  requested_at: '2026-05-01T10:00:00Z',
  status: 'pending',
  notes: null,
  first_name: 'Jane',
  last_name: 'Doe',
  email: 'jane@example.com',
  phone: '5551234567',
  team_name: 'Team Ten',
  background_check_status: 'cleared',
  ...overrides,
});

// Route fetch calls by URL. `signupsByCall` lets a test return different
// signup payloads on successive team-signups fetches (e.g. after a refetch).
function installFetch(signups: any[] | (() => any[])) {
  (fetch as jest.Mock).mockImplementation((url: string, init?: any) => {
    // /api/teams returns a BARE ARRAY (the shape that previously broke admins).
    if (url.includes('/api/teams') && !url.includes('volunteer-gateway')) {
      return Promise.resolve({ ok: true, json: async () => TEAMS });
    }
    if (url.includes('action=team-signups')) {
      const list = typeof signups === 'function' ? signups() : signups;
      return Promise.resolve({ ok: true, json: async () => ({ success: true, signups: list }) });
    }
    if (url.includes('action=review-signups-bulk')) {
      return Promise.resolve({
        ok: true,
        json: async () => ({ success: true, approved: 1, rejected: 0, skipped: [] }),
      });
    }
    if (url.includes('action=review-signup')) {
      return Promise.resolve({ ok: true, json: async () => ({ success: true }) });
    }
    return Promise.resolve({ ok: true, json: async () => ({ success: true, signups: [] }) });
  });
}

describe('VolunteerSignupRequests (CA-115)', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
    localStorage.setItem('auth_token', 'test-token');
  });

  test('loads requests for admin even though /api/teams returns a bare array', async () => {
    // Regression guard: previously data.teams was undefined for a bare array,
    // leaving the admin "all teams" view stuck on its loading guard.
    installFetch([makeSignup()]);
    render(<VolunteerSignupRequests />);

    await waitFor(() => expect(screen.getByText('Jane Doe')).toBeInTheDocument());

    // The team filter dropdown got populated from the bare array.
    const teamSelect = screen.getByLabelText('Team') as HTMLSelectElement;
    expect(within(teamSelect).getByText('Team Ten')).toBeInTheDocument();
  });

  test('approve sends review-signup with decision=approved', async () => {
    installFetch([makeSignup({ background_check_status: 'cleared' })]);
    render(<VolunteerSignupRequests />);

    await waitFor(() => expect(screen.getByText('Jane Doe')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /^Approve$/i }));

    await waitFor(() => {
      const call = (fetch as jest.Mock).mock.calls.find(
        (c) => typeof c[0] === 'string' && c[0].includes('action=review-signup&')
      );
      expect(call).toBeTruthy();
      expect(JSON.parse(call[1].body).decision).toBe('approved');
    });
  });

  test('approve button is disabled when background check not cleared', async () => {
    installFetch([makeSignup({ background_check_status: 'pending' })]);
    render(<VolunteerSignupRequests />);

    await waitFor(() => expect(screen.getByText('Jane Doe')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /^Approve$/i })).toBeDisabled();
  });

  test('background-check filter sends bg_status query param', async () => {
    installFetch([makeSignup()]);
    render(<VolunteerSignupRequests />);

    await waitFor(() => expect(screen.getByText('Jane Doe')).toBeInTheDocument());

    fireEvent.change(screen.getByLabelText(/Background Check/i), {
      target: { value: 'cleared' },
    });

    await waitFor(() => {
      const call = (fetch as jest.Mock).mock.calls.find(
        (c) => typeof c[0] === 'string' && c[0].includes('bg_status=cleared')
      );
      expect(call).toBeTruthy();
    });
  });

  test('status tab change refetches with the new status param', async () => {
    installFetch([makeSignup()]);
    render(<VolunteerSignupRequests />);

    await waitFor(() => expect(screen.getByText('Jane Doe')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /^Approved$/i }));

    await waitFor(() => {
      const call = (fetch as jest.Mock).mock.calls.find(
        (c) => typeof c[0] === 'string' && c[0].includes('status=approved')
      );
      expect(call).toBeTruthy();
    });
  });

  test('bulk approve selects rows and posts review-signups-bulk', async () => {
    installFetch([
      makeSignup({ id: 1, first_name: 'Jane', background_check_status: 'cleared' }),
      makeSignup({ id: 2, first_name: 'John', email: 'john@example.com', background_check_status: 'cleared' }),
    ]);
    render(<VolunteerSignupRequests />);

    await waitFor(() => expect(screen.getByText('Jane Doe')).toBeInTheDocument());

    // Select all pending via the header checkbox.
    fireEvent.click(screen.getByLabelText('Select all pending requests'));

    // Bulk bar appears with both selected.
    const bar = await screen.findByTestId('bulk-action-bar');
    expect(within(bar).getByText('2 selected')).toBeInTheDocument();

    fireEvent.click(within(bar).getByRole('button', { name: /Approve Selected/i }));

    await waitFor(() => {
      const call = (fetch as jest.Mock).mock.calls.find(
        (c) => typeof c[0] === 'string' && c[0].includes('action=review-signups-bulk')
      );
      expect(call).toBeTruthy();
      const body = JSON.parse(call[1].body);
      expect(body.decision).toBe('approved');
      expect(body.signup_ids.sort()).toEqual([1, 2]);
    });
  });
});

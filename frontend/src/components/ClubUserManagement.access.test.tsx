import React from 'react';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import ClubUserManagement from './ClubUserManagement';

/**
 * Club Settings -> Users: the per-row access control (Invite / Resend invite /
 * Send login link / Set password) lives HERE, not on the Coaches page (Maggie,
 * 2026-09-06). Staff roles get it; a parent row does not — crew are managed on
 * the Crew page. The Set-password modal shows the password once.
 */

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ activeContext: { scope_id: 51, scope_type: 'club' } }),
}));

const users = [
  { id: 1, user_id: 11, first_name: 'Ada', last_name: 'Admin', email: 'ada@club.test', role: 'club_admin', active: true, granted_at: '', status: 'active' },
  { id: 2, user_id: 12, first_name: 'Cal', last_name: 'Coach', email: 'cal@club.test', role: 'coach', active: true, granted_at: '', status: 'not_invited' },
  { id: 3, user_id: 13, first_name: 'Tia', last_name: 'Treasurer', email: 'tia@club.test', role: 'treasurer', active: true, granted_at: '', status: 'invited' },
  { id: 4, user_id: 14, first_name: 'Val', last_name: 'Volunteer', email: 'val@club.test', role: 'volunteer', active: true, granted_at: '', status: 'invite_expired' },
  { id: 5, user_id: 15, first_name: 'Pam', last_name: 'Parent', email: 'pam@club.test', role: 'parent', active: true, granted_at: '', status: 'active' },
];

const json = (body: any, status = 200) => ({ ok: status < 300, status, json: () => Promise.resolve(body) });

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  (global as any).fetch = jest.fn((url: any, init?: any) => {
    const u = String(url);
    if (u.includes('club-users-gateway.php') && (!init || !init.method)) {
      return Promise.resolve(json({ success: true, users }));
    }
    if (u.includes('coach-access.php?action=set-temporary-password')) {
      return Promise.resolve(json({ success: true, email: 'cal@club.test' }));
    }
    return Promise.reject(new Error(`unexpected fetch: ${u}`));
  });
  Object.assign(navigator, { clipboard: { writeText: jest.fn(() => Promise.resolve()) } });
});

const row = async (name: string) => within((await screen.findByText(name)).closest('tr') as HTMLElement);

describe('ClubUserManagement access controls', () => {
  test('one context-aware control per staff row, none on a parent row', async () => {
    render(<ClubUserManagement />);
    await waitFor(() => expect(screen.queryByText(/loading users/i)).toBeNull());

    expect((await row('Ada Admin')).getByRole('button', { name: 'Send login link' })).toBeInTheDocument();
    expect((await row('Cal Coach')).getByRole('button', { name: 'Invite' })).toBeInTheDocument();
    expect((await row('Tia Treasurer')).getByRole('button', { name: 'Resend invite' })).toBeInTheDocument();
    expect((await row('Val Volunteer')).getByRole('button', { name: 'Resend invite' })).toBeInTheDocument();

    const parent = await row('Pam Parent');
    expect(parent.queryByRole('button', { name: /invite|login link|set password/i })).toBeNull();
    expect(parent.getByText(/managed on crew/i)).toBeInTheDocument();

    expect(screen.getAllByRole('button', { name: 'Set password' })).toHaveLength(4);
    // The existing controls survive.
    expect(screen.getAllByRole('button', { name: 'Edit' })).toHaveLength(5);
    expect(screen.getAllByRole('button', { name: 'Remove' })).toHaveLength(5);
    // The status column is there.
    expect(screen.getAllByText('On the platform').length).toBeGreaterThan(0);
  });

  test('Set password opens the modal, which shows the password once after saving', async () => {
    render(<ClubUserManagement />);
    const coach = await row('Cal Coach');
    fireEvent.click(coach.getByRole('button', { name: 'Set password' }));

    fireEvent.change(screen.getByLabelText(/^temporary password/i), { target: { value: 'Temporary-9x' } });
    fireEvent.change(screen.getByLabelText(/confirm/i), { target: { value: 'Temporary-9x' } });
    // Four row buttons also say "Set password"; the modal's is the form submit.
    fireEvent.click(document.querySelector('form button[type="submit"]') as HTMLElement);

    expect(await screen.findByText('Temporary-9x')).toBeInTheDocument();
    expect(screen.getByText('Ask them to change it after signing in.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /copy/i })).toBeInTheDocument();
    expect(screen.queryByLabelText(/^temporary password/i)).toBeNull();

    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('set-temporary-password'));
    expect(JSON.parse(call[1].body)).toEqual({ user_id: 12, club_id: 51, password: 'Temporary-9x' });
  });

  test('a 403 on the list is said out loud, not rendered as an empty club', async () => {
    (global as any).fetch = jest.fn(() => Promise.resolve(json({ error: 'Only club admins can list club users' }, 403)));
    render(<ClubUserManagement />);
    expect(await screen.findByRole('alert')).toHaveTextContent(/only club admins/i);
    expect(screen.queryByText(/no users found/i)).toBeNull();
  });
});

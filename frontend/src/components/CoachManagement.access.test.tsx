import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import CoachManagement from './CoachManagement';

/**
 * The per-coach access control on the Coaches page.
 *
 * CoachManagement renders TWO tables — one in modal mode (`onClose` given) and
 * one as a standalone page — and both used to hard-code "Active" for every
 * coach. Whatever is added to one must appear in the other, so every case here
 * runs in both modes.
 *
 *   not_invited        → Invite
 *   invited / expired  → Resend invite
 *   active / never used→ Send login link
 *   no_email           → no button, with the reason shown
 *
 * Every row with an email also gets "Set password".
 */

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 32, isClubAdmin: true }),
}));

const coaches = [
  { id: 1, first_name: 'Nora', last_name: 'NotInvited', email: 'nora@club.test', team_count: 0, status: 'not_invited' },
  { id: 2, first_name: 'Ivy', last_name: 'Invited', email: 'ivy@club.test', team_count: 0, status: 'invited' },
  { id: 3, first_name: 'Ed', last_name: 'Expired', email: 'ed@club.test', team_count: 0, status: 'invite_expired' },
  { id: 4, first_name: 'Ann', last_name: 'Active', email: 'ann@club.test', team_count: 1, status: 'active' },
  { id: 5, first_name: 'Ulla', last_name: 'Unused', email: 'ulla@club.test', team_count: 0, status: 'account_never_used' },
  { id: 6, first_name: 'Nel', last_name: 'NoEmail', email: '', team_count: 0, status: 'no_email' },
];

const json = (body: any) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  (global as any).fetch = jest.fn((url: any) => {
    if (String(url).includes('coaches-gateway.php?action=available')) {
      return Promise.resolve(json({ success: true, coaches, page: { next_cursor: null, has_more: false } }));
    }
    return Promise.reject(new Error(`unexpected fetch: ${url}`));
  });
});

// Rows are located by the full name link ("Ivy Invited"), which is unique;
// a bare surname regex also matches "NotInvited".
const rowFor = async (fullName: string) => {
  const cell = await screen.findByText(fullName);
  return within(cell.closest('tr') as HTMLElement);
};

const expectLabels = async () => {
  await waitFor(() => expect(screen.queryByText(/loading coaches/i)).toBeNull());

  expect((await rowFor('Nora NotInvited')).getByRole('button', { name: 'Invite' })).toBeInTheDocument();
  expect((await rowFor('Ivy Invited')).getByRole('button', { name: 'Resend invite' })).toBeInTheDocument();
  expect((await rowFor('Ed Expired')).getByRole('button', { name: 'Resend invite' })).toBeInTheDocument();
  expect((await rowFor('Ann Active')).getByRole('button', { name: 'Send login link' })).toBeInTheDocument();
  expect((await rowFor('Ulla Unused')).getByRole('button', { name: 'Send login link' })).toBeInTheDocument();

  const noEmail = await rowFor('Nel NoEmail');
  expect(noEmail.queryByRole('button', { name: /invite|login link/i })).toBeNull();
  expect(noEmail.getByText(/no email on file/i)).toBeInTheDocument();

  // Set password is offered on every row — it is the one door that needs no address.
  expect(screen.getAllByRole('button', { name: 'Set password' })).toHaveLength(coaches.length);
};

describe('CoachManagement access controls', () => {
  test('standalone page: one context-aware control per row', async () => {
    render(<CoachManagement />);
    await expectLabels();
  });

  test('modal mode: the same control in the other table', async () => {
    render(<CoachManagement onClose={() => {}} />);
    await expectLabels();
  });
});

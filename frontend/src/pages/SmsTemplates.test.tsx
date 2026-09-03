import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import SmsTemplates from './SmsTemplates';

/**
 * The SMS template library's Send button — parity with the email template
 * editor, which has had one since the template work in July.
 *
 * The interesting assertion is the permission one. Edit / Duplicate / Delete are
 * admin-only here, and it would be easy to copy that gate onto Send. Doing so
 * would be wrong: CLAUDE.md's roles section says coaches can send SMS to their
 * own team and may *use* templates, they just cannot create or modify them.
 * Recipient scope is enforced server-side on the send regardless.
 */

const mockUseAuth = jest.fn();
const mockUseOrg = jest.fn();

jest.mock('../contexts/AuthContext', () => ({ useAuth: () => mockUseAuth() }));
jest.mock('../contexts/OrgContext', () => ({ useOrg: () => mockUseOrg() }));

// The compose modal is lazy-loaded and drags in recipient search; stub it and
// assert on the props the page hands it.
jest.mock('../components/communications/SmsCompose', () => ({
  SmsCompose: (props: any) => (
    <div data-testid="sms-compose" data-template-body={props.preselectedTemplate?.body_text} />
  ),
}));

const templates = [
  {
    id: 7,
    club_profile_id: 32,
    name: 'Practice Cancelled',
    body_text: 'Practice is cancelled tonight.',
    category: 'scheduling',
    scope: 'club',
    channel: 'sms',
    is_active: true,
    cloned_from: null,
    created_by: 1,
    updated_by: 1,
    created_at: '2026-07-01T00:00:00Z',
    updated_at: '2026-07-01T00:00:00Z',
  },
];

beforeEach(() => {
  mockUseOrg.mockReturnValue({
    activeContext: { role: 'coach', scope_type: 'club', scope_id: 32, scope_name: 'Club' },
  });
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ success: true, templates }),
  }) as any;
});

const asCoach = () =>
  mockUseAuth.mockReturnValue({
    user: { id: 79, activeRole: { role: 'coach' }, system_role: 'user' },
  });

const asAdmin = () =>
  mockUseAuth.mockReturnValue({
    user: { id: 1, activeRole: { role: 'club_admin' }, system_role: 'user' },
  });

describe('SmsTemplates Send button', () => {
  test('a coach sees Send but not Edit or Delete', async () => {
    asCoach();
    render(<SmsTemplates />);

    await waitFor(() => expect(screen.getByText('Practice Cancelled')).toBeInTheDocument());

    expect(screen.getByTitle('Send this template to recipients')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Edit$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Delete$/i })).not.toBeInTheDocument();
  });

  test('an admin sees Send alongside the management actions', async () => {
    asAdmin();
    render(<SmsTemplates />);

    await waitFor(() => expect(screen.getByText('Practice Cancelled')).toBeInTheDocument());

    expect(screen.getByTitle('Send this template to recipients')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Edit$/i })).toBeInTheDocument();
  });

  test('clicking Send opens compose preloaded with that template body', async () => {
    asCoach();
    render(<SmsTemplates />);

    await waitFor(() => expect(screen.getByText('Practice Cancelled')).toBeInTheDocument());
    expect(screen.queryByTestId('sms-compose')).not.toBeInTheDocument();

    fireEvent.click(screen.getByTitle('Send this template to recipients'));

    await waitFor(() => expect(screen.getByTestId('sms-compose')).toBeInTheDocument());
    expect(screen.getByTestId('sms-compose')).toHaveAttribute(
      'data-template-body',
      'Practice is cancelled tonight.'
    );
  });

  /**
   * With no active club the page never loads templates (the list fetch is keyed
   * on the club), so no row and therefore no Send button exists. The point here
   * is only that this degrades quietly — the compose modal is gated on
   * clubProfileId as well, so it cannot open without a club to send from.
   */
  test('with no active club nothing renders a way to send', async () => {
    asCoach();
    mockUseOrg.mockReturnValue({ activeContext: null });

    render(<SmsTemplates />);

    await waitFor(() =>
      expect(screen.queryByText('Practice Cancelled')).not.toBeInTheDocument()
    );
    expect(screen.queryByTestId('sms-compose')).not.toBeInTheDocument();
  });
});

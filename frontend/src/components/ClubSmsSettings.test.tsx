import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import ClubSmsSettings from './ClubSmsSettings';

let mockIsClubAdmin = true;

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 32, isClubAdmin: mockIsClubAdmin }),
}));

global.fetch = jest.fn();

const UNCONFIGURED = {
  configured: false,
  phone_number: null,
  messaging_service_sid: null,
  twilio_phone_sid: null,
  provisioned_at: null,
  blocked_reason:
    'This club has no SMS number configured. A club admin can set one in Club Profile → Messaging.',
};

const CONFIGURED = {
  configured: true,
  phone_number: '+13605550199',
  messaging_service_sid: null,
  twilio_phone_sid: 'PN0123456789abcdef',
  provisioned_at: '2026-07-30T12:00:00Z',
  blocked_reason: null,
};

const mockApi = (getState: any, setResult?: { ok: boolean; body: any }) => {
  (fetch as jest.Mock).mockImplementation((url: string) => {
    if (url.includes('action=get')) {
      return Promise.resolve({ ok: true, json: async () => ({ success: true, data: getState }) });
    }
    if (url.includes('action=set')) {
      const r = setResult || { ok: true, body: { success: true, data: CONFIGURED } };
      return Promise.resolve({ ok: r.ok, json: async () => r.body });
    }
    if (url.includes('action=clear')) {
      return Promise.resolve({ ok: true, json: async () => ({ success: true, data: UNCONFIGURED }) });
    }
    return Promise.resolve({ ok: true, json: async () => ({}) });
  });
};

const bodyOf = (matcher: string) => {
  const call = (fetch as jest.Mock).mock.calls.find((c) => (c[0] as string).includes(matcher));
  if (!call) throw new Error(`no request matching ${matcher}`);
  return JSON.parse(call[1].body);
};

describe('ClubSmsSettings', () => {
  beforeEach(() => {
    mockIsClubAdmin = true;
    (fetch as jest.Mock).mockClear();
    mockApi(UNCONFIGURED);
  });

  test('states plainly that SMS is blocked when no number is configured', async () => {
    render(<ClubSmsSettings />);

    // The unconfigured case is a blocker, not a hint — with no number the club
    // cannot text anyone, and the screen has to say so.
    expect(await screen.findByRole('alert')).toHaveTextContent(/SMS is blocked for this club/i);
    expect(screen.getByText(/no SMS number configured/i)).toBeInTheDocument();
  });

  test('shows the active number when configured', async () => {
    mockApi(CONFIGURED);
    render(<ClubSmsSettings />);

    expect(await screen.findByText('+1 (360) 555-0199')).toBeInTheDocument();
    expect(screen.getByText(/PN0123456789abcdef/)).toBeInTheDocument();
    expect(screen.queryByText(/SMS is blocked/i)).not.toBeInTheDocument();
  });

  test('posts the number for verification', async () => {
    render(<ClubSmsSettings />);
    await screen.findByRole('alert');

    fireEvent.change(screen.getByLabelText(/Twilio phone number/i), {
      target: { value: '360-555-0199' },
    });
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /Verify & Save/i }));
    });

    await waitFor(() =>
      expect((fetch as jest.Mock).mock.calls.some((c) => (c[0] as string).includes('action=set'))).toBe(true)
    );
    const body = bodyOf('action=set');
    expect(body.club_profile_id).toBe(32);
    expect(body.phone_number).toBe('360-555-0199');
  });

  test('surfaces a Twilio verification failure instead of claiming success', async () => {
    mockApi(UNCONFIGURED, {
      ok: false,
      body: { error: '+13605550199 is not on this Twilio account.' },
    });
    render(<ClubSmsSettings />);
    await screen.findByRole('alert');

    fireEvent.change(screen.getByLabelText(/Twilio phone number/i), {
      target: { value: '+13605550199' },
    });
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /Verify & Save/i }));
    });

    expect(await screen.findByText(/is not on this Twilio account/)).toBeInTheDocument();
  });

  test('sends the Messaging Service SID when one is given', async () => {
    render(<ClubSmsSettings />);
    await screen.findByRole('alert');

    fireEvent.change(screen.getByLabelText(/Messaging Service SID/i), {
      target: { value: 'MG0123456789abcdef' },
    });
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /Verify & Save/i }));
    });

    await waitFor(() =>
      expect((fetch as jest.Mock).mock.calls.some((c) => (c[0] as string).includes('action=set'))).toBe(true)
    );
    expect(bodyOf('action=set').messaging_service_sid).toBe('MG0123456789abcdef');
  });

  test('disables save until something has been entered', async () => {
    render(<ClubSmsSettings />);
    await screen.findByRole('alert');

    const button = screen.getByRole('button', { name: /Verify & Save/i });
    expect(button).toBeDisabled();

    fireEvent.change(screen.getByLabelText(/Twilio phone number/i), { target: { value: '+13605550199' } });
    expect(button).toBeEnabled();
  });

  test('warns that a shared number makes one STOP silence every club', async () => {
    mockApi(CONFIGURED);
    render(<ClubSmsSettings />);
    await screen.findByText('+1 (360) 555-0199');

    // This is the reason the feature exists; it should not be possible to read
    // this screen without encountering it.
    expect(screen.getByText(/stops hearing from every club/i)).toBeInTheDocument();
  });

  test('refuses the whole screen to non-admins', async () => {
    mockIsClubAdmin = false;
    await act(async () => {
      render(<ClubSmsSettings />);
    });

    expect(screen.getByText(/Only club admins can manage the SMS sending number/i)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Twilio phone number/i)).not.toBeInTheDocument();
  });
});

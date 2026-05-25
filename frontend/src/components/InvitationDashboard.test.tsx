import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import InvitationDashboard from './InvitationDashboard';

/**
 * RTL tests for the invitations dashboard resend/revoke flow (CA-118).
 *
 * Verifies:
 *   - pending invitations render with Resend + Cancel actions
 *   - Resend posts to action=resend and disables the button while in-flight
 *   - a cooldown 429 surfaces the server error message
 *   - Cancel posts to action=cancel and refetches the list
 */

const pendingList = {
  success: true,
  invitations: [
    {
      id: '1',
      email: 'coach@club.test',
      role: 'coach',
      status: 'pending',
      inviter_name: 'Admin One',
      created_at: '2026-05-01T00:00:00Z',
    },
  ],
  invitationLinks: [],
};

function mockFetchSequence(handlers: Array<(url: string, init?: any) => any>) {
  let call = 0;
  global.fetch = jest.fn((url: string, init?: any) => {
    const handler = handlers[Math.min(call, handlers.length - 1)];
    call += 1;
    return Promise.resolve(handler(url, init));
  }) as unknown as typeof fetch;
}

function jsonResponse(body: any, ok = true, status = 200) {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  };
}

describe('InvitationDashboard resend/revoke', () => {
  const originalAlert = window.alert;
  const originalConfirm = window.confirm;

  beforeEach(() => {
    jest.clearAllMocks();
    window.alert = jest.fn();
    window.confirm = jest.fn(() => true);
    localStorage.setItem('auth_token', 'test-token');
  });

  afterEach(() => {
    window.alert = originalAlert;
    window.confirm = originalConfirm;
    localStorage.clear();
  });

  test('renders pending invitation with resend and cancel actions', async () => {
    mockFetchSequence([() => jsonResponse(pendingList)]);

    render(<InvitationDashboard clubId={100} />);

    expect(await screen.findByText('coach@club.test')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /resend/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });

  test('resend posts to action=resend and shows success', async () => {
    mockFetchSequence([
      (url) => {
        if (url.includes('action=list')) return jsonResponse(pendingList);
        if (url.includes('action=resend')) {
          return jsonResponse({ success: true, message: 'Invitation resent successfully' });
        }
        return jsonResponse({});
      },
    ]);

    render(<InvitationDashboard clubId={100} />);
    const resendBtn = await screen.findByRole('button', { name: /resend/i });

    fireEvent.click(resendBtn);

    await waitFor(() => {
      expect(window.alert).toHaveBeenCalledWith('Invitation resent successfully!');
    });

    const resendCall = (global.fetch as jest.Mock).mock.calls.find(
      (c: any[]) => typeof c[0] === 'string' && c[0].includes('action=resend')
    );
    expect(resendCall).toBeTruthy();
    expect(resendCall[1].method).toBe('POST');
    expect(JSON.parse(resendCall[1].body)).toEqual({ invitationId: '1' });
  });

  test('resend surfaces cooldown (429) server message', async () => {
    mockFetchSequence([
      (url) => {
        if (url.includes('action=list')) return jsonResponse(pendingList);
        if (url.includes('action=resend')) {
          return jsonResponse(
            {
              error: 'This invitation was sent recently. Please wait before resending.',
              retryAfterSeconds: 90,
            },
            false,
            429
          );
        }
        return jsonResponse({});
      },
    ]);

    render(<InvitationDashboard clubId={100} />);
    const resendBtn = await screen.findByRole('button', { name: /resend/i });

    fireEvent.click(resendBtn);

    await waitFor(() => {
      expect(window.alert).toHaveBeenCalledWith(
        'This invitation was sent recently. Please wait before resending.'
      );
    });
  });

  test('resend button is disabled while the request is in flight', async () => {
    let resolveResend: (v: any) => void = () => {};
    const resendPromise = new Promise((res) => {
      resolveResend = res;
    });

    global.fetch = jest.fn((url: string) => {
      if (url.includes('action=list')) return Promise.resolve(jsonResponse(pendingList));
      if (url.includes('action=resend')) return resendPromise;
      return Promise.resolve(jsonResponse({}));
    }) as unknown as typeof fetch;

    render(<InvitationDashboard clubId={100} />);
    const resendBtn = await screen.findByRole('button', { name: /resend/i });

    fireEvent.click(resendBtn);

    // Button reflects in-flight state and is disabled.
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /resending/i })).toBeDisabled();
    });

    resolveResend(jsonResponse({ success: true, message: 'ok' }));
  });

  test('cancel posts to action=cancel after confirmation', async () => {
    const afterCancelList = { ...pendingList, invitations: [] };
    let listCalls = 0;
    global.fetch = jest.fn((url: string) => {
      if (url.includes('action=list')) {
        listCalls += 1;
        return Promise.resolve(jsonResponse(listCalls === 1 ? pendingList : afterCancelList));
      }
      if (url.includes('action=cancel')) {
        return Promise.resolve(jsonResponse({ success: true, message: 'Invitation canceled successfully' }));
      }
      return Promise.resolve(jsonResponse({}));
    }) as unknown as typeof fetch;

    render(<InvitationDashboard clubId={100} />);
    const cancelBtn = await screen.findByRole('button', { name: /^cancel$/i });

    fireEvent.click(cancelBtn);

    await waitFor(() => {
      const cancelCall = (global.fetch as jest.Mock).mock.calls.find(
        (c: any[]) => typeof c[0] === 'string' && c[0].includes('action=cancel')
      );
      expect(cancelCall).toBeTruthy();
      expect(cancelCall[1].method).toBe('POST');
      expect(JSON.parse(cancelCall[1].body)).toEqual({ invitationId: '1' });
    });

    // After cancel, the list refetches and the row is gone (no longer pending).
    await waitFor(() => {
      expect(screen.getByText(/no invitations found/i)).toBeInTheDocument();
    });
  });

  test('cancel is aborted when the user declines the confirm dialog', async () => {
    window.confirm = jest.fn(() => false);
    mockFetchSequence([() => jsonResponse(pendingList)]);

    render(<InvitationDashboard clubId={100} />);
    const cancelBtn = await screen.findByRole('button', { name: /^cancel$/i });

    fireEvent.click(cancelBtn);

    const cancelCall = (global.fetch as jest.Mock).mock.calls.find(
      (c: any[]) => typeof c[0] === 'string' && c[0].includes('action=cancel')
    );
    expect(cancelCall).toBeUndefined();
  });
});

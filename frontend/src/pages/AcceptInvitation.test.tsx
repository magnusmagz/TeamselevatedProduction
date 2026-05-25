import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import AcceptInvitation from './AcceptInvitation';

/**
 * RTL tests for the public invitation acceptance page.
 *
 * Verifies the end-to-end click-to-accept flow:
 *   - invitation info loads and renders
 *   - an authenticated user clicking "Accept" POSTs to action=accept with the
 *     id, stores the returned token, refreshes auth, and lands on success
 *   - an unauthenticated user is routed to the name/email form, and submitting
 *     it accepts the invitation
 *   - a server error during accept is surfaced (the original "button does
 *     nothing / fails" symptom)
 */

const mockNavigate = jest.fn();
const mockRefreshAuth = jest.fn().mockResolvedValue(undefined);
let mockSearchParams = new URLSearchParams('id=42');
let mockUser: any = null;

jest.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useSearchParams: () => [mockSearchParams],
}));

jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ user: mockUser, refreshAuth: mockRefreshAuth }),
}));

const invitationInfo = {
  success: true,
  type: 'email',
  invitationId: 42,
  email: 'invitee@club.test',
  role: 'coach',
  organizationName: 'Strikers FC',
  organizationType: 'club',
  inviterName: 'Admin One',
  personalMessage: null,
};

function jsonResponse(body: any, ok = true, status = 200) {
  return { ok, status, json: () => Promise.resolve(body) };
}

describe('AcceptInvitation page', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUser = null;
    mockSearchParams = new URLSearchParams('id=42');
    localStorage.clear();
  });

  test('loads and renders invitation info', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse(invitationInfo))) as any;

    render(<AcceptInvitation />);

    expect(await screen.findByText(/you're invited/i)).toBeInTheDocument();
    expect(screen.getAllByText('Strikers FC').length).toBeGreaterThan(0);
  });

  test('authenticated user accepts: posts id, stores token, lands on success', async () => {
    mockUser = { id: 7, email: 'invitee@club.test', name: 'Invitee' };

    global.fetch = jest.fn((url: string) => {
      if (url.includes('action=info')) return Promise.resolve(jsonResponse(invitationInfo));
      if (url.includes('action=accept')) {
        return Promise.resolve(
          jsonResponse({ success: true, token: 'new-jwt', userId: 7, role: 'coach' })
        );
      }
      return Promise.resolve(jsonResponse({}));
    }) as any;

    render(<AcceptInvitation />);

    const acceptBtn = await screen.findByRole('button', { name: /accept invitation/i });
    fireEvent.click(acceptBtn);

    await waitFor(() => {
      expect(screen.getByText(/welcome/i)).toBeInTheDocument();
    });

    const acceptCall = (global.fetch as jest.Mock).mock.calls.find(
      (c: any[]) => typeof c[0] === 'string' && c[0].includes('action=accept')
    );
    expect(acceptCall).toBeTruthy();
    expect(acceptCall[1].method).toBe('POST');
    expect(JSON.parse(acceptCall[1].body)).toEqual({ id: '42', code: null });

    expect(localStorage.getItem('auth_token')).toBe('new-jwt');
    expect(mockRefreshAuth).toHaveBeenCalled();
  });

  test('unauthenticated user is routed to the form, then submitting accepts', async () => {
    mockUser = null;

    global.fetch = jest.fn((url: string) => {
      if (url.includes('action=info')) return Promise.resolve(jsonResponse(invitationInfo));
      if (url.includes('action=accept')) {
        return Promise.resolve(
          jsonResponse({ success: true, token: 'new-jwt', userId: 8, role: 'coach' })
        );
      }
      return Promise.resolve(jsonResponse({}));
    }) as any;

    render(<AcceptInvitation />);

    // Step 1: click Accept -> form appears (no token call yet for guests).
    const acceptBtn = await screen.findByRole('button', { name: /accept invitation/i });
    fireEvent.click(acceptBtn);

    const nameInput = await screen.findByPlaceholderText(/enter your full name/i);
    fireEvent.change(nameInput, { target: { value: 'New Coach' } });

    // Email is pre-filled from the invitation.
    const emailInput = screen.getByPlaceholderText(/your.email@example.com/i) as HTMLInputElement;
    expect(emailInput.value).toBe('invitee@club.test');

    // Step 2: submit the form.
    const submitBtn = screen.getAllByRole('button', { name: /accept invitation/i }).pop()!;
    fireEvent.click(submitBtn);

    await waitFor(() => {
      expect(screen.getByText(/welcome/i)).toBeInTheDocument();
    });

    const acceptCall = (global.fetch as jest.Mock).mock.calls.find(
      (c: any[]) => typeof c[0] === 'string' && c[0].includes('action=accept')
    );
    expect(JSON.parse(acceptCall[1].body)).toEqual({
      id: '42',
      code: null,
      name: 'New Coach',
      email: 'invitee@club.test',
    });
    expect(localStorage.getItem('auth_token')).toBe('new-jwt');
  });

  test('surfaces a server error when accept fails', async () => {
    mockUser = { id: 7, email: 'invitee@club.test', name: 'Invitee' };

    global.fetch = jest.fn((url: string) => {
      if (url.includes('action=info')) return Promise.resolve(jsonResponse(invitationInfo));
      if (url.includes('action=accept')) {
        return Promise.resolve(jsonResponse({ error: 'Invitation has expired' }, false, 400));
      }
      return Promise.resolve(jsonResponse({}));
    }) as any;

    render(<AcceptInvitation />);

    const acceptBtn = await screen.findByRole('button', { name: /accept invitation/i });
    fireEvent.click(acceptBtn);

    await waitFor(() => {
      expect(screen.getByText('Invitation has expired')).toBeInTheDocument();
    });

    // It must NOT advance to the success screen on failure.
    expect(screen.queryByText(/welcome/i)).not.toBeInTheDocument();
    expect(localStorage.getItem('auth_token')).toBeNull();
  });

  test('shows invalid-invitation screen when info load fails', async () => {
    global.fetch = jest.fn(() =>
      Promise.resolve(jsonResponse({ error: 'Invitation not found' }, false, 404))
    ) as any;

    render(<AcceptInvitation />);

    expect(await screen.findByText(/invalid invitation/i)).toBeInTheDocument();
    expect(screen.getByText('Invitation not found')).toBeInTheDocument();
  });
});

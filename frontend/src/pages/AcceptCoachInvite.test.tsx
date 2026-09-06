import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import AcceptCoachInvite from './AcceptCoachInvite';

/**
 * AcceptCoachInvite — where a coach's single-use link lands (GOTR G6).
 *
 * Same three-answer ladder as SetParentPassword: `already_used` is NOT an error
 * state (they have an account — send them to sign in), `expired` points at the
 * club, and an unknown token stays vague. On success the returned session is
 * stored and the coach lands in the staff app.
 *
 * react-router-dom is mocked outright — see OnboardingFunnel.test.tsx.
 */

let mockSearch = '';
const mockNavigate = jest.fn();
jest.mock('react-router-dom', () => ({
  useSearchParams: () => [new URLSearchParams(mockSearch)],
  useNavigate: () => mockNavigate,
  Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>,
}));

global.fetch = jest.fn();

const fill = (pw: string) => {
  fireEvent.change(screen.getByLabelText(/^password/i), { target: { value: pw } });
  fireEvent.change(screen.getByLabelText(/confirm password/i), { target: { value: pw } });
};

beforeEach(() => {
  (fetch as jest.Mock).mockReset();
  mockNavigate.mockReset();
  localStorage.clear();
  mockSearch = '';
});

test('without a token there is nothing to do', () => {
  render(<AcceptCoachInvite />);
  expect(screen.getByText(/ask your club/i)).toBeInTheDocument();
  expect(screen.queryByLabelText(/^password/i)).not.toBeInTheDocument();
});

test('posts the token and password to the redemption endpoint, stores the session and lands on the dashboard', async () => {
  mockSearch = 'token=abc';
  (fetch as jest.Mock).mockResolvedValue({
    ok: true,
    json: async () => ({ success: true, token: 'jwt-123', user: { id: 8, email: 'c@x.org', name: 'Sam Shell' } }),
  });
  render(<AcceptCoachInvite />);
  fill('Str0ngPassword');
  fireEvent.click(screen.getByRole('button', { name: /set password/i }));

  await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/dashboard'));
  expect(localStorage.getItem('auth_token')).toBe('jwt-123');
  const [url, init] = (fetch as jest.Mock).mock.calls[0];
  expect(url).toContain('/api/coach-invite.php?action=redeem');
  expect(JSON.parse(init.body)).toEqual({ token: 'abc', password: 'Str0ngPassword' });
});

test('an already-used link is an account that exists, with a way to sign in', async () => {
  mockSearch = 'token=abc';
  (fetch as jest.Mock).mockResolvedValue({
    ok: false, status: 400,
    json: async () => ({ error: 'You have already set up this account. Please sign in with the password you chose.', reason: 'already_used' }),
  });
  render(<AcceptCoachInvite />);
  fill('Str0ngPassword');
  fireEvent.click(screen.getByRole('button', { name: /set password/i }));

  expect(await screen.findByText(/already set up this account/i)).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /sign in/i })).toHaveAttribute('href', '/login');
  expect(mockNavigate).not.toHaveBeenCalled();
});

test('an expired link says to ask the club for a new one', async () => {
  mockSearch = 'token=abc';
  (fetch as jest.Mock).mockResolvedValue({
    ok: false, status: 400,
    json: async () => ({ error: 'This invite link has expired. Ask your club to send a new one.', reason: 'expired' }),
  });
  render(<AcceptCoachInvite />);
  fill('Str0ngPassword');
  fireEvent.click(screen.getByRole('button', { name: /set password/i }));
  expect(await screen.findByText(/has expired/i)).toBeInTheDocument();
});

test('a weak password never reaches the server', async () => {
  mockSearch = 'token=abc';
  render(<AcceptCoachInvite />);
  fill('short');
  fireEvent.click(screen.getByRole('button', { name: /set password/i }));
  expect(await screen.findByText(/must be at least 8 characters/i)).toBeInTheDocument();
  await waitFor(() => expect(fetch).not.toHaveBeenCalled());
});

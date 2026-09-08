import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { useNavigate } from 'react-router-dom';
import Login from './Login';

// react-router-dom is the repo's manual mock (src/__mocks__); CRA resets mocks
// between tests, so the navigate double is wired in beforeEach.
jest.mock('../contexts/AuthContext', () => ({ useAuth: () => ({ login: jest.fn() }) }));

/**
 * Where a sign-in lands is decided by utils/landingRoute.ts. This renders the
 * real page against a mocked auth-gateway so the wiring — not just the rule —
 * is what is pinned.
 */
function loginResponse(user: Record<string, unknown>) {
  return { ok: true, status: 200, json: async () => ({ success: true, token: 'tok', user }) };
}

const navigate = jest.fn();

beforeEach(() => {
  navigate.mockReset();
  (useNavigate as jest.Mock).mockReturnValue(navigate);
  global.fetch = jest.fn();
});
afterEach(() => {
  delete (global as any).fetch;
});

async function signIn() {
  const view = render(<Login />);
  fireEvent.change(screen.getByPlaceholderText(/your.email@example.com/), { target: { value: 'ref@whistle.test' } });
  fireEvent.change(screen.getByPlaceholderText(/Enter your password/), { target: { value: 'Secret123' } });
  fireEvent.click(screen.getByRole('button', { name: /sign in/i }));
  await waitFor(() => expect(navigate).toHaveBeenCalled());
  view.unmount();
}

describe('Login — landing by role', () => {
  it('lands a referee-only account on /referee', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(
      loginResponse({ id: 300, email: 'ref@whistle.test', roles: [{ role: 'referee', scope_type: 'club', scope_id: 100 }] })
    );
    await signIn();
    expect(navigate).toHaveBeenCalledWith('/referee');
  });

  it('still lands a parent on /parent and a coach-referee on /dashboard', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(
      loginResponse({ id: 1, email: 'p@x.test', roles: [{ role: 'parent', scope_type: 'club', scope_id: 100 }] })
    );
    await signIn();
    expect(navigate).toHaveBeenCalledWith('/parent');

    navigate.mockReset();
    (global.fetch as jest.Mock).mockResolvedValueOnce(
      loginResponse({ id: 2, email: 'c@x.test', roles: [{ role: 'coach', scope_type: 'club', scope_id: 100 }, { role: 'referee', scope_type: 'club', scope_id: 100 }] })
    );
    await signIn();
    expect(navigate).toHaveBeenCalledWith('/dashboard');
  });
});

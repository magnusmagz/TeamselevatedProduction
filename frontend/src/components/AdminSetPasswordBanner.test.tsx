import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { AdminSetPasswordBanner } from './AdminSetPasswordBanner';

/**
 * The one-line "an admin set your password" nudge on the staff dashboard.
 *
 * It reads users.password_set_by_admin_at through api/user-profile.php. Absent
 * or null (migration 097 not applied, or the user changed their password) means
 * no banner; a failed read means no banner — it is a prompt, never a gate.
 */

const json = (body: any, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: () => Promise.resolve(body),
});

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  sessionStorage.clear();
});

describe('AdminSetPasswordBanner', () => {
  test('renders for a user whose password was set by an admin, and can be dismissed', async () => {
    (global as any).fetch = jest.fn(() =>
      Promise.resolve(json({ success: true, user: { id: 8, password_set_by_admin_at: '2026-09-06 10:00:00' } }))
    );
    render(<AdminSetPasswordBanner />);
    expect(await screen.findByText(/set by a club admin/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /dismiss/i }));
    expect(screen.queryByText(/set by a club admin/i)).toBeNull();
  });

  test('renders nothing when the mark is null', async () => {
    (global as any).fetch = jest.fn(() =>
      Promise.resolve(json({ success: true, user: { id: 8, password_set_by_admin_at: null } }))
    );
    const { container } = render(<AdminSetPasswordBanner />);
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  test('renders nothing when the read fails', async () => {
    (global as any).fetch = jest.fn(() => Promise.reject(new Error('network')));
    const { container } = render(<AdminSetPasswordBanner />);
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});

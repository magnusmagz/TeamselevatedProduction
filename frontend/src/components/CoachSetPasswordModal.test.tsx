import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import CoachSetPasswordModal from './CoachSetPasswordModal';

/**
 * "Set password" for a coach, from the Coaches page.
 *
 * Pinned: a short password is refused before any request; Generate fills a
 * 12-character one; on success the password is shown ONCE with a copy button
 * and the sentence asking the coach to change it; the request carries
 * user_id / club_id / password and nothing else.
 */

const coach = { id: 8, first_name: 'Fay', last_name: 'Fresh', email: 'fay@club.test' };

const json = (body: any, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: () => Promise.resolve(body),
});

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  (global as any).fetch = jest.fn(() => Promise.resolve(json({ success: true, email: coach.email })));
  Object.assign(navigator, { clipboard: { writeText: jest.fn(() => Promise.resolve()) } });
});

const typePassword = (value: string) => {
  fireEvent.change(screen.getByLabelText(/^temporary password/i), { target: { value } });
  fireEvent.change(screen.getByLabelText(/confirm/i), { target: { value } });
};

describe('CoachSetPasswordModal', () => {
  test('refuses a short password without calling the server', async () => {
    render(<CoachSetPasswordModal coach={coach} clubId={32} onClose={() => {}} onSaved={() => {}} />);
    typePassword('short9');
    fireEvent.click(screen.getByRole('button', { name: /set password/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/at least 10 characters/i);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  test('refuses a mismatched confirmation', async () => {
    render(<CoachSetPasswordModal coach={coach} clubId={32} onClose={() => {}} onSaved={() => {}} />);
    fireEvent.change(screen.getByLabelText(/^temporary password/i), { target: { value: 'LongEnough12' } });
    fireEvent.change(screen.getByLabelText(/confirm/i), { target: { value: 'LongEnough13' } });
    fireEvent.click(screen.getByRole('button', { name: /set password/i }));
    expect(await screen.findByText(/do not match/i)).toBeInTheDocument();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  test('Generate fills a 12-character password into both fields', () => {
    render(<CoachSetPasswordModal coach={coach} clubId={32} onClose={() => {}} onSaved={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /generate/i }));
    const pw = (screen.getByLabelText(/^temporary password/i) as HTMLInputElement).value;
    expect(pw).toHaveLength(12);
    expect((screen.getByLabelText(/confirm/i) as HTMLInputElement).value).toBe(pw);
  });

  test('on success shows the password once, with a copy button and the change-it sentence', async () => {
    const onSaved = jest.fn();
    render(<CoachSetPasswordModal coach={coach} clubId={32} onClose={() => {}} onSaved={onSaved} />);
    typePassword('Temporary-9x');
    fireEvent.click(screen.getByRole('button', { name: /set password/i }));

    expect(await screen.findByText('Temporary-9x')).toBeInTheDocument();
    expect(screen.getByText('Ask them to change it after signing in.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /copy/i })).toBeInTheDocument();
    expect(onSaved).toHaveBeenCalled();

    // The form is gone — there is no way back to a second submission.
    expect(screen.queryByLabelText(/^temporary password/i)).toBeNull();

    const [url, init] = (global.fetch as jest.Mock).mock.calls[0];
    expect(String(url)).toContain('/api/coach-access.php?action=set-temporary-password');
    expect(JSON.parse(init.body)).toEqual({ user_id: 8, club_id: 32, password: 'Temporary-9x' });
    expect(init.headers.Authorization).toBe('Bearer tok');

    fireEvent.click(screen.getByRole('button', { name: /copy/i }));
    await waitFor(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('Temporary-9x'));
  });

  test('a server refusal is shown and the password is not revealed', async () => {
    (global as any).fetch = jest.fn(() =>
      Promise.resolve(json({ success: false, error: 'Only club admins can manage a coach\'s access' }, 403))
    );
    render(<CoachSetPasswordModal coach={coach} clubId={32} onClose={() => {}} onSaved={() => {}} />);
    typePassword('Temporary-9x');
    fireEvent.click(screen.getByRole('button', { name: /set password/i }));
    expect(await screen.findByText(/only club admins/i)).toBeInTheDocument();
    expect(screen.queryByText('Ask them to change it after signing in.')).toBeNull();
  });
});

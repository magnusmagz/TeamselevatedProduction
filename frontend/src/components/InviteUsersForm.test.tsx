import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import InviteUsersForm from './InviteUsersForm';

/**
 * Shareable invitation links, and the QR that makes them usable in person.
 *
 * The link is for an admin standing at a table — Leya holds up her phone, a parent
 * scans it and signs up. Copying a URL is useless in that moment, so the QR is the
 * feature, not decoration.
 *
 * Crew is also an option here now. It works with no new linking code because parent
 * standing is derived from guardians.email = users.email: an existing guardian who
 * accepts on the same address lands on their own children. See CrewInvitationRoleTest.
 */
describe('InviteUsersForm — shareable link', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
  });

  const generateLink = async () => {
    render(<InviteUsersForm clubId={51} />);
    fireEvent.click(screen.getByText('Shareable Link'));
    fireEvent.click(screen.getByText('Generate Link'));
  };

  test('renders a QR of the generated link so it can be scanned off a phone', async () => {
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ url: 'https://teams-elevated.netlify.app/join?code=ABCD2345' }),
    }) as any;

    await generateLink();

    expect(await screen.findByText(/Your invitation link is ready/i)).toBeInTheDocument();
    expect(screen.getByText(/Have them scan this/i)).toBeInTheDocument();

    // qrcode.react renders an <svg>. Asserting on the element rather than the encoded
    // modules keeps this a test of "a QR is shown for THIS link", not of the library.
    const qr = document.querySelector('svg[height][width]');
    expect(qr).not.toBeNull();

    // The link itself is still available to copy — the QR supplements it.
    expect(
      screen.getByDisplayValue('https://teams-elevated.netlify.app/join?code=ABCD2345')
    ).toBeInTheDocument();
  });

  test('shows no QR before a link exists', async () => {
    render(<InviteUsersForm clubId={51} />);
    fireEvent.click(screen.getByText('Shareable Link'));

    expect(screen.queryByText(/Have them scan this/i)).not.toBeInTheDocument();
  });

  test('offers Crew as a role on both the email and link paths', async () => {
    render(<InviteUsersForm clubId={51} />);

    // Email path (default).
    expect(screen.getByRole('option', { name: 'Crew' })).toBeInTheDocument();

    // Link path renders its own separate <select>; adding the option to one and not
    // the other is the easy mistake here.
    fireEvent.click(screen.getByText('Shareable Link'));
    const crew = screen.getByRole('option', { name: 'Crew' }) as HTMLOptionElement;
    expect(crew.value).toBe('parent');
  });
});

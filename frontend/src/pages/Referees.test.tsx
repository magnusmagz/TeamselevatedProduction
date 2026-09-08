import React from 'react';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import Referees from './Referees';

jest.mock('../contexts/OrgContext', () => ({ useOrg: () => ({ currentClubId: 100, isClubAdmin: true }) }));

const ray = {
  id: 1, club_id: 100, user_id: 300, first_name: 'Ray', last_name: 'Whistle', name: 'Ray Whistle',
  email: 'ref@whistle.test', phone: '+13165550100', grade: 'Regional', certification_level: null, notes: null,
  active: true, archived_at: null, status: 'active',
};
const nora = {
  id: 2, club_id: 100, user_id: null, first_name: 'Nora', last_name: 'Flag', name: 'Nora Flag',
  email: 'nora@flag.test', phone: null, grade: 'Grade 5', certification_level: 'SafeSport', notes: null,
  active: true, archived_at: null, status: 'not_invited',
};

const listResponse = (referees: unknown[]) => ({
  ok: true, status: 200,
  json: async () => ({ success: true, available: true, referees, grades: ['Grassroots', 'Regional', 'National', 'Professional'] }),
});

beforeEach(() => {
  global.fetch = jest.fn();
  localStorage.setItem('auth_token', 'tok');
});
afterEach(() => {
  delete (global as any).fetch;
});

describe('Referees directory page', () => {
  it('lists the club with name, email, phone, grade and portal status, and adds a referee with an invite', async () => {
    (global.fetch as jest.Mock)
      .mockResolvedValueOnce(listResponse([ray, nora]))
      .mockResolvedValueOnce({
        ok: true, status: 201,
        json: async () => ({ success: true, id: 3, referee: { ...nora, id: 3, name: 'Sam Line' }, invite: { status: 'invited', sent: true, message: 'An invitation to set their password has been emailed to them.' } }),
      })
      .mockResolvedValueOnce(listResponse([ray, nora, { ...nora, id: 3, first_name: 'Sam', last_name: 'Line', name: 'Sam Line', email: 'sam@line.test', grade: 'Grassroots' }]));

    render(<Referees />);

    expect(await screen.findByText('Ray Whistle')).toBeInTheDocument();
    expect(screen.getByText('ref@whistle.test')).toBeInTheDocument();
    expect(screen.getByText('+13165550100')).toBeInTheDocument();
    expect(screen.getByText('Regional')).toBeInTheDocument();
    expect(screen.getByText('Grade 5')).toBeInTheDocument();
    expect(screen.getByText('On the platform')).toBeInTheDocument();
    expect(screen.getByText('Not invited')).toBeInTheDocument();
    // Invite only where there is no account and an email
    expect(screen.getAllByRole('button', { name: /Invite to portal/ })).toHaveLength(1);

    fireEvent.click(screen.getByRole('button', { name: /Add Referee/ }));
    fireEvent.change(screen.getByLabelText(/First name/), { target: { value: 'Sam' } });
    fireEvent.change(screen.getByLabelText(/Last name/), { target: { value: 'Line' } });
    fireEvent.change(screen.getByLabelText(/^Email/), { target: { value: 'sam@line.test' } });
    fireEvent.change(screen.getByLabelText(/^Phone/), { target: { value: '(316) 555-0199' } });
    fireEvent.change(screen.getByLabelText(/^Grade/), { target: { value: 'Grassroots' } });
    fireEvent.click(screen.getByLabelText(/Invite to portal/));
    const dialogForm = screen.getByRole('button', { name: /^Add Referee$/ }).closest('form') as HTMLFormElement;
    fireEvent.submit(dialogForm);

    await waitFor(() => expect((global.fetch as jest.Mock).mock.calls.length).toBe(3));
    const [url, init] = (global.fetch as jest.Mock).mock.calls[1];
    expect(url).toContain('/api/referees.php?action=create');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body)).toEqual({
      club_id: 100, first_name: 'Sam', last_name: 'Line', email: 'sam@line.test', phone: '(316) 555-0199',
      grade: 'Grassroots', certification_level: '', notes: '', invite: true,
    });
    expect(await screen.findByText(/emailed to them/)).toBeInTheDocument();
    expect(screen.getByText('Sam Line')).toBeInTheDocument();
  });

  it('edits a referee through update with only the fields on the form, and "Other" is free text', async () => {
    (global.fetch as jest.Mock)
      .mockResolvedValueOnce(listResponse([nora]))
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ success: true, referee: { ...nora, grade: 'FIFA Futsal' } }) })
      .mockResolvedValueOnce(listResponse([{ ...nora, grade: 'FIFA Futsal' }]));

    render(<Referees />);
    expect(await screen.findByText('Nora Flag')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /^Edit$/ }));

    expect((screen.getByLabelText(/First name/) as HTMLInputElement).value).toBe('Nora');
    expect((screen.getByLabelText(/^Grade/) as HTMLSelectElement).value).toBe('Grade 5');
    fireEvent.change(screen.getByLabelText(/^Grade/), { target: { value: '__other__' } });
    fireEvent.change(screen.getByLabelText(/Other grade/), { target: { value: 'FIFA Futsal' } });
    fireEvent.submit(screen.getByRole('button', { name: /^Save$/ }).closest('form') as HTMLFormElement);

    await waitFor(() => expect((global.fetch as jest.Mock).mock.calls.length).toBe(3));
    const [url, init] = (global.fetch as jest.Mock).mock.calls[1];
    expect(url).toContain('action=update');
    expect(init.method).toBe('PUT');
    const body = JSON.parse(init.body);
    expect(body.id).toBe(2);
    expect(body.grade).toBe('FIFA Futsal');
    expect(body).not.toHaveProperty('invite');
    expect(await screen.findByText('FIFA Futsal')).toBeInTheDocument();
  });

  it('archives, shows archived rows only with the toggle, and restores', async () => {
    const archived = { ...ray, archived_at: '2026-09-08 10:00:00', active: false };
    (global.fetch as jest.Mock)
      .mockResolvedValueOnce(listResponse([ray]))
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ success: true, referee: archived }) })
      .mockResolvedValueOnce(listResponse([]))
      .mockResolvedValueOnce(listResponse([archived]))
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ success: true, referee: ray }) })
      .mockResolvedValueOnce(listResponse([ray]));

    render(<Referees />);
    expect(await screen.findByText('Ray Whistle')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /^Archive$/ }));

    await waitFor(() => expect(screen.queryByText('Ray Whistle')).not.toBeInTheDocument());
    expect((global.fetch as jest.Mock).mock.calls[1][0]).toContain('action=archive');
    expect((global.fetch as jest.Mock).mock.calls[2][0]).not.toContain('include_archived');

    fireEvent.click(screen.getByLabelText(/Show archived/));
    expect(await screen.findByText('(archived)')).toBeInTheDocument();
    expect((global.fetch as jest.Mock).mock.calls[3][0]).toContain('include_archived=1');

    fireEvent.click(screen.getByRole('button', { name: /^Restore$/ }));
    await waitFor(() => expect((global.fetch as jest.Mock).mock.calls[4][0]).toContain('action=restore'));
    await waitFor(() => expect(screen.queryByText('(archived)')).not.toBeInTheDocument());
  });

  it('shows a 409 duplicate as the error, not a silent empty save', async () => {
    (global.fetch as jest.Mock)
      .mockResolvedValueOnce(listResponse([nora]))
      .mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ success: false, error: "Nora Flag is already in this club's referee directory with that email" }) });

    render(<Referees />);
    expect(await screen.findByText('Nora Flag')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Add Referee/ }));
    fireEvent.change(screen.getByLabelText(/First name/), { target: { value: 'N' } });
    fireEvent.change(screen.getByLabelText(/Last name/), { target: { value: 'F' } });
    fireEvent.change(screen.getByLabelText(/^Email/), { target: { value: 'nora@flag.test' } });
    fireEvent.submit(screen.getByRole('button', { name: /^Add Referee$/ }).closest('form') as HTMLFormElement);

    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText(/already in this club/)).toBeInTheDocument();
  });
});

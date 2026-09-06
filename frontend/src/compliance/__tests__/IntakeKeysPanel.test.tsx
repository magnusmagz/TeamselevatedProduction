import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { IntakeKeysPanel } from '../IntakeKeysPanel';

/**
 * Intake keys + unmatched arrivals (GOTR G7).
 *
 * Pinned: a created key is shown ONCE, in full, and the list that follows
 * carries only the prefix; revoke posts the unit and the id; an unmatched
 * arrival is matched to a person the server found, and a `no_requirement`
 * arrival cannot be recorded without a requirement chosen.
 */

const ok = (body: unknown) => ({ ok: true, status: 200, json: async () => body });

const requirements = [
  { id: 10, name: 'SafeSport', kind: 'training', required: true, org_unit_id: 1, club_profile_id: null },
  { id: 11, name: 'Concussion protocol', kind: 'training', required: true, org_unit_id: 2, club_profile_id: null },
];

function mockFetch(overrides: Partial<Record<string, any>> = {}) {
  let keys: any[] = overrides.keys || [];
  global.fetch = jest.fn(async (url: any, init?: any) => {
    const u = String(url);
    if (u.includes('action=keys')) return ok({ success: true, available: true, keys });
    if (u.includes('action=unmatched')) return ok({ success: true, available: true, arrivals: overrides.arrivals || [] });
    if (u.includes('action=key-create')) {
      keys = [{ id: 3, org_unit_id: 2, name: JSON.parse(init.body).name, key_prefix: 'tei_abcd', created_at: '2026-09-06 10:00:00', last_used_at: null, revoked_at: null, active: true }];
      return ok({ success: true, id: 3, key: 'tei_abcd1234567890abcdef1234567890abcdef12', prefix: 'tei_abcd' });
    }
    if (u.includes('action=people')) {
      return ok({ success: true, people: [{ user_id: 50, first_name: 'Hana', last_name: 'Head', email: 'head@gotr.org', club_id: 100 }] });
    }
    if (u.includes('action=match')) return ok({ success: true, credential_id: 77 });
    return ok({ success: true });
  }) as any;
}

describe('IntakeKeysPanel', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tok');
  });

  test('a new key is shown once in full, and the list carries only its prefix', async () => {
    mockFetch();
    render(<IntakeKeysPanel orgUnitId={2} requirements={requirements} />);
    await waitFor(() => expect(screen.getByText('Nothing waiting.')).toBeInTheDocument());

    fireEvent.change(screen.getByLabelText('Key name'), { target: { value: 'Cornerstone' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create key' }));

    const once = await screen.findByTestId('intake-key-once');
    expect(once).toHaveTextContent('tei_abcd1234567890abcdef1234567890abcdef12');
    expect(once).toHaveTextContent(/will not be shown again/i);

    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=key-create'));
    expect(JSON.parse(call[1].body)).toEqual({ org_unit_id: 2, name: 'Cornerstone' });

    // The list row shows the prefix and never the whole key.
    const row = await screen.findByTestId('intake-key-3');
    expect(row).toHaveTextContent('tei_abcd…');
    expect(row).not.toHaveTextContent('tei_abcd1234567890');

    // Dismissing the box removes the only place the key ever appeared.
    fireEvent.click(screen.getByText('I have copied it'));
    expect(screen.queryByTestId('intake-key-once')).toBeNull();
    expect(screen.queryByText('tei_abcd1234567890abcdef1234567890abcdef12')).toBeNull();
  });

  test('revoke posts the unit and the key id', async () => {
    mockFetch({ keys: [{ id: 4, org_unit_id: 2, name: 'Old', key_prefix: 'tei_0000', created_at: null, last_used_at: null, revoked_at: null, active: true }] });
    window.confirm = jest.fn(() => true);
    render(<IntakeKeysPanel orgUnitId={2} requirements={requirements} />);
    await waitFor(() => expect(screen.getByTestId('intake-key-4')).toBeInTheDocument());
    fireEvent.click(screen.getByText('Revoke'));
    await waitFor(() =>
      expect((global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=key-revoke'))).toBe(true)
    );
    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=key-revoke'));
    expect(JSON.parse(call[1].body)).toEqual({ org_unit_id: 2, id: 4 });
  });

  test('an unmatched arrival is matched to a person the server found', async () => {
    mockFetch({
      arrivals: [{ id: 21, org_unit_id: 2, key_id: 3, email: 'h.head@gotr.org', requirement_key: 'safesport', completed_on: '2026-09-01', external_id: 'x9', reason: 'no_person', received_at: '2026-09-06 09:00:00' }],
    });
    render(<IntakeKeysPanel orgUnitId={2} requirements={requirements} />);
    const row = await screen.findByTestId('arrival-21');
    expect(row).toHaveTextContent('h.head@gotr.org');
    expect(row).toHaveTextContent('no matching person');

    fireEvent.click(screen.getByText('Match to person'));
    fireEvent.change(screen.getByLabelText('Person (name or email)'), { target: { value: 'hana' } });
    fireEvent.click(await screen.findByText('Hana Head'));
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));

    await waitFor(() =>
      expect((global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=match'))).toBe(true)
    );
    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=match'));
    expect(JSON.parse(call[1].body)).toEqual({ org_unit_id: 2, id: 21, user_id: 50 });
  });

  test('a no_requirement arrival needs a requirement chosen before it can be recorded', async () => {
    mockFetch({
      arrivals: [{ id: 22, org_unit_id: 2, key_id: 3, email: 'head@gotr.org', requirement_key: 'safe-sport-2026', completed_on: '2026-09-01', external_id: null, reason: 'no_requirement', received_at: null }],
    });
    render(<IntakeKeysPanel orgUnitId={2} requirements={requirements} />);
    await screen.findByTestId('arrival-22');
    fireEvent.click(screen.getByText('Match to person'));
    fireEvent.change(screen.getByLabelText('Person (name or email)'), { target: { value: 'hana' } });
    fireEvent.click(await screen.findByText('Hana Head'));

    const record = screen.getByRole('button', { name: 'Record completion' });
    expect(record).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Requirement'), { target: { value: '10' } });
    expect(record).toBeEnabled();
    fireEvent.click(record);

    await waitFor(() =>
      expect((global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=match'))).toBe(true)
    );
    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=match'));
    expect(JSON.parse(call[1].body)).toEqual({ org_unit_id: 2, id: 22, user_id: 50, requirement_id: 10 });
  });
});

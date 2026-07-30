import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { ConsentGate } from '../components/ConsentGate';

jest.mock('../../contexts/AuthContext', () => ({ useAuth: jest.fn() }));
jest.mock('../../contexts/FinancialPermissionsContext', () => ({
  useFinancialPermissions: jest.fn(),
}));

import { useAuth } from '../../contexts/AuthContext';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';

const mockAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockPerms = useFinancialPermissions as jest.MockedFunction<typeof useFinancialPermissions>;

const mockLogout = jest.fn();

const setAthletes = (athletes: Array<{ id: number; first_name: string; last_name: string }>) =>
  mockPerms.mockReturnValue({ accessibleAthletes: athletes, loading: false } as any);

/** consent.php?action=status shape, reduced to what the gate reads. */
const statusRows = (rows: unknown[]) => ({ success: true, consents: rows });

const given = (type: string, confirmed = true) => ({
  consent_type: type,
  consent_given: true,
  revoked_at: null,
  email_confirmed_at: confirmed ? '2026-07-30T10:00:00Z' : null,
});

describe('ConsentGate', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
    mockAuth.mockReturnValue({ user: { id: 42 }, logout: mockLogout } as any);
  });

  test('blocks the portal when a child has no consent on file', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => statusRows([]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText('Parental consent')).toBeInTheDocument();
    expect(screen.queryByText('PORTAL')).not.toBeInTheDocument();
    // The child it covers is named explicitly.
    expect(screen.getByText('Rachel Jones')).toBeInTheDocument();
  });

  test('lets the portal through once both consents are on file', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => statusRows([given('data_collection'), given('medical_data')]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText('PORTAL')).toBeInTheDocument();
    expect(screen.queryByText('Parental consent')).not.toBeInTheDocument();
  });

  test('both statements are required before agreeing is possible', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => statusRows([]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);
    await screen.findByText('Parental consent');

    const agree = screen.getByText('I agree') as HTMLButtonElement;
    expect(agree).toBeDisabled();

    const boxes = screen.getAllByRole('checkbox');
    fireEvent.click(boxes[0]);
    expect(agree).toBeDisabled(); // one is not enough

    fireEvent.click(boxes[1]);
    expect(agree).not.toBeDisabled();
  });

  /**
   * The point of the per-child record: one parent, one agreement, but the stored
   * artifact is per (child × type) because consent is about a specific child.
   */
  test('records a separate row per child and per consent type', async () => {
    setAthletes([
      { id: 1, first_name: 'Rachel', last_name: 'Jones' },
      { id: 2, first_name: 'Sam', last_name: 'Jones' },
    ]);
    global.fetch = jest.fn().mockImplementation((url: string) =>
      Promise.resolve({
        ok: true,
        json: async () =>
          String(url).includes('action=record')
            ? { success: true, consent_id: 1 }
            : statusRows([]),
      })
    ) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);
    await screen.findByText('Parental consent');

    expect(screen.getByText('Rachel Jones')).toBeInTheDocument();
    expect(screen.getByText('Sam Jones')).toBeInTheDocument();

    screen.getAllByRole('checkbox').forEach((b) => fireEvent.click(b));
    fireEvent.click(screen.getByText('I agree'));

    await waitFor(() => {
      const records = (global.fetch as jest.Mock).mock.calls
        .filter(([u]) => String(u).includes('action=record'))
        .map(([, opts]) => JSON.parse(opts.body));
      // 2 children x 2 consent types
      expect(records).toHaveLength(4);
      expect(records.every((r) => r.guardian_id === 42 && r.consent_given === true)).toBe(true);
      expect(new Set(records.map((r) => r.athlete_id))).toEqual(new Set([1, 2]));
      expect(new Set(records.map((r) => r.consent_type))).toEqual(
        new Set(['data_collection', 'medical_data'])
      );
    });
  });

  /** A child already covered must not be re-recorded when a sibling is added. */
  test('only records for the child who is missing consent', async () => {
    setAthletes([
      { id: 1, first_name: 'Rachel', last_name: 'Jones' },
      { id: 2, first_name: 'Sam', last_name: 'Jones' },
    ]);
    global.fetch = jest.fn().mockImplementation((url: string) => {
      const u = String(url);
      if (u.includes('action=record')) {
        return Promise.resolve({ ok: true, json: async () => ({ success: true }) });
      }
      // Rachel (id 1) is already covered; Sam (id 2) is not.
      const consented = u.includes('athlete_id=1');
      return Promise.resolve({
        ok: true,
        json: async () =>
          statusRows(consented ? [given('data_collection'), given('medical_data')] : []),
      });
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);
    await screen.findByText('Parental consent');

    // Only the child who needs it is listed.
    expect(screen.queryByText('Rachel Jones')).not.toBeInTheDocument();
    expect(screen.getByText('Sam Jones')).toBeInTheDocument();

    screen.getAllByRole('checkbox').forEach((b) => fireEvent.click(b));
    fireEvent.click(screen.getByText('I agree'));

    await waitFor(() => {
      const records = (global.fetch as jest.Mock).mock.calls
        .filter(([x]) => String(x).includes('action=record'))
        .map(([, opts]) => JSON.parse(opts.body));
      expect(records).toHaveLength(2);
      expect(records.every((r) => r.athlete_id === 2)).toBe(true);
    });
  });

  /**
   * Non-dismissible, but not a trap: declining explains and offers sign-out. It
   * must never quietly drop the parent into the portal — that would record no
   * consent while behaving as though consent had been given.
   */
  test('declining explains and offers sign-out rather than dismissing', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => statusRows([]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);
    await screen.findByText('Parental consent');

    fireEvent.click(screen.getByText("I don't agree"));

    expect(await screen.findByText(/need a parent's consent first/i)).toBeInTheDocument();
    expect(screen.queryByText('PORTAL')).not.toBeInTheDocument();

    fireEvent.click(screen.getByText('Sign out'));
    expect(mockLogout).toHaveBeenCalled();
  });

  /** A failing status read must not lock a parent out of their own portal. */
  test('a status-read failure does not block the portal', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockRejectedValue(new Error('offline')) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText('PORTAL')).toBeInTheDocument();
  });

  /** Recorded-but-unconfirmed is usable, with a nudge — not a second wall. */
  test('recorded but unconfirmed consent shows a banner and still lets the portal through', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () =>
        statusRows([given('data_collection', false), given('medical_data', false)]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText('PORTAL')).toBeInTheDocument();
    expect(screen.getByText(/Check your email to confirm/i)).toBeInTheDocument();
  });
});

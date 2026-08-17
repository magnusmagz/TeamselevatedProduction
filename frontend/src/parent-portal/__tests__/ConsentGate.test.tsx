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
  mockPerms.mockReturnValue({ myChildren: athletes, loading: false } as any);

/** consent.php?action=status shape, reduced to what the gate reads. */
const statusRows = (rows: unknown[]) => ({ success: true, consents: rows });

const given = (type: string, confirmed = true, source = 'portal') => ({
  consent_type: type,
  consent_given: true,
  revoked_at: null,
  email_confirmed_at: confirmed ? '2026-07-30T10:00:00Z' : null,
  source,
  consented_at: '2026-07-28T09:00:00Z',
});

/** Consent captured on the public registration form (migration 063). */
const givenAtSignup = (type: string) => given(type, false, 'registration');

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

  /**
   * THE DOUBLE-CONSENT FLOW. Agreeing on the public registration form is now
   * recorded (lib/consent_capture.php), but it does NOT clear this gate — the
   * portal re-affirmation is deliberate, and it is what ties the consent to an
   * actual account rather than to a sign-up form. Keyed on source, so if the gate
   * ever starts clearing on a registration row the second prompt silently
   * disappears for every family who signed up online.
   */
  test('consent given at sign-up does not clear the gate', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () =>
        statusRows([givenAtSignup('data_collection'), givenAtSignup('medical_data')]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText('Confirm your consent')).toBeInTheDocument();
    expect(screen.queryByText('PORTAL')).not.toBeInTheDocument();
  });

  /** Someone who already agreed is asked to confirm, not asked cold. */
  test('a family who agreed at sign-up sees re-affirmation copy with the date', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () =>
        statusRows([givenAtSignup('data_collection'), givenAtSignup('medical_data')]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText(/You agreed to this when you signed up/)).toBeInTheDocument();
    expect(screen.getByText(/July 28, 2026/)).toBeInTheDocument();
  });

  /** A family with no prior record gets the plain first-ask wording. */
  test('a family with no sign-up consent gets the first-ask copy', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => statusRows([]),
    }) as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText('Parental consent')).toBeInTheDocument();
    expect(screen.queryByText(/You agreed to this when you signed up/)).not.toBeInTheDocument();
  });

  /**
   * Rows written before migration 063 carry no source. The migration backfilled
   * them to 'portal' because ConsentGate was the only writer that ever existed,
   * and the client defaults the same way — otherwise a backend that hasn't been
   * migrated yet would re-prompt families who already confirmed.
   */
  test('a row with no source is treated as portal consent', async () => {
    setAthletes([{ id: 1, first_name: 'Rachel', last_name: 'Jones' }]);
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () =>
        statusRows([
          { consent_type: 'data_collection', consent_given: true, revoked_at: null, email_confirmed_at: '2026-07-30T10:00:00Z' },
          { consent_type: 'medical_data', consent_given: true, revoked_at: null, email_confirmed_at: '2026-07-30T10:00:00Z' },
        ]),
    }) as any;

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

  /**
   * A coach who is also a parent must be asked about their OWN child only.
   *
   * accessibleAthletes on FinancialPermissionsContext is "whose finances may I see"
   * and includes every athlete on the teams this user coaches. The gate used to read
   * it, so Luis Escamilla (coach of team 79, father of one athlete on it) was asked
   * to give parental consent for eleven children. That was not just a disclosure of
   * the roster: consent.php?action=record correctly 422s a non-guardian, handleSubmit
   * throws on the first failure, and the gate is what stands between him and the
   * portal — so the parent portal was UNREACHABLE for him. He pressed Submit five
   * times on 2026-08-17, re-recording his own son's consent each time.
   *
   * This test fails on the old code, which is the point of it.
   */
  test('a coach-parent is asked about their own child, never their roster', async () => {
    mockPerms.mockReturnValue({
      myChildren: [{ id: 448, first_name: 'Luis', last_name: 'Escamilla' }],
      // Deliberately WIDER, exactly as the endpoint returns for a coach-parent.
      accessibleAthletes: [
        { id: 448, first_name: 'Luis', last_name: 'Escamilla' },
        { id: 500, first_name: 'Teammate', last_name: 'One' },
        { id: 501, first_name: 'Teammate', last_name: 'Two' },
      ],
      loading: false,
    } as any);

    const fetchMock = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => statusRows([]),
    });
    global.fetch = fetchMock as any;

    render(<ConsentGate><div>PORTAL</div></ConsentGate>);

    expect(await screen.findByText('Parental consent')).toBeInTheDocument();
    expect(screen.getByText('Luis Escamilla')).toBeInTheDocument();

    // Neither teammate is named on a screen asking for PARENTAL consent.
    expect(screen.queryByText('Teammate One')).not.toBeInTheDocument();
    expect(screen.queryByText('Teammate Two')).not.toBeInTheDocument();

    // And nothing was even asked about them. One athlete, one status call — this is
    // the assertion that fails loudest if the gate goes back to the wider list.
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(fetchMock.mock.calls[0][0]).toContain('athlete_id=448');
  });
});

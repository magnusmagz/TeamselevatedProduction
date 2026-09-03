import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MyRequirements } from '../../pages/MyRequirements';

/**
 * The coach's own page (GOTR G4).
 *
 * The load-bearing assertion is the DOCUMENT one: uploads go to the dyno's
 * local disk today and do not survive a restart (decision 14), so an enabled
 * button here would take a coach's certificate and silently lose it. Every
 * other proof type gets a real action.
 */

const base = {
  org_unit_id: null,
  club_profile_id: 100,
  kind: 'training',
  description: null,
  validity_days: 365,
  required: true,
  active: true,
  sort_order: 1,
  roles: [] as string[],
};

const row = (id: number, name: string, proof: string, extra: Record<string, unknown> = {}) => ({
  requirement: { ...base, id, name, proof, proof_url: proof === 'external_link' ? 'https://train.example' : null },
  status: 'missing',
  completed_at: null,
  expires_at: null,
  days_to_expiry: null,
  credential_id: null,
  document_id: null,
  rejection_reason: null,
  source: null,
  ...extra,
});

const ok = (body: unknown) => ({ ok: true, status: 200, json: async () => body });

const threeProofTypes = {
  success: true,
  available: true,
  clubs: [
    {
      club_id: 100,
      rollup: { compliant: false, missing: 3, expiring_30: 0, expired: 0, required_total: 3, total: 3 },
      requirements: [
        row(1, 'Concussion protocol', 'attested_date'),
        row(2, 'State training', 'external_link'),
        row(3, 'SafeSport certificate', 'document'),
      ],
    },
  ],
};

describe('MyRequirements', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tok');
    global.fetch = jest.fn(async () => ok(threeProofTypes)) as any;
  });

  test('each proof type gets its own action, and the document one is disabled', async () => {
    render(<MyRequirements />);
    await waitFor(() => expect(screen.getByText('Concussion protocol')).toBeInTheDocument());

    // attested_date — "I completed this", no link.
    const attested = screen.getByTestId('requirement-1');
    expect(attested).toHaveTextContent('I completed this');
    expect(attested.querySelector('a')).toBeNull();

    // external_link — open the link AND then attest a date.
    const external = screen.getByTestId('requirement-2');
    expect(external.querySelector('a')).toHaveAttribute('href', 'https://train.example');
    expect(external).toHaveTextContent('I completed this');

    // document — disabled, with the storage note. NOT hidden: the coach has to
    // know the requirement exists and what to do instead.
    const document_ = screen.getByTestId('requirement-3');
    const button = document_.querySelector('button');
    expect(button).toBeDisabled();
    expect(document_).toHaveTextContent(/uploads arrive with durable storage/i);
    expect(document_).toHaveTextContent(/send your certificate to your club/i);
  });

  test('attesting a date posts only the requirement — never a user id', async () => {
    render(<MyRequirements />);
    await waitFor(() => expect(screen.getByText('Concussion protocol')).toBeInTheDocument());

    const attested = screen.getByTestId('requirement-1');
    fireEvent.click(within(attested, 'I completed this'));

    fireEvent.change(screen.getByLabelText('I completed this on'), {
      target: { value: '2026-08-14' },
    });
    fireEvent.click(screen.getByText('Send to my club'));

    await waitFor(() =>
      expect(
        (global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=submit'))
      ).toBe(true)
    );

    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=submit'));
    const body = JSON.parse(call[1].body);

    expect(body).toEqual({ requirement_id: 1, completed_at: '2026-08-14' });
    // The subject is the token. A user_id here would make this a way to record
    // somebody else's completion.
    expect(body.user_id).toBeUndefined();
  });

  test('a status chip is readable without opening anything', async () => {
    (global.fetch as jest.Mock).mockImplementation(async () =>
      ok({
        success: true,
        available: true,
        clubs: [
          {
            club_id: 100,
            rollup: { compliant: false, missing: 0, expiring_30: 1, expired: 1, required_total: 2, total: 2 },
            requirements: [
              row(1, 'Nearly due', 'attested_date', {
                status: 'verified',
                expires_at: '2026-09-20',
                days_to_expiry: 14,
              }),
              row(2, 'Lapsed', 'attested_date', {
                status: 'expired',
                expires_at: '2026-08-01',
                days_to_expiry: -36,
              }),
            ],
          },
        ],
      })
    );

    render(<MyRequirements />);
    await waitFor(() => expect(screen.getByText('Nearly due')).toBeInTheDocument());

    // `verified` inside 30 days must NOT read as a green "Verified" — that is
    // the whole reason anybody renews anything.
    expect(screen.getByText('Expires in 14 days')).toBeInTheDocument();
    expect(screen.getByText('Expired')).toBeInTheDocument();
  });

  test('an empty list is not presented as a green tick', async () => {
    (global.fetch as jest.Mock).mockImplementation(async () =>
      ok({ success: true, available: true, clubs: [] })
    );

    render(<MyRequirements />);

    await waitFor(() =>
      expect(screen.getByText(/has not asked you for anything yet/i)).toBeInTheDocument()
    );
    expect(screen.queryByText('Compliant')).toBeNull();
  });
});

/** Click a button by its label inside one card. */
function within(container: HTMLElement, label: string): HTMLElement {
  const match = Array.from(container.querySelectorAll('button')).find(
    (b) => b.textContent?.trim() === label
  );
  if (!match) throw new Error(`no "${label}" button in that card`);
  return match;
}

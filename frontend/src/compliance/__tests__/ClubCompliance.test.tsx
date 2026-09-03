import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ClubCompliance } from '../../pages/ClubCompliance';

/**
 * The club compliance dashboard (GOTR G4).
 *
 * The two things worth pinning: a filter chip is a SERVER round trip carrying
 * the filter the CSV also takes (a client-side filter would let the page and
 * the file disagree, which is reported as data loss), and the tiles keep
 * counting everyone while the list narrows — a filtered page that also filtered
 * its own totals cannot say "1 of 2".
 */

jest.mock('../../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 100, isClubAdmin: true }),
}));

const requirement = {
  id: 10,
  org_unit_id: 1,
  club_profile_id: null,
  kind: 'training',
  name: 'SafeSport',
  description: null,
  proof: 'attested_date',
  proof_url: null,
  validity_days: 365,
  required: true,
  active: true,
  sort_order: 1,
  roles: [],
  origin: { scope: 'national', name: 'GOTR', label: 'National — GOTR', editable: false },
};

const hana = {
  user_id: 50,
  first_name: 'Hana',
  last_name: 'Head',
  email: 'head@gotr.org',
  rollup: { compliant: true, missing: 0, expiring_30: 0, expired: 0, required_total: 1, total: 1 },
  requirements: [
    {
      requirement,
      status: 'verified',
      completed_at: '2026-01-01',
      expires_at: '2027-01-01',
      days_to_expiry: 117,
      credential_id: 1,
      document_id: null,
      rejection_reason: null,
      source: 'admin',
    },
  ],
};

const cal = {
  user_id: 52,
  first_name: 'Cal',
  last_name: 'Coach',
  email: 'coach@gotr.org',
  rollup: { compliant: false, missing: 1, expiring_30: 0, expired: 0, required_total: 1, total: 1 },
  requirements: [
    {
      requirement,
      status: 'missing',
      completed_at: null,
      expires_at: null,
      days_to_expiry: null,
      credential_id: null,
      document_id: null,
      rejection_reason: null,
      source: null,
    },
  ],
};

const summary = { total: 2, compliant: 1, expiring_30: 0, expired: 0, missing: 1 };

const ok = (body: unknown) => ({ ok: true, status: 200, json: async () => body });

describe('ClubCompliance', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tok');
    global.fetch = jest.fn(async (url: any) => {
      const asked = String(url);
      if (asked.includes('action=club-status')) {
        const people = asked.includes('filter=missing') ? [cal] : [hana, cal];
        return ok({ success: true, available: true, filter: null, summary, people });
      }
      return ok({ success: true });
    }) as any;
  });

  test('the tiles count everyone and the list shows everyone by default', async () => {
    render(<ClubCompliance />);

    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());
    expect(screen.getByText('Cal Coach')).toBeInTheDocument();
    // "1 of 2" — the summary is built before the filter, on purpose.
    expect(screen.getByTestId('tile-compliant')).toHaveTextContent('1of 2');
  });

  test('a filter chip re-asks the server with that filter', async () => {
    render(<ClubCompliance />);
    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: 'Missing' }));

    // Wait for the RELOAD to land, not merely for the list to empty — it empties
    // while "Loading…" is on screen, and asserting on that moment would pass
    // even if the second fetch never returned anything.
    await waitFor(() => expect(screen.getByText('Cal Coach')).toBeInTheDocument());
    expect(screen.queryByText('Hana Head')).toBeNull();

    const calls = (global.fetch as jest.Mock).mock.calls.map((c) => String(c[0]));
    expect(calls.some((c) => c.includes('action=club-status') && c.includes('filter=missing'))).toBe(true);

    // The tiles still speak for the whole club.
    expect(screen.getByTestId('tile-compliant')).toHaveTextContent('1of 2');
  });

  test('search narrows what is on screen without re-asking the server', async () => {
    render(<ClubCompliance />);
    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());

    const before = (global.fetch as jest.Mock).mock.calls.length;
    fireEvent.change(screen.getByLabelText('Search name or email'), { target: { value: 'cal' } });

    expect(screen.queryByText('Hana Head')).toBeNull();
    expect(screen.getByText('Cal Coach')).toBeInTheDocument();
    expect((global.fetch as jest.Mock).mock.calls.length).toBe(before);
  });

  test('the drawer shows every requirement with its status and the admin actions', async () => {
    render(<ClubCompliance />);
    await waitFor(() => expect(screen.getByText('Cal Coach')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Cal Coach'));

    expect(screen.getByText('SafeSport')).toBeInTheDocument();
    expect(screen.getByText('Not on file')).toBeInTheDocument();
    expect(screen.getByText('Record completion')).toBeInTheDocument();
  });

  test('the export cap is shown to the person who pressed the button', async () => {
    (global.fetch as jest.Mock).mockImplementation(async (url: any) => {
      if (String(url).includes('compliance-export.php')) {
        return {
          ok: true,
          status: 200,
          headers: {
            get: (k: string) =>
              k === 'X-Compliance-Export-Truncated'
                ? '40 of 1040 rows were left out (the file is capped at 1000 rows).'
                : null,
          },
          blob: async () => new Blob(['a,b'], { type: 'text/csv' }),
        };
      }
      return ok({ success: true, available: true, filter: null, summary, people: [hana, cal] });
    });

    (window.URL as any).createObjectURL = jest.fn(() => 'blob:x');
    (window.URL as any).revokeObjectURL = jest.fn();
    jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    render(<ClubCompliance />);
    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Download CSV'));

    // Nothing is rendered back from a download, so this header is the only way
    // the admin learns the file is short.
    await waitFor(() => expect(screen.getByText(/not everything fit/i)).toBeInTheDocument());
  });

  test('a refused download says why instead of saving the error as a .csv', async () => {
    (global.fetch as jest.Mock).mockImplementation(async (url: any) => {
      if (String(url).includes('compliance-export.php')) {
        return { ok: false, status: 403, json: async () => ({ error: 'Only a club administrator can download the compliance report' }) };
      }
      return ok({ success: true, available: true, filter: null, summary, people: [hana, cal] });
    });

    render(<ClubCompliance />);
    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Download CSV'));

    await waitFor(() =>
      expect(screen.getByText(/Only a club administrator can download/i)).toBeInTheDocument()
    );
  });
});

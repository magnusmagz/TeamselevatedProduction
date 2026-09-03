import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import ComplianceAlertCard, { needsAttention } from '../ComplianceAlertCard';

/**
 * The dashboard alert card (GOTR G4).
 *
 * It renders on the staff dashboard and the parent dashboard, so the property
 * that matters most is the NEGATIVE one: nothing at all when the person owes
 * nothing, when the feature is off, and when the read fails. A permanent empty
 * box on a page the whole club opens every morning trains everyone to ignore
 * the space it sits in.
 */

const requirement = {
  id: 1,
  org_unit_id: null,
  club_profile_id: 100,
  kind: 'training',
  name: 'SafeSport',
  description: null,
  proof: 'attested_date',
  proof_url: null,
  validity_days: 365,
  required: true,
  active: true,
  sort_order: 1,
  roles: [] as string[],
};

const row = (extra: Record<string, unknown>) => ({
  requirement,
  status: 'verified',
  completed_at: '2026-01-01',
  expires_at: '2027-01-01',
  days_to_expiry: 200,
  credential_id: 1,
  document_id: null,
  rejection_reason: null,
  source: 'admin',
  ...extra,
});

const ok = (body: unknown) => ({ ok: true, status: 200, json: async () => body });

const withRows = (rows: unknown[]) => ({
  success: true,
  available: true,
  clubs: [
    {
      club_id: 100,
      rollup: { compliant: false, missing: 0, expiring_30: 0, expired: 0, required_total: 1, total: 1 },
      requirements: rows,
    },
  ],
});

describe('ComplianceAlertCard', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tok');
  });

  test('counts what needs attention and links to the page', async () => {
    global.fetch = jest.fn(async () =>
      ok(withRows([row({ status: 'missing' }), row({ status: 'expired', days_to_expiry: -5 })]))
    ) as any;

    render(<ComplianceAlertCard />);

    await waitFor(() =>
      expect(screen.getByText('2 requirements need attention')).toBeInTheDocument()
    );
    expect(screen.getByRole('link')).toHaveAttribute('href', '/compliance/mine');
  });

  test('the singular reads as English, not "1 requirements needs"', async () => {
    global.fetch = jest.fn(async () => ok(withRows([row({ status: 'missing' })]))) as any;

    render(<ComplianceAlertCard />);
    await waitFor(() => expect(screen.getByText('1 requirement needs attention')).toBeInTheDocument());
  });

  test('renders NOTHING when the person is compliant', async () => {
    global.fetch = jest.fn(async () => ok(withRows([row({})]))) as any;

    const { container } = render(<ComplianceAlertCard />);

    await waitFor(() => expect((global.fetch as jest.Mock).mock.calls.length).toBe(1));
    expect(container).toBeEmptyDOMElement();
  });

  test('renders NOTHING when the read fails', async () => {
    // The feature may be switched off, the migration may not be applied, or
    // this person may hold no staff role at all. None of that is worth an error
    // box on a dashboard.
    global.fetch = jest.fn(async () => ({ ok: false, status: 503, json: async () => ({}) })) as any;

    const { container } = render(<ComplianceAlertCard />);

    await waitFor(() => expect((global.fetch as jest.Mock).mock.calls.length).toBe(1));
    expect(container).toBeEmptyDOMElement();
  });

  test('a submitted requirement is with a reviewer, not the person', () => {
    // The whole point of the review step is that it is somebody else's move,
    // so counting it here would nag a coach about work they have finished.
    expect(needsAttention(row({ status: 'submitted' }) as any)).toBe(false);
    expect(needsAttention(row({}) as any)).toBe(false);

    expect(needsAttention(row({ status: 'missing' }) as any)).toBe(true);
    expect(needsAttention(row({ status: 'expired' }) as any)).toBe(true);
    expect(needsAttention(row({ status: 'rejected' }) as any)).toBe(true);
    // verified, but inside the 30-day window.
    expect(needsAttention(row({ days_to_expiry: 12 }) as any)).toBe(true);
    expect(needsAttention(row({ days_to_expiry: 31 }) as any)).toBe(false);
  });
});

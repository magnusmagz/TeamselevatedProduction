import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { useNavigate, useParams } from 'react-router-dom';
import { OrgCompliance } from '../../pages/OrgCompliance';

// react-router-dom is mocked project-wide (src/__mocks__), and CRA resets every
// jest.fn between tests, so the route param and the navigate function are
// supplied here per test rather than by a real router.
const mockNavigate = jest.fn();

/**
 * The org-tier compliance rollup (GOTR G5).
 *
 * What is pinned: the councils render highest risk first exactly as the
 * server ordered them; a council with no staff says so rather than "0%";
 * expanding a council asks the server for THAT council under THIS org unit
 * (the drill-down is server-scoped, the page only asks); a 403 is a sentence,
 * not a crash; "Open this council" appears only when the caller actually holds
 * a club role there; and the download reads the truncation header.
 */

const mockSwitchToContext = jest.fn(async () => undefined);

jest.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 7, system_role: 'user', roles: [] } }),
}));

jest.mock('../../contexts/OrgContext', () => ({
  useOrg: () => ({
    currentClubId: 100,
    isClubAdmin: true,
    switchToContext: mockSwitchToContext,
    // The caller holds a role at Kansas (100) and not at California (101).
    availableContexts: [{ role: 'club_admin', scope_type: 'club', scope_id: 100, scope_name: 'GOTR Kansas' }],
  }),
}));

const unit = { id: 2, parent_id: 1, type: 'division', name: 'West', external_code: null, path: '/1/2/', depth: 1 };

const summary = {
  success: true,
  available: true,
  standing: 'org_viewer',
  unit,
  units: [
    { ...unit, club_count: 0 },
    { id: 3, parent_id: 2, type: 'council', name: 'Kansas', external_code: null, path: '/1/2/3/', depth: 2, club_count: 1 },
    { id: 4, parent_id: 2, type: 'council', name: 'California', external_code: null, path: '/1/2/4/', depth: 2, club_count: 1 },
  ],
  as_of: '2026-09-06',
  requirement_id: null,
  requirements: [{ id: 10, name: 'SafeSport', kind: 'training', required: true, org_unit_id: 1, club_profile_id: null }],
  total: { staff_total: 7, compliant: 3, expiring_30: 1, expired: 2, missing: 2 },
  councils: [
    {
      club_id: 100, club_name: 'GOTR Kansas', org_unit_id: 3, org_unit_name: 'Kansas', org_unit_type: 'council',
      org_unit_path: '/1/2/3/', staff_total: 3, compliant: 1, expiring_30: 1, expired: 1, missing: 2,
      non_compliant: 2, risk_share: 0.6667,
    },
    {
      club_id: 101, club_name: 'GOTR California', org_unit_id: 4, org_unit_name: 'California', org_unit_type: 'council',
      org_unit_path: '/1/2/4/', staff_total: 4, compliant: 2, expiring_30: 0, expired: 1, missing: 0,
      non_compliant: 2, risk_share: 0.5,
    },
    {
      club_id: 104, club_name: 'GOTR Nevada', org_unit_id: 7, org_unit_name: 'Nevada', org_unit_type: 'council',
      org_unit_path: '/1/2/7/', staff_total: 0, compliant: 0, expiring_30: 0, expired: 0, missing: 0,
      non_compliant: 0, risk_share: null,
    },
  ],
};

const trend = {
  success: true,
  available: true,
  months: ['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02'],
  councils: [
    { club_id: 100, club_name: 'GOTR Kansas', by_month: [1, 0, 0, 0, 0, 0] },
    { club_id: 101, club_name: 'GOTR California', by_month: [0, 0, 1, 0, 0, 0] },
    { club_id: 104, club_name: 'GOTR Nevada', by_month: [0, 0, 0, 0, 0, 0] },
  ],
};

const requirement = {
  id: 10, org_unit_id: 1, club_profile_id: null, kind: 'training', name: 'SafeSport', description: null,
  proof: 'attested_date', proof_url: null, validity_days: 365, required: true, active: true, sort_order: 1, roles: [],
  origin: { scope: 'national', name: 'GOTR', label: 'National — GOTR', editable: false },
};

const club = {
  success: true,
  available: true,
  club: { id: 100, name: 'GOTR Kansas' },
  summary: { total: 3, compliant: 1, expiring_30: 1, expired: 1, missing: 2 },
  people: [
    {
      user_id: 50, first_name: 'Hana', last_name: 'Head', email: 'hana@gotr.org', staff_roles: ['head_coach'],
      rollup: { compliant: true, missing: 0, expiring_30: 1, expired: 0, required_total: 2, total: 2 },
      requirements: [{
        requirement, status: 'verified', completed_at: '2025-09-16', expires_at: '2026-09-16', days_to_expiry: 10,
        credential_id: 1, document_id: null, rejection_reason: null, source: 'admin',
      }],
    },
  ],
};

const ok = (body: unknown, headers: Record<string, string> = {}) => ({
  ok: true,
  status: 200,
  headers: { get: (name: string) => headers[name] ?? null },
  json: async () => body,
  blob: async () => new Blob(['x']),
});

function mount() {
  return render(<OrgCompliance />);
}

describe('OrgCompliance', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tok');
    mockSwitchToContext.mockClear();
    mockNavigate.mockClear();
    (useParams as jest.Mock).mockReturnValue({ id: '2' });
    (useNavigate as jest.Mock).mockReturnValue(mockNavigate);
    window.URL.createObjectURL = jest.fn(() => 'blob:x');
    window.URL.revokeObjectURL = jest.fn();
    global.fetch = jest.fn(async (url: any) => {
      const asked = String(url);
      if (asked.includes('view=summary')) return ok(summary);
      if (asked.includes('view=trend')) return ok(trend);
      if (asked.includes('view=club')) return ok(club);
      if (asked.includes('compliance-export.php')) {
        return ok({}, {
          'Content-Disposition': 'attachment; filename="compliance-west-all-2026-09-06.csv"',
          'X-Compliance-Export-Truncated': '9 of 1009 rows were left out.',
        });
      }
      return ok({ success: true });
    }) as any;
  });

  test('councils render in the order the server sent, highest risk first', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR Kansas')).toBeInTheDocument());

    const rows = screen.getAllByTestId('council-row');
    expect(rows[0]).toHaveTextContent('GOTR Kansas');
    expect(rows[1]).toHaveTextContent('GOTR California');
    expect(rows[2]).toHaveTextContent('GOTR Nevada');
    expect(rows[0]).toHaveTextContent('67%');
    // No staff is not 0% at risk.
    expect(rows[2]).toHaveTextContent('No staff');
    expect(rows[2]).not.toHaveTextContent('0%');

    expect(screen.getByTestId('tile-compliant')).toHaveTextContent('3of 7');
    // The summary was asked for THIS org unit.
    expect((global.fetch as jest.Mock).mock.calls.some(([u]) => String(u).includes('view=summary&org_unit_id=2'))).toBe(true);
  });

  test('sorting by name re-orders the table on the client', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR Kansas')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /^Council/ }));
    const rows = screen.getAllByTestId('council-row');
    expect(rows[0]).toHaveTextContent('GOTR California');
    expect(rows[1]).toHaveTextContent('GOTR Kansas');
  });

  test('expanding a council asks the server for that council under this org unit', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR Kansas')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /GOTR Kansas/ }));
    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());
    expect((global.fetch as jest.Mock).mock.calls.some(([u]) =>
      String(u).includes('view=club') && String(u).includes('org_unit_id=2') && String(u).includes('club_id=100')
    )).toBe(true);
    // The person's expiry is a date-only value, rendered on its own day.
    expect(screen.getByText(/Sep 16, 2026/)).toBeInTheDocument();
  });

  test('"Open this council" appears only where the caller holds a club role', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR Kansas')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /GOTR Kansas/ }));
    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());
    const open = screen.getByRole('button', { name: 'Open this council' });
    fireEvent.click(open);
    await waitFor(() => expect(mockSwitchToContext).toHaveBeenCalledWith(100, 'club'));
    // The switch completes BEFORE the navigation — arriving on /compliance
    // with the old club still active would show the wrong council.
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/compliance'));
    expect(mockSwitchToContext.mock.invocationCallOrder[0]).toBeLessThan(mockNavigate.mock.invocationCallOrder[0]);
  });

  test('a council the caller holds no role in offers no switch', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR California')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /GOTR California/ }));
    await waitFor(() => expect(screen.getByText('Hana Head')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Open this council' })).not.toBeInTheDocument();
    expect(screen.getByText(/no role at this council/i)).toBeInTheDocument();
  });

  test('the trend renders one bar cell per month per council', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR Kansas')).toBeInTheDocument());
    const kansas = screen.getByTestId('trend-100');
    expect(kansas.querySelectorAll('[data-testid="trend-cell"]')).toHaveLength(6);
    expect(kansas).toHaveTextContent('1');
    expect(screen.getByText('Sep 2026')).toBeInTheDocument();
  });

  test('a 403 is a sentence, not a crash', async () => {
    (global.fetch as jest.Mock).mockImplementation(async () => ({
      ok: false,
      status: 403,
      headers: { get: () => null },
      json: async () => ({ success: false, error: 'You do not have standing at this organization' }),
    }));
    mount();
    await waitFor(() =>
      expect(screen.getByText('You do not have standing at this organization')).toBeInTheDocument()
    );
    expect(screen.queryByTestId('council-row')).not.toBeInTheDocument();
  });

  test('the download fetches with the token for this org unit and reports the cap', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR Kansas')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Download CSV' }));

    await waitFor(() => expect(screen.getByText(/9 of 1009 rows/)).toBeInTheDocument());
    const call = (global.fetch as jest.Mock).mock.calls.find(([u]) => String(u).includes('compliance-export.php'));
    expect(String(call[0])).toContain('org_unit_id=2');
    expect(call[1].headers.Authorization).toBe('Bearer tok');
  });

  test('a requirement filter is a server round trip', async () => {
    mount();
    await waitFor(() => expect(screen.getByText('GOTR Kansas')).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText('Requirement'), { target: { value: '10' } });
    await waitFor(() =>
      expect((global.fetch as jest.Mock).mock.calls.some(([u]) => String(u).includes('requirement_id=10'))).toBe(true)
    );
  });
});

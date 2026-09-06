import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

/**
 * The national onboarding funnel (GOTR G6): per council under an org unit,
 * how far its coaches have got — accounts, invited, accepted, signed in,
 * compliant. Mirrors api/onboarding-funnel.php; that endpoint is the access
 * control (org_admin / org_viewer at the unit, inherited down), and this page
 * shows its refusal rather than an empty table.
 *
 * `compliant` is null when a council defines no requirements. It renders as
 * "n/a" — zero would read as an emergency, and it is not one.
 */

interface CouncilRow {
  club_id: number;
  club_name: string;
  org_unit_id: number;
  org_unit_name: string;
  council_code: string | null;
  accounts: number;
  invited: number;
  accepted: number;
  signed_in: number;
  compliant: number | null;
}

interface Totals {
  accounts: number;
  invited: number;
  accepted: number;
  signed_in: number;
  compliant: number | null;
}

interface FunnelResponse {
  success?: boolean;
  error?: string;
  standing?: string;
  available?: boolean;
  org_unit?: { id: number; name: string; type: string; external_code: string | null } | null;
  councils?: CouncilRow[];
  totals?: Totals;
  compliance_capped?: boolean;
}

const METRICS: Array<{ key: keyof Totals; label: string; hint: string }> = [
  { key: 'accounts', label: 'Accounts', hint: 'Coach role in the council' },
  { key: 'invited', label: 'Invited', hint: 'Sent a set-your-password link' },
  { key: 'accepted', label: 'Accepted', hint: 'Set a password through their link' },
  { key: 'signed_in', label: 'Signed in', hint: 'Has ever signed in' },
  { key: 'compliant', label: 'Compliant', hint: 'Every required credential verified' },
];

const cell = (v: number | null | undefined): string => (v === null || v === undefined ? 'n/a' : String(v));

const OnboardingFunnel: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const orgUnitId = Number(id);

  const [data, setData] = useState<FunnelResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(`${API_URL}/api/onboarding-funnel.php?org_unit_id=${orgUnitId}`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      const body: FunnelResponse = await response.json();
      if (!response.ok || body.success === false) {
        setError(body.error || 'Could not load the onboarding funnel.');
        setData(null);
      } else {
        setData(body);
      }
    } catch {
      setError('Could not load the onboarding funnel.');
    } finally {
      setLoading(false);
    }
  }, [orgUnitId]);

  useEffect(() => {
    if (Number.isFinite(orgUnitId) && orgUnitId > 0) {
      load();
    } else {
      setError('No organization selected.');
      setLoading(false);
    }
  }, [orgUnitId, load]);

  return (
    <main className="max-w-6xl mx-auto px-4 py-8">
      <div className="flex items-start justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-brand-primary">
            {data?.org_unit ? `${data.org_unit.name} — coach onboarding` : 'Coach onboarding'}
          </h1>
          <p className="text-sm text-gray-600 mt-1">
            One row per council. Accepted means the coach set a password through their own invite
            link; a password an admin typed does not count.
          </p>
        </div>
        <Link
          to={`/imports/national-coaches?org_unit_id=${orgUnitId}`}
          className="shrink-0 bg-white border border-gray-200 hover:border-brand-primary rounded-lg p-4 w-56"
        >
          <span className="block text-lg font-semibold text-brand-primary">Import coaches</span>
          <span className="block text-xs text-gray-600 mt-1">
            One CSV for every council, with a council code per row. Each coach is invited by email.
          </span>
        </Link>
      </div>

      {loading && <p className="text-gray-600">Loading…</p>}

      {!loading && error && (
        <div role="alert" className="bg-red-50 border border-red-200 text-red-800 p-4">
          {error}
        </div>
      )}

      {!loading && !error && data && data.available === false && (
        <p className="text-gray-600">
          This organization is not set up yet — the organization tree is not available on this environment.
        </p>
      )}

      {!loading && !error && data && data.available !== false && (
        <>
          {data.compliance_capped && (
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 p-3 mb-3">
              Compliance was evaluated for the first 2,000 coaches only; the Compliant column is a lower bound.
            </p>
          )}
          <div className="overflow-x-auto">
            <table aria-label="Onboarding funnel" className="min-w-full text-sm border border-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="text-left px-3 py-2">Council</th>
                  <th className="text-left px-3 py-2">Code</th>
                  {METRICS.map((m) => (
                    <th key={m.key} className="text-right px-3 py-2" title={m.hint}>{m.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {(data.councils || []).length === 0 && (
                  <tr>
                    <td colSpan={2 + METRICS.length} className="px-3 py-4 text-gray-600">
                      No councils are attached under this organization yet.
                    </td>
                  </tr>
                )}
                {(data.councils || []).map((row) => (
                  <tr key={row.club_id}>
                    <td className="px-3 py-2 font-medium">{row.club_name}</td>
                    <td className="px-3 py-2 text-gray-600">{row.council_code ?? '—'}</td>
                    {METRICS.map((m) => (
                      <td key={m.key} className="px-3 py-2 text-right tabular-nums">{cell(row[m.key])}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
              {data.totals && (
                <tfoot className="bg-gray-50 font-semibold">
                  <tr>
                    <td className="px-3 py-2">Totals</td>
                    <td className="px-3 py-2" />
                    {METRICS.map((m) => (
                      <td key={m.key} className="px-3 py-2 text-right tabular-nums">{cell(data.totals?.[m.key])}</td>
                    ))}
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </>
      )}
    </main>
  );
};

export default OnboardingFunnel;

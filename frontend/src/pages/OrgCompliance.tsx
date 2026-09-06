import React from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import PageHeader from '../components/ui/PageHeader';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';
import { formatDateOnly } from '../utils/dateFormat';
import StatusChip from '../compliance/StatusChip';
import { API_URL, authHeaders } from '../compliance/api';
import {
  fetchRollupClub,
  fetchRollupSummary,
  fetchRollupTrend,
  monthLabel,
  type CouncilRollup,
  type CouncilTrend,
  type OrgUnit,
  type RollupClubResponse,
  type RollupPerson,
  type RollupRequirement,
  type RollupSummaryResponse,
} from '../compliance/rollupApi';
import { useOrgStanding } from '../compliance/useOrgStanding';
import IntakeKeysPanel from '../compliance/IntakeKeysPanel';

/**
 * Division / national compliance — `/organizations/:id/compliance` (GOTR G5).
 *
 * Summary tiles across every council under the org unit, a table by council
 * (highest risk first, sortable), the next six months of expiries as a bar row
 * per council, a per-person drill-down, and a CSV of the whole tree.
 *
 * ⚠️ THE ROUTE GUARD IS AUTHENTICATION ONLY. Whether this person may see this
 * org unit is decided by api/compliance-rollup.php on every request; the nav
 * entry and the unit picker are conveniences. A 403 from the server renders as
 * its sentence, and the page then shows nothing else.
 *
 * ⚠️ THE ORDER IS THE SERVER'S. "Highest risk" is computed once, server-side,
 * and the table renders it as sent; the column sorts here are a client-side
 * re-ordering of the same rows and never change what the rows contain.
 *
 * ⚠️ THE DRILL-DOWN IS SCOPED BY THE SERVER TOO. Expanding a council asks for
 * `?view=club&org_unit_id=THIS&club_id=THAT`, and the server refuses a club
 * that is not under this unit. The page only asks.
 *
 * "Open this council" switches the active club through the existing context
 * switcher, and only renders when the caller actually holds a club role there
 * — `auth-gateway.php?action=switch-context` checks `user_club_access`, so an
 * org-only admin would get a 403 from a button that looked like it worked.
 *
 * The download is a fetch with the bearer token, never a plain link (same
 * reason as RosterDownloadButton), and it reads the truncation header.
 */

type SortKey = 'risk' | 'name' | 'division' | 'staff_total' | 'compliant' | 'expiring_30' | 'expired' | 'missing';

interface SortState {
  key: SortKey;
  dir: 'asc' | 'desc';
}

function fullName(person: RollupPerson): string {
  return `${person.first_name || ''} ${person.last_name || ''}`.trim() || (person.email || 'Unnamed');
}

function percent(share: number | null): string {
  if (share === null) return 'No staff';
  return `${Math.round(share * 100)}%`;
}

/** The nearest ancestor of type `division` for a council, from the flat tree the server sent. */
function divisionFor(council: CouncilRollup, units: OrgUnit[]): string {
  let best: OrgUnit | null = null;
  for (const unit of units) {
    if (unit.type !== 'division') continue;
    if (!council.org_unit_path.startsWith(unit.path)) continue;
    if (!best || unit.path.length > best.path.length) best = unit;
  }
  return best ? best.name : '—';
}

export const OrgCompliance: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const orgUnitId = Number(id);
  const navigate = useNavigate();
  const { availableContexts, switchToContext } = useOrg();
  const { units: myUnits } = useOrgStanding();

  const [summary, setSummary] = React.useState<RollupSummaryResponse | null>(null);
  const [trend, setTrend] = React.useState<{ months: string[]; byClub: Map<number, CouncilTrend> } | null>(null);
  const [requirementId, setRequirementId] = React.useState<number | null>(null);
  const [sort, setSort] = React.useState<SortState>({ key: 'risk', dir: 'desc' });
  const [openClub, setOpenClub] = React.useState<number | null>(null);
  const [clubData, setClubData] = React.useState<Record<number, RollupClubResponse>>({});
  const [clubError, setClubError] = React.useState<string | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [downloading, setDownloading] = React.useState(false);
  const [switching, setSwitching] = React.useState(false);

  const load = React.useCallback(async () => {
    if (!orgUnitId) return;
    setLoading(true);
    setError(null);
    try {
      const body = await fetchRollupSummary(orgUnitId, requirementId);
      setSummary(body);
    } catch (err: any) {
      setSummary(null);
      setError(err?.message || 'Could not load the rollup');
    } finally {
      setLoading(false);
    }
  }, [orgUnitId, requirementId]);

  const loadTrend = React.useCallback(async () => {
    if (!orgUnitId) return;
    try {
      const body = await fetchRollupTrend(orgUnitId);
      const byClub = new Map<number, CouncilTrend>();
      (body.councils || []).forEach((c) => byClub.set(c.club_id, c));
      setTrend({ months: body.months || [], byClub });
    } catch {
      // The trend is a second column on the same rows; a failed read leaves
      // the column blank rather than taking the page down with it.
      setTrend(null);
    }
  }, [orgUnitId]);

  React.useEffect(() => {
    load();
  }, [load]);

  React.useEffect(() => {
    loadTrend();
  }, [loadTrend]);

  // A new org unit is a new page: nothing from the previous one may linger.
  React.useEffect(() => {
    setOpenClub(null);
    setClubData({});
    setClubError(null);
    setRequirementId(null);
  }, [orgUnitId]);

  const units = React.useMemo(() => summary?.units || [], [summary]);

  const councils = React.useMemo(() => {
    const rows = [...(summary?.councils || [])];
    if (sort.key === 'risk') {
      // The server's order IS the risk order. 'desc' is that order; 'asc' reverses it.
      return sort.dir === 'desc' ? rows : rows.reverse();
    }
    const dir = sort.dir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      if (sort.key === 'name') return dir * a.club_name.localeCompare(b.club_name);
      if (sort.key === 'division') {
        return dir * divisionFor(a, units).localeCompare(divisionFor(b, units)) || a.club_name.localeCompare(b.club_name);
      }
      // 'risk', 'name' and 'division' were handled above; TS cannot see that
      // through the closure, so say so.
      const key = sort.key as 'staff_total' | 'compliant' | 'expiring_30' | 'expired' | 'missing';
      return dir * (a[key] - b[key]) || a.club_name.localeCompare(b.club_name);
    });
    return rows;
  }, [summary, sort, units]);

  const toggleSort = (key: SortKey) => {
    setSort((current) => {
      if (current.key !== key) {
        return { key, dir: key === 'name' || key === 'division' ? 'asc' : 'desc' };
      }
      return { key, dir: current.dir === 'asc' ? 'desc' : 'asc' };
    });
  };

  const expand = async (clubId: number) => {
    if (openClub === clubId) {
      setOpenClub(null);
      return;
    }
    setOpenClub(clubId);
    setClubError(null);
    if (clubData[clubId]) return;
    try {
      const body = await fetchRollupClub(orgUnitId, clubId);
      setClubData((current) => ({ ...current, [clubId]: body }));
    } catch (err: any) {
      setClubError(err?.message || 'Could not load that council');
    }
  };

  const holdsRoleAt = (clubId: number): boolean =>
    (availableContexts || []).some((ctx) => ctx.scope_type === 'club' && ctx.scope_id === clubId);

  const openCouncil = async (clubId: number) => {
    setSwitching(true);
    try {
      await switchToContext(clubId, 'club');
      navigate('/compliance');
    } catch (err: any) {
      setClubError(err?.message || 'Could not switch to that council');
    } finally {
      setSwitching(false);
    }
  };

  const download = async () => {
    setDownloading(true);
    setNotice(null);
    setError(null);
    try {
      const response = await fetch(`${API_URL}/api/compliance-export.php?org_unit_id=${orgUnitId}`, {
        headers: authHeaders(),
      });
      // fetch() does not reject on 4xx/5xx. Without this the error body would
      // be saved as the .csv.
      if (!response.ok) {
        let message = `Download failed (${response.status})`;
        try {
          const body = await response.json();
          if (body?.error) message = body.error;
        } catch {
          // Non-JSON error body — keep the status message.
        }
        setError(message);
        return;
      }
      const blob = await response.blob();
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^";]+)"?/);
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = match ? match[1] : 'compliance.csv';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);

      const truncated = response.headers.get('X-Compliance-Export-Truncated');
      if (truncated) {
        setNotice(`Downloaded, but not everything fit. ${truncated}`);
      }
    } catch (err: any) {
      setError(`Download failed: ${err?.message || 'network error'}`);
    } finally {
      setDownloading(false);
    }
  };

  if (!orgUnitId) {
    return (
      <div className="container mx-auto p-6">
        <p className="text-gray-600">Choose an organization to see its compliance rollup.</p>
      </div>
    );
  }

  const total = summary?.total;
  const unitName = summary?.unit?.name || `Organization ${orgUnitId}`;

  // Sort stays page-managed: the default is the SERVER's risk order and the
  // numeric columns open descending, neither of which DataTable's built-in
  // asc-first toggle would reproduce. The rows arrive already sorted.
  const sortHeader = (label: string, k: SortKey) => (
    <SortHeader label={label} k={k} sort={sort} onSort={toggleSort} />
  );

  const councilColumns: DataTableColumn<CouncilRollup>[] = [
    {
      key: 'name',
      header: sortHeader('Council', 'name'),
      render: (council) => (
        <>
          <button
            type="button"
            onClick={() => expand(council.club_id)}
            aria-expanded={openClub === council.club_id}
            className="text-left font-semibold text-brand-primary underline"
          >
            {council.club_name}
          </button>
          <span className="block text-xs text-gray-500">{council.org_unit_name}</span>
        </>
      ),
    },
    {
      key: 'division',
      header: sortHeader('Division', 'division'),
      render: (council) => <span className="text-gray-700">{divisionFor(council, units)}</span>,
    },
    { key: 'staff_total', header: sortHeader('Staff', 'staff_total'), align: 'right' },
    {
      key: 'compliant',
      header: sortHeader('Compliant', 'compliant'),
      align: 'right',
      render: (council) => <span className="text-green-700">{council.compliant}</span>,
    },
    {
      key: 'expiring_30',
      header: sortHeader('Expiring', 'expiring_30'),
      align: 'right',
      render: (council) => <span className="text-amber-700">{council.expiring_30}</span>,
    },
    {
      key: 'expired',
      header: sortHeader('Expired', 'expired'),
      align: 'right',
      render: (council) => <span className="text-red-700">{council.expired}</span>,
    },
    {
      key: 'missing',
      header: sortHeader('Missing', 'missing'),
      align: 'right',
      render: (council) => <span className="text-gray-700">{council.missing}</span>,
    },
    {
      key: 'risk',
      header: sortHeader('At risk', 'risk'),
      align: 'right',
      render: (council) => <RiskCell share={council.risk_share} />,
    },
    {
      key: 'trend',
      header: (
        <>
          <span className="block">Expiring next 6 months</span>
          {trend && (
            <span className="mt-1 flex gap-1 text-[10px] normal-case tracking-normal text-gray-400">
              {trend.months.map((m) => (
                <span key={m} className="w-9 text-center">{monthLabel(m)}</span>
              ))}
            </span>
          )}
        </>
      ),
      render: (council) =>
        trend ? <TrendRow clubId={council.club_id} months={trend.months} row={trend.byClub.get(council.club_id)} /> : null,
    },
  ];

  return (
    <div className="container mx-auto p-6">
      <PageHeader
        title={`${unitName} — Compliance`}
        subtitle={
          <>
            Every council under this {summary?.unit?.type || 'organization'}, highest risk first.
            {summary?.as_of ? ` As of ${formatDateOnly(summary.as_of)}.` : ''}
          </>
        }
        meta={
          myUnits.length > 1 ? (
            <label className="block text-sm text-gray-700">
              Organization{' '}
              <select
                className="ml-1 rounded-md border border-gray-300 px-2 py-1 text-sm"
                value={orgUnitId}
                onChange={(e) => navigate(`/organizations/${e.target.value}/compliance`)}
              >
                {myUnits.map((u) => (
                  <option key={u.org_unit_id} value={u.org_unit_id}>
                    {u.name} ({u.type})
                  </option>
                ))}
              </select>
            </label>
          ) : undefined
        }
        actions={
          <>
            <button
              type="button"
              onClick={download}
              disabled={downloading || !!error}
              className="rounded-md border border-brand-primary px-4 py-2 text-sm font-semibold uppercase text-brand-primary hover:bg-brand-secondary disabled:opacity-50"
            >
              {downloading ? 'Preparing…' : 'Download CSV'}
            </button>
            <label className="text-sm text-gray-700">
              <span className="sr-only">Requirement</span>
              <select
                aria-label="Requirement"
                className="rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                value={requirementId ?? ''}
                onChange={(e) => setRequirementId(e.target.value ? Number(e.target.value) : null)}
              >
                <option value="">All requirements</option>
                {(summary?.requirements || []).map((r: RollupRequirement) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                    {r.required ? '' : ' (optional)'}
                  </option>
                ))}
              </select>
            </label>
          </>
        }
      />

      {summary && summary.available === false && (
        <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
          Compliance is not switched on for this database yet.
        </div>
      )}

      {error && (
        <div role="alert" className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          {error}
        </div>
      )}

      {notice && (
        <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{notice}</div>
      )}

      {total && !error && (
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <Tile testId="tile-compliant" label="Compliant" value={total.compliant} total={total.staff_total} accent="border-green-500" />
          <Tile testId="tile-expiring" label="Expiring in 30 days" value={total.expiring_30} total={total.staff_total} accent="border-amber-500" />
          <Tile testId="tile-expired" label="Expired" value={total.expired} total={total.staff_total} accent="border-red-500" />
          <Tile testId="tile-missing" label="Missing" value={total.missing} total={total.staff_total} accent="border-gray-400" />
        </div>
      )}

      {requirementId && !error && (
        <p className="mb-3 text-xs text-gray-500">
          Counting only the people who owe this requirement. Clear the filter to count everyone on staff.
        </p>
      )}

      {loading ? (
        <p className="text-gray-500">Loading…</p>
      ) : error ? null : (
        <DataTable<CouncilRollup>
          columns={councilColumns}
          rows={councils}
          rowKey={(council) => council.club_id}
          // The row test hook. DataTable owns the <tr>, so the marker rides
          // on a class rather than a data-testid.
          rowTestId={() => 'council-row'}
          emptyState="No councils are attached under this organization yet."
          // The per-council drill-down, directly under the expanded council.
          renderExpandedRow={(council) =>
            openClub === council.club_id ? (
              <CouncilDrawer
                council={council}
                data={clubData[council.club_id] || null}
                error={clubError}
                canOpen={holdsRoleAt(council.club_id)}
                switching={switching}
                onOpen={() => openCouncil(council.club_id)}
              />
            ) : null
          }
        />
      )}

      <p className="mt-6 text-xs text-gray-500">
        This view is read-only. Requirements are managed from a council's own{' '}
        <Link to="/compliance/requirements" className="underline">
          Requirements
        </Link>{' '}
        page, or by an organization administrator.
      </p>

      {/* Intake keys and unmatched LMS arrivals (G7): org_admin only. The
          server re-checks standing on every call; this only decides whether
          to draw the section, because a viewer would get 403s from it. */}
      {summary && summary.standing === 'org_admin' && (
        <IntakeKeysPanel orgUnitId={orgUnitId} requirements={summary.requirements || []} />
      )}
    </div>
  );
};

/** The header node for a page-sorted column; DataTable supplies the <th>. */
const SortHeader: React.FC<{
  label: string;
  k: SortKey;
  sort: SortState;
  onSort: (k: SortKey) => void;
}> = ({ label, k, sort, onSort }) => (
  <button type="button" onClick={() => onSort(k)} className="uppercase hover:underline">
    {label}
    {sort.key === k ? (sort.dir === 'asc' ? ' ▲' : ' ▼') : ''}
  </button>
);

const Tile: React.FC<{ testId: string; label: string; value: number; total: number; accent: string }> = ({
  testId,
  label,
  value,
  total,
  accent,
}) => (
  <div data-testid={testId} className={`rounded-lg border-l-4 ${accent} bg-white p-4 shadow-sm`}>
    <div className="text-2xl font-bold text-brand-primary">
      {value}
      <span className="ml-1 text-sm font-normal text-gray-500">of {total}</span>
    </div>
    <div className="mt-1 text-xs uppercase tracking-wide text-gray-500">{label}</div>
  </div>
);

/** The non-compliant share. Null (no staff) is a sentence, not a zero. */
const RiskCell: React.FC<{ share: number | null }> = ({ share }) => {
  if (share === null) {
    return <span className="text-xs text-gray-400">No staff</span>;
  }
  const tone = share >= 0.5 ? 'text-red-700' : share > 0 ? 'text-amber-700' : 'text-green-700';
  return <span className={`font-semibold ${tone}`}>{percent(share)}</span>;
};

/** Six small bars, one per month. No chart library — a bar is a div with a height. */
const TrendRow: React.FC<{ clubId: number; months: string[]; row: CouncilTrend | undefined }> = ({
  clubId,
  months,
  row,
}) => {
  const counts = row?.by_month || months.map(() => 0);
  const max = Math.max(1, ...counts);
  return (
    <div data-testid={`trend-${clubId}`} className="flex items-end gap-1" aria-label="Expiries by month">
      {months.map((m, i) => {
        const n = counts[i] ?? 0;
        return (
          <div
            key={m}
            data-testid="trend-cell"
            title={`${monthLabel(m)}: ${n}`}
            className="flex w-9 flex-col items-center justify-end"
          >
            <div
              className={`w-6 rounded-t ${n > 0 ? 'bg-amber-400' : 'bg-gray-100'}`}
              style={{ height: `${4 + Math.round((n / max) * 20)}px` }}
            />
            <span className="mt-0.5 text-[10px] text-gray-600">{n}</span>
          </div>
        );
      })}
    </div>
  );
};

const CouncilDrawer: React.FC<{
  council: CouncilRollup;
  data: RollupClubResponse | null;
  error: string | null;
  canOpen: boolean;
  switching: boolean;
  onOpen: () => void;
}> = ({ council, data, error, canOpen, switching, onOpen }) => (
  <div>
    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
      <span className="text-sm font-semibold text-brand-primary">{council.club_name} — staff</span>
      {canOpen ? (
        <button
          type="button"
          onClick={onOpen}
          disabled={switching}
          className="rounded-md bg-brand-primary px-3 py-1.5 text-xs font-semibold uppercase text-white disabled:opacity-50"
        >
          {switching ? 'Switching…' : 'Open this council'}
        </button>
      ) : (
        <span className="text-xs text-gray-500">
          You hold no role at this council, so it cannot be opened from here; the rollup is read-only.
        </span>
      )}
    </div>

    {error ? (
      <p className="text-sm text-red-700">{error}</p>
    ) : !data ? (
      <p className="text-sm text-gray-500">Loading…</p>
    ) : data.people.length === 0 ? (
      <p className="text-sm text-gray-500">Nobody is on staff at this council yet.</p>
    ) : (
      <ul className="space-y-2">
        {data.people.map((person) => (
          <li key={person.user_id} className="rounded-md border border-gray-200 bg-white p-3">
            <div className="flex flex-wrap items-center gap-3">
              <span className="flex-1">
                <span className="block font-semibold text-gray-800">{fullName(person)}</span>
                <span className="block text-xs text-gray-500">
                  {person.email}
                  {person.staff_roles.length ? ` · ${person.staff_roles.join(', ')}` : ''}
                </span>
              </span>
              <PersonRollup person={person} />
            </div>
            <ul className="mt-2 space-y-1">
              {person.requirements.map((row) => (
                <li key={row.requirement.id} className="flex flex-wrap items-center gap-2 text-xs text-gray-700">
                  <span className="font-medium">{row.requirement.name}</span>
                  <StatusChip row={row} />
                  {row.requirement.origin && <span className="text-gray-500">{row.requirement.origin.label}</span>}
                  {!row.requirement.required && <span className="text-gray-500">optional</span>}
                  <span className="text-gray-500">
                    {row.expires_at ? `expires ${formatDateOnly(row.expires_at)}` : 'no expiry'}
                  </span>
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ul>
    )}
  </div>
);

const PersonRollup: React.FC<{ person: RollupPerson }> = ({ person }) => {
  const { rollup } = person;
  if (rollup.compliant) {
    return (
      <span className="rounded-full border border-green-200 bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800">
        Compliant
      </span>
    );
  }
  const parts: string[] = [];
  if (rollup.expired) parts.push(`${rollup.expired} expired`);
  if (rollup.missing) parts.push(`${rollup.missing} missing`);
  if (rollup.expiring_30) parts.push(`${rollup.expiring_30} expiring`);
  return (
    <span className="rounded-full border border-amber-300 bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">
      {parts.join(' · ') || 'Not compliant'}
    </span>
  );
};

export default OrgCompliance;

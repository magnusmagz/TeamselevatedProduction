import React, { useCallback, useEffect, useMemo, useState } from 'react';
import DataTable, { DataTableColumn } from '../ui/DataTable';
import Button from '../ui/Button';

const API_URL = process.env.REACT_APP_API_URL || '';

interface RoleRow {
  club_id: number;
  club_name: string;
  role: string;
  holders: number;
  dau: number;
  wau: number;
  mau: number;
}
interface ClubRow {
  club_id: number;
  club_name: string;
  holders: number;
  dau: number;
  wau: number;
  mau: number;
}
interface Summary {
  available: boolean;
  as_of: string;
  week_start: string;
  month_start: string;
  rows: RoleRow[];
  clubs: ClubRow[];
  error?: string;
}
interface TrendSeries {
  club_id: number;
  club_name: string;
  role: string;
  values: number[];
}
interface Trend {
  available: boolean;
  weeks: { start: string; end: string }[];
  series: TrendSeries[];
}

export const ROLE_LABELS: Record<string, string> = {
  club_admin: 'Club admins',
  coach: 'Coaches',
  treasurer: 'Treasurers',
  volunteer: 'Volunteers',
  referee: 'Referees',
  parent: 'Crew (parents)',
  player: 'Players',
  super_admin: 'Super admins',
  '*': 'Anyone',
};

export function roleLabel(role: string): string {
  return ROLE_LABELS[role] ?? role;
}

export function pct(n: number, d: number): string {
  if (d <= 0) return '–';
  return `${Math.round((n / d) * 100)}%`;
}

/**
 * Product usage: daily / weekly / monthly active users per club and per role,
 * as a share of everyone who holds that role. Super admin only; the endpoint
 * enforces that too.
 *
 * A row of zeros is a finding (nobody in that role has opened the app); a
 * missing row would read as "not tracked", so the endpoint returns every role
 * that has holders. Rates are against CURRENT holders — history is not
 * re-bucketed when a role changes hands.
 */
export const UsageMetrics: React.FC = () => {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [trend, setTrend] = useState<Trend | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [clubFilter, setClubFilter] = useState<number | 'all'>('all');
  const [asOf, setAsOf] = useState<string>('');

  const headers = useMemo<Record<string, string>>(() => {
    const token = localStorage.getItem('auth_token');
    const h: Record<string, string> = {};
    if (token) h.Authorization = `Bearer ${token}`;
    return h;
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const q = asOf ? `&as_of=${asOf}` : '';
      const [s, t] = await Promise.all([
        fetch(`${API_URL}/api/usage-metrics.php?action=summary${q}`, { headers }),
        fetch(`${API_URL}/api/usage-metrics.php?action=trend&weeks=12${q}`, { headers }),
      ]);
      const sj = (await s.json()) as Summary;
      const tj = (await t.json()) as Trend;
      if (!s.ok || !sj.available) {
        setError(sj.error || 'Usage metrics are not available.');
        setSummary(null);
        setTrend(null);
      } else {
        setSummary(sj);
        setTrend(t.ok && tj.available ? tj : null);
      }
    } catch (e) {
      setError('Could not load usage metrics.');
    } finally {
      setLoading(false);
    }
  }, [headers, asOf]);

  useEffect(() => {
    load();
  }, [load]);

  const clubs = useMemo(() => (summary ? summary.clubs.filter((c) => c.club_id !== 0) : []), [summary]);

  const roleRows = useMemo(() => {
    if (!summary) return [];
    return summary.rows.filter((r) => clubFilter === 'all' || r.club_id === clubFilter);
  }, [summary, clubFilter]);

  const clubRows = useMemo(() => {
    if (!summary) return [];
    return summary.clubs.filter((c) => clubFilter === 'all' || c.club_id === clubFilter);
  }, [summary, clubFilter]);

  const trendRows = useMemo(() => {
    if (!trend) return [];
    return trend.series
      .filter((s) => clubFilter === 'all' || s.club_id === clubFilter)
      .filter((s) => clubFilter !== 'all' || s.role === '*');
  }, [trend, clubFilter]);

  const roleColumns: DataTableColumn<RoleRow>[] = [
    { key: 'club_name', header: 'Club', sortable: true },
    { key: 'role', header: 'Role', render: (r) => roleLabel(r.role), sortable: true },
    { key: 'holders', header: 'Holders', align: 'right', sortable: true },
    { key: 'dau', header: 'DAU', align: 'right', sortable: true },
    { key: 'wau', header: 'WAU', align: 'right', sortable: true },
    { key: 'mau', header: 'MAU', align: 'right', sortable: true },
    { key: 'wau_rate', header: 'WAU rate', align: 'right', render: (r) => pct(r.wau, r.holders), sortable: true, sortValue: (r) => (r.holders ? r.wau / r.holders : -1) },
    { key: 'mau_rate', header: 'MAU rate', align: 'right', render: (r) => pct(r.mau, r.holders), sortable: true, sortValue: (r) => (r.holders ? r.mau / r.holders : -1) },
  ];

  const clubColumns: DataTableColumn<ClubRow>[] = [
    { key: 'club_name', header: 'Club', sortable: true },
    { key: 'holders', header: 'People with a role', align: 'right', sortable: true },
    { key: 'dau', header: 'DAU', align: 'right', sortable: true },
    { key: 'wau', header: 'WAU', align: 'right', sortable: true },
    { key: 'mau', header: 'MAU', align: 'right', sortable: true },
    { key: 'wau_rate', header: 'WAU rate', align: 'right', render: (r) => pct(r.wau, r.holders) },
    { key: 'mau_rate', header: 'MAU rate', align: 'right', render: (r) => pct(r.mau, r.holders) },
  ];

  const trendColumns: DataTableColumn<TrendSeries>[] = [
    { key: 'club_name', header: 'Club' },
    { key: 'role', header: 'Role', render: (s) => roleLabel(s.role) },
    ...(trend?.weeks ?? []).map((w, i) => ({
      key: `w${i}`,
      header: w.start.slice(5).replace('-', '/'),
      align: 'right' as const,
      render: (s: TrendSeries) => s.values[i] ?? 0,
    })),
  ];

  if (loading && !summary) {
    return <div className="text-gray-500">Loading usage…</div>;
  }
  if (error) {
    return (
      <div className="space-y-3">
        <p className="text-red-700">{error}</p>
        <Button variant="secondary" size="sm" onClick={load}>Retry</Button>
      </div>
    );
  }
  if (!summary) return null;

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-end gap-4">
        <label className="text-sm text-brand-primary">
          <span className="block font-medium mb-1">Club</span>
          <select
            className="border border-brand-secondary rounded px-2 py-1 text-sm"
            value={clubFilter}
            onChange={(e) => setClubFilter(e.target.value === 'all' ? 'all' : Number(e.target.value))}
          >
            <option value="all">All clubs</option>
            {clubs.map((c) => (
              <option key={c.club_id} value={c.club_id}>{c.club_name}</option>
            ))}
          </select>
        </label>
        <label className="text-sm text-brand-primary">
          <span className="block font-medium mb-1">As of</span>
          <input
            type="date"
            className="border border-brand-secondary rounded px-2 py-1 text-sm"
            value={asOf || summary.as_of}
            max={summary.as_of > (asOf || '') ? undefined : summary.as_of}
            onChange={(e) => setAsOf(e.target.value)}
          />
        </label>
        <p className="text-xs text-gray-600 max-w-xl">
          Active = opened the app while signed in. DAU is {summary.as_of}; WAU is {summary.week_start} to {summary.as_of};
          MAU is {summary.month_start} to {summary.as_of}. Rates are against everyone who currently holds the role.
          A coach who is also a parent counts in both roles and once for the club.
        </p>
      </div>

      <section>
        <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide mb-2">By club</h2>
        <DataTable<ClubRow>
          columns={clubColumns}
          rows={clubRows}
          rowKey={(r) => r.club_id}
          emptyState="No activity recorded yet."
        />
      </section>

      <section>
        <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide mb-2">By role</h2>
        <DataTable<RoleRow>
          columns={roleColumns}
          rows={roleRows}
          rowKey={(r) => `${r.club_id}|${r.role}`}
          emptyState="No roles found."
        />
      </section>

      {trend && (
        <section>
          <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide mb-2">
            Weekly active, last 12 weeks{clubFilter === 'all' ? ' (anyone, per club)' : ''}
          </h2>
          <DataTable<TrendSeries>
            columns={trendColumns}
            rows={trendRows}
            rowKey={(s) => `${s.club_id}|${s.role}`}
            emptyState="No weekly activity yet."
          />
          <p className="text-xs text-gray-600 mt-2">Each column is a 7-day window ending on the same weekday as the as-of date; the last column is the current, partial window.</p>
        </section>
      )}
    </div>
  );
};

export default UsageMetrics;

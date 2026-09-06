import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import { useFinancialPermissions } from '../contexts/FinancialPermissionsContext';
import ComplianceAlertCard from '../compliance/ComplianceAlertCard';
import { AdminSetPasswordBanner } from '../components/AdminSetPasswordBanner';
import PageHeader from '../components/ui/PageHeader';

/**
 * StaffDashboard — the staff home page (`/dashboard`, and therefore `/`).
 *
 * CKU R88: the home page used to render TeamManagement, so opening the app
 * landed on Teams and nothing else. This is an overview of the four areas a
 * club runs day to day, each tile linking to the page it counts.
 *
 * ⚠️ Every count comes from the SAME endpoint as the page its tile links to,
 * on purpose. Those endpoints already scope server-side — teams-gateway scopes
 * a non-admin down to the teams they coach, athletes-gateway uses
 * AthleteScope::accessibleAthleteFilter, programs-api checks canAccessClub —
 * so a tile can never show a number the linked page would not. Re-deriving a
 * count from a new query is how a tile starts disagreeing with its own page,
 * and how a coach's tile starts counting the whole club.
 *
 * Revenue is gated on the SERVER-derived `can_view_revenue` (club_admin,
 * treasurer or league_admin — api/financial-permissions.php), not on a local
 * role guess. A coach sees three tiles.
 */

type TileState =
  | { status: 'loading' }
  | { status: 'ready'; value: string }
  | { status: 'error' };

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

const authHeaders = () => ({
  Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
});

/** Count distinct ids. athletes-gateway once returned duplicate rows per
 *  athlete (one per guardian); the LATERAL join fixed that, but the Athletes
 *  page still dedupes and the tile must agree with it. */
const countById = (rows: unknown): number => {
  if (!Array.isArray(rows)) return 0;
  const seen = new Set<unknown>();
  rows.forEach((row) => {
    const id = row && typeof row === 'object' ? (row as { id?: unknown }).id : undefined;
    seen.add(id === undefined ? row : id);
  });
  return seen.size;
};

interface TileProps {
  label: string;
  to: string;
  state: TileState;
  hint: string;
  accent: string;
}

const Tile: React.FC<TileProps> = ({ label, to, state, hint, accent }) => (
  <Link
    to={to}
    aria-label={label}
    data-testid={`dashboard-tile-${label.toLowerCase().replace(/\s+/g, '-')}`}
    className={`bg-white p-6 rounded-lg shadow border-l-4 ${accent} hover:shadow-lg transition-shadow block`}
  >
    <div className="text-sm text-gray-600 mb-1 uppercase tracking-wide">{label}</div>
    <div className="text-3xl font-bold text-gray-900">
      {state.status === 'loading' && <span className="text-gray-300">&hellip;</span>}
      {state.status === 'ready' && state.value}
      {state.status === 'error' && (
        <span className="text-base font-medium text-gray-400">Unavailable</span>
      )}
    </div>
    <div className="text-xs text-gray-500 mt-2">{hint}</div>
    <div className="text-xs text-blue-600 mt-1">View {label} &rarr;</div>
  </Link>
);

export const StaffDashboard: React.FC = () => {
  const { currentClubId, isClubAdmin } = useOrg();
  const { permissions, loading: permissionsLoading } = useFinancialPermissions();

  const [teams, setTeams] = useState<TileState>({ status: 'loading' });
  const [athletes, setAthletes] = useState<TileState>({ status: 'loading' });
  const [programs, setPrograms] = useState<TileState>({ status: 'loading' });
  const [revenue, setRevenue] = useState<TileState>({ status: 'loading' });

  const showRevenue = permissions.can_view_revenue;

  const load = useCallback(
    async (
      url: string,
      pick: (body: any) => number | string,
      set: React.Dispatch<React.SetStateAction<TileState>>
    ) => {
      try {
        const response = await fetch(url, { headers: authHeaders() });
        if (!response.ok) {
          // A 403 here means the caller is out of scope for that area. Show
          // "Unavailable" rather than a zero — an empty club and a refused
          // read are opposite answers and must not render the same.
          set({ status: 'error' });
          return;
        }
        const body = await response.json();
        set({ status: 'ready', value: String(pick(body)) });
      } catch (err) {
        console.error(`Dashboard tile failed for ${url}:`, err);
        set({ status: 'error' });
      }
    },
    []
  );

  useEffect(() => {
    // Server-scoped: a coach gets only the teams they coach.
    load(`${API_URL}/legacy/teams-gateway.php`, (b) => countById(b?.teams), setTeams);
    // Server-scoped via AthleteScope::accessibleAthleteFilter; already excludes
    // soft-deleted athletes (delete sets active_status = false).
    load(`${API_URL}/legacy/athletes-gateway.php`, (b) => countById(b?.athletes), setAthletes);
  }, [load]);

  useEffect(() => {
    if (currentClubId == null) {
      setPrograms({ status: 'error' });
      return;
    }
    // Archived programs are excluded server-side unless include_archived=1.
    load(
      `${API_URL}/registration/programs-api.php?path=list&club_id=${currentClubId}`,
      (b) => countById(b),
      setPrograms
    );
  }, [currentClubId, load]);

  useEffect(() => {
    if (permissionsLoading) return;
    if (!showRevenue) return;
    if (currentClubId == null) {
      setRevenue({ status: 'error' });
      return;
    }
    load(
      `${API_URL}/api/revenue-summary.php?club_id=${currentClubId}`,
      (b) =>
        b?.success && b?.summary
          ? `$${Number(b.summary.collected ?? 0).toLocaleString('en-US', {
              minimumFractionDigits: 0,
              maximumFractionDigits: 0,
            })}`
          : 'Unavailable',
      setRevenue
    );
  }, [currentClubId, showRevenue, permissionsLoading, load]);

  return (
    <div className="container mx-auto p-6">
      <PageHeader
        title="Overview"
        subtitle={
          isClubAdmin
            ? 'Your club at a glance. Select any area to manage it.'
            : 'Your teams at a glance. Select any area to manage it.'
        }
      />

      {/* GOTR G4. Renders NOTHING when this person owes nothing, when the
          feature is off, and when the read fails — a dashboard the whole club
          opens every morning must not carry a permanent empty box. */}
      <ComplianceAlertCard className="mb-6" />

      {/* Renders NOTHING unless a club admin set this person's password and
          they have not changed it yet (users.password_set_by_admin_at). */}
      <AdminSetPasswordBanner />

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Tile
          label={isClubAdmin ? 'Teams' : 'My Teams'}
          to="/teams"
          state={teams}
          hint="Active teams"
          accent="border-blue-500"
        />
        <Tile
          label="Athletes"
          to="/athletes"
          state={athletes}
          hint="Active athletes"
          accent="border-green-500"
        />
        <Tile
          label="Programs"
          to="/program-management"
          state={programs}
          hint="Programs not archived"
          accent="border-purple-500"
        />
        {showRevenue && (
          <Tile
            label="Revenue"
            to="/payment/revenue"
            state={revenue}
            hint="Collected to date"
            accent="border-orange-500"
          />
        )}
      </div>
    </div>
  );
};

export default StaffDashboard;

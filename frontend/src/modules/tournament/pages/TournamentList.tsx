import React, { useState, useEffect, useCallback } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../../contexts/AuthContext';
import { useOrg } from '../../../contexts/OrgContext';
import { Tournament, TournamentStatus, TOURNAMENT_STATUS_CONFIG } from '../types';
import { listTournaments } from '../api/tournamentApi';
import PageHeader from '../../../components/ui/PageHeader';
import DataTable, { DataTableColumn } from '../../../components/ui/DataTable';
import { formatDateOnly } from '../../../utils/dateFormat';

function formatDate(dateStr: string): string {
  if (!dateStr) return '—';
  return formatDateOnly(dateStr, { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatCurrency(cents: number): string {
  return `$${(cents / 100).toFixed(2)}`;
}

const TournamentList: React.FC = () => {
  const { user } = useAuth();
  const { isClubAdmin, currentClubId, activeContext } = useOrg();
  const navigate = useNavigate();
  // Source club from the active org context (like ProgramManagement / comms),
  // falling back to the legacy user.organization.orgId. Using orgId alone left
  // the list silently empty for coaches / multi-role users whose active role
  // isn't roles[0] (cf. CommunicationLog COACH-18/19/25/26).
  const clubId = currentClubId ?? activeContext?.scope_id ?? user?.organization?.orgId;

  const [tournaments, setTournaments] = useState<Tournament[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<TournamentStatus | ''>('');

  const fetchTournaments = useCallback(async () => {
    if (!clubId) return;
    setLoading(true);
    try {
      const filters = statusFilter ? { status: statusFilter as TournamentStatus } : undefined;
      const data = await listTournaments(clubId, filters);
      setTournaments(data.tournaments || []);
    } catch (err) {
      console.error('Failed to fetch tournaments:', err);
    } finally {
      setLoading(false);
    }
  }, [clubId, statusFilter]);

  useEffect(() => {
    fetchTournaments();
  }, [fetchTournaments]);

  const isAdmin = isClubAdmin || user?.system_role === 'super_admin';

  const createLink = (
    <Link
      to="/tournaments/create"
      className="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover"
    >
      <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
      </svg>
      Create Tournament
    </Link>
  );

  const columns: DataTableColumn<Tournament>[] = [
    {
      key: 'name',
      header: 'Name',
      sortable: true,
      render: (t) => <span className="font-medium text-brand-primary">{t.name}</span>,
    },
    {
      key: 'dates',
      header: 'Dates',
      sortable: true,
      sortValue: (t) => t.start_date,
      render: (t) => (
        <span className="whitespace-nowrap">
          {formatDate(t.start_date)}
          {t.end_date !== t.start_date && ` – ${formatDate(t.end_date)}`}
        </span>
      ),
    },
    {
      key: 'location',
      header: 'Location',
      render: (t) =>
        t.venue_name || t.location_name ? (
          <>
            {t.venue_name || t.location_name}
            {t.venue_city && (
              <span className="text-gray-500 ml-1">
                — {t.venue_city}
                {t.venue_state ? `, ${t.venue_state}` : ''}
              </span>
            )}
          </>
        ) : (
          <span className="text-gray-400">—</span>
        ),
    },
    {
      key: 'divisions',
      header: 'Divisions',
      align: 'right',
      sortable: true,
      sortValue: (t) => t.division_count ?? 0,
      render: (t) => t.division_count ?? 0,
    },
    {
      key: 'teams',
      header: 'Teams',
      align: 'right',
      sortable: true,
      sortValue: (t) => t.registration_count ?? 0,
      render: (t) => t.registration_count ?? 0,
    },
    {
      key: 'fee',
      header: 'Entry fee',
      align: 'right',
      render: (t) => (t.entry_fee_cents > 0 ? formatCurrency(t.entry_fee_cents) : <span className="text-gray-400">—</span>),
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
      render: (t) => {
        const statusConfig = TOURNAMENT_STATUS_CONFIG[t.status];
        return (
          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ${statusConfig?.color || 'bg-gray-100 text-gray-700'}`}>
            {statusConfig?.label || t.status}
          </span>
        );
      },
    },
    {
      key: 'actions',
      header: 'Actions',
      actions: true,
      render: (t) => (
        <Link
          to={`/tournaments/${t.id}`}
          className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold"
        >
          Manage
        </Link>
      ),
    },
  ];

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title="Tournaments"
        subtitle="Manage your club's tournaments"
        actions={isAdmin ? createLink : undefined}
      />

      {/* Filters */}
      <div className="mb-4 flex flex-wrap gap-3 items-center">
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as TournamentStatus | '')}
          className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
        >
          <option value="">All Statuses</option>
          {Object.entries(TOURNAMENT_STATUS_CONFIG).map(([key, config]) => (
            <option key={key} value={key}>
              {config.label}
            </option>
          ))}
        </select>
      </div>

      {/* Content */}
      {loading ? (
        <div className="text-center py-12 text-gray-500">Loading tournaments...</div>
      ) : (
        <DataTable<Tournament>
          data-testid="tournament-table"
          columns={columns}
          rows={tournaments}
          rowKey={(t) => t.id}
          onRowClick={(t) => navigate(`/tournaments/${t.id}`)}
          emptyState={{
            text: (
              <>
                <div className="text-lg font-medium text-gray-900">No tournaments yet</div>
                <div className="mt-2 text-sm text-gray-500">Create your first tournament to get started.</div>
              </>
            ),
            action: isAdmin ? createLink : undefined,
          }}
        />
      )}
    </main>
  );
};

export default TournamentList;

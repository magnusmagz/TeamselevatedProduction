import React, { useState, useEffect, useCallback } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../../contexts/AuthContext';
import { useOrg } from '../../../contexts/OrgContext';
import { Tournament, TournamentStatus, TOURNAMENT_STATUS_CONFIG } from '../types';
import { listTournaments } from '../api/tournamentApi';

function formatDate(dateStr: string): string {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
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

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="flex justify-between items-center mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Tournaments</h1>
          <p className="text-sm text-gray-500 mt-1">
            Manage your club's tournaments
          </p>
        </div>
        {isAdmin && (
          <Link
            to="/tournaments/create"
            className="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover"
          >
            <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Create Tournament
          </Link>
        )}
      </div>

      {/* Filters */}
      <div className="mb-6">
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as TournamentStatus | '')}
          className="border border-gray-300 rounded-md px-3 py-2 text-sm"
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
      ) : tournaments.length === 0 ? (
        <div className="text-center py-16 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
          <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
          </svg>
          <h3 className="mt-4 text-lg font-medium text-gray-900">No tournaments yet</h3>
          <p className="mt-2 text-sm text-gray-500">
            Create your first tournament to get started.
          </p>
          {isAdmin && (
            <Link
              to="/tournaments/create"
              className="mt-4 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover"
            >
              Create Tournament
            </Link>
          )}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {tournaments.map((tournament) => {
            const statusConfig = TOURNAMENT_STATUS_CONFIG[tournament.status];
            return (
              <div
                key={tournament.id}
                onClick={() => navigate(`/tournaments/${tournament.id}`)}
                className="bg-white border border-gray-200 rounded-lg p-5 hover:shadow-md transition-shadow cursor-pointer"
              >
                <div className="flex justify-between items-start mb-3">
                  <h3 className="text-lg font-semibold text-gray-900 line-clamp-1">
                    {tournament.name}
                  </h3>
                  <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ${statusConfig.color}`}>
                    {statusConfig.label}
                  </span>
                </div>

                <div className="space-y-2 text-sm text-gray-600">
                  <div className="flex items-center">
                    <svg className="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    {formatDate(tournament.start_date)}
                    {tournament.end_date !== tournament.start_date && ` – ${formatDate(tournament.end_date)}`}
                  </div>

                  {(tournament.venue_name || tournament.location_name) && (
                    <div className="flex items-center">
                      <svg className="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                      {tournament.venue_name || tournament.location_name}
                      {tournament.venue_city && <span className="text-gray-500 ml-1">— {tournament.venue_city}{tournament.venue_state ? `, ${tournament.venue_state}` : ''}</span>}
                    </div>
                  )}

                  <div className="flex items-center justify-between pt-2 border-t border-gray-100">
                    <span className="text-gray-500">
                      {tournament.division_count ?? 0} division{(tournament.division_count ?? 0) !== 1 ? 's' : ''}
                    </span>
                    <span className="text-gray-500">
                      {tournament.registration_count ?? 0} team{(tournament.registration_count ?? 0) !== 1 ? 's' : ''}
                    </span>
                    {tournament.entry_fee_cents > 0 && (
                      <span className="font-medium text-gray-700">
                        {formatCurrency(tournament.entry_fee_cents)}
                      </span>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </main>
  );
};

export default TournamentList;

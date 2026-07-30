import React, { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../../../contexts/AuthContext';
import { useOrg } from '../../../contexts/OrgContext';
import { Tournament, TournamentStatus, TOURNAMENT_STATUS_CONFIG, VALID_STATUS_TRANSITIONS } from '../types';
import { getTournament, updateTournamentStatus, deleteTournament } from '../api/tournamentApi';
import DivisionManager from '../components/DivisionManager';
import RegistrationManager from '../components/RegistrationManager';
import GroupManager from '../components/GroupManager';
import ScheduleManager from '../components/ScheduleManager';
import StandingsTable from '../components/StandingsTable';
import BracketView from '../components/BracketView';
import DisciplinaryView from '../components/DisciplinaryView';
import GameDayBoard from '../components/GameDayBoard';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

// Helper component to load groups for a division and render standings tables
const StandingsView: React.FC<{
  divisionId: number;
  advancingCount: number;
  tiebreakerRules?: string[] | null;
}> = ({ divisionId, advancingCount, tiebreakerRules }) => {
  const [groups, setGroups] = useState<{ id: number; name: string }[]>([]);

  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    fetch(`${API_URL}/api/tournament-gateway.php?action=groups-list&division_id=${divisionId}`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => r.json())
      .then((data) => setGroups((data.groups || []).map((g: any) => ({ id: g.id, name: g.name }))))
      .catch(() => {});
  }, [divisionId]);

  if (groups.length === 0) return <p className="text-sm text-gray-400 italic">No groups yet</p>;

  return (
    <>
      {groups.map((g) => (
        <StandingsTable
          key={g.id}
          groupId={g.id}
          groupName={g.name}
          advancingCount={advancingCount}
          tiebreakerRules={tiebreakerRules}
        />
      ))}
    </>
  );
};

function formatDate(dateStr: string): string {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function formatCurrency(cents: number): string {
  return `$${(cents / 100).toFixed(2)}`;
}

const TournamentDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { isClubAdmin } = useOrg();
  const isAdmin = isClubAdmin || user?.system_role === 'super_admin';

  const [tournament, setTournament] = useState<Tournament | null>(null);
  const [loading, setLoading] = useState(true);
  const [statusUpdating, setStatusUpdating] = useState(false);
  const [activeTab, setActiveTab] = useState('overview');

  useEffect(() => {
    if (!id) return;
    setLoading(true);
    getTournament(Number(id))
      .then(setTournament)
      .catch((err) => console.error('Failed to load tournament:', err))
      .finally(() => setLoading(false));
  }, [id]);

  const handleStatusChange = async (newStatus: TournamentStatus) => {
    if (!tournament || !id) return;
    if (!window.confirm(`Change status to "${TOURNAMENT_STATUS_CONFIG[newStatus].label}"?`)) return;

    setStatusUpdating(true);
    try {
      await updateTournamentStatus(Number(id), newStatus);
      setTournament((prev) => prev ? { ...prev, status: newStatus } : prev);
    } catch (err: any) {
      alert(err.message || 'Failed to update status');
    } finally {
      setStatusUpdating(false);
    }
  };

  const handleDelete = async () => {
    if (!tournament || !id) return;
    if (!window.confirm(`Cancel "${tournament.name}"? This cannot be undone.`)) return;

    try {
      await deleteTournament(Number(id));
      navigate('/tournaments');
    } catch (err: any) {
      alert(err.message || 'Failed to cancel tournament');
    }
  };

  if (loading) {
    return (
      <main className="max-w-7xl mx-auto px-4 py-8">
        <div className="text-center text-gray-500">Loading tournament...</div>
      </main>
    );
  }

  if (!tournament) {
    return (
      <main className="max-w-7xl mx-auto px-4 py-8">
        <div className="text-center text-gray-500">Tournament not found.</div>
      </main>
    );
  }

  const statusConfig = TOURNAMENT_STATUS_CONFIG[tournament.status];
  const validTransitions = VALID_STATUS_TRANSITIONS[tournament.status] || [];

  // Determine which tabs to show based on format
  // Hide bracket tab if all divisions are round_robin only
  const hasKnockout = tournament.divisions?.some(
    (d) => d.format !== 'round_robin'
  );

  // Game Day shows up once the tournament is in flight or in a delay.
  // No reason to clutter the tab strip during draft/registration.
  const showGameDay = tournament.status === 'in_progress' || tournament.status === 'weather_delay';

  const tabs = [
    { key: 'overview', label: 'Overview' },
    ...(showGameDay ? [{ key: 'game-day', label: 'Game Day' }] : []),
    { key: 'divisions', label: 'Divisions' },
    { key: 'registrations', label: 'Registrations' },
    { key: 'groups', label: 'Groups' },
    { key: 'schedule', label: 'Schedule' },
    { key: 'standings', label: 'Standings' },
    ...(hasKnockout ? [{ key: 'bracket', label: 'Bracket' }] : []),
    { key: 'disciplinary', label: 'Disciplinary' },
  ];

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="mb-6">
        <Link to="/tournaments" className="text-sm text-brand-primary hover:underline mb-2 inline-block">
          &larr; Back to Tournaments
        </Link>

        <div className="flex justify-between items-start">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{tournament.name}</h1>
            <div className="flex items-center space-x-3 mt-2">
              <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusConfig.color}`}>
                {statusConfig.label}
              </span>
              <span className="text-sm text-gray-500 capitalize">{tournament.sport}</span>
              <span className="text-sm text-gray-500">
                {formatDate(tournament.start_date)} – {formatDate(tournament.end_date)}
              </span>
            </div>
          </div>

          {isAdmin && (
            <div className="flex items-center space-x-2">
              {/* Status transitions */}
              {validTransitions.length > 0 && (
                <div className="relative">
                  <select
                    value=""
                    onChange={(e) => e.target.value && handleStatusChange(e.target.value as TournamentStatus)}
                    disabled={statusUpdating}
                    className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white"
                  >
                    <option value="">Change Status...</option>
                    {validTransitions.map((s) => (
                      <option key={s} value={s}>
                        {TOURNAMENT_STATUS_CONFIG[s].label}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              <Link
                to={`/tournaments/${id}/edit`}
                className="px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Edit
              </Link>

              {tournament.status !== 'cancelled' && (
                <button
                  onClick={handleDelete}
                  className="px-3 py-2 border border-red-300 rounded-md text-sm font-medium text-red-700 hover:bg-red-50"
                >
                  Cancel
                </button>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200 mb-6">
        <nav className="flex space-x-6 -mb-px">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`py-3 px-1 border-b-2 text-sm font-medium ${
                activeTab === tab.key
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab Content */}
      {activeTab === 'overview' && (
        <div className="grid gap-6 md:grid-cols-2">
          {/* Tournament Info */}
          <div className="bg-white border border-gray-200 rounded-lg p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">Details</h3>
            <dl className="space-y-3 text-sm">
              {tournament.description && (
                <div>
                  <dt className="text-gray-500">Description</dt>
                  <dd className="text-gray-900 mt-1">{tournament.description}</dd>
                </div>
              )}
              {(tournament.venue_name || tournament.location_name) && (
                <div>
                  <dt className="text-gray-500">Venue</dt>
                  <dd className="text-gray-900 mt-1">
                    {tournament.venue_name || tournament.location_name}
                    {(tournament.venue_address || tournament.location_address) && (
                      <><br />{tournament.venue_address || tournament.location_address}</>
                    )}
                    {(tournament.venue_city || tournament.location_city) && (
                      <><br />
                        {tournament.venue_city || tournament.location_city}
                        {(tournament.venue_state || tournament.location_state) ? `, ${tournament.venue_state || tournament.location_state}` : ''}
                        {(tournament.venue_zip || tournament.location_zip) ? ` ${tournament.venue_zip || tournament.location_zip}` : ''}
                      </>
                    )}
                    {typeof tournament.venue_field_count === 'number' && (
                      <div className="text-xs text-gray-500 mt-1">
                        {tournament.venue_field_count} field{tournament.venue_field_count === 1 ? '' : 's'} available
                      </div>
                    )}
                  </dd>
                </div>
              )}
              {tournament.entry_fee_cents > 0 && (
                <div>
                  <dt className="text-gray-500">Entry Fee</dt>
                  <dd className="text-gray-900 mt-1">{formatCurrency(tournament.entry_fee_cents)} per team</dd>
                </div>
              )}
              {tournament.public_url_slug && (
                <div>
                  <dt className="text-gray-500">Public Link</dt>
                  <dd className="text-brand-primary mt-1">
                    <Link to={`/tournament/${tournament.public_url_slug}`} className="hover:underline">
                      /tournament/{tournament.public_url_slug}
                    </Link>
                  </dd>
                </div>
              )}
            </dl>
          </div>

          {/* Contact & Registration */}
          <div className="bg-white border border-gray-200 rounded-lg p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">Contact & Registration</h3>
            <dl className="space-y-3 text-sm">
              {tournament.contact_name && (
                <div>
                  <dt className="text-gray-500">Contact</dt>
                  <dd className="text-gray-900 mt-1">
                    {tournament.contact_name}
                    {tournament.contact_email && <><br />{tournament.contact_email}</>}
                    {tournament.contact_phone && <><br />{tournament.contact_phone}</>}
                  </dd>
                </div>
              )}
              {tournament.registration_open_date && (
                <div>
                  <dt className="text-gray-500">Registration Window</dt>
                  <dd className="text-gray-900 mt-1">
                    {formatDate(tournament.registration_open_date)} – {formatDate(tournament.registration_close_date || '')}
                  </dd>
                </div>
              )}
            </dl>
          </div>

          {/* Insurance */}
          {(tournament.insurance_certificate_url || tournament.insurance_expiry_date || tournament.insurance_policy_number || tournament.insurance_provider) && (() => {
            const expiry = tournament.insurance_expiry_date ? new Date(tournament.insurance_expiry_date + 'T00:00:00') : null;
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const daysUntilExpiry = expiry ? Math.floor((expiry.getTime() - today.getTime()) / 86_400_000) : null;
            let chip: { label: string; cls: string } | null = null;
            if (daysUntilExpiry !== null) {
              if (daysUntilExpiry < 0) chip = { label: `Expired ${Math.abs(daysUntilExpiry)} day${Math.abs(daysUntilExpiry) === 1 ? '' : 's'} ago`, cls: 'bg-red-100 text-red-700' };
              else if (daysUntilExpiry <= 30) chip = { label: `Expires in ${daysUntilExpiry} day${daysUntilExpiry === 1 ? '' : 's'}`, cls: 'bg-amber-100 text-amber-700' };
              else chip = { label: 'Active', cls: 'bg-green-100 text-green-700' };
            }
            return (
              <div className="bg-white border border-gray-200 rounded-lg p-6 md:col-span-2">
                <div className="flex items-start justify-between mb-4">
                  <h3 className="text-lg font-semibold text-gray-900">Insurance Certificate</h3>
                  {chip && (
                    <span className={`text-xs font-medium px-2 py-1 rounded ${chip.cls}`}>{chip.label}</span>
                  )}
                </div>
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                  {tournament.insurance_certificate_url && (
                    <div>
                      <dt className="text-gray-500">Certificate</dt>
                      <dd className="mt-1">
                        <a
                          href={tournament.insurance_certificate_url.startsWith('http') ? tournament.insurance_certificate_url : `${process.env.REACT_APP_API_URL || ''}${tournament.insurance_certificate_url}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-brand-primary hover:underline"
                        >
                          📄 {tournament.insurance_certificate_filename || 'View certificate'}
                        </a>
                      </dd>
                    </div>
                  )}
                  {tournament.insurance_expiry_date && (
                    <div>
                      <dt className="text-gray-500">Expires</dt>
                      <dd className="text-gray-900 mt-1">{formatDate(tournament.insurance_expiry_date)}</dd>
                    </div>
                  )}
                  {tournament.insurance_policy_number && (
                    <div>
                      <dt className="text-gray-500">Policy number</dt>
                      <dd className="text-gray-900 mt-1">{tournament.insurance_policy_number}</dd>
                    </div>
                  )}
                  {tournament.insurance_provider && (
                    <div>
                      <dt className="text-gray-500">Provider</dt>
                      <dd className="text-gray-900 mt-1">{tournament.insurance_provider}</dd>
                    </div>
                  )}
                </dl>
              </div>
            );
          })()}

          {/* Divisions summary */}
          <div className="bg-white border border-gray-200 rounded-lg p-6 md:col-span-2">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-semibold text-gray-900">
                Divisions ({tournament.divisions?.length || 0})
              </h3>
              <button
                onClick={() => setActiveTab('divisions')}
                className="text-sm text-brand-primary hover:underline"
              >
                Manage Divisions
              </button>
            </div>
            {tournament.divisions && tournament.divisions.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {tournament.divisions.map((div) => (
                  <div key={div.id} className="border border-gray-100 rounded-md p-3 bg-gray-50">
                    <div className="font-medium text-gray-900">{div.name}</div>
                    <div className="text-xs text-gray-500 mt-1">
                      {div.age_group} {div.gender} &bull; {div.format.replace('_', ' ')}
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-gray-500">No divisions created yet.</p>
            )}
          </div>
        </div>
      )}

      {activeTab === 'game-day' && showGameDay && (
        <GameDayBoard
          tournament={tournament}
          isAdmin={isAdmin}
          onTournamentUpdated={() => {
            if (id) {
              getTournament(Number(id))
                .then(setTournament)
                .catch(() => {});
            }
          }}
        />
      )}

      {activeTab === 'divisions' && (
        <DivisionManager
          tournamentId={tournament.id}
          sport={tournament.sport || 'soccer'}
          isAdmin={isAdmin}
        />
      )}

      {activeTab === 'registrations' && tournament.divisions && (
        <RegistrationManager
          tournamentId={tournament.id}
          divisions={tournament.divisions || []}
          isAdmin={isAdmin}
          clubId={tournament.club_id}
          registrationOpenDate={tournament.registration_open_date || null}
        />
      )}

      {activeTab === 'groups' && tournament.divisions && tournament.divisions.length > 0 && (
        <div className="space-y-6">
          {[...tournament.divisions]
            .sort((a, b) => {
              // Numeric age sort so U6 < U8 < U10 < U12 (string sort puts U10 < U6).
              const ageA = parseInt((a.age_group || '').replace(/[^0-9]/g, ''), 10);
              const ageB = parseInt((b.age_group || '').replace(/[^0-9]/g, ''), 10);
              if (!isNaN(ageA) && !isNaN(ageB) && ageA !== ageB) return ageA - ageB;
              return (a.sort_order ?? 0) - (b.sort_order ?? 0);
            })
            .map((div) => (
              <div key={div.id}>
                <h3 className="text-md font-semibold text-gray-700 mb-2">{div.name}</h3>
                <GroupManager divisionId={div.id} isAdmin={isAdmin} />
              </div>
            ))}
        </div>
      )}

      {activeTab === 'schedule' && tournament.divisions && (
        <div className="space-y-8">
          {tournament.divisions.map((div) => (
            <ScheduleManager
              key={div.id}
              division={div}
              tournament={tournament}
              isAdmin={isAdmin}
            />
          ))}
        </div>
      )}

      {activeTab === 'standings' && tournament.divisions && (
        <div className="space-y-6">
          {tournament.divisions.map((div) => {
            return (
              <div key={div.id}>
                <h3 className="text-md font-semibold text-gray-700 mb-3">{div.name}</h3>
                <StandingsView
                  divisionId={div.id}
                  advancingCount={div.teams_advancing_per_group}
                  tiebreakerRules={div.tiebreaker_rules}
                />
              </div>
            );
          })}
        </div>
      )}

      {activeTab === 'bracket' && tournament.divisions && (
        <div className="space-y-8">
          {tournament.divisions
            .filter((d) => d.format !== 'round_robin')
            .map((div) => (
              <BracketView key={div.id} divisionId={div.id} isAdmin={isAdmin} />
            ))}
        </div>
      )}

      {activeTab === 'disciplinary' && tournament.divisions && (
        <DisciplinaryView
          tournamentId={tournament.id}
          divisions={tournament.divisions.map((d) => ({ id: d.id, name: d.name }))}
          isAdmin={isAdmin}
        />
      )}
    </main>
  );
};

export default TournamentDetail;

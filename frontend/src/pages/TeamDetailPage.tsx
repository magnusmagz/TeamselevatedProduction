import React from 'react';
import { useParams, Link } from 'react-router-dom';
import TeamFormWithTabs from '../components/TeamFormWithTabs';

interface Team {
  id: number;
  name: string;
  logo_url: string | null;
  age_group: string;
  division: string;
  season_name: string;
  coach_name: string;
  primary_coach_id: number | null;
  home_field_name: string;
  max_players: number;
  player_count: number;
}

interface RosterMember {
  id: number;
  athlete_id: number;
  first_name: string;
  last_name: string;
  email: string;
  role: string;
  date_of_birth?: string;
  jersey_number?: number;
  primary_position?: string;
}

function calcAge(dob: string): number {
  const birth = new Date(dob);
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const m = today.getMonth() - birth.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
  return age;
}

function getAgeQuarter(dob: string): string {
  const month = new Date(dob).getMonth();
  if (month <= 2) return 'Q1';
  if (month <= 5) return 'Q2';
  if (month <= 8) return 'Q3';
  return 'Q4';
}

function getUGroup(dob: string): string {
  const birth = new Date(dob);
  const today = new Date();
  const seasonYear = today.getMonth() >= 7 ? today.getFullYear() + 1 : today.getFullYear();
  return `U${seasonYear - birth.getFullYear()}`;
}

function formatDOB(dob: string): string {
  const d = new Date(dob);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

interface Volunteer {
  id: number;
  user_id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  background_check_status: string;
  status: string;
  start_date: string | null;
}

const bgBadge = (status: string) => {
  const colors: Record<string, string> = {
    cleared: 'bg-green-100 text-green-800',
    pending: 'bg-yellow-100 text-yellow-800',
    expired: 'bg-red-100 text-red-800',
  };
  return (
    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${colors[status] || 'bg-gray-100 text-gray-600'}`}>
      {status || 'none'}
    </span>
  );
};

const TeamVolunteersSection: React.FC<{ teamId: number }> = ({ teamId }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [volunteers, setVolunteers] = React.useState<Volunteer[]>([]);
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    if (!teamId) return;
    const token = localStorage.getItem('auth_token');
    fetch(`${API_URL}/api/volunteer-gateway.php?action=team-volunteers&team_id=${teamId}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) setVolunteers(data.volunteers || []);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [teamId, API_URL]);

  const handleRemove = async (volunteerId: number) => {
    if (!window.confirm('Remove this volunteer from the team?')) return;
    const token = localStorage.getItem('auth_token');
    const res = await fetch(`${API_URL}/api/volunteer-gateway.php?action=remove-volunteer&volunteer_id=${volunteerId}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      setVolunteers(prev => prev.filter(v => v.id !== volunteerId));
    }
  };

  return (
    <div className="bg-white border border-brand-secondary rounded-lg mt-6">
      <div className="px-6 py-4 border-b border-brand-secondary flex justify-between items-center">
        <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide">
          Volunteers ({volunteers.length})
        </h2>
        <Link
          to="/volunteers"
          className="text-sm text-brand-primary hover:underline uppercase font-medium"
        >
          Manage Volunteers
        </Link>
      </div>

      {loading ? (
        <div className="p-8 text-center text-gray-400">Loading volunteers...</div>
      ) : volunteers.length === 0 ? (
        <div className="p-12 text-center">
          <div className="text-gray-400 mb-4">
            <svg className="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
            </svg>
          </div>
          <p className="text-gray-500">No volunteers assigned to this team</p>
        </div>
      ) : (
        <div className="divide-y divide-gray-100">
          {volunteers.map((vol) => (
            <div key={vol.id} className="px-6 py-4 flex items-center hover:bg-gray-50">
              <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center mr-4">
                <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                </svg>
              </div>
              <div className="flex-1">
                <div className="font-semibold text-brand-primary">
                  {vol.first_name} {vol.last_name}
                </div>
                <div className="text-sm text-gray-500">{vol.email}</div>
              </div>
              <div className="mr-4">{bgBadge(vol.background_check_status)}</div>
              <button
                onClick={() => handleRemove(vol.id)}
                className="text-red-500 hover:text-red-700 text-sm font-medium"
              >
                Remove
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export const TeamDetailPage: React.FC = () => {
  const { teamId } = useParams<{ teamId: string }>();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [team, setTeam] = React.useState<Team | null>(null);
  const [roster, setRoster] = React.useState<RosterMember[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [showEditModal, setShowEditModal] = React.useState(false);

  const fetchTeamData = React.useCallback(async () => {
    if (!teamId) return;

    try {
      const token = localStorage.getItem('auth_token');

      if (!token) {
        setError('Please log in to view team details');
        setLoading(false);
        return;
      }

      // Fetch team
      const teamResponse = await fetch(`${API_URL}/legacy/teams-gateway.php?id=${teamId}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });

      const teamData = await teamResponse.json();

      if (teamData.error) {
        setError(teamData.error);
        setLoading(false);
        return;
      }

      if (!teamData.id) {
        setError('Team not found');
        setLoading(false);
        return;
      }

      setTeam({
        ...teamData,
        home_field_name: teamData.home_field_name || null,
        max_players: teamData.max_players || 20,
        player_count: parseInt(teamData.player_count) || 0
      });

      // Clear any previous error since team loaded successfully
      setError(null);
      setLoading(false);

      // Fetch roster separately (don't let roster errors hide the team)
      try {
        const rosterResponse = await fetch(`${API_URL}/legacy/team-players-gateway.php?team_id=${teamId}`);
        const rosterData = await rosterResponse.json();

        if (rosterData.success && rosterData.team_members) {
          setRoster(rosterData.team_members);
        }
      } catch (rosterErr) {
        // Don't set error - team is still valid, just roster failed to load
        console.error('Roster fetch error:', rosterErr);
      }
    } catch (err: any) {
      setError(`Failed to load team: ${err?.message}`);
      setLoading(false);
    }
  }, [teamId, API_URL]);

  // Track if we've started fetching
  const hasFetched = React.useRef(false);

  // Handle team edit submission
  const handleEditSubmit = async (teamData: any) => {
    const token = localStorage.getItem('auth_token');
    console.log('Submitting team data:', teamData);
    try {
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php?id=${teamId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(teamData)
      });

      const data = await response.json();
      console.log('Update response:', data);

      if (response.ok && data.success) {
        setShowEditModal(false);
        // Reload page to get fresh data with all JOINed fields
        window.location.reload();
      } else {
        alert(`Failed to update team: ${data.error || 'Unknown error'}`);
      }
    } catch (err) {
      console.error('Error saving team:', err);
      alert('Failed to update team. Please try again.');
    }
  };

  // Call fetch directly (useEffect wasn't firing reliably)
  if (!hasFetched.current && teamId && loading) {
    hasFetched.current = true;
    fetchTeamData();
  }

  if (loading) {
    return (
      <div className="max-w-5xl mx-auto px-4 py-8">
        <div className="animate-pulse">
          <div className="h-8 bg-gray-200 rounded w-1/3 mb-4"></div>
          <div className="h-4 bg-gray-200 rounded w-1/4 mb-8"></div>
          <div className="h-64 bg-gray-200 rounded"></div>
        </div>
      </div>
    );
  }

  if (error || !team) {
    const isAuthError = error?.toLowerCase().includes('token') || error?.toLowerCase().includes('unauthorized') || error?.toLowerCase().includes('log in');
    return (
      <div className="max-w-5xl mx-auto px-4 py-8">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
          <h2 className="text-xl font-bold text-red-800 mb-2">
            {isAuthError ? 'Session Expired' : 'Team Not Found'}
          </h2>
          <p className="text-red-600 mb-4">{error || 'The team you are looking for does not exist.'}</p>
          <div className="flex justify-center gap-4">
            {isAuthError ? (
              <Link
                to="/login"
                className="bg-brand-primary text-white px-6 py-2 rounded font-medium hover:opacity-90"
              >
                Log In Again
              </Link>
            ) : (
              <button
                onClick={() => {
                  hasFetched.current = false;
                  setLoading(true);
                  setError(null);
                }}
                className="bg-brand-primary text-white px-6 py-2 rounded font-medium hover:opacity-90"
              >
                Try Again
              </button>
            )}
            <Link to="/dashboard" className="text-brand-primary hover:underline px-6 py-2">
              Back to Teams
            </Link>
          </div>
        </div>
      </div>
    );
  }

  // Separate players from coaches in roster
  const players = roster.filter(m => m.role === 'player' || !m.role);
  const assistantCoaches = roster.filter(m => m.role === 'assistant_coach');

  return (
    <div className="max-w-5xl mx-auto px-4 py-8">
      {/* Back Link */}
      <Link
        to="/dashboard"
        className="inline-flex items-center text-brand-primary hover:underline mb-6"
      >
        <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
        </svg>
        Back to Teams
      </Link>

      {/* Team Header */}
      <div className="bg-white border border-brand-secondary rounded-lg overflow-hidden mb-6">
        <div className="bg-brand-primary px-6 py-8">
          <div className="flex items-center gap-6">
            {/* Team Logo */}
            {team.logo_url ? (
              <img
                src={team.logo_url}
                alt={`${team.name} logo`}
                className="w-24 h-24 rounded-lg object-cover bg-white"
              />
            ) : (
              <div className="w-24 h-24 rounded-lg bg-white/20 flex items-center justify-center">
                <span className="text-4xl font-bold text-white">
                  {team.name.charAt(0).toUpperCase()}
                </span>
              </div>
            )}

            {/* Team Name & Info */}
            <div className="flex-1">
              <h1 className="text-3xl font-bold text-white mb-2">{team.name}</h1>
              <div className="flex flex-wrap gap-3">
                <span className="bg-white/20 text-white px-3 py-1 rounded-full text-sm font-medium">
                  {team.age_group}
                </span>
                <span className="bg-white/20 text-white px-3 py-1 rounded-full text-sm font-medium">
                  {team.division}
                </span>
                {team.season_name && (
                  <span className="bg-white/20 text-white px-3 py-1 rounded-full text-sm font-medium">
                    {team.season_name}
                  </span>
                )}
              </div>
            </div>

            {/* Quick Stats */}
            <div className="text-right">
              <div className="text-4xl font-bold text-white">{team.player_count || players.length}</div>
              <div className="text-white/70 text-sm">Players</div>
            </div>
          </div>
        </div>

        {/* Quick Actions Row */}
        <div className="flex justify-end gap-3 px-6 py-3 bg-gray-50 border-t border-brand-secondary">
          <Link
            to={`/team/${team.id}/calendar`}
            className="inline-flex items-center text-brand-primary hover:underline text-sm uppercase font-medium"
          >
            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            Team Calendar
          </Link>
          <Link
            to={`/teams/${team.id}/roster`}
            className="inline-flex items-center text-brand-primary hover:underline text-sm uppercase font-medium"
          >
            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Manage Roster
          </Link>
          <button
            onClick={() => setShowEditModal(true)}
            className="inline-flex items-center text-brand-primary hover:underline text-sm uppercase font-medium"
          >
            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Edit Team
          </button>
        </div>

        {/* Team Details Row */}
        <div className="grid grid-cols-3 divide-x divide-brand-secondary border-t border-brand-secondary">
          {/* Head Coach */}
          <div className="px-6 py-4">
            <div className="text-xs text-gray-500 uppercase tracking-wide mb-1">Head Coach</div>
            {team.primary_coach_id ? (
              <Link
                to={`/coach/${team.primary_coach_id}`}
                className="text-brand-primary font-semibold hover:underline"
              >
                {team.coach_name}
              </Link>
            ) : (
              <span className="text-gray-400">Unassigned</span>
            )}
          </div>

          {/* Home Field */}
          <div className="px-6 py-4">
            <div className="text-xs text-gray-500 uppercase tracking-wide mb-1">Home Field</div>
            <div className="text-brand-primary font-semibold">
              {team.home_field_name || (
                <button
                  onClick={() => setShowEditModal(true)}
                  className="text-gray-400 hover:text-brand-primary hover:underline"
                >
                  Not set - Click to add
                </button>
              )}
            </div>
          </div>

          {/* Roster Capacity */}
          <div className="px-6 py-4">
            <div className="text-xs text-gray-500 uppercase tracking-wide mb-1">Roster</div>
            <div className="text-brand-primary font-semibold">
              {team.player_count || players.length} / {team.max_players || 20} players
            </div>
          </div>
        </div>
      </div>

      {/* Coaches Section */}
      {assistantCoaches.length > 0 && (
        <div className="bg-white border border-brand-secondary rounded-lg mb-6">
          <div className="px-6 py-4 border-b border-brand-secondary">
            <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide">
              Coaching Staff
            </h2>
          </div>
          <div className="p-6">
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
              {/* Head Coach Card */}
              {team.primary_coach_id && (
                <div className="bg-brand-secondary rounded-lg p-4">
                  <div className="text-xs text-brand-primary uppercase tracking-wide mb-1">Head Coach</div>
                  <Link
                    to={`/coach/${team.primary_coach_id}`}
                    className="font-semibold text-brand-primary hover:underline"
                  >
                    {team.coach_name}
                  </Link>
                </div>
              )}

              {/* Assistant Coaches */}
              {assistantCoaches.map((coach) => (
                <div key={coach.id} className="bg-gray-50 rounded-lg p-4">
                  <div className="text-xs text-gray-500 uppercase tracking-wide mb-1">Assistant Coach</div>
                  <div className="font-semibold text-brand-primary">
                    {coach.first_name} {coach.last_name}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Roster Section */}
      <div className="bg-white border border-brand-secondary rounded-lg">
        <div className="px-6 py-4 border-b border-brand-secondary flex justify-between items-center">
          <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide">
            Roster ({players.length})
          </h2>
          <Link
            to={`/teams/${teamId}/roster`}
            className="text-sm text-brand-primary hover:underline uppercase font-medium"
          >
            Manage Roster
          </Link>
        </div>

        {players.length === 0 ? (
          <div className="p-12 text-center">
            <div className="text-gray-400 mb-4">
              <svg className="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </div>
            <p className="text-gray-500 mb-4">No players on this roster yet</p>
            <Link
              to={`/teams/${teamId}/roster`}
              className="inline-block bg-brand-primary text-white px-6 py-2 rounded font-medium hover:opacity-90"
            >
              Add Players
            </Link>
          </div>
        ) : (
          <div className="divide-y divide-gray-100">
            {players.map((player: RosterMember, index: number) => (
              <div
                key={player.id || player.athlete_id}
                className="px-6 py-4 flex items-center hover:bg-gray-50"
              >
                {/* Player Number/Index */}
                <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center mr-4">
                  <span className="text-brand-primary font-bold">{index + 1}</span>
                </div>

                {/* Player Info */}
                <div className="flex-1">
                  <Link
                    to={`/athlete/${player.athlete_id}`}
                    className="font-semibold text-brand-primary hover:underline"
                  >
                    {player.first_name} {player.last_name}
                  </Link>
                  {player.date_of_birth && (
                    <div className="text-xs text-gray-500">
                      Age {calcAge(player.date_of_birth)}
                      <span className="ml-1 text-xs font-semibold bg-brand-secondary text-brand-primary px-1.5 py-0.5 rounded-full">
                        {getAgeQuarter(player.date_of_birth)}
                      </span>
                      <span className="ml-1 text-xs font-semibold bg-brand-primary text-white px-1.5 py-0.5 rounded-full">
                        {getUGroup(player.date_of_birth)}
                      </span>
                      <span className="ml-1">· {formatDOB(player.date_of_birth)}</span>
                    </div>
                  )}
                  {player.primary_position && (
                    <div className="text-xs text-gray-500">
                      {player.primary_position}
                      {player.jersey_number && <span className="ml-1">· #{player.jersey_number}</span>}
                    </div>
                  )}
                </div>

                {/* View Profile Link */}
                <Link
                  to={`/athlete/${player.athlete_id}`}
                  className="text-brand-primary hover:underline text-sm uppercase font-medium"
                >
                  View
                </Link>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Volunteers Section */}
      <TeamVolunteersSection teamId={parseInt(teamId || '0')} />

      {/* Edit Team Modal */}
      {showEditModal && (
        <TeamFormWithTabs
          team={team}
          onSubmit={handleEditSubmit}
          onClose={() => setShowEditModal(false)}
        />
      )}
    </div>
  );
};

export default TeamDetailPage;

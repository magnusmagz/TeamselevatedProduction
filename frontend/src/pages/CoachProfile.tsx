import React, { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import CoachProfileEdit from '../components/CoachProfileEdit';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';

interface CoachProfileData {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  profile_image_url: string | null;
  coaching_background: string | null;
  archived: boolean;
  created_at: string;
}

interface CoachTeam {
  id: number;
  name: string;
  age_group: string;
  gender: string;
  status: string;
  division: string | null;
  skill_level: string;
  season_name: string | null;
  club_name: string | null;
  athlete_count: number;
}

interface Stats {
  total_teams: number;
  active_teams: number;
  total_athletes: number;
}

type TabType = 'about' | 'teams' | 'forms' | 'activities' | 'resources';

const CoachProfile: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [coach, setCoach] = useState<CoachProfileData | null>(null);
  const [teams, setTeams] = useState<CoachTeam[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [activeTab, setActiveTab] = useState<TabType>('about');
  const [showEditModal, setShowEditModal] = useState(false);
  const [showArchiveModal, setShowArchiveModal] = useState(false);

  useEffect(() => {
    fetchCoachProfile();
  }, [id]);

  const fetchCoachProfile = async () => {
    try {
      setLoading(true);
      setError(null);

      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/coach-profile.php?id=${id}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      const data = await response.json();

      if (data.success) {
        setCoach(data.coach);
        setTeams(data.teams || []);
        setStats(data.stats || null);
      } else {
        setError(data.error || 'Failed to load coach profile');
      }
    } catch (err) {
      console.error('Error fetching coach profile:', err);
      setError('Failed to load coach profile');
    } finally {
      setLoading(false);
    }
  };

  const handleArchiveCoach = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/coach-profile.php?id=${id}&action=archive`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      const data = await response.json();

      if (data.success) {
        navigate('/coaches');
      } else {
        alert(data.error || 'Failed to archive coach');
      }
    } catch (err) {
      console.error('Error archiving coach:', err);
      alert('Failed to archive coach');
    }
    setShowArchiveModal(false);
  };

  const isAdmin = () => {
    return user?.activeRole?.role === 'club_admin' || user?.system_role === 'super_admin';
  };

  const getInitials = (firstName: string, lastName: string) => {
    return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="text-brand-primary">Loading coach profile...</div>
      </div>
    );
  }

  if (error || !coach) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="text-red-600">{error || 'Coach not found'}</div>
      </div>
    );
  }

  return (
    <div className="max-w-6xl mx-auto">
      <PageHeader
        title={`${coach.first_name} ${coach.last_name}`}
        subtitle="Coach"
        actions={
          <>
            <a
              href={`mailto:${coach.email}`}
              className="bg-brand-primary text-white px-6 py-2 rounded-md hover:bg-brand-primary font-semibold uppercase text-center"
            >
              Send Message
            </a>
            {(isAdmin() || user?.id === coach.id) && (
              <Button variant="secondary" onClick={() => setShowEditModal(true)}>
                Edit Profile
              </Button>
            )}
          </>
        }
      />

      {/* Profile card */}
      <div className="bg-white border border-brand-secondary rounded-md p-8 mb-6">
        <div className="flex flex-col md:flex-row items-center md:items-start gap-6">
          {/* Profile Image */}
          <div className="flex-shrink-0">
            {coach.profile_image_url ? (
              <img
                src={coach.profile_image_url}
                alt={`${coach.first_name} ${coach.last_name}`}
                className="w-40 h-40 rounded-full object-cover border-4 border-brand-secondary"
              />
            ) : (
              <div className="w-40 h-40 rounded-full bg-brand-secondary border-4 border-brand-secondary flex items-center justify-center">
                <span className="text-4xl font-bold text-brand-primary">
                  {getInitials(coach.first_name, coach.last_name)}
                </span>
              </div>
            )}
          </div>

          {/* Coach Info */}
          <div className="flex-1 text-center md:text-left">
            <div className="space-y-2">
              {coach.phone && (
                <div>
                  <a
                    href={`tel:${coach.phone}`}
                    className="text-lg text-blue-600 hover:underline"
                  >
                    {coach.phone}
                  </a>
                </div>
              )}
              <div>
                <a
                  href={`mailto:${coach.email}`}
                  className="text-lg text-blue-600 hover:underline"
                >
                  {coach.email}
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white border border-brand-secondary rounded-md mb-6">
        <div className="border-b border-brand-secondary">
          <div className="flex space-x-8 px-6">
            {/* forms / activities / resources hidden for now */}
            {(['about', 'teams'] as TabType[]).map((tab) => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`py-4 text-base font-semibold uppercase tracking-wide ${
                  activeTab === tab
                    ? 'text-blue-600 border-b-2 border-blue-600'
                    : 'text-gray-600 hover:text-gray-800'
                }`}
              >
                {tab}
              </button>
            ))}
          </div>
        </div>

        {/* Tab Content */}
        <div className="p-6">
          {activeTab === 'about' && (
            <div>
              <div className="flex justify-between items-center mb-4">
                <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                  Background
                </h3>
                {(isAdmin() || user?.id === coach.id) && !coach.coaching_background && (
                  <Button variant="link" onClick={() => setShowEditModal(true)}>
                    Add
                  </Button>
                )}
              </div>
              {coach.coaching_background ? (
                <p className="text-gray-700 whitespace-pre-wrap">{coach.coaching_background}</p>
              ) : (
                <p className="text-gray-500 italic">
                  A coaching background has not been provided at this time.
                </p>
              )}

              {/* Stats */}
              {stats && (
                <div className="mt-8 grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="bg-brand-secondary border border-brand-secondary rounded-md p-4 text-center">
                    <div className="text-3xl font-bold text-brand-primary">{stats.total_teams}</div>
                    <div className="text-sm text-gray-600 uppercase">Total Teams</div>
                  </div>
                  <div className="bg-brand-secondary border border-brand-secondary rounded-md p-4 text-center">
                    <div className="text-3xl font-bold text-brand-primary">{stats.active_teams}</div>
                    <div className="text-sm text-gray-600 uppercase">Active Teams</div>
                  </div>
                  <div className="bg-brand-secondary border border-brand-secondary rounded-md p-4 text-center">
                    <div className="text-3xl font-bold text-brand-primary">{stats.total_athletes}</div>
                    <div className="text-sm text-gray-600 uppercase">Total Athletes</div>
                  </div>
                </div>
              )}
            </div>
          )}

          {activeTab === 'teams' && (
            <div>
              <h3 className="text-xl font-semibold text-brand-primary mb-4 uppercase tracking-wide">
                Teams ({teams.length})
              </h3>
              {teams.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {teams.map((team) => (
                    <Link
                      key={team.id}
                      to={`/team/${team.id}`}
                      className="bg-white border border-brand-secondary rounded-md p-4 hover:border-brand-primary transition-colors"
                    >
                      <h4 className="text-lg font-semibold text-brand-primary mb-2">{team.name}</h4>
                      <div className="space-y-1 text-sm text-gray-600">
                        {team.age_group && (
                          <div>Age Group: <span className="font-medium">{team.age_group}</span></div>
                        )}
                        <div>Gender: <span className="font-medium">{team.gender}</span></div>
                        {team.skill_level && (
                          <div>Level: <span className="font-medium">{team.skill_level}</span></div>
                        )}
                        {team.season_name && (
                          <div>Season: <span className="font-medium">{team.season_name}</span></div>
                        )}
                        <div>Athletes: <span className="font-medium">{team.athlete_count}</span></div>
                        <div>
                          Status:{' '}
                          <span className={`font-medium ${team.status === 'active' ? 'text-green-600' : 'text-gray-600'}`}>
                            {team.status}
                          </span>
                        </div>
                      </div>
                    </Link>
                  ))}
                </div>
              ) : (
                <p className="text-gray-500 italic">No teams assigned to this coach.</p>
              )}
            </div>
          )}

          {activeTab === 'forms' && (
            <div>
              <h3 className="text-xl font-semibold text-brand-primary mb-4 uppercase tracking-wide">
                Forms
              </h3>
              <p className="text-gray-500 italic">No forms available at this time.</p>
            </div>
          )}

          {activeTab === 'activities' && (
            <div>
              <h3 className="text-xl font-semibold text-brand-primary mb-4 uppercase tracking-wide">
                Activities
              </h3>
              <p className="text-gray-500 italic">No activities available at this time.</p>
            </div>
          )}

          {activeTab === 'resources' && (
            <div>
              <h3 className="text-xl font-semibold text-brand-primary mb-4 uppercase tracking-wide">
                Resources
              </h3>
              <p className="text-gray-500 italic">No resources available at this time.</p>
            </div>
          )}
        </div>
      </div>

      {/* Archive Button - Admin Only */}
      {isAdmin() && (
        <div className="flex justify-end">
          <Button variant="danger" leadingIcon={<span>✕</span>} onClick={() => setShowArchiveModal(true)}>
            Archive Coach
          </Button>
        </div>
      )}

      {/* Archive Confirmation Modal */}
      {showArchiveModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-md p-8 max-w-md mx-4">
            <h3 className="text-2xl font-bold text-brand-primary mb-4">Archive Coach?</h3>
            <p className="text-gray-700 mb-6">
              Are you sure you want to archive <strong>{coach.first_name} {coach.last_name}</strong>?
            </p>
            <p className="text-sm text-gray-600 mb-6">
              This will:
              <ul className="list-disc list-inside mt-2">
                <li>Hide the coach from active coach lists</li>
                <li>Mark their teams for reassignment</li>
                <li>Preserve all historical data</li>
              </ul>
            </p>
            <div className="flex gap-4 justify-end">
              <Button variant="secondary" onClick={() => setShowArchiveModal(false)}>
                Cancel
              </Button>
              <Button variant="danger" onClick={handleArchiveCoach}>
                Archive Coach
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Edit Profile Modal */}
      {showEditModal && coach && (
        <CoachProfileEdit
          coach={coach}
          onClose={() => setShowEditModal(false)}
          onSave={(updatedCoach) => {
            setCoach(updatedCoach);
            setShowEditModal(false);
          }}
        />
      )}
    </div>
  );
};

export default CoachProfile;

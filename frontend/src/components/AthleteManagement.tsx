import React, { useState, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import AthleteForm from './AthleteForm';
import GuardianManagement from './GuardianManagement';

interface Athlete {
  id: number;
  first_name: string;
  middle_initial?: string;
  last_name: string;
  preferred_name?: string;
  date_of_birth?: string;
  gender?: string;
  school_name?: string;
  grade_level?: number;
  primary_guardian_name?: string;
  primary_guardian_email?: string;
  primary_guardian_phone?: string;
  email?: string;
  active_status?: boolean;
  created_at?: string;
  teams?: string[];
}

interface AthleteManagementProps {
  onClose?: () => void;
}

const AthleteManagement: React.FC<AthleteManagementProps> = ({ onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const navigate = useNavigate();
  const [athletes, setAthletes] = useState<Athlete[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedAthlete, setSelectedAthlete] = useState<Athlete | null>(null);
  const [showGuardianManagement, setShowGuardianManagement] = useState(false);
  const [selectedAthleteForGuardians, setSelectedAthleteForGuardians] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterGender, setFilterGender] = useState('');
  const [filterGrade, setFilterGrade] = useState('');
  const [athleteTeams, setAthleteTeams] = useState<{ [key: number]: string[] }>({});
  const [showTeamSelector, setShowTeamSelector] = useState<number | null>(null);
  const [availableTeams, setAvailableTeams] = useState<any[]>([]);

  useEffect(() => {
    fetchAthletes();
    fetchAvailableTeams();
  }, []);

  const fetchAthletes = async () => {
    try {
      // Fetch athletes
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php`);
      const data = await response.json();
      const athleteList = data.athletes || [];
      setAthletes(athleteList);

      // Fetch team-player relationships
      const teamPlayersResponse = await fetch(`${API_URL}/legacy/team-players-gateway.php`);
      const teamPlayersData = await teamPlayersResponse.json();

      if (teamPlayersData.success && teamPlayersData.team_players) {
        // Create a map of athlete ID to team names
        const teamsByAthlete: { [key: number]: string[] } = {};

        teamPlayersData.team_players.forEach((tp: any) => {
          const memberId = tp.athlete_id || tp.user_id || tp.member_id;
          if (memberId) {
            if (!teamsByAthlete[memberId]) {
              teamsByAthlete[memberId] = [];
            }
            if (tp.team_name && !teamsByAthlete[memberId].includes(tp.team_name)) {
              teamsByAthlete[memberId].push(tp.team_name);
            }
          }
        });

        setAthleteTeams(teamsByAthlete);
      }
    } catch (error) {
      console.error('Error fetching athletes:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchAvailableTeams = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      if (data.teams) {
        setAvailableTeams(data.teams);
      }
    } catch (error) {
      console.error('Error fetching available teams:', error);
    }
  };

  const handleAddToTeam = async (athleteId: number, teamId: number) => {
    try {
      const response = await fetch(`${API_URL}/legacy/team-players-gateway.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          team_id: teamId,
          player_id: athleteId
        })
      });

      if (response.ok) {
        // Refresh the data
        await fetchAthletes();
        setShowTeamSelector(null);
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to add athlete to team');
      }
    } catch (error) {
      console.error('Error adding athlete to team:', error);
      alert('Error adding athlete to team');
    }
  };

  const handleAddAthlete = () => {
    setSelectedAthlete(null);
    setShowForm(true);
  };

  const handleEditAthlete = async (athlete: Athlete) => {
    try {
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php?id=${athlete.id}`);
      const fullAthlete = await response.json();
      setSelectedAthlete(fullAthlete);
      setShowForm(true);
    } catch (error) {
      console.error('Error fetching athlete details:', error);
    }
  };

  const handleManageGuardians = async (athlete: Athlete) => {
    try {
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php?id=${athlete.id}`);
      const fullAthlete = await response.json();
      setSelectedAthleteForGuardians(fullAthlete);
      setShowGuardianManagement(true);
    } catch (error) {
      console.error('Error fetching athlete details:', error);
    }
  };

  const handleArchiveAthlete = async (athlete: Athlete) => {
    if (!window.confirm(`Are you sure you want to archive ${athlete.first_name} ${athlete.last_name}?`)) {
      return;
    }

    try {
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php?id=${athlete.id}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' }
      });

      if (response.ok) {
        fetchAthletes();
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to archive athlete');
      }
    } catch (error) {
      console.error('Error archiving athlete:', error);
      alert('Error archiving athlete');
    }
  };

  const calculateAge = (dob: string) => {
    if (!dob || dob === 'null' || dob === 'undefined') {
      return null;
    }
    const birthDate = new Date(dob);
    if (isNaN(birthDate.getTime())) {
      return null;
    }
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
      age--;
    }
    return age;
  };

  const filteredAthletes = athletes.filter(athlete => {
    const fullName = `${athlete.first_name} ${athlete.last_name}`.toLowerCase();
    const matchesSearch = fullName.includes(searchTerm.toLowerCase());
    const matchesGender = !filterGender || athlete.gender === filterGender;
    const matchesGrade = !filterGrade || athlete.grade_level?.toString() === filterGrade;
    return matchesSearch && matchesGender && matchesGrade;
  });

  if (onClose) {
    // Modal view for when opened from another component
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
        <div className="bg-white border border-brand-secondary rounded-md w-full max-w-6xl max-h-[90vh] overflow-y-auto">
          <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
            <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">Athlete Management</h3>
            <button
              onClick={onClose}
              className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
            >
              ×
            </button>
          </div>

          <div className="p-6">
            <AthleteListContent
              athletes={filteredAthletes}
              loading={loading}
              searchTerm={searchTerm}
              setSearchTerm={setSearchTerm}
              filterGender={filterGender}
              setFilterGender={setFilterGender}
              filterGrade={filterGrade}
              setFilterGrade={setFilterGrade}
              handleAddAthlete={handleAddAthlete}
              handleEditAthlete={handleEditAthlete}
              handleManageGuardians={handleManageGuardians}
              handleArchiveAthlete={handleArchiveAthlete}
              calculateAge={calculateAge}
              athleteTeams={athleteTeams}
              showTeamSelector={showTeamSelector}
              setShowTeamSelector={setShowTeamSelector}
              availableTeams={availableTeams}
              handleAddToTeam={handleAddToTeam}
            />
          </div>
        </div>

        {showForm && (
          <AthleteForm
            athlete={selectedAthlete}
            onSubmit={() => {
              setShowForm(false);
              fetchAthletes();
            }}
            onClose={() => setShowForm(false)}
          />
        )}
      </div>
    );
  }

  // Full page view
  return (
    <div>
      <div className="mb-8 flex justify-between items-start">
        <div>
          <h2 className="text-3xl font-bold text-brand-primary mb-2 uppercase tracking-wide">Athlete Management</h2>
          <p className="text-gray-600">Manage all athletes in your club</p>
        </div>
        <button
          onClick={handleAddAthlete}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary font-semibold uppercase"
        >
          + Add New Athlete
        </button>
      </div>

      <AthleteListContent
        athletes={filteredAthletes}
        loading={loading}
        searchTerm={searchTerm}
        setSearchTerm={setSearchTerm}
        filterGender={filterGender}
        setFilterGender={setFilterGender}
        filterGrade={filterGrade}
        setFilterGrade={setFilterGrade}
        handleAddAthlete={handleAddAthlete}
        handleEditAthlete={handleEditAthlete}
        handleManageGuardians={handleManageGuardians}
        handleArchiveAthlete={handleArchiveAthlete}
        calculateAge={calculateAge}
        athleteTeams={athleteTeams}
        showTeamSelector={showTeamSelector}
        setShowTeamSelector={setShowTeamSelector}
        availableTeams={availableTeams}
        handleAddToTeam={handleAddToTeam}
      />

      {showForm && (
        <AthleteForm
          athlete={selectedAthlete}
          onSubmit={() => {
            setShowForm(false);
            fetchAthletes();
          }}
          onClose={() => setShowForm(false)}
        />
      )}

      {showGuardianManagement && selectedAthleteForGuardians && (
        <GuardianManagement
          athleteId={selectedAthleteForGuardians.id}
          guardians={selectedAthleteForGuardians.guardians || []}
          onUpdate={() => {
            handleManageGuardians({ id: selectedAthleteForGuardians.id } as Athlete);
          }}
          onClose={() => {
            setShowGuardianManagement(false);
            setSelectedAthleteForGuardians(null);
          }}
        />
      )}
    </div>
  );
};

// Shared content component
const AthleteListContent: React.FC<{
  athletes: Athlete[];
  loading: boolean;
  searchTerm: string;
  setSearchTerm: (value: string) => void;
  filterGender: string;
  setFilterGender: (value: string) => void;
  filterGrade: string;
  setFilterGrade: (value: string) => void;
  handleAddAthlete: () => void;
  handleEditAthlete: (athlete: Athlete) => void;
  handleManageGuardians: (athlete: Athlete) => void;
  handleArchiveAthlete: (athlete: Athlete) => void;
  calculateAge: (dob: string) => number | null;
  athleteTeams: { [key: number]: string[] };
  showTeamSelector: number | null;
  setShowTeamSelector: (value: number | null) => void;
  availableTeams: any[];
  handleAddToTeam: (athleteId: number, teamId: number) => void;
}> = ({
  athletes,
  loading,
  searchTerm,
  setSearchTerm,
  filterGender,
  setFilterGender,
  filterGrade,
  setFilterGrade,
  handleAddAthlete,
  handleEditAthlete,
  handleManageGuardians,
  handleArchiveAthlete,
  calculateAge,
  athleteTeams,
  showTeamSelector,
  setShowTeamSelector,
  availableTeams,
  handleAddToTeam
}) => {
  return (
    <>
      <div className="border border-brand-secondary rounded-md bg-white p-6 mb-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <input
            type="text"
            placeholder="Search athletes..."
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />

          <select
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
            value={filterGender}
            onChange={(e) => setFilterGender(e.target.value)}
          >
            <option value="">All Genders</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Non-binary">Non-binary</option>
          </select>

          <select
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
            value={filterGrade}
            onChange={(e) => setFilterGrade(e.target.value)}
          >
            <option value="">All Grades</option>
            {Array.from({ length: 12 }, (_, i) => i + 1).map(grade => (
              <option key={grade} value={grade}>Grade {grade}</option>
            ))}
          </select>

          <button
            onClick={() => {
              setSearchTerm('');
              setFilterGender('');
              setFilterGrade('');
            }}
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-100 uppercase"
          >
            Clear Filters
          </button>
        </div>

        <div className="mt-4 text-brand-primary">
          Showing {athletes.length} athletes
        </div>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading athletes...</div>
      ) : (
        <div className="border border-brand-secondary rounded-md overflow-hidden">
          <table className="min-w-full bg-white">
            <thead>
              <tr className="border-b border-brand-secondary">
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Name
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Age
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Gender
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Team
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Primary Guardian
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Contact
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase w-32">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {athletes.map((athlete, index) => (
                <tr
                  key={athlete.id}
                  className="border-b border-gray-300 hover:bg-gray-50"
                >
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div>
                      <Link
                        to={`/athlete/${athlete.id}/enhanced`}
                        className="text-sm font-medium text-brand-primary hover:text-brand-primary-hover hover:underline"
                      >
                        {athlete.first_name} {athlete.middle_initial ? `${athlete.middle_initial}. ` : ''}{athlete.last_name}
                      </Link>
                      {athlete.preferred_name && (
                        <div className="text-xs text-gray-500">
                          Prefers: {athlete.preferred_name}
                        </div>
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-sm text-brand-primary">
                      {athlete.date_of_birth ? (() => {
                        const age = calculateAge(athlete.date_of_birth);
                        return age !== null ? `${age} years` : 'Invalid date';
                      })() : 'Not set'}
                    </div>
                    <div className="text-xs text-gray-500">
                      {athlete.date_of_birth ? (
                        isNaN(new Date(athlete.date_of_birth).getTime()) ? (
                          'Invalid date'
                        ) : (
                          new Date(athlete.date_of_birth).toLocaleDateString()
                        )
                      ) : (
                        'No date of birth'
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-sm text-brand-primary">{athlete.gender || 'Not set'}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="flex items-center space-x-2">
                      <div className="text-sm text-brand-primary flex flex-wrap gap-1">
                        {athleteTeams[athlete.id] && athleteTeams[athlete.id].length > 0 ? (
                          athleteTeams[athlete.id].map((team, idx) => (
                            <span key={idx} className="bg-brand-secondary border border-brand-primary px-2 py-1 text-xs">
                              {team}
                            </span>
                          ))
                        ) : (
                          <span className="text-gray-400 text-xs">No teams</span>
                        )}
                      </div>

                      <button
                        onClick={() => setShowTeamSelector(athlete.id)}
                        className="text-brand-primary hover:text-brand-primary-hover font-bold text-lg flex-shrink-0"
                        title="Add to team"
                      >
                        +
                      </button>
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-sm text-brand-primary">
                      {athlete.primary_guardian_name || '-'}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div>
                      {athlete.primary_guardian_email && (
                        <div className="text-xs text-gray-600">
                          {athlete.primary_guardian_email}
                        </div>
                      )}
                      {athlete.primary_guardian_phone && (
                        <div className="text-xs text-gray-600">
                          {athlete.primary_guardian_phone}
                        </div>
                      )}
                      {athlete.email && (
                        <div className="text-xs text-gray-600">
                          {athlete.email}
                        </div>
                      )}
                      {!athlete.primary_guardian_email && !athlete.primary_guardian_phone && athlete.email && (
                        <div className="text-xs text-gray-500">Contact via email</div>
                      )}
                      {!athlete.primary_guardian_email && !athlete.primary_guardian_phone && !athlete.email && (
                        <div className="text-xs text-gray-500">No contact info</div>
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm w-32">
                    <div className="flex flex-col space-y-1">
                      <button
                        onClick={() => handleManageGuardians(athlete)}
                        className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold text-left"
                      >
                        Guardians
                      </button>
                      <button
                        onClick={() => handleEditAthlete(athlete)}
                        className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold text-left"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => handleArchiveAthlete(athlete)}
                        className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold text-left"
                      >
                        Archive
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Team Selection Modal */}
      {showTeamSelector !== null && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-brand-secondary rounded-md max-w-2xl w-full max-h-[80vh] overflow-y-auto">
            <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                Add to Team
              </h3>
              <button
                onClick={() => setShowTeamSelector(null)}
                className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
              >
                ×
              </button>
            </div>

            <div className="p-6">
              <div className="grid gap-3">
                {availableTeams
                  .filter(team =>
                    !athleteTeams[showTeamSelector] ||
                    !athleteTeams[showTeamSelector].includes(team.name)
                  )
                  .map(team => (
                    <button
                      key={team.id}
                      onClick={() => {
                        handleAddToTeam(showTeamSelector, team.id);
                        setShowTeamSelector(null);
                      }}
                      className="border border-brand-secondary rounded-md bg-white hover:bg-brand-secondary p-4 text-left transition-colors"
                    >
                      <div className="font-semibold text-brand-primary text-lg">
                        {team.name}
                      </div>
                      {team.age_group && (
                        <div className="text-sm text-gray-600 mt-1">
                          Age Group: {team.age_group}
                        </div>
                      )}
                      {team.program_name && (
                        <div className="text-sm text-gray-600">
                          Program: {team.program_name}
                        </div>
                      )}
                    </button>
                  ))}
                {availableTeams.filter(team =>
                  !athleteTeams[showTeamSelector] ||
                  !athleteTeams[showTeamSelector].includes(team.name)
                ).length === 0 && (
                  <div className="text-center text-gray-600 py-8">
                    All available teams have been assigned
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default AthleteManagement;
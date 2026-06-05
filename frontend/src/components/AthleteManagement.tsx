import React, { useState, useEffect, useMemo } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import AthleteForm from './AthleteForm';
import GuardianManagement from './GuardianManagement';
import EmailCompose from './communications/EmailCompose';
import SmsCompose from './communications/SmsCompose';
import { useOrg } from '../contexts/OrgContext';

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
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      const data = await response.json();
      const athleteList: Athlete[] = data.athletes || [];
      // The gateway LEFT JOINs guardians, so an athlete with more than one
      // primary guardian comes back as duplicate rows sharing the same id.
      // Dedupe by id — duplicate React keys silently break row re-ordering on
      // sort (first sort applies, later ones no-op) and inflate the count.
      const seen = new Set<number>();
      const uniqueAthletes = athleteList.filter((a) => {
        if (seen.has(a.id)) return false;
        seen.add(a.id);
        return true;
      });
      setAthletes(uniqueAthletes);

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
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php?id=${athlete.id}`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      const fullAthlete = await response.json();
      setSelectedAthlete(fullAthlete);
      setShowForm(true);
    } catch (error) {
      console.error('Error fetching athlete details:', error);
    }
  };

  const handleManageGuardians = async (athlete: Athlete) => {
    try {
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php?id=${athlete.id}`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
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
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        }
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

  const calculateUGroup = (dob: string): string | null => {
    if (!dob || dob === 'null' || dob === 'undefined') return null;
    const birth = new Date(dob);
    if (isNaN(birth.getTime())) return null;
    const today = new Date();
    // Aug 1 - Jul 31 cycle: season year = current year if we're Aug+, otherwise current year
    const seasonYear = today.getMonth() >= 7 ? today.getFullYear() + 1 : today.getFullYear();
    const uAge = seasonYear - birth.getFullYear();
    return `U${uAge}`;
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
      <div className="mb-8">
        <h2 className="text-3xl font-bold text-brand-primary mb-2 uppercase tracking-wide">Athlete Management</h2>
        <p className="text-gray-600">Manage all athletes in your club</p>
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
export const AthleteListContent: React.FC<{
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
  const { currentClubId } = useOrg();
  const [showEmailCompose, setShowEmailCompose] = useState(false);
  const [showSmsCompose, setShowSmsCompose] = useState(false);
  const [composeRecipient, setComposeRecipient] = useState<any>(null);

  // Per-column sort + filter for the athlete table.
  type ColKey = 'name' | 'age' | 'grade' | 'gender' | 'team' | 'guardian' | 'contact';
  const GRADE_OPTIONS = Array.from({ length: 12 }, (_, i) => ({
    value: String(i + 1),
    label: `Grade ${i + 1}`,
  }));
  // Columns with `options` render an exact-match dropdown filter; the rest a substring text filter.
  const COLUMNS: { key: ColKey; label: string; options?: { value: string; label: string }[] }[] = [
    { key: 'name', label: 'Name' },
    { key: 'age', label: 'Age' },
    { key: 'grade', label: 'Grade', options: GRADE_OPTIONS },
    {
      key: 'gender',
      label: 'Gender',
      options: [
        { value: 'male', label: 'Male' },
        { value: 'female', label: 'Female' },
        { value: 'non-binary', label: 'Non-binary' },
      ],
    },
    { key: 'team', label: 'Team' },
    { key: 'guardian', label: 'Primary Guardian' },
    { key: 'contact', label: 'Contact' },
  ];
  const [sortKey, setSortKey] = useState<ColKey | null>(null);
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
  const [colFilters, setColFilters] = useState<Record<string, string>>({});

  const setColFilter = (key: ColKey, value: string) =>
    setColFilters((prev) => ({ ...prev, [key]: value }));

  const toggleSort = (key: ColKey) => {
    if (sortKey !== key) {
      setSortKey(key);
      setSortDir('asc');
    } else {
      // Same column: just flip direction. Stays sorted on every click.
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    }
  };
  const sortArrow = (key: ColKey) => (sortKey !== key ? '▾' : sortDir === 'asc' ? '▲' : '▼');

  const teamLabel = (a: Athlete) => (athleteTeams[a.id] || a.teams || []).join(', ');
  const contactStr = (a: Athlete) =>
    [a.primary_guardian_email, a.primary_guardian_phone, a.email].filter(Boolean).join(' ');
  const ageOf = (a: Athlete) => (a.date_of_birth ? calculateAge(a.date_of_birth) : null);

  const sortValue = (a: Athlete, key: ColKey): string | number => {
    switch (key) {
      case 'name': return `${a.last_name || ''} ${a.first_name || ''}`.trim().toLowerCase();
      case 'age': { const v = ageOf(a); return v == null ? -Infinity : v; }
      case 'grade': return a.grade_level == null ? -Infinity : a.grade_level;
      case 'gender': return (a.gender || '').toLowerCase();
      case 'team': return teamLabel(a).toLowerCase();
      case 'guardian': return (a.primary_guardian_name || '').toLowerCase();
      case 'contact': return contactStr(a).toLowerCase();
    }
  };
  const filterText = (a: Athlete, key: ColKey): string => {
    switch (key) {
      case 'name': return `${a.first_name || ''} ${a.middle_initial || ''} ${a.last_name || ''} ${a.preferred_name || ''}`.toLowerCase();
      case 'age': { const v = ageOf(a); return v == null ? '' : String(v); }
      case 'grade': return a.grade_level == null ? '' : String(a.grade_level);
      case 'gender': return (a.gender || '').toLowerCase();
      case 'team': return teamLabel(a).toLowerCase();
      case 'guardian': return (a.primary_guardian_name || '').toLowerCase();
      case 'contact': return contactStr(a).toLowerCase();
    }
  };

  const displayedAthletes = useMemo(() => {
    const rows = athletes.filter((a) =>
      COLUMNS.every((col) => {
        const f = (colFilters[col.key] || '').trim().toLowerCase();
        if (!f) return true;
        if (col.options) return filterText(a, col.key) === f;
        return filterText(a, col.key).includes(f);
      })
    );
    if (sortKey) {
      rows.sort((a, b) => {
        const va = sortValue(a, sortKey);
        const vb = sortValue(b, sortKey);
        const cmp =
          typeof va === 'number' && typeof vb === 'number'
            ? va - vb
            : String(va).localeCompare(String(vb));
        return sortDir === 'asc' ? cmp : -cmp;
      });
    }
    return rows;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [athletes, colFilters, sortKey, sortDir, athleteTeams]);

  return (
    <>
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
        <span className="text-brand-primary text-sm">
          Showing {displayedAthletes.length}
          {displayedAthletes.length !== athletes.length ? ` of ${athletes.length}` : ''} athlete
          {displayedAthletes.length !== 1 ? 's' : ''}
        </span>
        <button
          onClick={handleAddAthlete}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary font-semibold uppercase w-full sm:w-auto"
        >
          + Add New Athlete
        </button>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading athletes...</div>
      ) : (
        <div className="border border-brand-secondary rounded-md overflow-hidden">
          <div className="overflow-x-auto">
          <table className="min-w-full bg-white">
            <thead>
              <tr className="border-b border-brand-secondary">
                {COLUMNS.map((col) => (
                  <th
                    key={col.key}
                    className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300 align-top"
                  >
                    <button
                      type="button"
                      onClick={() => toggleSort(col.key)}
                      className="flex w-full items-center gap-1 uppercase font-bold text-left text-brand-primary hover:text-brand-primary-hover cursor-pointer"
                      title="Click to sort"
                    >
                      {col.label}
                      <span className="text-[10px] text-gray-400">{sortArrow(col.key)}</span>
                    </button>
                    {col.options ? (
                      <select
                        value={colFilters[col.key] || ''}
                        onChange={(e) => setColFilter(col.key, e.target.value)}
                        className="mt-1 w-full font-normal normal-case text-xs border border-brand-secondary rounded px-1 py-0.5 focus:outline-none focus:border-brand-accent"
                      >
                        <option value="">All</option>
                        {col.options.map((o) => (
                          <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                      </select>
                    ) : (
                      <input
                        type="text"
                        value={colFilters[col.key] || ''}
                        onChange={(e) => setColFilter(col.key, e.target.value)}
                        placeholder="Filter…"
                        className="mt-1 w-full font-normal normal-case text-xs border border-brand-secondary rounded px-1 py-0.5 focus:outline-none focus:border-brand-accent"
                      />
                    )}
                  </th>
                ))}
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase w-32 align-top">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {displayedAthletes.map((athlete, index) => (
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
                        if (age === null) return 'Invalid date';
                        const month = new Date(athlete.date_of_birth).getMonth();
                        const quarter = month <= 2 ? 'Q1' : month <= 5 ? 'Q2' : month <= 8 ? 'Q3' : 'Q4';
                        const birthYear = new Date(athlete.date_of_birth).getFullYear();
                        const now = new Date();
                        const seasonYear = now.getMonth() >= 7 ? now.getFullYear() + 1 : now.getFullYear();
                        const uGroup = `U${seasonYear - birthYear}`;
                        return (
                          <>
                            {age} years
                            <span className="ml-2 text-xs font-semibold bg-brand-secondary text-brand-primary px-1.5 py-0.5 rounded-full">
                              {quarter}
                            </span>
                            {uGroup && (
                              <span className="ml-1 text-xs font-semibold bg-brand-primary text-white px-1.5 py-0.5 rounded-full">
                                {uGroup}
                              </span>
                            )}
                          </>
                        );
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
                    <div className="text-sm text-brand-primary">
                      {athlete.grade_level != null ? `Grade ${athlete.grade_level}` : 'Not set'}
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
                        <div className="text-xs">
                          <button
                            onClick={() => {
                              const nameParts = (athlete.primary_guardian_name || '').split(' ');
                              setComposeRecipient({
                                id: athlete.id,
                                type: 'guardian' as const,
                                first_name: nameParts[0] || '',
                                last_name: nameParts.slice(1).join(' ') || '',
                                email: athlete.primary_guardian_email,
                                phone: athlete.primary_guardian_phone,
                                suppressed: false
                              });
                              setShowEmailCompose(true);
                            }}
                            className="text-brand-primary hover:underline cursor-pointer"
                          >
                            {athlete.primary_guardian_email}
                          </button>
                        </div>
                      )}
                      {athlete.primary_guardian_phone && (
                        <div className="text-xs">
                          <button
                            onClick={() => {
                              const nameParts = (athlete.primary_guardian_name || '').split(' ');
                              setComposeRecipient({
                                id: athlete.id,
                                type: 'guardian' as const,
                                first_name: nameParts[0] || '',
                                last_name: nameParts.slice(1).join(' ') || '',
                                email: athlete.primary_guardian_email,
                                phone: athlete.primary_guardian_phone,
                                suppressed: false
                              });
                              setShowSmsCompose(true);
                            }}
                            className="text-brand-primary hover:underline cursor-pointer"
                          >
                            {athlete.primary_guardian_phone}
                          </button>
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
              {displayedAthletes.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-6 py-8 text-center text-sm text-gray-500">
                    No athletes match the current filters.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          </div>
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

      {/* Compose Modals */}
      {showEmailCompose && (
        <EmailCompose
          isOpen={showEmailCompose}
          onClose={() => { setShowEmailCompose(false); setComposeRecipient(null); }}
          clubProfileId={currentClubId || 0}
          preselectedRecipients={composeRecipient ? [{ ...composeRecipient, suppressed: false }] : []}
        />
      )}
      {showSmsCompose && (
        <SmsCompose
          isOpen={showSmsCompose}
          onClose={() => { setShowSmsCompose(false); setComposeRecipient(null); }}
          clubProfileId={currentClubId || 0}
          preselectedRecipients={composeRecipient ? [{ ...composeRecipient, suppressed: false }] : []}
        />
      )}
    </>
  );
};

export default AthleteManagement;
import React, { useState, useEffect, useMemo } from 'react';
import {
  CONSENT_STATUS_META,
  CONSENT_STATUS_ORDER,
  consentStatusMeta,
  consentStatusRank,
} from '../utils/consentStatus';
import { Link } from 'react-router-dom';
import { ageGroup, ageInYears, ageQuarter } from '../utils/ageGroup';
import { formatGrade, GRADE_OPTIONS as GRADE_LEVEL_OPTIONS } from '../utils/grade';
import AthleteForm from './AthleteForm';
import GuardianManagement from './GuardianManagement';
import EmailCompose from './communications/EmailCompose';
import SmsCompose from './communications/SmsCompose';
import { useOrg } from '../contexts/OrgContext';
import LoadMore from './LoadMore';
import { PageMeta, pageQuery, readPage } from '../utils/pagination';
import PageHeader from './ui/PageHeader';
import DataTable, { DataTableColumn } from './ui/DataTable';
import Button from './ui/Button';

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
  // Every crew member on the athlete, in link order. Crew members are EQUAL —
  // there is no primary guardian in this product (2026-09-02) — so the list
  // screen shows the whole family instead of electing one to stand for it.
  //
  // The gateway still returns `primary_guardian_name/email/phone` for one
  // release (they are simply the first crew member, not a ranked one). Nothing
  // here reads them; they exist so an older deployed bundle does not blank its
  // column mid-deploy.
  guardians?: AthleteCrewMember[];
  email?: string;
  active_status?: boolean;
  created_at?: string;
  teams?: string[];
}

interface AthleteCrewMember {
  guardian_id: number;
  first_name: string;
  last_name: string;
  name: string;
  email?: string | null;
  mobile_phone?: string | null;
  relationship?: string | null;
}

interface AthleteManagementProps {
  onClose?: () => void;
}

const AthleteManagement: React.FC<AthleteManagementProps> = ({ onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
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

  // Parental-consent roll-up, keyed by athlete id. Fetched separately from the
  // athlete list rather than joined into athletes-gateway: consent lives behind
  // api/consent.php, which owns the staff-only scoping (staffManageableAthleteIds),
  // and widening the athlete gateway to carry it would put a compliance read
  // behind a different permission check than the rest of the consent API.
  const [consentByAthlete, setConsentByAthlete] = useState<Record<number, string>>({});

  // ⚠️ The athlete list is PAGINATED (200 a page). Without `page` on screen this
  // table would show the first 200 of a council's roster and look complete.
  const [page, setPage] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);

  useEffect(() => {
    fetchAthletes();
    fetchAvailableTeams();
    fetchConsentSummary();
  }, []);

  const fetchConsentSummary = async () => {
    try {
      const res = await fetch(`${API_URL}/api/consent.php?action=summary`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      const data = await res.json();
      if (res.ok && data.success && Array.isArray(data.athletes)) {
        const map: Record<number, string> = {};
        for (const a of data.athletes) map[a.athlete_id] = a.status;
        setConsentByAthlete(map);
      }
    } catch {
      // Leave the column blank rather than breaking the roster. It renders as
      // "Unknown" (never as a silent pass) — see consentStatusMeta.
    }
  };

  /**
   * One page of athletes. `cursor` null loads the first page and REPLACES the
   * list; a cursor appends, so "Load more" never loses what is already on screen.
   */
  const fetchAthletes = async (cursor: string | null = null) => {
    try {
      // Fetch athletes
      const response = await fetch(
        `${API_URL}/legacy/athletes-gateway.php?list=1${pageQuery(cursor)}`,
        {
          headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
        }
      );
      const data = await response.json();
      const athleteList: Athlete[] = data.athletes || [];
      setPage(readPage(data));
      // The gateway builds its crew list with a LATERAL join and a separate
      // keyed query, so it returns one row per athlete. This dedupe stays as a
      // belt: duplicate React keys silently break row re-ordering on sort (the
      // first sort applies, later ones no-op) and inflate the count, which is a
      // failure with no error message attached to it. It now also spans PAGES:
      // a row edited between two requests can legitimately arrive twice.
      setAthletes((previous) => {
        const merged = cursor ? [...previous, ...athleteList] : athleteList;
        const seen = new Set<number>();
        return merged.filter((a) => {
          if (seen.has(a.id)) return false;
          seen.add(a.id);
          return true;
        });
      });

      // Fetch team-player relationships
      const teamPlayersResponse = await fetch(`${API_URL}/legacy/team-players-gateway.php`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
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
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
        },
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

  // Age-group + age come from the shared calendar-year util (utils/ageGroup).
  const calculateAge = (dob: string): number | null => ageInYears(dob);

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
            <Button
              variant="ghost"
              size="icon"
              aria-label="Close"
              className="text-2xl"
              onClick={onClose}
            >
              ×
            </Button>
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
              consentByAthlete={consentByAthlete}
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
      <PageHeader title="Athlete Management" subtitle="Manage all athletes in your club" />

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
              consentByAthlete={consentByAthlete}
        page={page}
        loadingMore={loadingMore}
        totalLoaded={athletes.length}
        onLoadMore={async () => {
          if (!page?.nextCursor) return;
          setLoadingMore(true);
          try {
            await fetchAthletes(page.nextCursor);
          } finally {
            setLoadingMore(false);
          }
        }}
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
          athleteName={selectedAthleteForGuardians.first_name}
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
  /** athlete id -> consent rollup status. Optional: a caller without it renders
   *  "Unknown", which is honest — a blank cell would read as "fine". */
  consentByAthlete?: Record<number, string>;
  /** Cursor-pagination state for the athlete list. Optional so the embedded
   *  callers that hand this component a fixed array keep compiling; when it is
   *  absent nothing is rendered, which is correct — those lists are complete. */
  page?: PageMeta | null;
  loadingMore?: boolean;
  onLoadMore?: () => void;
  /** Rows loaded so far, before the client-side filters narrow them. */
  totalLoaded?: number;
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
  handleAddToTeam,
  consentByAthlete = {},
  page = null,
  loadingMore = false,
  onLoadMore,
  totalLoaded,
}) => {
  const { currentClubId } = useOrg();
  const [showEmailCompose, setShowEmailCompose] = useState(false);
  const [showSmsCompose, setShowSmsCompose] = useState(false);
  const [composeRecipient, setComposeRecipient] = useState<any>(null);

  // Per-column sort + filter for the athlete table.
  type ColKey = 'name' | 'age' | 'grade' | 'gender' | 'team' | 'guardian' | 'contact' | 'consent';
  // Pre-K / K / 1st … 12th — filter values are the stored integer as a string
  // (matched against athlete.grade_level.toString()).
  const GRADE_OPTIONS = GRADE_LEVEL_OPTIONS.map((o) => ({
    value: String(o.value),
    label: o.label,
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
    { key: 'guardian', label: 'Crew' },
    { key: 'contact', label: 'Contact' },
    {
      key: 'consent',
      label: 'Consent',
      options: CONSENT_STATUS_ORDER.map((k) => ({
        value: k,
        label: CONSENT_STATUS_META[k].label,
      })),
    },
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
  // Sorting and filtering see EVERY crew member, not the one that happens to be
  // rendered. A parent whose name is hidden behind "+1 more" must still be
  // findable by typing it, or the filter quietly lies about who is in the club.
  const crewOf = (a: Athlete): AthleteCrewMember[] => a.guardians ?? [];
  const crewNames = (a: Athlete) =>
    crewOf(a).map((g) => g.name || `${g.first_name || ''} ${g.last_name || ''}`.trim())
      .filter(Boolean).join(', ');
  const contactStr = (a: Athlete) =>
    [...crewOf(a).flatMap((g) => [g.email, g.mobile_phone]), a.email]
      .filter(Boolean).join(' ');
  const ageOf = (a: Athlete) => (a.date_of_birth ? calculateAge(a.date_of_birth) : null);

  const sortValue = (a: Athlete, key: ColKey): string | number => {
    switch (key) {
      case 'name': return `${a.last_name || ''} ${a.first_name || ''}`.trim().toLowerCase();
      case 'age': { const v = ageOf(a); return v == null ? -Infinity : v; }
      case 'grade': return a.grade_level == null ? -Infinity : a.grade_level;
      case 'gender': return (a.gender || '').toLowerCase();
      case 'team': return teamLabel(a).toLowerCase();
      case 'guardian': return crewNames(a).toLowerCase();
      case 'contact': return contactStr(a).toLowerCase();
      case 'consent': return consentStatusRank(consentByAthlete[a.id]);
    }
  };
  const filterText = (a: Athlete, key: ColKey): string => {
    switch (key) {
      case 'name': return `${a.first_name || ''} ${a.middle_initial || ''} ${a.last_name || ''} ${a.preferred_name || ''}`.toLowerCase();
      case 'age': { const v = ageOf(a); return v == null ? '' : String(v); }
      case 'grade': return a.grade_level == null ? '' : String(a.grade_level);
      case 'gender': return (a.gender || '').toLowerCase();
      case 'team': return teamLabel(a).toLowerCase();
      case 'guardian': return crewNames(a).toLowerCase();
      case 'contact': return contactStr(a).toLowerCase();
      case 'consent': return (consentByAthlete[a.id] || '').toLowerCase();
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

  // Page-managed sort and per-column filters: each header carries the existing
  // sort button and filter control, and the rows are handed over already
  // sorted and filtered, so the behaviour is unchanged.
  const columnHeader = (col: (typeof COLUMNS)[number]) => (
    <>
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
    </>
  );

  const cellRenderers: Record<ColKey, (athlete: Athlete) => React.ReactNode> = {
    name: (athlete) => (
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
    ),
    age: (athlete) => (
      <>
        <div className="text-sm text-brand-primary">
          {athlete.date_of_birth ? (() => {
            const age = calculateAge(athlete.date_of_birth);
            if (age === null) return 'Invalid date';
            const quarter = ageQuarter(athlete.date_of_birth) ?? '';
            const uGroup = ageGroup(athlete.date_of_birth) ?? '';
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
      </>
    ),
    grade: (athlete) => (
      <div className="text-sm text-brand-primary">
        {athlete.grade_level != null ? formatGrade(athlete.grade_level) : 'Not set'}
      </div>
    ),
    gender: (athlete) => <div className="text-sm text-brand-primary">{athlete.gender || 'Not set'}</div>,
    team: (athlete) => (
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

        <Button
          variant="ghost"
          size="icon"
          aria-label="Add to team"
          className="text-lg flex-shrink-0"
          onClick={() => setShowTeamSelector(athlete.id)}
          title="Add to team"
        >
          +
        </Button>
      </div>
    ),
    // The whole family, stacked. Two names fit the row; beyond that the count
    // is shown rather than a chosen pair, because picking two of four would put
    // this screen straight back in the business of ranking crew members.
    // Sorting and filtering still read every name.
    guardian: (athlete) =>
      crewOf(athlete).length === 0 ? (
        <div className="text-sm text-gray-500">-</div>
      ) : (
        <div className="text-sm text-brand-primary space-y-0.5">
          {crewOf(athlete).slice(0, 2).map((g) => (
            <div key={g.guardian_id} className="whitespace-nowrap">
              {g.name || `${g.first_name || ''} ${g.last_name || ''}`.trim()}
            </div>
          ))}
          {crewOf(athlete).length > 2 && (
            <div className="text-xs text-gray-500">
              +{crewOf(athlete).length - 2} more
            </div>
          )}
        </div>
      ),
    contact: (athlete) => (
      <div className="space-y-1">
        {crewOf(athlete).slice(0, 2).map((g) => (
          <div key={g.guardian_id}>
            {g.email && (
              <div className="text-xs">
                <Button
                  variant="link"
                  className="normal-case"
                  onClick={() => {
                    setComposeRecipient({
                      id: g.guardian_id,
                      type: 'guardian' as const,
                      first_name: g.first_name || '',
                      last_name: g.last_name || '',
                      email: g.email || undefined,
                      phone: g.mobile_phone || undefined,
                      suppressed: false
                    });
                    setShowEmailCompose(true);
                  }}
                >
                  {g.email}
                </Button>
              </div>
            )}
            {g.mobile_phone && (
              <div className="text-xs">
                <Button
                  variant="link"
                  className="normal-case"
                  onClick={() => {
                    setComposeRecipient({
                      id: g.guardian_id,
                      type: 'guardian' as const,
                      first_name: g.first_name || '',
                      last_name: g.last_name || '',
                      email: g.email || undefined,
                      phone: g.mobile_phone || undefined,
                      suppressed: false
                    });
                    setShowSmsCompose(true);
                  }}
                >
                  {g.mobile_phone}
                </Button>
              </div>
            )}
          </div>
        ))}
        {crewOf(athlete).length > 2 && (
          <div className="text-xs text-gray-500">
            +{crewOf(athlete).length - 2} more
          </div>
        )}
        {athlete.email && (
          <div className="text-xs text-gray-600">
            {athlete.email}
          </div>
        )}
        {crewOf(athlete).length === 0 && athlete.email && (
          <div className="text-xs text-gray-500">Contact via email</div>
        )}
        {crewOf(athlete).length === 0 && !athlete.email && (
          <div className="text-xs text-gray-500">No contact info</div>
        )}
      </div>
    ),
    consent: (athlete) => {
      const meta = consentStatusMeta(consentByAthlete[athlete.id]);
      return (
        <span
          className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${meta.cls}`}
          title={meta.detail}
        >
          {meta.label}
        </span>
      );
    },
  };

  // Columns that wrap (crew, contact) keep their natural width; the rest stay
  // on one line as before.
  const NOWRAP: ColKey[] = ['name', 'age', 'grade', 'gender', 'team', 'consent'];
  const tableColumns: DataTableColumn<Athlete>[] = [
    ...COLUMNS.map((col) => ({
      key: col.key,
      header: columnHeader(col),
      className: `align-top ${NOWRAP.includes(col.key) ? 'whitespace-nowrap' : ''}`.trim(),
      render: cellRenderers[col.key],
    })),
    {
      key: 'actions',
      header: 'Actions',
      width: '8rem',
      className: 'align-top whitespace-nowrap',
      render: (athlete) => (
        <div className="flex flex-col space-y-1">
          <Button variant="link" size="sm" className="justify-start" onClick={() => handleManageGuardians(athlete)}>
            Guardians
          </Button>
          <Button variant="link" size="sm" className="justify-start" onClick={() => handleEditAthlete(athlete)}>
            Edit
          </Button>
          <Button variant="link" size="sm" className="justify-start" onClick={() => handleArchiveAthlete(athlete)}>
            Archive
          </Button>
        </div>
      ),
    },
  ];

  return (
    <>
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
        <span className="text-brand-primary text-sm">
          Showing {displayedAthletes.length}
          {displayedAthletes.length !== athletes.length ? ` of ${athletes.length}` : ''} athlete
          {displayedAthletes.length !== 1 ? 's' : ''}
        </span>
        <Button onClick={handleAddAthlete} className="w-full sm:w-auto">
          + Add New Athlete
        </Button>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading athletes...</div>
      ) : (
        <>
          <DataTable<Athlete>
            columns={tableColumns}
            rows={displayedAthletes}
            rowKey={(athlete) => athlete.id}
            emptyState="No athletes match the current filters."
          />
          <LoadMore
            page={page}
            loading={loadingMore}
            shown={totalLoaded ?? athletes.length}
            label="athletes"
            onLoadMore={() => onLoadMore?.()}
          />
        </>
      )}

      {/* Team Selection Modal */}
      {showTeamSelector !== null && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-brand-secondary rounded-md max-w-2xl w-full max-h-[80vh] overflow-y-auto">
            <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                Add to Team
              </h3>
              <Button
                variant="ghost"
                size="icon"
                aria-label="Close"
                className="text-2xl"
                onClick={() => setShowTeamSelector(null)}
              >
                ×
              </Button>
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
import React, { useState, useEffect } from 'react';
import {
  TryoutRegistration,
  TryoutOffer,
  TryoutRanking,
  EvaluationCriterion,
  TryoutSession,
  TryoutStatus
} from '../types';
import EvaluationModal from './EvaluationModal';

interface TryoutManagementProps {
  programId: number;
  programName: string;
  currentUserId: number;
  onClose: () => void;
}

type Tab = 'registrations' | 'evaluations' | 'rankings' | 'offers';

const TryoutManagement: React.FC<TryoutManagementProps> = ({
  programId,
  programName,
  currentUserId,
  onClose
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [activeTab, setActiveTab] = useState<Tab>('registrations');
  const [registrations, setRegistrations] = useState<TryoutRegistration[]>([]);
  const [rankings, setRankings] = useState<TryoutRanking[]>([]);
  const [offers, setOffers] = useState<TryoutOffer[]>([]);
  const [sessions, setSessions] = useState<TryoutSession[]>([]);
  const [criteria, setCriteria] = useState<EvaluationCriterion[]>([]);
  const [teams, setTeams] = useState<{ id: number; name: string; age_group?: string }[]>([]);
  const [loading, setLoading] = useState(true);

  // Modals
  const [evaluatingRegistration, setEvaluatingRegistration] = useState<TryoutRegistration | null>(null);
  const [selectedSession, setSelectedSession] = useState<number | undefined>(undefined);

  // Filters
  const [statusFilter, setStatusFilter] = useState<TryoutStatus | 'all'>('all');
  const [searchTerm, setSearchTerm] = useState('');

  useEffect(() => {
    loadData();
  }, [programId, activeTab]);

  const loadData = async () => {
    setLoading(true);
    try {
      if (activeTab === 'registrations' || activeTab === 'evaluations') {
        const [regRes, sessRes, critRes] = await Promise.all([
          fetch(`${API_URL}/registration/tryouts-api.php?path=registrations&program_id=${programId}`),
          fetch(`${API_URL}/registration/tryouts-api.php?path=sessions&program_id=${programId}`),
          fetch(`${API_URL}/registration/tryouts-api.php?path=criteria&program_id=${programId}`)
        ]);
        setRegistrations(await regRes.json());
        setSessions(await sessRes.json());
        setCriteria(await critRes.json());
      } else if (activeTab === 'rankings') {
        const token = localStorage.getItem('auth_token');
        const [rankRes, teamsRes] = await Promise.all([
          fetch(`${API_URL}/registration/tryouts-api.php?path=rankings&program_id=${programId}`),
          fetch(`${API_URL}/legacy/teams-gateway.php?club_id=1`, {
            headers: { 'Authorization': `Bearer ${token}` }
          })
        ]);
        setRankings(await rankRes.json());
        const teamsData = await teamsRes.json();
        setTeams(teamsData.teams || []);
      } else if (activeTab === 'offers') {
        const res = await fetch(`${API_URL}/registration/tryouts-api.php?path=offers&program_id=${programId}`);
        setOffers(await res.json());
      }
    } catch (error) {
      console.error('Error loading data:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleCheckIn = async (registrationId: number, tryoutNumber?: string) => {
    try {
      const response = await fetch(`${API_URL}/registration/tryouts-api.php?path=check-in`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          registration_id: registrationId,
          tryout_number: tryoutNumber
        })
      });

      if (response.ok) {
        loadData();
      }
    } catch (error) {
      console.error('Error checking in athlete:', error);
    }
  };

  const handleSendOffers = async (selectedIds: number[], offerType: 'roster' | 'waitlist' | 'not_selected', teamId?: number) => {
    try {
      const response = await fetch(`${API_URL}/registration/tryouts-api.php?path=send-offers`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          offers: selectedIds.map(id => ({
            registration_id: id,
            offer_type: offerType,
            team_id: teamId
          }))
        })
      });

      if (response.ok) {
        loadData();
        setActiveTab('offers');
      }
    } catch (error) {
      console.error('Error sending offers:', error);
    }
  };

  const handleUpdateOffer = async (offerId: number, response: 'accepted' | 'declined') => {
    try {
      const res = await fetch(`${API_URL}/registration/tryouts-api.php?path=update-offer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          offer_id: offerId,
          response
        })
      });

      if (res.ok) {
        loadData();
      }
    } catch (error) {
      console.error('Error updating offer:', error);
    }
  };

  const handleAddToRoster = async (registrationId: number, teamId: number) => {
    try {
      const response = await fetch(`${API_URL}/registration/tryouts-api.php?path=add-to-roster`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          registration_id: registrationId,
          team_id: teamId
        })
      });

      if (response.ok) {
        loadData();
      }
    } catch (error) {
      console.error('Error adding to roster:', error);
    }
  };

  const getStatusBadge = (status?: TryoutStatus) => {
    const statusColors: Record<string, string> = {
      registered: 'bg-gray-100 text-gray-700',
      checked_in: 'bg-blue-100 text-blue-700',
      evaluated: 'bg-purple-100 text-purple-700',
      offered: 'bg-yellow-100 text-yellow-700',
      waitlist: 'bg-orange-100 text-orange-700',
      not_selected: 'bg-red-100 text-red-700',
      accepted: 'bg-green-100 text-green-700',
      declined: 'bg-red-100 text-red-700',
      rostered: 'bg-green-200 text-green-800'
    };

    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${statusColors[status || 'registered']}`}>
        {status?.replace('_', ' ').toUpperCase() || 'REGISTERED'}
      </span>
    );
  };

  const filteredRegistrations = registrations.filter(r => {
    const matchesStatus = statusFilter === 'all' || r.tryout_status === statusFilter || (!r.tryout_status && statusFilter === 'registered');
    const matchesSearch = !searchTerm ||
      `${r.first_name} ${r.last_name}`.toLowerCase().includes(searchTerm.toLowerCase());
    return matchesStatus && matchesSearch;
  });

  const tabs: { key: Tab; label: string }[] = [
    { key: 'registrations', label: 'Check-In' },
    { key: 'evaluations', label: 'Evaluate' },
    { key: 'rankings', label: 'Rankings' },
    { key: 'offers', label: 'Offers' }
  ];

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md w-full max-w-6xl h-[90vh] flex flex-col">
        {/* Header */}
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <div>
            <h2 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
              Manage Tryout
            </h2>
            <p className="text-gray-600">{programName}</p>
          </div>
          <button
            onClick={onClose}
            className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
          >
            &times;
          </button>
        </div>

        {/* Tabs */}
        <div className="border-b border-brand-secondary px-6">
          <div className="flex space-x-6">
            {tabs.map(tab => (
              <button
                key={tab.key}
                onClick={() => setActiveTab(tab.key)}
                className={`py-3 px-2 font-medium text-sm uppercase tracking-wide border-b-2 transition-colors ${
                  activeTab === tab.key
                    ? 'border-brand-primary text-brand-primary'
                    : 'border-transparent text-gray-500 hover:text-brand-primary'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-hidden flex flex-col">
          {loading ? (
            <div className="flex-1 flex items-center justify-center">
              <div className="text-brand-primary">Loading...</div>
            </div>
          ) : (
            <>
              {/* Filters */}
              {(activeTab === 'registrations' || activeTab === 'evaluations') && (
                <div className="p-4 border-b border-brand-secondary bg-gray-50">
                  <div className="flex items-center space-x-4">
                    <input
                      type="text"
                      placeholder="Search athletes..."
                      className="border border-brand-secondary rounded-md px-3 py-2 w-64"
                      value={searchTerm}
                      onChange={(e) => setSearchTerm(e.target.value)}
                    />
                    <select
                      className="border border-brand-secondary rounded-md px-3 py-2"
                      value={statusFilter}
                      onChange={(e) => setStatusFilter(e.target.value as TryoutStatus | 'all')}
                    >
                      <option value="all">All Statuses</option>
                      <option value="registered">Registered</option>
                      <option value="checked_in">Checked In</option>
                      <option value="evaluated">Evaluated</option>
                      <option value="offered">Offered</option>
                      <option value="accepted">Accepted</option>
                      <option value="declined">Declined</option>
                    </select>
                    {activeTab === 'evaluations' && sessions.length > 0 && (
                      <select
                        className="border border-brand-secondary rounded-md px-3 py-2"
                        value={selectedSession || ''}
                        onChange={(e) => setSelectedSession(e.target.value ? parseInt(e.target.value) : undefined)}
                      >
                        <option value="">All Sessions</option>
                        {sessions.map(s => (
                          <option key={s.id} value={s.id}>
                            {new Date(s.session_date).toLocaleDateString()} - {s.location || 'TBD'}
                          </option>
                        ))}
                      </select>
                    )}
                    <div className="ml-auto text-sm text-gray-600">
                      {filteredRegistrations.length} athletes
                    </div>
                  </div>
                </div>
              )}

              {/* Tab Content */}
              <div className="flex-1 overflow-y-auto p-6">
                {/* Check-In Tab */}
                {activeTab === 'registrations' && (
                  <RegistrationsTable
                    registrations={filteredRegistrations}
                    onCheckIn={handleCheckIn}
                    getStatusBadge={getStatusBadge}
                  />
                )}

                {/* Evaluations Tab */}
                {activeTab === 'evaluations' && (
                  <EvaluationsTable
                    registrations={filteredRegistrations.filter(
                      r => r.tryout_status === 'checked_in' || r.tryout_status === 'evaluated'
                    )}
                    criteria={criteria}
                    onEvaluate={(reg) => setEvaluatingRegistration(reg)}
                    getStatusBadge={getStatusBadge}
                  />
                )}

                {/* Rankings Tab */}
                {activeTab === 'rankings' && (
                  <RankingsTable
                    rankings={rankings}
                    teams={teams}
                    onSendOffers={handleSendOffers}
                    getStatusBadge={getStatusBadge}
                  />
                )}

                {/* Offers Tab */}
                {activeTab === 'offers' && (
                  <OffersTable
                    offers={offers}
                    onUpdateOffer={handleUpdateOffer}
                    onAddToRoster={handleAddToRoster}
                  />
                )}
              </div>
            </>
          )}
        </div>

        {/* Evaluation Modal */}
        {evaluatingRegistration && (
          <EvaluationModal
            registration={evaluatingRegistration}
            programId={programId}
            evaluatorId={currentUserId}
            sessionId={selectedSession}
            onClose={() => setEvaluatingRegistration(null)}
            onSave={() => {
              setEvaluatingRegistration(null);
              loadData();
            }}
          />
        )}
      </div>
    </div>
  );
};

// Sub-components for each tab
interface RegistrationsTableProps {
  registrations: TryoutRegistration[];
  onCheckIn: (id: number, tryoutNumber?: string) => void;
  getStatusBadge: (status?: TryoutStatus) => React.ReactElement;
}

const RegistrationsTable: React.FC<RegistrationsTableProps> = ({
  registrations,
  onCheckIn,
  getStatusBadge
}) => {
  const [tryoutNumbers, setTryoutNumbers] = React.useState<Record<number, string>>({});

  const handleTryoutNumberChange = (id: number, value: string) => {
    setTryoutNumbers(prev => ({ ...prev, [id]: value }));
  };

  if (registrations.length === 0) {
    return (
      <div className="text-center py-12 text-gray-500">
        No registrations found.
      </div>
    );
  }

  return (
    <table className="w-full">
      <thead>
        <tr className="border-b border-brand-secondary">
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Tryout #</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Athlete</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">DOB</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Status</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Registered</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Actions</th>
        </tr>
      </thead>
      <tbody>
        {registrations.map(reg => (
          <tr key={reg.id} className="border-b border-gray-100 hover:bg-gray-50">
            <td className="py-3 px-4">
              {reg.tryout_status === 'checked_in' || (reg.tryout_status && reg.tryout_status !== 'registered') ? (
                <span className="font-bold text-lg text-brand-primary">{reg.tryout_number || '-'}</span>
              ) : (
                <input
                  type="text"
                  placeholder="#"
                  value={tryoutNumbers[reg.id] || ''}
                  onChange={(e) => handleTryoutNumberChange(reg.id, e.target.value)}
                  className="w-16 px-2 py-1 border border-gray-300 rounded text-center font-bold"
                />
              )}
            </td>
            <td className="py-3 px-4 font-medium">
              {reg.first_name} {reg.last_name}
            </td>
            <td className="py-3 px-4 text-gray-600">
              {reg.date_of_birth ? new Date(reg.date_of_birth).toLocaleDateString() : '-'}
            </td>
            <td className="py-3 px-4">
              {getStatusBadge(reg.tryout_status)}
            </td>
            <td className="py-3 px-4 text-gray-600">
              {reg.submitted_at ? new Date(reg.submitted_at).toLocaleDateString() : '-'}
            </td>
            <td className="py-3 px-4">
              {(!reg.tryout_status || reg.tryout_status === 'registered') && (
                <button
                  onClick={() => onCheckIn(reg.id, tryoutNumbers[reg.id])}
                  className="text-brand-primary hover:text-brand-primary-hover text-sm font-semibold uppercase"
                >
                  Check In
                </button>
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
};

interface EvaluationsTableProps {
  registrations: TryoutRegistration[];
  criteria: EvaluationCriterion[];
  onEvaluate: (reg: TryoutRegistration) => void;
  getStatusBadge: (status?: TryoutStatus) => React.ReactElement;
}

const EvaluationsTable: React.FC<EvaluationsTableProps> = ({
  registrations,
  criteria,
  onEvaluate,
  getStatusBadge
}) => {
  if (registrations.length === 0) {
    return (
      <div className="text-center py-12 text-gray-500">
        No checked-in athletes to evaluate. Check in athletes first.
      </div>
    );
  }

  return (
    <table className="w-full">
      <thead>
        <tr className="border-b border-brand-secondary">
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">#</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Athlete</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Status</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Evaluations</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Avg Score</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Actions</th>
        </tr>
      </thead>
      <tbody>
        {registrations.map(reg => (
          <tr key={reg.id} className="border-b border-gray-100 hover:bg-gray-50">
            <td className="py-3 px-4 font-bold text-lg text-brand-primary">
              {reg.tryout_number || '-'}
            </td>
            <td className="py-3 px-4 font-medium">
              {reg.first_name} {reg.last_name}
            </td>
            <td className="py-3 px-4">
              {getStatusBadge(reg.tryout_status)}
            </td>
            <td className="py-3 px-4 text-gray-600">
              {reg.evaluation_count || 0} evaluation{(reg.evaluation_count || 0) !== 1 ? 's' : ''}
            </td>
            <td className="py-3 px-4">
              {reg.overall_score != null ? (
                <span className="font-medium text-brand-primary">{Number(reg.overall_score).toFixed(1)}</span>
              ) : '-'}
            </td>
            <td className="py-3 px-4">
              <button
                onClick={() => onEvaluate(reg)}
                className="text-brand-primary hover:text-brand-primary-hover text-sm font-semibold uppercase"
              >
                {reg.evaluation_count ? 'View/Edit' : 'Evaluate'}
              </button>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
};

interface RankingsTableProps {
  rankings: TryoutRanking[];
  teams: { id: number; name: string; age_group?: string }[];
  onSendOffers: (ids: number[], type: 'roster' | 'waitlist' | 'not_selected', teamId?: number) => void;
  getStatusBadge: (status?: TryoutStatus) => React.ReactElement;
}

const RankingsTable: React.FC<RankingsTableProps> = ({
  rankings,
  teams,
  onSendOffers,
  getStatusBadge
}) => {
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [selectedTeamId, setSelectedTeamId] = useState<number | undefined>(undefined);

  const toggleSelection = (id: number) => {
    setSelectedIds(prev =>
      prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]
    );
  };

  const toggleAll = () => {
    if (selectedIds.length === rankings.length) {
      setSelectedIds([]);
    } else {
      setSelectedIds(rankings.map(r => r.id));
    }
  };

  // Calculate average age of selected athletes
  const getAverageAge = (): number | null => {
    const selectedRankings = rankings.filter(r => selectedIds.includes(r.id));
    if (selectedRankings.length === 0) return null;

    const ages = selectedRankings
      .filter(r => r.date_of_birth)
      .map(r => {
        const dob = new Date(r.date_of_birth!);
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
          age--;
        }
        return age;
      });

    if (ages.length === 0) return null;
    return Math.round(ages.reduce((sum, age) => sum + age, 0) / ages.length);
  };

  // Parse age group, handling "U6" or "6" formats
  const parseAgeGroup = (ageGroup: string | undefined): number => {
    if (!ageGroup) return 999;
    // Remove 'U' or 'u' prefix if present
    const numStr = ageGroup.replace(/^[Uu]/, '');
    const num = parseInt(numStr);
    return isNaN(num) ? 999 : num;
  };

  // Sort teams: closest to athlete age first, then by U low to high
  const getSortedTeams = () => {
    const avgAge = getAverageAge();
    return [...teams].sort((a, b) => {
      const ageA = parseAgeGroup(a.age_group);
      const ageB = parseAgeGroup(b.age_group);

      if (avgAge !== null) {
        // Sort by distance from athlete's age first
        const distA = Math.abs(ageA - avgAge);
        const distB = Math.abs(ageB - avgAge);
        if (distA !== distB) return distA - distB;
      }

      // Then sort by age group low to high
      return ageA - ageB;
    });
  };

  // Calculate age from date of birth
  const calculateAge = (dob: string | undefined): number | null => {
    if (!dob) return null;
    const birthDate = new Date(dob);
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
      age--;
    }
    return age;
  };

  if (rankings.length === 0) {
    return (
      <div className="text-center py-12 text-gray-500">
        No rankings available. Athletes need to be evaluated first.
      </div>
    );
  }

  return (
    <div>
      {/* Action Bar */}
      {selectedIds.length > 0 && (
        <div className="mb-4 p-4 bg-gray-50 rounded-md">
          <div className="flex items-center justify-between mb-3">
            <span className="text-brand-primary font-medium">
              {selectedIds.length} athlete{selectedIds.length !== 1 ? 's' : ''} selected
            </span>
          </div>
          <div className="flex items-center space-x-3">
            <select
              value={selectedTeamId || ''}
              onChange={(e) => setSelectedTeamId(e.target.value ? Number(e.target.value) : undefined)}
              className="px-3 py-2 border border-gray-300 rounded-md text-sm"
            >
              <option value="">Select Team...</option>
              {getSortedTeams().map(team => (
                <option key={team.id} value={team.id}>
                  {team.age_group
                    ? `U${team.age_group.toString().replace(/^[Uu]/, '')} - ${team.name}`
                    : team.name}
                </option>
              ))}
            </select>
            <button
              onClick={() => {
                if (!selectedTeamId) {
                  alert('Please select a team for roster offers');
                  return;
                }
                onSendOffers(selectedIds, 'roster', selectedTeamId);
                setSelectedIds([]);
              }}
              className="px-3 py-2 bg-green-600 text-white rounded-md text-sm font-semibold uppercase hover:bg-green-700"
            >
              Send Roster Offer
            </button>
            <button
              onClick={() => {
                onSendOffers(selectedIds, 'waitlist');
                setSelectedIds([]);
              }}
              className="px-3 py-2 bg-yellow-600 text-white rounded-md text-sm font-semibold uppercase hover:bg-yellow-700"
            >
              Waitlist
            </button>
            <button
              onClick={() => {
                onSendOffers(selectedIds, 'not_selected');
                setSelectedIds([]);
              }}
              className="px-3 py-2 bg-gray-600 text-white rounded-md text-sm font-semibold uppercase hover:bg-gray-700"
            >
              Not Selected
            </button>
          </div>
        </div>
      )}

      <table className="w-full">
        <thead>
          <tr className="border-b border-brand-secondary">
            <th className="text-left py-3 px-4">
              <input
                type="checkbox"
                checked={selectedIds.length === rankings.length}
                onChange={toggleAll}
                className="rounded"
              />
            </th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Rank</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">#</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Athlete</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Age</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Next Yr</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Evaluations</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Score</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Range</th>
            <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Status</th>
          </tr>
        </thead>
        <tbody>
          {rankings.map((ranking, index) => (
            <tr key={ranking.id} className="border-b border-gray-100 hover:bg-gray-50">
              <td className="py-3 px-4">
                <input
                  type="checkbox"
                  checked={selectedIds.includes(ranking.id)}
                  onChange={() => toggleSelection(ranking.id)}
                  className="rounded"
                />
              </td>
              <td className="py-3 px-4 font-bold text-brand-primary">
                #{index + 1}
              </td>
              <td className="py-3 px-4 font-bold text-lg">
                {ranking.tryout_number || '-'}
              </td>
              <td className="py-3 px-4 font-medium">
                {ranking.first_name} {ranking.last_name}
              </td>
              <td className="py-3 px-4 text-gray-600">
                {calculateAge(ranking.date_of_birth) ?? '-'}
              </td>
              <td className="py-3 px-4 text-gray-600">
                {calculateAge(ranking.date_of_birth) != null ? calculateAge(ranking.date_of_birth)! + 1 : '-'}
              </td>
              <td className="py-3 px-4 text-gray-600">
                {ranking.evaluation_count}
              </td>
              <td className="py-3 px-4">
                <span className="font-bold text-lg text-brand-primary">
                  {ranking.avg_score != null ? Number(ranking.avg_score).toFixed(1) : '-'}
                </span>
              </td>
              <td className="py-3 px-4 text-gray-600 text-sm">
                {ranking.min_score != null && ranking.max_score != null
                  ? `${Number(ranking.min_score).toFixed(1)} - ${Number(ranking.max_score).toFixed(1)}`
                  : '-'}
              </td>
              <td className="py-3 px-4">
                {getStatusBadge(ranking.tryout_status)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

interface OffersTableProps {
  offers: TryoutOffer[];
  onUpdateOffer: (offerId: number, response: 'accepted' | 'declined') => void;
  onAddToRoster: (registrationId: number, teamId: number) => void;
}

const OffersTable: React.FC<OffersTableProps> = ({
  offers,
  onUpdateOffer,
  onAddToRoster
}) => {
  const getOfferTypeBadge = (type: string) => {
    const colors: Record<string, string> = {
      roster: 'bg-green-100 text-green-700',
      waitlist: 'bg-yellow-100 text-yellow-700',
      not_selected: 'bg-gray-100 text-gray-700'
    };
    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[type]}`}>
        {type.replace('_', ' ').toUpperCase()}
      </span>
    );
  };

  const getResponseBadge = (response?: string) => {
    if (!response) return null;
    const colors: Record<string, string> = {
      accepted: 'bg-green-100 text-green-700',
      declined: 'bg-red-100 text-red-700'
    };
    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[response]}`}>
        {response.toUpperCase()}
      </span>
    );
  };

  if (offers.length === 0) {
    return (
      <div className="text-center py-12 text-gray-500">
        No offers sent yet. Use the Rankings tab to send offers.
      </div>
    );
  }

  return (
    <table className="w-full">
      <thead>
        <tr className="border-b border-brand-secondary">
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Athlete</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Offer Type</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Team</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Response</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Sent</th>
          <th className="text-left py-3 px-4 text-brand-primary text-sm font-medium uppercase">Actions</th>
        </tr>
      </thead>
      <tbody>
        {offers.map(offer => (
          <tr key={offer.id} className="border-b border-gray-100 hover:bg-gray-50">
            <td className="py-3 px-4 font-medium">
              {offer.first_name} {offer.last_name}
            </td>
            <td className="py-3 px-4">
              {getOfferTypeBadge(offer.offer_type)}
            </td>
            <td className="py-3 px-4 text-gray-600">
              {offer.team_name || '-'}
            </td>
            <td className="py-3 px-4">
              {getResponseBadge(offer.response) || (
                <span className="text-gray-400 text-sm">Pending</span>
              )}
            </td>
            <td className="py-3 px-4 text-gray-600">
              {offer.sent_at ? new Date(offer.sent_at).toLocaleDateString() : '-'}
            </td>
            <td className="py-3 px-4">
              {offer.offer_type === 'roster' && !offer.response && (
                <div className="flex space-x-2">
                  <button
                    onClick={() => onUpdateOffer(offer.id!, 'accepted')}
                    className="text-green-600 hover:text-green-700 text-sm font-semibold uppercase"
                  >
                    Accept
                  </button>
                  <button
                    onClick={() => onUpdateOffer(offer.id!, 'declined')}
                    className="text-red-600 hover:text-red-700 text-sm font-semibold uppercase"
                  >
                    Decline
                  </button>
                </div>
              )}
              {offer.response === 'accepted' && offer.team_id && (
                <button
                  onClick={() => onAddToRoster(offer.registration_id, offer.team_id!)}
                  className="text-brand-primary hover:text-brand-primary-hover text-sm font-semibold uppercase"
                >
                  Add to Roster
                </button>
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
};

export default TryoutManagement;

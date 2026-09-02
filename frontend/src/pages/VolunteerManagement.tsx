import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import LoadMore from '../components/LoadMore';
import { PageMeta, pageQuery, readPage } from '../utils/pagination';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Volunteer {
  id: number;
  user_id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  team_id: number;
  team_name: string;
  bg_check_status: 'cleared' | 'pending' | 'expired' | 'never_checked';
  start_date: string;
  end_date: string | null;
  status: 'active' | 'inactive';
  notes: string;
}

interface ComplianceSummary {
  total_volunteers: number;
  cleared: number;
  pending: number;
  expired: number;
  never_checked: number;
  active_count: number;
  compliance_rate: number;
  pending_signups: number;
}

interface Team {
  id: number;
  name: string;
}

interface TeamCompliance {
  team_id: number;
  team_name: string;
  age_group: string | null;
  division: string | null;
  volunteer_count: number;
  cleared: number;
  pending_bg: number;
  expired_bg: number;
  compliance_rate: number;
}

interface UserSearchResult {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  bg_check_status: 'cleared' | 'pending' | 'expired' | 'never_checked';
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '--';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

const BG_BADGE_STYLES: Record<string, string> = {
  cleared: 'bg-green-100 text-green-800',
  pending: 'bg-yellow-100 text-yellow-800',
  expired: 'bg-red-100 text-red-800',
  never_checked: 'bg-gray-100 text-gray-700',
};

const STATUS_BADGE_STYLES: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  inactive: 'bg-gray-100 text-gray-700',
};

export const VolunteerManagement: React.FC = () => {
  const { currentClubId, isClubAdmin } = useOrg();
  const navigate = useNavigate();
  const token = localStorage.getItem('auth_token');

  // Data
  const [volunteers, setVolunteers] = useState<Volunteer[]>([]);
  const [page, setPage] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [compliance, setCompliance] = useState<ComplianceSummary | null>(null);
  const [teamCompliance, setTeamCompliance] = useState<TeamCompliance[]>([]);
  const [teams, setTeams] = useState<Team[]>([]);
  const [loading, setLoading] = useState(true);

  // Filters
  const [filterTeam, setFilterTeam] = useState<string>('all');
  const [filterBgStatus, setFilterBgStatus] = useState<string>('all');
  const [filterActive, setFilterActive] = useState<'active' | 'inactive' | 'all'>('active');
  const [searchQuery, setSearchQuery] = useState('');

  // Edit modal
  const [editingVolunteer, setEditingVolunteer] = useState<Volunteer | null>(null);
  const [editForm, setEditForm] = useState<{
    status: string;
    notes: string;
    start_date: string;
    end_date: string;
    bg_check_status: string;
  }>({ status: '', notes: '', start_date: '', end_date: '', bg_check_status: '' });
  const [editSaving, setEditSaving] = useState(false);

  // Add modal
  const [showAddModal, setShowAddModal] = useState(false);
  const [addMode, setAddMode] = useState<'new' | 'existing'>('new');
  const [userSearch, setUserSearch] = useState('');
  const [userResults, setUserResults] = useState<UserSearchResult[]>([]);
  const [selectedUser, setSelectedUser] = useState<UserSearchResult | null>(null);
  const [newVolunteerForm, setNewVolunteerForm] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
  });
  const [addForm, setAddForm] = useState({
    team_id: '',
    start_date: new Date().toISOString().split('T')[0],
    end_date: '',
    notes: '',
  });
  const [addSaving, setAddSaving] = useState(false);
  const [searchingUsers, setSearchingUsers] = useState(false);

  // Remove confirmation
  const [removingId, setRemovingId] = useState<number | null>(null);

  // Stable across renders so the fetch callbacks/effects below can depend on it
  // without being rebuilt every render.
  const headers = useMemo(() => ({
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`,
  }), [token]);

  /**
   * One page of volunteers. A null cursor loads the first page and replaces the
   * list; a cursor appends.
   *
   * ⚠️ `club-volunteers` is PAGINATED (200 a page). A council's volunteer roster
   * is the largest list this product serves, and the compliance filters below
   * run on what has been LOADED — so "3 expired" means "3 in the rows you have",
   * which is why <LoadMore> has to be visible rather than implied.
   */
  const fetchVolunteers = useCallback(async (cursor: string | null = null) => {
    if (!currentClubId) return;
    if (!cursor) setLoading(true);
    try {
      const action = isClubAdmin ? 'club-volunteers' : 'team-volunteers';
      const idParam = isClubAdmin ? `club_id=${currentClubId}` : `team_id=${currentClubId}`;
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=${action}&${idParam}${pageQuery(cursor)}`,
        { headers }
      );
      const data = await res.json();
      const raw = Array.isArray(data) ? data : data.volunteers || [];
      // team-volunteers is not paginated and returns no `page`; readPage answers
      // null for it, and LoadMore renders nothing. That is correct — one team's
      // volunteers really is the whole list.
      setPage(readPage(data));
      const mapped = raw.map((v: any) => ({
        ...v,
        bg_check_status: v.bg_check_status || v.background_check_status || 'never_checked',
      }));
      setVolunteers((previous) => (cursor ? [...previous, ...mapped] : mapped));
    } catch (err) {
      console.error('Error fetching volunteers:', err);
    } finally {
      setLoading(false);
    }
  }, [currentClubId, isClubAdmin, headers]);

  const loadMoreVolunteers = useCallback(async () => {
    if (!page?.nextCursor) return;
    setLoadingMore(true);
    try {
      await fetchVolunteers(page.nextCursor);
    } finally {
      setLoadingMore(false);
    }
  }, [page, fetchVolunteers]);

  const fetchTeams = useCallback(async () => {
    if (!currentClubId) return;
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=available-teams&club_id=${currentClubId}`,
        { headers }
      );
      const data = await res.json();
      const teamList = data.teams || (Array.isArray(data) ? data : []);
      setTeams(teamList.map((t: any) => ({ id: t.id, name: t.name })));
    } catch (err) {
      console.error('Error fetching teams:', err);
    }
  }, [currentClubId, headers]);

  const fetchCompliance = useCallback(async () => {
    if (!currentClubId) return;
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=compliance&club_id=${currentClubId}`,
        { headers }
      );
      const data = await res.json();
      setCompliance(data.summary || null);
      // Per-team compliance breakdown (was previously fetched and discarded —
      // the dashboard rendered totals but never the per-team table).
      setTeamCompliance(Array.isArray(data.team_breakdown) ? data.team_breakdown : []);
    } catch (err) {
      console.error('Error fetching compliance:', err);
    }
  }, [currentClubId, headers]);

  useEffect(() => {
    fetchVolunteers();
    fetchCompliance();
    fetchTeams();
  }, [fetchVolunteers, fetchCompliance, fetchTeams]);

  // User search for Add modal
  useEffect(() => {
    if (!userSearch || userSearch.length < 2 || !currentClubId) {
      setUserResults([]);
      return;
    }
    const timeout = setTimeout(async () => {
      setSearchingUsers(true);
      try {
        const res = await fetch(
          `${API_URL}/api/volunteer-gateway.php?action=search-users&club_id=${currentClubId}&q=${encodeURIComponent(userSearch)}`,
          { headers }
        );
        const data = await res.json();
        const users = Array.isArray(data) ? data : data.users || [];
        // Normalize: backend returns background_check_status, frontend uses bg_check_status
        setUserResults(users.map((u: any) => ({
          ...u,
          bg_check_status: u.bg_check_status || u.background_check_status || 'never_checked',
        })));
      } catch (err) {
        console.error('Error searching users:', err);
      } finally {
        setSearchingUsers(false);
      }
    }, 300);
    return () => clearTimeout(timeout);
  }, [userSearch, currentClubId, headers]);

  // Filtered volunteers
  const filteredVolunteers = volunteers.filter((v) => {
    if (filterTeam !== 'all' && String(v.team_id) !== filterTeam) return false;
    if (filterBgStatus !== 'all' && v.bg_check_status !== filterBgStatus) return false;
    if (filterActive !== 'all' && v.status !== filterActive) return false;
    if (searchQuery) {
      const q = searchQuery.toLowerCase();
      const match =
        v.first_name.toLowerCase().includes(q) ||
        v.last_name.toLowerCase().includes(q) ||
        v.email.toLowerCase().includes(q) ||
        (v.phone && v.phone.includes(q));
      if (!match) return false;
    }
    return true;
  });

  // Handlers
  const handleEdit = (vol: Volunteer) => {
    setEditingVolunteer(vol);
    setEditForm({
      status: vol.status,
      notes: vol.notes || '',
      start_date: vol.start_date ? vol.start_date.split('T')[0] : '',
      end_date: vol.end_date ? vol.end_date.split('T')[0] : '',
      bg_check_status: vol.bg_check_status,
    });
  };

  const handleEditSave = async () => {
    if (!editingVolunteer) return;
    setEditSaving(true);
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=update-volunteer&volunteer_id=${editingVolunteer.id}`,
        {
          method: 'PUT',
          headers,
          body: JSON.stringify({
            ...editForm,
            background_check_status: editForm.bg_check_status,
          }),
        }
      );
      if (!res.ok) throw new Error('Update failed');
      setEditingVolunteer(null);
      fetchVolunteers();
      fetchCompliance();
    } catch (err) {
      console.error('Error updating volunteer:', err);
      alert('Failed to update volunteer. Please try again.');
    } finally {
      setEditSaving(false);
    }
  };

  const handleRemove = async (id: number) => {
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=remove-volunteer&volunteer_id=${id}`,
        { method: 'DELETE', headers }
      );
      if (!res.ok) throw new Error('Remove failed');
      setRemovingId(null);
      fetchVolunteers();
      fetchCompliance();
    } catch (err) {
      console.error('Error removing volunteer:', err);
      alert('Failed to remove volunteer. Please try again.');
    }
  };

  const handleAddSubmit = async () => {
    if (!addForm.team_id) return;
    setAddSaving(true);
    try {
      let res: Response;
      if (addMode === 'existing') {
        if (!selectedUser) return;
        res = await fetch(
          `${API_URL}/api/volunteer-gateway.php?action=assign-volunteer`,
          {
            method: 'POST',
            headers,
            body: JSON.stringify({
              user_id: selectedUser.id,
              team_id: Number(addForm.team_id),
              start_date: addForm.start_date,
              end_date: addForm.end_date || null,
              notes: addForm.notes,
            }),
          }
        );
      } else {
        if (!newVolunteerForm.first_name || !newVolunteerForm.last_name || !newVolunteerForm.email) return;
        res = await fetch(
          `${API_URL}/api/volunteer-gateway.php?action=create-volunteer`,
          {
            method: 'POST',
            headers,
            body: JSON.stringify({
              team_id: Number(addForm.team_id),
              first_name: newVolunteerForm.first_name,
              last_name: newVolunteerForm.last_name,
              email: newVolunteerForm.email,
              phone: newVolunteerForm.phone || null,
              start_date: addForm.start_date,
              end_date: addForm.end_date || null,
              notes: addForm.notes,
            }),
          }
        );
      }
      const data = await res.json();
      if (!res.ok) {
        alert(data.error || 'Failed to add volunteer');
        return;
      }
      setShowAddModal(false);
      resetAddForm();
      fetchVolunteers();
      fetchCompliance();
    } catch (err) {
      console.error('Error adding volunteer:', err);
      alert('Failed to add volunteer. Please try again.');
    } finally {
      setAddSaving(false);
    }
  };

  const resetAddForm = () => {
    setAddMode('new');
    setSelectedUser(null);
    setUserSearch('');
    setUserResults([]);
    setNewVolunteerForm({ first_name: '', last_name: '', email: '', phone: '' });
    setAddForm({
      team_id: '',
      start_date: new Date().toISOString().split('T')[0],
      end_date: '',
      notes: '',
    });
  };

  if (!currentClubId) {
    return (
      <div className="p-6 text-center text-brand-primary">
        Please select a club to manage volunteers.
      </div>
    );
  }

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">Volunteer Management</h1>
          <p className="text-sm text-brand-primary mt-1">
            Manage volunteers, track background checks, and monitor compliance.
          </p>
        </div>
        <button
          onClick={() => {
            resetAddForm();
            setShowAddModal(true);
          }}
          className="inline-flex items-center px-4 py-2 bg-brand-primary text-white text-sm font-semibold uppercase rounded-md hover:opacity-90 transition-colors"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Add Volunteer
        </button>
      </div>

      {/* Metrics Cards */}
      {compliance && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <div className="bg-white rounded-lg border border-green-200 p-5">
            <p className="text-sm font-medium text-green-600">Total Active Volunteers</p>
            <p className="text-3xl font-bold text-green-700 mt-1">{compliance.active_count}</p>
          </div>
          <div
            className="bg-white rounded-lg border border-amber-200 p-5 cursor-pointer hover:shadow-md transition-shadow"
            onClick={() => navigate('/volunteers/requests')}
          >
            <p className="text-sm font-medium text-amber-600">Pending Signups</p>
            <p className="text-3xl font-bold text-amber-700 mt-1">{compliance.pending_signups}</p>
            <p className="text-xs text-amber-500 mt-1">Click to review</p>
          </div>
          <div className="bg-white rounded-lg border border-green-200 p-5">
            <p className="text-sm font-medium text-green-600">BG Checks Cleared</p>
            <p className="text-3xl font-bold text-green-700 mt-1">{compliance.cleared}</p>
          </div>
          <div className="bg-white rounded-lg border border-red-200 p-5">
            <p className="text-sm font-medium text-red-600">BG Checks Expired / Pending</p>
            <p className="text-3xl font-bold text-red-700 mt-1">
              {compliance.expired + compliance.pending}
            </p>
          </div>
        </div>
      )}

      {/* Per-Team Compliance */}
      {isClubAdmin && teamCompliance.length > 0 && (
        <div className="bg-white rounded-lg border border-brand-secondary overflow-hidden mb-6">
          <div className="px-4 py-3 border-b border-gray-200">
            <h2 className="text-sm font-semibold text-brand-primary uppercase tracking-wide">
              Per-Team Compliance
            </h2>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">Team</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">Volunteers</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">Cleared</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">Pending</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">Expired</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">Compliance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {teamCompliance.map((tc) => {
                  const rate = Number(tc.compliance_rate);
                  const rateClass =
                    rate >= 100 ? 'text-green-700' : rate >= 75 ? 'text-amber-600' : 'text-red-600';
                  return (
                    <tr key={tc.team_id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm font-medium text-brand-primary whitespace-nowrap">
                        {tc.team_name}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600">{tc.volunteer_count}</td>
                      <td className="px-4 py-3 text-sm text-green-700">{tc.cleared}</td>
                      <td className="px-4 py-3 text-sm text-amber-600">{tc.pending_bg}</td>
                      <td className="px-4 py-3 text-sm text-red-600">{tc.expired_bg}</td>
                      <td className={`px-4 py-3 text-sm font-semibold ${rateClass}`}>{rate}%</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Filters */}
      <div className="bg-white rounded-lg border border-brand-secondary p-4 mb-6">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">Team</label>
            <select
              value={filterTeam}
              onChange={(e) => setFilterTeam(e.target.value)}
              className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
            >
              <option value="all">All Teams</option>
              {teams.map((t) => (
                <option key={t.id} value={String(t.id)}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">BG Check Status</label>
            <select
              value={filterBgStatus}
              onChange={(e) => setFilterBgStatus(e.target.value)}
              className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
            >
              <option value="all">All Statuses</option>
              <option value="cleared">Cleared</option>
              <option value="pending">Pending</option>
              <option value="expired">Expired</option>
              <option value="never_checked">Never Checked</option>
            </select>
          </div>
          <div>
            <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">Status</label>
            <div className="flex items-center gap-1 bg-gray-100 rounded-md p-1">
              {(['active', 'inactive', 'all'] as const).map((s) => (
                <button
                  key={s}
                  onClick={() => setFilterActive(s)}
                  className={`flex-1 px-3 py-1.5 text-sm font-medium rounded transition-colors ${
                    filterActive === s
                      ? 'bg-white text-brand-primary shadow-sm'
                      : 'text-gray-500 hover:text-brand-primary'
                  }`}
                >
                  {s.charAt(0).toUpperCase() + s.slice(1)}
                </button>
              ))}
            </div>
          </div>
          <div>
            <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">Search</label>
            <input
              type="text"
              placeholder="Name, email, or phone..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
            />
          </div>
        </div>
      </div>

      {/* Volunteer Table */}
      <div className="bg-white rounded-lg border border-brand-secondary overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-brand-primary">Loading volunteers...</div>
        ) : filteredVolunteers.length === 0 ? (
          <div className="p-12 text-center text-brand-primary">
            {volunteers.length === 0
              ? 'No volunteers found. Click "Add Volunteer" to get started.'
              : 'No volunteers match your current filters.'}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">
                    Name
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">
                    Email
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">
                    Phone
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">
                    Team
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">
                    BG Check
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">
                    Start Date
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-brand-primary uppercase tracking-wide">
                    Status
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-brand-primary uppercase tracking-wide">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {filteredVolunteers.map((vol) => (
                  <tr key={vol.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-medium text-brand-primary whitespace-nowrap">
                      {vol.first_name} {vol.last_name}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {vol.email}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {vol.phone || '--'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {vol.team_name}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span
                        className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          BG_BADGE_STYLES[vol.bg_check_status] || BG_BADGE_STYLES.never_checked
                        }`}
                      >
                        {vol.bg_check_status === 'never_checked'
                          ? 'Not Checked'
                          : vol.bg_check_status.charAt(0).toUpperCase() + vol.bg_check_status.slice(1)}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {formatDate(vol.start_date)}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span
                        className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          STATUS_BADGE_STYLES[vol.status] || STATUS_BADGE_STYLES.inactive
                        }`}
                      >
                        {vol.status.charAt(0).toUpperCase() + vol.status.slice(1)}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right whitespace-nowrap">
                      <button
                        onClick={() => handleEdit(vol)}
                        className="text-brand-primary hover:underline text-sm font-medium mr-3"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => setRemovingId(vol.id)}
                        className="text-red-600 hover:text-red-800 text-sm font-medium"
                      >
                        Remove
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <LoadMore
              page={page}
              loading={loadingMore}
              shown={volunteers.length}
              label="volunteers"
              onLoadMore={loadMoreVolunteers}
            />
          </div>
        )}
      </div>

      {/* Edit Modal */}
      {editingVolunteer && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
            <h2 className="text-lg font-semibold text-brand-primary mb-4">
              Edit Volunteer: {editingVolunteer.first_name} {editingVolunteer.last_name}
            </h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm text-brand-primary mb-1">Status</label>
                <select
                  value={editForm.status}
                  onChange={(e) => setEditForm({ ...editForm, status: e.target.value })}
                  className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div>
                <label className="block text-sm text-brand-primary mb-1">
                  BG Check Status
                </label>
                <select
                  value={editForm.bg_check_status}
                  onChange={(e) =>
                    setEditForm({ ...editForm, bg_check_status: e.target.value })
                  }
                  className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                >
                  <option value="cleared">Cleared</option>
                  <option value="pending">Pending</option>
                  <option value="expired">Expired</option>
                  <option value="never_checked">Never Checked</option>
                </select>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm text-brand-primary mb-1">
                    Start Date
                  </label>
                  <input
                    type="date"
                    value={editForm.start_date}
                    onChange={(e) => setEditForm({ ...editForm, start_date: e.target.value })}
                    className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                  />
                </div>
                <div>
                  <label className="block text-sm text-brand-primary mb-1">End Date</label>
                  <input
                    type="date"
                    value={editForm.end_date}
                    onChange={(e) => setEditForm({ ...editForm, end_date: e.target.value })}
                    className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm text-brand-primary mb-1">Notes</label>
                <textarea
                  value={editForm.notes}
                  onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
                  rows={3}
                  className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setEditingVolunteer(null)}
                className="px-4 py-2 text-sm font-semibold uppercase bg-white text-brand-primary border border-brand-secondary rounded-md hover:bg-gray-100 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleEditSave}
                disabled={editSaving}
                className="px-4 py-2 text-sm font-semibold uppercase text-white bg-brand-primary rounded-md hover:opacity-90 disabled:opacity-50 transition-colors"
              >
                {editSaving ? 'Saving...' : 'Save Changes'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Remove Confirmation Modal */}
      {removingId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-sm mx-4 p-6">
            <h2 className="text-lg font-semibold text-brand-primary mb-2">Remove Volunteer</h2>
            <p className="text-sm text-gray-600 mb-6">
              Are you sure you want to remove this volunteer? This action cannot be undone.
            </p>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => setRemovingId(null)}
                className="px-4 py-2 text-sm font-semibold uppercase bg-white text-brand-primary border border-brand-secondary rounded-md hover:bg-gray-100 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={() => handleRemove(removingId)}
                className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors"
              >
                Remove
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Add Volunteer Modal */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 p-6">
            <h2 className="text-lg font-semibold text-brand-primary uppercase tracking-wide mb-4">Add Volunteer</h2>

            {/* Mode Toggle */}
            <div className="flex rounded-md border border-brand-secondary mb-4 overflow-hidden">
              <button
                onClick={() => setAddMode('new')}
                className={`flex-1 px-3 py-2 text-sm font-semibold uppercase ${
                  addMode === 'new'
                    ? 'bg-brand-primary text-white'
                    : 'bg-white text-brand-primary hover:bg-gray-50'
                }`}
              >
                New Person
              </button>
              <button
                onClick={() => setAddMode('existing')}
                className={`flex-1 px-3 py-2 text-sm font-semibold uppercase ${
                  addMode === 'existing'
                    ? 'bg-brand-primary text-white'
                    : 'bg-white text-brand-primary hover:bg-gray-50'
                }`}
              >
                Existing User
              </button>
            </div>

            <div className="space-y-4">
              {addMode === 'new' ? (
                <div className="space-y-3">
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">First Name *</label>
                      <input
                        type="text"
                        value={newVolunteerForm.first_name}
                        onChange={(e) => setNewVolunteerForm({ ...newVolunteerForm, first_name: e.target.value })}
                        className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                        placeholder="First name"
                      />
                    </div>
                    <div>
                      <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">Last Name *</label>
                      <input
                        type="text"
                        value={newVolunteerForm.last_name}
                        onChange={(e) => setNewVolunteerForm({ ...newVolunteerForm, last_name: e.target.value })}
                        className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                        placeholder="Last name"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">Email *</label>
                    <input
                      type="email"
                      value={newVolunteerForm.email}
                      onChange={(e) => setNewVolunteerForm({ ...newVolunteerForm, email: e.target.value })}
                      className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                      placeholder="email@example.com"
                    />
                  </div>
                  <div>
                    <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">Phone</label>
                    <input
                      type="tel"
                      value={newVolunteerForm.phone}
                      onChange={(e) => setNewVolunteerForm({ ...newVolunteerForm, phone: e.target.value })}
                      className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                      placeholder="(555) 123-4567"
                    />
                  </div>
                  <p className="text-xs text-gray-500">Background check will start as pending. Update status once cleared.</p>
                </div>
              ) : (
                <div>
                <label className="block text-xs text-brand-primary uppercase tracking-wide mb-1">
                  Search User
                </label>
                {selectedUser ? (
                  <div className="flex items-center justify-between bg-gray-50 border border-brand-secondary rounded-md px-3 py-2">
                    <div>
                      <p className="text-sm font-medium text-brand-primary">
                        {selectedUser.first_name} {selectedUser.last_name}
                      </p>
                      <p className="text-xs text-gray-500">{selectedUser.email}</p>
                    </div>
                    <button
                      onClick={() => {
                        setSelectedUser(null);
                        setUserSearch('');
                      }}
                      className="text-gray-400 hover:text-gray-600"
                    >
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>
                ) : (
                  <div className="relative">
                    <input
                      type="text"
                      placeholder="Type a name or email to search..."
                      value={userSearch}
                      onChange={(e) => setUserSearch(e.target.value)}
                      className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                    />
                    {searchingUsers && (
                      <div className="absolute right-3 top-2.5 text-xs text-gray-400">
                        Searching...
                      </div>
                    )}
                    {userResults.length > 0 && (
                      <div className="absolute z-10 w-full mt-1 bg-white border border-brand-secondary rounded-md shadow-lg max-h-48 overflow-y-auto">
                        {userResults.map((u) => (
                          <button
                            key={u.id}
                            onClick={() => {
                              setSelectedUser(u);
                              setUserResults([]);
                              setUserSearch('');
                            }}
                            className="w-full text-left px-3 py-2 hover:bg-gray-50 border-b border-gray-100 last:border-0"
                          >
                            <p className="text-sm font-medium text-brand-primary">
                              {u.first_name} {u.last_name}
                            </p>
                            <p className="text-xs text-gray-500">{u.email}</p>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                )}

                {/* BG Check Status Display */}
                {selectedUser && (
                  <div className="mt-3">
                    <label className="block text-sm text-brand-primary mb-1">
                      Background Check Status
                    </label>
                    <span
                      className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                        BG_BADGE_STYLES[selectedUser.bg_check_status] || BG_BADGE_STYLES.never_checked
                      }`}
                    >
                      {selectedUser.bg_check_status === 'never_checked'
                        ? 'Not Checked'
                        : selectedUser.bg_check_status.charAt(0).toUpperCase() +
                          selectedUser.bg_check_status.slice(1)}
                    </span>
                    {selectedUser.bg_check_status !== 'cleared' && (
                      <p className="text-xs text-amber-600 mt-1">
                        Background check status: {selectedUser.bg_check_status}. You can update this after assigning.
                      </p>
                    )}
                  </div>
                )}
              </div>
              )}

              {/* Team */}
              <div>
                <label className="block text-sm text-brand-primary mb-1">Team</label>
                <select
                  value={addForm.team_id}
                  onChange={(e) => setAddForm({ ...addForm, team_id: e.target.value })}
                  className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                >
                  <option value="">Select a team</option>
                  {teams.map((t) => (
                    <option key={t.id} value={String(t.id)}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </div>

              {/* Dates */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm text-brand-primary mb-1">
                    Start Date
                  </label>
                  <input
                    type="date"
                    value={addForm.start_date}
                    onChange={(e) => setAddForm({ ...addForm, start_date: e.target.value })}
                    className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                  />
                </div>
                <div>
                  <label className="block text-sm text-brand-primary mb-1">
                    End Date (optional)
                  </label>
                  <input
                    type="date"
                    value={addForm.end_date}
                    onChange={(e) => setAddForm({ ...addForm, end_date: e.target.value })}
                    className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                  />
                </div>
              </div>

              {/* Notes */}
              <div>
                <label className="block text-sm text-brand-primary mb-1">Notes</label>
                <textarea
                  value={addForm.notes}
                  onChange={(e) => setAddForm({ ...addForm, notes: e.target.value })}
                  rows={3}
                  placeholder="Optional notes about this volunteer assignment..."
                  className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:outline-none focus:border-brand-accent"
                />
              </div>
            </div>

            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => {
                  setShowAddModal(false);
                  resetAddForm();
                }}
                className="px-4 py-2 text-sm font-semibold uppercase bg-white text-brand-primary border border-brand-secondary rounded-md hover:bg-gray-100 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddSubmit}
                disabled={
                  addSaving ||
                  !addForm.team_id ||
                  (addMode === 'existing' && !selectedUser) ||
                  (addMode === 'new' && (!newVolunteerForm.first_name || !newVolunteerForm.last_name || !newVolunteerForm.email))
                }
                className="px-4 py-2 text-sm font-semibold uppercase text-white bg-brand-primary rounded-md hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                {addSaving ? 'Adding...' : 'Add Volunteer'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default VolunteerManagement;

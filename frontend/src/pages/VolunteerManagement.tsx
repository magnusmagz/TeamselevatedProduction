import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

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
  const { user } = useAuth();
  const { currentClubId, isClubAdmin } = useOrg();
  const navigate = useNavigate();
  const token = localStorage.getItem('auth_token');

  // Data
  const [volunteers, setVolunteers] = useState<Volunteer[]>([]);
  const [compliance, setCompliance] = useState<ComplianceSummary | null>(null);
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
  const [userSearch, setUserSearch] = useState('');
  const [userResults, setUserResults] = useState<UserSearchResult[]>([]);
  const [selectedUser, setSelectedUser] = useState<UserSearchResult | null>(null);
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

  const headers = {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`,
  };

  const fetchVolunteers = useCallback(async () => {
    if (!currentClubId) return;
    setLoading(true);
    try {
      const action = isClubAdmin ? 'club-volunteers' : 'team-volunteers';
      const idParam = isClubAdmin ? `club_id=${currentClubId}` : `team_id=${currentClubId}`;
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=${action}&${idParam}`,
        { headers }
      );
      const data = await res.json();
      setVolunteers(Array.isArray(data) ? data : data.volunteers || []);

      // Extract unique teams from volunteer data
      const teamMap = new Map<number, string>();
      (Array.isArray(data) ? data : data.volunteers || []).forEach((v: Volunteer) => {
        if (v.team_id && v.team_name) teamMap.set(v.team_id, v.team_name);
      });
      setTeams(Array.from(teamMap.entries()).map(([id, name]) => ({ id, name })));
    } catch (err) {
      console.error('Error fetching volunteers:', err);
    } finally {
      setLoading(false);
    }
  }, [currentClubId, isClubAdmin]);

  const fetchCompliance = useCallback(async () => {
    if (!currentClubId) return;
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=compliance&club_id=${currentClubId}`,
        { headers }
      );
      const data = await res.json();
      setCompliance(data.summary || null);
    } catch (err) {
      console.error('Error fetching compliance:', err);
    }
  }, [currentClubId]);

  useEffect(() => {
    fetchVolunteers();
    fetchCompliance();
  }, [fetchVolunteers, fetchCompliance]);

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
        setUserResults(Array.isArray(data) ? data : data.users || []);
      } catch (err) {
        console.error('Error searching users:', err);
      } finally {
        setSearchingUsers(false);
      }
    }, 300);
    return () => clearTimeout(timeout);
  }, [userSearch, currentClubId]);

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
          body: JSON.stringify(editForm),
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
    if (!selectedUser || !addForm.team_id) return;
    setAddSaving(true);
    try {
      const res = await fetch(
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
      if (!res.ok) throw new Error('Assign failed');
      setShowAddModal(false);
      resetAddForm();
      fetchVolunteers();
      fetchCompliance();
    } catch (err) {
      console.error('Error assigning volunteer:', err);
      alert('Failed to add volunteer. Please try again.');
    } finally {
      setAddSaving(false);
    }
  };

  const resetAddForm = () => {
    setSelectedUser(null);
    setUserSearch('');
    setUserResults([]);
    setAddForm({
      team_id: '',
      start_date: new Date().toISOString().split('T')[0],
      end_date: '',
      notes: '',
    });
  };

  if (!currentClubId) {
    return (
      <div className="p-6 text-center text-gray-500">
        Please select a club to manage volunteers.
      </div>
    );
  }

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Volunteer Management</h1>
          <p className="text-sm text-gray-500 mt-1">
            Manage volunteers, track background checks, and monitor compliance.
          </p>
        </div>
        <button
          onClick={() => {
            resetAddForm();
            setShowAddModal(true);
          }}
          className="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
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

      {/* Filters */}
      <div className="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Team</label>
            <select
              value={filterTeam}
              onChange={(e) => setFilterTeam(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
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
            <label className="block text-xs font-medium text-gray-500 mb-1">BG Check Status</label>
            <select
              value={filterBgStatus}
              onChange={(e) => setFilterBgStatus(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
            >
              <option value="all">All Statuses</option>
              <option value="cleared">Cleared</option>
              <option value="pending">Pending</option>
              <option value="expired">Expired</option>
              <option value="never_checked">Never Checked</option>
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Status</label>
            <div className="flex items-center gap-1 bg-gray-100 rounded-md p-1">
              {(['active', 'inactive', 'all'] as const).map((s) => (
                <button
                  key={s}
                  onClick={() => setFilterActive(s)}
                  className={`flex-1 px-3 py-1.5 text-sm font-medium rounded transition-colors ${
                    filterActive === s
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-500 hover:text-gray-700'
                  }`}
                >
                  {s.charAt(0).toUpperCase() + s.slice(1)}
                </button>
              ))}
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <input
              type="text"
              placeholder="Name, email, or phone..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
        </div>
      </div>

      {/* Volunteer Table */}
      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-gray-500">Loading volunteers...</div>
        ) : filteredVolunteers.length === 0 ? (
          <div className="p-12 text-center text-gray-500">
            {volunteers.length === 0
              ? 'No volunteers found. Click "Add Volunteer" to get started.'
              : 'No volunteers match your current filters.'}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Name
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Email
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Phone
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Team
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    BG Check
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Start Date
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {filteredVolunteers.map((vol) => (
                  <tr key={vol.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">
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
                        className="text-blue-600 hover:text-blue-800 text-sm font-medium mr-3"
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
          </div>
        )}
      </div>

      {/* Edit Modal */}
      {editingVolunteer && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">
              Edit Volunteer: {editingVolunteer.first_name} {editingVolunteer.last_name}
            </h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select
                  value={editForm.status}
                  onChange={(e) => setEditForm({ ...editForm, status: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  BG Check Status
                </label>
                <select
                  value={editForm.bg_check_status}
                  onChange={(e) =>
                    setEditForm({ ...editForm, bg_check_status: e.target.value })
                  }
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                >
                  <option value="cleared">Cleared</option>
                  <option value="pending">Pending</option>
                  <option value="expired">Expired</option>
                  <option value="never_checked">Never Checked</option>
                </select>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Start Date
                  </label>
                  <input
                    type="date"
                    value={editForm.start_date}
                    onChange={(e) => setEditForm({ ...editForm, start_date: e.target.value })}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                  <input
                    type="date"
                    value={editForm.end_date}
                    onChange={(e) => setEditForm({ ...editForm, end_date: e.target.value })}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea
                  value={editForm.notes}
                  onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
                  rows={3}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setEditingVolunteer(null)}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleEditSave}
                disabled={editSaving}
                className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 transition-colors"
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
            <h2 className="text-lg font-semibold text-gray-900 mb-2">Remove Volunteer</h2>
            <p className="text-sm text-gray-600 mb-6">
              Are you sure you want to remove this volunteer? This action cannot be undone.
            </p>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => setRemovingId(null)}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
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
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Add Volunteer</h2>
            <div className="space-y-4">
              {/* User Search */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Search User
                </label>
                {selectedUser ? (
                  <div className="flex items-center justify-between bg-blue-50 border border-blue-200 rounded-md px-3 py-2">
                    <div>
                      <p className="text-sm font-medium text-gray-900">
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
                        <path
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          strokeWidth={2}
                          d="M6 18L18 6M6 6l12 12"
                        />
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
                      className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                    />
                    {searchingUsers && (
                      <div className="absolute right-3 top-2.5 text-xs text-gray-400">
                        Searching...
                      </div>
                    )}
                    {userResults.length > 0 && (
                      <div className="absolute z-10 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
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
                            <p className="text-sm font-medium text-gray-900">
                              {u.first_name} {u.last_name}
                            </p>
                            <p className="text-xs text-gray-500">{u.email}</p>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* BG Check Status Display */}
              {selectedUser && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
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
                    <p className="text-xs text-red-600 mt-1">
                      Background check must be cleared before this volunteer can be assigned.
                    </p>
                  )}
                </div>
              )}

              {/* Team */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Team</label>
                <select
                  value={addForm.team_id}
                  onChange={(e) => setAddForm({ ...addForm, team_id: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
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
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Start Date
                  </label>
                  <input
                    type="date"
                    value={addForm.start_date}
                    onChange={(e) => setAddForm({ ...addForm, start_date: e.target.value })}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    End Date (optional)
                  </label>
                  <input
                    type="date"
                    value={addForm.end_date}
                    onChange={(e) => setAddForm({ ...addForm, end_date: e.target.value })}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
              </div>

              {/* Notes */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea
                  value={addForm.notes}
                  onChange={(e) => setAddForm({ ...addForm, notes: e.target.value })}
                  rows={3}
                  placeholder="Optional notes about this volunteer assignment..."
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
            </div>

            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => {
                  setShowAddModal(false);
                  resetAddForm();
                }}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddSubmit}
                disabled={
                  addSaving ||
                  !selectedUser ||
                  !addForm.team_id ||
                  selectedUser.bg_check_status !== 'cleared'
                }
                className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
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

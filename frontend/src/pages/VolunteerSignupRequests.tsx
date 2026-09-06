import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../contexts/OrgContext';
import PageHeader from '../components/ui/PageHeader';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Signup {
  id: number;
  team_id: number;
  user_id: number;
  requested_at: string;
  status: string;
  notes: string | null;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  team_name: string;
  background_check_status: 'cleared' | 'pending' | 'expired' | 'none' | null;
}

interface Team {
  id: number;
  name: string;
}

type StatusFilter = 'pending' | 'approved' | 'rejected' | 'all';
type BgFilter = 'all' | 'cleared' | 'pending' | 'expired' | 'none';

const BG_CHECK_STYLES: Record<string, { bg: string; text: string; label: string }> = {
  cleared: { bg: 'bg-green-100', text: 'text-green-800', label: 'Cleared' },
  pending: { bg: 'bg-yellow-100', text: 'text-yellow-800', label: 'Pending' },
  expired: { bg: 'bg-red-100', text: 'text-red-800', label: 'Expired' },
  none: { bg: 'bg-gray-100', text: 'text-gray-600', label: 'None' },
};

export const VolunteerSignupRequests: React.FC = () => {
  const { isClubAdmin } = useOrg();

  const [teams, setTeams] = useState<Team[]>([]);
  const [selectedTeamId, setSelectedTeamId] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('pending');
  const [bgFilter, setBgFilter] = useState<BgFilter>('all');
  const [signups, setSignups] = useState<Signup[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Rejection UI state
  const [rejectingId, setRejectingId] = useState<number | null>(null);
  const [rejectNotes, setRejectNotes] = useState('');
  const [actionLoading, setActionLoading] = useState<number | null>(null);

  // Bulk selection state
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [bulkLoading, setBulkLoading] = useState(false);

  const token = localStorage.getItem('auth_token');

  // Fetch teams
  useEffect(() => {
    if (!token) return;

    const fetchTeams = async () => {
      try {
        const endpoint = isClubAdmin
          ? `${API_URL}/api/teams`
          : `${API_URL}/api/coach/teams`;
        const res = await fetch(endpoint, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const data = await res.json();
        // /api/teams returns a bare array; /api/coach/teams may return either an
        // array or { teams: [...] }. Normalize both so the admin "all teams" view
        // actually loads (a bare array previously yielded [] for admins, which left
        // the page stuck on its loading guard and the team filter empty).
        const teamList: Team[] = Array.isArray(data) ? data : (data.teams || []);
        setTeams(teamList);
        // Auto-select first team if coach has exactly one
        if (!isClubAdmin && teamList.length === 1) {
          setSelectedTeamId(String(teamList[0].id));
        }
      } catch {
        setError('Failed to load teams.');
      }
    };

    fetchTeams();
  }, [token, isClubAdmin]);

  // Fetch signups
  const fetchSignups = useCallback(async () => {
    if (!token) return;
    // Coaches must have a team selected
    if (!isClubAdmin && !selectedTeamId) {
      setSignups([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);
    // Clear any stale row selection when the result set changes.
    setSelectedIds(new Set());

    const bgParam = bgFilter !== 'all' ? `&bg_status=${bgFilter}` : '';

    try {
      if (isClubAdmin && !selectedTeamId) {
        // Fetch for all teams
        const allSignups: Signup[] = [];
        await Promise.all(
          teams.map(async (team) => {
            const url = `${API_URL}/api/volunteer-gateway.php?action=team-signups&team_id=${team.id}&status=${statusFilter}${bgParam}`;
            const res = await fetch(url, {
              headers: { Authorization: `Bearer ${token}` },
            });
            const data = await res.json();
            if (data.success && data.signups) {
              allSignups.push(...data.signups);
            }
          })
        );
        // Sort by requested_at descending
        allSignups.sort((a, b) => new Date(b.requested_at).getTime() - new Date(a.requested_at).getTime());
        setSignups(allSignups);
      } else {
        const url = `${API_URL}/api/volunteer-gateway.php?action=team-signups&team_id=${selectedTeamId}&status=${statusFilter}${bgParam}`;
        const res = await fetch(url, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const data = await res.json();
        if (data.success) {
          setSignups(data.signups || []);
        } else {
          setError(data.error || 'Failed to load signup requests.');
        }
      }
    } catch {
      setError('Failed to load signup requests.');
    } finally {
      setLoading(false);
    }
  }, [token, isClubAdmin, selectedTeamId, statusFilter, bgFilter, teams]);

  useEffect(() => {
    // Wait until teams are loaded for admin "all teams" fetch
    if (isClubAdmin && teams.length === 0) return;
    fetchSignups();
  }, [fetchSignups, isClubAdmin, teams.length]);

  const handleApprove = async (signupId: number) => {
    if (!token) return;
    setActionLoading(signupId);
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=review-signup&signup_id=${signupId}`,
        {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ decision: 'approved' }),
        }
      );
      const data = await res.json();
      if (data.success) {
        fetchSignups();
      } else {
        setError(data.error || 'Failed to approve request.');
      }
    } catch {
      setError('Failed to approve request.');
    } finally {
      setActionLoading(null);
    }
  };

  const handleReject = async (signupId: number) => {
    if (!token) return;
    setActionLoading(signupId);
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=review-signup&signup_id=${signupId}`,
        {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ decision: 'rejected', notes: rejectNotes || undefined }),
        }
      );
      const data = await res.json();
      if (data.success) {
        setRejectingId(null);
        setRejectNotes('');
        fetchSignups();
      } else {
        setError(data.error || 'Failed to reject request.');
      }
    } catch {
      setError('Failed to reject request.');
    } finally {
      setActionLoading(null);
    }
  };

  // Only pending rows are selectable / actionable in bulk.
  const pendingSignups = signups.filter((s) => s.status === 'pending');
  // Approvals require a cleared background check (the backend enforces this too).
  const selectablePending = pendingSignups;

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleSelectAll = () => {
    setSelectedIds((prev) => {
      if (prev.size === selectablePending.length && selectablePending.length > 0) {
        return new Set();
      }
      return new Set(selectablePending.map((s) => s.id));
    });
  };

  const handleBulk = async (decision: 'approved' | 'rejected') => {
    if (!token || selectedIds.size === 0) return;
    setBulkLoading(true);
    setError(null);
    try {
      const res = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=review-signups-bulk`,
        {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({ decision, signup_ids: Array.from(selectedIds) }),
        }
      );
      const data = await res.json();
      if (data.success) {
        if (Array.isArray(data.skipped) && data.skipped.length > 0) {
          const blocked = data.skipped.filter(
            (s: any) => s.reason === 'background_check_not_cleared'
          ).length;
          setError(
            blocked > 0
              ? `${blocked} request(s) skipped: background check not cleared.`
              : `${data.skipped.length} request(s) could not be processed.`
          );
        }
        setSelectedIds(new Set());
        fetchSignups();
      } else {
        setError(data.error || 'Bulk action failed.');
      }
    } catch {
      setError('Bulk action failed.');
    } finally {
      setBulkLoading(false);
    }
  };

  const formatDate = (dateStr: string) => {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    });
  };

  const getBgCheckBadge = (status: string | null) => {
    const key = status || 'none';
    const style = BG_CHECK_STYLES[key] || BG_CHECK_STYLES.none;
    return (
      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${style.bg} ${style.text}`}>
        {style.label}
      </span>
    );
  };

  const pendingCount = signups.length;

  const signupColumns: DataTableColumn<Signup>[] = [
    {
      key: 'select',
      header: (
        <input
          type="checkbox"
          aria-label="Select all pending requests"
          checked={selectablePending.length > 0 && selectedIds.size === selectablePending.length}
          onChange={toggleSelectAll}
          disabled={selectablePending.length === 0}
          className="h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-accent"
        />
      ),
      className: 'whitespace-nowrap',
      render: (signup) =>
        signup.status === 'pending' ? (
          <input
            type="checkbox"
            aria-label={`Select ${signup.first_name} ${signup.last_name}`}
            checked={selectedIds.has(signup.id)}
            onChange={() => toggleSelect(signup.id)}
            className="h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-accent"
          />
        ) : null,
    },
    {
      key: 'name',
      header: 'Name',
      className: 'whitespace-nowrap',
      render: (signup) => <span className="font-medium text-brand-primary">{signup.first_name} {signup.last_name}</span>,
    },
    {
      key: 'email',
      header: 'Email',
      className: 'whitespace-nowrap',
      render: (signup) => <span className="text-gray-500">{signup.email}</span>,
    },
    {
      key: 'phone',
      header: 'Phone',
      className: 'whitespace-nowrap',
      render: (signup) => <span className="text-gray-500">{signup.phone || '--'}</span>,
    },
    {
      key: 'team_name',
      header: 'Team',
      className: 'whitespace-nowrap',
      render: (signup) => <span className="text-gray-500">{signup.team_name}</span>,
    },
    {
      key: 'requested_at',
      header: 'Requested',
      className: 'whitespace-nowrap',
      render: (signup) => <span className="text-gray-500">{formatDate(signup.requested_at)}</span>,
    },
    {
      key: 'notes',
      header: 'Notes',
      className: 'max-w-xs truncate',
      render: (signup) => <span className="text-gray-500">{signup.notes || '--'}</span>,
    },
    {
      key: 'background_check_status',
      header: 'BG Check',
      className: 'whitespace-nowrap',
      render: (signup) => getBgCheckBadge(signup.background_check_status),
    },
    {
      key: 'actions',
      header: 'Actions',
      actions: true,
      render: (signup) => {
        const bgCleared = signup.background_check_status === 'cleared';
        const isRejecting = rejectingId === signup.id;
        const isActioning = actionLoading === signup.id;
        return signup.status === 'pending' ? (
          <div className="flex items-center justify-end gap-2">
            <div className="relative group">
              <button
                onClick={() => handleApprove(signup.id)}
                disabled={!bgCleared || isActioning}
                className={`inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold uppercase transition-colors ${
                  bgCleared
                    ? 'bg-brand-primary text-white hover:opacity-90'
                    : 'bg-gray-200 text-gray-400 cursor-not-allowed'
                }`}
              >
                {isActioning ? 'Processing...' : 'Approve'}
              </button>
              {!bgCleared && (
                <div className="absolute bottom-full right-0 mb-1 hidden group-hover:block z-10">
                  <div className="bg-gray-900 text-white text-xs rounded py-1 px-2 whitespace-nowrap">
                    Background check not cleared
                  </div>
                </div>
              )}
            </div>
            <button
              onClick={() => {
                setRejectingId(isRejecting ? null : signup.id);
                setRejectNotes('');
              }}
              disabled={isActioning}
              className="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold uppercase bg-red-600 text-white hover:bg-red-700 transition-colors disabled:opacity-50"
            >
              Reject
            </button>
          </div>
        ) : (
          <span
            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
              signup.status === 'approved'
                ? 'bg-green-100 text-green-800'
                : 'bg-red-100 text-red-800'
            }`}
          >
            {signup.status.charAt(0).toUpperCase() + signup.status.slice(1)}
          </span>
        );
      },
    },
  ];

  const STATUS_TABS: { key: StatusFilter; label: string }[] = [
    { key: 'pending', label: 'Pending' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'all', label: 'All' },
  ];

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title="Volunteer Signup Requests"
        meta={
          !loading ? (
            <span className="inline-flex items-center justify-center px-2.5 py-0.5 rounded-full text-sm font-semibold bg-brand-primary/10 text-brand-primary">
              {pendingCount}
            </span>
          ) : undefined
        }
      />

      {/* Filters row */}
      <div className="flex flex-col sm:flex-row sm:items-center gap-4 mb-6">
        {/* Team filter */}
        <div>
          <label htmlFor="team-filter" className="block text-sm font-medium text-gray-700 mb-1">
            Team
          </label>
          <select
            id="team-filter"
            value={selectedTeamId}
            onChange={(e) => setSelectedTeamId(e.target.value)}
            className="block w-full sm:w-64 rounded-md border border-brand-secondary text-brand-primary shadow-sm focus:outline-none focus:border-brand-accent text-sm"
          >
            {isClubAdmin && <option value="">All Teams</option>}
            {!isClubAdmin && !selectedTeamId && <option value="">Select a team</option>}
            {teams.map((team) => (
              <option key={team.id} value={String(team.id)}>
                {team.name}
              </option>
            ))}
          </select>
        </div>

        {/* Background-check filter */}
        <div>
          <label htmlFor="bg-filter" className="block text-sm font-medium text-gray-700 mb-1">
            Background Check
          </label>
          <select
            id="bg-filter"
            value={bgFilter}
            onChange={(e) => setBgFilter(e.target.value as BgFilter)}
            className="block w-full sm:w-48 rounded-md border border-brand-secondary text-brand-primary shadow-sm focus:outline-none focus:border-brand-accent text-sm"
          >
            <option value="all">All</option>
            <option value="cleared">Cleared</option>
            <option value="pending">Pending</option>
            <option value="expired">Expired</option>
            <option value="none">None</option>
          </select>
        </div>

        {/* Status tabs */}
        <div className="sm:ml-auto">
          <nav className="flex space-x-1 rounded-lg bg-gray-100 p-1" aria-label="Status filter">
            {STATUS_TABS.map((tab) => (
              <button
                key={tab.key}
                onClick={() => setStatusFilter(tab.key)}
                className={`px-3 py-1.5 text-sm font-medium rounded-md transition-colors ${
                  statusFilter === tab.key
                    ? 'bg-white text-brand-primary shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </nav>
        </div>
      </div>

      {/* Error */}
      {error && (
        <div className="mb-4 rounded-md bg-red-50 p-4">
          <p className="text-sm text-red-700">{error}</p>
        </div>
      )}

      {/* Loading */}
      {loading && (
        <div className="text-center py-12">
          <div className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-solid border-brand-primary border-r-transparent" />
          <p className="mt-2 text-sm text-brand-primary">Loading requests...</p>
        </div>
      )}

      {/* Bulk action bar */}
      {!loading && selectedIds.size > 0 && (
        <div
          data-testid="bulk-action-bar"
          className="mb-4 flex items-center gap-3 rounded-md bg-brand-primary/5 border border-brand-primary/20 px-4 py-3"
        >
          <span className="text-sm font-medium text-brand-primary">
            {selectedIds.size} selected
          </span>
          <button
            onClick={() => handleBulk('approved')}
            disabled={bulkLoading}
            className="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold uppercase bg-brand-primary text-white hover:opacity-90 transition-colors disabled:opacity-50"
          >
            {bulkLoading ? 'Processing...' : 'Approve Selected'}
          </button>
          <button
            onClick={() => handleBulk('rejected')}
            disabled={bulkLoading}
            className="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold uppercase bg-red-600 text-white hover:bg-red-700 transition-colors disabled:opacity-50"
          >
            {bulkLoading ? 'Processing...' : 'Reject Selected'}
          </button>
          <button
            onClick={() => setSelectedIds(new Set())}
            disabled={bulkLoading}
            className="ml-auto text-sm text-gray-500 hover:text-gray-700 disabled:opacity-50"
          >
            Clear
          </button>
        </div>
      )}

      {/* Table */}
      {!loading && (
        <>
          <DataTable<Signup>
            columns={signupColumns}
            rows={signups}
            rowKey={(signup) => signup.id}
            emptyState={{
              text: (
                <>
                  <svg
                    className="mx-auto h-12 w-12 text-gray-400"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    aria-hidden="true"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={1.5}
                      d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"
                    />
                  </svg>
                  <h3 className="mt-4 text-lg font-medium text-brand-primary">
                    No {statusFilter !== 'all' ? statusFilter : ''} signup requests
                  </h3>
                  <p className="mt-1 text-sm text-gray-500">
                    {statusFilter === 'pending'
                      ? 'There are no pending volunteer signup requests to review right now.'
                      : `No ${statusFilter !== 'all' ? statusFilter : ''} volunteer signup requests found.`}
                  </p>
                </>
              ),
            }}
            // Reject reason input, directly under the request being rejected.
            renderExpandedRow={(signup) => {
              if (rejectingId !== signup.id) return null;
              const isActioning = actionLoading === signup.id;
              return (
                <div className="flex items-center gap-3">
                  <label className="text-sm text-gray-700 font-medium whitespace-nowrap">
                    Reason (optional):
                  </label>
                  <input
                    type="text"
                    value={rejectNotes}
                    onChange={(e) => setRejectNotes(e.target.value)}
                    placeholder="Enter a reason for rejection..."
                    className="flex-1 rounded-md border border-brand-secondary text-brand-primary shadow-sm focus:outline-none focus:border-brand-accent text-sm"
                  />
                  <button
                    onClick={() => handleReject(signup.id)}
                    disabled={isActioning}
                    className="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold uppercase bg-red-600 text-white hover:bg-red-700 transition-colors disabled:opacity-50"
                  >
                    {isActioning ? 'Processing...' : 'Confirm Reject'}
                  </button>
                  <button
                    onClick={() => {
                      setRejectingId(null);
                      setRejectNotes('');
                    }}
                    className="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold uppercase bg-gray-200 text-gray-700 hover:bg-gray-300 transition-colors"
                  >
                    Cancel
                  </button>
                </div>
              );
            }}
          />
        </>
      )}
    </div>
  );
};

export default VolunteerSignupRequests;

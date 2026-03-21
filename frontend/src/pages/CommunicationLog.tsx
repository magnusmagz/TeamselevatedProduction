import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface CommunicationEntry {
  id: number;
  channel: 'email' | 'sms';
  recipient_name: string;
  recipient_email?: string;
  recipient_phone?: string;
  subject?: string;
  body: string;
  status: 'queued' | 'sending' | 'sent' | 'delivered' | 'failed' | 'bounced' | 'rejected';
  sender_first_name: string;
  sender_last_name: string;
  open_count: number;
  click_count: number;
  created_at: string;
  sent_at: string;
  delivered_at: string;
}

interface CommunicationDetail extends CommunicationEntry {
  events?: CommunicationEvent[];
}

interface CommunicationEvent {
  id: number;
  event_type: string;
  occurred_at: string;
  url?: string;
  user_agent?: string;
}

interface Team {
  id: number;
  name: string;
}

const STATUS_COLORS: Record<string, string> = {
  queued: 'bg-gray-100 text-gray-700',
  sending: 'bg-yellow-100 text-yellow-700',
  sent: 'bg-blue-100 text-blue-700',
  delivered: 'bg-green-100 text-green-700',
  failed: 'bg-red-100 text-red-700',
  bounced: 'bg-orange-100 text-orange-700',
  rejected: 'bg-red-100 text-red-700',
};

const STATUS_STEPS = ['queued', 'sent', 'delivered', 'opened'];

function formatDate(dateStr: string): string {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function formatShortDate(dateStr: string): string {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export const CommunicationLog: React.FC = () => {
  const { user } = useAuth();
  const [entries, setEntries] = useState<CommunicationEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);

  // Filters
  const [channelFilter, setChannelFilter] = useState<'all' | 'email' | 'sms'>('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [teamFilter, setTeamFilter] = useState('');
  const [teams, setTeams] = useState<Team[]>([]);

  // Detail panel
  const [selectedEntry, setSelectedEntry] = useState<CommunicationDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [showDetail, setShowDetail] = useState(false);

  // Compose modals
  const [showEmailCompose, setShowEmailCompose] = useState(false);
  const [showSmsCompose, setShowSmsCompose] = useState(false);

  // Lazy-loaded compose components
  const [EmailCompose, setEmailCompose] = useState<React.FC<any> | null>(null);
  const [SmsCompose, setSmsCompose] = useState<React.FC<any> | null>(null);

  const clubProfileId = user?.organization?.orgId;

  // Load compose components on demand
  useEffect(() => {
    if (showEmailCompose && !EmailCompose) {
      import('../components/communications/EmailCompose').then((mod) => {
        setEmailCompose(() => mod.default || mod.EmailCompose);
      });
    }
  }, [showEmailCompose, EmailCompose]);

  useEffect(() => {
    if (showSmsCompose && !SmsCompose) {
      import('../components/communications/SmsCompose').then((mod) => {
        setSmsCompose(() => mod.default || mod.SmsCompose);
      });
    }
  }, [showSmsCompose, SmsCompose]);

  // Fetch teams for filter
  useEffect(() => {
    if (!clubProfileId) return;
    const token = localStorage.getItem('auth_token');
    fetch(`${API_URL}/api/teams.php?club_profile_id=${clubProfileId}`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success && data.teams) {
          setTeams(data.teams);
        }
      })
      .catch((err) => console.error('Error fetching teams:', err));
  }, [clubProfileId]);

  const fetchEntries = useCallback(
    (pageNum: number, append: boolean = false) => {
      if (!clubProfileId) return;
      const token = localStorage.getItem('auth_token');

      if (!append) setLoading(true);
      else setLoadingMore(true);

      let url = `${API_URL}/api/communications.php?action=log&club_profile_id=${clubProfileId}&page=${pageNum}&per_page=25`;
      if (channelFilter !== 'all') url += `&channel=${channelFilter}`;
      if (statusFilter !== 'all') url += `&status=${statusFilter}`;
      if (dateFrom) url += `&date_from=${dateFrom}`;
      if (dateTo) url += `&date_to=${dateTo}`;
      if (teamFilter) url += `&team_id=${teamFilter}`;

      fetch(url, {
        headers: { Authorization: `Bearer ${token}` },
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.success) {
            const newEntries = data.entries || data.data || [];
            if (append) {
              setEntries((prev) => [...prev, ...newEntries]);
            } else {
              setEntries(newEntries);
            }
            setHasMore(newEntries.length === 25);
          }
        })
        .catch((err) => console.error('Error fetching communication log:', err))
        .finally(() => {
          setLoading(false);
          setLoadingMore(false);
        });
    },
    [clubProfileId, channelFilter, statusFilter, dateFrom, dateTo, teamFilter]
  );

  // Reset and fetch on filter change
  useEffect(() => {
    setPage(1);
    fetchEntries(1, false);
  }, [fetchEntries]);

  const handleLoadMore = () => {
    const nextPage = page + 1;
    setPage(nextPage);
    fetchEntries(nextPage, true);
  };

  const handleRowClick = (entry: CommunicationEntry) => {
    setShowDetail(true);
    setDetailLoading(true);
    setSelectedEntry(entry as CommunicationDetail);

    const token = localStorage.getItem('auth_token');
    fetch(`${API_URL}/api/communications.php?action=log-detail&id=${entry.id}`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          setSelectedEntry(data.entry || data.data);
        }
      })
      .catch((err) => console.error('Error fetching detail:', err))
      .finally(() => setDetailLoading(false));
  };

  const handleComposeSent = () => {
    setShowEmailCompose(false);
    setShowSmsCompose(false);
    setPage(1);
    fetchEntries(1, false);
  };

  // Skeleton rows for loading state
  const SkeletonRows = () => (
    <>
      {[...Array(8)].map((_, i) => (
        <tr key={i} className="animate-pulse">
          <td className="px-4 py-3"><div className="h-4 bg-gray-200 rounded w-20" /></td>
          <td className="px-4 py-3"><div className="h-5 bg-gray-200 rounded w-14" /></td>
          <td className="px-4 py-3"><div className="h-4 bg-gray-200 rounded w-32" /></td>
          <td className="px-4 py-3"><div className="h-4 bg-gray-200 rounded w-48" /></td>
          <td className="px-4 py-3"><div className="h-4 bg-gray-200 rounded w-24" /></td>
          <td className="px-4 py-3"><div className="h-5 bg-gray-200 rounded w-20" /></td>
          <td className="px-4 py-3"><div className="h-4 bg-gray-200 rounded w-8" /></td>
          <td className="px-4 py-3"><div className="h-4 bg-gray-200 rounded w-8" /></td>
        </tr>
      ))}
    </>
  );

  const getStatusStep = (entry: CommunicationDetail): number => {
    if (entry.events?.some((e) => e.event_type === 'open')) return 3;
    if (entry.status === 'delivered' || entry.delivered_at) return 2;
    if (entry.status === 'sent' || entry.sent_at) return 1;
    return 0;
  };

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="bg-white border-b border-gray-200">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 className="text-2xl font-bold text-gray-900">Communication Log</h1>
              <p className="mt-1 text-sm text-gray-500">Track all emails and SMS sent from your organization</p>
            </div>
            <div className="flex gap-3">
              <button
                onClick={() => setShowEmailCompose(true)}
                className="inline-flex items-center px-4 py-2 bg-brand-primary text-white text-sm font-medium rounded-lg hover:opacity-90 transition-opacity shadow-sm"
              >
                <span className="mr-2"><svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg></span>
                Compose Email
              </button>
              <button
                onClick={() => setShowSmsCompose(true)}
                className="inline-flex items-center px-4 py-2 bg-white text-brand-primary text-sm font-medium rounded-lg border border-brand-primary hover:bg-gray-50 transition-colors"
              >
                <span className="mr-2"><svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg></span>
                Compose SMS
              </button>
            </div>
          </div>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Filters */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
          <div className="flex flex-wrap items-center gap-4">
            {/* Channel toggle */}
            <div className="flex rounded-lg border border-gray-300 overflow-hidden">
              {(['all', 'email', 'sms'] as const).map((ch) => (
                <button
                  key={ch}
                  onClick={() => setChannelFilter(ch)}
                  className={`px-4 py-2 text-sm font-medium transition-colors ${
                    channelFilter === ch
                      ? 'bg-brand-primary text-white'
                      : 'bg-white text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  {ch === 'all' ? 'All' : ch === 'email' ? '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg> Email' : '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg> SMS'}
                </button>
              ))}
            </div>

            {/* Status dropdown */}
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
            >
              <option value="all">All Statuses</option>
              <option value="delivered">Delivered</option>
              <option value="sent">Sent</option>
              <option value="failed">Failed</option>
              <option value="bounced">Bounced</option>
              <option value="queued">Queued</option>
              <option value="rejected">Rejected</option>
            </select>

            {/* Team dropdown */}
            <select
              value={teamFilter}
              onChange={(e) => setTeamFilter(e.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
            >
              <option value="">All Teams</option>
              {teams.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>

            {/* Date range */}
            <div className="flex items-center gap-2">
              <label className="text-sm text-gray-500">From</label>
              <input
                type="date"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>
            <div className="flex items-center gap-2">
              <label className="text-sm text-gray-500">To</label>
              <input
                type="date"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>

            {/* Clear filters */}
            {(channelFilter !== 'all' || statusFilter !== 'all' || dateFrom || dateTo || teamFilter) && (
              <button
                onClick={() => {
                  setChannelFilter('all');
                  setStatusFilter('all');
                  setDateFrom('');
                  setDateTo('');
                  setTeamFilter('');
                }}
                className="text-sm text-gray-500 hover:text-gray-700 underline"
              >
                Clear filters
              </button>
            )}
          </div>
        </div>

        {/* Table */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-200">
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Channel</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Recipient</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Subject / Preview</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Sender</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Opens</th>
                  <th className="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Clicks</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {loading ? (
                  <SkeletonRows />
                ) : entries.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-16 text-center">
                      <div className="flex flex-col items-center">
                        <div className="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                          <span className="text-2xl">📨</span>
                        </div>
                        <h3 className="text-lg font-medium text-gray-900 mb-1">No communications sent yet</h3>
                        <p className="text-sm text-gray-500 mb-4">Get started by composing your first email or SMS</p>
                        <div className="flex gap-3">
                          <button
                            onClick={() => setShowEmailCompose(true)}
                            className="px-4 py-2 bg-brand-primary text-white text-sm font-medium rounded-lg hover:opacity-90"
                          >
                            Compose Email
                          </button>
                          <button
                            onClick={() => setShowSmsCompose(true)}
                            className="px-4 py-2 border border-brand-primary text-brand-primary text-sm font-medium rounded-lg hover:bg-gray-50"
                          >
                            Compose SMS
                          </button>
                        </div>
                      </div>
                    </td>
                  </tr>
                ) : (
                  entries.map((entry) => (
                    <tr
                      key={entry.id}
                      onClick={() => handleRowClick(entry)}
                      className="hover:bg-gray-50 cursor-pointer transition-colors"
                    >
                      <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                        {formatShortDate(entry.created_at)}
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                            entry.channel === 'email'
                              ? 'bg-blue-50 text-blue-700'
                              : 'bg-purple-50 text-purple-700'
                          }`}
                        >
                          {entry.channel === 'email' ? '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg> Email' : '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg> SMS'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="text-sm font-medium text-gray-900">{entry.recipient_name}</div>
                        <div className="text-xs text-gray-500">
                          {entry.channel === 'email' ? entry.recipient_email : entry.recipient_phone}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700 max-w-xs truncate">
                        {entry.channel === 'email'
                          ? entry.subject || '(no subject)'
                          : entry.body?.substring(0, 80) + (entry.body?.length > 80 ? '...' : '')}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                        {entry.sender_first_name} {entry.sender_last_name}
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize ${
                            STATUS_COLORS[entry.status] || 'bg-gray-100 text-gray-700'
                          }`}
                        >
                          {entry.status}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600 text-center">
                        {entry.channel === 'email' ? entry.open_count : '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600 text-center">
                        {entry.channel === 'email' ? entry.click_count : '—'}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Load more */}
          {!loading && hasMore && entries.length > 0 && (
            <div className="border-t border-gray-200 px-4 py-4 text-center">
              <button
                onClick={handleLoadMore}
                disabled={loadingMore}
                className="inline-flex items-center px-6 py-2 text-sm font-medium text-brand-primary bg-white border border-brand-primary rounded-lg hover:bg-gray-50 disabled:opacity-50 transition-colors"
              >
                {loadingMore ? (
                  <>
                    <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-brand-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Loading...
                  </>
                ) : (
                  'Load More'
                )}
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Detail Slide-over Panel */}
      {showDetail && (
        <div className="fixed inset-0 z-50 overflow-hidden">
          <div className="absolute inset-0 bg-black bg-opacity-30" onClick={() => setShowDetail(false)} />
          <div className="absolute inset-y-0 right-0 w-full max-w-lg bg-white shadow-xl flex flex-col">
            {/* Panel header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
              <h2 className="text-lg font-semibold text-gray-900">Communication Detail</h2>
              <button
                onClick={() => setShowDetail(false)}
                className="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Panel body */}
            <div className="flex-1 overflow-y-auto px-6 py-4">
              {detailLoading ? (
                <div className="flex items-center justify-center py-12">
                  <svg className="animate-spin h-8 w-8 text-brand-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                </div>
              ) : selectedEntry ? (
                <div className="space-y-6">
                  {/* Channel + Status */}
                  <div className="flex items-center gap-3">
                    <span
                      className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                        selectedEntry.channel === 'email'
                          ? 'bg-blue-50 text-blue-700'
                          : 'bg-purple-50 text-purple-700'
                      }`}
                    >
                      {selectedEntry.channel === 'email' ? '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg> Email' : '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg> SMS'}
                    </span>
                    <span
                      className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium capitalize ${
                        STATUS_COLORS[selectedEntry.status] || 'bg-gray-100 text-gray-700'
                      }`}
                    >
                      {selectedEntry.status}
                    </span>
                  </div>

                  {/* Subject */}
                  {selectedEntry.channel === 'email' && selectedEntry.subject && (
                    <div>
                      <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Subject</label>
                      <p className="text-gray-900 font-medium">{selectedEntry.subject}</p>
                    </div>
                  )}

                  {/* Recipient */}
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Recipient</label>
                    <p className="text-gray-900">{selectedEntry.recipient_name}</p>
                    <p className="text-sm text-gray-500">
                      {selectedEntry.channel === 'email' ? selectedEntry.recipient_email : selectedEntry.recipient_phone}
                    </p>
                  </div>

                  {/* Sender */}
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Sender</label>
                    <p className="text-gray-900">
                      {selectedEntry.sender_first_name} {selectedEntry.sender_last_name}
                    </p>
                  </div>

                  {/* Status Timeline */}
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Delivery Timeline</label>
                    <div className="flex items-center gap-0">
                      {STATUS_STEPS.map((step, idx) => {
                        const currentStep = getStatusStep(selectedEntry);
                        const isActive = idx <= currentStep;
                        const isFailed = selectedEntry.status === 'failed' || selectedEntry.status === 'bounced' || selectedEntry.status === 'rejected';
                        return (
                          <React.Fragment key={step}>
                            <div className="flex flex-col items-center">
                              <div
                                className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold ${
                                  isFailed && idx > 0
                                    ? 'bg-red-100 text-red-500'
                                    : isActive
                                    ? 'bg-green-500 text-white'
                                    : 'bg-gray-200 text-gray-400'
                                }`}
                              >
                                {isActive && !isFailed ? '✓' : idx + 1}
                              </div>
                              <span className="text-xs text-gray-500 mt-1 capitalize">{step}</span>
                            </div>
                            {idx < STATUS_STEPS.length - 1 && (
                              <div
                                className={`flex-1 h-0.5 mx-1 ${
                                  idx < currentStep ? 'bg-green-500' : 'bg-gray-200'
                                }`}
                              />
                            )}
                          </React.Fragment>
                        );
                      })}
                    </div>
                  </div>

                  {/* Timestamps */}
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Timestamps</label>
                    <div className="space-y-1 text-sm">
                      <div className="flex justify-between">
                        <span className="text-gray-500">Created</span>
                        <span className="text-gray-900">{formatDate(selectedEntry.created_at)}</span>
                      </div>
                      {selectedEntry.sent_at && (
                        <div className="flex justify-between">
                          <span className="text-gray-500">Sent</span>
                          <span className="text-gray-900">{formatDate(selectedEntry.sent_at)}</span>
                        </div>
                      )}
                      {selectedEntry.delivered_at && (
                        <div className="flex justify-between">
                          <span className="text-gray-500">Delivered</span>
                          <span className="text-gray-900">{formatDate(selectedEntry.delivered_at)}</span>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Metrics (email only) */}
                  {selectedEntry.channel === 'email' && (
                    <div>
                      <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Engagement</label>
                      <div className="grid grid-cols-2 gap-3">
                        <div className="bg-blue-50 rounded-lg p-3 text-center">
                          <div className="text-2xl font-bold text-blue-700">{selectedEntry.open_count}</div>
                          <div className="text-xs text-blue-600">Opens</div>
                        </div>
                        <div className="bg-green-50 rounded-lg p-3 text-center">
                          <div className="text-2xl font-bold text-green-700">{selectedEntry.click_count}</div>
                          <div className="text-xs text-green-600">Clicks</div>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Body */}
                  <div>
                    <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                      {selectedEntry.channel === 'email' ? 'Email Body' : 'Message'}
                    </label>
                    {selectedEntry.channel === 'email' ? (
                      <div className="border border-gray-200 rounded-lg overflow-hidden">
                        <iframe
                          title="Email body"
                          srcDoc={selectedEntry.body}
                          className="w-full min-h-[300px] bg-white"
                          sandbox="allow-same-origin"
                        />
                      </div>
                    ) : (
                      <div className="bg-gray-50 rounded-lg p-4 text-sm text-gray-800 whitespace-pre-wrap">
                        {selectedEntry.body}
                      </div>
                    )}
                  </div>

                  {/* Events list */}
                  {selectedEntry.events && selectedEntry.events.length > 0 && (
                    <div>
                      <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Events</label>
                      <div className="space-y-2">
                        {selectedEntry.events.map((evt) => (
                          <div key={evt.id} className="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-2">
                            <div>
                              <span className="text-sm font-medium text-gray-900 capitalize">{evt.event_type.replace('_', ' ')}</span>
                              {evt.url && (
                                <p className="text-xs text-gray-500 truncate max-w-[250px]">{evt.url}</p>
                              )}
                            </div>
                            <span className="text-xs text-gray-500 whitespace-nowrap ml-4">
                              {formatDate(evt.occurred_at)}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              ) : null}
            </div>
          </div>
        </div>
      )}

      {/* Email Compose Modal */}
      {showEmailCompose && EmailCompose && (
        <EmailCompose
          isOpen={showEmailCompose}
          onClose={() => {
            setShowEmailCompose(false);
            handleComposeSent();
          }}
          clubProfileId={clubProfileId || 0}
        />
      )}

      {/* SMS Compose Modal */}
      {showSmsCompose && SmsCompose && (
        <SmsCompose
          isOpen={showSmsCompose}
          onClose={() => {
            setShowSmsCompose(false);
            handleComposeSent();
          }}
          clubProfileId={clubProfileId || 0}
        />
      )}
    </div>
  );
};

export default CommunicationLog;

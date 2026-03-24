import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';
import {
  LineChart, Line, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer
} from 'recharts';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

// --- Interfaces ---

interface Team {
  id: number;
  name: string;
}

interface OverviewStats {
  total_sent: number;
  email_sent: number;
  sms_sent: number;
  total_delivered: number;
  total_opened: number;
  total_clicked: number;
  total_bounced: number;
  total_failed: number;
  total_pending: number;
  delivery_rate: number;
  open_rate: number;
  click_rate: number;
  prev_total_sent: number;
  prev_delivery_rate: number;
  prev_open_rate: number;
  prev_click_rate: number;
}

interface VolumeDataPoint {
  date: string;
  email_sent: number;
  sms_sent: number;
}

interface EngagementDataPoint {
  date: string;
  open_rate: number;
  click_rate: number;
}

interface LinkAnalytics {
  url: string;
  clicks: number;
}

interface RecentSend {
  id: number;
  channel: 'email' | 'sms';
  subject: string | null;
  body_preview: string;
  recipient_count: number;
  sent_at: string;
  open_rate: number | null;
  click_rate: number | null;
  status: string;
  sender_name: string;
}

interface RecentSendsResponse {
  sends: RecentSend[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

interface RecipientDetail {
  name: string;
  email: string;
  phone: string | null;
  status: string;
  opened: boolean;
  clicked: boolean;
  opened_at: string | null;
  clicked_at: string | null;
}

interface PerEmailReport {
  id: number;
  channel: string;
  subject: string;
  body: string;
  sender_name: string;
  sent_at: string;
  recipient_count: number;
  total_delivered: number;
  total_opened: number;
  total_clicked: number;
  total_bounced: number;
  recipients: RecipientDetail[];
}

type DatePreset = '7d' | '30d' | '90d' | 'all' | 'custom';
type ChannelFilter = 'all' | 'email' | 'sms';

// --- Helper functions ---

const formatDate = (dateStr: string): string => {
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const formatShortDate = (dateStr: string): string => {
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const getDateRange = (preset: DatePreset): { from: string; to: string } => {
  const to = new Date();
  const from = new Date();
  switch (preset) {
    case '7d':
      from.setDate(from.getDate() - 7);
      break;
    case '30d':
      from.setDate(from.getDate() - 30);
      break;
    case '90d':
      from.setDate(from.getDate() - 90);
      break;
    case 'all':
      from.setFullYear(from.getFullYear() - 5);
      break;
    default:
      from.setDate(from.getDate() - 30);
  }
  return {
    from: from.toISOString().split('T')[0],
    to: to.toISOString().split('T')[0],
  };
};

const truncateUrl = (url: string, max: number = 50): string => {
  if (url.length <= max) return url;
  return url.substring(0, max) + '...';
};

const getTrendIndicator = (current: number, previous: number): { label: string; positive: boolean; neutral: boolean } => {
  if (previous === 0) return { label: '--', positive: false, neutral: true };
  const diff = ((current - previous) / previous) * 100;
  if (Math.abs(diff) < 0.5) return { label: '0%', positive: false, neutral: true };
  return {
    label: `${diff > 0 ? '+' : ''}${diff.toFixed(1)}%`,
    positive: diff > 0,
    neutral: false,
  };
};

// --- Components ---

const LoadingSpinner: React.FC<{ text?: string }> = ({ text = 'Loading...' }) => (
  <div className="flex items-center justify-center py-12">
    <div className="flex flex-col items-center gap-3">
      <div className="w-8 h-8 border-4 border-brand-primary border-t-transparent rounded-full animate-spin" />
      <p className="text-gray-500 text-sm">{text}</p>
    </div>
  </div>
);

const EmptyState: React.FC<{ message: string }> = ({ message }) => (
  <div className="flex items-center justify-center py-12">
    <p className="text-gray-400 text-sm">{message}</p>
  </div>
);

// --- Main Component ---

export const EmailReporting: React.FC = () => {
  const { user } = useAuth();
  const { currentClubId, isClubAdmin } = useOrg();
  const token = localStorage.getItem('auth_token');

  // Filters
  const [datePreset, setDatePreset] = useState<DatePreset>('30d');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [selectedTeam, setSelectedTeam] = useState<string>('');
  const [channelFilter, setChannelFilter] = useState<ChannelFilter>('all');

  // Data
  const [teams, setTeams] = useState<Team[]>([]);
  const [overview, setOverview] = useState<OverviewStats | null>(null);
  const [volumeData, setVolumeData] = useState<VolumeDataPoint[]>([]);
  const [engagementData, setEngagementData] = useState<EngagementDataPoint[]>([]);
  const [linkData, setLinkData] = useState<LinkAnalytics[]>([]);
  const [recentSends, setRecentSends] = useState<RecentSendsResponse | null>(null);
  const [expandedSendId, setExpandedSendId] = useState<number | null>(null);
  const [perEmailReport, setPerEmailReport] = useState<PerEmailReport | null>(null);

  // Loading states
  const [loadingTeams, setLoadingTeams] = useState(true);
  const [loadingOverview, setLoadingOverview] = useState(true);
  const [loadingVolume, setLoadingVolume] = useState(true);
  const [loadingLinks, setLoadingLinks] = useState(true);
  const [loadingSends, setLoadingSends] = useState(true);
  const [loadingPerEmail, setLoadingPerEmail] = useState(false);

  // Pagination
  const [sendsPage, setSendsPage] = useState(1);
  const sendsPerPage = 10;

  // Computed date range
  const getActiveDateRange = useCallback((): { from: string; to: string } => {
    if (datePreset === 'custom' && customFrom && customTo) {
      return { from: customFrom, to: customTo };
    }
    return getDateRange(datePreset);
  }, [datePreset, customFrom, customTo]);

  // Determine period grouping based on date range
  const getPeriod = useCallback((): string => {
    const { from, to } = getActiveDateRange();
    const diffDays = (new Date(to).getTime() - new Date(from).getTime()) / (1000 * 60 * 60 * 24);
    if (diffDays <= 14) return 'day';
    if (diffDays <= 90) return 'week';
    return 'month';
  }, [getActiveDateRange]);

  const buildParams = useCallback((): string => {
    const { from, to } = getActiveDateRange();
    const clubId = currentClubId || 32;
    let params = `club_profile_id=${clubId}&date_from=${from}&date_to=${to}`;
    if (selectedTeam) params += `&team_id=${selectedTeam}`;
    if (channelFilter !== 'all') params += `&channel=${channelFilter}`;
    return params;
  }, [getActiveDateRange, currentClubId, selectedTeam, channelFilter]);

  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };

  // Fetch teams for filter
  useEffect(() => {
    const clubId = currentClubId || 32;
    setLoadingTeams(true);
    fetch(`${API_URL}/api/analytics?action=teams&club_profile_id=${clubId}`, { headers })
      .then(res => res.json())
      .then(data => {
        if (data.success && data.teams) {
          setTeams(data.teams);
        }
      })
      .catch(err => console.error('Error fetching teams:', err))
      .finally(() => setLoadingTeams(false));
  }, [currentClubId]);

  // Fetch overview stats
  useEffect(() => {
    setLoadingOverview(true);
    const params = buildParams();
    fetch(`${API_URL}/api/analytics?action=overview&${params}`, { headers })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setOverview(data.stats);
        }
      })
      .catch(err => console.error('Error fetching overview:', err))
      .finally(() => setLoadingOverview(false));
  }, [buildParams]);

  // Fetch volume + engagement data
  useEffect(() => {
    setLoadingVolume(true);
    const params = buildParams();
    const period = getPeriod();
    fetch(`${API_URL}/api/analytics?action=campaign-performance&${params}&period=${period}`, { headers })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setVolumeData(data.volume || []);
          setEngagementData(data.engagement || []);
        }
      })
      .catch(err => console.error('Error fetching volume:', err))
      .finally(() => setLoadingVolume(false));
  }, [buildParams, getPeriod]);

  // Fetch link analytics
  useEffect(() => {
    setLoadingLinks(true);
    const params = buildParams();
    fetch(`${API_URL}/api/analytics?action=link-analytics&${params}`, { headers })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setLinkData(data.links || []);
        }
      })
      .catch(err => console.error('Error fetching links:', err))
      .finally(() => setLoadingLinks(false));
  }, [buildParams]);

  // Fetch recent sends
  useEffect(() => {
    setLoadingSends(true);
    const params = buildParams();
    fetch(`${API_URL}/api/analytics?action=recent-sends&${params}&page=${sendsPage}&per_page=${sendsPerPage}`, { headers })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setRecentSends(data);
        }
      })
      .catch(err => console.error('Error fetching sends:', err))
      .finally(() => setLoadingSends(false));
  }, [buildParams, sendsPage]);

  // Fetch per-email report
  const fetchPerEmailReport = (id: number) => {
    if (expandedSendId === id) {
      setExpandedSendId(null);
      setPerEmailReport(null);
      return;
    }
    setExpandedSendId(id);
    setLoadingPerEmail(true);
    setPerEmailReport(null);
    fetch(`${API_URL}/api/analytics?action=per-email-report&id=${id}&type=single`, { headers })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setPerEmailReport(data.report);
        }
      })
      .catch(err => console.error('Error fetching per-email report:', err))
      .finally(() => setLoadingPerEmail(false));
  };

  // Reset page when filters change
  useEffect(() => {
    setSendsPage(1);
    setExpandedSendId(null);
    setPerEmailReport(null);
  }, [datePreset, customFrom, customTo, selectedTeam, channelFilter]);

  // Pie chart data
  const pieData = overview
    ? [
        { name: 'Delivered', value: overview.total_delivered, color: '#22c55e' },
        { name: 'Bounced', value: overview.total_bounced, color: '#f97316' },
        { name: 'Failed', value: overview.total_failed, color: '#ef4444' },
        { name: 'Pending', value: overview.total_pending, color: '#9ca3af' },
      ].filter(d => d.value > 0)
    : [];

  const COLORS_PIE = ['#22c55e', '#f97316', '#ef4444', '#9ca3af'];

  return (
    <div className="container mx-auto p-4 sm:p-6 max-w-7xl">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl sm:text-3xl font-bold text-brand-primary uppercase tracking-wide">
          Email &amp; SMS Reporting
        </h1>
        <p className="text-gray-600 mt-1">
          Track delivery, engagement, and performance across all communications
        </p>
      </div>

      {/* Filters Section */}
      <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-6 mb-6 shadow-sm">
        <div className="flex flex-col lg:flex-row lg:items-end gap-4">
          {/* Date Presets */}
          <div className="flex-1">
            <label className="block text-sm font-medium text-gray-700 mb-2">Date Range</label>
            <div className="flex flex-wrap gap-2">
              {(['7d', '30d', '90d', 'all'] as DatePreset[]).map(preset => (
                <button
                  key={preset}
                  onClick={() => setDatePreset(preset)}
                  className={`px-4 py-2 text-sm uppercase font-semibold rounded-md border border-brand-secondary transition-colors ${
                    datePreset === preset
                      ? 'bg-brand-primary text-white'
                      : 'bg-white text-brand-primary hover:bg-gray-50'
                  }`}
                >
                  {preset === 'all' ? 'All Time' : preset.toUpperCase()}
                </button>
              ))}
              <button
                onClick={() => setDatePreset('custom')}
                className={`px-4 py-2 text-sm uppercase font-semibold rounded-md border border-brand-secondary transition-colors ${
                  datePreset === 'custom'
                    ? 'bg-brand-primary text-white'
                    : 'bg-white text-brand-primary hover:bg-gray-50'
                }`}
              >
                Custom
              </button>
            </div>
            {datePreset === 'custom' && (
              <div className="flex gap-3 mt-3">
                <input
                  type="date"
                  value={customFrom}
                  onChange={e => setCustomFrom(e.target.value)}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
                />
                <span className="text-gray-400 self-center">to</span>
                <input
                  type="date"
                  value={customTo}
                  onChange={e => setCustomTo(e.target.value)}
                  className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
                />
              </div>
            )}
          </div>

          {/* Team Filter */}
          <div className="w-full lg:w-48">
            <label className="block text-sm font-medium text-gray-700 mb-2">Team</label>
            <select
              value={selectedTeam}
              onChange={e => setSelectedTeam(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none bg-white"
            >
              <option value="">All Teams</option>
              {teams.map(t => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>

          {/* Channel Filter */}
          <div className="w-full lg:w-40">
            <label className="block text-sm font-medium text-gray-700 mb-2">Channel</label>
            <select
              value={channelFilter}
              onChange={e => setChannelFilter(e.target.value as ChannelFilter)}
              className="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none bg-white"
            >
              <option value="all">All Channels</option>
              <option value="email">Email Only</option>
              <option value="sms">SMS Only</option>
            </select>
          </div>
        </div>
      </div>

      {/* Stats Cards */}
      {loadingOverview ? (
        <LoadingSpinner text="Loading overview..." />
      ) : overview ? (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          {/* Total Sent */}
          <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
            <p className="text-3xl sm:text-4xl font-bold text-brand-primary">
              {overview.total_sent.toLocaleString()}
            </p>
            <p className="text-sm text-gray-500 mt-1">Total Sent</p>
            <div className="flex gap-3 mt-2 text-xs text-gray-400">
              <span>{overview.email_sent.toLocaleString()} emails</span>
              <span>{overview.sms_sent.toLocaleString()} sms</span>
            </div>
            {(() => {
              const trend = getTrendIndicator(overview.total_sent, overview.prev_total_sent);
              return (
                <p className={`text-xs mt-2 font-medium ${trend.neutral ? 'text-gray-400' : trend.positive ? 'text-green-600' : 'text-red-500'}`}>
                  {trend.label} vs prev period
                </p>
              );
            })()}
          </div>

          {/* Delivery Rate */}
          <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
            <p className="text-3xl sm:text-4xl font-bold text-brand-primary">
              {overview.delivery_rate.toFixed(1)}%
            </p>
            <p className="text-sm text-gray-500 mt-1">Delivery Rate</p>
            <p className="text-xs text-gray-400 mt-2">
              {overview.total_delivered.toLocaleString()} delivered
            </p>
            {(() => {
              const trend = getTrendIndicator(overview.delivery_rate, overview.prev_delivery_rate);
              return (
                <p className={`text-xs mt-1 font-medium ${trend.neutral ? 'text-gray-400' : trend.positive ? 'text-green-600' : 'text-red-500'}`}>
                  {trend.label} vs prev period
                </p>
              );
            })()}
          </div>

          {/* Open Rate */}
          <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
            <p className="text-3xl sm:text-4xl font-bold text-brand-primary">
              {overview.open_rate.toFixed(1)}%
            </p>
            <p className="text-sm text-gray-500 mt-1">Open Rate</p>
            <p className="text-xs text-gray-400 mt-2">email only</p>
            {(() => {
              const trend = getTrendIndicator(overview.open_rate, overview.prev_open_rate);
              return (
                <p className={`text-xs mt-1 font-medium ${trend.neutral ? 'text-gray-400' : trend.positive ? 'text-green-600' : 'text-red-500'}`}>
                  {trend.label} vs prev period
                </p>
              );
            })()}
          </div>

          {/* Click Rate */}
          <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
            <p className="text-3xl sm:text-4xl font-bold text-brand-primary">
              {overview.click_rate.toFixed(1)}%
            </p>
            <p className="text-sm text-gray-500 mt-1">Click Rate</p>
            <p className="text-xs text-gray-400 mt-2">email only</p>
            {(() => {
              const trend = getTrendIndicator(overview.click_rate, overview.prev_click_rate);
              return (
                <p className={`text-xs mt-1 font-medium ${trend.neutral ? 'text-gray-400' : trend.positive ? 'text-green-600' : 'text-red-500'}`}>
                  {trend.label} vs prev period
                </p>
              );
            })()}
          </div>
        </div>
      ) : (
        <EmptyState message="No overview data available for the selected filters." />
      )}

      {/* Charts Section - 2 column grid */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {/* Chart 1: Send Volume Over Time */}
        <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">
            Send Volume Over Time
          </h3>
          {loadingVolume ? (
            <LoadingSpinner text="Loading chart..." />
          ) : volumeData.length > 0 ? (
            <ResponsiveContainer width="100%" height={280}>
              <LineChart data={volumeData} margin={{ top: 5, right: 20, left: 0, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis
                  dataKey="date"
                  tickFormatter={formatShortDate}
                  tick={{ fontSize: 11, fill: '#9ca3af' }}
                  axisLine={{ stroke: '#e5e7eb' }}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: '#9ca3af' }}
                  axisLine={{ stroke: '#e5e7eb' }}
                  allowDecimals={false}
                />
                <Tooltip
                  labelFormatter={(label: any) => formatDate(String(label))}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e5e7eb' }}
                />
                <Legend iconSize={10} wrapperStyle={{ fontSize: 12 }} />
                <Line
                  type="monotone"
                  dataKey="email_sent"
                  name="Emails"
                  stroke="#12443e"
                  strokeWidth={2}
                  dot={{ r: 3 }}
                  activeDot={{ r: 5 }}
                />
                <Line
                  type="monotone"
                  dataKey="sms_sent"
                  name="SMS"
                  stroke="#3fcb9a"
                  strokeWidth={2}
                  dot={{ r: 3 }}
                  activeDot={{ r: 5 }}
                />
              </LineChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState message="No send volume data for this period." />
          )}
        </div>

        {/* Chart 2: Delivery Status Breakdown */}
        <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">
            Delivery Status Breakdown
          </h3>
          {loadingOverview ? (
            <LoadingSpinner text="Loading chart..." />
          ) : pieData.length > 0 ? (
            <ResponsiveContainer width="100%" height={280}>
              <PieChart>
                <Pie
                  data={pieData}
                  cx="50%"
                  cy="50%"
                  innerRadius={60}
                  outerRadius={100}
                  paddingAngle={3}
                  dataKey="value"
                  nameKey="name"
                  label={({ name, percent }: any) => `${name} ${((percent || 0) * 100).toFixed(0)}%`}
                  labelLine={{ strokeWidth: 1 }}
                >
                  {pieData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip
                  formatter={(value: any, name: any) => [Number(value).toLocaleString(), String(name)]}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e5e7eb' }}
                />
                <Legend iconSize={10} wrapperStyle={{ fontSize: 12 }} />
              </PieChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState message="No delivery data for this period." />
          )}
        </div>

        {/* Chart 3: Open & Click Rates Over Time */}
        <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">
            Open &amp; Click Rates Over Time
          </h3>
          {loadingVolume ? (
            <LoadingSpinner text="Loading chart..." />
          ) : engagementData.length > 0 ? (
            <ResponsiveContainer width="100%" height={280}>
              <BarChart data={engagementData} margin={{ top: 5, right: 20, left: 0, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis
                  dataKey="date"
                  tickFormatter={formatShortDate}
                  tick={{ fontSize: 11, fill: '#9ca3af' }}
                  axisLine={{ stroke: '#e5e7eb' }}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: '#9ca3af' }}
                  axisLine={{ stroke: '#e5e7eb' }}
                  tickFormatter={(v: number) => `${v}%`}
                  domain={[0, 100]}
                />
                <Tooltip
                  labelFormatter={(label: any) => formatDate(String(label))}
                  formatter={(value: any, name: any) => [`${Number(value).toFixed(1)}%`, String(name)]}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e5e7eb' }}
                />
                <Legend iconSize={10} wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="open_rate" name="Open Rate" fill="#12443e" radius={[4, 4, 0, 0]} />
                <Bar dataKey="click_rate" name="Click Rate" fill="#3fcb9a" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState message="No engagement data for this period." />
          )}
        </div>

        {/* Chart 4: Top Clicked Links */}
        <div className="bg-white border border-gray-200 rounded-lg p-4 sm:p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">
            Top Clicked Links
          </h3>
          {loadingLinks ? (
            <LoadingSpinner text="Loading links..." />
          ) : linkData.length > 0 ? (
            <div className="space-y-3 max-h-[280px] overflow-y-auto pr-1">
              {(() => {
                const maxClicks = Math.max(...linkData.map(l => l.clicks), 1);
                return linkData.map((link, idx) => (
                  <div key={idx} className="group">
                    <div className="flex items-center justify-between mb-1">
                      <a
                        href={link.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs text-brand-primary hover:underline truncate max-w-[80%]"
                        title={link.url}
                      >
                        {truncateUrl(link.url)}
                      </a>
                      <span className="text-xs font-semibold text-gray-600 ml-2 whitespace-nowrap">
                        {link.clicks.toLocaleString()} clicks
                      </span>
                    </div>
                    <div className="w-full bg-gray-100 rounded-full h-2">
                      <div
                        className="h-2 rounded-full transition-all"
                        style={{
                          width: `${(link.clicks / maxClicks) * 100}%`,
                          backgroundColor: '#3fcb9a',
                        }}
                      />
                    </div>
                  </div>
                ));
              })()}
            </div>
          ) : (
            <EmptyState message="No link click data for this period." />
          )}
        </div>
      </div>

      {/* Recent Sends Table */}
      <div className="bg-white border border-gray-200 rounded-lg shadow-sm mb-6">
        <div className="p-4 sm:p-5 border-b border-gray-100">
          <h3 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">
            Recent Sends
          </h3>
        </div>

        {loadingSends ? (
          <LoadingSpinner text="Loading recent sends..." />
        ) : recentSends && recentSends.sends.length > 0 ? (
          <>
            {/* Desktop Table */}
            <div className="hidden sm:block overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-100 text-left">
                    <th className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                    <th className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                    <th className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Subject / Preview</th>
                    <th className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider text-center">Recipients</th>
                    <th className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider text-center">Open Rate</th>
                    <th className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider text-center">Click Rate</th>
                    <th className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {recentSends.sends.map(send => (
                    <React.Fragment key={send.id}>
                      <tr
                        onClick={() => fetchPerEmailReport(send.id)}
                        className="border-b border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors"
                      >
                        <td className="px-4 py-3 text-gray-600 whitespace-nowrap">
                          {formatDate(send.sent_at)}
                        </td>
                        <td className="px-4 py-3">
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                              send.channel === 'email'
                                ? 'bg-blue-50 text-blue-700'
                                : 'bg-purple-50 text-purple-700'
                            }`}
                          >
                            {send.channel === 'email' ? 'Email' : 'SMS'}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-gray-800 max-w-xs truncate">
                          {send.subject || send.body_preview}
                        </td>
                        <td className="px-4 py-3 text-gray-600 text-center">
                          {send.recipient_count}
                        </td>
                        <td className="px-4 py-3 text-center">
                          {send.open_rate !== null ? (
                            <span className="text-gray-700 font-medium">{send.open_rate.toFixed(1)}%</span>
                          ) : (
                            <span className="text-gray-300">--</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-center">
                          {send.click_rate !== null ? (
                            <span className="text-gray-700 font-medium">{send.click_rate.toFixed(1)}%</span>
                          ) : (
                            <span className="text-gray-300">--</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-center">
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                              send.status === 'delivered'
                                ? 'bg-green-50 text-green-700'
                                : send.status === 'sent'
                                ? 'bg-blue-50 text-blue-700'
                                : send.status === 'failed'
                                ? 'bg-red-50 text-red-700'
                                : send.status === 'bounced'
                                ? 'bg-orange-50 text-orange-700'
                                : 'bg-gray-50 text-gray-600'
                            }`}
                          >
                            {send.status.charAt(0).toUpperCase() + send.status.slice(1)}
                          </span>
                        </td>
                      </tr>

                      {/* Expanded Per-Email Report */}
                      {expandedSendId === send.id && (
                        <tr>
                          <td colSpan={7} className="px-0 py-0">
                            <div className="bg-gray-50 border-t border-b border-gray-200 px-6 py-5">
                              {loadingPerEmail ? (
                                <LoadingSpinner text="Loading report details..." />
                              ) : perEmailReport ? (
                                <div>
                                  {/* Report Header */}
                                  <div className="flex flex-wrap gap-6 mb-5">
                                    <div>
                                      <p className="text-xs text-gray-500 uppercase">Subject</p>
                                      <p className="text-sm font-medium text-gray-800">
                                        {perEmailReport.subject || 'N/A'}
                                      </p>
                                    </div>
                                    <div>
                                      <p className="text-xs text-gray-500 uppercase">Sender</p>
                                      <p className="text-sm text-gray-700">{perEmailReport.sender_name}</p>
                                    </div>
                                    <div>
                                      <p className="text-xs text-gray-500 uppercase">Sent</p>
                                      <p className="text-sm text-gray-700">{formatDate(perEmailReport.sent_at)}</p>
                                    </div>
                                    <div>
                                      <p className="text-xs text-gray-500 uppercase">Recipients</p>
                                      <p className="text-sm text-gray-700">{perEmailReport.recipient_count}</p>
                                    </div>
                                  </div>

                                  {/* Aggregate Metrics */}
                                  <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5">
                                    <div className="bg-white rounded-md px-3 py-2 border border-gray-200 text-center">
                                      <p className="text-lg font-bold text-brand-primary">{perEmailReport.recipient_count}</p>
                                      <p className="text-xs text-gray-500">Sent</p>
                                    </div>
                                    <div className="bg-white rounded-md px-3 py-2 border border-gray-200 text-center">
                                      <p className="text-lg font-bold text-green-600">{perEmailReport.total_delivered}</p>
                                      <p className="text-xs text-gray-500">Delivered</p>
                                    </div>
                                    <div className="bg-white rounded-md px-3 py-2 border border-gray-200 text-center">
                                      <p className="text-lg font-bold text-brand-primary">{perEmailReport.total_opened}</p>
                                      <p className="text-xs text-gray-500">Opened</p>
                                    </div>
                                    <div className="bg-white rounded-md px-3 py-2 border border-gray-200 text-center">
                                      <p className="text-lg font-bold" style={{ color: '#3fcb9a' }}>{perEmailReport.total_clicked}</p>
                                      <p className="text-xs text-gray-500">Clicked</p>
                                    </div>
                                    <div className="bg-white rounded-md px-3 py-2 border border-gray-200 text-center">
                                      <p className="text-lg font-bold text-orange-500">{perEmailReport.total_bounced}</p>
                                      <p className="text-xs text-gray-500">Bounced</p>
                                    </div>
                                  </div>

                                  {/* Per-Recipient Table */}
                                  {perEmailReport.recipients && perEmailReport.recipients.length > 0 && (
                                    <div className="overflow-x-auto">
                                      <table className="w-full text-xs">
                                        <thead>
                                          <tr className="border-b border-gray-200">
                                            <th className="text-left px-3 py-2 text-gray-500 uppercase font-semibold">Name</th>
                                            <th className="text-left px-3 py-2 text-gray-500 uppercase font-semibold">Email</th>
                                            <th className="text-center px-3 py-2 text-gray-500 uppercase font-semibold">Status</th>
                                            <th className="text-center px-3 py-2 text-gray-500 uppercase font-semibold">Opened</th>
                                            <th className="text-center px-3 py-2 text-gray-500 uppercase font-semibold">Clicked</th>
                                          </tr>
                                        </thead>
                                        <tbody>
                                          {perEmailReport.recipients.map((r, idx) => (
                                            <tr key={idx} className="border-b border-gray-100">
                                              <td className="px-3 py-2 text-gray-800">{r.name}</td>
                                              <td className="px-3 py-2 text-gray-600">{r.email || r.phone || '--'}</td>
                                              <td className="px-3 py-2 text-center">
                                                <span
                                                  className={`inline-flex px-1.5 py-0.5 rounded text-xs font-medium ${
                                                    r.status === 'delivered'
                                                      ? 'bg-green-50 text-green-700'
                                                      : r.status === 'bounced'
                                                      ? 'bg-orange-50 text-orange-700'
                                                      : r.status === 'failed'
                                                      ? 'bg-red-50 text-red-700'
                                                      : 'bg-gray-50 text-gray-600'
                                                  }`}
                                                >
                                                  {r.status}
                                                </span>
                                              </td>
                                              <td className="px-3 py-2 text-center">
                                                {r.opened ? (
                                                  <span className="text-green-600 font-medium" title={r.opened_at || ''}>
                                                    Yes
                                                  </span>
                                                ) : (
                                                  <span className="text-gray-300">No</span>
                                                )}
                                              </td>
                                              <td className="px-3 py-2 text-center">
                                                {r.clicked ? (
                                                  <span className="text-green-600 font-medium" title={r.clicked_at || ''}>
                                                    Yes
                                                  </span>
                                                ) : (
                                                  <span className="text-gray-300">No</span>
                                                )}
                                              </td>
                                            </tr>
                                          ))}
                                        </tbody>
                                      </table>
                                    </div>
                                  )}
                                </div>
                              ) : (
                                <EmptyState message="Could not load report details." />
                              )}
                            </div>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Mobile Cards */}
            <div className="sm:hidden divide-y divide-gray-100">
              {recentSends.sends.map(send => (
                <React.Fragment key={send.id}>
                  <div
                    onClick={() => fetchPerEmailReport(send.id)}
                    className="p-4 hover:bg-gray-50 cursor-pointer transition-colors"
                  >
                    <div className="flex items-center justify-between mb-2">
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                          send.channel === 'email'
                            ? 'bg-blue-50 text-blue-700'
                            : 'bg-purple-50 text-purple-700'
                        }`}
                      >
                        {send.channel === 'email' ? 'Email' : 'SMS'}
                      </span>
                      <span className="text-xs text-gray-400">{formatDate(send.sent_at)}</span>
                    </div>
                    <p className="text-sm font-medium text-gray-800 truncate mb-2">
                      {send.subject || send.body_preview}
                    </p>
                    <div className="flex items-center gap-4 text-xs text-gray-500">
                      <span>{send.recipient_count} recipients</span>
                      {send.open_rate !== null && <span>Open: {send.open_rate.toFixed(1)}%</span>}
                      {send.click_rate !== null && <span>Click: {send.click_rate.toFixed(1)}%</span>}
                      <span
                        className={`inline-flex px-1.5 py-0.5 rounded text-xs font-medium ${
                          send.status === 'delivered'
                            ? 'bg-green-50 text-green-700'
                            : send.status === 'sent'
                            ? 'bg-blue-50 text-blue-700'
                            : send.status === 'failed'
                            ? 'bg-red-50 text-red-700'
                            : 'bg-gray-50 text-gray-600'
                        }`}
                      >
                        {send.status}
                      </span>
                    </div>
                  </div>

                  {/* Expanded Per-Email Report (Mobile) */}
                  {expandedSendId === send.id && (
                    <div className="bg-gray-50 border-t border-gray-200 px-4 py-4">
                      {loadingPerEmail ? (
                        <LoadingSpinner text="Loading details..." />
                      ) : perEmailReport ? (
                        <div>
                          <div className="grid grid-cols-2 gap-2 mb-4">
                            <div className="bg-white rounded-md p-2 border border-gray-200 text-center">
                              <p className="text-base font-bold text-brand-primary">{perEmailReport.recipient_count}</p>
                              <p className="text-xs text-gray-500">Sent</p>
                            </div>
                            <div className="bg-white rounded-md p-2 border border-gray-200 text-center">
                              <p className="text-base font-bold text-green-600">{perEmailReport.total_delivered}</p>
                              <p className="text-xs text-gray-500">Delivered</p>
                            </div>
                            <div className="bg-white rounded-md p-2 border border-gray-200 text-center">
                              <p className="text-base font-bold text-brand-primary">{perEmailReport.total_opened}</p>
                              <p className="text-xs text-gray-500">Opened</p>
                            </div>
                            <div className="bg-white rounded-md p-2 border border-gray-200 text-center">
                              <p className="text-base font-bold" style={{ color: '#3fcb9a' }}>{perEmailReport.total_clicked}</p>
                              <p className="text-xs text-gray-500">Clicked</p>
                            </div>
                          </div>
                          <p className="text-xs text-gray-500 mb-2">
                            Sender: {perEmailReport.sender_name} | {formatDate(perEmailReport.sent_at)}
                          </p>
                          {perEmailReport.recipients && perEmailReport.recipients.length > 0 && (
                            <div className="space-y-2">
                              {perEmailReport.recipients.map((r, idx) => (
                                <div key={idx} className="bg-white rounded p-2 border border-gray-200 text-xs">
                                  <div className="flex justify-between">
                                    <span className="font-medium text-gray-800">{r.name}</span>
                                    <span
                                      className={`px-1.5 py-0.5 rounded font-medium ${
                                        r.status === 'delivered' ? 'bg-green-50 text-green-700' : 'bg-gray-50 text-gray-600'
                                      }`}
                                    >
                                      {r.status}
                                    </span>
                                  </div>
                                  <div className="text-gray-500 mt-1">
                                    {r.opened ? 'Opened' : 'Not opened'} | {r.clicked ? 'Clicked' : 'Not clicked'}
                                  </div>
                                </div>
                              ))}
                            </div>
                          )}
                        </div>
                      ) : (
                        <EmptyState message="Could not load report." />
                      )}
                    </div>
                  )}
                </React.Fragment>
              ))}
            </div>

            {/* Pagination */}
            {recentSends.total_pages > 1 && (
              <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100">
                <p className="text-xs text-gray-500">
                  Page {recentSends.page} of {recentSends.total_pages} ({recentSends.total} total)
                </p>
                <div className="flex gap-2">
                  <button
                    onClick={() => setSendsPage(prev => Math.max(1, prev - 1))}
                    disabled={sendsPage <= 1}
                    className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Previous
                  </button>
                  <button
                    onClick={() => setSendsPage(prev => Math.min(recentSends.total_pages, prev + 1))}
                    disabled={sendsPage >= recentSends.total_pages}
                    className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        ) : (
          <EmptyState message="No sends found for the selected filters." />
        )}
      </div>
    </div>
  );
};

// Error boundary to catch rendering crashes
class EmailReportingErrorBoundary extends React.Component<
  { children: React.ReactNode },
  { hasError: boolean; error: string }
> {
  constructor(props: { children: React.ReactNode }) {
    super(props);
    this.state = { hasError: false, error: '' };
  }

  static getDerivedStateFromError(error: Error) {
    return { hasError: true, error: error.message };
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="container mx-auto p-6 max-w-7xl">
          <h1 className="text-2xl font-bold text-brand-primary uppercase mb-4">Email & SMS Reporting</h1>
          <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
            <p className="text-red-700 font-medium mb-2">Something went wrong loading the reporting dashboard.</p>
            <p className="text-red-500 text-sm mb-4">{this.state.error}</p>
            <button
              onClick={() => { this.setState({ hasError: false, error: '' }); window.location.reload(); }}
              className="bg-brand-primary text-white px-6 py-2 rounded-md font-medium"
            >
              Reload Page
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}

const EmailReportingWithBoundary: React.FC = () => (
  <EmailReportingErrorBoundary>
    <EmailReporting />
  </EmailReportingErrorBoundary>
);

export default EmailReportingWithBoundary;

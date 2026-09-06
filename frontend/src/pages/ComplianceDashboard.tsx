import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import PageHeader from '../components/ui/PageHeader';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

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

interface TeamBreakdown {
  team_id: number;
  team_name: string;
  age_group: string;
  division: string;
  volunteer_count: number;
  cleared: number;
  pending_bg: number;
  expired_bg: number;
  compliance_rate: number;
}

interface NeedsAttention {
  id: number;
  user_id: number;
  first_name: string;
  last_name: string;
  email: string;
  team_name: string;
  background_check_status: string;
  background_check_date: string | null;
  days_since_check: number | null;
}

interface ComplianceData {
  summary: ComplianceSummary;
  team_breakdown: TeamBreakdown[];
  needs_attention: NeedsAttention[];
}

function complianceRateColor(rate: number): string {
  if (rate >= 90) return 'text-green-600';
  if (rate >= 70) return 'text-yellow-600';
  return 'text-red-600';
}

function complianceRateBg(rate: number): string {
  if (rate >= 100) return 'bg-green-500';
  if (rate >= 80) return 'bg-yellow-500';
  return 'bg-red-500';
}

function statusBadge(status: string): React.ReactNode {
  const normalized = status.toLowerCase();
  let classes = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium';
  if (normalized === 'expired') {
    classes += ' bg-red-100 text-red-800';
  } else if (normalized === 'pending') {
    classes += ' bg-yellow-100 text-yellow-800';
  } else if (normalized === 'cleared') {
    classes += ' bg-green-100 text-green-800';
  } else {
    classes += ' bg-gray-100 text-gray-800';
  }
  return <span className={classes}>{status.charAt(0).toUpperCase() + status.slice(1)}</span>;
}

const statusSortOrder: Record<string, number> = {
  expired: 0,
  pending: 1,
  never_checked: 2,
};

const attentionColumns: DataTableColumn<NeedsAttention>[] = [
  {
    key: 'name',
    header: 'Name',
    className: 'whitespace-nowrap',
    render: (vol) => (
      <span className="font-medium text-brand-primary">{vol.first_name} {vol.last_name}</span>
    ),
  },
  {
    key: 'email',
    header: 'Email',
    className: 'whitespace-nowrap',
    render: (vol) => <span className="text-gray-500">{vol.email}</span>,
  },
  {
    key: 'team_name',
    header: 'Team',
    className: 'whitespace-nowrap',
    render: (vol) => <span className="text-gray-500">{vol.team_name}</span>,
  },
  {
    key: 'background_check_status',
    header: 'BG Check Status',
    className: 'whitespace-nowrap',
    render: (vol) => statusBadge(vol.background_check_status),
  },
  {
    key: 'background_check_date',
    header: 'Last Check Date',
    className: 'whitespace-nowrap',
    render: (vol) => (
      <span className="text-gray-500">
        {vol.background_check_date
          ? new Date(vol.background_check_date).toLocaleDateString()
          : 'N/A'}
      </span>
    ),
  },
  {
    key: 'days_since_check',
    header: 'Days Since Check',
    className: 'whitespace-nowrap',
    render: (vol) => (
      <span className="text-gray-500">
        {vol.days_since_check != null ? `${vol.days_since_check} days` : 'N/A'}
      </span>
    ),
  },
];

const teamColumns: DataTableColumn<TeamBreakdown>[] = [
  {
    key: 'team_name',
    header: 'Team',
    className: 'whitespace-nowrap',
    render: (team) => <span className="font-medium text-brand-primary">{team.team_name}</span>,
  },
  {
    key: 'age_group',
    header: 'Age Group',
    className: 'whitespace-nowrap',
    render: (team) => <span className="text-gray-500">{team.age_group}</span>,
  },
  {
    key: 'division',
    header: 'Division',
    className: 'whitespace-nowrap',
    render: (team) => <span className="text-gray-500">{team.division}</span>,
  },
  {
    key: 'volunteer_count',
    header: 'Volunteers',
    align: 'center',
    className: 'whitespace-nowrap',
    render: (team) => <span className="text-gray-500">{team.volunteer_count}</span>,
  },
  {
    key: 'cleared',
    header: 'Cleared',
    align: 'center',
    className: 'whitespace-nowrap',
    render: (team) => <span className="text-green-600 font-medium">{team.cleared}</span>,
  },
  {
    key: 'pending_bg',
    header: 'Pending',
    align: 'center',
    className: 'whitespace-nowrap',
    render: (team) => <span className="text-yellow-600 font-medium">{team.pending_bg}</span>,
  },
  {
    key: 'expired_bg',
    header: 'Expired',
    align: 'center',
    className: 'whitespace-nowrap',
    render: (team) => <span className="text-red-600 font-medium">{team.expired_bg}</span>,
  },
  {
    key: 'compliance_rate',
    header: 'Compliance Rate',
    className: 'whitespace-nowrap',
    render: (team) => (
      <div className="flex items-center gap-3">
        <div className="flex-1 bg-brand-secondary rounded-full h-2 w-24">
          <div
            className={`h-2 rounded-full ${complianceRateBg(team.compliance_rate)}`}
            style={{ width: `${Math.min(team.compliance_rate, 100)}%` }}
          />
        </div>
        <span className={`text-sm font-medium ${complianceRateColor(team.compliance_rate)}`}>
          {team.compliance_rate.toFixed(0)}%
        </span>
      </div>
    ),
  },
];

/**
 * Club Admin: Volunteer Compliance Dashboard
 * Shows background check compliance status across all volunteers and teams.
 */
export const ComplianceDashboard: React.FC = () => {
  const { currentClubId } = useOrg();
  const [data, setData] = useState<ComplianceData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!currentClubId) return;

    setLoading(true);
    setError(null);

    const token = localStorage.getItem('auth_token');

    fetch(`${API_URL}/api/volunteer-gateway.php?action=compliance&club_id=${currentClubId}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    })
      .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        return res.json();
      })
      .then(json => {
        if (json.success) {
          setData({
            summary: json.summary,
            team_breakdown: json.team_breakdown,
            needs_attention: json.needs_attention,
          });
        } else {
          throw new Error(json.error || 'Failed to load compliance data');
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching compliance data:', err);
        setError(err.message || 'An unexpected error occurred');
        setLoading(false);
      });
  }, [currentClubId]);

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-primary mx-auto mb-4" />
          <p className="text-brand-primary text-sm">Loading compliance data...</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="max-w-4xl mx-auto px-4 py-8">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
          <svg className="w-12 h-12 text-red-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <h3 className="text-lg font-semibold text-red-800 mb-1">Failed to load compliance data</h3>
          <p className="text-red-600 text-sm">{error}</p>
        </div>
      </div>
    );
  }

  if (!data) return null;

  const { summary, team_breakdown, needs_attention } = data;

  const sortedAttention = [...needs_attention].sort((a, b) => {
    const aOrder = statusSortOrder[a.background_check_status] ?? 3;
    const bOrder = statusSortOrder[b.background_check_status] ?? 3;
    return aOrder - bOrder;
  });

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title="Volunteer Compliance"
        subtitle={
          <>
            Overall compliance rate:{' '}
            <span className={`text-3xl font-bold ${complianceRateColor(summary.compliance_rate)}`}>
              {summary.compliance_rate.toFixed(1)}%
            </span>
          </>
        }
      />

      {/* Pending Signups Banner */}
      {summary.pending_signups > 0 && (
        <div className="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <svg className="w-5 h-5 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span className="text-sm text-blue-800">
              <strong>{summary.pending_signups}</strong> volunteer signup{summary.pending_signups !== 1 ? 's' : ''} pending review
            </span>
          </div>
          <Link
            to="/volunteers/requests"
            className="text-sm font-semibold uppercase rounded-md bg-brand-primary text-white px-4 py-2 hover:opacity-90"
          >
            Review Requests
          </Link>
        </div>
      )}

      {/* Summary Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {/* Compliance Rate */}
        <div className={`rounded-lg border p-5 ${
          summary.compliance_rate >= 90 ? 'bg-green-50 border-green-200' :
          summary.compliance_rate >= 70 ? 'bg-yellow-50 border-yellow-200' :
          'bg-red-50 border-red-200'
        }`}>
          <p className="text-sm font-medium text-gray-600">Compliance Rate</p>
          <p className={`text-3xl font-bold mt-1 ${complianceRateColor(summary.compliance_rate)}`}>
            {summary.compliance_rate.toFixed(1)}%
          </p>
          <p className="text-xs text-gray-500 mt-1">
            {summary.cleared} of {summary.total_volunteers} cleared
          </p>
        </div>

        {/* Expired BG Checks */}
        <div className="rounded-lg border bg-red-50 border-red-200 p-5">
          <p className="text-sm font-medium text-gray-600">Expired BG Checks</p>
          <p className="text-3xl font-bold mt-1 text-red-600">{summary.expired}</p>
          <p className="text-xs text-gray-500 mt-1">Require immediate renewal</p>
        </div>

        {/* Pending BG Checks */}
        <div className="rounded-lg border bg-yellow-50 border-yellow-200 p-5">
          <p className="text-sm font-medium text-gray-600">Pending BG Checks</p>
          <p className="text-3xl font-bold mt-1 text-yellow-600">{summary.pending}</p>
          <p className="text-xs text-gray-500 mt-1">Awaiting results</p>
        </div>

        {/* Never Checked */}
        <div className="rounded-lg border bg-gray-50 border-brand-secondary p-5">
          <p className="text-sm font-medium text-gray-600">Never Checked</p>
          <p className="text-3xl font-bold mt-1 text-gray-600">{summary.never_checked}</p>
          <p className="text-xs text-gray-500 mt-1">No background check on file</p>
        </div>
      </div>

      {/* Needs Attention Section */}
      <div className="mb-8">
        <h2 className="text-lg font-semibold text-brand-primary uppercase tracking-wide mb-4">Needs Attention</h2>
        <DataTable<NeedsAttention>
          columns={attentionColumns}
          rows={sortedAttention}
          rowKey={(vol) => vol.id}
          emptyState={{
            text: (
              <div className="bg-green-50 border border-green-200 rounded-lg p-8 text-center">
                <svg className="w-12 h-12 text-green-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p className="text-green-800 font-medium">All volunteers are compliant!</p>
              </div>
            ),
          }}
        />
      </div>

      {/* Per-Team Breakdown */}
      <div>
        <h2 className="text-lg font-semibold text-brand-primary uppercase tracking-wide mb-4">Per-Team Breakdown</h2>
        <DataTable<TeamBreakdown>
          columns={teamColumns}
          rows={team_breakdown}
          rowKey={(team) => team.team_id}
          emptyState="No team data available."
        />
      </div>
    </div>
  );
};

export default ComplianceDashboard;

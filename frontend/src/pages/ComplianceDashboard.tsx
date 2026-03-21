import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

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
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto mb-4" />
          <p className="text-gray-500 text-sm">Loading compliance data...</p>
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
      {/* Page Title */}
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-gray-900">Volunteer Compliance</h1>
        <p className="mt-1 text-sm text-gray-500">
          Overall compliance rate:{' '}
          <span className={`text-3xl font-bold ${complianceRateColor(summary.compliance_rate)}`}>
            {summary.compliance_rate.toFixed(1)}%
          </span>
        </p>
      </div>

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
            className="text-sm font-medium text-blue-700 hover:text-blue-900 underline"
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
        <div className="rounded-lg border bg-gray-50 border-gray-200 p-5">
          <p className="text-sm font-medium text-gray-600">Never Checked</p>
          <p className="text-3xl font-bold mt-1 text-gray-600">{summary.never_checked}</p>
          <p className="text-xs text-gray-500 mt-1">No background check on file</p>
        </div>
      </div>

      {/* Needs Attention Section */}
      <div className="mb-8">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Needs Attention</h2>
        {sortedAttention.length === 0 ? (
          <div className="bg-green-50 border border-green-200 rounded-lg p-8 text-center">
            <svg className="w-12 h-12 text-green-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p className="text-green-800 font-medium">All volunteers are compliant!</p>
          </div>
        ) : (
          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">BG Check Status</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Check Date</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Days Since Check</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {sortedAttention.map(vol => (
                    <tr key={vol.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        {vol.first_name} {vol.last_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{vol.email}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{vol.team_name}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">
                        {statusBadge(vol.background_check_status)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {vol.background_check_date
                          ? new Date(vol.background_check_date).toLocaleDateString()
                          : 'N/A'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {vol.days_since_check != null ? `${vol.days_since_check} days` : 'N/A'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {/* Per-Team Breakdown */}
      <div>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Per-Team Breakdown</h2>
        {team_breakdown.length === 0 ? (
          <p className="text-gray-500 text-sm">No team data available.</p>
        ) : (
          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Age Group</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Division</th>
                    <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Volunteers</th>
                    <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Cleared</th>
                    <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Pending</th>
                    <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Expired</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Compliance Rate</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {team_breakdown.map(team => (
                    <tr key={team.team_id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        {team.team_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{team.age_group}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{team.division}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">{team.volunteer_count}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-center text-green-600 font-medium">{team.cleared}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-center text-yellow-600 font-medium">{team.pending_bg}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-center text-red-600 font-medium">{team.expired_bg}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">
                        <div className="flex items-center gap-3">
                          <div className="flex-1 bg-gray-200 rounded-full h-2 w-24">
                            <div
                              className={`h-2 rounded-full ${complianceRateBg(team.compliance_rate)}`}
                              style={{ width: `${Math.min(team.compliance_rate, 100)}%` }}
                            />
                          </div>
                          <span className={`text-sm font-medium ${complianceRateColor(team.compliance_rate)}`}>
                            {team.compliance_rate.toFixed(0)}%
                          </span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default ComplianceDashboard;

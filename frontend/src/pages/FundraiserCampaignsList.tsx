import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import PageHeader from '../components/ui/PageHeader';

interface Campaign {
  id: number;
  title: string;
  slug: string;
  goal_amount: number;
  amount_raised: number;
  donor_count: number;
  start_date: string;
  end_date: string;
  status: string;
  progress_percent: number;
  days_remaining: number;
  is_active: boolean;
  created_by_name: string;
}

interface CampaignStats {
  total_campaigns: number;
  active_campaigns: number;
  ended_campaigns: number;
  total_raised: number;
  total_donors: number;
}

interface FundraiserCampaignsListProps {
  clubId: number;
  clubSlug: string;
}

/**
 * FundraiserCampaignsList - Admin list of all fundraiser campaigns
 * Route: /admin/fundraisers
 */
export const FundraiserCampaignsList: React.FC<FundraiserCampaignsListProps> = ({
  clubId,
  clubSlug
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [stats, setStats] = useState<CampaignStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'all' | 'active' | 'draft' | 'ended'>('all');

  useEffect(() => {
    const fetchData = async () => {
      try {
        // Fetch campaigns and stats in parallel
        const [campaignsRes, statsRes] = await Promise.all([
          fetch(`${API_URL}/api/fundraiser-campaigns.php?action=list&club_id=${clubId}&include_ended=true`),
          fetch(`${API_URL}/api/fundraiser-campaigns.php?action=stats&club_id=${clubId}`)
        ]);

        const campaignsData = await campaignsRes.json();
        const statsData = await statsRes.json();

        setCampaigns(Array.isArray(campaignsData) ? campaignsData : []);
        setStats(statsData);
      } catch (err) {
        console.error('Error fetching campaigns:', err);
      } finally {
        setLoading(false);
      }
    };

    if (clubId) {
      fetchData();
    }
  }, [clubId, API_URL]);

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  };

  const getStatusBadge = (campaign: Campaign) => {
    if (campaign.status === 'draft') {
      return <span className="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">Draft</span>;
    }
    if (campaign.status === 'ended' || campaign.status === 'cancelled') {
      return <span className="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">Ended</span>;
    }
    if (campaign.is_active) {
      return <span className="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded-full">Active</span>;
    }
    return <span className="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 rounded-full">Scheduled</span>;
  };

  const filteredCampaigns = campaigns.filter((campaign) => {
    if (filter === 'all') return true;
    if (filter === 'active') return campaign.status === 'active' && campaign.is_active;
    if (filter === 'draft') return campaign.status === 'draft';
    if (filter === 'ended') return campaign.status === 'ended' || campaign.status === 'cancelled';
    return true;
  });

  if (loading) {
    return (
      <div className="p-6">
        <div className="animate-pulse">
          <div className="h-8 bg-gray-200 rounded w-1/4 mb-6"></div>
          <div className="grid grid-cols-4 gap-4 mb-8">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="h-24 bg-gray-100 rounded-lg"></div>
            ))}
          </div>
          <div className="space-y-4">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-20 bg-gray-100 rounded-lg"></div>
            ))}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <PageHeader
        title="Fundraiser Campaigns"
        subtitle="Create and manage goal-based fundraising campaigns"
        actions={
          <Link
            to="/admin/fundraisers/new"
            className="px-4 py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover flex items-center gap-2"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            New Campaign
          </Link>
        }
      />

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
          <div className="bg-white rounded-lg p-4 border border-gray-100 shadow-sm">
            <p className="text-sm text-gray-500">Total Raised</p>
            <p className="text-2xl font-bold text-brand-primary">{formatCurrency(stats.total_raised)}</p>
          </div>
          <div className="bg-white rounded-lg p-4 border border-gray-100 shadow-sm">
            <p className="text-sm text-gray-500">Total Donors</p>
            <p className="text-2xl font-bold text-gray-900">{stats.total_donors}</p>
          </div>
          <div className="bg-white rounded-lg p-4 border border-gray-100 shadow-sm">
            <p className="text-sm text-gray-500">Active Campaigns</p>
            <p className="text-2xl font-bold text-green-600">{stats.active_campaigns}</p>
          </div>
          <div className="bg-white rounded-lg p-4 border border-gray-100 shadow-sm">
            <p className="text-sm text-gray-500">Total Campaigns</p>
            <p className="text-2xl font-bold text-gray-900">{stats.total_campaigns}</p>
          </div>
        </div>
      )}

      {/* Filter Tabs */}
      <div className="flex gap-2 mb-6">
        {(['all', 'active', 'draft', 'ended'] as const).map((tab) => (
          <button
            key={tab}
            onClick={() => setFilter(tab)}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
              filter === tab
                ? 'bg-brand-primary text-white'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
          >
            {tab.charAt(0).toUpperCase() + tab.slice(1)}
            {tab === 'all' && ` (${campaigns.length})`}
          </button>
        ))}
      </div>

      {/* Campaigns List */}
      {filteredCampaigns.length === 0 ? (
        <div className="bg-white rounded-lg border border-gray-200 p-12 text-center">
          <svg className="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
          </svg>
          <h3 className="text-lg font-medium text-brand-primary mb-1">No campaigns found</h3>
          <p className="text-gray-500 mb-4">
            {filter === 'all'
              ? "Get started by creating your first fundraiser campaign."
              : `No ${filter} campaigns at the moment.`}
          </p>
          {filter === 'all' && (
            <Link
              to="/admin/fundraisers/new"
              className="inline-flex items-center gap-2 px-4 py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
              Create Campaign
            </Link>
          )}
        </div>
      ) : (
        <div className="space-y-4">
          {filteredCampaigns.map((campaign) => (
            <div
              key={campaign.id}
              className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow"
            >
              <div className="flex items-start justify-between">
                <div className="flex-1">
                  <div className="flex items-center gap-3 mb-2">
                    <Link
                      to={`/admin/fundraisers/${campaign.id}`}
                      className="text-lg font-semibold text-gray-900 hover:text-brand-primary"
                    >
                      {campaign.title}
                    </Link>
                    {getStatusBadge(campaign)}
                  </div>

                  {/* Progress bar */}
                  <div className="mb-3">
                    <div className="flex justify-between text-sm mb-1">
                      <span className="font-medium text-brand-primary">
                        {formatCurrency(campaign.amount_raised)} raised
                      </span>
                      <span className="text-gray-500">
                        {campaign.progress_percent.toFixed(0)}% of {formatCurrency(campaign.goal_amount)}
                      </span>
                    </div>
                    <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-brand-primary rounded-full transition-all duration-500"
                        style={{ width: `${Math.min(100, campaign.progress_percent)}%` }}
                      />
                    </div>
                  </div>

                  {/* Stats row */}
                  <div className="flex items-center gap-6 text-sm text-gray-500">
                    <span>{campaign.donor_count} donors</span>
                    <span>
                      {campaign.is_active && campaign.days_remaining > 0
                        ? `${Math.ceil(campaign.days_remaining)} days left`
                        : `Ends ${formatDate(campaign.end_date)}`}
                    </span>
                    <span>
                      {formatDate(campaign.start_date)} - {formatDate(campaign.end_date)}
                    </span>
                  </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2 ml-4">
                  <Link
                    to={`/admin/fundraisers/${campaign.id}`}
                    className="p-2 text-gray-400 hover:text-brand-primary hover:bg-gray-100 rounded-lg"
                    title="View Dashboard"
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                  </Link>
                  <Link
                    to={`/admin/fundraisers/${campaign.id}/edit`}
                    className="p-2 text-gray-400 hover:text-brand-primary hover:bg-gray-100 rounded-lg"
                    title="Edit"
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </Link>
                  <a
                    href={`/donate/${clubSlug}/campaign/${campaign.slug}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="p-2 text-gray-400 hover:text-brand-primary hover:bg-gray-100 rounded-lg"
                    title="View Public Page"
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                  </a>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default FundraiserCampaignsList;

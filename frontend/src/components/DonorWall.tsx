import React, { useState, useEffect } from 'react';

interface Donor {
  id: number;
  display_name: string;
  amount: number | null;
  comment: string | null;
  created_at: string;
}

interface DonorWallProps {
  campaignId: number;
  showDonorNames?: boolean;
  showDonorAmounts?: boolean;
  allowComments?: boolean;
}

/**
 * DonorWall - Displays recent donors for a fundraiser campaign
 */
export const DonorWall: React.FC<DonorWallProps> = ({
  campaignId,
  showDonorNames = true,
  showDonorAmounts = true,
  allowComments = true
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [donors, setDonors] = useState<Donor[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAll, setShowAll] = useState(false);

  useEffect(() => {
    const fetchDonors = async () => {
      try {
        const response = await fetch(
          `${API_URL}/api/campaign-donations.php?action=donor-wall&campaign_id=${campaignId}&limit=20`
        );
        const data = await response.json();
        setDonors(data);
      } catch (err) {
        console.error('Error fetching donors:', err);
      } finally {
        setLoading(false);
      }
    };

    if (campaignId) {
      fetchDonors();
    }
  }, [campaignId, API_URL]);

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  };

  const formatTimeAgo = (dateString: string) => {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString();
  };

  const getInitials = (name: string) => {
    if (name === 'Anonymous') return '?';
    const parts = name.split(' ').filter(Boolean);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return parts[0]?.[0]?.toUpperCase() || '?';
  };

  if (loading) {
    return (
      <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
        <h3 className="text-lg font-semibold text-gray-900 mb-4">Recent Supporters</h3>
        <div className="animate-pulse space-y-4">
          {[1, 2, 3].map((i) => (
            <div key={i} className="flex items-center gap-3">
              <div className="w-10 h-10 bg-gray-200 rounded-full" />
              <div className="flex-1">
                <div className="h-4 bg-gray-200 rounded w-1/3 mb-1" />
                <div className="h-3 bg-gray-100 rounded w-1/4" />
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (donors.length === 0) {
    return (
      <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
        <h3 className="text-lg font-semibold text-gray-900 mb-4">Recent Supporters</h3>
        <div className="text-center py-8 text-gray-500">
          <svg className="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
          <p className="font-medium">Be the first to donate!</p>
          <p className="text-sm">Your support means the world to us.</p>
        </div>
      </div>
    );
  }

  const displayedDonors = showAll ? donors : donors.slice(0, 5);

  return (
    <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
      <h3 className="text-lg font-semibold text-gray-900 mb-4">
        Recent Supporters
        <span className="text-sm font-normal text-gray-500 ml-2">({donors.length})</span>
      </h3>

      <div className="space-y-4">
        {displayedDonors.map((donor) => (
          <div key={donor.id} className="flex items-start gap-3">
            {/* Avatar */}
            <div className="w-10 h-10 rounded-full bg-brand-primary/10 flex items-center justify-center text-brand-primary font-semibold text-sm flex-shrink-0">
              {getInitials(donor.display_name)}
            </div>

            {/* Content */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center justify-between gap-2">
                <p className="font-medium text-gray-900 truncate">
                  {showDonorNames ? donor.display_name : 'Supporter'}
                </p>
                {showDonorAmounts && donor.amount && (
                  <span className="text-brand-primary font-semibold whitespace-nowrap">
                    {formatCurrency(donor.amount)}
                  </span>
                )}
              </div>
              {allowComments && donor.comment && (
                <p className="text-sm text-gray-600 italic mt-1">"{donor.comment}"</p>
              )}
              <p className="text-xs text-gray-400 mt-1">{formatTimeAgo(donor.created_at)}</p>
            </div>
          </div>
        ))}
      </div>

      {donors.length > 5 && (
        <button
          onClick={() => setShowAll(!showAll)}
          className="w-full mt-4 py-2 text-brand-primary hover:text-brand-primary-hover text-sm font-medium"
        >
          {showAll ? 'Show less' : `Show all ${donors.length} supporters`}
        </button>
      )}
    </div>
  );
};

export default DonorWall;

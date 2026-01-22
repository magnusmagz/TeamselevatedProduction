import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { CampaignProgress } from '../components/CampaignProgress';

interface CampaignUpdate {
  id: number;
  title: string;
  content: string;
  created_at: string;
}

interface Campaign {
  id: number;
  club_id: number;
  title: string;
  slug: string;
  description: string;
  image_url: string | null;
  goal_amount: number;
  amount_raised: number;
  donor_count: number;
  start_date: string;
  end_date: string;
  show_donor_names: boolean;
  show_donor_amounts: boolean;
  show_progress: boolean;
  allow_comments: boolean;
  status: string;
  club_slug: string;
  club_name: string;
  progress_percent: number;
  days_remaining: number;
  is_active: boolean;
  updates?: CampaignUpdate[];
}

interface Donation {
  id: number;
  donor_name: string;
  donor_email: string;
  donor_phone: string | null;
  is_anonymous: boolean;
  amount: number;
  comment: string | null;
  status: string;
  receipt_sent_at: string | null;
  created_at: string;
}

interface FundraiserCampaignDashboardProps {
  clubSlug: string;
  userId: number;
}

/**
 * FundraiserCampaignDashboard - Admin view of campaign with donations
 * Route: /admin/fundraisers/:id
 */
export const FundraiserCampaignDashboard: React.FC<FundraiserCampaignDashboardProps> = ({
  clubSlug,
  userId
}) => {
  const { id } = useParams<{ id: string }>();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [donations, setDonations] = useState<Donation[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'donations' | 'updates'>('donations');
  const [linkCopied, setLinkCopied] = useState(false);

  // Update posting
  const [showUpdateForm, setShowUpdateForm] = useState(false);
  const [updateTitle, setUpdateTitle] = useState('');
  const [updateContent, setUpdateContent] = useState('');
  const [postingUpdate, setPostingUpdate] = useState(false);

  // End campaign
  const [showEndConfirm, setShowEndConfirm] = useState(false);
  const [endingCampaign, setEndingCampaign] = useState(false);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [campaignRes, donationsRes] = await Promise.all([
          fetch(`${API_URL}/api/fundraiser-campaigns.php?action=get&id=${id}`),
          fetch(`${API_URL}/api/campaign-donations.php?action=list&campaign_id=${id}&admin=true`)
        ]);

        const campaignData = await campaignRes.json();
        const donationsData = await donationsRes.json();

        if (campaignData.error) {
          setError(campaignData.error);
        } else {
          setCampaign(campaignData);
        }

        if (donationsData.donations) {
          setDonations(donationsData.donations);
        }
      } catch (err) {
        setError('Failed to load campaign data');
        console.error('Error fetching data:', err);
      } finally {
        setLoading(false);
      }
    };

    if (id) {
      fetchData();
    }
  }, [id, API_URL]);

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  };

  const handleCopyLink = () => {
    if (!campaign) return;
    const url = `${window.location.origin}/donate/${clubSlug}/campaign/${campaign.slug}`;
    navigator.clipboard.writeText(url);
    setLinkCopied(true);
    setTimeout(() => setLinkCopied(false), 2000);
  };

  const handleExport = () => {
    if (!campaign) return;
    window.location.href = `${API_URL}/api/campaign-donations.php?action=export&campaign_id=${campaign.id}`;
  };

  const handlePostUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!campaign || !updateTitle.trim() || !updateContent.trim()) return;

    setPostingUpdate(true);
    try {
      const response = await fetch(
        `${API_URL}/api/fundraiser-campaigns.php?action=post-update`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            campaign_id: campaign.id,
            title: updateTitle.trim(),
            content: updateContent.trim(),
            created_by: userId
          })
        }
      );

      const result = await response.json();

      if (result.success) {
        setUpdateTitle('');
        setUpdateContent('');
        setShowUpdateForm(false);
        // Refresh campaign data
        const res = await fetch(`${API_URL}/api/fundraiser-campaigns.php?action=get&id=${id}`);
        const data = await res.json();
        setCampaign(data);
      }
    } catch (err) {
      console.error('Error posting update:', err);
    } finally {
      setPostingUpdate(false);
    }
  };

  const handleEndCampaign = async () => {
    if (!campaign) return;

    setEndingCampaign(true);
    try {
      const response = await fetch(
        `${API_URL}/api/fundraiser-campaigns.php?action=end`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: campaign.id })
        }
      );

      const result = await response.json();

      if (result.success) {
        setCampaign({ ...campaign, status: 'ended', is_active: false });
        setShowEndConfirm(false);
      }
    } catch (err) {
      console.error('Error ending campaign:', err);
    } finally {
      setEndingCampaign(false);
    }
  };

  const handleResendReceipt = async (donationId: number) => {
    try {
      await fetch(
        `${API_URL}/api/campaign-donations.php?action=resend-receipt`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ donation_id: donationId })
        }
      );
      alert('Receipt sent successfully');
    } catch (err) {
      console.error('Error resending receipt:', err);
      alert('Failed to send receipt');
    }
  };

  if (loading) {
    return (
      <div className="p-6">
        <div className="animate-pulse">
          <div className="h-8 bg-gray-200 rounded w-1/3 mb-6"></div>
          <div className="grid grid-cols-3 gap-6">
            <div className="col-span-2 h-64 bg-gray-100 rounded-lg"></div>
            <div className="h-64 bg-gray-100 rounded-lg"></div>
          </div>
        </div>
      </div>
    );
  }

  if (error || !campaign) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
          <h2 className="text-lg font-medium text-red-800 mb-2">Campaign Not Found</h2>
          <p className="text-red-600 mb-4">{error || 'The campaign could not be loaded.'}</p>
          <Link to="/admin/fundraisers" className="text-brand-primary hover:underline">
            Back to Campaigns
          </Link>
        </div>
      </div>
    );
  }

  const publicUrl = `${window.location.origin}/donate/${clubSlug}/campaign/${campaign.slug}`;

  return (
    <div className="p-6">
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/admin/fundraisers"
          className="text-brand-primary hover:text-brand-primary-hover mb-2 inline-flex items-center gap-1 text-sm"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
          Back to Campaigns
        </Link>

        <div className="flex items-start justify-between">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{campaign.title}</h1>
              {campaign.status === 'active' && campaign.is_active ? (
                <span className="px-3 py-1 text-sm font-medium bg-green-100 text-green-700 rounded-full">Active</span>
              ) : campaign.status === 'draft' ? (
                <span className="px-3 py-1 text-sm font-medium bg-gray-100 text-gray-600 rounded-full">Draft</span>
              ) : (
                <span className="px-3 py-1 text-sm font-medium bg-gray-100 text-gray-600 rounded-full">Ended</span>
              )}
            </div>
            <p className="text-gray-500 mt-1">{campaign.club_name}</p>
          </div>

          <div className="flex items-center gap-2">
            <Link
              to={`/admin/fundraisers/${campaign.id}/edit`}
              className="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 flex items-center gap-2"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              Edit
            </Link>
            <a
              href={publicUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="px-4 py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover flex items-center gap-2"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
              View Page
            </a>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-6">
          {/* Share Link */}
          <div className="bg-white rounded-lg border border-gray-200 p-4">
            <label className="block text-sm font-medium text-gray-700 mb-2">Share Link</label>
            <div className="flex gap-2">
              <input
                type="text"
                value={publicUrl}
                readOnly
                className="flex-1 bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono text-gray-600"
              />
              <button
                onClick={handleCopyLink}
                className="px-4 py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover"
              >
                {linkCopied ? 'Copied!' : 'Copy'}
              </button>
            </div>
          </div>

          {/* Tabs */}
          <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div className="border-b border-gray-200">
              <div className="flex">
                <button
                  onClick={() => setActiveTab('donations')}
                  className={`px-6 py-3 font-medium text-sm ${
                    activeTab === 'donations'
                      ? 'text-brand-primary border-b-2 border-brand-primary'
                      : 'text-gray-500 hover:text-gray-700'
                  }`}
                >
                  Donations ({donations.length})
                </button>
                <button
                  onClick={() => setActiveTab('updates')}
                  className={`px-6 py-3 font-medium text-sm ${
                    activeTab === 'updates'
                      ? 'text-brand-primary border-b-2 border-brand-primary'
                      : 'text-gray-500 hover:text-gray-700'
                  }`}
                >
                  Updates ({campaign.updates?.length || 0})
                </button>
              </div>
            </div>

            <div className="p-4">
              {activeTab === 'donations' && (
                <div>
                  {/* Export button */}
                  {donations.length > 0 && (
                    <div className="mb-4 flex justify-end">
                      <button
                        onClick={handleExport}
                        className="px-4 py-2 text-sm border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 flex items-center gap-2"
                      >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Export CSV
                      </button>
                    </div>
                  )}

                  {/* Donations list */}
                  {donations.length === 0 ? (
                    <div className="text-center py-8 text-gray-500">
                      <p>No donations yet. Share your campaign to get started!</p>
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {donations.map((donation) => (
                        <div
                          key={donation.id}
                          className={`border rounded-lg p-4 ${
                            donation.status === 'succeeded' ? 'border-gray-200' : 'border-red-200 bg-red-50'
                          }`}
                        >
                          <div className="flex items-start justify-between">
                            <div>
                              <div className="flex items-center gap-2">
                                <span className="font-medium text-gray-900">
                                  {donation.is_anonymous ? 'Anonymous' : donation.donor_name}
                                </span>
                                <span className="text-lg font-semibold text-brand-primary">
                                  {formatCurrency(donation.amount)}
                                </span>
                                {donation.status !== 'succeeded' && (
                                  <span className="px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700 rounded">
                                    {donation.status}
                                  </span>
                                )}
                              </div>
                              <p className="text-sm text-gray-500">{donation.donor_email}</p>
                              {donation.comment && (
                                <p className="text-sm text-gray-600 mt-2 italic">"{donation.comment}"</p>
                              )}
                              <p className="text-xs text-gray-400 mt-1">{formatDate(donation.created_at)}</p>
                            </div>
                            {donation.status === 'succeeded' && (
                              <button
                                onClick={() => handleResendReceipt(donation.id)}
                                className="text-sm text-brand-primary hover:text-brand-primary-hover"
                              >
                                Resend Receipt
                              </button>
                            )}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {activeTab === 'updates' && (
                <div>
                  {/* Post update form */}
                  {showUpdateForm ? (
                    <form onSubmit={handlePostUpdate} className="mb-6 border border-gray-200 rounded-lg p-4">
                      <h3 className="font-medium text-gray-900 mb-3">Post an Update</h3>
                      <div className="space-y-3">
                        <input
                          type="text"
                          value={updateTitle}
                          onChange={(e) => setUpdateTitle(e.target.value)}
                          placeholder="Update title"
                          required
                          className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                        />
                        <textarea
                          value={updateContent}
                          onChange={(e) => setUpdateContent(e.target.value)}
                          placeholder="Share progress, thank donors, or give updates..."
                          required
                          rows={4}
                          className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-brand-primary focus:border-transparent resize-none"
                        />
                        <div className="flex gap-2">
                          <button
                            type="button"
                            onClick={() => setShowUpdateForm(false)}
                            className="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                          >
                            Cancel
                          </button>
                          <button
                            type="submit"
                            disabled={postingUpdate}
                            className="px-4 py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover disabled:opacity-50"
                          >
                            {postingUpdate ? 'Posting...' : 'Post Update'}
                          </button>
                        </div>
                      </div>
                    </form>
                  ) : (
                    <button
                      onClick={() => setShowUpdateForm(true)}
                      className="mb-6 px-4 py-2 border-2 border-dashed border-gray-300 text-gray-600 rounded-lg w-full hover:border-brand-primary hover:text-brand-primary"
                    >
                      + Post an Update
                    </button>
                  )}

                  {/* Updates list */}
                  {(!campaign.updates || campaign.updates.length === 0) && !showUpdateForm ? (
                    <div className="text-center py-8 text-gray-500">
                      <p>No updates posted yet. Keep your donors informed!</p>
                    </div>
                  ) : (
                    <div className="space-y-4">
                      {campaign.updates?.map((update) => (
                        <div key={update.id} className="border-l-4 border-brand-primary pl-4 py-2">
                          <h4 className="font-medium text-gray-900">{update.title}</h4>
                          <p className="text-sm text-gray-500 mb-2">{formatDate(update.created_at)}</p>
                          <p className="text-gray-600 whitespace-pre-wrap">{update.content}</p>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* Progress */}
          <CampaignProgress
            amountRaised={campaign.amount_raised}
            goalAmount={campaign.goal_amount}
            donorCount={campaign.donor_count}
            daysRemaining={campaign.days_remaining}
            showProgress={true}
          />

          {/* Campaign Details */}
          <div className="bg-white rounded-lg border border-gray-200 p-4">
            <h3 className="font-semibold text-gray-900 mb-3">Campaign Details</h3>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-500">Start Date</span>
                <span className="text-gray-900">
                  {new Date(campaign.start_date).toLocaleDateString()}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">End Date</span>
                <span className="text-gray-900">
                  {new Date(campaign.end_date).toLocaleDateString()}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">Status</span>
                <span className="text-gray-900 capitalize">{campaign.status}</span>
              </div>
            </div>
          </div>

          {/* End Campaign */}
          {campaign.status === 'active' && campaign.is_active && (
            <div className="bg-white rounded-lg border border-gray-200 p-4">
              <h3 className="font-semibold text-gray-900 mb-3">Manage Campaign</h3>
              {showEndConfirm ? (
                <div className="space-y-3">
                  <p className="text-sm text-gray-600">
                    Are you sure you want to end this campaign? This action cannot be undone.
                  </p>
                  <div className="flex gap-2">
                    <button
                      onClick={() => setShowEndConfirm(false)}
                      className="flex-1 px-3 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50"
                    >
                      Cancel
                    </button>
                    <button
                      onClick={handleEndCampaign}
                      disabled={endingCampaign}
                      className="flex-1 px-3 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 disabled:opacity-50"
                    >
                      {endingCampaign ? 'Ending...' : 'End Campaign'}
                    </button>
                  </div>
                </div>
              ) : (
                <button
                  onClick={() => setShowEndConfirm(true)}
                  className="w-full px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm hover:bg-red-50"
                >
                  End Campaign Early
                </button>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default FundraiserCampaignDashboard;

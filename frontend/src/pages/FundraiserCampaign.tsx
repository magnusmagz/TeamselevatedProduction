import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { CampaignProgress } from '../components/CampaignProgress';
import { DonorWall } from '../components/DonorWall';
import { DonationForm } from '../components/DonationForm';

interface CampaignUpdate {
  id: number;
  title: string;
  content: string;
  author_name: string;
  created_at: string;
}

interface Campaign {
  id: number;
  club_id: number;
  title: string;
  slug: string;
  description: string;
  image_url: string | null;
  image_data: string | null;
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
  updates: CampaignUpdate[];
}

/**
 * FundraiserCampaign - Public campaign page with donation form
 * Route: /donate/:clubSlug/campaign/:campaignSlug
 */
export const FundraiserCampaign: React.FC = () => {
  const { clubSlug, campaignSlug } = useParams<{ clubSlug: string; campaignSlug: string }>();
  const navigate = useNavigate();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [linkCopied, setLinkCopied] = useState(false);

  useEffect(() => {
    const fetchCampaign = async () => {
      try {
        const response = await fetch(
          `${API_URL}/api/fundraiser-campaigns.php?action=get&slug=${campaignSlug}&club_slug=${clubSlug}`
        );
        const data = await response.json();

        if (data.error) {
          setError(data.error);
        } else {
          setCampaign(data);
        }
      } catch (err) {
        setError('Failed to load campaign. Please try again.');
        console.error('Error fetching campaign:', err);
      } finally {
        setLoading(false);
      }
    };

    if (clubSlug && campaignSlug) {
      fetchCampaign();
    }
  }, [clubSlug, campaignSlug, API_URL]);

  const handleDonationSuccess = (donationId: number) => {
    navigate(`/donate/thank-you/${donationId}`);
  };

  const handleCopyLink = () => {
    navigator.clipboard.writeText(window.location.href);
    setLinkCopied(true);
    setTimeout(() => setLinkCopied(false), 2000);
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'long',
      day: 'numeric',
      year: 'numeric'
    });
  };

  // Loading state
  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-primary mx-auto mb-4"></div>
          <p className="text-gray-600">Loading campaign...</p>
        </div>
      </div>
    );
  }

  // Error state
  if (error || !campaign) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="bg-white rounded-lg shadow-lg p-8 max-w-md text-center">
          <div className="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg className="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
          <h1 className="text-xl font-bold text-gray-900 mb-2">Campaign Not Found</h1>
          <p className="text-gray-600 mb-4">{error || 'The campaign you are looking for does not exist or has ended.'}</p>
          <a href="/" className="text-brand-primary hover:underline">Return to Home</a>
        </div>
      </div>
    );
  }

  // Campaign ended state
  if (campaign.status === 'ended' || campaign.status === 'cancelled') {
    return (
      <div className="min-h-screen bg-gray-50">
        {/* Hero Image */}
        {(campaign.image_data || campaign.image_url) && (
          <div className="w-full h-64 md:h-80 bg-gray-200 relative">
            <img
              src={campaign.image_data || campaign.image_url || ''}
              alt={campaign.title}
              className="w-full h-full object-cover"
            />
            <div className="absolute inset-0 bg-black/30" />
          </div>
        )}

        <div className="container mx-auto px-4 py-8">
          <div className="max-w-2xl mx-auto text-center">
            <div className="bg-white rounded-lg shadow-lg p-8">
              <div className="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg className="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <h1 className="text-2xl font-bold text-gray-900 mb-2">{campaign.title}</h1>
              <p className="text-gray-500 mb-4">by {campaign.club_name}</p>
              <p className="text-gray-600 mb-6">This campaign has ended.</p>

              {/* Final stats */}
              <div className="bg-gray-50 rounded-lg p-6">
                <div className="text-3xl font-bold text-brand-primary mb-1">
                  ${campaign.amount_raised.toLocaleString()}
                </div>
                <p className="text-gray-500">
                  raised of ${campaign.goal_amount.toLocaleString()} goal
                </p>
                <p className="text-sm text-gray-400 mt-2">
                  {campaign.donor_count} {campaign.donor_count === 1 ? 'donor' : 'donors'}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Hero Image */}
      {(campaign.image_data || campaign.image_url) ? (
        <div className="w-full h-64 md:h-96 bg-gray-200 relative">
          <img
            src={campaign.image_data || campaign.image_url || ''}
            alt={campaign.title}
            className="w-full h-full object-cover"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent" />
          <div className="absolute bottom-0 left-0 right-0 p-6 text-white">
            <div className="container mx-auto">
              <p className="text-white/70 mb-1">{campaign.club_name}</p>
              <h1 className="text-3xl md:text-4xl font-bold">{campaign.title}</h1>
            </div>
          </div>
        </div>
      ) : (
        <div className="bg-brand-primary text-white py-12">
          <div className="container mx-auto px-4">
            <p className="text-white/70 mb-1">{campaign.club_name}</p>
            <h1 className="text-3xl md:text-4xl font-bold">{campaign.title}</h1>
          </div>
        </div>
      )}

      <div className="container mx-auto px-4 py-8">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Main Content */}
          <div className="lg:col-span-2 space-y-6">
            {/* Description */}
            <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
              <h2 className="text-lg font-semibold text-gray-900 mb-4">About this campaign</h2>
              <div className="prose prose-gray max-w-none">
                {campaign.description ? (
                  <p className="text-gray-600 whitespace-pre-wrap">{campaign.description}</p>
                ) : (
                  <p className="text-gray-400 italic">No description provided.</p>
                )}
              </div>
              <div className="mt-4 pt-4 border-t border-gray-100 flex items-center gap-4 text-sm text-gray-500">
                <span>Ends {formatDate(campaign.end_date)}</span>
              </div>
            </div>

            {/* Campaign Updates */}
            {campaign.updates && campaign.updates.length > 0 && (
              <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">
                  Campaign Updates
                  <span className="text-sm font-normal text-gray-500 ml-2">({campaign.updates.length})</span>
                </h2>
                <div className="space-y-4">
                  {campaign.updates.map((update) => (
                    <div key={update.id} className="border-l-4 border-brand-primary pl-4">
                      <h3 className="font-medium text-gray-900">{update.title}</h3>
                      <p className="text-sm text-gray-500 mb-2">
                        by {update.author_name} on {formatDate(update.created_at)}
                      </p>
                      <p className="text-gray-600 whitespace-pre-wrap">{update.content}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Donor Wall - on mobile, show after main content */}
            <div className="lg:hidden">
              <DonorWall
                campaignId={campaign.id}
                showDonorNames={campaign.show_donor_names}
                showDonorAmounts={campaign.show_donor_amounts}
                allowComments={campaign.allow_comments}
              />
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
              showProgress={campaign.show_progress}
            />

            {/* Share buttons */}
            <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
              <h3 className="font-semibold text-gray-900 mb-3">Share this campaign</h3>
              <div className="flex gap-2">
                <button
                  onClick={handleCopyLink}
                  className="flex-1 flex items-center justify-center gap-2 py-2 px-4 bg-gray-100 hover:bg-gray-200 rounded-lg text-gray-700 text-sm font-medium transition-colors"
                >
                  {linkCopied ? (
                    <>
                      <svg className="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                      </svg>
                      Copied!
                    </>
                  ) : (
                    <>
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                      </svg>
                      Copy Link
                    </>
                  )}
                </button>
                <a
                  href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(window.location.href)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="p-2 bg-[#1877F2] hover:bg-[#1877F2]/90 rounded-lg text-white transition-colors"
                  title="Share on Facebook"
                >
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                  </svg>
                </a>
                <a
                  href={`https://twitter.com/intent/tweet?url=${encodeURIComponent(window.location.href)}&text=${encodeURIComponent(`Support ${campaign.title}!`)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="p-2 bg-black hover:bg-gray-800 rounded-lg text-white transition-colors"
                  title="Share on X"
                >
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                  </svg>
                </a>
              </div>
            </div>

            {/* Donation Form */}
            {campaign.is_active && (
              <DonationForm
                campaignId={campaign.id}
                campaignTitle={campaign.title}
                onSuccess={handleDonationSuccess}
              />
            )}

            {!campaign.is_active && (
              <div className="bg-gray-100 rounded-lg p-6 text-center text-gray-500">
                <p>This campaign is not currently accepting donations.</p>
              </div>
            )}

            {/* Donor Wall - on desktop */}
            <div className="hidden lg:block">
              <DonorWall
                campaignId={campaign.id}
                showDonorNames={campaign.show_donor_names}
                showDonorAmounts={campaign.show_donor_amounts}
                allowComments={campaign.allow_comments}
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default FundraiserCampaign;

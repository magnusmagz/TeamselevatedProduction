import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';

interface DonationReceipt {
  donation_id: number;
  amount: number;
  donor_name: string;
  donor_email: string;
  campaign_title: string;
  club_name: string;
  transaction_id: string;
  date: string;
}

/**
 * DonationSuccess - Thank you page after successful donation
 * Route: /donate/thank-you/:donationId
 */
export const DonationSuccess: React.FC = () => {
  const { donationId } = useParams<{ donationId: string }>();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [receipt, setReceipt] = useState<DonationReceipt | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [email, setEmail] = useState('');
  const [emailSubmitted, setEmailSubmitted] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);

  // If we have the email from URL params or session, try to fetch receipt
  useEffect(() => {
    // For now, show a generic success page if we don't have the email
    // Receipt lookup requires email verification for security
    setLoading(false);
  }, [donationId, API_URL]);

  const handleEmailSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(
        `${API_URL}/api/campaign-donations.php?action=receipt&id=${donationId}&email=${encodeURIComponent(email)}`
      );
      const data = await response.json();

      if (data.error) {
        setError(data.error);
      } else {
        setReceipt(data);
        setEmailSubmitted(true);
      }
    } catch (err) {
      setError('Unable to retrieve receipt. Please try again.');
      console.error('Error fetching receipt:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleCopyLink = () => {
    navigator.clipboard.writeText(window.location.origin);
    setLinkCopied(true);
    setTimeout(() => setLinkCopied(false), 2000);
  };

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'long',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-primary mx-auto mb-4"></div>
          <p className="text-gray-600">Loading...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50 py-12">
      <div className="container mx-auto px-4">
        <div className="max-w-lg mx-auto">
          {/* Success Header */}
          <div className="bg-white rounded-lg shadow-lg overflow-hidden">
            <div className="bg-green-500 p-6 text-center">
              <div className="w-16 h-16 bg-white rounded-full flex items-center justify-center mx-auto mb-4">
                <svg className="w-10 h-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <h1 className="text-2xl font-bold text-white">Thank You!</h1>
              <p className="text-white/90 mt-1">Your donation was successful</p>
            </div>

            <div className="p-6">
              {receipt ? (
                // Show receipt details
                <div className="space-y-6">
                  <div className="bg-gray-50 rounded-lg p-4">
                    <p className="text-sm text-gray-500 mb-1">Donation Amount</p>
                    <p className="text-3xl font-bold text-brand-primary">{formatCurrency(receipt.amount)}</p>
                  </div>

                  <div className="space-y-3">
                    <div className="flex justify-between py-2 border-b border-gray-100">
                      <span className="text-gray-500">Campaign</span>
                      <span className="font-medium text-gray-900">{receipt.campaign_title}</span>
                    </div>
                    <div className="flex justify-between py-2 border-b border-gray-100">
                      <span className="text-gray-500">Organization</span>
                      <span className="font-medium text-gray-900">{receipt.club_name}</span>
                    </div>
                    <div className="flex justify-between py-2 border-b border-gray-100">
                      <span className="text-gray-500">Date</span>
                      <span className="text-gray-900">{formatDate(receipt.date)}</span>
                    </div>
                    <div className="flex justify-between py-2 border-b border-gray-100">
                      <span className="text-gray-500">Reference #</span>
                      <span className="font-mono text-sm text-gray-900">DON-{receipt.donation_id}</span>
                    </div>
                    <div className="flex justify-between py-2">
                      <span className="text-gray-500">Transaction ID</span>
                      <span className="font-mono text-xs text-gray-600 break-all">{receipt.transaction_id}</span>
                    </div>
                  </div>

                  <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                    <div className="flex items-start gap-3">
                      <svg className="w-5 h-5 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                      </svg>
                      <div>
                        <p className="font-medium text-green-800">Receipt sent!</p>
                        <p className="text-sm text-green-600">A receipt has been emailed to {receipt.donor_email}</p>
                      </div>
                    </div>
                  </div>
                </div>
              ) : (
                // Show generic success + email lookup form
                <div className="space-y-6">
                  <div className="text-center">
                    <p className="text-gray-600 mb-4">
                      Your generous contribution helps make a difference. A receipt has been sent to your email address.
                    </p>
                  </div>

                  {/* Email lookup form */}
                  <div className="bg-gray-50 rounded-lg p-4">
                    <p className="text-sm font-medium text-gray-700 mb-3">View your receipt</p>
                    <form onSubmit={handleEmailSubmit} className="flex gap-2">
                      <input
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="Enter your email"
                        required
                        className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                      />
                      <button
                        type="submit"
                        className="px-4 py-2 bg-brand-primary text-white rounded-lg text-sm font-medium hover:bg-brand-primary-hover"
                      >
                        View
                      </button>
                    </form>
                    {error && (
                      <p className="text-red-600 text-sm mt-2">{error}</p>
                    )}
                  </div>
                </div>
              )}

              {/* Share Section */}
              <div className="mt-6 pt-6 border-t border-gray-200">
                <p className="font-medium text-gray-900 mb-3">Help spread the word</p>
                <p className="text-sm text-gray-600 mb-4">
                  Share this campaign with friends and family to help reach the goal!
                </p>
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
                    href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(window.location.origin)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="p-2 bg-[#1877F2] hover:bg-[#1877F2]/90 rounded-lg text-white transition-colors"
                  >
                    <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                    </svg>
                  </a>
                  <a
                    href={`https://twitter.com/intent/tweet?text=${encodeURIComponent('I just supported a great cause!')}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="p-2 bg-black hover:bg-gray-800 rounded-lg text-white transition-colors"
                  >
                    <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                    </svg>
                  </a>
                </div>
              </div>
            </div>
          </div>

          {/* Back to home */}
          <div className="text-center mt-6">
            <a
              href="/"
              className="text-brand-primary hover:text-brand-primary-hover font-medium"
            >
              Return to Home
            </a>
          </div>
        </div>
      </div>
    </div>
  );
};

export default DonationSuccess;

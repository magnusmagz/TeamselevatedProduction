import React, { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';

/**
 * ContributePage — the public "sphere of influence" page (Phase 4).
 * Anyone with the link can chip in toward a child's program fees. Payment is
 * a Stripe-hosted checkout; the amount is capped at the remaining balance and
 * the link closes automatically at goal. Not a donation page: contributions
 * are payments toward fees owed to the club and are not tax-deductible.
 */

interface CampaignState {
  display_name: string;
  message: string | null;
  club_name: string | null;
  status: 'active' | 'completed' | 'closed' | 'expired';
  goal: number;
  raised: number;
  remaining: number;
  contributor_count: number;
}

const PRESET_AMOUNTS = [25, 50, 100];

export const ContributePage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const { token } = useParams<{ token: string }>();

  const [campaign, setCampaign] = useState<CampaignState | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [loading, setLoading] = useState(true);

  const [amount, setAmount] = useState<number | ''>('');
  const [customAmount, setCustomAmount] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [comment, setComment] = useState('');
  const [anonymous, setAnonymous] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const returnLeg = new URLSearchParams(window.location.search).get('c');

  const fetchState = useCallback(async () => {
    try {
      const res = await fetch(`${API_URL}/api/contribute.php?action=state&token=${token}`);
      if (res.status === 404) {
        setNotFound(true);
        return;
      }
      const data = await res.json();
      if (data.success) setCampaign(data.campaign);
    } catch {
      setError('Could not load this page — please try again.');
    } finally {
      setLoading(false);
    }
  }, [API_URL, token]);

  useEffect(() => {
    fetchState();
  }, [fetchState]);

  // After returning from Stripe, the webhook can lag the redirect — re-poll so
  // the progress bar reflects the new contribution.
  useEffect(() => {
    if (returnLeg !== 'success') return;
    const timers = [3000, 8000, 15000].map((ms) => setTimeout(fetchState, ms));
    return () => timers.forEach(clearTimeout);
  }, [returnLeg, fetchState]);

  const chosenAmount = customAmount !== '' ? parseFloat(customAmount) || 0 : (amount || 0);

  const contribute = async () => {
    if (!campaign) return;
    if (chosenAmount < 1) {
      setError('Please choose an amount of at least $1.');
      return;
    }
    if (chosenAmount > campaign.remaining) {
      setError(`Only $${campaign.remaining.toFixed(2)} is still needed.`);
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/api/contribute.php?action=checkout&token=${token}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ amount: chosenAmount, name, email, anonymous, comment }),
      });
      const data = await res.json();
      if (res.ok && data.url) {
        window.location.href = data.url;
        return;
      }
      setError(data.error || 'Could not start payment — please try again.');
    } catch {
      setError('Could not start payment — please try again.');
    }
    setSubmitting(false);
  };

  const progressPercent = campaign && campaign.goal > 0
    ? Math.min(100, (campaign.raised / campaign.goal) * 100)
    : 0;

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
      </div>
    );
  }

  if (notFound || !campaign) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-8 max-w-md text-center">
          <h1 className="text-xl font-semibold text-gray-900 mb-2">This link isn't available</h1>
          <p className="text-gray-600">
            {error || 'It may have been closed by the family, or the address may be incomplete.'}
          </p>
        </div>
      </div>
    );
  }

  const goalReached = campaign.status !== 'active';

  return (
    <div className="min-h-screen bg-gray-50 py-8 px-4">
      <div className="max-w-lg mx-auto space-y-4">

        {returnLeg === 'success' && (
          <div className="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            Thank you! Your contribution was received — a receipt is on its way to your email.
          </div>
        )}
        {returnLeg === 'cancelled' && (
          <div className="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            Checkout was cancelled — no payment was made.
          </div>
        )}

        <div className="bg-white rounded-lg shadow-sm border border-gray-100 p-6">
          <p className="text-xs font-semibold uppercase tracking-wide text-brand-primary mb-1">
            {campaign.club_name || 'Team fundraising'}
          </p>
          <h1 className="text-2xl font-bold text-gray-900 mb-2">{campaign.display_name}</h1>
          {campaign.message && <p className="text-gray-600 mb-4">{campaign.message}</p>}

          <div className="mb-2 flex items-baseline gap-2">
            <span className="text-3xl font-bold text-brand-primary">
              ${campaign.raised.toLocaleString('en-US', { minimumFractionDigits: 0 })}
            </span>
            <span className="text-gray-500">
              raised of ${campaign.goal.toLocaleString('en-US', { minimumFractionDigits: 0 })} goal
            </span>
          </div>
          <div className="w-full bg-gray-100 rounded-full h-3 mb-2">
            <div
              className="bg-brand-primary h-3 rounded-full transition-all"
              style={{ width: `${progressPercent}%` }}
            ></div>
          </div>
          <p className="text-sm text-gray-500">
            {campaign.contributor_count > 0 && (
              <>{campaign.contributor_count} {campaign.contributor_count === 1 ? 'person has' : 'people have'} chipped in · </>
            )}
            {goalReached ? 'Goal reached!' : `$${campaign.remaining.toFixed(2)} to go`}
          </p>
        </div>

        {goalReached ? (
          <div className="bg-white rounded-lg shadow-sm border border-gray-100 p-6 text-center">
            <p className="text-2xl mb-2">🎉</p>
            <h2 className="text-lg font-semibold text-gray-900 mb-1">The goal has been reached!</h2>
            <p className="text-gray-600 text-sm">
              Thanks to everyone who chipped in — this one's fully covered.
            </p>
          </div>
        ) : (
          <div className="bg-white rounded-lg shadow-sm border border-gray-100 p-6">
            <h2 className="font-semibold text-gray-900 mb-3">Chip in</h2>

            <div className="grid grid-cols-4 gap-2 mb-3">
              {PRESET_AMOUNTS.map((preset) => (
                <button
                  key={preset}
                  type="button"
                  disabled={preset > campaign.remaining}
                  onClick={() => { setAmount(preset); setCustomAmount(''); }}
                  className={`py-2 rounded-lg border text-sm font-medium transition-colors disabled:opacity-40 ${
                    amount === preset && customAmount === ''
                      ? 'border-brand-primary bg-brand-primary text-white'
                      : 'border-gray-300 text-gray-700 hover:border-brand-primary'
                  }`}
                >
                  ${preset}
                </button>
              ))}
              <input
                type="text"
                inputMode="decimal"
                placeholder="Other"
                value={customAmount}
                onChange={(e) => { setCustomAmount(e.target.value.replace(/[^\d.]/g, '')); setAmount(''); }}
                className="py-2 px-2 rounded-lg border border-gray-300 text-sm text-center focus:border-brand-primary focus:outline-none"
              />
            </div>

            <div className="space-y-3 mb-4">
              <input
                type="text" placeholder="Your name (optional)" value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full py-2 px-3 rounded-lg border border-gray-300 text-sm focus:border-brand-primary focus:outline-none"
              />
              <input
                type="email" placeholder="Email for your receipt (optional)" value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full py-2 px-3 rounded-lg border border-gray-300 text-sm focus:border-brand-primary focus:outline-none"
              />
              <input
                type="text" placeholder="Message to the family (optional)" value={comment}
                onChange={(e) => setComment(e.target.value)} maxLength={200}
                className="w-full py-2 px-3 rounded-lg border border-gray-300 text-sm focus:border-brand-primary focus:outline-none"
              />
              <label className="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" checked={anonymous} onChange={(e) => setAnonymous(e.target.checked)} />
                Keep my name private
              </label>
            </div>

            {error && (
              <div className="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
                {error}
              </div>
            )}

            <button
              onClick={contribute}
              disabled={submitting || chosenAmount < 1}
              className="w-full py-3 bg-brand-primary text-white rounded-lg font-semibold hover:opacity-90 transition-opacity disabled:opacity-50"
            >
              {submitting
                ? 'Redirecting to secure checkout…'
                : `Contribute${chosenAmount >= 1 ? ` $${chosenAmount.toFixed(2)}` : ''}`}
            </button>

            <p className="mt-3 text-xs text-gray-500 leading-relaxed">
              Your payment goes to {campaign.club_name || 'the club'} toward {campaign.display_name.split('—')[0].trim()}'s
              program fees. Contributions are not tax-deductible. Payments are processed securely by Stripe.
            </p>
          </div>
        )}
      </div>
    </div>
  );
};

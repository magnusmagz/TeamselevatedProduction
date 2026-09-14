import React, { useState } from 'react';

interface DonationFormProps {
  campaignId: number;
  campaignTitle: string;
  onError?: (error: string) => void;
}

const PRESET_AMOUNTS = [25, 50, 100, 250];

/**
 * DonationForm - amount + donor details, then Stripe-hosted checkout.
 *
 * No card fields live here (2026-09-14). The form asks the backend for a
 * Checkout Session on the club's connected Stripe account and sends the
 * browser there; Stripe takes the card, the webhook records the donation,
 * and the donor lands back on the campaign page with ?donated=success.
 */
export const DonationForm: React.FC<DonationFormProps> = ({
  campaignId,
  campaignTitle,
  onError
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [amount, setAmount] = useState<number | ''>('');
  const [customAmount, setCustomAmount] = useState<string>('');
  const [donorName, setDonorName] = useState('');
  const [donorEmail, setDonorEmail] = useState('');
  const [donorPhone, setDonorPhone] = useState('');
  const [comment, setComment] = useState('');
  const [isAnonymous, setIsAnonymous] = useState(false);

  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const formatCurrency = (value: number) => {
    return `$${value.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  };

  const handleAmountSelect = (value: number) => {
    setAmount(value);
    setCustomAmount('');
  };

  const handleCustomAmountChange = (value: string) => {
    const numericValue = value.replace(/[^\d.]/g, '');
    setCustomAmount(numericValue);
    const parsed = parseFloat(numericValue);
    if (!isNaN(parsed) && parsed > 0) {
      setAmount(parsed);
    } else {
      setAmount('');
    }
  };

  const getSelectedAmount = (): number => {
    if (typeof amount === 'number') return amount;
    const custom = parseFloat(customAmount);
    return isNaN(custom) ? 0 : custom;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    const donationAmount = getSelectedAmount();
    if (donationAmount < 1) {
      setError('Please enter a donation amount of at least $1');
      return;
    }

    if (!donorName.trim() || !donorEmail.trim()) {
      setError('Please fill in your name and email');
      return;
    }

    setProcessing(true);

    try {
      const response = await fetch(`${API_URL}/api/campaign-donations.php?action=checkout`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          campaign_id: campaignId,
          donor_name: donorName.trim(),
          donor_email: donorEmail.trim(),
          donor_phone: donorPhone.trim() || null,
          is_anonymous: isAnonymous,
          amount: donationAmount,
          comment: comment.trim() || null
        })
      });

      // The error body is the message (400 validation, 409 campaign closed, 503 not set up).
      const result = await response.json();

      if (result.success && result.url) {
        window.location.assign(result.url);
        return; // stay "processing" while the browser leaves for Stripe
      }
      const errorMsg = result.error || 'Unable to start checkout. Please try again.';
      setError(errorMsg);
      onError?.(errorMsg);
    } catch (err) {
      console.error('Donation error:', err);
      const errorMsg = 'An error occurred. Please try again.';
      setError(errorMsg);
      onError?.(errorMsg);
    }
    setProcessing(false);
  };

  return (
    <form onSubmit={handleSubmit} className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
      {/* Header */}
      <div className="bg-brand-primary text-white p-4">
        <h3 className="font-semibold">Make a Donation</h3>
        <p className="text-sm text-white/70">Support {campaignTitle}</p>
      </div>

      <div className="p-6 space-y-6">
        {/* Amount selection */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-3">
            Choose your donation amount
          </label>
          <div className="grid grid-cols-4 gap-2 mb-3">
            {PRESET_AMOUNTS.map((presetAmount) => (
              <button
                key={presetAmount}
                type="button"
                onClick={() => handleAmountSelect(presetAmount)}
                className={`py-3 px-4 rounded-lg font-semibold text-center transition-all ${
                  amount === presetAmount && !customAmount
                    ? 'bg-brand-primary text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                {formatCurrency(presetAmount)}
              </button>
            ))}
          </div>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
            <input
              type="text"
              inputMode="decimal"
              placeholder="Other amount"
              value={customAmount}
              onChange={(e) => handleCustomAmountChange(e.target.value)}
              className={`w-full pl-8 pr-4 py-3 border rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent ${
                customAmount ? 'border-brand-primary' : 'border-gray-300'
              }`}
            />
          </div>
        </div>

        {/* Donor information */}
        <div className="space-y-4">
          <h4 className="font-medium text-brand-primary">Your Information</h4>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
            <input
              type="text"
              value={donorName}
              onChange={(e) => setDonorName(e.target.value)}
              required
              autoComplete="name"
              placeholder="John Smith"
              className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
            <input
              type="email"
              value={donorEmail}
              onChange={(e) => setDonorEmail(e.target.value)}
              required
              autoComplete="email"
              placeholder="john@example.com"
              className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
            />
            <p className="text-xs text-gray-500 mt-1">Your receipt will be sent to this email</p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Phone (optional)</label>
            <input
              type="tel"
              value={donorPhone}
              onChange={(e) => setDonorPhone(e.target.value)}
              autoComplete="tel"
              placeholder="(555) 123-4567"
              className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Leave a message (optional)</label>
            <textarea
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              placeholder="Share why you're supporting this campaign..."
              rows={3}
              maxLength={500}
              className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent resize-none"
            />
          </div>

          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={isAnonymous}
              onChange={(e) => setIsAnonymous(e.target.checked)}
              className="w-4 h-4 text-brand-primary focus:ring-brand-primary border-gray-300 rounded"
            />
            <span className="text-sm text-gray-700">Make my donation anonymous</span>
          </label>
        </div>

        {/* Error message */}
        {error && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm" role="alert">
            {error}
          </div>
        )}

        {/* Submit button */}
        <button
          type="submit"
          disabled={processing || getSelectedAmount() < 1}
          className={`w-full py-4 rounded-lg font-semibold text-white text-lg transition-colors ${
            processing || getSelectedAmount() < 1
              ? 'bg-gray-400 cursor-not-allowed'
              : 'bg-brand-primary hover:bg-brand-primary-hover'
          }`}
        >
          {processing ? (
            <span className="flex items-center justify-center gap-2">
              <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Taking you to secure checkout...
            </span>
          ) : getSelectedAmount() > 0 ? (
            `Donate ${formatCurrency(getSelectedAmount())}`
          ) : (
            'Select an amount'
          )}
        </button>

        <p className="text-xs text-gray-500 text-center">
          Payment is handled by Stripe on a secure checkout page. Your card details never touch our servers.
        </p>
      </div>
    </form>
  );
};

export default DonationForm;

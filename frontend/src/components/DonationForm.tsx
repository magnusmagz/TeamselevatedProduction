import React, { useState } from 'react';

interface DonationFormProps {
  campaignId: number;
  campaignTitle: string;
  onSuccess: (donationId: number) => void;
  onError?: (error: string) => void;
}

const PRESET_AMOUNTS = [25, 50, 100, 250];

/**
 * DonationForm - Guest checkout form for campaign donations
 */
export const DonationForm: React.FC<DonationFormProps> = ({
  campaignId,
  campaignTitle,
  onSuccess,
  onError
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  // Form state
  const [amount, setAmount] = useState<number | ''>('');
  const [customAmount, setCustomAmount] = useState<string>('');
  const [donorName, setDonorName] = useState('');
  const [donorEmail, setDonorEmail] = useState('');
  const [donorPhone, setDonorPhone] = useState('');
  const [comment, setComment] = useState('');
  const [isAnonymous, setIsAnonymous] = useState(false);

  // Card state
  const [cardNumber, setCardNumber] = useState('');
  const [expiryMonth, setExpiryMonth] = useState('');
  const [expiryYear, setExpiryYear] = useState('');
  const [cvv, setCvv] = useState('');

  // Processing state
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const formatCurrency = (value: number) => {
    return `$${value.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  };

  const formatCardNumber = (value: string) => {
    const cleaned = value.replace(/\D/g, '');
    const groups = cleaned.match(/.{1,4}/g) || [];
    return groups.join(' ').substr(0, 19);
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

    if (!cardNumber || !expiryMonth || !expiryYear || !cvv) {
      setError('Please fill in your payment information');
      return;
    }

    setProcessing(true);

    try {
      const response = await fetch(`${API_URL}/api/campaign-donations.php?action=create`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          campaign_id: campaignId,
          donor_name: donorName.trim(),
          donor_email: donorEmail.trim(),
          donor_phone: donorPhone.trim() || null,
          is_anonymous: isAnonymous,
          amount: donationAmount,
          comment: comment.trim() || null,
          payment_method: {
            type: 'card',
            card_number: cardNumber.replace(/\s/g, ''),
            expiry_month: expiryMonth,
            expiry_year: expiryYear,
            cvv: cvv
          }
        })
      });

      const result = await response.json();

      if (result.success) {
        onSuccess(result.donation_id);
      } else {
        const errorMsg = result.error || 'Payment failed. Please try again.';
        setError(errorMsg);
        onError?.(errorMsg);
      }
    } catch (err) {
      console.error('Donation error:', err);
      const errorMsg = 'An error occurred. Please try again.';
      setError(errorMsg);
      onError?.(errorMsg);
    } finally {
      setProcessing(false);
    }
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
              placeholder="john@example.com"
              className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
            />
            <p className="text-xs text-gray-500 mt-1">Receipt will be sent to this email</p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Phone (optional)</label>
            <input
              type="tel"
              value={donorPhone}
              onChange={(e) => setDonorPhone(e.target.value)}
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

        {/* Payment information */}
        <div className="space-y-4">
          <h4 className="font-medium text-brand-primary">Payment Details</h4>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Card Number</label>
            <input
              type="text"
              value={cardNumber}
              onChange={(e) => setCardNumber(formatCardNumber(e.target.value))}
              placeholder="4242 4242 4242 4242"
              maxLength={19}
              required
              className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
            />
          </div>

          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Month</label>
              <input
                type="text"
                value={expiryMonth}
                onChange={(e) => setExpiryMonth(e.target.value.replace(/\D/g, '').substr(0, 2))}
                placeholder="MM"
                maxLength={2}
                required
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Year</label>
              <input
                type="text"
                value={expiryYear}
                onChange={(e) => setExpiryYear(e.target.value.replace(/\D/g, '').substr(0, 2))}
                placeholder="YY"
                maxLength={2}
                required
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">CVV</label>
              <input
                type="text"
                value={cvv}
                onChange={(e) => setCvv(e.target.value.replace(/\D/g, '').substr(0, 4))}
                placeholder="123"
                maxLength={4}
                required
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>
          </div>

          {/* Test card hint */}
          <div className="bg-gray-50 rounded-lg p-3 text-xs text-gray-500">
            <p className="font-medium mb-1">Demo Mode - Test Cards:</p>
            <p>4242 4242 4242 4242 (success)</p>
            <p>4000 0000 0000 0002 (decline)</p>
          </div>
        </div>

        {/* Error message */}
        {error && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm">
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
              Processing...
            </span>
          ) : getSelectedAmount() > 0 ? (
            `Donate ${formatCurrency(getSelectedAmount())}`
          ) : (
            'Select an amount'
          )}
        </button>

        <p className="text-xs text-gray-500 text-center">
          Secure payment processing. Your card details are encrypted.
        </p>
      </div>
    </form>
  );
};

export default DonationForm;

import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

interface PendingPayment {
  id: number;
  athlete_name: string;
  program_name: string;
  item_name: string;
  amount: number;
  base_amount: number;
  discount_amount: number;
}

/**
 * Multi-Payment Checkout
 * Pay for multiple registrations in one transaction
 */
export const MultiPaymentCheckout: React.FC = () => {
  const navigate = useNavigate();
  const [payments, setPayments] = useState<PendingPayment[]>([]);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  // Form state
  const [cardNumber, setCardNumber] = useState('');
  const [expiryMonth, setExpiryMonth] = useState('');
  const [expiryYear, setExpiryYear] = useState('');
  const [cvv, setCvv] = useState('');

  const isDemoMode = process.env.REACT_APP_PAYMENT_MODE === 'demo';

  useEffect(() => {
    const pendingData = sessionStorage.getItem('pendingPayments');
    if (!pendingData) {
      navigate('/registration/cart');
      return;
    }

    const { paymentIds } = JSON.parse(pendingData);

    // Fetch payment details for all IDs
    Promise.all(
      paymentIds.map((id: number) =>
        fetch(`${process.env.REACT_APP_API_URL}/api/athlete-payments.php?payment_id=${id}`)
          .then(res => res.json())
      )
    )
      .then(results => {
        const paymentDetails = results
          .filter(r => r.success && r.payment)
          .map(r => ({
            id: r.payment.id,
            athlete_name: r.payment.athlete_name || 'Athlete',
            program_name: r.payment.program_name || '',
            item_name: r.payment.item_name || 'Registration',
            amount: parseFloat(r.payment.amount_remaining || r.payment.final_amount),
            base_amount: parseFloat(r.payment.base_amount),
            discount_amount: parseFloat(r.payment.discount_amount || 0)
          }));
        setPayments(paymentDetails);
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching payment details:', err);
        setError('Failed to load payment details');
        setLoading(false);
      });
  }, [navigate]);

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const getTotal = () => payments.reduce((sum, p) => sum + p.amount, 0);
  const getTotalDiscount = () => payments.reduce((sum, p) => sum + p.discount_amount, 0);

  const formatCardNumber = (value: string) => {
    const cleaned = value.replace(/\s/g, '');
    const groups = cleaned.match(/.{1,4}/g) || [];
    return groups.join(' ');
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setProcessing(true);

    try {
      // Process payment for each item
      const transactionIds: number[] = [];

      for (const payment of payments) {
        const response = await fetch(`${process.env.REACT_APP_API_URL}/api/payments-stub.php?action=process-payment`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            athlete_payment_id: payment.id,
            amount: payment.amount,
            payment_method: {
              card_number: cardNumber.replace(/\s/g, ''),
              expiry_month: expiryMonth,
              expiry_year: expiryYear,
              cvv: cvv
            }
          })
        });

        const result = await response.json();
        if (!result.success) {
          throw new Error(result.message || `Payment failed for ${payment.athlete_name}`);
        }
        if (result.transaction_id) {
          transactionIds.push(result.transaction_id);
        }
      }

      // Clear pending payments
      sessionStorage.removeItem('pendingPayments');

      setSuccess(true);

      // Redirect to receipt (use first transaction ID or dashboard)
      setTimeout(() => {
        if (transactionIds.length === 1) {
          navigate(`/payment/receipt/${transactionIds[0]}`);
        } else {
          navigate('/dashboard');
        }
      }, 2000);

    } catch (err: any) {
      setError(err.message || 'Payment failed');
    } finally {
      setProcessing(false);
    }
  };

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Loading payment details...</p>
        </div>
      </div>
    );
  }

  if (success) {
    return (
      <div className="container mx-auto p-6">
        <div className="max-w-md mx-auto mt-10 bg-green-50 border-2 border-green-500 rounded-lg p-8 text-center">
          <div className="text-green-600 mb-4">
            <svg className="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h2 className="text-2xl font-bold text-green-900 mb-2">Payment Successful!</h2>
          <p className="text-green-700 mb-4">
            {payments.length} registration{payments.length > 1 ? 's' : ''} completed for {formatCurrency(getTotal())}
          </p>
          <p className="text-sm text-green-600">Redirecting...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <div className="max-w-2xl mx-auto">
        <h1 className="text-3xl font-bold mb-6">Complete Payment</h1>

        {isDemoMode && (
          <div className="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
            <p className="text-sm font-medium text-yellow-800">Demo Mode - Test Cards</p>
            <p className="text-xs text-yellow-700 mt-1">
              <strong>Success:</strong> 4242 4242 4242 4242 |
              <strong> Decline:</strong> 4000 0000 0000 0002
            </p>
          </div>
        )}

        {/* Payment Summary */}
        <div className="bg-white shadow rounded-lg p-6 mb-6">
          <h2 className="text-xl font-bold mb-4">Payment Summary</h2>
          <div className="divide-y divide-gray-200">
            {payments.map(payment => (
              <div key={payment.id} className="py-3 flex justify-between">
                <div>
                  <div className="font-semibold">{payment.athlete_name}</div>
                  <div className="text-sm text-gray-600">{payment.program_name} - {payment.item_name}</div>
                </div>
                <div className="text-right">
                  {payment.discount_amount > 0 && (
                    <div className="text-xs text-green-600">-{formatCurrency(payment.discount_amount)} discount</div>
                  )}
                  <div className="font-semibold">{formatCurrency(payment.amount)}</div>
                </div>
              </div>
            ))}
          </div>

          <div className="border-t pt-4 mt-4">
            {getTotalDiscount() > 0 && (
              <div className="flex justify-between text-green-600 mb-2">
                <span>Total Discounts</span>
                <span>-{formatCurrency(getTotalDiscount())}</span>
              </div>
            )}
            <div className="flex justify-between text-xl font-bold">
              <span>Total Due</span>
              <span className="text-blue-600">{formatCurrency(getTotal())}</span>
            </div>
          </div>
        </div>

        {/* Payment Form */}
        <form onSubmit={handleSubmit} className="bg-white shadow rounded-lg p-6">
          <h2 className="text-xl font-bold mb-4">Payment Details</h2>

          {error && (
            <div className="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
              <p className="text-sm text-red-800">{error}</p>
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Card Number</label>
              <input
                type="text"
                value={cardNumber}
                onChange={(e) => setCardNumber(formatCardNumber(e.target.value.replace(/\s/g, '')))}
                placeholder="4242 4242 4242 4242"
                maxLength={19}
                required
                className="w-full border border-gray-300 rounded px-4 py-2"
              />
            </div>

            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Month</label>
                <input
                  type="text"
                  value={expiryMonth}
                  onChange={(e) => setExpiryMonth(e.target.value)}
                  placeholder="MM"
                  maxLength={2}
                  required
                  className="w-full border border-gray-300 rounded px-4 py-2"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Year</label>
                <input
                  type="text"
                  value={expiryYear}
                  onChange={(e) => setExpiryYear(e.target.value)}
                  placeholder="YY"
                  maxLength={2}
                  required
                  className="w-full border border-gray-300 rounded px-4 py-2"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">CVV</label>
                <input
                  type="text"
                  value={cvv}
                  onChange={(e) => setCvv(e.target.value)}
                  placeholder="123"
                  maxLength={4}
                  required
                  className="w-full border border-gray-300 rounded px-4 py-2"
                />
              </div>
            </div>
          </div>

          <button
            type="submit"
            disabled={processing}
            className={`w-full mt-6 py-3 rounded-lg text-white font-semibold ${
              processing
                ? 'bg-gray-400 cursor-not-allowed'
                : 'bg-brand-primary hover:bg-brand-primary-hover'
            }`}
          >
            {processing ? 'Processing...' : `Pay ${formatCurrency(getTotal())}`}
          </button>

          <p className="text-xs text-gray-500 text-center mt-4">
            {isDemoMode ? 'Demo mode - No real charges' : 'Secure payment processing'}
          </p>
        </form>
      </div>
    </div>
  );
};

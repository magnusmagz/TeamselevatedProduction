import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

interface PaymentDetails {
  athlete_id: number;
  athlete_name: string;
  payment_id?: number;
  item_name?: string;
  amount: number;
  base_amount?: number;
  discount_amount?: number;
  description: string;
  allow_payment_plan?: boolean;
}

interface PaymentPlan {
  id: number;
  name: string;
  description: string;
  total_installments: number;
  frequency: string;
  down_payment_percentage: string;
  auto_pay_required: boolean;
}

interface Installment {
  number: number;
  amount: number;
  due_date: string;
  is_down_payment: boolean;
}

/**
 * Parent: Payment Checkout
 * Payment form for making payments
 */
export const PaymentCheckout: React.FC = () => {
  const { athleteId, paymentId } = useParams<{ athleteId: string; paymentId?: string }>();
  const navigate = useNavigate();

  const [paymentDetails, setPaymentDetails] = useState<PaymentDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  // Form state
  const [paymentMethod, setPaymentMethod] = useState<'card' | 'ach'>('card');
  const [cardNumber, setCardNumber] = useState('');
  const [expiryMonth, setExpiryMonth] = useState('');
  const [expiryYear, setExpiryYear] = useState('');
  const [cvv, setCvv] = useState('');
  const [discountCode, setDiscountCode] = useState('');
  const [saveCard, setSaveCard] = useState(false);

  // Payment plan state
  const [paymentPlans, setPaymentPlans] = useState<PaymentPlan[]>([]);
  const [selectedPlan, setSelectedPlan] = useState<number | null>(null);
  const [installments, setInstallments] = useState<Installment[]>([]);
  const [usePaymentPlan, setUsePaymentPlan] = useState(false);

  const isDemoMode = process.env.REACT_APP_PAYMENT_MODE === 'demo';

  useEffect(() => {
    if (!athleteId) return;

    // Fetch payment details
    const url = paymentId
      ? `${process.env.REACT_APP_API_URL}/api/athlete-payments.php?athlete_id=${athleteId}`
      : `${process.env.REACT_APP_API_URL}/api/athlete-payments.php?athlete_id=${athleteId}`;

    fetch(url)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          if (paymentId) {
            // Single payment
            const payment = data.payments.find((p: any) => p.id === parseInt(paymentId));
            if (payment) {
              setPaymentDetails({
                athlete_id: parseInt(athleteId),
                athlete_name: data.athlete.name,
                payment_id: payment.id,
                item_name: payment.item_name,
                amount: parseFloat(payment.amount_remaining),
                base_amount: parseFloat(payment.base_amount || payment.amount_remaining),
                discount_amount: parseFloat(payment.discount_amount || 0),
                description: `${payment.item_name} - ${payment.program_name}`
              });
            }
          } else {
            // Pay all
            setPaymentDetails({
              athlete_id: parseInt(athleteId),
              athlete_name: data.athlete.name,
              amount: parseFloat(data.athlete.total_remaining),
              description: 'All outstanding payments'
            });
          }
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching payment details:', err);
        setError('Failed to load payment details');
        setLoading(false);
      });
  }, [athleteId, paymentId]);

  // Fetch available payment plans
  useEffect(() => {
    fetch(`${process.env.REACT_APP_API_URL}/api/payment-plans.php?action=list&club_id=13`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setPaymentPlans(data.plans);
        }
      })
      .catch(err => console.error('Error fetching payment plans:', err));
  }, []);

  // Fetch installment schedule when plan is selected
  useEffect(() => {
    if (!selectedPlan || !paymentDetails?.amount) return;

    fetch(`${process.env.REACT_APP_API_URL}/api/payment-plans.php?action=calculate&plan_id=${selectedPlan}&amount=${paymentDetails.amount}`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setInstallments(data.installments);
        }
      })
      .catch(err => console.error('Error calculating installments:', err));
  }, [selectedPlan, paymentDetails?.amount]);

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setProcessing(true);

    try {
      // If using payment plan, first apply the plan to create installments
      if (usePaymentPlan && selectedPlan) {
        const applyPlanResponse = await fetch(`${process.env.REACT_APP_API_URL}/api/payment-plans.php?action=apply`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            athlete_payment_id: paymentDetails?.payment_id,
            plan_id: selectedPlan
          })
        });

        const planResult = await applyPlanResponse.json();
        if (!planResult.success) {
          setError(planResult.error || 'Failed to apply payment plan');
          setProcessing(false);
          return;
        }
      }

      // Calculate amount to charge (down payment if using plan, full amount otherwise)
      const amountToCharge = usePaymentPlan && installments.length > 0
        ? installments.find(i => i.is_down_payment)?.amount || paymentDetails?.amount
        : paymentDetails?.amount;

      const payload = {
        athlete_payment_id: paymentDetails?.payment_id,
        amount: amountToCharge,
        payment_method: paymentMethod === 'card' ? {
          card_number: cardNumber.replace(/\s/g, ''),
          expiry_month: expiryMonth,
          expiry_year: expiryYear,
          cvv: cvv
        } : {},
        discount_code: discountCode || undefined,
        save_card: usePaymentPlan ? true : saveCard // Always save card for payment plans
      };

      const response = await fetch(`${process.env.REACT_APP_API_URL}/api/payments-stub.php?action=process-payment`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload)
      });

      const result = await response.json();

      if (result.success) {
        setSuccess(true);
        // Redirect to receipt page if we have a transaction_id
        if (result.transaction_id) {
          setTimeout(() => {
            navigate(`/payment/receipt/${result.transaction_id}`);
          }, 2000);
        } else {
          setTimeout(() => {
            navigate(`/athlete/${athleteId}/payments`);
          }, 2000);
        }
      } else {
        setError(result.message || result.error || 'Payment failed');
      }
    } catch (err) {
      setError('Network error. Please try again.');
    } finally {
      setProcessing(false);
    }
  };

  const formatCardNumber = (value: string) => {
    const cleaned = value.replace(/\s/g, '');
    const groups = cleaned.match(/.{1,4}/g) || [];
    return groups.join(' ');
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

  if (!paymentDetails) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Payment not found.</p>
          <button
            onClick={() => navigate(-1)}
            className="mt-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
          >
            Go Back
          </button>
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
            Your payment of {formatCurrency(paymentDetails.amount)} has been processed.
          </p>
          <p className="text-sm text-green-600">Redirecting...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <div className="max-w-2xl mx-auto">
        <div className="mb-6">
          <button
            onClick={() => navigate(-1)}
            className="text-blue-600 hover:text-blue-800 mb-4 flex items-center gap-2"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Back
          </button>
          <h1 className="text-3xl font-bold">Payment Checkout</h1>
          <p className="text-gray-600">Complete your payment securely</p>
        </div>

        {isDemoMode && (
          <div className="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
            <div className="flex">
              <div className="flex-shrink-0">
                <span className="text-2xl">🧪</span>
              </div>
              <div className="ml-3">
                <p className="text-sm font-medium text-yellow-800">Demo Mode - Test Cards</p>
                <p className="text-xs text-yellow-700 mt-1">
                  <strong>Success:</strong> 4242 4242 4242 4242 |
                  <strong> Decline:</strong> 4000 0000 0000 0002 |
                  <strong> Insufficient Funds:</strong> 4000 0000 0000 9995
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Payment Summary */}
        <div className="bg-white shadow rounded-lg p-6 mb-6">
          <h2 className="text-xl font-bold mb-4">Payment Summary</h2>
          <div className="space-y-2">
            <div className="flex justify-between">
              <span className="text-gray-600">Athlete:</span>
              <span className="font-semibold">{paymentDetails.athlete_name}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-600">Description:</span>
              <span className="font-semibold">{paymentDetails.description}</span>
            </div>

            {/* Show discount breakdown if applicable */}
            {paymentDetails.discount_amount && paymentDetails.discount_amount > 0 && (
              <>
                <div className="flex justify-between text-gray-600">
                  <span>Subtotal:</span>
                  <span>{formatCurrency(paymentDetails.base_amount || paymentDetails.amount)}</span>
                </div>
                <div className="flex justify-between text-green-600">
                  <span className="flex items-center gap-2">
                    Sibling Discount
                    <span className="bg-green-100 text-green-800 text-xs px-2 py-0.5 rounded-full">Applied</span>
                  </span>
                  <span>-{formatCurrency(paymentDetails.discount_amount)}</span>
                </div>
              </>
            )}

            <div className="border-t pt-2 mt-2 flex justify-between">
              <span className="text-lg font-bold">Total Amount:</span>
              <span className="text-2xl font-bold text-blue-600">{formatCurrency(paymentDetails.amount)}</span>
            </div>
          </div>
        </div>

        {/* Payment Plan Selection */}
        {paymentPlans.length > 0 && (
          <div className="bg-white shadow rounded-lg p-6 mb-6">
            <h2 className="text-xl font-bold mb-4">Payment Options</h2>

            <div className="space-y-3">
              <label className="flex items-start p-4 border-2 rounded-lg cursor-pointer hover:border-blue-300 transition-colors">
                <input
                  type="radio"
                  name="paymentOption"
                  checked={!usePaymentPlan}
                  onChange={() => {
                    setUsePaymentPlan(false);
                    setSelectedPlan(null);
                    setInstallments([]);
                  }}
                  className="mt-1 mr-3"
                />
                <div>
                  <div className="font-semibold">Pay in Full</div>
                  <div className="text-sm text-gray-600">Pay {formatCurrency(paymentDetails.amount)} today</div>
                </div>
              </label>

              <label className="flex items-start p-4 border-2 rounded-lg cursor-pointer hover:border-blue-300 transition-colors">
                <input
                  type="radio"
                  name="paymentOption"
                  checked={usePaymentPlan}
                  onChange={() => setUsePaymentPlan(true)}
                  className="mt-1 mr-3"
                />
                <div className="flex-1">
                  <div className="font-semibold">Use Payment Plan</div>
                  <div className="text-sm text-gray-600">Spread payments over multiple installments</div>

                  {usePaymentPlan && (
                    <div className="mt-3 space-y-3">
                      <select
                        value={selectedPlan || ''}
                        onChange={(e) => setSelectedPlan(parseInt(e.target.value))}
                        className="w-full border border-gray-300 rounded px-3 py-2"
                      >
                        <option value="">Select a payment plan...</option>
                        {paymentPlans.map(plan => (
                          <option key={plan.id} value={plan.id}>
                            {plan.name} - {plan.total_installments} payments ({plan.frequency})
                          </option>
                        ))}
                      </select>

                      {installments.length > 0 && (
                        <div className="bg-gray-50 rounded-lg p-4">
                          <div className="text-sm font-semibold mb-2">Payment Schedule:</div>
                          <div className="space-y-2">
                            {installments.map((inst) => (
                              <div key={inst.number} className="flex justify-between text-sm">
                                <span className={inst.is_down_payment ? 'font-semibold text-blue-600' : ''}>
                                  {inst.is_down_payment ? 'Down Payment (Today)' : `Installment ${inst.number - 1}`}
                                  {!inst.is_down_payment && (
                                    <span className="text-gray-500 ml-2">
                                      Due: {new Date(inst.due_date).toLocaleDateString()}
                                    </span>
                                  )}
                                </span>
                                <span className="font-semibold">{formatCurrency(inst.amount)}</span>
                              </div>
                            ))}
                          </div>
                          <div className="border-t mt-2 pt-2 flex justify-between text-sm font-semibold">
                            <span>Due Today:</span>
                            <span className="text-blue-600">
                              {formatCurrency(installments.find(i => i.is_down_payment)?.amount || 0)}
                            </span>
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </label>
            </div>
          </div>
        )}

        {/* Payment Form */}
        <form onSubmit={handleSubmit} className="bg-white shadow rounded-lg p-6">
          <h2 className="text-xl font-bold mb-4">Payment Details</h2>

          {error && (
            <div className="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
              <p className="text-sm text-red-800">{error}</p>
            </div>
          )}

          {/* Payment Method Selection */}
          <div className="mb-6">
            <label className="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
            <div className="flex gap-4">
              <button
                type="button"
                onClick={() => setPaymentMethod('card')}
                className={`flex-1 py-3 px-4 border-2 rounded font-semibold ${
                  paymentMethod === 'card'
                    ? 'border-blue-600 bg-blue-50 text-blue-900'
                    : 'border-gray-300 text-gray-700 hover:border-gray-400'
                }`}
              >
                Credit/Debit Card
              </button>
              <button
                type="button"
                onClick={() => setPaymentMethod('ach')}
                className={`flex-1 py-3 px-4 border-2 rounded font-semibold ${
                  paymentMethod === 'ach'
                    ? 'border-blue-600 bg-blue-50 text-blue-900'
                    : 'border-gray-300 text-gray-700 hover:border-gray-400'
                }`}
              >
                Bank Account (ACH)
              </button>
            </div>
          </div>

          {/* Card Details */}
          {paymentMethod === 'card' && (
            <div className="space-y-4 mb-6">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Card Number</label>
                <input
                  type="text"
                  value={cardNumber}
                  onChange={(e) => setCardNumber(formatCardNumber(e.target.value.replace(/\s/g, '')))}
                  placeholder="4242 4242 4242 4242"
                  maxLength={19}
                  required
                  className="w-full border border-gray-300 rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
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
                    className="w-full border border-gray-300 rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
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
                    className="w-full border border-gray-300 rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
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
                    className="w-full border border-gray-300 rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  />
                </div>
              </div>

              <div className="flex items-center">
                <input
                  type="checkbox"
                  id="saveCard"
                  checked={saveCard}
                  onChange={(e) => setSaveCard(e.target.checked)}
                  className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                />
                <label htmlFor="saveCard" className="ml-2 text-sm text-gray-700">
                  Save card for future payments
                </label>
              </div>
            </div>
          )}

          {paymentMethod === 'ach' && (
            <div className="bg-blue-50 border border-blue-200 rounded p-4 mb-6">
              <p className="text-sm text-blue-800">
                ACH payment processing is coming soon. Please use a credit/debit card for now.
              </p>
            </div>
          )}

          {/* Discount Code */}
          <div className="mb-6">
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Discount Code (Optional)
            </label>
            <input
              type="text"
              value={discountCode}
              onChange={(e) => setDiscountCode(e.target.value.toUpperCase())}
              placeholder="Enter code"
              className="w-full border border-gray-300 rounded px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
            {isDemoMode && (
              <p className="text-xs text-gray-500 mt-1">
                Try: EARLYBIRD10, SIBLING15, VOLUNTEER5
              </p>
            )}
          </div>

          {/* Submit Button */}
          <button
            type="submit"
            disabled={processing || (paymentMethod === 'ach')}
            className={`w-full py-3 px-4 rounded font-semibold text-white ${
              processing || (paymentMethod === 'ach')
                ? 'bg-gray-400 cursor-not-allowed'
                : 'bg-blue-600 hover:bg-blue-700'
            }`}
          >
            {processing ? 'Processing...' : `Pay ${formatCurrency(paymentDetails.amount)}`}
          </button>

          <p className="text-xs text-gray-500 text-center mt-4">
            {isDemoMode
              ? '🧪 Demo mode - No real charges will be made'
              : '🔒 Secure payment processing powered by Maverick Payments'}
          </p>
        </form>
      </div>
    </div>
  );
};

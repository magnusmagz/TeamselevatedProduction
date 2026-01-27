import React, { useState, useEffect } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';

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
  const [searchParams] = useSearchParams();

  // Banking feature demo flag - enabled via ?bank URL parameter
  const showBankingFeature = searchParams.has('bank');
  const PROCESSING_FEE_RATE = 0.03; // 3%

  const [paymentDetails, setPaymentDetails] = useState<PaymentDetails | null>(null);
  const [useElevatedAccount, setUseElevatedAccount] = useState(false);
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

  // Calculate processing fee (only shown when banking feature is enabled)
  const processingFee = paymentDetails ? paymentDetails.amount * PROCESSING_FEE_RATE : 0;
  const totalWithFee = paymentDetails ? paymentDetails.amount + (useElevatedAccount ? 0 : processingFee) : 0;

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

            {/* Processing Fee (shown when banking feature enabled) */}
            {showBankingFeature && !useElevatedAccount && (
              <div className="flex justify-between text-gray-600">
                <span>Processing Fee (3%):</span>
                <span>{formatCurrency(processingFee)}</span>
              </div>
            )}

            {showBankingFeature && useElevatedAccount && (
              <div className="flex justify-between text-green-600">
                <span className="flex items-center gap-2">
                  Processing Fee
                  <span className="bg-green-100 text-green-800 text-xs px-2 py-0.5 rounded-full">Waived</span>
                </span>
                <span className="line-through text-gray-400">{formatCurrency(processingFee)}</span>
              </div>
            )}

            <div className="border-t pt-2 mt-2 flex justify-between">
              <span className="text-lg font-bold">Total Amount:</span>
              <span className="text-2xl font-bold text-blue-600">
                {formatCurrency(showBankingFeature ? totalWithFee : paymentDetails.amount)}
              </span>
            </div>
          </div>
        </div>

        {/* Elevated Account Upsell - Airline Style (only shown with ?bank flag) */}
        {showBankingFeature && (
          <div className="bg-gradient-to-r from-brand-primary to-forest-700 rounded-lg overflow-hidden mb-6 shadow-lg">
            <div className="p-6">
              <div className="flex items-start gap-4">
                <div className="flex-shrink-0">
                  <div className="w-14 h-14 bg-white/20 rounded-full flex items-center justify-center">
                    <svg className="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                </div>
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <span className="bg-brand-accent text-brand-primary text-xs font-bold px-2 py-0.5 rounded uppercase">
                      Save {formatCurrency(processingFee)}
                    </span>
                  </div>
                  <h3 className="text-xl font-bold text-white mb-2">
                    Waive Processing Fees with Elevated Account
                  </h3>
                  <p className="text-brand-light text-sm mb-4">
                    Pay directly from your bank account and skip the 3% processing fee.
                    Plus, enjoy faster refunds and seamless payment tracking.
                  </p>

                  <div className="grid grid-cols-3 gap-4 mb-4">
                    <div className="text-center">
                      <div className="text-2xl mb-1">💰</div>
                      <div className="text-xs text-brand-light">No Processing Fees</div>
                    </div>
                    <div className="text-center">
                      <div className="text-2xl mb-1">⚡</div>
                      <div className="text-xs text-brand-light">Instant Payments</div>
                    </div>
                    <div className="text-center">
                      <div className="text-2xl mb-1">🔒</div>
                      <div className="text-xs text-brand-light">Bank-Level Security</div>
                    </div>
                  </div>

                  <div className="flex items-center gap-3">
                    <button
                      type="button"
                      onClick={() => setUseElevatedAccount(true)}
                      className={`flex-1 py-3 px-4 rounded-lg font-semibold transition-all ${
                        useElevatedAccount
                          ? 'bg-white text-brand-primary'
                          : 'bg-brand-accent text-brand-primary hover:bg-brand-light'
                      }`}
                    >
                      {useElevatedAccount ? (
                        <span className="flex items-center justify-center gap-2">
                          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                          </svg>
                          Elevated Account Selected
                        </span>
                      ) : (
                        'Link Bank Account & Save'
                      )}
                    </button>
                    {useElevatedAccount && (
                      <button
                        type="button"
                        onClick={() => setUseElevatedAccount(false)}
                        className="text-white/70 hover:text-white text-sm underline"
                      >
                        Use card instead
                      </button>
                    )}
                  </div>
                </div>
              </div>
            </div>

            {/* Trust badges */}
            <div className="bg-black/20 px-6 py-3 flex items-center justify-between text-xs text-brand-light">
              <span className="flex items-center gap-2">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                256-bit encryption
              </span>
              <span className="flex items-center gap-2">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                FDIC Insured
              </span>
              <span className="flex items-center gap-2">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                Powered by Plaid
              </span>
            </div>
          </div>
        )}

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
          {showBankingFeature && useElevatedAccount ? (
            <div className="mb-6">
              <label className="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
              <div className="bg-brand-light border-2 border-brand-accent rounded-lg p-4">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 bg-brand-primary rounded-full flex items-center justify-center">
                    <svg className="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <div className="flex-1">
                    <div className="font-semibold text-brand-primary">Elevated Account</div>
                    <div className="text-sm text-gray-600">Processing fee waived</div>
                  </div>
                  <div className="text-right">
                    <div className="text-green-600 font-semibold">-{formatCurrency(processingFee)}</div>
                    <div className="text-xs text-gray-500">You save</div>
                  </div>
                </div>
              </div>
              <p className="text-xs text-gray-500 mt-2">
                You'll be prompted to securely link your bank account via Plaid.
              </p>
            </div>
          ) : (
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
          )}

          {/* Elevated Account - Bank Linking Placeholder */}
          {showBankingFeature && useElevatedAccount && (
            <div className="space-y-4 mb-6">
              <div className="bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                <div className="w-16 h-16 bg-brand-light rounded-full flex items-center justify-center mx-auto mb-4">
                  <svg className="w-8 h-8 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                  </svg>
                </div>
                <h4 className="font-semibold text-gray-900 mb-2">Link Your Bank Account</h4>
                <p className="text-sm text-gray-600 mb-4">
                  Securely connect your bank account to make fee-free payments.
                  We use Plaid, trusted by millions, to securely link your account.
                </p>
                <div className="flex items-center justify-center gap-6 text-xs text-gray-500">
                  <span className="flex items-center gap-1">
                    <svg className="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                    </svg>
                    Read-only access
                  </span>
                  <span className="flex items-center gap-1">
                    <svg className="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                    </svg>
                    We never store credentials
                  </span>
                  <span className="flex items-center gap-1">
                    <svg className="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                    </svg>
                    Cancel anytime
                  </span>
                </div>
              </div>
            </div>
          )}

          {/* Card Details */}
          {paymentMethod === 'card' && !useElevatedAccount && (
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

          {paymentMethod === 'ach' && !useElevatedAccount && (
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
            disabled={processing || (paymentMethod === 'ach' && !useElevatedAccount)}
            className={`w-full py-3 px-4 rounded font-semibold text-white ${
              processing || (paymentMethod === 'ach' && !useElevatedAccount)
                ? 'bg-gray-400 cursor-not-allowed'
                : useElevatedAccount
                  ? 'bg-brand-primary hover:bg-brand-primary-hover'
                  : 'bg-blue-600 hover:bg-blue-700'
            }`}
          >
            {processing ? 'Processing...' : (
              useElevatedAccount
                ? `Pay ${formatCurrency(paymentDetails.amount)} with Elevated Account`
                : `Pay ${formatCurrency(showBankingFeature ? totalWithFee : paymentDetails.amount)}`
            )}
          </button>

          {showBankingFeature && !useElevatedAccount && (
            <p className="text-xs text-center mt-2 text-gray-500">
              Includes {formatCurrency(processingFee)} processing fee •
              <button
                type="button"
                onClick={() => setUseElevatedAccount(true)}
                className="text-brand-primary hover:underline ml-1"
              >
                Waive with Elevated Account
              </button>
            </p>
          )}

          <p className="text-xs text-gray-500 text-center mt-4">
            {isDemoMode
              ? '🧪 Demo mode - No real charges will be made'
              : useElevatedAccount
                ? '🔒 Secure bank connection powered by Plaid'
                : '🔒 Secure payment processing powered by Maverick Payments'}
          </p>
        </form>
      </div>
    </div>
  );
};

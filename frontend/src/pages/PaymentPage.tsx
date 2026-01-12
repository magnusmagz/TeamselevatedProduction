import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';

interface Invoice {
  id: number;
  invoice_number: string;
  athlete_id: number;
  athlete_first: string;
  athlete_last: string;
  athlete_payment_id: number;
  guardian_first: string;
  guardian_last: string;
  guardian_email: string;
  program_id: number;
  program_name: string;
  invoice_date: string;
  due_date: string;
  subtotal: number;
  discount_amount: number;
  total_amount: number;
  amount_paid: number;
  status: string;
  memo: string;
  items?: {
    id: number;
    description: string;
    quantity: number;
    unit_price: number;
    line_total: number;
  }[];
}

interface PaymentPlanOption {
  id: string;
  name: string;
  description: string;
  payments: {
    amount: number;
    dueDate: string;
    label: string;
  }[];
  totalAmount: number;
}

interface CoPayer {
  firstName: string;
  lastName: string;
  email: string;
}

interface Contributor {
  name: string;
  amount: number;
  message?: string;
  date: string;
}

/**
 * Payment Page
 * Real invoice payment experience fetching data from the API
 * Route: /pay/:invoiceId
 */
export const PaymentPage: React.FC = () => {
  const { invoiceId } = useParams<{ invoiceId: string }>();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  // Invoice data state
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Fetch invoice on mount
  useEffect(() => {
    const fetchInvoice = async () => {
      try {
        const response = await fetch(`${API_URL}/api/invoices.php?action=get&id=${invoiceId}`);
        const data = await response.json();

        if (data.success === false || data.error) {
          setError(data.error || 'Invoice not found');
        } else if (data.invoice) {
          // API returns {success: true, invoice: {...}}
          setInvoice(data.invoice);
        } else {
          // Fallback for direct invoice response
          setInvoice(data);
        }
      } catch (err) {
        setError('Failed to load invoice. Please try again.');
        console.error('Error fetching invoice:', err);
      } finally {
        setLoading(false);
      }
    };

    if (invoiceId) {
      fetchInvoice();
    }
  }, [invoiceId, API_URL]);

  // Calculate future payment dates based on today
  const getPaymentDate = (monthsFromNow: number): string => {
    const date = new Date();
    date.setMonth(date.getMonth() + monthsFromNow);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  };

  // Split payment state
  const [isSplit, setIsSplit] = useState(false);
  const [showSplitModal, setShowSplitModal] = useState(false);
  const [coPayer, setCoPayer] = useState<CoPayer | null>(null);
  const [splitInviteSent, setSplitInviteSent] = useState(false);

  // Co-payer form state
  const [coPayerForm, setCoPayerForm] = useState<CoPayer>({
    firstName: '',
    lastName: '',
    email: ''
  });

  // Crowdfunding state
  const [isCrowdfunded, setIsCrowdfunded] = useState(false);
  const [showCrowdfundModal, setShowCrowdfundModal] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);
  const [contributors, setContributors] = useState<Contributor[]>([]);

  // Computed fields from invoice
  const athleteName = invoice ? `${invoice.athlete_first} ${invoice.athlete_last}` : '';
  const guardianName = invoice ? `${invoice.guardian_first || ''} ${invoice.guardian_last || ''}`.trim() : '';
  const amountRemaining = invoice ? (invoice.total_amount - (invoice.amount_paid || 0)) : 0;

  // Share link for crowdfunding
  const shareLink = invoice ? `${window.location.origin}/contribute/${invoice.invoice_number.toLowerCase()}` : '';

  // Calculate total contributions
  const getTotalContributions = () => {
    return contributors.reduce((sum, c) => sum + c.amount, 0);
  };

  // Get the amount based on split status and contributions
  const getEffectiveAmount = () => {
    if (!invoice) return 0;
    const baseAmount = isSplit ? amountRemaining / 2 : amountRemaining;
    return Math.max(0, baseAmount - getTotalContributions());
  };

  // Enable crowdfunding (in real app, this would save to backend)
  const enableCrowdfunding = () => {
    setIsCrowdfunded(true);
    setShowCrowdfundModal(false);
  };

  // Payment plan options with dynamic dates
  const getPaymentPlans = (): PaymentPlanOption[] => {
    const baseAmount = getEffectiveAmount();
    return [
      {
        id: 'full',
        name: 'Pay in Full',
        description: 'One payment today',
        payments: [
          { amount: baseAmount, dueDate: 'Today', label: 'Full Payment' }
        ],
        totalAmount: baseAmount
      },
      {
        id: '2-pay',
        name: '2 Monthly Payments',
        description: 'Split into 2 equal payments',
        payments: [
          { amount: baseAmount / 2, dueDate: 'Today', label: 'Payment 1 of 2' },
          { amount: baseAmount / 2, dueDate: getPaymentDate(1), label: 'Payment 2 of 2' }
        ],
        totalAmount: baseAmount
      },
      {
        id: '3-pay',
        name: '3 Monthly Payments',
        description: 'Split into 3 equal payments',
        payments: [
          { amount: baseAmount / 3, dueDate: 'Today', label: 'Payment 1 of 3' },
          { amount: baseAmount / 3, dueDate: getPaymentDate(1), label: 'Payment 2 of 3' },
          { amount: baseAmount / 3, dueDate: getPaymentDate(2), label: 'Payment 3 of 3' }
        ],
        totalAmount: baseAmount
      }
    ];
  };

  const paymentPlans = getPaymentPlans();

  const [selectedPlan, setSelectedPlan] = useState<string>('full');
  const [step, setStep] = useState<'invoice' | 'payment' | 'success'>('invoice');
  const [processing, setProcessing] = useState(false);

  // Payment form state - pre-filled for demo
  const [cardNumber, setCardNumber] = useState('4242 4242 4242 4242');
  const [expiryMonth, setExpiryMonth] = useState('12');
  const [expiryYear, setExpiryYear] = useState(String((new Date().getFullYear() + 4) % 100).padStart(2, '0'));
  const [cvv, setCvv] = useState('123');

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const formatCardNumber = (value: string) => {
    const cleaned = value.replace(/\D/g, '');
    const groups = cleaned.match(/.{1,4}/g) || [];
    return groups.join(' ').substr(0, 19);
  };

  const getSelectedPlanDetails = () => {
    return paymentPlans.find(p => p.id === selectedPlan)!;
  };

  const getAmountDueToday = () => {
    const plan = getSelectedPlanDetails();
    return plan?.payments[0].amount || 0;
  };

  const handleProceedToPayment = () => {
    setStep('payment');
  };

  const handleSubmitPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    setProcessing(true);

    try {
      // Process payment via backend API
      const response = await fetch(`${API_URL}/api/payments-stub.php?action=process-payment`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          athlete_payment_id: invoice?.athlete_payment_id,
          amount: getAmountDueToday(),
          payment_method: {
            type: 'card',
            last4: cardNumber.replace(/\s/g, '').slice(-4)
          }
        })
      });

      const result = await response.json();

      if (result.success) {
        setStep('success');
      } else {
        alert(result.error || 'Payment failed. Please try again.');
      }
    } catch (err) {
      console.error('Payment error:', err);
      alert('Payment failed. Please try again.');
    } finally {
      setProcessing(false);
    }
  };

  const handleSplitSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setCoPayer(coPayerForm);
    setIsSplit(true);
    setSplitInviteSent(true);
    setShowSplitModal(false);
  };

  const handleRemoveSplit = () => {
    setIsSplit(false);
    setCoPayer(null);
    setSplitInviteSent(false);
    setCoPayerForm({ firstName: '', lastName: '', email: '' });
  };

  const handleCopyLink = () => {
    navigator.clipboard.writeText(shareLink);
    setLinkCopied(true);
    setTimeout(() => setLinkCopied(false), 2000);
  };

  const handleDisableCrowdfunding = () => {
    setIsCrowdfunded(false);
    setContributors([]);
  };

  // Loading state
  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-primary mx-auto mb-4"></div>
          <p className="text-gray-600">Loading invoice...</p>
        </div>
      </div>
    );
  }

  // Error state
  if (error || !invoice) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="bg-white rounded-lg shadow-lg p-8 max-w-md text-center">
          <div className="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg className="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
          <h1 className="text-xl font-bold text-gray-900 mb-2">Invoice Not Found</h1>
          <p className="text-gray-600 mb-4">{error || 'The invoice you are looking for does not exist or has been removed.'}</p>
          <a href="/" className="text-brand-primary hover:underline">Return to Home</a>
        </div>
      </div>
    );
  }

  // Already paid state
  if (invoice.status === 'paid') {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="bg-white rounded-lg shadow-lg p-8 max-w-md text-center">
          <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h1 className="text-xl font-bold text-gray-900 mb-2">Already Paid</h1>
          <p className="text-gray-600 mb-4">This invoice ({invoice.invoice_number}) has already been paid.</p>
          <p className="text-sm text-gray-500">Amount: {formatCurrency(invoice.total_amount)}</p>
        </div>
      </div>
    );
  }

  // Split Payment Modal
  const renderSplitModal = () => {
    if (!showSplitModal) return null;

    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div className="bg-white rounded-lg shadow-xl w-full max-w-md overflow-hidden">
          <div className="bg-brand-primary text-white p-6">
            <h2 className="text-xl font-bold">Split This Payment</h2>
            <p className="text-white/70 text-sm mt-1">
              Share the cost 50/50 with another person
            </p>
          </div>

          <form onSubmit={handleSplitSubmit} className="p-6">
            <div className="flex items-center gap-3 mb-6">
              <div className="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center border border-brand-primary">
                <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                </svg>
              </div>
              <div>
                <p className="font-semibold text-brand-primary-dark">Split the cost evenly</p>
                <p className="text-sm text-brand-primary">
                  Each person pays {formatCurrency(amountRemaining / 2)} independently
                </p>
              </div>
            </div>

            <p className="text-sm text-gray-600 mb-4">
              Enter the details of the person you'd like to split with. They'll receive an email invitation to pay their half.
            </p>

            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                  <input
                    type="text"
                    value={coPayerForm.firstName}
                    onChange={(e) => setCoPayerForm({ ...coPayerForm, firstName: e.target.value })}
                    required
                    placeholder="John"
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                  <input
                    type="text"
                    value={coPayerForm.lastName}
                    onChange={(e) => setCoPayerForm({ ...coPayerForm, lastName: e.target.value })}
                    required
                    placeholder="Smith"
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input
                  type="email"
                  value={coPayerForm.email}
                  onChange={(e) => setCoPayerForm({ ...coPayerForm, email: e.target.value })}
                  required
                  placeholder="john.smith@email.com"
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                />
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button
                type="button"
                onClick={() => setShowSplitModal(false)}
                className="flex-1 px-4 py-3 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                className="flex-1 px-4 py-3 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover"
              >
                Send Invitation
              </button>
            </div>
          </form>
        </div>
      </div>
    );
  };

  // Crowdfund Modal
  const renderCrowdfundModal = () => {
    if (!showCrowdfundModal) return null;

    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div className="bg-white rounded-lg shadow-xl w-full max-w-md overflow-hidden">
          <div className="bg-brand-primary text-white p-6">
            <h2 className="text-xl font-bold">Invite Supporters</h2>
            <p className="text-white/70 text-sm mt-1">
              Let supporters chip in for {athleteName}'s registration
            </p>
          </div>

          <div className="p-6">
            <div className="flex items-center gap-3 mb-6">
              <div className="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center border border-brand-primary">
                <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div>
                <p className="font-semibold text-brand-primary-dark">Rally Your Supporters</p>
                <p className="text-sm text-gray-600">
                  Family, friends, coaches, neighbors - anyone can chip in
                </p>
              </div>
            </div>

            <div className="space-y-4">
              <p className="text-gray-600 text-sm">
                Share a special link with supporters. Anyone can contribute any amount toward {athleteName}'s {invoice.program_name} fee. You only pay what's left!
              </p>

              <div className="bg-gray-100 rounded-lg p-3">
                <p className="text-xs text-gray-500 mb-1">Shareable Link</p>
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={shareLink}
                    readOnly
                    className="flex-1 bg-white border border-gray-300 rounded px-3 py-2 text-sm font-mono text-gray-600"
                  />
                  <button
                    onClick={handleCopyLink}
                    className="px-3 py-2 bg-brand-primary text-white rounded font-medium text-sm hover:opacity-90"
                  >
                    {linkCopied ? 'Copied!' : 'Copy'}
                  </button>
                </div>
              </div>

              <div className="flex items-center gap-3 text-sm text-gray-500">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                <span>Contributors can leave a message with their gift</span>
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button
                type="button"
                onClick={() => setShowCrowdfundModal(false)}
                className="flex-1 px-4 py-3 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={enableCrowdfunding}
                className="flex-1 px-4 py-3 bg-brand-primary text-white rounded-lg font-medium hover:opacity-90"
              >
                Enable & Share
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  };

  // Success screen
  if (step === 'success') {
    const plan = getSelectedPlanDetails();
    return (
      <div className="min-h-screen bg-gray-50 py-12">
        <div className="container mx-auto px-4">
          <div className="max-w-lg mx-auto bg-white rounded-lg shadow-lg p-8 text-center">
            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-2">Payment Successful!</h1>
            <p className="text-gray-600 mb-6">
              Thank you for your payment of {formatCurrency(getAmountDueToday())}
            </p>

            {isSplit && coPayer && (
              <div className="bg-gray-50 border border-brand-primary rounded-lg p-4 text-left mb-6">
                <div className="flex items-center gap-2 mb-2">
                  <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                  </svg>
                  <span className="font-semibold text-brand-primary-dark">Split Payment</span>
                </div>
                <p className="text-sm text-brand-primary">
                  You've paid your half ({formatCurrency(getEffectiveAmount())}). {coPayer.firstName} {coPayer.lastName} has been notified to pay their portion.
                </p>
              </div>
            )}

            <div className="bg-gray-50 rounded-lg p-4 text-left mb-6">
              <div className="text-sm text-gray-600 mb-2">Payment Details</div>
              <div className="space-y-1 text-sm">
                <div className="flex justify-between">
                  <span>Invoice:</span>
                  <span className="font-mono">{invoice.invoice_number}</span>
                </div>
                <div className="flex justify-between">
                  <span>Athlete:</span>
                  <span>{athleteName}</span>
                </div>
                <div className="flex justify-between">
                  <span>Amount Paid:</span>
                  <span className="font-semibold text-brand-primary">{formatCurrency(getAmountDueToday())}</span>
                </div>
                {isSplit && (
                  <div className="flex justify-between text-brand-primary">
                    <span>Your portion of:</span>
                    <span className="font-semibold">{formatCurrency(amountRemaining)} total</span>
                  </div>
                )}
              </div>
            </div>

            {plan && plan.payments.length > 1 && (
              <div className="bg-gray-50 border border-brand-primary rounded-lg p-4 text-left mb-6">
                <div className="text-sm font-semibold text-brand-primary-dark mb-2">Your Upcoming Payments</div>
                <div className="space-y-2">
                  {plan.payments.slice(1).map((payment, index) => (
                    <div key={index} className="flex justify-between text-sm">
                      <span className="text-brand-primary">{payment.dueDate}</span>
                      <span className="font-semibold text-brand-primary-dark">{formatCurrency(payment.amount)}</span>
                    </div>
                  ))}
                </div>
                <p className="text-xs text-brand-primary mt-2">
                  Your saved card will be charged automatically on each due date.
                </p>
              </div>
            )}

            <p className="text-sm text-gray-500 mb-4">
              A receipt has been sent to {invoice.guardian_email}
            </p>

            <a
              href="/"
              className="text-brand-primary hover:text-brand-primary-hover text-sm font-medium"
            >
              Return to Home
            </a>
          </div>
        </div>
      </div>
    );
  }

  // Payment form screen
  if (step === 'payment') {
    const plan = getSelectedPlanDetails();
    return (
      <div className="min-h-screen bg-gray-50 py-12">
        <div className="container mx-auto px-4">
          <div className="max-w-lg mx-auto">
            {/* Back button */}
            <button
              onClick={() => setStep('invoice')}
              className="text-brand-primary hover:text-brand-primary-hover mb-4 flex items-center gap-2"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
              </svg>
              Back to Invoice
            </button>

            <div className="bg-white rounded-lg shadow-lg overflow-hidden">
              {/* Header */}
              <div className="bg-brand-primary text-white p-6">
                <h1 className="text-xl font-bold">Complete Payment</h1>
                <p className="text-white/70 text-sm">{invoice.program_name}</p>
              </div>

              {/* Split indicator */}
              {isSplit && coPayer && (
                <div className="bg-gray-50 border-b border-brand-primary p-3">
                  <p className="text-sm text-brand-primary-dark">
                    <strong>Split Payment:</strong> You're paying your half ({formatCurrency(getEffectiveAmount())})
                  </p>
                </div>
              )}

              <form onSubmit={handleSubmitPayment} className="p-6">
                {/* Payment summary */}
                <div className="bg-gray-50 rounded-lg p-4 mb-6">
                  <div className="flex justify-between items-center mb-2">
                    <span className="text-gray-600">Plan:</span>
                    <span className="font-semibold">{plan?.name}</span>
                  </div>
                  <div className="flex justify-between items-center text-lg">
                    <span className="font-semibold">Due Today:</span>
                    <span className="font-bold text-brand-primary">{formatCurrency(getAmountDueToday())}</span>
                  </div>
                  {plan && plan.payments.length > 1 && (
                    <p className="text-xs text-gray-500 mt-2">
                      + {plan.payments.length - 1} future payment(s) of {formatCurrency(plan.payments[1].amount)}
                    </p>
                  )}
                </div>

                {/* Card details */}
                <div className="space-y-4">
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

                  {plan && plan.payments.length > 1 && (
                    <div className="flex items-start gap-2 bg-gray-50 rounded-lg p-3">
                      <svg className="w-5 h-5 text-brand-primary mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <p className="text-sm text-brand-primary-dark">
                        Your card will be saved securely for automatic payments on future due dates.
                      </p>
                    </div>
                  )}
                </div>

                <button
                  type="submit"
                  disabled={processing}
                  className={`w-full mt-6 py-4 rounded-lg font-semibold text-white text-lg ${
                    processing
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
                  ) : (
                    `Pay ${formatCurrency(getAmountDueToday())}`
                  )}
                </button>

                <p className="text-xs text-gray-500 text-center mt-4">
                  Secure payment processing. Your card details are encrypted.
                </p>
              </form>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // Invoice view (default)
  return (
    <div className="min-h-screen bg-gray-50 py-12">
      <div className="container mx-auto px-4">
        <div className="max-w-2xl mx-auto">
          {/* Invoice Card */}
          <div className="bg-white rounded-lg shadow-lg overflow-hidden">
            {/* Invoice Header */}
            <div className="bg-brand-primary text-white p-6">
              <div className="flex justify-between items-start">
                <div>
                  <p className="text-white/70 text-sm mb-1">Invoice</p>
                  <p className="font-mono text-lg">{invoice.invoice_number}</p>
                </div>
                <div className="text-right">
                  <p className="text-white/70 text-sm mb-1">
                    {isSplit ? 'Your Portion' : 'Amount Due'}
                  </p>
                  <p className="text-3xl font-bold">{formatCurrency(getEffectiveAmount())}</p>
                  {isSplit && (
                    <p className="text-white/70 text-sm">of {formatCurrency(amountRemaining)} total</p>
                  )}
                </div>
              </div>
            </div>

            {/* Split Payment Banner */}
            {isSplit && coPayer && (
              <div className="bg-gray-50 border-b border-brand-primary p-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center border border-brand-primary">
                      <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                      </svg>
                    </div>
                    <div>
                      <p className="font-semibold text-brand-primary-dark">Split with {coPayer.firstName} {coPayer.lastName}</p>
                      <p className="text-sm text-brand-primary">
                        {splitInviteSent ? (
                          <>Invite sent to {coPayer.email}</>
                        ) : (
                          <>Each pays {formatCurrency(amountRemaining / 2)}</>
                        )}
                      </p>
                    </div>
                  </div>
                  <button
                    onClick={handleRemoveSplit}
                    className="text-brand-primary hover:text-brand-primary-hover text-sm font-medium"
                  >
                    Remove Split
                  </button>
                </div>
              </div>
            )}

            {/* Invoice Details */}
            <div className="p-6 border-b">
              <div className="grid grid-cols-2 gap-6">
                <div>
                  <p className="text-sm text-gray-500 mb-1">Athlete</p>
                  <p className="font-semibold text-gray-900">{athleteName}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-500 mb-1">Program</p>
                  <p className="font-semibold text-gray-900">{invoice.program_name}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-500 mb-1">Invoice Date</p>
                  <p className="text-gray-900">{new Date(invoice.invoice_date).toLocaleDateString()}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-500 mb-1">Due Date</p>
                  <p className="text-gray-900 font-semibold">{new Date(invoice.due_date).toLocaleDateString()}</p>
                </div>
              </div>
            </div>

            {/* Line Items */}
            <div className="p-6 border-b">
              <table className="w-full">
                <thead>
                  <tr className="text-left text-sm text-gray-500">
                    <th className="pb-2">Description</th>
                    <th className="pb-2 text-right">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {invoice.items && invoice.items.map((item, index) => (
                    <tr key={index}>
                      <td className="py-2 text-gray-900">{item.description}</td>
                      <td className="py-2 text-right font-semibold">{formatCurrency(item.line_total)}</td>
                    </tr>
                  ))}
                  {(!invoice.items || invoice.items.length === 0) && (
                    <tr>
                      <td className="py-2 text-gray-900">{invoice.program_name} - Registration</td>
                      <td className="py-2 text-right font-semibold">{formatCurrency(invoice.total_amount)}</td>
                    </tr>
                  )}
                  {isSplit && (
                    <tr className="text-brand-primary">
                      <td className="py-2">50/50 Split - Your portion</td>
                      <td className="py-2 text-right font-semibold">-{formatCurrency(amountRemaining / 2)}</td>
                    </tr>
                  )}
                  {isCrowdfunded && getTotalContributions() > 0 && (
                    <tr className="text-brand-primary">
                      <td className="py-2">Supporter contributions ({contributors.length} gifts)</td>
                      <td className="py-2 text-right font-semibold">-{formatCurrency(getTotalContributions())}</td>
                    </tr>
                  )}
                </tbody>
                <tfoot>
                  <tr className="border-t">
                    <td className="pt-4 font-bold text-gray-900">
                      {isSplit || isCrowdfunded ? 'Your Total' : 'Total'}
                    </td>
                    <td className="pt-4 text-right font-bold text-xl text-brand-primary">
                      {formatCurrency(getEffectiveAmount())}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>

            {/* Crowdfunding Progress - shown when enabled */}
            {isCrowdfunded && contributors.length > 0 && (
              <div className="px-6 py-4 bg-white border-b">
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span className="font-semibold text-brand-primary-dark">Supporter Contributions</span>
                  </div>
                  <button
                    onClick={handleDisableCrowdfunding}
                    className="text-brand-primary hover:text-brand-primary-hover text-sm"
                  >
                    Disable
                  </button>
                </div>

                {/* Progress bar */}
                <div className="mb-3">
                  <div className="flex justify-between text-sm mb-1">
                    <span className="text-brand-primary">{formatCurrency(getTotalContributions())} raised</span>
                    <span className="text-gray-500">of {formatCurrency(isSplit ? amountRemaining / 2 : amountRemaining)}</span>
                  </div>
                  <div className="h-3 bg-gray-200 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-brand-primary rounded-full transition-all duration-500"
                      style={{ width: `${Math.min(100, (getTotalContributions() / (isSplit ? amountRemaining / 2 : amountRemaining)) * 100)}%` }}
                    />
                  </div>
                </div>

                {/* Contributors list */}
                <div className="space-y-2">
                  {contributors.map((contributor, index) => (
                    <div key={index} className="bg-white rounded-lg p-3 border border-brand-primary">
                      <div className="flex justify-between items-start">
                        <div>
                          <p className="font-medium text-gray-900">{contributor.name}</p>
                          {contributor.message && (
                            <p className="text-sm text-gray-600 italic">"{contributor.message}"</p>
                          )}
                        </div>
                        <span className="font-semibold text-brand-primary">{formatCurrency(contributor.amount)}</span>
                      </div>
                    </div>
                  ))}
                </div>

                {/* Share link */}
                <div className="mt-3 pt-3 border-t border-brand-primary">
                  <p className="text-xs text-gray-500 mb-2">Share with more supporters:</p>
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={shareLink}
                      readOnly
                      className="flex-1 bg-white border border-gray-300 rounded px-2 py-1 text-xs font-mono text-gray-600"
                    />
                    <button
                      onClick={handleCopyLink}
                      className="px-3 py-1 bg-brand-primary text-white rounded text-xs font-medium hover:opacity-90"
                    >
                      {linkCopied ? 'Copied!' : 'Copy'}
                    </button>
                  </div>
                </div>
              </div>
            )}

            {/* Split & Crowdfund Options - shown when not split and not crowdfunded */}
            {!isSplit && !isCrowdfunded && (
              <div className="px-6 py-4 bg-gray-50 border-b space-y-3">
                {/* Split option */}
                <button
                  onClick={() => setShowSplitModal(true)}
                  className="w-full flex items-center justify-center gap-3 py-3 border-2 border-brand-primary border-dashed rounded-lg text-brand-primary hover:bg-gray-50 hover:border-brand-primary transition-colors bg-white"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                  </svg>
                  <span className="font-semibold">Split 50/50</span>
                  <span className="text-sm text-gray-500">with another person</span>
                </button>

                {/* Crowdfund option */}
                <button
                  onClick={() => setShowCrowdfundModal(true)}
                  className="w-full flex items-center justify-center gap-3 py-3 border-2 border-brand-primary border-dashed rounded-lg text-brand-primary hover:bg-gray-50 hover:border-brand-primary transition-colors bg-white"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span className="font-semibold">Invite Supporters</span>
                  <span className="text-sm text-gray-500">let anyone chip in</span>
                </button>
              </div>
            )}

            {/* Just Split option when crowdfunded */}
            {!isSplit && isCrowdfunded && (
              <div className="px-6 py-3 bg-gray-50 border-b">
                <button
                  onClick={() => setShowSplitModal(true)}
                  className="w-full flex items-center justify-center gap-3 py-2 text-brand-primary hover:text-brand-primary-hover transition-colors text-sm"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                  </svg>
                  <span className="font-medium">Also split remaining with another person?</span>
                </button>
              </div>
            )}

            {/* Payment Options */}
            <div className="p-6">
              <h2 className="text-lg font-bold text-gray-900 mb-4">Choose Payment Option</h2>

              <div className="space-y-3">
                {paymentPlans.map((plan) => (
                  <label
                    key={plan.id}
                    className={`block p-4 border-2 rounded-lg cursor-pointer transition-all ${
                      selectedPlan === plan.id
                        ? 'border-brand-primary bg-white'
                        : 'border-gray-200 hover:border-brand-primary'
                    }`}
                  >
                    <div className="flex items-start gap-3">
                      <input
                        type="radio"
                        name="paymentPlan"
                        value={plan.id}
                        checked={selectedPlan === plan.id}
                        onChange={() => setSelectedPlan(plan.id)}
                        className="mt-1"
                      />
                      <div className="flex-1">
                        <div>
                          <p className="font-semibold text-gray-900">{plan.name}</p>
                          <p className="text-sm text-gray-600">{plan.description}</p>
                        </div>

                        {selectedPlan === plan.id && (
                          <div className="mt-3 bg-white rounded border p-3">
                            <p className="text-sm font-medium text-gray-700 mb-2">Payment Schedule:</p>
                            <div className="space-y-1">
                              {plan.payments.map((payment, index) => (
                                <div key={index} className="flex justify-between text-sm">
                                  <span className={payment.dueDate === 'Today' ? 'font-semibold text-brand-primary' : 'text-gray-600'}>
                                    {payment.label} - {payment.dueDate}
                                  </span>
                                  <span className={payment.dueDate === 'Today' ? 'font-semibold text-brand-primary' : 'text-gray-900'}>
                                    {formatCurrency(payment.amount)}
                                  </span>
                                </div>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>
                    </div>
                  </label>
                ))}
              </div>

              <button
                onClick={handleProceedToPayment}
                className="w-full mt-6 bg-brand-primary hover:bg-brand-primary-hover text-white font-semibold py-4 rounded-lg text-lg transition-colors"
              >
                Continue to Payment - {formatCurrency(getAmountDueToday())} Due Today
              </button>

              <p className="text-center text-sm text-gray-500 mt-4">
                Secure payment processing
              </p>
            </div>
          </div>

          {/* Help text */}
          <div className="mt-6 text-center">
            <p className="text-sm text-gray-600">
              Questions? Contact us at{' '}
              <a href="mailto:support@teamselevated.com" className="text-brand-primary hover:underline">
                support@teamselevated.com
              </a>
            </p>
          </div>
        </div>
      </div>

      {/* Split Modal */}
      {renderSplitModal()}

      {/* Crowdfund Modal */}
      {renderCrowdfundModal()}
    </div>
  );
};

export default PaymentPage;

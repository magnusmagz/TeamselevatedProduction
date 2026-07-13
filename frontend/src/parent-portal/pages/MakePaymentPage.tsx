import React, { useState, useEffect, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { ParentHeader } from '../components/ParentHeader';

interface Invoice {
  id: number;
  athlete_id: number;
  athlete_name: string;
  description: string;
  total_amount: number;
  paid_amount: number;
  balance_due: number;
  status: string;
}

interface PaymentMethod {
  id: string;
  type: 'card' | 'bank';
  last4: string;
  brand?: string;
  isDefault: boolean;
}

export const MakePaymentPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const [searchParams] = useSearchParams();
  const { user } = useAuth();

  const invoiceId = searchParams.get('invoice');

  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [selectedInvoices, setSelectedInvoices] = useState<number[]>([]);
  const [paymentMethods, setPaymentMethods] = useState<PaymentMethod[]>([]);
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [paymentAmount, setPaymentAmount] = useState<'full' | 'custom'>('full');
  const [customAmount, setCustomAmount] = useState('');

  // Fetch outstanding invoices for the family. Reused after a payment to
  // refetch fresh balances so the UI reflects the recorded payment (PAR-17).
  const fetchInvoices = useCallback(async (): Promise<Invoice[]> => {
    const token = localStorage.getItem('auth_token');
    const invoicesRes = await fetch(
      `${API_URL}/api/invoices.php?action=family`,
      { headers: { Authorization: `Bearer ${token}` } }
    );
    const invoicesData = await invoicesRes.json();

    if (invoicesData.success && invoicesData.invoices) {
      const mapped: Invoice[] = invoicesData.invoices.map((inv: Record<string, unknown>) => ({
        id: inv.id,
        athlete_id: inv.athlete_id,
        athlete_name: `${inv.athlete_first || ''} ${inv.athlete_last || ''}`.trim(),
        description: inv.program_name || inv.memo || 'Invoice',
        total_amount: parseFloat(String(inv.total_amount || 0)),
        paid_amount: parseFloat(String(inv.amount_paid || 0)),
        balance_due: parseFloat(String(inv.amount_remaining || 0)),
        status: inv.is_overdue ? 'overdue' : (inv.status as string),
      }));
      const outstanding = mapped.filter((inv: Invoice) => inv.status !== 'paid');
      setInvoices(outstanding);
      return outstanding;
    }
    return [];
  }, [API_URL]);

  useEffect(() => {
    const fetchData = async () => {
      if (!user) return;

      setLoading(true);

      try {
        const outstanding = await fetchInvoices();

        // Pre-select invoice if specified in URL
        if (invoiceId) {
          setSelectedInvoices([parseInt(invoiceId)]);
        } else if (outstanding.length > 0) {
          setSelectedInvoices(outstanding.map((inv: Invoice) => inv.id));
        }

        // Saved payment methods intentionally not fetched — the endpoint ships with
        // the Stripe integration (Phase 5, docs/payments-stripe-implementation-plan.md).
        // paymentMethods stays empty and the method selector stays hidden until then.
      } catch (err) {
        setError('Failed to load payment information');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [API_URL, user, invoiceId, fetchInvoices]);

  const selectedTotal = invoices
    .filter((inv) => selectedInvoices.includes(inv.id))
    .reduce((sum, inv) => sum + inv.balance_due, 0);

  const payableAmount = paymentAmount === 'full'
    ? selectedTotal
    : parseFloat(customAmount) || 0;

  const toggleInvoice = (id: number) => {
    setSelectedInvoices((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  };

  const handleSubmit = async () => {
    if (selectedInvoices.length === 0) {
      setError('Please select at least one invoice');
      return;
    }

    if (payableAmount <= 0) {
      setError('Please enter a valid payment amount');
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const token = localStorage.getItem('auth_token');
      // Hosted Stripe Checkout: this endpoint validates ownership + balance and
      // returns a stripe.com URL. The webhook — not the redirect back — is what
      // marks invoices paid, so PaymentStatusPage re-polls after returning.
      const response = await fetch(`${API_URL}/api/checkout-sessions.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          invoice_ids: selectedInvoices,
          amount: payableAmount,
          return_context: 'parent',
        }),
      });

      const data = await response.json();

      if (response.ok && data.url) {
        window.location.href = data.url;
        return; // keep the button disabled while the browser navigates away
      }
      setError(data.error || 'Could not start payment');
    } catch (err) {
      setError('Payment processing failed. Please try again.');
    }
    setSubmitting(false);
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Make Payment" showBack />
        <div className="pt-14 flex items-center justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title="Make Payment" showBack />

      <div className="pt-14 pb-40 px-4">
        {error && (
          <div className="mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
            {error}
          </div>
        )}

        {invoices.length === 0 ? (
          <div className="text-center py-12">
            <svg
              className="mx-auto h-12 w-12 text-green-500"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">All Paid Up!</h3>
            <p className="mt-1 text-sm text-gray-500">
              You have no outstanding payments.
            </p>
          </div>
        ) : (
          <>
            {/* Invoice Selection */}
            <div className="mt-4">
              <h2 className="font-semibold text-brand-primary mb-3">Select Invoices</h2>
              <div className="space-y-2">
                {invoices.map((invoice) => (
                  <button
                    key={invoice.id}
                    onClick={() => toggleInvoice(invoice.id)}
                    className={`w-full text-left p-4 rounded-lg border transition-colors ${
                      selectedInvoices.includes(invoice.id)
                        ? 'bg-brand-secondary border-brand-primary'
                        : 'bg-white border-gray-200'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="font-medium text-gray-900">
                          {invoice.athlete_name}
                        </p>
                        <p className="text-sm text-gray-600">{invoice.description}</p>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="font-semibold text-gray-900">
                          ${invoice.balance_due.toFixed(2)}
                        </span>
                        <div
                          className={`w-5 h-5 rounded border-2 flex items-center justify-center ${
                            selectedInvoices.includes(invoice.id)
                              ? 'bg-brand-primary border-brand-primary'
                              : 'border-gray-300'
                          }`}
                        >
                          {selectedInvoices.includes(invoice.id) && (
                            <svg
                              className="w-3 h-3 text-white"
                              fill="currentColor"
                              viewBox="0 0 20 20"
                            >
                              <path
                                fillRule="evenodd"
                                d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                clipRule="evenodd"
                              />
                            </svg>
                          )}
                        </div>
                      </div>
                    </div>
                  </button>
                ))}
              </div>
            </div>

            {/* Payment Amount */}
            <div className="mt-6">
              <h2 className="font-semibold text-brand-primary mb-3">Payment Amount</h2>
              <div className="space-y-2">
                <button
                  onClick={() => setPaymentAmount('full')}
                  className={`w-full text-left p-4 rounded-lg border transition-colors ${
                    paymentAmount === 'full'
                      ? 'bg-brand-secondary border-brand-primary'
                      : 'bg-white border-gray-200'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <span className="font-medium">Pay Full Amount</span>
                    <span className="font-semibold">${selectedTotal.toFixed(2)}</span>
                  </div>
                </button>

                <button
                  onClick={() => setPaymentAmount('custom')}
                  className={`w-full text-left p-4 rounded-lg border transition-colors ${
                    paymentAmount === 'custom'
                      ? 'bg-brand-secondary border-brand-primary'
                      : 'bg-white border-gray-200'
                  }`}
                >
                  <span className="font-medium">Pay Custom Amount</span>
                </button>

                {paymentAmount === 'custom' && (
                  <div className="mt-2">
                    <div className="relative">
                      <span className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500">
                        $
                      </span>
                      <input
                        type="number"
                        value={customAmount}
                        onChange={(e) => setCustomAmount(e.target.value)}
                        placeholder="0.00"
                        min="0"
                        max={selectedTotal}
                        step="0.01"
                        className="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                      />
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* Payment Method */}
            {paymentMethods.length > 0 && (
              <div className="mt-6">
                <h2 className="font-semibold text-brand-primary mb-3">Payment Method</h2>
                <div className="space-y-2">
                  {paymentMethods.map((method) => (
                    <button
                      key={method.id}
                      onClick={() => setSelectedPaymentMethod(method.id)}
                      className={`w-full text-left p-4 rounded-lg border transition-colors ${
                        selectedPaymentMethod === method.id
                          ? 'bg-brand-secondary border-brand-primary'
                          : 'bg-white border-gray-200'
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-6 bg-gray-200 rounded flex items-center justify-center text-xs font-medium">
                          {method.brand || 'CARD'}
                        </div>
                        <span className="font-medium">•••• {method.last4}</span>
                        {method.isDefault && (
                          <span className="text-xs text-gray-500">Default</span>
                        )}
                      </div>
                    </button>
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Fixed Bottom Button - positioned above bottom nav */}
      {invoices.length > 0 && (
        <div
          className="fixed left-0 right-0 bg-white border-t border-gray-200 p-4 z-40"
          style={{ bottom: 'calc(4rem + var(--safe-area-inset-bottom, 0px))' }}
        >
          <div className="max-w-lg mx-auto">
            <div className="flex items-center justify-between mb-3">
              <span className="text-gray-600">Total</span>
              <span className="text-xl font-bold text-gray-900">
                ${payableAmount.toFixed(2)}
              </span>
            </div>
            <button
              onClick={handleSubmit}
              disabled={submitting || selectedInvoices.length === 0 || payableAmount <= 0}
              className="w-full py-3 bg-brand-primary text-white font-semibold rounded-lg hover:bg-brand-primary-hover transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {submitting ? 'Processing...' : 'Pay Now'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default MakePaymentPage;

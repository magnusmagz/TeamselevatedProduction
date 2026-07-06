import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
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
  due_date?: string;
  is_overdue: boolean;
  status: string;
}

interface InvoiceLineItem {
  id: number;
  description: string;
  quantity: number;
  unit_price: number;
  line_total: number;
}

interface InvoicePayment {
  transaction_id: number;
  payer_name: string;
  amount: number;
  payment_method: string;
  status: string;
  refunded: boolean;
  date: string;
}

interface InvoiceDetail {
  id: number;
  invoice_number?: string;
  athlete_name: string;
  program_name?: string;
  invoice_date?: string;
  due_date?: string;
  subtotal?: number;
  discount_amount?: number;
  total_amount: number;
  amount_paid: number;
  items: InvoiceLineItem[];
}

export const PaymentStatusPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<'all' | 'outstanding' | 'paid'>('outstanding');

  // Return leg of hosted Stripe Checkout (?checkout=success|cancelled). The
  // webhook applies the payment, which can lag the redirect by a few seconds —
  // refreshTick re-runs the invoice fetch a few times so balances catch up.
  const checkoutReturn = new URLSearchParams(window.location.search).get('checkout');
  const [refreshTick, setRefreshTick] = useState(0);

  // Invoice detail (line items) — lazily fetched per invoice on expand (PAR-16).
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [detailById, setDetailById] = useState<Record<number, InvoiceDetail>>({});
  // Who-paid-what ledger per invoice (split payments) — lazy, same trigger.
  const [paymentsById, setPaymentsById] = useState<Record<number, InvoicePayment[]>>({});
  const [detailLoadingId, setDetailLoadingId] = useState<number | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);

  useEffect(() => {
    const fetchInvoices = async () => {
      if (!user) return;

      if (refreshTick === 0) {
        setLoading(true); // re-polls refresh silently, no full-page spinner
      }
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(
          `${API_URL}/api/invoices.php?action=family`,
          {
            headers: { Authorization: `Bearer ${token}` },
          }
        );
        const data = await response.json();

        if (data.success && data.invoices) {
          // Map API field names to component field names
          const mapped = data.invoices.map((inv: Record<string, unknown>) => ({
            id: inv.id,
            athlete_id: inv.athlete_id,
            athlete_name: `${inv.athlete_first || ''} ${inv.athlete_last || ''}`.trim(),
            description: inv.program_name || inv.memo || 'Invoice',
            total_amount: parseFloat(String(inv.total_amount || 0)),
            paid_amount: parseFloat(String(inv.amount_paid || 0)),
            balance_due: parseFloat(String(inv.amount_remaining || 0)),
            due_date: inv.due_date as string | undefined,
            is_overdue: Boolean(inv.is_overdue),
            status: inv.is_overdue ? 'overdue' : (inv.status as string),
          }));
          setInvoices(mapped);
        } else {
          setError(data.error || 'Failed to load invoices');
        }
      } catch (err) {
        setError('Failed to load payment information');
      } finally {
        setLoading(false);
      }
    };

    fetchInvoices();
  }, [API_URL, user, refreshTick]);

  useEffect(() => {
    if (checkoutReturn !== 'success') return;
    const timers = [3000, 8000, 15000].map((ms) =>
      setTimeout(() => setRefreshTick((t) => t + 1), ms)
    );
    return () => timers.forEach(clearTimeout);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Toggle the line-item detail panel for an invoice; fetch it on first expand.
  const toggleDetail = async (invoiceId: number) => {
    if (expandedId === invoiceId) {
      setExpandedId(null);
      return;
    }

    setExpandedId(invoiceId);
    setDetailError(null);

    // Payment history loads alongside the line items; failures stay silent —
    // the ledger is supplementary to the invoice detail.
    if (!paymentsById[invoiceId]) {
      (async () => {
        try {
          const token = localStorage.getItem('auth_token');
          const res = await fetch(
            `${API_URL}/api/invoice-payments.php?invoice_id=${invoiceId}`,
            { headers: { Authorization: `Bearer ${token}` } }
          );
          const data = await res.json();
          if (data.success && Array.isArray(data.payments)) {
            setPaymentsById((prev) => ({ ...prev, [invoiceId]: data.payments }));
          }
        } catch {
          // supplementary data — ignore
        }
      })();
    }

    // Already loaded — reuse cached detail.
    if (detailById[invoiceId]) {
      return;
    }

    setDetailLoadingId(invoiceId);
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(
        `${API_URL}/api/invoices.php?action=get&id=${invoiceId}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      const data = await response.json();

      if (data.success && data.invoice) {
        const inv = data.invoice as Record<string, unknown>;
        const detail: InvoiceDetail = {
          id: Number(inv.id),
          invoice_number: inv.invoice_number as string | undefined,
          athlete_name: `${inv.athlete_first || ''} ${inv.athlete_last || ''}`.trim(),
          program_name: inv.program_name as string | undefined,
          invoice_date: inv.invoice_date as string | undefined,
          due_date: inv.due_date as string | undefined,
          subtotal: inv.subtotal != null ? parseFloat(String(inv.subtotal)) : undefined,
          discount_amount: inv.discount_amount != null ? parseFloat(String(inv.discount_amount)) : undefined,
          total_amount: parseFloat(String(inv.total_amount || 0)),
          amount_paid: parseFloat(String(inv.amount_paid || 0)),
          items: Array.isArray(inv.items)
            ? (inv.items as Record<string, unknown>[]).map((it) => ({
                id: Number(it.id),
                description: String(it.description ?? it.item_name ?? ''),
                quantity: it.quantity != null ? Number(it.quantity) : 1,
                unit_price: parseFloat(String(it.unit_price || 0)),
                line_total: parseFloat(String(it.line_total || 0)),
              }))
            : [],
        };
        setDetailById((prev) => ({ ...prev, [invoiceId]: detail }));
      } else {
        setDetailError(data.error || 'Failed to load invoice details');
      }
    } catch (err) {
      setDetailError('Failed to load invoice details');
    } finally {
      setDetailLoadingId(null);
    }
  };

  const filteredInvoices = invoices.filter((inv) => {
    if (filter === 'outstanding') return inv.status !== 'paid';
    if (filter === 'paid') return inv.status === 'paid';
    return true;
  });

  const totalOutstanding = invoices
    .filter((inv) => inv.status !== 'paid')
    .reduce((sum, inv) => sum + inv.balance_due, 0);

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      sent: 'bg-blue-100 text-blue-800',
      viewed: 'bg-blue-100 text-blue-800',
      pending: 'bg-yellow-100 text-yellow-800',
      partial: 'bg-blue-100 text-blue-800',
      paid: 'bg-green-100 text-green-800',
      overdue: 'bg-red-100 text-red-800',
    };
    const label = status.charAt(0).toUpperCase() + status.slice(1);
    return (
      <span className={`px-2 py-0.5 text-xs font-medium rounded ${styles[status] || 'bg-gray-100 text-gray-800'}`}>
        {label}
      </span>
    );
  };

  const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title="Payments" showBack />

      <div className="pt-14 pb-4">
        {checkoutReturn === 'success' && (
          <div className="mx-4 mt-3 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            Payment received — thank you! Balances update automatically within a few seconds.
          </div>
        )}
        {checkoutReturn === 'cancelled' && (
          <div className="mx-4 mt-3 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            Checkout was cancelled — no payment was made.
          </div>
        )}

        {/* Summary Card */}
        <div className="bg-brand-primary text-white px-4 py-6">
          <p className="text-sm opacity-80">Total Outstanding</p>
          <p className="text-3xl font-bold">${totalOutstanding.toFixed(2)}</p>
        </div>

        {/* Filter Tabs */}
        <div className="flex border-b border-gray-200 bg-white">
          {(['outstanding', 'paid', 'all'] as const).map((tab) => (
            <button
              key={tab}
              onClick={() => setFilter(tab)}
              className={`flex-1 py-3 text-sm font-medium border-b-2 transition-colors ${
                filter === tab
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              {tab.charAt(0).toUpperCase() + tab.slice(1)}
            </button>
          ))}
        </div>

        {/* Loading State */}
        {loading && (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
          </div>
        )}

        {/* Error State */}
        {error && (
          <div className="mx-4 mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
            {error}
          </div>
        )}

        {/* Empty State */}
        {!loading && !error && filteredInvoices.length === 0 && (
          <div className="text-center py-12 px-4">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">
              {filter === 'outstanding'
                ? 'No Outstanding Payments'
                : filter === 'paid'
                ? 'No Payment History'
                : 'No Invoices Found'}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {filter === 'outstanding'
                ? "You're all caught up!"
                : 'Invoices will appear here when available.'}
            </p>
          </div>
        )}

        {/* Invoice List */}
        {!loading && !error && filteredInvoices.length > 0 && (
          <div className="px-4 py-4 space-y-3">
            {filteredInvoices.map((invoice) => {
              const isExpanded = expandedId === invoice.id;
              const detail = detailById[invoice.id];
              return (
              <div
                key={invoice.id}
                data-testid={`invoice-card-${invoice.id}`}
                className={`bg-white rounded-lg shadow-sm border p-4 ${
                  invoice.is_overdue
                    ? 'border-red-300 ring-1 ring-red-200 bg-red-50'
                    : 'border-gray-200'
                }`}
              >
                <button
                  type="button"
                  onClick={() => toggleDetail(invoice.id)}
                  aria-expanded={isExpanded}
                  className="w-full text-left"
                >
                  <div className="flex items-start justify-between mb-2">
                    <div>
                      <p className="font-semibold text-gray-900">{invoice.athlete_name}</p>
                      <p className="text-sm text-gray-600">{invoice.description}</p>
                    </div>
                    {getStatusBadge(invoice.status)}
                  </div>

                  <div className="flex items-center justify-between text-sm">
                    <div>
                      {invoice.due_date && (
                        <p className={invoice.is_overdue ? 'text-red-600 font-medium' : 'text-gray-500'}>
                          {invoice.is_overdue ? 'Overdue: ' : 'Due: '}
                          {formatDate(invoice.due_date)}
                        </p>
                      )}
                      <p className="text-xs text-brand-primary mt-1">
                        {isExpanded ? 'Hide details' : 'View details'}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="font-semibold text-gray-900">
                        ${invoice.balance_due.toFixed(2)}
                      </p>
                      {invoice.paid_amount > 0 && (
                        <p className="text-xs text-gray-500">
                          Paid: ${invoice.paid_amount.toFixed(2)} of ${invoice.total_amount.toFixed(2)}
                        </p>
                      )}
                    </div>
                  </div>
                </button>

                {/* Invoice detail: line items + amounts + athlete name (PAR-16) */}
                {isExpanded && (
                  <div className="mt-3 pt-3 border-t border-gray-200" data-testid={`invoice-detail-${invoice.id}`}>
                    {detailLoadingId === invoice.id && (
                      <div className="flex items-center justify-center py-4">
                        <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-brand-primary"></div>
                      </div>
                    )}

                    {detailError && detailLoadingId !== invoice.id && !detail && (
                      <p className="text-sm text-red-600">{detailError}</p>
                    )}

                    {detail && (
                      <div>
                        <div className="flex items-center justify-between mb-2">
                          <p className="text-sm font-medium text-gray-900">
                            {detail.athlete_name || invoice.athlete_name}
                          </p>
                          {detail.invoice_number && (
                            <p className="text-xs font-mono text-gray-500">{detail.invoice_number}</p>
                          )}
                        </div>

                        <table className="w-full text-sm">
                          <thead>
                            <tr className="text-left text-xs text-gray-500">
                              <th className="pb-1 font-normal">Description</th>
                              <th className="pb-1 font-normal text-right">Amount</th>
                            </tr>
                          </thead>
                          <tbody>
                            {detail.items.length > 0 ? (
                              detail.items.map((item) => (
                                <tr key={item.id}>
                                  <td className="py-1 text-gray-900">
                                    {item.description}
                                    {item.quantity > 1 && (
                                      <span className="text-gray-400"> &times;{item.quantity}</span>
                                    )}
                                  </td>
                                  <td className="py-1 text-right font-medium text-gray-900">
                                    ${item.line_total.toFixed(2)}
                                  </td>
                                </tr>
                              ))
                            ) : (
                              <tr>
                                <td className="py-1 text-gray-900">
                                  {detail.program_name || invoice.description}
                                </td>
                                <td className="py-1 text-right font-medium text-gray-900">
                                  ${detail.total_amount.toFixed(2)}
                                </td>
                              </tr>
                            )}
                          </tbody>
                          <tfoot>
                            <tr className="border-t border-gray-200">
                              <td className="pt-2 font-semibold text-gray-900">Total</td>
                              <td className="pt-2 text-right font-semibold text-gray-900">
                                ${detail.total_amount.toFixed(2)}
                              </td>
                            </tr>
                            {detail.amount_paid > 0 && (
                              <tr>
                                <td className="pt-1 text-gray-500">Paid</td>
                                <td className="pt-1 text-right text-gray-500">
                                  ${detail.amount_paid.toFixed(2)}
                                </td>
                              </tr>
                            )}
                          </tfoot>
                        </table>

                        {(paymentsById[invoice.id]?.length ?? 0) > 0 && (
                          <div className="mt-3 pt-3 border-t border-gray-100">
                            <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">
                              Payment history
                            </p>
                            <ul className="space-y-1.5">
                              {paymentsById[invoice.id].map((p) => (
                                <li key={p.transaction_id} className="flex items-center justify-between text-sm">
                                  <span className="text-gray-700">
                                    {p.payer_name}
                                    <span className="text-gray-400"> · {formatDate(p.date)}</span>
                                    {p.refunded && (
                                      <span className="ml-1.5 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5">
                                        refunded
                                      </span>
                                    )}
                                  </span>
                                  <span className={`font-medium ${p.refunded ? 'text-gray-400 line-through' : 'text-gray-900'}`}>
                                    ${p.amount.toFixed(2)}
                                  </span>
                                </li>
                              ))}
                            </ul>
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                )}

                {invoice.status !== 'paid' && (
                  <Link
                    to={`/parent/pay/${invoice.id}`}
                    className="mt-3 block w-full text-center py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover transition-colors"
                  >
                    Pay Now
                  </Link>
                )}
              </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
};

export default PaymentStatusPage;

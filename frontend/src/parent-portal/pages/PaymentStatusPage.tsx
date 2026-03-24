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
  status: string;
}

export const PaymentStatusPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<'all' | 'outstanding' | 'paid'>('outstanding');

  useEffect(() => {
    const fetchInvoices = async () => {
      if (!user) return;

      setLoading(true);
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
  }, [API_URL, user]);

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
        {/* Summary Card */}
        <div className="bg-brand-primary text-white px-4 py-6">
          <p className="text-sm opacity-80">Total Outstanding</p>
          <p className="text-3xl font-bold">${totalOutstanding.toFixed(2)}</p>
          {totalOutstanding > 0 && (
            <Link
              to="/parent/payments/checkout"
              className="inline-block mt-4 bg-white text-brand-primary px-6 py-2 rounded-lg font-medium hover:bg-gray-100 transition-colors"
            >
              Make a Payment
            </Link>
          )}
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
            {filteredInvoices.map((invoice) => (
              <div
                key={invoice.id}
                className="bg-white rounded-lg shadow-sm border border-gray-200 p-4"
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
                      <p className="text-gray-500">
                        Due: {formatDate(invoice.due_date)}
                      </p>
                    )}
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

                {invoice.status !== 'paid' && (
                  <Link
                    to={`/parent/payments/checkout?invoice=${invoice.id}`}
                    className="mt-3 block w-full text-center py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover transition-colors"
                  >
                    Pay Now
                  </Link>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

export default PaymentStatusPage;

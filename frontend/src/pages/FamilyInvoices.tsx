import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

interface Athlete {
  id: number;
  first_name: string;
  last_name: string;
}

interface Invoice {
  id: number;
  invoice_number: string;
  athlete_id: number;
  athlete_first: string;
  athlete_last: string;
  athlete_payment_id: number | null;
  program_name: string;
  invoice_date: string;
  due_date: string;
  total_amount: string;
  amount_paid: string;
  amount_remaining: string;
  status: string;
  is_overdue: boolean;
}

interface Summary {
  total_outstanding: number;
  total_paid: number;
  overdue_count: number;
  by_athlete: Record<number, {
    name: string;
    outstanding: number;
    paid: number;
  }>;
}

/**
 * Family Invoices Page
 * Shows all invoices across all children for a parent/guardian
 */
export const FamilyInvoices: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [athletes, setAthletes] = useState<Athlete[]>([]);
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedAthlete, setSelectedAthlete] = useState<string>('all');
  const [selectedStatus, setSelectedStatus] = useState<string>('all');

  useEffect(() => {
    if (!user?.id) return;

    // Fetch family invoices using guardian_id
    // Note: In production, get guardian_id from user context or API
    const token = localStorage.getItem('auth_token');
    fetch(`${process.env.REACT_APP_API_URL}/api/invoices.php?action=family`, {
      headers: { 'Authorization': `Bearer ${token}` }
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setAthletes(data.athletes);
          setInvoices(data.invoices);
          setSummary(data.summary);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching family invoices:', err);
        setLoading(false);
      });
  }, [user?.id]);

  const formatCurrency = (amount: number | string) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount;
    return `$${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      sent: 'bg-blue-100 text-blue-800',
      viewed: 'bg-yellow-100 text-yellow-800',
      paid: 'bg-green-100 text-green-800',
      overdue: 'bg-red-100 text-red-800',
      cancelled: 'bg-gray-100 text-gray-500',
    };
    return colors[status] || 'bg-gray-100 text-gray-800';
  };

  // Filter invoices
  const filteredInvoices = invoices.filter(inv => {
    if (selectedAthlete !== 'all' && inv.athlete_id !== parseInt(selectedAthlete)) {
      return false;
    }
    if (selectedStatus !== 'all' && inv.status !== selectedStatus) {
      return false;
    }
    return true;
  });

  // Get unpaid invoices for Pay All
  const unpaidInvoices = filteredInvoices.filter(
    inv => inv.status !== 'paid' && inv.status !== 'cancelled'
  );

  const handlePayAll = () => {
    // Store invoice payment IDs in session and navigate to multi-checkout
    const paymentIds = unpaidInvoices
      .filter(inv => inv.athlete_payment_id)
      .map(inv => inv.athlete_payment_id);

    if (paymentIds.length > 0) {
      sessionStorage.setItem('pendingPayments', JSON.stringify(paymentIds));
      navigate('/payment/multi-checkout');
    }
  };

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Loading family invoices...</p>
        </div>
      </div>
    );
  }

  if (athletes.length === 0) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">No athletes linked to your account.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <div className="mb-6">
        <h1 className="text-3xl font-bold mb-2">Family Invoices</h1>
        <p className="text-gray-600">View and pay invoices for all your athletes</p>
      </div>

      {/* Summary Cards */}
      {summary && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
            <div className="text-sm text-gray-600 mb-1">Athletes</div>
            <div className="text-2xl font-bold text-gray-900">{athletes.length}</div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
            <div className="text-sm text-gray-600 mb-1">Total Outstanding</div>
            <div className="text-2xl font-bold text-orange-600">
              {formatCurrency(summary.total_outstanding)}
            </div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
            <div className="text-sm text-gray-600 mb-1">Total Paid</div>
            <div className="text-2xl font-bold text-green-600">
              {formatCurrency(summary.total_paid)}
            </div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-red-500">
            <div className="text-sm text-gray-600 mb-1">Overdue</div>
            <div className="text-2xl font-bold text-red-600">{summary.overdue_count}</div>
          </div>
        </div>
      )}

      {/* Filters */}
      <div className="bg-white shadow rounded-lg p-4 mb-6">
        <div className="flex flex-wrap gap-4 items-end">
          <div className="flex-1 min-w-[150px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Athlete</label>
            <select
              value={selectedAthlete}
              onChange={(e) => setSelectedAthlete(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="all">All Athletes</option>
              {athletes.map(athlete => (
                <option key={athlete.id} value={athlete.id}>
                  {athlete.first_name} {athlete.last_name}
                </option>
              ))}
            </select>
          </div>

          <div className="flex-1 min-w-[150px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="all">All Status</option>
              <option value="sent">Sent</option>
              <option value="viewed">Viewed</option>
              <option value="overdue">Overdue</option>
              <option value="paid">Paid</option>
            </select>
          </div>

          {unpaidInvoices.length > 0 && (
            <button
              onClick={handlePayAll}
              className="px-6 py-2 bg-brand-primary hover:bg-brand-primary-hover text-white font-semibold rounded"
            >
              Pay All ({unpaidInvoices.length})
            </button>
          )}
        </div>
      </div>

      {/* Per-Athlete Summary */}
      {summary && Object.keys(summary.by_athlete).length > 1 && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          {Object.entries(summary.by_athlete).map(([athleteId, data]) => (
            <div
              key={athleteId}
              onClick={() => setSelectedAthlete(athleteId)}
              className={`bg-white p-4 rounded-lg shadow cursor-pointer hover:shadow-md transition-shadow ${
                selectedAthlete === athleteId ? 'ring-2 ring-brand-primary' : ''
              }`}
            >
              <div className="font-semibold text-gray-900 mb-2">{data.name}</div>
              <div className="flex justify-between text-sm">
                <span className="text-gray-600">Outstanding:</span>
                <span className="font-semibold text-orange-600">{formatCurrency(data.outstanding)}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-gray-600">Paid:</span>
                <span className="font-semibold text-green-600">{formatCurrency(data.paid)}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Invoices Table */}
      {filteredInvoices.length === 0 ? (
        <div className="bg-white shadow rounded-lg p-10 text-center">
          <p className="text-gray-500">No invoices found.</p>
        </div>
      ) : (
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Invoice
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Athlete
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Program
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Due Date
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                  Amount
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Status
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                  Action
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {filteredInvoices.map(invoice => (
                <tr key={invoice.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm font-mono font-medium text-gray-900">
                      {invoice.invoice_number}
                    </div>
                    <div className="text-xs text-gray-500">
                      {new Date(invoice.invoice_date).toLocaleDateString()}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm text-gray-900">
                      {invoice.athlete_first} {invoice.athlete_last}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    {invoice.program_name}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className={`text-sm ${invoice.is_overdue ? 'text-red-600 font-semibold' : 'text-gray-900'}`}>
                      {new Date(invoice.due_date).toLocaleDateString()}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-right">
                    <div className="text-sm font-semibold text-gray-900">
                      {formatCurrency(invoice.total_amount)}
                    </div>
                    {parseFloat(invoice.amount_remaining) > 0 && parseFloat(invoice.amount_remaining) < parseFloat(invoice.total_amount) && (
                      <div className="text-xs text-orange-600">
                        {formatCurrency(invoice.amount_remaining)} remaining
                      </div>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 py-1 text-xs font-semibold rounded-full ${getStatusColor(invoice.status)}`}>
                      {invoice.status.toUpperCase()}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-right">
                    {invoice.status !== 'paid' && invoice.status !== 'cancelled' && invoice.athlete_payment_id && (
                      <button
                        onClick={() => navigate(`/payment/checkout/${invoice.athlete_id}/${invoice.athlete_payment_id}`)}
                        className="text-brand-primary hover:text-brand-primary-dark text-sm font-medium"
                      >
                        Pay Now
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </div>
      )}
    </div>
  );
};

export default FamilyInvoices;

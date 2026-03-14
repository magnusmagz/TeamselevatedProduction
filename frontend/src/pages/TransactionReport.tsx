import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';

interface Transaction {
  id: number;
  transaction_id: string;
  date: string;
  athlete_id: number;
  athlete_name: string;
  guardian_name: string;
  guardian_email: string;
  program_name: string;
  item_name: string;
  item_type: string;
  accounting_code: string;
  amount: number;
  status: string;
  payment_type: string;
  payment_method: string;
  installment_number: number | null;
  refund_amount: number;
  refund_reason: string | null;
}

interface Summary {
  total_transactions: number;
  total_amount: number;
  total_refunds: number;
  net_amount: number;
  by_status: Record<string, { count: number; amount: number }>;
  by_payment_type: Record<string, { count: number; amount: number }>;
}

interface Program {
  id: number;
  name: string;
}

/**
 * Admin: Transaction Report
 * Comprehensive transaction reporting with filters and export
 */
export const TransactionReport: React.FC = () => {
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [programs, setPrograms] = useState<Program[]>([]);
  const [loading, setLoading] = useState(true);

  // Filters
  const [selectedProgram, setSelectedProgram] = useState('');
  const [selectedStatus, setSelectedStatus] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [paymentType, setPaymentType] = useState('');

  // Fetch programs for filter dropdown
  useEffect(() => {
    fetch(`${process.env.REACT_APP_API_URL}/api/programs.php?club_id=13`)
      .then(res => res.json())
      .then(data => {
        if (data.success && data.programs) {
          setPrograms(data.programs);
        }
      })
      .catch(err => console.error('Error fetching programs:', err));
  }, []);

  // Fetch transactions
  useEffect(() => {
    fetchTransactions();
  }, [selectedProgram, selectedStatus, dateFrom, dateTo, paymentType]);

  const fetchTransactions = () => {
    setLoading(true);

    let url = `${process.env.REACT_APP_API_URL}/api/transaction-report.php?club_id=13`;
    if (selectedProgram) url += `&program_id=${selectedProgram}`;
    if (selectedStatus) url += `&status=${selectedStatus}`;
    if (dateFrom) url += `&date_from=${dateFrom}`;
    if (dateTo) url += `&date_to=${dateTo}`;
    if (paymentType) url += `&payment_type=${paymentType}`;

    fetch(url)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setTransactions(data.transactions);
          setSummary(data.summary);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching transactions:', err);
        setLoading(false);
      });
  };

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      succeeded: 'bg-green-100 text-green-800',
      pending: 'bg-yellow-100 text-yellow-800',
      failed: 'bg-red-100 text-red-800',
      refunded: 'bg-purple-100 text-purple-800',
    };
    return colors[status] || 'bg-gray-100 text-gray-800';
  };

  const handleExportCSV = () => {
    let url = `${process.env.REACT_APP_API_URL}/api/transaction-report.php?club_id=13&export=csv`;
    if (selectedProgram) url += `&program_id=${selectedProgram}`;
    if (selectedStatus) url += `&status=${selectedStatus}`;
    if (dateFrom) url += `&date_from=${dateFrom}`;
    if (dateTo) url += `&date_to=${dateTo}`;
    if (paymentType) url += `&payment_type=${paymentType}`;

    window.location.href = url;
  };

  const clearFilters = () => {
    setSelectedProgram('');
    setSelectedStatus('');
    setDateFrom('');
    setDateTo('');
    setPaymentType('');
  };

  const hasFilters = selectedProgram || selectedStatus || dateFrom || dateTo || paymentType;

  return (
    <div className="container mx-auto p-6">
      <div className="flex justify-between items-center mb-6">
        <div>
          <h1 className="text-3xl font-bold">Transaction Report</h1>
          <p className="text-gray-600">View and export payment transactions</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={handleExportCSV}
            className="px-4 py-2 bg-brand-primary text-white rounded hover:bg-brand-primary-hover flex items-center gap-2"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Export CSV
          </button>
        </div>
      </div>

      {/* Summary Cards */}
      {summary && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
            <div className="text-sm text-gray-600 mb-1">Total Transactions</div>
            <div className="text-2xl font-bold text-gray-900">{summary.total_transactions}</div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
            <div className="text-sm text-gray-600 mb-1">Total Collected</div>
            <div className="text-2xl font-bold text-brand-primary">{formatCurrency(summary.total_amount)}</div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-red-500">
            <div className="text-sm text-gray-600 mb-1">Total Refunds</div>
            <div className="text-2xl font-bold text-red-600">{formatCurrency(summary.total_refunds)}</div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-purple-500">
            <div className="text-sm text-gray-600 mb-1">Net Revenue</div>
            <div className="text-2xl font-bold text-purple-600">{formatCurrency(summary.net_amount)}</div>
          </div>
        </div>
      )}

      {/* Filters */}
      <div className="bg-white shadow rounded-lg p-4 mb-6">
        <div className="flex flex-wrap gap-4 items-end">
          <div className="flex-1 min-w-[150px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Program</label>
            <select
              value={selectedProgram}
              onChange={(e) => setSelectedProgram(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Programs</option>
              {programs.map(program => (
                <option key={program.id} value={program.id}>{program.name}</option>
              ))}
            </select>
          </div>

          <div className="flex-1 min-w-[120px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Status</option>
              <option value="succeeded">Succeeded</option>
              <option value="pending">Pending</option>
              <option value="failed">Failed</option>
              <option value="refunded">Refunded</option>
            </select>
          </div>

          <div className="flex-1 min-w-[120px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Payment Type</label>
            <select
              value={paymentType}
              onChange={(e) => setPaymentType(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Types</option>
              <option value="full">Full Payment</option>
              <option value="installment">Installment</option>
              <option value="partial">Partial</option>
            </select>
          </div>

          <div className="flex-1 min-w-[140px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Date From</label>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>

          <div className="flex-1 min-w-[140px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Date To</label>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>

          {hasFilters && (
            <button
              onClick={clearFilters}
              className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded"
            >
              Clear Filters
            </button>
          )}
        </div>
      </div>

      {/* Transactions Table */}
      {loading ? (
        <div className="text-center py-10">
          <p className="text-gray-500">Loading transactions...</p>
        </div>
      ) : transactions.length === 0 ? (
        <div className="bg-white shadow rounded-lg p-10 text-center">
          <p className="text-gray-500">No transactions found.</p>
          {hasFilters && (
            <button
              onClick={clearFilters}
              className="mt-4 text-brand-primary hover:text-brand-primary-dark"
            >
              Clear filters to see all transactions
            </button>
          )}
        </div>
      ) : (
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Transaction ID</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Athlete</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Program</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acct Code</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {transactions.map(txn => (
                  <tr key={txn.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 whitespace-nowrap">
                      <div className="text-sm font-mono text-gray-900">{txn.transaction_id}</div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                      {formatDate(txn.date)}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <Link
                        to={`/athlete/${txn.athlete_id}/payments`}
                        className="text-sm font-medium text-brand-primary hover:text-brand-primary-dark"
                      >
                        {txn.athlete_name}
                      </Link>
                      <div className="text-xs text-gray-500">{txn.guardian_name}</div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                      {txn.program_name}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                      {txn.item_name}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500 font-mono">
                      {txn.accounting_code || '—'}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm">
                      <span className="capitalize">{txn.payment_type}</span>
                      {txn.installment_number && (
                        <span className="text-gray-500 text-xs ml-1">
                          (#{txn.installment_number})
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-sm text-right">
                      <div className="font-semibold text-gray-900">{formatCurrency(txn.amount)}</div>
                      {txn.refund_amount > 0 && (
                        <div className="text-xs text-red-600">
                          Refunded: {formatCurrency(txn.refund_amount)}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span className={`px-2 py-1 inline-flex text-xs font-semibold rounded-full ${getStatusColor(txn.status)}`}>
                        {txn.status}
                      </span>
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

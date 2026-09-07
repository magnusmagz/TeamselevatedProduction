import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';

interface Payment {
  id: number;
  item_name: string;
  program_name: string;
  amount: string;
  due_date: string | null;
  status: string;
}

interface Balance {
  athlete_id: number;
  athlete_name: string;
  guardian_name: string | null;
  guardian_email: string | null;
  guardian_phone: string | null;
  payment_count: number;
  total_owed: string;
  total_paid: string;
  total_remaining: string;
  earliest_due_date: string | null;
  days_overdue: number;
  has_overdue: boolean;
  payments: Payment[];
}

interface Summary {
  total_outstanding: number;
  families_count: number;
  overdue_count: number;
}

/**
 * Treasurer: Outstanding Balances Report
 * Shows families with unpaid balances
 */
export const OutstandingBalances: React.FC = () => {
  const { currentClubId, activeContext } = useOrg();
  const clubId = currentClubId ?? activeContext?.scope_id ?? null;
  const [balances, setBalances] = useState<Balance[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [sortBy, setSortBy] = useState<'amount' | 'days_overdue' | 'name'>('amount');
  const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());
  const [sendingReminder, setSendingReminder] = useState<number | null>(null);

  useEffect(() => {
    fetchBalances();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sortBy, clubId]);

  const fetchBalances = async () => {
    if (clubId == null) {
      setLoading(false);
      return;
    }
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(
        `${process.env.REACT_APP_API_URL}/api/outstanding-balances.php?club_id=${clubId}&sort_by=${sortBy}`,
        { headers: { 'Authorization': `Bearer ${token}` } }
      );
      const data = await response.json();
      if (data.success) {
        setSummary(data.summary);
        setBalances(data.balances);
      }
    } catch (err) {
      console.error('Error fetching outstanding balances:', err);
    } finally {
      setLoading(false);
    }
  };

  const formatCurrency = (amount: string | number) => {
    return `$${parseFloat(amount.toString()).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const toggleRow = (athleteId: number) => {
    const newExpanded = new Set(expandedRows);
    if (newExpanded.has(athleteId)) {
      newExpanded.delete(athleteId);
    } else {
      newExpanded.add(athleteId);
    }
    setExpandedRows(newExpanded);
  };

  const handleSendReminder = async (balance: Balance) => {
    if (!balance.guardian_email) {
      alert('No email address on file for this family.');
      return;
    }

    setSendingReminder(balance.athlete_id);

    // Simulate sending reminder (would call actual API)
    setTimeout(() => {
      setSendingReminder(null);
      alert(`Reminder sent to ${balance.guardian_email}`);
    }, 1000);
  };

  const exportToCSV = () => {
    const headers = ['Athlete', 'Guardian', 'Email', 'Phone', 'Total Owed', 'Total Paid', 'Remaining', 'Days Overdue'];
    const rows = balances.map(b => [
      b.athlete_name,
      b.guardian_name || '',
      b.guardian_email || '',
      b.guardian_phone || '',
      b.total_owed,
      b.total_paid,
      b.total_remaining,
      b.days_overdue.toString()
    ]);

    const csv = [headers, ...rows].map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `outstanding-balances-${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
  };

  const paymentColumns: DataTableColumn<Payment>[] = [
    { key: 'item_name', header: 'Item', render: (payment) => payment.item_name },
    { key: 'program_name', header: 'Program', render: (payment) => payment.program_name },
    {
      key: 'due_date',
      header: 'Due Date',
      render: (payment) =>
        payment.due_date
          ? new Date(payment.due_date).toLocaleDateString()
          : '-',
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      render: (payment) => <span className="font-semibold">{formatCurrency(payment.amount)}</span>,
    },
  ];

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Loading outstanding balances...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <PageHeader
        title="Outstanding Balances"
        subtitle="Families with unpaid registration fees"
        actions={
          <Button
            onClick={exportToCSV}
            leadingIcon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            }
          >
            Export CSV
          </Button>
        }
      />

      {/* Summary Cards */}
      {summary && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
            <div className="text-sm text-gray-600 mb-1">Total Outstanding</div>
            <div className="text-2xl font-bold text-orange-600">{formatCurrency(summary.total_outstanding)}</div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
            <div className="text-sm text-gray-600 mb-1">Families with Balance</div>
            <div className="text-2xl font-bold text-blue-600">{summary.families_count}</div>
          </div>

          <div className="bg-white p-6 rounded-lg shadow border-l-4 border-red-500">
            <div className="text-sm text-gray-600 mb-1">Overdue Accounts</div>
            <div className="text-2xl font-bold text-red-600">{summary.overdue_count}</div>
          </div>
        </div>
      )}

      {/* Sort Controls */}
      <div className="bg-white shadow rounded-lg mb-4">
        <div className="px-6 py-4 border-b flex justify-between items-center">
          <h2 className="text-xl font-bold">Balances by Family</h2>
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-600">Sort by:</label>
            <select
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value as any)}
              className="border border-gray-300 rounded px-3 py-1"
            >
              <option value="amount">Amount (High to Low)</option>
              <option value="days_overdue">Days Overdue</option>
              <option value="name">Name (A-Z)</option>
            </select>
          </div>
        </div>

        {balances.length === 0 ? (
          <div className="text-center py-10">
            <p className="text-gray-500">No outstanding balances found.</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-200">
            {balances.map(balance => (
              <div key={balance.athlete_id} className="hover:bg-gray-50">
                <div
                  className="p-4 cursor-pointer"
                  onClick={() => toggleRow(balance.athlete_id)}
                >
                  <div className="flex justify-between items-center">
                    <div className="flex items-center gap-4">
                      <Button variant="ghost" size="icon" aria-label={expandedRows.has(balance.athlete_id) ? 'Collapse' : 'Expand'} tabIndex={-1}>
                        <svg className={`w-5 h-5 transition-transform ${expandedRows.has(balance.athlete_id) ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                        </svg>
                      </Button>
                      <div>
                        <div className="flex items-center gap-2">
                          <Link
                            to={`/athlete/${balance.athlete_id}/payments`}
                            className="font-semibold text-brand-primary hover:text-brand-primary-dark"
                            onClick={(e) => e.stopPropagation()}
                          >
                            {balance.athlete_name}
                          </Link>
                          {balance.has_overdue && (
                            <span className="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                              OVERDUE
                            </span>
                          )}
                        </div>
                        <div className="text-sm text-gray-600">
                          {balance.guardian_name && <span>{balance.guardian_name}</span>}
                          {balance.guardian_email && <span className="ml-2 text-gray-400">{balance.guardian_email}</span>}
                        </div>
                      </div>
                    </div>

                    <div className="flex items-center gap-6">
                      {balance.days_overdue > 0 && (
                        <div className="text-right">
                          <div className="text-xs text-gray-500">Days Overdue</div>
                          <div className="text-lg font-bold text-red-600">{balance.days_overdue}</div>
                        </div>
                      )}
                      <div className="text-right">
                        <div className="text-xs text-gray-500">Outstanding</div>
                        <div className="text-xl font-bold text-orange-600">{formatCurrency(balance.total_remaining)}</div>
                      </div>
                      <Button
                        variant="secondary"
                        size="sm"
                        onClick={(e) => {
                          e.stopPropagation();
                          handleSendReminder(balance);
                        }}
                        loading={sendingReminder === balance.athlete_id}
                      >
                        Send Reminder
                      </Button>
                    </div>
                  </div>
                </div>

                {/* Expanded Details */}
                {expandedRows.has(balance.athlete_id) && (
                  <div className="px-4 pb-4 bg-gray-50">
                    <div className="ml-9 border-l-2 border-gray-200 pl-4">
                      <DataTable<Payment>
                        columns={paymentColumns}
                        rows={balance.payments}
                        rowKey={(payment) => payment.id}
                      />
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

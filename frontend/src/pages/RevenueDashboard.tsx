import React, { useState, useEffect } from 'react';

interface RevenueSummary {
  total_revenue: string;
  collected: string;
  outstanding: string;
  collection_rate: string;
}

interface ProgramRevenue {
  id: number;
  name: string;
  athletes: number;
  revenue: string;
  collected: string;
  outstanding: string;
  collection_rate: string;
}

interface StatusBreakdown {
  [key: string]: {
    count: number;
    amount: number;
  };
}

/**
 * Admin: Revenue Dashboard
 * Shows revenue summary, by program, and by status
 */
export const RevenueDashboard: React.FC = () => {
  const [summary, setSummary] = useState<RevenueSummary | null>(null);
  const [byProgram, setByProgram] = useState<ProgramRevenue[]>([]);
  const [byStatus, setByStatus] = useState<StatusBreakdown>({});
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Fetch revenue summary for league_id=13 (from demo data)
    fetch(`${process.env.REACT_APP_API_URL}/api/revenue-summary.php?league_id=13`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setSummary(data.summary);
          setByProgram(data.by_program);
          setByStatus(data.by_status);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching revenue summary:', err);
        setLoading(false);
      });
  }, []);

  const formatCurrency = (amount: string | number) => {
    return `$${parseFloat(amount.toString()).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      paid: 'bg-green-100 text-green-800 border-green-200',
      partial: 'bg-yellow-100 text-yellow-800 border-yellow-200',
      pending: 'bg-red-100 text-red-800 border-red-200',
    };
    return colors[status] || 'bg-gray-100 text-gray-800 border-gray-200';
  };

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Loading revenue data...</p>
        </div>
      </div>
    );
  }

  if (!summary) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">No revenue data available.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <h1 className="text-3xl font-bold mb-6">Revenue Dashboard</h1>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <div className="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
          <div className="text-sm text-gray-600 mb-1">Total Revenue</div>
          <div className="text-2xl font-bold text-gray-900">{formatCurrency(summary.total_revenue)}</div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
          <div className="text-sm text-gray-600 mb-1">Collected</div>
          <div className="text-2xl font-bold text-green-600">{formatCurrency(summary.collected)}</div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
          <div className="text-sm text-gray-600 mb-1">Outstanding</div>
          <div className="text-2xl font-bold text-orange-600">{formatCurrency(summary.outstanding)}</div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow border-l-4 border-purple-500">
          <div className="text-sm text-gray-600 mb-1">Collection Rate</div>
          <div className="text-2xl font-bold text-purple-600">{summary.collection_rate}%</div>
        </div>
      </div>

      {/* Revenue by Program */}
      <div className="bg-white shadow rounded-lg p-6 mb-8">
        <h2 className="text-xl font-bold mb-4">Revenue by Program</h2>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead>
              <tr className="bg-gray-50">
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Program</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Athletes</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Revenue</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Collected</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Outstanding</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {byProgram.map(program => (
                <tr key={program.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 text-sm font-medium text-gray-900">{program.name}</td>
                  <td className="px-4 py-3 text-sm text-gray-600">{program.athletes}</td>
                  <td className="px-4 py-3 text-sm font-semibold text-gray-900">{formatCurrency(program.revenue)}</td>
                  <td className="px-4 py-3 text-sm text-green-600">{formatCurrency(program.collected)}</td>
                  <td className="px-4 py-3 text-sm text-orange-600">{formatCurrency(program.outstanding)}</td>
                  <td className="px-4 py-3 text-sm">
                    <div className="flex items-center">
                      <div className="w-16 bg-gray-200 rounded-full h-2 mr-2">
                        <div
                          className="bg-green-500 h-2 rounded-full"
                          style={{ width: `${program.collection_rate}%` }}
                        />
                      </div>
                      <span className="text-gray-600">{program.collection_rate}%</span>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Payment Status Breakdown */}
      <div className="bg-white shadow rounded-lg p-6">
        <h2 className="text-xl font-bold mb-4">Payment Status Breakdown</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {Object.entries(byStatus).map(([status, data]) => (
            <div key={status} className={`p-4 rounded-lg border-2 ${getStatusColor(status)}`}>
              <div className="flex justify-between items-start mb-2">
                <span className="text-sm font-medium uppercase">{status}</span>
                <span className="text-2xl font-bold">{data.count}</span>
              </div>
              <div className="text-lg font-semibold">{formatCurrency(data.amount)}</div>
              <div className="text-xs mt-1">
                {formatCurrency(data.amount / data.count)} avg per payment
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

interface AthleteInfo {
  id: number;
  name: string;
  total_owed: string;
  total_paid: string;
  total_remaining: string;
}

interface Payment {
  id: number;
  item_name: string;
  item_type: string;
  program_name: string;
  final_amount: string;
  amount_paid: string;
  amount_remaining: string;
  status: string;
  due_date: string | null;
  on_payment_plan: boolean;
  payment_plan_name?: string;
  total_installments?: number;
  current_installment?: number;
  is_overdue: boolean;
}

/**
 * Parent: Athlete Payments Dashboard
 * Shows payment status for a specific athlete
 */
export const AthletePaymentsDashboard: React.FC = () => {
  const { athleteId } = useParams<{ athleteId: string }>();
  const navigate = useNavigate();
  const [athlete, setAthlete] = useState<AthleteInfo | null>(null);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!athleteId) return;

    fetch(`${process.env.REACT_APP_API_URL}/api/athlete-payments.php?athlete_id=${athleteId}`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setAthlete(data.athlete);
          setPayments(data.payments);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching athlete payments:', err);
        setLoading(false);
      });
  }, [athleteId]);

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

  const getTypeColor = (type: string) => {
    const colors: Record<string, string> = {
      registration: 'bg-blue-100 text-blue-800',
      dues: 'bg-green-100 text-green-800',
      uniform: 'bg-purple-100 text-purple-800',
      tournament: 'bg-orange-100 text-orange-800',
      merchandise: 'bg-pink-100 text-pink-800',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
  };

  const handleMakePayment = (paymentId: number) => {
    navigate(`/payment/checkout/${athleteId}/${paymentId}`);
  };

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Loading payment information...</p>
        </div>
      </div>
    );
  }

  if (!athlete) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Athlete not found.</p>
        </div>
      </div>
    );
  }

  const overduePayments = payments.filter(p => p.is_overdue);
  const unpaidPayments = payments.filter(p => p.status !== 'paid');

  return (
    <div className="container mx-auto p-6">
      <div className="mb-6">
        <h1 className="text-3xl font-bold mb-2">Payments for {athlete.name}</h1>
        <p className="text-gray-600">View and manage payment obligations</p>
      </div>

      {/* Overdue Alert */}
      {overduePayments.length > 0 && (
        <div className="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
          <div className="flex">
            <div className="flex-shrink-0">
              <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
              </svg>
            </div>
            <div className="ml-3">
              <h3 className="text-sm font-medium text-red-800">Overdue Payments</h3>
              <p className="mt-1 text-sm text-red-700">
                You have {overduePayments.length} overdue payment{overduePayments.length > 1 ? 's' : ''}. Please make a payment as soon as possible.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div className="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
          <div className="text-sm text-gray-600 mb-1">Total Owed</div>
          <div className="text-2xl font-bold text-gray-900">{formatCurrency(athlete.total_owed)}</div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
          <div className="text-sm text-gray-600 mb-1">Total Paid</div>
          <div className="text-2xl font-bold text-green-600">{formatCurrency(athlete.total_paid)}</div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
          <div className="text-sm text-gray-600 mb-1">Remaining Balance</div>
          <div className="text-2xl font-bold text-orange-600">{formatCurrency(athlete.total_remaining)}</div>
        </div>
      </div>

      {/* Payment Items */}
      <div className="bg-white shadow rounded-lg overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h2 className="text-xl font-bold">Payment Items</h2>
        </div>

        {payments.length === 0 ? (
          <div className="text-center py-10">
            <p className="text-gray-500">No payment items found.</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-200">
            {payments.map(payment => (
              <div key={payment.id} className="p-6 hover:bg-gray-50">
                <div className="flex justify-between items-start mb-3">
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                      <h3 className="text-lg font-semibold text-gray-900">{payment.item_name}</h3>
                      <span className={`px-2 py-1 text-xs font-semibold rounded-full ${getTypeColor(payment.item_type)}`}>
                        {payment.item_type}
                      </span>
                      {payment.is_overdue && (
                        <span className="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                          OVERDUE
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-gray-600">{payment.program_name}</p>
                  </div>
                  <div className="text-right">
                    <div className="text-2xl font-bold text-gray-900">{formatCurrency(payment.final_amount)}</div>
                    <span className={`inline-block mt-1 px-3 py-1 text-sm font-semibold rounded-full border-2 ${getStatusColor(payment.status)}`}>
                      {payment.status.toUpperCase()}
                    </span>
                  </div>
                </div>

                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3 text-sm">
                  <div>
                    <span className="text-gray-600">Amount Paid:</span>
                    <div className="font-semibold text-green-600">{formatCurrency(payment.amount_paid)}</div>
                  </div>
                  <div>
                    <span className="text-gray-600">Remaining:</span>
                    <div className="font-semibold text-orange-600">{formatCurrency(payment.amount_remaining)}</div>
                  </div>
                  {payment.due_date && (
                    <div>
                      <span className="text-gray-600">Due Date:</span>
                      <div className={`font-semibold ${payment.is_overdue ? 'text-red-600' : 'text-gray-900'}`}>
                        {new Date(payment.due_date).toLocaleDateString()}
                      </div>
                    </div>
                  )}
                  {payment.on_payment_plan && (
                    <div>
                      <span className="text-gray-600">Payment Plan:</span>
                      <div className="font-semibold text-blue-600">
                        {payment.payment_plan_name} ({payment.current_installment}/{payment.total_installments})
                      </div>
                    </div>
                  )}
                </div>

                {payment.status !== 'paid' && (
                  <div className="flex justify-end">
                    <button
                      onClick={() => handleMakePayment(payment.id)}
                      className={`px-4 py-2 rounded font-semibold text-white ${
                        payment.is_overdue
                          ? 'bg-red-600 hover:bg-red-700'
                          : 'bg-blue-600 hover:bg-blue-700'
                      }`}
                    >
                      Make Payment
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Quick Actions */}
      {unpaidPayments.length > 0 && (
        <div className="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div className="flex justify-between items-center">
            <div>
              <h3 className="font-semibold text-blue-900">Pay All Outstanding</h3>
              <p className="text-sm text-blue-700">
                Total outstanding: {formatCurrency(athlete.total_remaining)}
              </p>
            </div>
            <button
              onClick={() => navigate(`/payment/checkout/${athleteId}`)}
              className="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded"
            >
              Pay All
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

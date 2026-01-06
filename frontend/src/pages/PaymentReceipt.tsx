import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';

interface Receipt {
  transaction_id: string;
  transaction_date: string;
  athlete_name: string;
  guardian_name: string;
  guardian_email: string;
  item_name: string;
  program_name: string;
  base_amount: number;
  discount_amount: number;
  amount_paid: number;
  payment_method: string;
  last_four: string;
  status: string;
}

/**
 * Payment Receipt Page
 * Displays receipt after successful payment
 */
export const PaymentReceipt: React.FC = () => {
  const { transactionId } = useParams<{ transactionId: string }>();
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!transactionId) return;

    fetch(`${process.env.REACT_APP_API_URL}/api/payment-receipt.php?transaction_id=${transactionId}`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setReceipt(data.receipt);
        } else {
          setError(data.error || 'Receipt not found');
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching receipt:', err);
        setError('Failed to load receipt');
        setLoading(false);
      });
  }, [transactionId]);

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  const handlePrint = () => {
    window.print();
  };

  const handleEmailReceipt = async () => {
    try {
      const response = await fetch(`${process.env.REACT_APP_API_URL}/api/payment-receipt.php?action=email`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ transaction_id: transactionId })
      });
      const data = await response.json();
      if (data.success) {
        alert('Receipt sent to ' + receipt?.guardian_email);
      } else {
        alert('Failed to send email: ' + (data.error || 'Unknown error'));
      }
    } catch (err) {
      alert('Failed to send email');
    }
  };

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-gray-500">Loading receipt...</p>
        </div>
      </div>
    );
  }

  if (error || !receipt) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-10">
          <p className="text-red-500">{error || 'Receipt not found'}</p>
          <Link to="/dashboard" className="mt-4 inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
            Go to Dashboard
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6 print:p-0">
      <div className="max-w-2xl mx-auto">
        {/* Action buttons - hidden when printing */}
        <div className="flex justify-between mb-6 print:hidden">
          <Link to="/dashboard" className="text-blue-600 hover:text-blue-800 flex items-center gap-2">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Back to Dashboard
          </Link>
          <div className="flex gap-2">
            <button
              onClick={handleEmailReceipt}
              className="px-4 py-2 border border-blue-600 text-blue-600 rounded hover:bg-blue-50 flex items-center gap-2"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
              Email Receipt
            </button>
            <button
              onClick={handlePrint}
              className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 flex items-center gap-2"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
              </svg>
              Print
            </button>
          </div>
        </div>

        {/* Receipt Card */}
        <div className="bg-white shadow-lg rounded-lg overflow-hidden print:shadow-none">
          {/* Header */}
          <div className="bg-green-600 text-white p-6 text-center">
            <div className="mb-2">
              <svg className="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <h1 className="text-2xl font-bold">Payment Successful</h1>
            <p className="text-green-100">Thank you for your payment!</p>
          </div>

          {/* Receipt Details */}
          <div className="p-6">
            <div className="border-b pb-4 mb-4">
              <div className="text-center">
                <h2 className="text-xl font-bold text-gray-900">Payment Receipt</h2>
                <p className="text-sm text-gray-500">Transaction ID: {receipt.transaction_id}</p>
                <p className="text-sm text-gray-500">{formatDate(receipt.transaction_date)}</p>
              </div>
            </div>

            {/* Bill To */}
            <div className="mb-6">
              <h3 className="text-sm font-semibold text-gray-500 uppercase mb-2">Bill To</h3>
              <div className="text-gray-900">
                <p className="font-semibold">{receipt.guardian_name}</p>
                <p className="text-sm text-gray-600">{receipt.guardian_email}</p>
              </div>
            </div>

            {/* Payment For */}
            <div className="mb-6">
              <h3 className="text-sm font-semibold text-gray-500 uppercase mb-2">Payment For</h3>
              <div className="text-gray-900">
                <p className="font-semibold">{receipt.athlete_name}</p>
                <p className="text-sm text-gray-600">{receipt.item_name}</p>
                <p className="text-sm text-gray-600">{receipt.program_name}</p>
              </div>
            </div>

            {/* Amount Breakdown */}
            <div className="bg-gray-50 rounded-lg p-4 mb-6">
              <h3 className="text-sm font-semibold text-gray-500 uppercase mb-3">Payment Summary</h3>
              <div className="space-y-2">
                <div className="flex justify-between">
                  <span className="text-gray-600">Subtotal</span>
                  <span className="text-gray-900">{formatCurrency(receipt.base_amount)}</span>
                </div>
                {receipt.discount_amount > 0 && (
                  <div className="flex justify-between text-green-600">
                    <span>Discount</span>
                    <span>-{formatCurrency(receipt.discount_amount)}</span>
                  </div>
                )}
                <div className="border-t pt-2 mt-2 flex justify-between font-bold">
                  <span className="text-gray-900">Amount Paid</span>
                  <span className="text-green-600 text-lg">{formatCurrency(receipt.amount_paid)}</span>
                </div>
              </div>
            </div>

            {/* Payment Method */}
            <div className="mb-6">
              <h3 className="text-sm font-semibold text-gray-500 uppercase mb-2">Payment Method</h3>
              <div className="flex items-center gap-2">
                <svg className="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <div>
                  <p className="font-semibold text-gray-900">{receipt.payment_method}</p>
                  <p className="text-sm text-gray-600">ending in {receipt.last_four}</p>
                </div>
              </div>
            </div>

            {/* Status */}
            <div className="text-center p-4 bg-green-50 rounded-lg">
              <span className="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-green-100 text-green-800">
                <svg className="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                </svg>
                Payment Complete
              </span>
            </div>
          </div>

          {/* Footer */}
          <div className="bg-gray-50 px-6 py-4 text-center text-sm text-gray-500 border-t">
            <p>Questions about your payment? Contact the club administrator.</p>
            <p className="mt-1">A copy of this receipt has been sent to {receipt.guardian_email}</p>
          </div>
        </div>
      </div>
    </div>
  );
};

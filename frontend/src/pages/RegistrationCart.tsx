import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useRegistrationCart } from '../contexts/RegistrationCartContext';

/**
 * Registration Cart Page
 * Shows all children/programs in cart before checkout
 */
export const RegistrationCart: React.FC = () => {
  const { items, removeItem, clearCart, getTotal, getTotalDiscount } = useRegistrationCart();
  const navigate = useNavigate();
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const handleCheckout = async () => {
    if (items.length === 0) return;

    setProcessing(true);
    setError(null);

    try {
      // Submit all registrations
      const registrationResults = [];

      for (const item of items) {
        const response = await fetch(`${process.env.REACT_APP_API_URL}/registration/registrations-api.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            program_id: item.programId,
            form_data: {
              ...item.formData,
              athlete_first: item.athleteData.firstName,
              athlete_last: item.athleteData.lastName,
              athlete_birthday: item.athleteData.birthday,
              athlete_gender: item.athleteData.gender,
              athlete_grade: item.athleteData.grade,
              guardian_first: item.guardianData.firstName,
              guardian_last: item.guardianData.lastName,
              guardian_email: item.guardianData.email,
              mobile_phone: item.guardianData.phone
            }
          })
        });

        const result = await response.json();
        if (!result.success) {
          throw new Error(result.error || `Failed to register ${item.athleteData.firstName}`);
        }
        registrationResults.push(result);
      }

      // All registrations successful - redirect to multi-payment checkout
      const athletePaymentIds = registrationResults
        .filter(r => r.athlete_payment_id)
        .map(r => r.athlete_payment_id);

      if (athletePaymentIds.length > 0) {
        // Store payment IDs for checkout
        sessionStorage.setItem('pendingPayments', JSON.stringify({
          paymentIds: athletePaymentIds,
          total: getTotal(),
          itemCount: items.length
        }));
        clearCart();
        navigate('/payment/multi-checkout');
      } else {
        clearCart();
        navigate('/dashboard');
      }
    } catch (err: any) {
      setError(err.message || 'Failed to process registrations');
    } finally {
      setProcessing(false);
    }
  };

  if (items.length === 0) {
    return (
      <div className="container mx-auto p-6">
        <div className="max-w-2xl mx-auto text-center py-12">
          <div className="text-gray-400 mb-4">
            <svg className="w-24 h-24 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
          </div>
          <h2 className="text-2xl font-bold text-brand-primary mb-2">Your cart is empty</h2>
          <p className="text-gray-600 mb-6">Add children to programs to get started</p>
          <Link
            to="/program-management"
            className="inline-block px-6 py-3 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover"
          >
            Browse Programs
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <div className="max-w-3xl mx-auto">
        <h1 className="text-3xl font-bold mb-6">Registration Cart</h1>

        {error && (
          <div className="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
            <p className="text-red-800">{error}</p>
          </div>
        )}

        {/* Cart Items */}
        <div className="bg-white shadow rounded-lg mb-6">
          <div className="divide-y divide-gray-200">
            {items.map((item) => (
              <div key={item.id} className="p-4">
                <div className="flex justify-between items-start">
                  <div className="flex-1">
                    <div className="flex items-center gap-3 mb-2">
                      <div className="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                        <span className="text-blue-600 font-bold">
                          {item.athleteData.firstName.charAt(0)}{item.athleteData.lastName.charAt(0)}
                        </span>
                      </div>
                      <div>
                        <h3 className="font-semibold text-brand-primary">
                          {item.athleteData.firstName} {item.athleteData.lastName}
                        </h3>
                        <p className="text-sm text-gray-600">{item.programName}</p>
                      </div>
                    </div>
                    <div className="ml-13 text-sm text-gray-500">
                      Guardian: {item.guardianData.firstName} {item.guardianData.lastName}
                    </div>
                  </div>

                  <div className="text-right">
                    {item.discountAmount > 0 && (
                      <>
                        <div className="text-sm text-gray-500 line-through">
                          {formatCurrency(item.basePrice)}
                        </div>
                        <div className="text-xs text-green-600">{item.discountReason}</div>
                      </>
                    )}
                    <div className="text-lg font-bold text-gray-900">
                      {formatCurrency(item.finalPrice)}
                    </div>
                    <button
                      onClick={() => removeItem(item.id)}
                      className="text-sm text-red-600 hover:text-red-800 mt-1"
                    >
                      Remove
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Add Another Child */}
        <div className="mb-6">
          <Link
            to="/program-management"
            className="flex items-center justify-center gap-2 w-full py-3 border-2 border-dashed border-gray-300 rounded-lg text-gray-600 hover:border-brand-primary hover:text-brand-primary transition-colors"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add Another Child
          </Link>
        </div>

        {/* Summary */}
        <div className="bg-white shadow rounded-lg p-6 mb-6">
          <h2 className="text-lg font-bold mb-4">Order Summary</h2>
          <div className="space-y-2">
            <div className="flex justify-between text-gray-600">
              <span>Registrations ({items.length})</span>
              <span>{formatCurrency(getTotal() + getTotalDiscount())}</span>
            </div>
            {getTotalDiscount() > 0 && (
              <div className="flex justify-between text-green-600">
                <span>Discounts</span>
                <span>-{formatCurrency(getTotalDiscount())}</span>
              </div>
            )}
            <div className="border-t pt-2 mt-2 flex justify-between font-bold text-lg">
              <span>Total</span>
              <span>{formatCurrency(getTotal())}</span>
            </div>
          </div>
        </div>

        {/* Actions */}
        <div className="flex gap-4">
          <button
            onClick={clearCart}
            className="flex-1 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
          >
            Clear Cart
          </button>
          <button
            onClick={handleCheckout}
            disabled={processing}
            className={`flex-1 py-3 rounded-lg text-white font-semibold ${
              processing
                ? 'bg-gray-400 cursor-not-allowed'
                : 'bg-brand-primary hover:bg-brand-primary-hover'
            }`}
          >
            {processing ? 'Processing...' : `Checkout ${formatCurrency(getTotal())}`}
          </button>
        </div>
      </div>
    </div>
  );
};

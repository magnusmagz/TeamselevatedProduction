import React, { useState } from 'react';

/**
 * Demo Mode Banner
 * Displays when REACT_APP_PAYMENT_MODE=demo
 * Shows test card numbers for payment testing
 */
export const DemoModeBanner: React.FC = () => {
  const [dismissed, setDismissed] = useState(false);

  // Only show in demo mode
  if (process.env.REACT_APP_PAYMENT_MODE !== 'demo') {
    return null;
  }

  // Don't show if dismissed
  if (dismissed) {
    return null;
  }

  return (
    <div className="bg-yellow-100 border-b-2 border-yellow-500 p-3">
      <div className="container mx-auto flex justify-between items-center">
        <div className="flex items-center gap-3">
          <span className="text-2xl">🧪</span>
          <div>
            <p className="font-bold text-yellow-900">DEMO MODE</p>
            <p className="text-sm text-yellow-700">
              No real payments will be processed. Test card: <code className="bg-yellow-200 px-1 rounded">4242 4242 4242 4242</code>
            </p>
          </div>
        </div>
        <button
          onClick={() => setDismissed(true)}
          className="text-yellow-700 hover:text-yellow-900 text-xl font-bold px-2"
          aria-label="Dismiss banner"
        >
          ✕
        </button>
      </div>
    </div>
  );
};

import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

type Phase = 'confirming' | 'submitting' | 'success' | 'error';

/**
 * Public landing page for waitlist offer accept/decline links emailed to
 * team registrars. No auth required — the URL token in the email is the
 * only credential. The page calls the public-waitlist-respond gateway
 * action and renders the outcome.
 *
 * URL shape: /tournament-waitlist/respond?token={t}&action={accept|decline}
 *
 * Flow:
 *   - Land on confirm screen: "Do you want to accept this spot?" with a
 *     single confirm button. Two reasons for the extra click:
 *       1. Some email clients pre-fetch links — without the click step,
 *          the simple act of opening the email could accept the offer.
 *       2. Lets the user back out if they clicked the wrong button.
 *   - Confirm → submit → render success or error
 */
const WaitlistResponse: React.FC = () => {
  const [params] = useSearchParams();
  const token = params.get('token') || '';
  const initialAction = params.get('action');
  const [choice, setChoice] = useState<'accept' | 'decline' | null>(
    initialAction === 'accept' || initialAction === 'decline' ? initialAction : null
  );
  const [phase, setPhase] = useState<Phase>('confirming');
  const [errorMessage, setErrorMessage] = useState<string>('');

  useEffect(() => {
    if (!token) {
      setPhase('error');
      setErrorMessage('This link is missing required information. Please use the button in the original email.');
    }
  }, [token]);

  const submit = async (which: 'accept' | 'decline') => {
    setChoice(which);
    setPhase('submitting');
    setErrorMessage('');
    try {
      const url = `${API_URL}/api/tournament-gateway.php?action=public-waitlist-respond&token=${encodeURIComponent(token)}&choice=${which}`;
      const res = await fetch(url);
      const data = await res.json();
      if (data.ok) {
        setPhase('success');
      } else {
        setPhase('error');
        setErrorMessage(data.error || 'Something went wrong. Please contact the tournament director.');
      }
    } catch (err) {
      setPhase('error');
      setErrorMessage('Network error — please try again or contact the tournament director.');
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 max-w-lg w-full p-8">
        {phase === 'confirming' && (
          <>
            <h1 className="text-2xl font-bold text-gray-900 mb-2">Waitlist offer</h1>
            <p className="text-gray-600 mb-6">
              {choice === 'accept'
                ? 'Confirm that you want to accept this tournament spot. We will email a confirmation once the spot is locked in.'
                : choice === 'decline'
                  ? 'Confirm that you want to decline this offer. The spot will go to the next team on the waitlist; your team stays eligible if more spots open up.'
                  : 'Please confirm whether your team is taking the open spot.'}
            </p>

            <div className="flex flex-col sm:flex-row gap-3">
              <button
                onClick={() => submit('accept')}
                className={`flex-1 px-4 py-3 rounded-md font-semibold text-white transition-colors ${
                  choice === 'accept' ? 'bg-green-600 hover:bg-green-700' : 'bg-green-500 hover:bg-green-600'
                }`}
              >
                {choice === 'accept' ? 'Confirm — Accept Spot' : 'Accept Spot'}
              </button>
              <button
                onClick={() => submit('decline')}
                className={`flex-1 px-4 py-3 rounded-md font-semibold transition-colors ${
                  choice === 'decline'
                    ? 'bg-red-600 hover:bg-red-700 text-white'
                    : 'bg-white border border-red-500 text-red-600 hover:bg-red-50'
                }`}
              >
                {choice === 'decline' ? 'Confirm — Decline' : 'Decline'}
              </button>
            </div>
          </>
        )}

        {phase === 'submitting' && (
          <div className="text-center py-12">
            <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary" />
            <p className="text-gray-600 mt-4">Processing…</p>
          </div>
        )}

        {phase === 'success' && choice === 'accept' && (
          <div className="text-center py-8">
            <div className="text-green-500 text-5xl mb-3">✓</div>
            <h1 className="text-2xl font-bold text-gray-900 mb-2">You're in!</h1>
            <p className="text-gray-600">
              Your team is now confirmed. A confirmation email is on its way with next steps —
              roster, documents, and any outstanding entry fee.
            </p>
          </div>
        )}

        {phase === 'success' && choice === 'decline' && (
          <div className="text-center py-8">
            <div className="text-gray-400 text-5xl mb-3">✓</div>
            <h1 className="text-2xl font-bold text-gray-900 mb-2">Offer declined</h1>
            <p className="text-gray-600">
              Got it — we'll offer the spot to the next team. Thanks for letting us know.
            </p>
          </div>
        )}

        {phase === 'error' && (
          <div className="py-8">
            <div className="text-amber-500 text-5xl mb-3 text-center">!</div>
            <h1 className="text-2xl font-bold text-gray-900 mb-2 text-center">We couldn't process that</h1>
            <p className="text-gray-600 text-center">{errorMessage}</p>
          </div>
        )}
      </div>
    </div>
  );
};

export default WaitlistResponse;

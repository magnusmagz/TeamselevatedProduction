import React, { useState, useEffect } from 'react';
import { useSearchParams, Link } from 'react-router-dom';

interface ConfirmResult {
  success: boolean;
  message?: string;
  error?: string;
  already_confirmed?: boolean;
  athlete_name?: string;
  guardian_name?: string;
  consent_type?: string;
  club_name?: string;
  club_logo?: string;
}

export default function ConsentConfirm() {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token');
  const [loading, setLoading] = useState(true);
  const [result, setResult] = useState<ConfirmResult | null>(null);

  useEffect(() => {
    if (!token) {
      setResult({ success: false, error: 'No confirmation token provided.' });
      setLoading(false);
      return;
    }

    const confirmConsent = async () => {
      try {
        const response = await fetch(
          `${API_URL}/api/consent.php?action=confirm-email&token=${encodeURIComponent(token)}`
        );
        const data = await response.json();
        setResult(data);
      } catch {
        setResult({ success: false, error: 'Unable to reach the server. Please try again later.' });
      } finally {
        setLoading(false);
      }
    };

    confirmConsent();
  }, [token, API_URL]);

  const clubName = result?.club_name;
  const athleteName = result?.athlete_name;

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <div className="flex-1 flex items-center justify-center px-4 py-12">
        <div className="max-w-md w-full">
          {/* Club branding */}
          <div className="text-center mb-8">
            {result?.club_logo ? (
              <img
                src={result.club_logo}
                alt={clubName || 'Club logo'}
                className="h-20 w-auto mx-auto mb-4"
              />
            ) : clubName ? (
              <div className="w-20 h-20 bg-brand-primary rounded-full flex items-center justify-center mx-auto mb-4">
                <span className="text-white text-2xl font-bold">
                  {clubName.charAt(0).toUpperCase()}
                </span>
              </div>
            ) : null}
            {clubName && (
              <h1 className="text-2xl font-bold text-gray-900">{clubName}</h1>
            )}
          </div>

          {/* Card */}
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            {loading ? (
              <div className="text-center">
                <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-brand-primary mx-auto mb-4" />
                <p className="text-gray-600">Confirming your consent...</p>
              </div>
            ) : result?.success ? (
              <div className="text-center">
                <div className="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                  <svg className="w-7 h-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                {result.already_confirmed ? (
                  <>
                    <h2 className="text-xl font-semibold text-gray-900 mb-2">Already Confirmed</h2>
                    <p className="text-gray-600">
                      Your consent{athleteName ? ` for ${athleteName}` : ''} has already been confirmed. No further action is needed.
                    </p>
                  </>
                ) : (
                  <>
                    <h2 className="text-xl font-semibold text-gray-900 mb-2">Consent Confirmed</h2>
                    <p className="text-gray-600">
                      {result.guardian_name ? `Thank you, ${result.guardian_name}. ` : 'Thank you. '}
                      Your consent{athleteName ? ` for ${athleteName}'s enrollment` : ''} has been confirmed.
                    </p>
                  </>
                )}
                <p className="text-sm text-gray-500 mt-4">You can safely close this page.</p>
              </div>
            ) : result?.error === 'expired' ? (
              <div className="text-center">
                <div className="w-14 h-14 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-5">
                  <svg className="w-7 h-7 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <h2 className="text-xl font-semibold text-gray-900 mb-2">Link Expired</h2>
                <p className="text-gray-600">
                  This confirmation link has expired (48-hour limit).
                  {athleteName ? ` Please contact ${clubName || 'your club administrator'} for a new confirmation link for ${athleteName}.` : ' Please contact your club administrator for a new link.'}
                </p>
              </div>
            ) : (
              <div className="text-center">
                <div className="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-5">
                  <svg className="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </div>
                <h2 className="text-xl font-semibold text-gray-900 mb-2">Something Went Wrong</h2>
                <p className="text-gray-600">{result?.error || 'Unable to confirm consent.'}</p>
              </div>
            )}
          </div>

          {/* Privacy link */}
          <div className="text-center mt-6">
            <Link to="/privacy-policy" className="text-sm text-gray-500 hover:text-gray-700 hover:underline">
              Privacy Policy
            </Link>
          </div>

          {/* Powered by */}
          <div className="text-center mt-4 text-xs text-gray-400">
            Powered by Teams Elevated
          </div>
        </div>
      </div>
    </div>
  );
}

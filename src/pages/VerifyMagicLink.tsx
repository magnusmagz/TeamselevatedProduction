import React, { useEffect, useState, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

export default function VerifyMagicLink() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { refreshAuth } = useAuth();
  const [status, setStatus] = useState<'verifying' | 'success' | 'error'>('verifying');
  const [message, setMessage] = useState('');
  const hasVerified = useRef(false);

  useEffect(() => {
    // Prevent double verification (React StrictMode calls useEffect twice in dev)
    if (hasVerified.current) {
      return;
    }

    const token = searchParams.get('token');

    if (!token) {
      setStatus('error');
      setMessage('No token provided');
      return;
    }

    hasVerified.current = true;
    verifyToken(token);
  }, [searchParams]);

  const verifyToken = async (token: string) => {
    try {
      const response = await fetch(`${API_URL}/api/auth-gateway.php?action=verify-magic-link`, {
        method: 'POST',
        credentials: 'include', // Important: receive and store cookies
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token }),
      });

      const data = await response.json();

      if (response.ok && data.token) {
        // Store JWT token
        localStorage.setItem('auth_token', data.token);

        setStatus('success');
        setMessage('Authentication successful! Redirecting...');

        // Refresh auth context
        await refreshAuth();

        // Redirect to dashboard after a short delay
        setTimeout(() => {
          navigate('/dashboard');
        }, 1500);
      } else {
        setStatus('error');
        setMessage(data.error || 'Verification failed');
      }
    } catch (err) {
      setStatus('error');
      setMessage(err instanceof Error ? err.message : 'An error occurred');
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
      <div className="max-w-md w-full">
        <div className="bg-white border-2 border-gray-200 p-8">
          <div className="text-center">
            {status === 'verifying' && (
              <>
                <div className="mx-auto h-16 w-16 bg-forest-100 flex items-center justify-center mb-4 animate-pulse">
                  <svg className="h-8 w-8 text-forest-600 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                  </svg>
                </div>
                <h2 className="text-2xl font-bold text-gray-900 mb-2 uppercase">VERIFYING...</h2>
                <p className="text-gray-600">Please wait while we verify your magic link</p>
              </>
            )}

            {status === 'success' && (
              <>
                <div className="mx-auto h-16 w-16 bg-green-100 flex items-center justify-center mb-4">
                  <svg className="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <h2 className="text-2xl font-bold text-gray-900 mb-2 uppercase">SUCCESS!</h2>
                <p className="text-gray-600">{message}</p>
              </>
            )}

            {status === 'error' && (
              <>
                <div className="mx-auto h-16 w-16 bg-red-100 flex items-center justify-center mb-4">
                  <svg className="h-8 w-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </div>
                <h2 className="text-2xl font-bold text-gray-900 mb-2 uppercase">VERIFICATION FAILED</h2>
                <p className="text-gray-600 mb-6">{message}</p>
                <button
                  onClick={() => navigate('/login')}
                  className="bg-forest-600 hover:bg-forest-700 text-white font-semibold py-2 px-6 transition-colors duration-200 uppercase"
                >
                  TRY AGAIN
                </button>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

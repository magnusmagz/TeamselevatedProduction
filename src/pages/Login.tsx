import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

export default function Login() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`${API_URL}/api/auth-gateway.php?action=send-magic-link`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Failed to send magic link');
      }

      setSuccess(true);

      // In development, show the magic link
      if (data.debug && data.debug.link) {
        console.log('🔗 Magic link (dev only):', data.debug.link);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div className="max-w-md w-full">
          <div className="bg-white border-2 border-gray-200 p-8">
            <div className="text-center">
              <div className="mx-auto h-16 w-16 bg-forest-100 flex items-center justify-center mb-4">
                <svg className="h-8 w-8 text-forest-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
              </div>
              <h2 className="text-2xl font-bold text-gray-900 mb-2">CHECK YOUR EMAIL!</h2>
              <p className="text-gray-600 mb-6">
                We've sent a magic link to <strong>{email}</strong>
              </p>
              <p className="text-sm text-gray-500 mb-6">
                Click the link in your email to sign in. The link expires in 15 minutes.
              </p>
              <button
                onClick={() => {
                  setSuccess(false);
                  setEmail('');
                }}
                className="bg-white text-forest-600 font-semibold py-2 px-4 border-2 border-forest-600 hover:bg-gray-50 transition-colors duration-200 w-full"
              >
                SEND ANOTHER LINK
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
      <div className="max-w-md w-full">
        <div className="bg-white border-2 border-gray-200 p-8">
          <div className="text-center mb-8">
            <h1 className="text-3xl font-bold text-gray-900 uppercase">WELCOME BACK</h1>
            <p className="text-gray-600 mt-2">Sign in to access your teams</p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-6">
            <div>
              <label className="block text-sm font-semibold text-gray-700 mb-2 uppercase">
                Email Address
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="your.email@example.com"
                className="w-full px-4 py-3 border-2 border-gray-300 focus:border-forest-600 focus:outline-none transition-colors"
                required
                autoFocus
              />
            </div>

            {error && (
              <div className="bg-red-50 border-2 border-red-200 p-4 text-red-700 text-sm">
                {error}
              </div>
            )}

            <button
              type="submit"
              disabled={loading}
              className="w-full bg-forest-600 hover:bg-forest-700 text-white font-semibold py-3 px-4 transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed uppercase"
            >
              {loading ? 'SENDING...' : 'SEND MAGIC LINK'}
            </button>
          </form>

          <div className="mt-6 text-center">
            <p className="text-sm text-gray-600">
              Don't have an account?{' '}
              <span className="text-forest-600 hover:text-forest-700 font-semibold cursor-pointer">
                Contact your coach
              </span>
            </p>
          </div>

          <div className="mt-8 pt-6 border-t-2 border-gray-200">
            <p className="text-xs text-gray-500 text-center">
              We'll send you a secure login link via email. No passwords needed!
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

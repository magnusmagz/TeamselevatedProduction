import React, { useState } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

/**
 * Where a coach's single-use invite link lands (GOTR G6).
 *
 * Posts { token, password } to api/coach-invite.php?action=redeem. The server
 * answers the three-answer ladder from lib/coach_invite.php: `already_used`
 * (an account exists — sign in), `expired` (ask the club), and a deliberately
 * vague not-found. On success the JWT is stored the way Login stores it and
 * the coach lands in the staff app.
 */
export default function AcceptCoachInvite() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token');

  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errorReason, setErrorReason] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);

  const validatePassword = (): boolean => {
    if (password.length < 8) {
      setPasswordError('Password must be at least 8 characters');
      return false;
    }
    if (!/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password)) {
      setPasswordError('Password must contain uppercase, lowercase, and numbers');
      return false;
    }
    if (password !== confirmPassword) {
      setPasswordError('Passwords do not match');
      return false;
    }
    setPasswordError(null);
    return true;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!validatePassword()) return;

    setLoading(true);
    setError(null);
    setErrorReason(null);
    try {
      const response = await fetch(`${API_URL}/api/coach-invite.php?action=redeem`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, password }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        // `reason` is what the backend used to flatten into one "invalid or
        // expired". already_used is not a failure: they have a working account.
        setErrorReason(data.reason ?? null);
        throw new Error(data.error || 'Failed to set password');
      }
      if (data.token) {
        localStorage.setItem('auth_token', data.token);
      }
      navigate('/dashboard');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  if (!token) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div className="max-w-md w-full bg-white border-2 border-gray-200 p-8 text-center">
          <h2 className="text-2xl font-bold text-brand-primary mb-2">Invalid invite link</h2>
          <p className="text-gray-600">
            This link is missing its token. Ask your club to send you a new invitation.
          </p>
        </div>
      </div>
    );
  }

  const alreadyUsed = errorReason === 'already_used';

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
      <div className="max-w-md w-full bg-white border-2 border-gray-200 p-8">
        <h2 className="text-2xl font-bold text-brand-primary mb-2">Set up your coach account</h2>
        <p className="text-gray-600 mb-6">
          Choose a password to finish setting up your Teams Elevated account. This link works once.
        </p>

        {error && (
          <div
            role="alert"
            className={`p-4 mb-4 border ${alreadyUsed ? 'bg-blue-50 border-blue-200 text-blue-800' : 'bg-red-50 border-red-200 text-red-800'}`}
          >
            <p>{error}</p>
            {alreadyUsed && (
              <Link to="/login" className="inline-block mt-3 underline font-medium">
                Go to sign in
              </Link>
            )}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="coach-password" className="block text-sm font-medium text-gray-700 mb-1">
              Password
            </label>
            <input
              id="coach-password"
              type="password"
              autoComplete="new-password"
              className="w-full border border-gray-300 px-3 py-2"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>
          <div>
            <label htmlFor="coach-password-confirm" className="block text-sm font-medium text-gray-700 mb-1">
              Confirm password
            </label>
            <input
              id="coach-password-confirm"
              type="password"
              autoComplete="new-password"
              className="w-full border border-gray-300 px-3 py-2"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              required
            />
          </div>
          {passwordError && <p className="text-sm text-red-700">{passwordError}</p>}
          <p className="text-xs text-gray-500">At least 8 characters, with uppercase, lowercase and a number.</p>
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-brand-primary text-white py-2 px-4 font-medium disabled:opacity-60"
          >
            {loading ? 'Setting up…' : 'Set password'}
          </button>
        </form>
      </div>
    </div>
  );
}

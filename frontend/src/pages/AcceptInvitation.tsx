import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

export default function AcceptInvitation() {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { user, refreshAuth } = useAuth();

  const id = searchParams.get('id');
  const code = searchParams.get('code');

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [invitationInfo, setInvitationInfo] = useState<any>(null);
  const [joining, setJoining] = useState(false);
  const [step, setStep] = useState<'info' | 'form' | 'success'>('info');
  const [formData, setFormData] = useState({
    name: '',
    email: '',
  });

  useEffect(() => {
    if (id || code) {
      fetchInvitationInfo();
    } else {
      setError('Invalid invitation link');
      setLoading(false);
    }
  }, [id, code]);

  const fetchInvitationInfo = async () => {
    try {
      const params = new URLSearchParams();
      if (id) params.append('id', id);
      if (code) params.append('code', code);

      const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=info&${params}`);

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error || 'Invalid or expired invitation');
      }

      const data = await response.json();
      setInvitationInfo(data);

      // Pre-fill email if it's in the invitation
      if (data.email) {
        setFormData(prev => ({ ...prev, email: data.email }));
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  const handleAccept = async () => {
    setJoining(true);
    setError(null);

    try {
      if (user) {
        // Authenticated user - accept invitation directly
        const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=accept`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ id, code }),
        });

        if (!response.ok) {
          const data = await response.json();
          throw new Error(data.error || 'Failed to accept invitation');
        }

        const data = await response.json();

        // Store new token and refresh auth
        if (data.token) {
          localStorage.setItem('auth_token', data.token);
          await refreshAuth();
        }

        setStep('success');
      } else {
        // Non-authenticated user - show form
        setStep('form');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to accept invitation');
    } finally {
      setJoining(false);
    }
  };

  const handleSubmitForm = async (e: React.FormEvent) => {
    e.preventDefault();
    setJoining(true);
    setError(null);

    if (!formData.name || !formData.email) {
      setError('Please fill in all fields');
      setJoining(false);
      return;
    }

    try {
      const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=accept`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          id,
          code,
          name: formData.name,
          email: formData.email,
        }),
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error || 'Failed to accept invitation');
      }

      const data = await response.json();

      // Store token and refresh auth
      if (data.token) {
        localStorage.setItem('auth_token', data.token);
        await refreshAuth();
      }

      setStep('success');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to accept invitation');
    } finally {
      setJoining(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-b from-green-50 to-white">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-forest-800 mx-auto"></div>
          <p className="mt-4 text-forest-800">Loading invitation details...</p>
        </div>
      </div>
    );
  }

  if (error && !invitationInfo) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-b from-green-50 to-white px-4">
        <div className="max-w-md w-full bg-white rounded-md border border-forest-200 p-8 text-center">
          <svg className="w-16 h-16 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <h2 className="text-2xl font-bold text-forest-800 mb-2 uppercase">Invalid Invitation</h2>
          <p className="text-gray-600 mb-6">{error}</p>
          <button
            onClick={() => navigate('/')}
            className="bg-forest-800 hover:bg-forest-700 text-white font-semibold py-2 px-6 rounded-md uppercase"
          >
            Go to Home
          </button>
        </div>
      </div>
    );
  }

  if (step === 'success') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-b from-green-50 to-white px-4 py-12">
        <div className="max-w-md w-full">
          <div className="bg-white rounded-md border border-forest-200 p-8">
            <div className="text-center mb-8">
              <div className="mx-auto h-20 w-20 bg-green-100 rounded-md flex items-center justify-center mb-4">
                <svg className="h-10 w-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <h2 className="text-3xl font-bold text-forest-800 uppercase">Welcome!</h2>
              <p className="mt-2 text-gray-600">You've successfully joined {invitationInfo?.organizationName}</p>
            </div>

            <div className="bg-gray-50 rounded-md p-4 mb-6">
              <h3 className="font-semibold text-forest-800 mb-2 uppercase">Details</h3>
              <dl className="space-y-1 text-sm">
                <div className="flex justify-between">
                  <dt className="text-gray-600">Organization:</dt>
                  <dd className="font-semibold text-forest-800">{invitationInfo?.organizationName}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-gray-600">Type:</dt>
                  <dd className="font-semibold text-forest-800 uppercase">{invitationInfo?.organizationType}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-gray-600">Your Role:</dt>
                  <dd className="font-semibold text-forest-800 uppercase">{invitationInfo?.role?.replace('_', ' ')}</dd>
                </div>
              </dl>
            </div>

            <div className="text-center">
              <button
                onClick={() => navigate('/dashboard')}
                className="bg-forest-800 hover:bg-forest-700 text-white font-semibold py-3 px-6 rounded-md uppercase"
              >
                Go to Dashboard
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-b from-green-50 to-white px-4 py-12">
      <div className="max-w-md w-full">
        <div className="bg-white rounded-md border border-forest-200 p-8">
          <div className="text-center mb-8">
            <div className="mx-auto h-20 w-20 bg-forest-50 rounded-md flex items-center justify-center mb-4">
              <svg className="h-10 w-10 text-forest-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </div>
            <h2 className="text-3xl font-bold text-forest-800 uppercase">You're Invited!</h2>
            <p className="mt-2 text-gray-600">Join <span className="font-semibold">{invitationInfo?.organizationName}</span></p>
          </div>

          {invitationInfo && (
            <div className="bg-gray-50 rounded-md p-4 mb-6">
              <h3 className="font-semibold text-forest-800 mb-2 uppercase">Invitation Details</h3>
              <dl className="space-y-1 text-sm">
                <div className="flex justify-between">
                  <dt className="text-gray-600">Organization:</dt>
                  <dd className="font-semibold text-forest-800">{invitationInfo.organizationName}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-gray-600">Type:</dt>
                  <dd className="font-semibold text-forest-800 uppercase">{invitationInfo.organizationType}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-gray-600">Role:</dt>
                  <dd className="font-semibold text-forest-800 uppercase">{invitationInfo.role.replace('_', ' ')}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-gray-600">Invited by:</dt>
                  <dd className="font-semibold text-forest-800">{invitationInfo.inviterName || invitationInfo.creatorName}</dd>
                </div>
              </dl>
              {invitationInfo.personalMessage && (
                <div className="mt-3 p-3 bg-white rounded-md border border-forest-100">
                  <p className="text-sm text-gray-700 italic">"{invitationInfo.personalMessage}"</p>
                </div>
              )}
            </div>
          )}

          {step === 'info' ? (
            <div className="text-center">
              {user ? (
                <>
                  <div className="mb-4 p-4 bg-blue-50 rounded-md">
                    <p className="text-sm text-blue-700">
                      Logged in as <strong>{user.name || user.email}</strong>
                    </p>
                  </div>
                  <button
                    onClick={handleAccept}
                    disabled={joining}
                    className="w-full bg-forest-800 hover:bg-forest-700 text-white font-semibold py-3 px-4 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed uppercase"
                  >
                    {joining ? 'Accepting...' : 'Accept Invitation'}
                  </button>
                </>
              ) : (
                <button
                  onClick={handleAccept}
                  className="w-full bg-forest-800 hover:bg-forest-700 text-white font-semibold py-3 px-4 rounded-md transition-colors uppercase"
                >
                  Accept Invitation
                </button>
              )}
            </div>
          ) : (
            <form onSubmit={handleSubmitForm} className="space-y-4">
              <div>
                <label className="block text-sm font-semibold text-forest-800 mb-2 uppercase">
                  Your Name
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Enter your full name"
                  className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-forest-800 mb-2 uppercase">
                  Your Email
                </label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="your.email@example.com"
                  className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
                  required
                  disabled={!!invitationInfo?.email}
                />
                {invitationInfo?.email && (
                  <p className="text-xs text-gray-600 mt-1">Email pre-filled from invitation</p>
                )}
              </div>

              {error && (
                <div className="bg-red-50 border border-red-200 rounded-md p-4 text-red-700 text-sm">
                  {error}
                </div>
              )}

              <div className="flex space-x-3">
                <button
                  type="button"
                  onClick={() => setStep('info')}
                  className="flex-1 bg-white hover:bg-gray-50 text-forest-800 font-semibold py-3 px-4 border border-forest-200 rounded-md transition-colors uppercase"
                >
                  Back
                </button>
                <button
                  type="submit"
                  disabled={joining}
                  className="flex-1 bg-forest-800 hover:bg-forest-700 text-white font-semibold py-3 px-4 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed uppercase"
                >
                  {joining ? 'Accepting...' : 'Accept Invitation'}
                </button>
              </div>
            </form>
          )}

          <p className="mt-6 text-center text-xs text-gray-600">
            By accepting, you agree to share your profile information with the organization
          </p>
        </div>
      </div>
    </div>
  );
}

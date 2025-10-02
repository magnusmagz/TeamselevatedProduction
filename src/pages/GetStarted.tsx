import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

export default function GetStarted() {
  const navigate = useNavigate();
  const { refreshAuth } = useAuth();
  const [step, setStep] = useState<'roles' | 'details' | 'success'>('roles');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Role selection
  const [selectedRoles, setSelectedRoles] = useState<string[]>([]);

  // Form data
  const [formData, setFormData] = useState({
    organizationName: '',
    yourName: '',
    email: '',
    phone: '',
  });

  // Success data
  const [createdData, setCreatedData] = useState<any>(null);

  const roles = [
    { id: 'league', label: 'I am a League' },
    { id: 'team', label: 'I am a Team' },
    { id: 'coach', label: 'I am a Coach' },
    { id: 'administrator', label: 'I am an Administrator' },
    { id: 'parent', label: 'I am a Parent' },
  ];

  const toggleRole = (roleId: string) => {
    setSelectedRoles(prev =>
      prev.includes(roleId)
        ? prev.filter(r => r !== roleId)
        : [...prev, roleId]
    );
  };

  const handleContinueToDetails = () => {
    if (selectedRoles.length === 0) {
      setError('Please select at least one role');
      return;
    }
    setError(null);
    setStep('details');
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value,
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`${API_URL}/api/organization-gateway.php?action=create`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          ...formData,
          roles: selectedRoles,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Failed to create organization');
      }

      setCreatedData(data);

      // Automatically verify the magic link to log the user in
      if (data.magicLink) {
        console.log('[GetStarted] Magic link received:', data.magicLink);
        // Extract token from magic link URL
        const url = new URL(data.magicLink);
        const token = url.searchParams.get('token');
        console.log('[GetStarted] Extracted token:', token);

        if (token) {
          // Verify the magic link to create the session
          console.log('[GetStarted] Verifying magic link...');
          const verifyResponse = await fetch(`${API_URL}/api/auth-gateway.php?action=verify-magic-link`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ token }),
          });

          console.log('[GetStarted] Verify response status:', verifyResponse.status);
          const verifyData = await verifyResponse.json();
          console.log('[GetStarted] Verify response data:', verifyData);

          if (verifyResponse.ok) {
            // Successfully logged in, refresh auth state and redirect to dashboard
            console.log('[GetStarted] Verification successful, refreshing auth...');
            await refreshAuth();
            console.log('[GetStarted] Auth refreshed, navigating to dashboard');
            navigate('/dashboard');
            return;
          } else {
            console.error('[GetStarted] Verification failed:', verifyData);
          }
        }
      }

      // Fallback: show success screen if auto-login failed
      setStep('success');

      // Redirect to dashboard after 3 seconds
      setTimeout(() => {
        navigate('/dashboard');
      }, 3000);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  // Step 1: Role Selection
  if (step === 'roles') {
    return (
      <div className="min-h-screen bg-gradient-to-b from-green-50 to-white flex items-center justify-center px-4">
        <div className="max-w-2xl w-full">
          <div className="bg-white border-2 border-gray-200 p-8">
            <div className="text-center mb-8">
              <h1 className="text-3xl font-bold text-gray-900 mb-2 uppercase">Get Started</h1>
              <p className="text-gray-600">Tell us about yourself (select all that apply)</p>
            </div>

            <div className="space-y-3 mb-8">
              {roles.map((role) => (
                <label
                  key={role.id}
                  className={`flex items-center p-4 border-2 cursor-pointer transition-colors ${
                    selectedRoles.includes(role.id)
                      ? 'border-forest-600 bg-forest-50'
                      : 'border-gray-200 hover:border-gray-300'
                  }`}
                >
                  <input
                    type="checkbox"
                    checked={selectedRoles.includes(role.id)}
                    onChange={() => toggleRole(role.id)}
                    className="w-5 h-5 text-forest-600 border-gray-300 focus:ring-forest-500"
                  />
                  <span className="ml-3 text-lg font-medium text-gray-900">{role.label}</span>
                </label>
              ))}
            </div>

            {error && (
              <div className="bg-red-50 border-2 border-red-200 p-4 text-red-700 text-sm mb-6">
                {error}
              </div>
            )}

            <div className="space-y-3">
              <button
                onClick={handleContinueToDetails}
                className="w-full bg-forest-600 hover:bg-forest-700 text-white font-semibold py-3 px-4 transition-colors duration-200 uppercase"
              >
                Continue
              </button>
              <button
                onClick={() => navigate('/')}
                className="w-full bg-white hover:bg-gray-50 text-forest-600 font-semibold py-3 px-4 border-2 border-forest-600 transition-colors duration-200 uppercase"
              >
                Back to Home
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // Step 2: Details Form
  if (step === 'details') {
    return (
      <div className="min-h-screen bg-gradient-to-b from-green-50 to-white flex items-center justify-center px-4 py-8">
        <div className="max-w-2xl w-full">
          <div className="bg-white border-2 border-gray-200 p-8">
            <div className="text-center mb-8">
              <h1 className="text-3xl font-bold text-gray-900 mb-2 uppercase">Create Your Organization</h1>
              <p className="text-gray-600">Provide your organization details</p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-6">
              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2 uppercase">
                  Organization Name *
                </label>
                <input
                  type="text"
                  name="organizationName"
                  value={formData.organizationName}
                  onChange={handleInputChange}
                  required
                  placeholder="Enter your organization, team, or league name"
                  className="w-full px-4 py-3 border-2 border-gray-300 focus:border-forest-600 focus:outline-none transition-colors"
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2 uppercase">
                  Your Name *
                </label>
                <input
                  type="text"
                  name="yourName"
                  value={formData.yourName}
                  onChange={handleInputChange}
                  required
                  placeholder="Enter your full name"
                  className="w-full px-4 py-3 border-2 border-gray-300 focus:border-forest-600 focus:outline-none transition-colors"
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2 uppercase">
                  Email Address *
                </label>
                <input
                  type="email"
                  name="email"
                  value={formData.email}
                  onChange={handleInputChange}
                  required
                  placeholder="your.email@example.com"
                  className="w-full px-4 py-3 border-2 border-gray-300 focus:border-forest-600 focus:outline-none transition-colors"
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2 uppercase">
                  Phone Number (Optional)
                </label>
                <input
                  type="tel"
                  name="phone"
                  value={formData.phone}
                  onChange={handleInputChange}
                  placeholder="(555) 123-4567"
                  className="w-full px-4 py-3 border-2 border-gray-300 focus:border-forest-600 focus:outline-none transition-colors"
                />
              </div>

              {error && (
                <div className="bg-red-50 border-2 border-red-200 p-4 text-red-700 text-sm">
                  {error}
                </div>
              )}

              <div className="space-y-3">
                <button
                  type="submit"
                  disabled={loading}
                  className="w-full bg-forest-600 hover:bg-forest-700 text-white font-semibold py-3 px-4 transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed uppercase"
                >
                  {loading ? 'Creating...' : 'Create Organization'}
                </button>
                <button
                  type="button"
                  onClick={() => setStep('roles')}
                  className="w-full bg-white hover:bg-gray-50 text-forest-600 font-semibold py-3 px-4 border-2 border-forest-600 transition-colors duration-200 uppercase"
                >
                  Back
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    );
  }

  // Step 3: Success
  return (
    <div className="min-h-screen bg-gradient-to-b from-green-50 to-white flex items-center justify-center px-4">
      <div className="max-w-2xl w-full">
        <div className="bg-white border-2 border-gray-200 p-8">
          <div className="text-center">
            <div className="mx-auto h-16 w-16 bg-green-100 flex items-center justify-center mb-4">
              <svg className="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <h2 className="text-2xl font-bold text-gray-900 mb-2 uppercase">Success!</h2>
            <p className="text-gray-600 mb-4">
              Your organization "{formData.organizationName}" has been created.
            </p>
            <p className="text-sm text-gray-500 mb-6">
              Redirecting to your dashboard in a few seconds...
            </p>

            {createdData?.magicLink && (
              <div className="bg-forest-50 border-2 border-forest-200 p-4 text-sm text-forest-800 mb-6">
                <p className="font-semibold mb-2">Check your email!</p>
                <p>We've sent you a magic link to complete your setup.</p>
              </div>
            )}

            <button
              onClick={() => navigate('/dashboard')}
              className="bg-forest-600 hover:bg-forest-700 text-white font-semibold py-2 px-6 transition-colors duration-200 uppercase"
            >
              Go to Dashboard Now
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

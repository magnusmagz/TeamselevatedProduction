import React, { useState } from 'react';

interface InviteUsersFormProps {
  leagueId?: number;
  clubId?: number;
  onSuccess?: () => void;
}

export default function InviteUsersForm({ leagueId, clubId, onSuccess }: InviteUsersFormProps) {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const [inviteMethod, setInviteMethod] = useState<'email' | 'link'>('email');
  const [emails, setEmails] = useState<string[]>(['']);
  const [role, setRole] = useState<string>('coach');
  const [personalMessage, setPersonalMessage] = useState('');
  const [maxUses, setMaxUses] = useState<string>('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [generatedLink, setGeneratedLink] = useState<string | null>(null);

  const addEmailField = () => {
    setEmails([...emails, '']);
  };

  const removeEmailField = (index: number) => {
    setEmails(emails.filter((_, i) => i !== index));
  };

  const updateEmail = (index: number, value: string) => {
    const newEmails = [...emails];
    newEmails[index] = value;
    setEmails(newEmails);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSuccess(null);
    setLoading(true);

    const token = localStorage.getItem('auth_token');

    try {
      if (inviteMethod === 'email') {
        const validEmails = emails.filter(email => email.trim() !== '');
        if (validEmails.length === 0) {
          throw new Error('Please enter at least one email address');
        }

        const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=send`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            leagueId,
            clubId,
            emails: validEmails,
            role,
            personalMessage: personalMessage.trim() || null,
          }),
        });

        if (!response.ok) {
          const data = await response.json();
          throw new Error(data.error || 'Failed to send invitations');
        }

        const data = await response.json();
        setSuccess(`Successfully sent ${data.sent.length} invitation(s)`);
        setEmails(['']);
        setPersonalMessage('');

        if (onSuccess) onSuccess();
      } else {
        // Create shareable link
        const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=create-link`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            leagueId,
            clubId,
            role,
            maxUses: maxUses ? parseInt(maxUses) : null,
          }),
        });

        if (!response.ok) {
          const data = await response.json();
          throw new Error(data.error || 'Failed to create invitation link');
        }

        const data = await response.json();
        setGeneratedLink(data.url);
        setSuccess('Invitation link created successfully!');

        if (onSuccess) onSuccess();
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="bg-white rounded-md border border-brand-secondary p-6">
      <h2 className="text-xl font-semibold text-brand-primary mb-6 uppercase">Invite Users</h2>

      {/* Method Selection */}
      <div className="mb-6">
        <label className="block text-sm font-semibold text-brand-primary mb-3 uppercase">
          Invitation Method
        </label>
        <div className="flex space-x-4">
          <button
            type="button"
            onClick={() => setInviteMethod('email')}
            className={`flex-1 py-3 px-4 rounded-md border-2 transition-all uppercase font-medium text-sm ${
              inviteMethod === 'email'
                ? 'border-brand-primary bg-brand-secondary text-brand-primary'
                : 'border-brand-secondary hover:border-brand-secondary bg-white text-brand-primary'
            }`}
          >
            <svg className="w-5 h-5 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            Email Invitations
          </button>
          <button
            type="button"
            onClick={() => setInviteMethod('link')}
            className={`flex-1 py-3 px-4 rounded-md border-2 transition-all uppercase font-medium text-sm ${
              inviteMethod === 'link'
                ? 'border-brand-primary bg-brand-secondary text-brand-primary'
                : 'border-brand-secondary hover:border-brand-secondary bg-white text-brand-primary'
            }`}
          >
            <svg className="w-5 h-5 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
            </svg>
            Shareable Link
          </button>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {inviteMethod === 'email' ? (
          <>
            {/* Email Fields */}
            <div>
              <label className="block text-sm font-semibold text-brand-primary mb-3 uppercase">
                Email Addresses
              </label>
              <div className="space-y-3">
                {emails.map((email, index) => (
                  <div key={index} className="flex space-x-2">
                    <input
                      type="email"
                      value={email}
                      onChange={(e) => updateEmail(index, e.target.value)}
                      placeholder="user@example.com"
                      className="flex-1 bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      required
                    />
                    {emails.length > 1 && (
                      <button
                        type="button"
                        onClick={() => removeEmailField(index)}
                        className="text-red-600 hover:text-red-700 p-2"
                      >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                      </button>
                    )}
                  </div>
                ))}
              </div>
              <button
                type="button"
                onClick={addEmailField}
                className="mt-3 text-sm text-brand-primary hover:text-brand-primary-hover font-semibold uppercase"
              >
                + Add another email
              </button>
            </div>

            {/* Role Selection */}
            <div>
              <label className="block text-sm font-semibold text-brand-primary mb-2 uppercase">
                Role
              </label>
              <select
                value={role}
                onChange={(e) => setRole(e.target.value)}
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              >
                <option value="coach">Coach</option>
                <option value="club_admin">Club Admin</option>
                {leagueId && <option value="league_admin">League Admin</option>}
              </select>
            </div>

            {/* Personal Message */}
            <div>
              <label className="block text-sm font-semibold text-brand-primary mb-2 uppercase">
                Personal Message (Optional)
              </label>
              <textarea
                value={personalMessage}
                onChange={(e) => setPersonalMessage(e.target.value)}
                rows={3}
                placeholder="Add a personal message to your invitation..."
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              />
            </div>
          </>
        ) : (
          <>
            {/* Role Selection for Link */}
            <div>
              <label className="block text-sm font-semibold text-brand-primary mb-2 uppercase">
                Role
              </label>
              <select
                value={role}
                onChange={(e) => setRole(e.target.value)}
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              >
                <option value="coach">Coach</option>
                <option value="club_admin">Club Admin</option>
                {leagueId && <option value="league_admin">League Admin</option>}
              </select>
            </div>

            {/* Max Uses for Link */}
            <div>
              <label className="block text-sm font-semibold text-brand-primary mb-2 uppercase">
                Maximum Uses (Optional)
              </label>
              <input
                type="number"
                value={maxUses}
                onChange={(e) => setMaxUses(e.target.value)}
                min="1"
                placeholder="Leave empty for unlimited uses"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              />
              <p className="text-sm text-gray-600 mt-1">
                Set a limit on how many people can join using this link
              </p>
            </div>

            {/* Generated Link Display */}
            {generatedLink && (
              <div className="bg-brand-secondary border border-brand-secondary rounded-md p-4">
                <p className="text-sm font-semibold text-brand-primary mb-2 uppercase">
                  Your invitation link is ready!
                </p>
                <div className="flex items-center space-x-2">
                  <input
                    type="text"
                    value={generatedLink}
                    readOnly
                    className="flex-1 bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 text-sm"
                  />
                  <button
                    type="button"
                    onClick={() => navigator.clipboard.writeText(generatedLink)}
                    className="px-4 py-2 bg-white text-brand-primary border border-brand-secondary rounded-md hover:bg-brand-secondary font-semibold text-sm uppercase"
                  >
                    Copy
                  </button>
                </div>
              </div>
            )}
          </>
        )}

        {/* Error/Success Messages */}
        {error && (
          <div className="bg-red-50 border border-red-200 rounded-md p-4 text-red-700 text-sm">
            {error}
          </div>
        )}

        {success && (
          <div className="bg-green-50 border border-green-200 rounded-md p-4 text-green-700 text-sm">
            {success}
          </div>
        )}

        {/* Submit Button */}
        <button
          type="submit"
          disabled={loading}
          className="w-full bg-brand-primary hover:bg-brand-primary text-white font-semibold py-3 px-4 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed uppercase"
        >
          {loading ? 'Processing...' : inviteMethod === 'email' ? 'Send Invitations' : 'Generate Link'}
        </button>
      </form>
    </div>
  );
}

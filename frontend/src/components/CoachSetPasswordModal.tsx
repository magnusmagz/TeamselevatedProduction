import React, { useState } from 'react';
import { TEMP_PASSWORD_MIN_LENGTH, generateTemporaryPassword } from '../utils/coachAccess';

/**
 * "Set password" for a coach — a club admin types (or generates) a temporary
 * password, and after the save the modal shows it ONCE with a copy button.
 *
 * Deliberately no forced-change flag (product decision, 2026-09-06). The nudge
 * is the sentence below plus a dismissible banner on the coach's dashboard
 * (AdminSetPasswordBanner). The server (api/coach-access.php) is what decides
 * whether this admin may do this; the modal only carries the request.
 */

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface CoachRef {
  id: number;
  first_name: string;
  last_name: string;
  email?: string | null;
}

interface Props {
  coach: CoachRef;
  clubId: number;
  onClose: () => void;
  /** Called once the server has accepted the password (the list can refresh). */
  onSaved: () => void;
}

const CoachSetPasswordModal: React.FC<Props> = ({ coach, clubId, onClose, onSaved }) => {
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  // The password as shown after a successful save. Set exactly once; the form
  // is unmounted at that point so it cannot be submitted a second time.
  const [revealed, setRevealed] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const name = `${coach.first_name} ${coach.last_name}`.trim() || coach.email || 'this coach';

  const handleGenerate = () => {
    const pw = generateTemporaryPassword(12);
    setPassword(pw);
    setConfirm(pw);
    setError(null);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    if (password.length < TEMP_PASSWORD_MIN_LENGTH) {
      setError(`Password must be at least ${TEMP_PASSWORD_MIN_LENGTH} characters.`);
      return;
    }
    if (password !== confirm) {
      setError('The two passwords do not match.');
      return;
    }
    setSaving(true);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(`${API_URL}/api/coach-access.php?action=set-temporary-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ user_id: coach.id, club_id: clubId, password }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.success) {
        setRevealed(password);
        setPassword('');
        setConfirm('');
        onSaved();
      } else {
        setError(data.error || 'Could not set the password.');
      }
    } catch {
      setError('Could not set the password.');
    } finally {
      setSaving(false);
    }
  };

  const handleCopy = async () => {
    if (!revealed) return;
    try {
      await navigator.clipboard.writeText(revealed);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md w-full max-w-md">
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <h4 className="text-lg font-semibold text-brand-primary uppercase tracking-wide">
            Set password for {name}
          </h4>
          <button
            type="button"
            onClick={onClose}
            className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
            aria-label="Close"
          >
            ×
          </button>
        </div>

        {revealed === null ? (
          <form onSubmit={handleSubmit} className="p-6 space-y-4">
            <p className="text-sm text-gray-600">
              This replaces any password they have and cancels any outstanding invite link.
              You will see the password once after saving.
            </p>
            <div>
              <label htmlFor="coach-temp-password" className="block text-brand-primary text-sm font-medium mb-1 uppercase">
                Temporary password
              </label>
              <div className="flex gap-2">
                <input
                  id="coach-temp-password"
                  type="text"
                  autoComplete="off"
                  spellCheck={false}
                  className="flex-1 bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 font-mono focus:outline-none focus:border-brand-accent"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  minLength={TEMP_PASSWORD_MIN_LENGTH}
                />
                <button
                  type="button"
                  onClick={handleGenerate}
                  className="bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 hover:bg-gray-100 uppercase text-xs font-semibold"
                >
                  Generate
                </button>
              </div>
              <p className="text-xs text-gray-500 mt-1">At least {TEMP_PASSWORD_MIN_LENGTH} characters.</p>
            </div>
            <div>
              <label htmlFor="coach-temp-password-confirm" className="block text-brand-primary text-sm font-medium mb-1 uppercase">
                Confirm password
              </label>
              <input
                id="coach-temp-password-confirm"
                type="text"
                autoComplete="off"
                spellCheck={false}
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 font-mono focus:outline-none focus:border-brand-accent"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
              />
            </div>
            {error && (
              <p className="text-sm text-red-700" role="alert">
                {error}
              </p>
            )}
            <div className="flex justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={onClose}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-100 uppercase text-sm"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={saving}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 font-semibold uppercase text-sm disabled:opacity-50"
              >
                {saving ? 'Saving…' : 'Set password'}
              </button>
            </div>
          </form>
        ) : (
          <div className="p-6 space-y-4">
            <p className="text-sm text-gray-700">
              Password set for <span className="font-semibold">{name}</span>. It is shown here
              once — copy it now.
            </p>
            <div className="flex items-center gap-2">
              <code
                className="flex-1 bg-gray-50 border border-brand-secondary rounded-md px-3 py-2 font-mono text-brand-primary break-all"
                data-testid="revealed-password"
              >
                {revealed}
              </code>
              <button
                type="button"
                onClick={handleCopy}
                className="bg-brand-primary text-white rounded-md px-3 py-2 text-xs font-semibold uppercase"
              >
                {copied ? 'Copied' : 'Copy'}
              </button>
            </div>
            <p className="text-sm text-gray-700">Ask them to change it after signing in.</p>
            <div className="flex justify-end pt-2">
              <button
                type="button"
                onClick={onClose}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-100 uppercase text-sm"
              >
                Done
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default CoachSetPasswordModal;

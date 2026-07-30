import React, { useState } from 'react';
import {
  JERSEY_SIZE_GROUPS,
  jerseySizesInGroup,
  formatJerseySize,
} from '../../utils/jerseySize';

// The option list comes from utils/jerseySize.ts — the same module the staff-side
// athlete form and roster slide-out use. Crew and staff therefore pick from one
// list by construction, so the two surfaces cannot drift into offering different
// sizes for the same column (that list is in turn locked to lib/jersey_size.php
// and the migration-054 CHECK constraint by JerseySizeConsistencyTest).

interface JerseySizeCardProps {
  athleteId: number;
  /** Stored code ('YM') or null/undefined when nobody has filled it in yet. */
  jerseySize?: string | null;
  /** Used for the empty-state copy: "We don't have Rachel's size yet." */
  athleteFirstName: string;
  /** Lets the parent page keep its copy current after a save. */
  onSaved?: (size: string | null) => void;
}

export const JerseySizeCard: React.FC<JerseySizeCardProps> = ({
  athleteId,
  jerseySize,
  athleteFirstName,
  onSaved,
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

  const [size, setSize] = useState<string | null>(jerseySize ?? null);
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<string>(jerseySize ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [justSaved, setJustSaved] = useState(false);

  const startEditing = () => {
    setDraft(size ?? '');
    setError(null);
    setJustSaved(false);
    setEditing(true);
  };

  const handleSave = async () => {
    setSaving(true);
    setError(null);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/athlete-jersey-size.php`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        // '' is a deliberate clear ("we don't know yet"), which the backend
        // stores as NULL. It is not the same as never having opened this form.
        body: JSON.stringify({ athlete_id: athleteId, jersey_size: draft }),
      });
      const data = await response.json();

      if (response.ok && data.success) {
        const saved: string | null = data.jersey_size ?? null;
        setSize(saved);
        setEditing(false);
        setJustSaved(true);
        onSaved?.(saved);
      } else {
        setError(data.error || 'Could not save the size. Please try again.');
      }
    } catch {
      setError('Unable to reach the server. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="px-4 mb-4">
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div className="flex items-center justify-between mb-3">
          <h2 className="font-semibold text-brand-primary">Uniform</h2>
          {!editing && (
            <button
              onClick={startEditing}
              className="text-sm text-brand-accent hover:underline"
            >
              {size ? 'Edit' : 'Add size'}
            </button>
          )}
        </div>

        {!editing ? (
          <>
            <div className="flex items-center justify-between">
              <span className="text-sm text-gray-600">Jersey size</span>
              <span className={size ? 'font-medium text-gray-900' : 'text-gray-400'}>
                {size ? formatJerseySize(size) : 'Not set'}
              </span>
            </div>
            {!size && (
              <p className="text-xs text-gray-500 mt-2">
                We don't have {athleteFirstName}'s jersey size yet. Adding it here
                means the club can order the right kit.
              </p>
            )}
            {justSaved && (
              <p className="text-xs text-green-700 mt-2">Saved.</p>
            )}
          </>
        ) : (
          <>
            <label
              htmlFor="jersey-size-select"
              className="block text-sm text-gray-600 mb-1"
            >
              Jersey size
            </label>
            <select
              id="jersey-size-select"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              disabled={saving}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-primary disabled:opacity-50"
            >
              {/* Youth Medium and Adult Medium are very different garments, so the
                  optgroups are load-bearing, not decoration — there is no bare
                  'Medium' to pick by mistake. */}
              <option value="">Not sure yet</option>
              {JERSEY_SIZE_GROUPS.map((group) => (
                <optgroup key={group} label={group}>
                  {jerseySizesInGroup(group).map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </optgroup>
              ))}
            </select>

            {error && (
              <div className="bg-red-50 text-red-700 px-3 py-2 rounded text-sm mt-3">
                {error}
              </div>
            )}

            <div className="flex gap-3 mt-3">
              <button
                onClick={() => {
                  setEditing(false);
                  setError(null);
                }}
                disabled={saving}
                className="flex-1 px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm font-medium disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={saving}
                className="flex-1 px-4 py-2 bg-brand-primary text-white rounded-md hover:opacity-90 text-sm font-medium disabled:opacity-50"
              >
                {saving ? 'Saving...' : 'Save'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
};

export default JerseySizeCard;

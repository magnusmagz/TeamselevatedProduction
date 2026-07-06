import React, { useState, useEffect, useCallback } from 'react';
import { TryoutSession, Venue } from '../types';
import VenuePicker from './VenuePicker';

interface ProgramScheduleBuilderProps {
  programId: number;
  venues: Venue[];
}

const API_URL =
  process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

const authHeaders = () => ({
  'Content-Type': 'application/json',
  Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
});

const blankSession = (): TryoutSession => ({
  program_id: 0, // filled in on save
  name: '',
  session_date: '',
  start_time: '',
  end_time: '',
  location: '',
  venue_id: undefined,
  is_rain_date: false,
});

/**
 * Build/edit a program's meeting schedule. Backed by the program-scoped
 * tryout_sessions table via tryouts-api (path=sessions) — works for any program,
 * not just tryouts. Requires a saved program (needs a program_id).
 */
const ProgramScheduleBuilder: React.FC<ProgramScheduleBuilderProps> = ({ programId, venues }) => {
  const [sessions, setSessions] = useState<TryoutSession[]>([]);
  const [deletedIds, setDeletedIds] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(
        `${API_URL}/registration/tryouts-api.php?path=sessions&program_id=${programId}`,
        { headers: authHeaders() }
      );
      const data = await res.json();
      setSessions(Array.isArray(data) ? data : []);
      setDeletedIds([]);
    } catch (e) {
      console.error('Error loading schedule:', e);
      setSessions([]);
    } finally {
      setLoading(false);
    }
  }, [programId]);

  useEffect(() => {
    load();
  }, [load]);

  const update = (idx: number, patch: Partial<TryoutSession>) =>
    setSessions((prev) => prev.map((s, i) => (i === idx ? { ...s, ...patch } : s)));

  const addRow = () => setSessions((prev) => [...prev, blankSession()]);

  const removeRow = (idx: number) =>
    setSessions((prev) => {
      const s = prev[idx];
      if (s.id) setDeletedIds((d) => [...d, s.id as number]);
      return prev.filter((_, i) => i !== idx);
    });

  const save = async () => {
    if (sessions.some((s) => !s.session_date)) {
      alert('Every session needs a date.');
      return;
    }
    setSaving(true);
    try {
      for (const id of deletedIds) {
        await fetch(`${API_URL}/registration/tryouts-api.php?path=sessions&id=${id}`, {
          method: 'DELETE',
          headers: authHeaders(),
        });
      }
      for (const s of sessions) {
        const body = {
          program_id: programId,
          name: s.name || null,
          session_date: s.session_date,
          start_time: s.start_time || null,
          end_time: s.end_time || null,
          location: s.location || null,
          venue_id: s.venue_id || null,
          is_rain_date: !!s.is_rain_date,
        };
        if (s.id) {
          await fetch(`${API_URL}/registration/tryouts-api.php?path=sessions&id=${s.id}`, {
            method: 'PUT',
            headers: authHeaders(),
            body: JSON.stringify(body),
          });
        } else {
          await fetch(`${API_URL}/registration/tryouts-api.php?path=sessions`, {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify(body),
          });
        }
      }
      await load();
      alert('Schedule saved.');
    } catch (e) {
      console.error('Error saving schedule:', e);
      alert('An error occurred saving the schedule. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div className="text-brand-primary py-8 text-center">Loading schedule…</div>;
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-start gap-4">
        <p className="text-sm text-gray-600">
          Add each date this program meets. Times and location are optional; a session with no
          facility uses the program's facility.
        </p>
        <button
          onClick={addRow}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 text-sm uppercase font-semibold whitespace-nowrap"
        >
          + Add Session
        </button>
      </div>

      {sessions.length === 0 ? (
        <div className="text-center text-gray-500 py-8 border border-dashed border-brand-secondary rounded-md">
          No sessions yet. Click “Add Session” to build the schedule.
        </div>
      ) : (
        <div className="space-y-3">
          {sessions.map((s, idx) => (
            <div
              key={s.id ?? `new-${idx}`}
              className="border border-brand-secondary rounded-md p-4 grid grid-cols-2 gap-3"
            >
              <div>
                <label className="block text-xs text-gray-500 uppercase mb-1">Label</label>
                <input
                  type="text"
                  className="w-full border border-brand-secondary rounded-md px-3 py-2"
                  value={s.name || ''}
                  onChange={(e) => update(idx, { name: e.target.value })}
                  placeholder="e.g. Day 1"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 uppercase mb-1">Date *</label>
                <input
                  type="date"
                  className="w-full border border-brand-secondary rounded-md px-3 py-2"
                  value={s.session_date || ''}
                  onChange={(e) => update(idx, { session_date: e.target.value })}
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 uppercase mb-1">Start</label>
                <input
                  type="time"
                  className="w-full border border-brand-secondary rounded-md px-3 py-2"
                  value={s.start_time || ''}
                  onChange={(e) => update(idx, { start_time: e.target.value })}
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 uppercase mb-1">End</label>
                <input
                  type="time"
                  className="w-full border border-brand-secondary rounded-md px-3 py-2"
                  value={s.end_time || ''}
                  onChange={(e) => update(idx, { end_time: e.target.value })}
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 uppercase mb-1">Facility</label>
                <VenuePicker
                  venues={venues}
                  value={s.venue_id}
                  onChange={(id) => update(idx, { venue_id: id })}
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 uppercase mb-1">Location note</label>
                <input
                  type="text"
                  className="w-full border border-brand-secondary rounded-md px-3 py-2"
                  value={s.location || ''}
                  onChange={(e) => update(idx, { location: e.target.value })}
                  placeholder="Field 3, North lot…"
                />
              </div>
              <div className="col-span-2 flex items-center justify-between">
                <label className="flex items-center gap-2 text-sm text-brand-primary">
                  <input
                    type="checkbox"
                    checked={!!s.is_rain_date}
                    onChange={(e) => update(idx, { is_rain_date: e.target.checked })}
                  />
                  Rain date
                </label>
                <button
                  onClick={() => removeRow(idx)}
                  className="text-red-600 hover:underline text-xs uppercase"
                >
                  Remove
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="flex justify-end pt-2">
        <button
          onClick={save}
          disabled={saving}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 font-semibold uppercase disabled:opacity-50"
        >
          {saving ? 'Saving…' : 'Save Schedule'}
        </button>
      </div>
    </div>
  );
};

export default ProgramScheduleBuilder;

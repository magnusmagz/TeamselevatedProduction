import React, { useCallback, useEffect, useState } from 'react';
import { pageQuery, rowsFrom } from '../../../utils/pagination';

/**
 * Who runs this program.
 *
 * Camps, clinics and drop-ins have registrants and no roster, so a coach
 * assigned here is the only way the person running the session gets the program
 * on their calendar and can reach the families who signed up. Everything else in
 * the product derives a coach's reach from `team_members`, which is empty for
 * these programs.
 *
 * Two rules this UI exists to make visible:
 *   - only club coaches and admins can be assigned. The backend refuses a parent
 *     with a 422, and this list only ever offers people from the club's coach
 *     list, so the refusal should be unreachable — but the message is rendered
 *     rather than swallowed, because "nothing happened" is the worst answer.
 *   - `available: false` means migration 086 has not run yet. That is a
 *     different fact from "nobody is assigned", and an empty list would read as
 *     the second.
 */

interface StaffMember {
  user_id: number;
  first_name: string;
  last_name: string;
  email: string;
  role: string;
  created_at?: string;
}

interface AvailableCoach {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
}

interface Props {
  programId: number;
  programName: string;
  onClose: () => void;
}

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

const authHeaders = (): Record<string, string> => ({
  'Content-Type': 'application/json',
  Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
});

const fullName = (p: { first_name?: string; last_name?: string }) =>
  `${p.first_name ?? ''} ${p.last_name ?? ''}`.trim();

const ProgramStaffModal: React.FC<Props> = ({ programId, programName, onClose }) => {
  const [staff, setStaff] = useState<StaffMember[]>([]);
  const [coaches, setCoaches] = useState<AvailableCoach[]>([]);
  const [available, setAvailable] = useState(true);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<string>('');

  const loadStaff = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(
        `${API_URL}/legacy/programs-gateway.php?action=staff&program_id=${programId}`,
        { headers: authHeaders() }
      );
      const data = await res.json().catch(() => null);
      if (!res.ok) {
        setError(data?.error || 'Could not load program staff.');
        setStaff([]);
        return;
      }
      setStaff(Array.isArray(data?.staff) ? data.staff : []);
      // Reported by the backend rather than inferred from an empty list.
      setAvailable(data?.available !== false);
    } catch (e) {
      setError('Could not load program staff.');
      setStaff([]);
    } finally {
      setLoading(false);
    }
  }, [programId]);

  useEffect(() => {
    loadStaff();
  }, [loadStaff]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        // A picker: ask for the ceiling rather than the default page, since a
        // name absent from a dropdown is indistinguishable from a name that does
        // not exist. rowsFrom() accepts the old bare array and the new
        // {coaches, page} object, so this works against either backend.
        const res = await fetch(
          `${API_URL}/legacy/coaches-gateway.php?action=available${pageQuery(null, 1000)}`,
          { headers: authHeaders() }
        );
        if (!res.ok) return;
        const data = await res.json();
        if (!cancelled) setCoaches(rowsFrom<any>(data, 'coaches'));
      } catch {
        // Non-fatal: the assigned list still renders, the picker is just empty.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const post = async (action: 'assign-staff' | 'remove-staff', userId: number) => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/legacy/programs-gateway.php?action=${action}`, {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({ program_id: programId, user_id: userId, role: 'coach' }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok) {
        // 422 (not a coach) and 503 (migration 086 not applied) both carry a
        // message the admin can act on. Show it instead of a generic failure.
        setError(data?.error || 'Could not update program staff.');
        return;
      }
      setSelectedId('');
      await loadStaff();
    } catch (e) {
      setError('Could not update program staff.');
    } finally {
      setBusy(false);
    }
  };

  const assignedIds = new Set(staff.map((s) => s.user_id));
  const selectable = coaches.filter((c) => !assignedIds.has(c.id));

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
        <div className="flex items-start justify-between p-4 border-b">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Staff</h2>
            <p className="text-sm text-gray-500">{programName}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="text-gray-400 hover:text-gray-600 text-xl leading-none"
          >
            ×
          </button>
        </div>

        <div className="p-4 space-y-4">
          <p className="text-xs text-gray-500">
            Assigned coaches see this program on their calendar and can message the families
            registered to it. Only club coaches and admins can be assigned.
          </p>

          {!available && (
            <div className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded p-2">
              Program staffing is not available yet.
            </div>
          )}

          {error && (
            <div role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 rounded p-2">
              {error}
            </div>
          )}

          {loading ? (
            <div className="text-sm text-gray-500">Loading…</div>
          ) : staff.length === 0 ? (
            <div className="text-sm text-gray-500">No coaches assigned yet.</div>
          ) : (
            <ul className="divide-y border rounded">
              {staff.map((s) => (
                <li key={s.user_id} className="flex items-center justify-between px-3 py-2">
                  <div>
                    <div className="text-sm font-medium text-gray-900">{fullName(s)}</div>
                    <div className="text-xs text-gray-500">{s.email}</div>
                  </div>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => post('remove-staff', s.user_id)}
                    className="text-xs text-red-600 hover:text-red-800 disabled:opacity-50"
                  >
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}

          <div className="flex items-center gap-2 pt-2 border-t">
            <label htmlFor="program-staff-add" className="sr-only">
              Add a coach
            </label>
            <select
              id="program-staff-add"
              value={selectedId}
              disabled={busy || !available}
              onChange={(e) => setSelectedId(e.target.value)}
              className="flex-1 border rounded px-2 py-1 text-sm"
            >
              <option value="">Select a coach…</option>
              {selectable.map((c) => (
                <option key={c.id} value={c.id}>
                  {fullName(c)} {c.email ? `(${c.email})` : ''}
                </option>
              ))}
            </select>
            <button
              type="button"
              disabled={busy || !selectedId || !available}
              onClick={() => post('assign-staff', Number(selectedId))}
              className="px-3 py-1 text-sm rounded bg-brand-primary text-white disabled:opacity-50"
            >
              Add
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProgramStaffModal;

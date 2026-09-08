import React, { useCallback, useEffect, useState } from 'react';
import Button from '../ui/Button';
import RefereeTypeahead from './RefereeTypeahead';
import {
  GAME_REFEREE_ROLES, GAME_REFEREE_ROLE_LABEL, GameRefereeAssignment, GameRefereeRole,
  PendingRefereeAssignment, RefereeSearchHit,
} from './refereeTypes';
import { refereeGradeMeets } from '../../constants/refereeGrades';

/**
 * The Referees block on a game's event modal (Maggie, 2026-09-08).
 *
 * Two modes, one component, so the create and edit forms look the same:
 *
 *  - EDIT (eventId set): server-backed. Lists the assignments from
 *    `action=for-event`; Assign / Unassign call the API at once. Event staff
 *    (club admin of the game's club, or a coach of a team on it) get the
 *    controls; anyone else sees the names read-only. The server re-derives
 *    standing on every call — `canEdit` only decides what is drawn.
 *  - CREATE (no eventId): local. The picks are held in `pending` and the
 *    parent sends them as `referees: [{referee_id, role}]` with the game, so a
 *    game is saved with its referees in one request.
 *
 * A referee below the game's minimum grade can still be placed by staff; the
 * picker warns, and the server records grade_override on the row.
 */
interface Props {
  apiUrl: string;
  clubId: number | null;
  eventId?: number | null;
  canEdit: boolean;
  /** The game's minimum grade (Any when null). Drives the picker warning. */
  minGrade?: string | null;
  /** CREATE mode only. */
  pending?: PendingRefereeAssignment[];
  onPendingChange?: (next: PendingRefereeAssignment[]) => void;
  /** EDIT mode: called after a successful assign / unassign so the calendar can refresh its chips. */
  onChanged?: () => void;
}

const GameRefereesBlock: React.FC<Props> = ({
  apiUrl, clubId, eventId = null, canEdit, minGrade = null, pending = [], onPendingChange, onChanged,
}) => {
  const [assigned, setAssigned] = useState<GameRefereeAssignment[]>([]);
  const [available, setAvailable] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [picked, setPicked] = useState<RefereeSearchHit | null>(null);
  const [role, setRole] = useState<GameRefereeRole>('center');
  const [busy, setBusy] = useState(false);
  const token = localStorage.getItem('auth_token');
  const editMode = eventId != null;

  const load = useCallback(async () => {
    if (!editMode) return;
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${apiUrl}/api/referees.php?action=for-event&event_id=${eventId}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const data = await res.json();
      if (!res.ok || !data?.success) {
        setError(data?.error || `Could not load referees (${res.status})`);
        return;
      }
      setAvailable(data.available !== false);
      setAssigned(Array.isArray(data.referees) ? data.referees : []);
    } catch (err: any) {
      setError(err?.message || 'Could not load referees');
    } finally {
      setLoading(false);
    }
  }, [apiUrl, eventId, token, editMode]);

  useEffect(() => {
    load();
  }, [load]);

  const belowMinimum = picked ? !refereeGradeMeets(picked.grade, minGrade) : false;

  const add = async () => {
    if (!picked) return;
    setError(null);
    if (!editMode) {
      if (pending.some((p) => p.referee_id === picked.id)) return;
      onPendingChange?.([...pending, { referee_id: picked.id, name: picked.name, grade: picked.grade, role }]);
      setPicked(null);
      setQuery('');
      return;
    }
    setBusy(true);
    try {
      const res = await fetch(`${apiUrl}/api/referees.php?action=assign`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ event_id: eventId, referee_id: picked.id, role }),
      });
      const data = await res.json();
      if (!res.ok || !data?.success) {
        setError(data?.error || `Could not assign (${res.status})`);
        return;
      }
      setAssigned(Array.isArray(data.referees) ? data.referees : []);
      setPicked(null);
      setQuery('');
      onChanged?.();
    } catch (err: any) {
      setError(err?.message || 'Could not assign');
    } finally {
      setBusy(false);
    }
  };

  const remove = async (refereeId: number) => {
    setError(null);
    if (!editMode) {
      onPendingChange?.(pending.filter((p) => p.referee_id !== refereeId));
      return;
    }
    setBusy(true);
    try {
      const res = await fetch(`${apiUrl}/api/referees.php?action=unassign`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ event_id: eventId, referee_id: refereeId }),
      });
      const data = await res.json();
      if (!res.ok || !data?.success) {
        setError(data?.error || `Could not unassign (${res.status})`);
        return;
      }
      setAssigned(Array.isArray(data.referees) ? data.referees : []);
      onChanged?.();
    } catch (err: any) {
      setError(err?.message || 'Could not unassign');
    } finally {
      setBusy(false);
    }
  };

  const rows: Array<{ id: number; name: string; grade: string | null; role: string; self_assigned?: boolean; grade_override?: boolean }> =
    editMode ? assigned : pending.map((p) => ({ id: p.referee_id, name: p.name, grade: p.grade, role: p.role }));
  const excludeIds = rows.map((r) => r.id);
  const roleLabel = (r: string) => GAME_REFEREE_ROLE_LABEL[r as GameRefereeRole] ?? r;

  return (
    <div className="col-span-2 border border-brand-secondary rounded-md p-3" data-testid="game-referees-block">
      <div className="flex items-center justify-between mb-2">
        <span className="block text-brand-primary text-sm font-medium uppercase">Referees</span>
        {minGrade && <span className="text-xs text-gray-600">Minimum grade: {minGrade}</span>}
      </div>

      {loading && <p className="text-xs text-gray-500">Loading…</p>}
      {!available && editMode && (
        <p className="text-xs text-gray-500">The referee directory is not switched on for this club yet.</p>
      )}

      {rows.length === 0 && !loading && (
        <p className="text-sm text-gray-500" data-testid="game-referees-empty">No referees assigned yet.</p>
      )}
      {rows.length > 0 && (
        <ul className="divide-y divide-gray-100 mb-2">
          {rows.map((r) => (
            <li key={r.id} className="py-1.5 flex items-center justify-between gap-2 text-sm" data-testid={`game-referee-${r.id}`}>
              <span>
                <span className="font-medium text-gray-900">{r.name}</span>
                <span className="ml-2 text-xs text-gray-500">{roleLabel(r.role)}</span>
                {r.grade && <span className="ml-2 text-xs text-gray-500">· {r.grade}</span>}
                {r.self_assigned && (
                  <span className="ml-2 inline-block px-1.5 py-0.5 text-xs rounded bg-sky-100 text-sky-800">Self-assigned</span>
                )}
                {r.grade_override && (
                  <span className="ml-2 inline-block px-1.5 py-0.5 text-xs rounded bg-amber-100 text-amber-900" title="Placed below the game's minimum grade">
                    Below minimum
                  </span>
                )}
              </span>
              {canEdit && (
                <Button variant="danger-link" size="sm" disabled={busy} onClick={() => remove(r.id)}>
                  Unassign
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {canEdit && (available || !editMode) && (
        <div className="flex flex-col sm:flex-row gap-2 sm:items-start">
          <div className="flex-1">
            <RefereeTypeahead
              apiUrl={apiUrl}
              clubId={clubId}
              id="game-referee-typeahead"
              value={query}
              onChange={(t) => {
                setQuery(t);
                setPicked(null);
              }}
              onPick={(hit) => {
                setPicked(hit);
                setQuery(hit.name);
              }}
              excludeIds={excludeIds}
              placeholder="Add a referee…"
            />
            {belowMinimum && picked && (
              <p className="mt-1 text-xs text-amber-900" data-testid="grade-warning">
                {picked.name}&apos;s grade ({picked.grade || 'not set'}) is below this game&apos;s minimum ({minGrade}). You can still assign them; the assignment will be marked.
              </p>
            )}
          </div>
          <select
            aria-label="Referee role"
            value={role}
            onChange={(e) => setRole(e.target.value as GameRefereeRole)}
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 text-sm"
          >
            {GAME_REFEREE_ROLES.map((r) => (
              <option key={r} value={r}>{GAME_REFEREE_ROLE_LABEL[r]}</option>
            ))}
          </select>
          <Button variant="secondary" size="sm" disabled={!picked || busy} loading={busy} onClick={add}>
            Assign
          </Button>
        </div>
      )}

      {error && <p className="mt-2 text-xs text-red-700" role="alert">{error}</p>}
    </div>
  );
};

export default GameRefereesBlock;

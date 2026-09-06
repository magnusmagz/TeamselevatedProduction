import React from 'react';
import { formatDateOnly } from '../utils/dateFormat';
import {
  createIntakeKey,
  fetchIntakeKeys,
  fetchUnmatched,
  matchArrival,
  revokeIntakeKey,
  searchIntakePeople,
  type IntakeKey,
  type IntakePerson,
  type UnmatchedArrival,
} from './intakeApi';
import type { RollupRequirement } from './rollupApi';

/**
 * Intake keys and unmatched arrivals for one org unit (GOTR G7).
 *
 * Rendered on the org compliance page for an org_admin. A key is shown ONCE,
 * at creation, in a box the admin copies from; the list afterwards carries
 * only the prefix. There is no "show key again" — it is not stored.
 *
 * Unmatched arrivals are the LMS rows the feed could not apply: an email
 * nobody under the unit has, or a requirement key nothing resolves to. The
 * admin matches each to a person (and, for a `no_requirement` row, picks the
 * requirement); the credential is written with source='lms' and the row
 * leaves the queue.
 */

interface Props {
  orgUnitId: number;
  requirements: RollupRequirement[];
}

export const IntakeKeysPanel: React.FC<Props> = ({ orgUnitId, requirements }) => {
  const [keys, setKeys] = React.useState<IntakeKey[]>([]);
  const [arrivals, setArrivals] = React.useState<UnmatchedArrival[]>([]);
  const [available, setAvailable] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [newName, setNewName] = React.useState('');
  const [creating, setCreating] = React.useState(false);
  const [shownOnce, setShownOnce] = React.useState<{ id: number; key: string; name: string } | null>(null);

  const [matching, setMatching] = React.useState<number | null>(null);
  const [query, setQuery] = React.useState('');
  const [people, setPeople] = React.useState<IntakePerson[]>([]);
  const [chosen, setChosen] = React.useState<IntakePerson | null>(null);
  const [requirementId, setRequirementId] = React.useState<string>('');
  const [busy, setBusy] = React.useState(false);

  const load = React.useCallback(async () => {
    setError(null);
    try {
      const [k, u] = await Promise.all([fetchIntakeKeys(orgUnitId), fetchUnmatched(orgUnitId)]);
      setKeys(k.keys || []);
      setArrivals(u.arrivals || []);
      setAvailable(k.available !== false && u.available !== false);
    } catch (err: any) {
      setError(err?.message || 'Could not load intake settings');
    }
  }, [orgUnitId]);

  React.useEffect(() => {
    load();
  }, [load]);

  const create = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!newName.trim()) return;
    setCreating(true);
    setError(null);
    try {
      const body = await createIntakeKey(orgUnitId, newName.trim());
      setShownOnce({ id: body.id, key: body.key, name: newName.trim() });
      setNewName('');
      await load();
    } catch (err: any) {
      setError(err?.message || 'Could not create the key');
    } finally {
      setCreating(false);
    }
  };

  const revoke = async (key: IntakeKey) => {
    if (!window.confirm(`Revoke "${key.name}"? Anything still sending with it will get 401s from now on.`)) return;
    setError(null);
    try {
      await revokeIntakeKey(orgUnitId, key.id);
      if (shownOnce?.id === key.id) setShownOnce(null);
      await load();
    } catch (err: any) {
      setError(err?.message || 'Could not revoke the key');
    }
  };

  const search = async (q: string) => {
    setQuery(q);
    setChosen(null);
    if (q.trim().length < 2) {
      setPeople([]);
      return;
    }
    try {
      const body = await searchIntakePeople(orgUnitId, q.trim());
      setPeople(body.people || []);
    } catch {
      setPeople([]);
    }
  };

  const openMatch = (arrival: UnmatchedArrival) => {
    setMatching(arrival.id);
    setQuery('');
    setPeople([]);
    setChosen(null);
    setRequirementId('');
    setError(null);
  };

  const submitMatch = async (arrival: UnmatchedArrival) => {
    if (!chosen) return;
    setBusy(true);
    setError(null);
    try {
      await matchArrival({
        org_unit_id: orgUnitId,
        id: arrival.id,
        user_id: chosen.user_id,
        requirement_id: requirementId ? Number(requirementId) : undefined,
      });
      setMatching(null);
      await load();
    } catch (err: any) {
      setError(err?.message || 'Could not match the arrival');
    } finally {
      setBusy(false);
    }
  };

  const personName = (p: IntakePerson) => `${p.first_name || ''} ${p.last_name || ''}`.trim() || p.email || `User ${p.user_id}`;

  return (
    <section className="mt-8" data-testid="intake-keys-panel">
      <h2 className="mb-1 text-sm font-semibold uppercase tracking-wide text-gray-500">Intake keys</h2>
      <p className="mb-3 text-sm text-gray-500">
        A key lets a learning system post completions for people under this organization. Each key is shown once,
        when it is made.
      </p>

      {!available && (
        <p className="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
          Credential intake is not switched on for this database yet (migration 098).
        </p>
      )}
      {error && (
        <p role="alert" className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
          {error}
        </p>
      )}

      {shownOnce && (
        <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4" data-testid="intake-key-once">
          <p className="text-sm font-medium text-amber-900">
            Copy this key for “{shownOnce.name}” now. It will not be shown again.
          </p>
          <code className="mt-2 block break-all rounded bg-white p-2 font-mono text-sm text-gray-800">{shownOnce.key}</code>
          <button type="button" onClick={() => setShownOnce(null)} className="mt-2 text-xs text-amber-900 underline">
            I have copied it
          </button>
        </div>
      )}

      <form onSubmit={create} className="mb-4 flex flex-wrap items-end gap-2" aria-label="New intake key">
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-gray-700">Key name</span>
          <input
            type="text"
            value={newName}
            onChange={(e) => setNewName(e.target.value)}
            placeholder="Cornerstone production"
            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm"
          />
        </label>
        <button
          type="submit"
          disabled={creating || !available || !newName.trim()}
          className="rounded-md bg-brand-primary px-3 py-2 text-xs font-semibold uppercase text-white disabled:opacity-50"
        >
          {creating ? 'Creating…' : 'Create key'}
        </button>
      </form>

      {keys.length > 0 && (
        <ul className="mb-6 divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white text-sm">
          {keys.map((key) => (
            <li key={key.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-testid={`intake-key-${key.id}`}>
              <div>
                <span className="font-medium text-gray-800">{key.name}</span>{' '}
                <code className="font-mono text-xs text-gray-500">{key.key_prefix}…</code>
                <span className="ml-2 text-xs text-gray-500">
                  {key.revoked_at
                    ? 'revoked'
                    : key.last_used_at
                    ? `last used ${formatDateOnly(key.last_used_at.slice(0, 10))}`
                    : 'never used'}
                </span>
              </div>
              {!key.revoked_at && (
                <button type="button" onClick={() => revoke(key)} className="text-xs text-red-700 underline">
                  Revoke
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-1 text-sm font-semibold uppercase tracking-wide text-gray-500">Unmatched arrivals</h3>
      <p className="mb-3 text-sm text-gray-500">
        Completions the feed could not apply — nobody under this organization has the email, or the requirement
        key did not match. Match each one to a person.
      </p>
      {arrivals.length === 0 ? (
        <p className="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500">
          Nothing waiting.
        </p>
      ) : (
        <ul className="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white text-sm">
          {arrivals.map((arrival) => (
            <li key={arrival.id} className="px-3 py-2" data-testid={`arrival-${arrival.id}`}>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <span className="font-medium text-gray-800">{arrival.email}</span>
                  <span className="ml-2 text-gray-600">{arrival.requirement_key}</span>
                  {arrival.completed_on && (
                    <span className="ml-2 text-xs text-gray-500">completed {formatDateOnly(arrival.completed_on)}</span>
                  )}
                  <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                    {arrival.reason === 'no_person' ? 'no matching person' : arrival.reason === 'no_requirement' ? 'no matching requirement' : arrival.reason}
                  </span>
                </div>
                <button type="button" onClick={() => openMatch(arrival)} className="text-xs text-brand-primary underline">
                  Match to person
                </button>
              </div>

              {matching === arrival.id && (
                <div className="mt-2 rounded-md border border-brand-secondary bg-gray-50 p-3">
                  <label className="block">
                    <span className="mb-1 block text-xs font-medium text-gray-700">Person (name or email)</span>
                    <input
                      type="search"
                      value={query}
                      onChange={(e) => search(e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
                    />
                  </label>
                  {people.length > 0 && !chosen && (
                    <ul className="mt-1 max-h-40 overflow-y-auto rounded-md border border-gray-200 bg-white">
                      {people.map((p) => (
                        <li key={p.user_id}>
                          <button
                            type="button"
                            onClick={() => setChosen(p)}
                            className="block w-full px-2 py-1 text-left text-sm hover:bg-gray-50"
                          >
                            {personName(p)} <span className="text-xs text-gray-500">{p.email}</span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                  {chosen && <p className="mt-1 text-sm text-gray-700">Matching to <strong>{personName(chosen)}</strong></p>}

                  {arrival.reason === 'no_requirement' && (
                    <label className="mt-2 block">
                      <span className="mb-1 block text-xs font-medium text-gray-700">Requirement</span>
                      <select
                        value={requirementId}
                        onChange={(e) => setRequirementId(e.target.value)}
                        className="rounded-md border border-gray-300 px-2 py-1 text-sm"
                      >
                        <option value="">Choose…</option>
                        {requirements.map((r) => (
                          <option key={r.id} value={r.id}>
                            {r.name}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}

                  <div className="mt-2 flex gap-3">
                    <button
                      type="button"
                      disabled={busy || !chosen || (arrival.reason === 'no_requirement' && !requirementId)}
                      onClick={() => submitMatch(arrival)}
                      className="rounded-md bg-brand-primary px-3 py-1.5 text-xs font-semibold uppercase text-white disabled:opacity-50"
                    >
                      {busy ? 'Saving…' : 'Record completion'}
                    </button>
                    <button type="button" onClick={() => setMatching(null)} className="text-xs text-gray-700 underline">
                      Cancel
                    </button>
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
};

export default IntakeKeysPanel;

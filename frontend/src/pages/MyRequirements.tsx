import React from 'react';
import { formatDateOnly, toDateOnlyString } from '../utils/dateFormat';
import StatusChip from '../compliance/StatusChip';
import { fetchMyRequirements, submitOwnCompletion } from '../compliance/api';
import type { ComplianceRow, MyComplianceClub } from '../compliance/types';

/**
 * "My requirements" — `/compliance/mine` (GOTR G4).
 *
 * ⚠️ ONE PAGE, TWO DOORS. It is linked from the staff ProfileMenu and from the
 * parent portal's More menu, because a coach who is also a parent may be living
 * in either app and this is the same list either way. Duplicating it would mean
 * two places to fix the day a status label changes.
 *
 * ⚠️ MOBILE FIRST, AND THAT IS NOT DECORATION. A junior coach is 16 and a team
 * helper is a parent standing on a field; both will open this on a phone. One
 * column, tap targets that are whole cards rather than links inside prose, and
 * a status chip readable without opening anything.
 *
 * ⚠️ EACH PROOF TYPE GETS ITS OWN ACTION, and the document one is DISABLED.
 *   attested_date — a date picker and "I completed this on…"
 *   external_link — open the link, then the same date
 *   document      — disabled, with the storage note (decision 14): uploads go
 *                   to the dyno's local disk today and do not survive a restart,
 *                   so a button here would take a coach's certificate and lose
 *                   it. A control that silently discards what it was given is
 *                   worse than no control, which is the whole reason this
 *                   product spent two days removing silent promises.
 *
 * The person is the TOKEN. This page sends no user_id anywhere; `submit` is the
 * only write a non-admin can make and it can only ever be about themselves.
 */

export const MyRequirements: React.FC = () => {
  const [clubs, setClubs] = React.useState<MyComplianceClub[]>([]);
  const [available, setAvailable] = React.useState(true);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [openRow, setOpenRow] = React.useState<number | null>(null);
  const [completedAt, setCompletedAt] = React.useState(toDateOnlyString(new Date()));
  const [busy, setBusy] = React.useState<number | null>(null);

  const load = React.useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const body = await fetchMyRequirements();
      setClubs(body.clubs || []);
      setAvailable(body.available !== false);
    } catch (err: any) {
      setError(err?.message || 'Could not load your requirements');
    } finally {
      setLoading(false);
    }
  }, []);

  React.useEffect(() => {
    load();
  }, [load]);

  const attest = async (row: ComplianceRow) => {
    setBusy(row.requirement.id);
    setError(null);
    try {
      await submitOwnCompletion({
        requirement_id: row.requirement.id,
        // Straight from the date input as 'YYYY-MM-DD'. Never through a Date:
        // that is what put a coach's Tuesday practices on Wednesday.
        completed_at: completedAt,
      });
      setOpenRow(null);
      await load();
    } catch (err: any) {
      setError(err?.message || 'That did not save');
    } finally {
      setBusy(null);
    }
  };

  const rows = clubs.flatMap((club) => club.requirements || []);

  return (
    <div className="mx-auto w-full max-w-2xl px-4 py-6">
      <h1 className="text-2xl font-bold text-brand-primary">My requirements</h1>
      <p className="mt-1 text-sm text-gray-600">
        What your club needs on file for you, and when each one runs out.
      </p>

      {!available && (
        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
          Compliance is not switched on yet.
        </div>
      )}

      {error && (
        <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{error}</div>
      )}

      {loading ? (
        <p className="mt-6 text-gray-500">Loading…</p>
      ) : rows.length === 0 ? (
        <p className="mt-6 rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
          {/* Deliberately not a green tick. An empty list means nobody has asked
              anything of you yet, which is a different fact from "you are done". */}
          Your club has not asked you for anything yet.
        </p>
      ) : (
        <ul className="mt-6 space-y-3">
          {rows.map((row) => (
            <li
              key={row.requirement.id}
              className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
              data-testid={`requirement-${row.requirement.id}`}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-base font-semibold text-brand-primary">{row.requirement.name}</p>
                  {row.requirement.description && (
                    <p className="mt-1 text-sm text-gray-600">{row.requirement.description}</p>
                  )}
                </div>
                <StatusChip row={row} className="flex-shrink-0" />
              </div>

              <p className="mt-2 text-sm text-gray-500">
                {row.expires_at ? `Expires ${formatDateOnly(row.expires_at)}` : 'Never expires'}
                {row.completed_at ? ` · completed ${formatDateOnly(row.completed_at)}` : ''}
              </p>

              {row.status === 'rejected' && row.rejection_reason && (
                <p className="mt-2 rounded-md bg-red-50 p-2 text-sm text-red-800">
                  Not accepted: {row.rejection_reason}
                </p>
              )}

              {row.status === 'submitted' && (
                <p className="mt-2 text-sm text-blue-800">
                  Sent to your club. They will confirm it.
                </p>
              )}

              <div className="mt-3">
                {row.requirement.proof === 'document' ? (
                  <div data-testid={`document-disabled-${row.requirement.id}`}>
                    <button
                      type="button"
                      disabled
                      className="w-full cursor-not-allowed rounded-lg bg-gray-200 px-4 py-3 text-sm font-semibold uppercase text-gray-500"
                    >
                      Upload proof
                    </button>
                    <p className="mt-2 text-xs text-gray-500">
                      Uploads arrive with durable storage. Until then, send your certificate to your
                      club and they will record it for you.
                    </p>
                  </div>
                ) : (
                  <>
                    {row.requirement.proof === 'external_link' && row.requirement.proof_url && (
                      <a
                        href={row.requirement.proof_url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="mb-2 block w-full rounded-lg border border-brand-primary px-4 py-3 text-center text-sm font-semibold uppercase text-brand-primary"
                      >
                        Open link
                      </a>
                    )}
                    <button
                      type="button"
                      onClick={() => setOpenRow(openRow === row.requirement.id ? null : row.requirement.id)}
                      className="w-full rounded-lg bg-brand-primary px-4 py-3 text-sm font-semibold uppercase text-white"
                    >
                      I completed this
                    </button>
                  </>
                )}
              </div>

              {openRow === row.requirement.id && (
                <div className="mt-3 rounded-lg bg-gray-50 p-3">
                  <label className="block">
                    <span className="mb-1 block text-sm font-medium text-gray-700">
                      I completed this on
                    </span>
                    <input
                      type="date"
                      value={completedAt}
                      onChange={(e) => setCompletedAt(e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-3 py-2 text-base"
                    />
                  </label>
                  <button
                    type="button"
                    disabled={busy === row.requirement.id}
                    onClick={() => attest(row)}
                    className="mt-3 w-full rounded-lg bg-brand-primary px-4 py-3 text-sm font-semibold uppercase text-white disabled:opacity-50"
                  >
                    {busy === row.requirement.id ? 'Sending…' : 'Send to my club'}
                  </button>
                  <p className="mt-2 text-xs text-gray-500">
                    Your club confirms it before it counts.
                  </p>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default MyRequirements;

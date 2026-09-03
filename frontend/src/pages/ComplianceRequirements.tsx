import React from 'react';
import { Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import {
  deleteRequirement,
  fetchRequirements,
  saveRequirement,
  type SaveRequirementBody,
} from '../compliance/api';
import type { ComplianceRequirement, ComplianceVocabulary, ProofType } from '../compliance/types';

/**
 * The requirement builder — `/compliance/requirements` (GOTR G4).
 *
 * ⚠️ THE INHERITED SET IS SHOWN, READ-ONLY, ABOVE THE CLUB'S OWN.
 * A council admin has to see that national already demands SafeSport before
 * they add a fourth copy of it. Hiding what they cannot edit is what produces
 * three near-identical rules and a compliance report nobody trusts.
 *
 * `origin.editable` decides which list a row lands in and is a LABEL only. The
 * gate is the owner check in api/compliance-gateway.php — a division admin's
 * standing over one of their councils is resolved there, not here, and nothing
 * on this page is an access control.
 *
 * ⚠️ AN EMPTY ROLE SELECTION MEANS EVERYONE, and the form says so. That is the
 * useful default for "everybody does SafeSport", and it means the role list is
 * an optional narrowing rather than a step somebody can forget and thereby
 * switch a requirement off for the whole club.
 *
 * ⚠️ Proof type "document" is SELECTABLE but the coach-side submit for it is
 * disabled until durable file storage lands (decision 14 — uploads currently go
 * to the dyno's local disk and do not survive a restart). It is selectable
 * because a council's real requirement list has documents in it and recording
 * that fact now is right; the note says plainly that uploads are not open yet,
 * rather than offering a button that loses the file.
 */

const PROOF_LABELS: Record<ProofType, string> = {
  attested_date: 'A completion date the person enters',
  external_link: 'A link they complete elsewhere',
  document: 'An uploaded document',
};

const KIND_LABELS: Record<string, string> = {
  background_check: 'Background check',
  cpr_first_aid: 'CPR / First aid',
  training: 'Training',
  document: 'Document',
  custom: 'Other',
};

const ROLE_LABELS: Record<string, string> = {
  head_coach: 'Head coach',
  junior_coach: 'Junior coach',
  team_helper: 'Team helper',
  volunteer: 'Volunteer',
  coach: 'Coach',
  club_admin: 'Club admin',
};

const EMPTY_DRAFT = {
  id: 0,
  name: '',
  description: '',
  kind: 'custom',
  proof: 'attested_date' as ProofType,
  proof_url: '',
  validity_days: '',
  roles: [] as string[],
  required: true,
  active: true,
};

type Draft = typeof EMPTY_DRAFT;

function draftFrom(requirement: ComplianceRequirement): Draft {
  return {
    id: requirement.id,
    name: requirement.name,
    description: requirement.description || '',
    kind: requirement.kind,
    proof: requirement.proof,
    proof_url: requirement.proof_url || '',
    validity_days: requirement.validity_days === null ? '' : String(requirement.validity_days),
    roles: requirement.roles || [],
    required: requirement.required,
    active: requirement.active,
  };
}

export const ComplianceRequirements: React.FC = () => {
  const { currentClubId } = useOrg();

  const [requirements, setRequirements] = React.useState<ComplianceRequirement[]>([]);
  const [vocabulary, setVocabulary] = React.useState<ComplianceVocabulary | null>(null);
  const [available, setAvailable] = React.useState(true);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [draft, setDraft] = React.useState<Draft | null>(null);
  const [saving, setSaving] = React.useState(false);

  const load = React.useCallback(async () => {
    if (!currentClubId) return;
    setLoading(true);
    setError(null);
    try {
      const body = await fetchRequirements(currentClubId);
      setRequirements(body.requirements || []);
      setVocabulary(body.vocabulary || null);
      setAvailable(body.available !== false);
    } catch (err: any) {
      setError(err?.message || 'Could not load requirements');
    } finally {
      setLoading(false);
    }
  }, [currentClubId]);

  React.useEffect(() => {
    load();
  }, [load]);

  // Inherited rows are the ones this club did not write. They are shown, and
  // they cannot be edited from here.
  const inherited = requirements.filter((r) => !r.origin?.editable);
  const own = requirements.filter((r) => r.origin?.editable);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!draft || !currentClubId) return;

    setSaving(true);
    setError(null);
    try {
      const body: SaveRequirementBody = {
        name: draft.name.trim(),
        description: draft.description.trim() || null,
        kind: draft.kind,
        proof: draft.proof,
        // Only an external-link requirement has a URL. Sending one for the
        // other two proof types would leave a stale link behind after somebody
        // changed their mind about how the requirement is proved.
        proof_url: draft.proof === 'external_link' ? draft.proof_url.trim() || null : null,
        // '' and 0 both mean "never expires"; the backend normalises either.
        validity_days: draft.validity_days.trim() === '' ? null : Number(draft.validity_days),
        roles: draft.roles,
        required: draft.required,
        active: draft.active,
      };
      if (draft.id > 0) {
        body.id = draft.id;
      } else {
        // Only ever sent on a CREATE. On an update the gateway takes the owner
        // from the stored row, so a club cannot re-home somebody else's rule.
        body.club_profile_id = currentClubId;
      }
      await saveRequirement(body);
      setDraft(null);
      await load();
    } catch (err: any) {
      setError(err?.message || 'Could not save the requirement');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (requirement: ComplianceRequirement) => {
    if (!window.confirm(`Remove "${requirement.name}"? Anyone who has already completed it keeps their record.`)) {
      return;
    }
    setError(null);
    try {
      await deleteRequirement(requirement.id);
      await load();
    } catch (err: any) {
      setError(err?.message || 'Could not remove the requirement');
    }
  };

  const toggleRole = (role: string) => {
    setDraft((current) =>
      current
        ? {
            ...current,
            roles: current.roles.includes(role)
              ? current.roles.filter((r) => r !== role)
              : [...current.roles, role],
          }
        : current
    );
  };

  if (!currentClubId) {
    return (
      <div className="container mx-auto p-6">
        <p className="text-gray-600">Choose a club to manage its requirements.</p>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">Requirements</h1>
          <p className="text-gray-600 mt-1">
            What your staff and volunteers have to have on file, and how long each one lasts.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link to="/compliance" className="text-sm text-brand-primary underline">
            Compliance dashboard
          </Link>
          <button
            type="button"
            onClick={() => setDraft({ ...EMPTY_DRAFT })}
            className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold uppercase text-white hover:opacity-90"
          >
            Add requirement
          </button>
        </div>
      </div>

      {!available && (
        <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
          Compliance is not switched on for this database yet. Nothing here has been saved or lost —
          the tables arrive with the next database update.
        </div>
      )}

      {error && (
        <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{error}</div>
      )}

      {loading ? (
        <p className="text-gray-500">Loading…</p>
      ) : (
        <>
          {/* Inherited first, and read-only. See the note at the top of this file. */}
          {inherited.length > 0 && (
            <section className="mb-8">
              <h2 className="mb-1 text-sm font-semibold uppercase tracking-wide text-gray-500">
                Required by your organisation
              </h2>
              <p className="mb-3 text-sm text-gray-500">
                These apply to your club and are managed by the tier that set them. You cannot edit
                them here — and you do not need to add your own copy.
              </p>
              <ul className="space-y-2">
                {inherited.map((requirement) => (
                  <li
                    key={requirement.id}
                    className="rounded-lg border border-gray-200 bg-gray-50 p-4"
                    data-testid={`inherited-${requirement.id}`}
                  >
                    <RequirementSummary requirement={requirement} />
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section>
            <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
              Your club&apos;s own
            </h2>
            {own.length === 0 ? (
              <p className="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                Your club has not added any of its own requirements yet.
              </p>
            ) : (
              <ul className="space-y-2">
                {own.map((requirement) => (
                  <li
                    key={requirement.id}
                    className="rounded-lg border border-gray-200 bg-white p-4"
                    data-testid={`own-${requirement.id}`}
                  >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <RequirementSummary requirement={requirement} />
                      <div className="flex gap-3">
                        <button
                          type="button"
                          onClick={() => setDraft(draftFrom(requirement))}
                          className="text-sm text-brand-primary underline"
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          onClick={() => remove(requirement)}
                          className="text-sm text-red-700 underline"
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}

      {draft && vocabulary && (
        <form
          onSubmit={submit}
          className="mt-8 rounded-lg border border-brand-secondary bg-white p-5"
          aria-label="Requirement"
        >
          <h2 className="mb-4 text-lg font-semibold text-brand-primary">
            {draft.id > 0 ? 'Edit requirement' : 'New requirement'}
          </h2>

          <label className="mb-4 block">
            <span className="mb-1 block text-sm font-medium text-gray-700">Name</span>
            <input
              type="text"
              required
              value={draft.name}
              onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              className="w-full rounded-md border border-gray-300 px-3 py-2"
            />
          </label>

          <label className="mb-4 block">
            <span className="mb-1 block text-sm font-medium text-gray-700">Description</span>
            <textarea
              rows={2}
              value={draft.description}
              onChange={(e) => setDraft({ ...draft, description: e.target.value })}
              className="w-full rounded-md border border-gray-300 px-3 py-2"
            />
          </label>

          <div className="mb-4 grid gap-4 sm:grid-cols-2">
            <label className="block">
              <span className="mb-1 block text-sm font-medium text-gray-700">Category</span>
              <select
                value={draft.kind}
                onChange={(e) => setDraft({ ...draft, kind: e.target.value })}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              >
                {vocabulary.kinds.map((kind) => (
                  <option key={kind} value={kind}>
                    {KIND_LABELS[kind] || kind}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1 block text-sm font-medium text-gray-700">What counts as proof</span>
              <select
                value={draft.proof}
                onChange={(e) => setDraft({ ...draft, proof: e.target.value as ProofType })}
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              >
                {vocabulary.proofs.map((proof) => (
                  <option key={proof} value={proof}>
                    {PROOF_LABELS[proof] || proof}
                  </option>
                ))}
              </select>
            </label>
          </div>

          {draft.proof === 'document' && (
            <p
              className="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
              data-testid="document-storage-note"
            >
              Uploads arrive with durable storage. You can define this requirement now and record
              completions for it yourself; the coach-side upload stays switched off until files are
              stored somewhere that survives a restart.
            </p>
          )}

          {draft.proof === 'external_link' && (
            <label className="mb-4 block">
              <span className="mb-1 block text-sm font-medium text-gray-700">Where they complete it</span>
              <input
                type="url"
                value={draft.proof_url}
                onChange={(e) => setDraft({ ...draft, proof_url: e.target.value })}
                placeholder="https://…"
                className="w-full rounded-md border border-gray-300 px-3 py-2"
              />
            </label>
          )}

          <label className="mb-4 block">
            <span className="mb-1 block text-sm font-medium text-gray-700">Valid for (days)</span>
            <input
              type="number"
              min={0}
              value={draft.validity_days}
              onChange={(e) => setDraft({ ...draft, validity_days: e.target.value })}
              className="w-full rounded-md border border-gray-300 px-3 py-2 sm:w-48"
            />
            <span className="mt-1 block text-xs text-gray-500">
              Leave blank if it never expires. Otherwise the expiry date is worked out from the
              completion date.
            </span>
          </label>

          <fieldset className="mb-4">
            <legend className="mb-1 text-sm font-medium text-gray-700">Who it applies to</legend>
            <p className="mb-2 text-xs text-gray-500">
              Select none and it applies to everyone with a staff or volunteer role.
            </p>
            <div className="flex flex-wrap gap-2">
              {vocabulary.roles.map((role) => (
                <button
                  key={role}
                  type="button"
                  aria-pressed={draft.roles.includes(role)}
                  onClick={() => toggleRole(role)}
                  className={`rounded-full border px-3 py-1.5 text-sm ${
                    draft.roles.includes(role)
                      ? 'border-brand-primary bg-brand-primary text-white'
                      : 'border-gray-300 bg-white text-gray-700'
                  }`}
                >
                  {ROLE_LABELS[role] || role}
                </button>
              ))}
            </div>
          </fieldset>

          <div className="mb-5 flex flex-wrap gap-6">
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                checked={draft.required}
                onChange={(e) => setDraft({ ...draft, required: e.target.checked })}
              />
              Required — someone without it is not compliant
            </label>
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                checked={draft.active}
                onChange={(e) => setDraft({ ...draft, active: e.target.checked })}
              />
              Active
            </label>
          </div>

          <div className="flex gap-3">
            <button
              type="submit"
              disabled={saving}
              className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold uppercase text-white hover:opacity-90 disabled:opacity-50"
            >
              {saving ? 'Saving…' : 'Save'}
            </button>
            <button
              type="button"
              onClick={() => setDraft(null)}
              className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold uppercase text-gray-700"
            >
              Cancel
            </button>
          </div>
        </form>
      )}
    </div>
  );
};

const RequirementSummary: React.FC<{ requirement: ComplianceRequirement }> = ({ requirement }) => (
  <div>
    <div className="flex flex-wrap items-center gap-2">
      <span className="font-semibold text-brand-primary">{requirement.name}</span>
      {requirement.origin && (
        <span className="rounded-full bg-white px-2 py-0.5 text-xs text-gray-600 ring-1 ring-gray-200">
          {requirement.origin.label}
        </span>
      )}
      {!requirement.required && (
        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">Optional</span>
      )}
      {!requirement.active && (
        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">Inactive</span>
      )}
    </div>
    {requirement.description && (
      <p className="mt-1 text-sm text-gray-600">{requirement.description}</p>
    )}
    <p className="mt-1 text-xs text-gray-500">
      {PROOF_LABELS[requirement.proof] || requirement.proof}
      {' · '}
      {requirement.validity_days ? `valid ${requirement.validity_days} days` : 'never expires'}
      {' · '}
      {/* An empty role list is EVERYONE, not nobody. Rendering it as a blank
          would read as "applies to no one", which is the opposite. */}
      {requirement.roles.length === 0
        ? 'everyone'
        : requirement.roles.map((r) => ROLE_LABELS[r] || r).join(', ')}
    </p>
  </div>
);

export default ComplianceRequirements;

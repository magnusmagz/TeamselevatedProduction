import React from 'react';
import {
  fetchStreamForRequirement,
  previewStep,
  saveStream,
  setStreamActive,
  StreamApiError,
  type ReminderStream,
  type StreamDescription,
} from './streamsApi';

/**
 * The "Reminder stream" panel on one requirement (GOTR G7).
 *
 * Shows which stream applies at THIS tier — the tier's own, one inherited from
 * a unit above, or the default 90/60/30/7 — and lets the tier's admin write
 * its own: an ordered list of steps (days from expiry, subject, body), the
 * allowed merge tags as chips, a preview rendered by the server for the
 * signed-in admin, and an on/off switch.
 *
 * ⚠️ EXACTLY ONE STREAM APPLIES, and steps are never merged across tiers. The
 * panel says which one is live in one line, because "what will this coach
 * actually receive" has to be answerable from this screen.
 *
 * ⚠️ SWITCHING A STREAM OFF FALLS BACK TO THE NEXT TIER, never to silence. The
 * copy says so — an admin who reads "off" as "no reminders" would be wrong.
 *
 * The tag list comes from the server with the description, so the chips
 * cannot drift from what lib/compliance_streams.php accepts. A 422 for an
 * unknown tag names the tag and is shown next to the steps.
 */

interface Props {
  requirementId: number;
  requirementName: string;
  tier: { club_id: number } | { org_unit_id: number };
}

interface DraftStep {
  days_before: string;
  subject: string;
  body: string;
}

const TAG_HELP: Record<string, string> = {
  first_name: "the person's first name",
  requirement_name: 'the requirement',
  expires_on: 'the expiry date',
  days_left: 'whole days to (or since) expiry',
  club_name: 'the club',
  renewal_url: 'where to renew',
};

function draftFrom(stream: ReminderStream | null): DraftStep[] {
  if (!stream || stream.steps.length === 0) {
    return [{ days_before: '30', subject: '', body: '' }];
  }
  return stream.steps.map((s) => ({ days_before: String(s.days_before), subject: s.subject, body: s.body }));
}

function offsetLabel(days: number): string {
  if (days === 0) return 'On the expiry date';
  if (days > 0) return `${days} day${days === 1 ? '' : 's'} before expiry`;
  return `${-days} day${days === -1 ? '' : 's'} after expiry`;
}

export const ReminderStreamPanel: React.FC<Props> = ({ requirementId, requirementName, tier }) => {
  const clubId = 'club_id' in tier ? tier.club_id : 0;
  const orgUnitId = 'org_unit_id' in tier ? tier.org_unit_id : 0;

  const [open, setOpen] = React.useState(false);
  const [description, setDescription] = React.useState<StreamDescription | null>(null);
  const [loading, setLoading] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [editing, setEditing] = React.useState(false);
  const [draft, setDraft] = React.useState<DraftStep[]>([]);
  const [saving, setSaving] = React.useState(false);
  const [preview, setPreview] = React.useState<{ index: number; subject: string; body: string } | null>(null);
  const [previewing, setPreviewing] = React.useState(false);

  const load = React.useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const body = await fetchStreamForRequirement(requirementId, clubId > 0 ? { club_id: clubId } : { org_unit_id: orgUnitId });
      setDescription(body);
    } catch (err: any) {
      setError(err?.message || 'Could not load the reminder stream');
    } finally {
      setLoading(false);
    }
  }, [requirementId, clubId, orgUnitId]);

  React.useEffect(() => {
    if (open && !description) {
      load();
    }
  }, [open, description, load]);

  const startEditing = () => {
    setDraft(draftFrom(description?.own ?? null));
    setPreview(null);
    setError(null);
    setEditing(true);
  };

  const updateStep = (index: number, patch: Partial<DraftStep>) => {
    setDraft((current) => current.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  };

  const addStep = () => setDraft((current) => [...current, { days_before: '', subject: '', body: '' }]);
  const removeStep = (index: number) => setDraft((current) => current.filter((_, i) => i !== index));

  /** The same checks the server makes, so the obvious mistakes never leave the browser. */
  const clientProblem = (): string | null => {
    if (draft.length === 0) return 'A stream needs at least one step.';
    const seen = new Set<number>();
    for (let i = 0; i < draft.length; i++) {
      const s = draft[i];
      const n = i + 1;
      if (s.days_before.trim() === '' || !/^-?\d+$/.test(s.days_before.trim())) return `Step ${n} needs a whole number of days.`;
      const d = Number(s.days_before);
      if (seen.has(d)) return `Two steps are both ${d} days from expiry.`;
      seen.add(d);
      if (s.subject.trim() === '') return `Step ${n} has no subject.`;
      if (s.body.trim() === '') return `Step ${n} has no body.`;
      const allowed = description?.tags || [];
      const tags = Array.from((s.subject + ' ' + s.body).matchAll(/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g)).map((m) => m[1]);
      const unknown = tags.filter((t) => !allowed.includes(t));
      if (unknown.length) return `Step ${n} uses an unknown merge tag: ${unknown.map((t) => `{{${t}}}`).join(', ')}.`;
    }
    return null;
  };

  const save = async (activate: boolean | null) => {
    const problem = clientProblem();
    if (problem) {
      setError(problem);
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const body: Parameters<typeof saveStream>[0] = {
        requirement_id: requirementId,
        steps: draft.map((s) => ({
          days_before: Number(s.days_before),
          subject: s.subject.trim(),
          body: s.body.trim(),
          channel: 'email' as const,
        })),
      };
      if (description?.own) {
        body.id = description.own.id;
      } else if (clubId > 0) {
        body.club_profile_id = clubId;
      } else {
        body.org_unit_id = orgUnitId;
      }
      if (activate !== null) body.active = activate;
      await saveStream(body);
      setEditing(false);
      setDescription(null); // reload from the server, which decides what applies
    } catch (err: any) {
      if (err instanceof StreamApiError && err.unknownTags.length) {
        setError(`${err.message}`);
      } else {
        setError(err?.message || 'Could not save the stream');
      }
    } finally {
      setSaving(false);
    }
  };

  const toggle = async (active: boolean) => {
    if (!description?.own) return;
    setSaving(true);
    setError(null);
    try {
      await setStreamActive(description.own.id, active);
      setDescription(null);
    } catch (err: any) {
      setError(err?.message || 'Could not update the stream');
    } finally {
      setSaving(false);
    }
  };

  const runPreview = async (index: number) => {
    const s = draft[index];
    setPreviewing(true);
    setError(null);
    try {
      const body = await previewStep({
        days_before: /^-?\d+$/.test(s.days_before.trim()) ? Number(s.days_before) : 30,
        subject: s.subject,
        body: s.body,
        club_id: clubId > 0 ? clubId : undefined,
        requirement_name: requirementName,
      });
      setPreview({ index, subject: body.subject, body: body.body });
    } catch (err: any) {
      setError(err?.message || 'Could not render the preview');
    } finally {
      setPreviewing(false);
    }
  };

  const applies = description?.applies;
  const own = description?.own ?? null;
  const live = description?.stream ?? null;
  const defaults = description?.default_thresholds || [90, 60, 30, 7];

  let statusLine = '';
  if (applies === 'own') statusLine = 'Using this tier’s own stream.';
  else if (applies === 'inherited' && description?.inherited_from) {
    statusLine = `Inherited from ${description.inherited_from.name} (${description.inherited_from.type}).`;
  } else if (applies === 'default') {
    statusLine = `Using the default cadence: ${defaults.join(', ')} days before expiry.`;
  }

  return (
    <div className="mt-3 rounded-md border border-gray-200 bg-gray-50" data-testid={`stream-panel-${requirementId}`}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium text-gray-700"
      >
        <span>Reminder stream</span>
        <span className="text-xs text-gray-500">{open ? 'Hide' : 'Show'}</span>
      </button>

      {open && (
        <div className="border-t border-gray-200 px-3 py-3 text-sm">
          {loading && <p className="text-gray-500">Loading…</p>}
          {error && (
            <p role="alert" className="mb-2 rounded-md border border-red-200 bg-red-50 p-2 text-red-800">
              {error}
            </p>
          )}

          {description && description.available === false && (
            <p className="text-blue-900">Compliance is not switched on for this database yet.</p>
          )}

          {description && description.available !== false && !editing && (
            <>
              <p className="text-gray-700" data-testid={`stream-status-${requirementId}`}>
                {statusLine}
              </p>
              {live && (
                <ul className="mt-2 space-y-1 text-xs text-gray-600">
                  {live.steps.map((s) => (
                    <li key={s.days_before}>
                      <span className="font-medium text-gray-700">{offsetLabel(s.days_before)}</span>
                      {' — '}
                      {s.subject}
                    </li>
                  ))}
                </ul>
              )}
              {own && !own.active && applies !== 'own' && (
                <p className="mt-2 text-xs text-gray-500">
                  This tier has a stream of its own that is switched off. Switching it on replaces the one above.
                </p>
              )}
              <div className="mt-3 flex flex-wrap gap-3">
                <button type="button" onClick={startEditing} className="text-brand-primary underline">
                  {own ? 'Edit steps' : 'Write a stream for this tier'}
                </button>
                {own && own.active && (
                  <button type="button" disabled={saving} onClick={() => toggle(false)} className="text-gray-700 underline">
                    Switch off
                  </button>
                )}
                {own && !own.active && own.steps.length > 0 && (
                  <button type="button" disabled={saving} onClick={() => toggle(true)} className="text-brand-primary underline">
                    Switch on
                  </button>
                )}
              </div>
              {own && own.active && (
                <p className="mt-2 text-xs text-gray-500">
                  Switching this off falls back to the tier above, or to the default cadence — never to no reminders.
                </p>
              )}
            </>
          )}

          {editing && (
            <form
              aria-label={`Reminder stream for ${requirementName}`}
              onSubmit={(e) => {
                e.preventDefault();
                save(null);
              }}
            >
              <div className="mb-3">
                <p className="mb-1 text-xs font-medium text-gray-700">Merge tags you can use</p>
                <div className="flex flex-wrap gap-1">
                  {(description?.tags || []).map((tag) => (
                    <span
                      key={tag}
                      title={TAG_HELP[tag] || tag}
                      className="rounded-full bg-white px-2 py-0.5 font-mono text-xs text-gray-700 ring-1 ring-gray-300"
                    >
                      {`{{${tag}}}`}
                    </span>
                  ))}
                </div>
              </div>

              <ol className="space-y-3">
                {draft.map((step, index) => (
                  <li key={index} className="rounded-md border border-gray-200 bg-white p-3" data-testid={`stream-step-${index}`}>
                    <div className="mb-2 flex flex-wrap items-end gap-3">
                      <label className="block">
                        <span className="mb-1 block text-xs font-medium text-gray-700">Days before expiry</span>
                        <input
                          type="number"
                          aria-label={`Step ${index + 1} days before expiry`}
                          value={step.days_before}
                          onChange={(e) => updateStep(index, { days_before: e.target.value })}
                          className="w-28 rounded-md border border-gray-300 px-2 py-1"
                        />
                      </label>
                      <span className="pb-1 text-xs text-gray-500">
                        {/^-?\d+$/.test(step.days_before.trim()) ? offsetLabel(Number(step.days_before)) : 'Negative = after expiry'}
                      </span>
                      <div className="ml-auto flex gap-3 pb-1">
                        <button
                          type="button"
                          disabled={previewing}
                          onClick={() => runPreview(index)}
                          className="text-xs text-brand-primary underline"
                        >
                          Preview
                        </button>
                        {draft.length > 1 && (
                          <button type="button" onClick={() => removeStep(index)} className="text-xs text-red-700 underline">
                            Remove
                          </button>
                        )}
                      </div>
                    </div>
                    <label className="mb-2 block">
                      <span className="mb-1 block text-xs font-medium text-gray-700">Subject</span>
                      <input
                        type="text"
                        aria-label={`Step ${index + 1} subject`}
                        value={step.subject}
                        onChange={(e) => updateStep(index, { subject: e.target.value })}
                        className="w-full rounded-md border border-gray-300 px-2 py-1"
                      />
                    </label>
                    <label className="block">
                      <span className="mb-1 block text-xs font-medium text-gray-700">Body</span>
                      <textarea
                        rows={4}
                        aria-label={`Step ${index + 1} body`}
                        value={step.body}
                        onChange={(e) => updateStep(index, { body: e.target.value })}
                        className="w-full rounded-md border border-gray-300 px-2 py-1"
                      />
                    </label>
                    {preview && preview.index === index && (
                      <div className="mt-2 rounded-md border border-brand-secondary bg-gray-50 p-2" data-testid={`stream-preview-${index}`}>
                        <p className="text-xs font-medium text-gray-500">Preview, as you would receive it</p>
                        <p className="mt-1 font-medium text-gray-800">{preview.subject}</p>
                        <p className="mt-1 whitespace-pre-wrap text-gray-700">{preview.body}</p>
                      </div>
                    )}
                  </li>
                ))}
              </ol>

              <div className="mt-3 flex flex-wrap items-center gap-3">
                <button type="button" onClick={addStep} className="text-xs text-brand-primary underline">
                  Add a step
                </button>
                <span className="flex-1" />
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-md bg-brand-primary px-3 py-1.5 text-xs font-semibold uppercase text-white disabled:opacity-50"
                >
                  {saving ? 'Saving…' : 'Save'}
                </button>
                {(!own || !own.active) && (
                  <button
                    type="button"
                    disabled={saving}
                    onClick={() => save(true)}
                    className="rounded-md border border-brand-primary px-3 py-1.5 text-xs font-semibold uppercase text-brand-primary disabled:opacity-50"
                  >
                    Save and switch on
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => {
                    setEditing(false);
                    setPreview(null);
                    setError(null);
                  }}
                  className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold uppercase text-gray-700"
                >
                  Cancel
                </button>
              </div>
            </form>
          )}
        </div>
      )}
    </div>
  );
};

export default ReminderStreamPanel;

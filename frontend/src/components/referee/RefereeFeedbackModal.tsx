import React, { useCallback, useEffect, useState } from 'react';
import { REFEREE_FEEDBACK_CATEGORIES } from '../../constants/refereeFeedbackCategories';
import { formatDateOnly } from '../../utils/dateFormat';
import { RefereeFeedbackRow } from './types';
import Button from '../ui/Button';

/**
 * Record or edit a coach's feedback about the referee(s) of a game (CKU R68).
 *
 * The modal asks the server first (`action=event`) and renders what it is
 * told: whether this game can be rated at all (a future game or a practice
 * cannot), which of the caller's teams are on it, and the rows the caller has
 * already recorded. Everything it decides, the server decides again — a coach
 * with no team on the game gets a 403 here and a sentence, not an empty form.
 *
 * One row per referee per coach per game. A second referee (assistant, centre)
 * is a second row; a second opinion of the same referee is an edit.
 */

interface Props {
  eventId: number;
  apiUrl: string;
  onClose: () => void;
  onSaved: () => void;
}

interface EventInfo {
  id: number;
  name: string;
  event_date: string;
  opponent_name: string | null;
}

interface FormState {
  id: number | null;
  team_id: number | '';
  referee_name: string;
  rating: number | null;
  categories: string[];
  comments: string;
  incident: boolean;
}

const emptyForm = (teamId: number | ''): FormState => ({
  id: null,
  team_id: teamId,
  referee_name: '',
  rating: null,
  categories: [],
  comments: '',
  incident: false,
});

const RefereeFeedbackModal: React.FC<Props> = ({ eventId, apiUrl, onClose, onSaved }) => {
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [event, setEvent] = useState<EventInfo | null>(null);
  const [canSubmit, setCanSubmit] = useState(false);
  const [reason, setReason] = useState<string | null>(null);
  const [teams, setTeams] = useState<Array<{ id: number; name: string }>>([]);
  const [existing, setExisting] = useState<RefereeFeedbackRow[]>([]);
  const [form, setForm] = useState<FormState | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  const token = localStorage.getItem('auth_token');

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const res = await fetch(`${apiUrl}/api/referee-feedback.php?action=event&event_id=${eventId}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const data = await res.json();
      if (!res.ok || !data?.success) {
        setLoadError(data?.error || `Could not load this game (${res.status})`);
        return;
      }
      setEvent(data.event);
      setCanSubmit(Boolean(data.can_submit));
      setReason(data.reason ?? null);
      const teamList: Array<{ id: number; name: string }> = Array.isArray(data.teams) ? data.teams : [];
      const mine: RefereeFeedbackRow[] = Array.isArray(data.feedback) ? data.feedback : [];
      setTeams(teamList);
      setExisting(mine);
      // When the game can be rated and nothing is recorded yet, open straight
      // on the blank form: that is the common case and the button already said
      // what this modal is for.
      if (data.can_submit && mine.length === 0) {
        setForm(emptyForm(teamList.length === 1 ? teamList[0].id : ''));
      }
    } catch (err: any) {
      setLoadError(err?.message || 'Could not load this game');
    } finally {
      setLoading(false);
    }
  }, [apiUrl, eventId, token]);

  useEffect(() => {
    load();
  }, [load]);

  const startNew = () => {
    setSaveError(null);
    setForm(emptyForm(teams.length === 1 ? teams[0].id : ''));
  };

  const startEdit = (row: RefereeFeedbackRow) => {
    setSaveError(null);
    setForm({
      id: row.id,
      team_id: row.team_id,
      referee_name: row.referee_name,
      rating: row.rating,
      categories: row.categories,
      comments: row.comments ?? '',
      incident: row.incident,
    });
  };

  const toggleCategory = (value: string) => {
    if (!form) return;
    setForm({
      ...form,
      categories: form.categories.includes(value)
        ? form.categories.filter((c) => c !== value)
        : [...form.categories, value],
    });
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form) return;
    setSaveError(null);

    const name = form.referee_name.trim();
    if (!name) {
      setSaveError('Enter the referee\'s name.');
      return;
    }
    if (form.rating === null) {
      setSaveError('Pick a rating from 1 to 5.');
      return;
    }
    if (form.id === null && teams.length > 1 && form.team_id === '') {
      setSaveError('Say which of your teams this feedback is for.');
      return;
    }

    // Categories go in the canonical order so the stored row and the export
    // column do not depend on the order they were ticked.
    const categories = REFEREE_FEEDBACK_CATEGORIES.map((c) => c.value).filter((v) => form.categories.includes(v));

    const shared = {
      referee_name: name,
      rating: form.rating,
      categories,
      comments: form.comments.trim(),
      incident: form.incident,
    };
    const isEdit = form.id !== null;
    const body = isEdit ? { id: form.id, ...shared } : { event_id: eventId, team_id: form.team_id, ...shared };

    setSaving(true);
    try {
      const res = await fetch(`${apiUrl}/api/referee-feedback.php?action=${isEdit ? 'update' : 'create'}`, {
        method: isEdit ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok || !data?.success) {
        setSaveError(data?.error || `Could not save (${res.status})`);
        return;
      }
      setForm(null);
      onSaved();
      await load();
    } catch (err: any) {
      setSaveError(err?.message || 'Could not save');
    } finally {
      setSaving(false);
    }
  };

  const heading = event
    ? `${event.name}${event.opponent_name ? ` vs ${event.opponent_name}` : ''} — ${formatDateOnly(event.event_date)}`
    : 'Referee feedback';

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div className="flex items-start justify-between p-4 border-b border-gray-200">
          <div>
            <h2 className="text-lg font-semibold text-brand-primary">Referee feedback</h2>
            <p className="text-sm text-gray-600">{heading}</p>
          </div>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </Button>
        </div>

        <div className="p-4 space-y-4">
          {loading && <p className="text-sm text-gray-500">Loading…</p>}

          {!loading && loadError && (
            <div className="bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800">{loadError}</div>
          )}

          {!loading && !loadError && !canSubmit && reason && (
            <div className="bg-amber-50 border border-amber-200 rounded-md p-3 text-sm text-amber-900">{reason}</div>
          )}

          {!loading && !loadError && existing.length > 0 && (
            <div>
              <h3 className="text-sm font-semibold text-gray-700 mb-2">Your feedback on this game</h3>
              <ul className="divide-y divide-gray-100 border border-gray-200 rounded-md">
                {existing.map((row) => (
                  <li key={row.id} className="p-3 flex items-start justify-between gap-3">
                    <div className="text-sm">
                      <div className="font-medium text-gray-900">
                        {row.referee_name}
                        <span className="ml-2 text-gray-500">{row.rating}/5</span>
                        {row.incident && (
                          <span className="ml-2 inline-block px-2 py-0.5 text-xs rounded bg-red-100 text-red-800">Incident</span>
                        )}
                      </div>
                      {row.categories.length > 0 && (
                        <div className="text-xs text-gray-500">
                          {row.categories.map((c) => REFEREE_FEEDBACK_CATEGORIES.find((k) => k.value === c)?.label ?? c).join(', ')}
                        </div>
                      )}
                      {row.comments && <div className="text-xs text-gray-600 mt-1">{row.comments}</div>}
                    </div>
                    {canSubmit && (
                      <Button variant="link" onClick={() => startEdit(row)} className="shrink-0">
                        Edit
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
              {canSubmit && form === null && (
                <Button variant="link" onClick={startNew} className="mt-2">
                  + Another referee
                </Button>
              )}
            </div>
          )}

          {!loading && !loadError && canSubmit && form && (
            <form onSubmit={submit} className="space-y-4">
              {form.id === null && teams.length > 1 && (
                <div>
                  <label htmlFor="ref-team" className="block text-sm font-medium text-gray-700">Your team</label>
                  <select
                    id="ref-team"
                    value={form.team_id}
                    onChange={(e) => setForm({ ...form, team_id: e.target.value === '' ? '' : Number(e.target.value) })}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                  >
                    <option value="">Choose a team…</option>
                    {teams.map((t) => (
                      <option key={t.id} value={t.id}>{t.name}</option>
                    ))}
                  </select>
                </div>
              )}

              <div>
                <label htmlFor="ref-name" className="block text-sm font-medium text-gray-700">Referee name</label>
                <input
                  id="ref-name"
                  type="text"
                  value={form.referee_name}
                  onChange={(e) => setForm({ ...form, referee_name: e.target.value })}
                  maxLength={120}
                  placeholder="As shown on the game card"
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
              </div>

              <fieldset>
                <legend className="block text-sm font-medium text-gray-700">Rating</legend>
                <div className="mt-1 flex gap-2" role="radiogroup">
                  {[1, 2, 3, 4, 5].map((n) => (
                    <label
                      key={n}
                      className={`flex-1 text-center py-2 border rounded-md cursor-pointer text-sm ${
                        form.rating === n ? 'bg-brand-primary text-white border-brand-primary' : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                      }`}
                    >
                      <input
                        type="radio"
                        name="ref-rating"
                        value={n}
                        checked={form.rating === n}
                        onChange={() => setForm({ ...form, rating: n })}
                        className="sr-only"
                        aria-label={String(n)}
                      />
                      {n}
                    </label>
                  ))}
                </div>
                <p className="text-xs text-gray-500 mt-1">1 = poor, 5 = excellent</p>
              </fieldset>

              <div>
                <span className="block text-sm font-medium text-gray-700">What stood out</span>
                <div className="mt-1 flex flex-wrap gap-2">
                  {REFEREE_FEEDBACK_CATEGORIES.map((c) => {
                    const on = form.categories.includes(c.value);
                    return (
                      <button
                        key={c.value}
                        type="button"
                        aria-pressed={on}
                        title={c.hint}
                        onClick={() => toggleCategory(c.value)}
                        className={`px-3 py-1 rounded-full text-sm border ${
                          on ? 'bg-brand-primary text-white border-brand-primary' : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                        }`}
                      >
                        {c.label}
                      </button>
                    );
                  })}
                </div>
              </div>

              <div>
                <label htmlFor="ref-comments" className="block text-sm font-medium text-gray-700">Comments</label>
                <textarea
                  id="ref-comments"
                  value={form.comments}
                  onChange={(e) => setForm({ ...form, comments: e.target.value })}
                  rows={3}
                  maxLength={4000}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                />
              </div>

              <label className="flex items-start gap-2 text-sm text-gray-800">
                <input
                  type="checkbox"
                  checked={form.incident}
                  onChange={(e) => setForm({ ...form, incident: e.target.checked })}
                  className="mt-0.5"
                />
                <span>
                  <span className="font-medium">Flag as incident</span>
                  <span className="block text-xs text-gray-500">Marks this for your club admin's attention.</span>
                </span>
              </label>

              {saveError && (
                <div className="bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800">{saveError}</div>
              )}

              <div className="flex justify-end gap-2 pt-2 border-t border-gray-200">
                <Button variant="secondary" onClick={() => (existing.length > 0 ? setForm(null) : onClose())}>
                  Cancel
                </Button>
                <Button type="submit" loading={saving}>
                  Save feedback
                </Button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  );
};

export default RefereeFeedbackModal;

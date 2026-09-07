import React, { useCallback, useEffect, useState } from 'react';
import {
  CriterionScoreList,
  ScoringCriterion,
  computeWeightedOverall,
} from './ScoringForm';
import { AthleteEvaluation, EvaluationCriterionOption, IdpGoal } from './types';
import Button from '../ui/Button';

/**
 * Record or edit a mid-year evaluation, and its IDP goals (CKU R76/R77).
 *
 * The scoring half is the SAME component the tryout sheet uses
 * (ScoringForm.tsx), so a coach who has evaluated at tryouts is looking at the
 * instrument they already know and the two cannot drift apart.
 *
 * Scores here are keyed by criterion NAME rather than by criterion id, which is
 * the whole point of migration 086: the name, max_score and weight are copied
 * onto the record, so a club renaming or deleting a tryout criterion next season
 * cannot change what this evaluation said.
 */

const MAX_GOALS = 5;
const SUGGESTED_GOALS = 3;

interface AthleteEvaluationModalProps {
  athleteId: number;
  athleteName: string;
  /** Teams the athlete is on, for the optional "which team" attribution. */
  teams?: Array<{ id: number; name: string }>;
  /** Present when editing; absent when recording a new one. */
  existing?: AthleteEvaluation | null;
  apiUrl: string;
  onClose: () => void;
  onSaved: () => void;
}

/** Today as YYYY-MM-DD in the viewer's own timezone.
 *
 * Built from the LOCAL date parts, never `toISOString().split('T')[0]` — that
 * writes the UTC day, which is the previous day all evening in Central and is
 * exactly the bug that put Schedule Practices on Wednesdays. */
function todayDateOnly(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** A default season label from the date, rolling on Aug 1 like the rest of the
 *  frontend (see utils/ageGroup.ts). Read off the STRING, never a Date. */
function defaultSeasonLabel(dateOnly: string): string {
  const year = parseInt(dateOnly.slice(0, 4), 10);
  const month = parseInt(dateOnly.slice(5, 7), 10);
  const start = month >= 8 ? year : year - 1;
  return `${start}-${String((start + 1) % 100).padStart(2, '0')}`;
}

export const AthleteEvaluationModal: React.FC<AthleteEvaluationModalProps> = ({
  athleteId,
  athleteName,
  teams = [],
  existing,
  apiUrl,
  onClose,
  onSaved,
}) => {
  const [criteria, setCriteria] = useState<EvaluationCriterionOption[]>([]);
  const [criteriaSource, setCriteriaSource] = useState<string>('club');
  const [scores, setScores] = useState<Record<string, number>>({});
  const [comments, setComments] = useState<Record<string, string>>({});
  const [notes, setNotes] = useState(existing?.notes ?? '');
  const [evaluatedAt, setEvaluatedAt] = useState(existing?.evaluated_at ?? todayDateOnly());
  const [seasonLabel, setSeasonLabel] = useState(
    existing?.season_label ?? defaultSeasonLabel(todayDateOnly()),
  );
  const [teamId, setTeamId] = useState<string>(
    existing?.team_id != null ? String(existing.team_id) : teams.length === 1 ? String(teams[0].id) : '',
  );
  const [goals, setGoals] = useState<IdpGoal[]>(
    existing?.idp_goals?.length ? existing.idp_goals : [{ goal: '', target_date: null }],
  );
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const token = localStorage.getItem('auth_token');

  const loadCriteria = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(
        `${apiUrl}/api/athlete-evaluations.php?action=criteria&athlete_id=${athleteId}`,
        { headers: { Authorization: `Bearer ${token}` } },
      );
      const data = await res.json();
      if (!res.ok || !data.success) {
        setError(data.error || 'Could not load the evaluation criteria.');
        return;
      }
      const list: EvaluationCriterionOption[] = data.criteria || [];
      setCriteria(list);
      setCriteriaSource(data.source || 'club');

      // Editing: seed from what was actually recorded, matched by NAME. A
      // criterion that has since been removed from the club's list still shows
      // its old score, because the record carries its own name and max.
      if (existing) {
        const seededScores: Record<string, number> = {};
        const seededComments: Record<string, string> = {};
        existing.scores.forEach((s) => {
          if (s.score !== null) seededScores[s.criterion_name] = s.score;
          if (s.comment) seededComments[s.criterion_name] = s.comment;
        });
        setScores(seededScores);
        setComments(seededComments);
      }
    } catch {
      setError('Unable to reach the server.');
    } finally {
      setLoading(false);
    }
  }, [apiUrl, athleteId, token, existing]);

  useEffect(() => {
    loadCriteria();
  }, [loadCriteria]);

  // The recorded criteria come first so an evaluation can still be edited after
  // the club dropped one of its criteria; the club's current list fills in the
  // rest. Keyed by name, so a criterion in both appears once.
  const scoringCriteria: ScoringCriterion[] = React.useMemo(() => {
    const byName = new Map<string, ScoringCriterion>();
    (existing?.scores ?? []).forEach((s) => {
      byName.set(s.criterion_name, {
        key: s.criterion_name,
        name: s.criterion_name,
        description: null,
        max_score: s.max_score ?? 5,
        weight: s.weight ?? 1,
      });
    });
    criteria.forEach((c) => {
      if (!byName.has(c.name)) {
        byName.set(c.name, {
          key: c.name,
          name: c.name,
          description: c.description,
          max_score: c.max_score,
          weight: c.weight,
        });
      }
    });
    return Array.from(byName.values());
  }, [criteria, existing]);

  const updateGoal = (index: number, patch: Partial<IdpGoal>) => {
    setGoals((prev) => prev.map((g, i) => (i === index ? { ...g, ...patch } : g)));
  };

  const handleSubmit = async () => {
    setError(null);

    if (!seasonLabel.trim()) {
      setError('Give the evaluation a season label so it can be charted year over year.');
      return;
    }

    const filledGoals = goals.filter((g) => g.goal.trim() !== '');
    if (filledGoals.length > MAX_GOALS) {
      setError(`An IDP can hold at most ${MAX_GOALS} goals.`);
      return;
    }

    setSaving(true);
    try {
      const payload = {
        athlete_id: athleteId,
        id: existing?.id,
        team_id: teamId === '' ? null : parseInt(teamId, 10),
        evaluated_at: evaluatedAt,
        season_label: seasonLabel.trim(),
        notes: notes.trim(),
        // max_score and weight ride along with every score: they are copied onto
        // the record so it stays readable after the club edits its criteria.
        scores: scoringCriteria.map((c) => ({
          criterion_name: c.name,
          score: scores[c.key] ?? null,
          max_score: c.max_score,
          weight: c.weight,
          comment: comments[c.key] ?? null,
        })),
        idp_goals: filledGoals.map((g) => ({
          goal: g.goal.trim(),
          target_date: g.target_date || null,
        })),
      };

      const res = await fetch(
        `${apiUrl}/api/athlete-evaluations.php?action=${existing ? 'update' : 'create'}`,
        {
          method: existing ? 'PUT' : 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify(payload),
        },
      );
      const data = await res.json();

      if (res.ok && data.success) {
        onSaved();
      } else {
        // The 503 "not switched on yet" sentence arrives here verbatim, and the
        // coach needs to read it: their work was not saved.
        setError(data.error || 'Could not save the evaluation.');
      }
    } catch {
      setError('Unable to reach the server.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div className="bg-white border border-brand-secondary rounded-md p-8">
          <div className="text-brand-primary">Loading evaluation form...</div>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <div>
            <h2 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
              {existing ? 'Edit evaluation' : 'Mid-year evaluation'}
            </h2>
            <p className="text-gray-600 mt-1">{athleteName}</p>
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="text-2xl"
            onClick={onClose}
            aria-label="Close"
          >
            &times;
          </Button>
        </div>

        <div className="flex-1 overflow-y-auto p-6">
          {error && (
            <div className="mb-4 p-3 bg-red-50 border border-red-300 text-red-800 rounded-md text-sm">
              {error}
            </div>
          )}

          <div className="grid sm:grid-cols-3 gap-4 mb-6">
            <div>
              <label
                htmlFor="evaluation-date"
                className="block text-brand-primary text-sm font-medium mb-1 uppercase"
              >
                Date
              </label>
              <input
                id="evaluation-date"
                type="date"
                className="w-full border border-brand-secondary rounded-md px-3 py-2"
                value={evaluatedAt}
                onChange={(e) => setEvaluatedAt(e.target.value)}
              />
            </div>
            <div>
              <label
                htmlFor="evaluation-season"
                className="block text-brand-primary text-sm font-medium mb-1 uppercase"
              >
                Season
              </label>
              <input
                id="evaluation-season"
                type="text"
                className="w-full border border-brand-secondary rounded-md px-3 py-2"
                placeholder="2025-26"
                value={seasonLabel}
                onChange={(e) => setSeasonLabel(e.target.value)}
              />
            </div>
            <div>
              <label
                htmlFor="evaluation-team"
                className="block text-brand-primary text-sm font-medium mb-1 uppercase"
              >
                Team
              </label>
              <select
                id="evaluation-team"
                className="w-full border border-brand-secondary rounded-md px-3 py-2"
                value={teamId}
                onChange={(e) => setTeamId(e.target.value)}
              >
                <option value="">Not team specific</option>
                {teams.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="mb-6 p-4 bg-gray-50 rounded-md border border-brand-secondary">
            <div className="flex justify-between items-center">
              <span className="text-brand-primary font-medium">Overall Score Preview</span>
              <span className="text-2xl font-bold text-brand-primary">
                {computeWeightedOverall(scoringCriteria, scores)}
              </span>
            </div>
          </div>

          {criteriaSource === 'default' && (
            <p className="mb-4 text-sm text-gray-600">
              This club has not set up its own evaluation criteria, so the platform defaults are
              shown. Criteria defined on a tryout program will be used here instead.
            </p>
          )}

          <CriterionScoreList
            criteria={scoringCriteria}
            scores={scores}
            onScoreChange={(key, score) => setScores({ ...scores, [key]: score })}
            comments={comments}
            onCommentChange={(key, comment) => setComments({ ...comments, [key]: comment })}
          />

          <div className="mt-6">
            <label
              htmlFor="evaluation-notes"
              className="block text-brand-primary text-sm font-medium mb-2 uppercase"
            >
              Notes
            </label>
            <textarea
              id="evaluation-notes"
              className="w-full border border-brand-secondary rounded-md px-4 py-2"
              rows={3}
              placeholder="What this athlete is doing well, and where the season is heading..."
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
            />
          </div>

          {/* --- IDP --- */}
          <div className="mt-8">
            <div className="text-brand-primary text-sm font-medium mb-1 uppercase">
              Individual development plan
            </div>
            <p className="text-sm text-gray-600 mb-3">
              {SUGGESTED_GOALS} to {MAX_GOALS} things to work on before the next evaluation. The
              athlete&rsquo;s crew can see these in the parent portal.
            </p>

            <div className="space-y-3">
              {goals.map((goal, index) => (
                <div key={index} className="flex gap-2 items-start">
                  <input
                    type="text"
                    className="flex-1 border border-brand-secondary rounded-md px-3 py-2 text-sm"
                    placeholder={`Goal ${index + 1}`}
                    aria-label={`Goal ${index + 1}`}
                    value={goal.goal}
                    onChange={(e) => updateGoal(index, { goal: e.target.value })}
                  />
                  <input
                    type="date"
                    className="border border-brand-secondary rounded-md px-3 py-2 text-sm"
                    aria-label={`Goal ${index + 1} target date`}
                    value={goal.target_date ?? ''}
                    onChange={(e) => updateGoal(index, { target_date: e.target.value || null })}
                  />
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Remove goal ${index + 1}`}
                    onClick={() => setGoals((prev) => prev.filter((_, i) => i !== index))}
                  >
                    &times;
                  </Button>
                </div>
              ))}
            </div>

            {goals.length < MAX_GOALS && (
              <Button
                variant="link"
                className="mt-3"
                onClick={() => setGoals((prev) => [...prev, { goal: '', target_date: null }])}
              >
                Add a goal
              </Button>
            )}
          </div>
        </div>

        <div className="border-t border-brand-secondary px-6 py-4 flex justify-end space-x-3">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} loading={saving}>
            {existing ? 'Update evaluation' : 'Save evaluation'}
          </Button>
        </div>
      </div>
    </div>
  );
};

export default AthleteEvaluationModal;

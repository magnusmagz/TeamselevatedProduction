import React, { useCallback, useEffect, useState } from 'react';
import { AthleteEvaluation, EvaluationListResponse } from './types';
import EvaluationTrendChart from './EvaluationTrendChart';
import AthleteEvaluationModal from './AthleteEvaluationModal';
import { formatDateOnly } from '../../utils/dateFormat';

/**
 * Mid-year evaluations and IDP goals for one athlete (CKU R76/R77).
 *
 * ONE component serves the staff athlete profile and the parent portal, with
 * `readOnly` deciding whether the editing controls exist at all. That is
 * deliberate: the alternative — a second read-only copy for the portal — is how
 * `getAgeQuarter` ended up wrong in four places, and a parent must see exactly
 * what their child's coach wrote, not a paraphrase of it.
 *
 * ⚠️ `readOnly` is a UI simplification, NOT the access control. The server
 * decides: reads gate on AthleteScope::userCanAccessAthlete (a guardian passes),
 * writes on staffCanManageAthlete AND directly coaching the athlete. This
 * component renders the New evaluation button only when the SERVER said
 * `can_evaluate` — never from a locally inferred role — so a button that leads
 * to a 403 is not possible.
 */

interface AthleteEvaluationsPanelProps {
  athleteId: number;
  athleteName: string;
  /** Teams the athlete is on, for the modal's attribution select. */
  teams?: Array<{ id: number; name: string }>;
  /** Parent portal. Hides every write control regardless of what the API says. */
  readOnly?: boolean;
  apiUrl: string;
}

export const AthleteEvaluationsPanel: React.FC<AthleteEvaluationsPanelProps> = ({
  athleteId,
  athleteName,
  teams = [],
  readOnly = false,
  apiUrl,
}) => {
  const [evaluations, setEvaluations] = useState<AthleteEvaluation[]>([]);
  const [available, setAvailable] = useState(true);
  const [canEvaluate, setCanEvaluate] = useState(false);
  const [canDelete, setCanDelete] = useState(false);
  const [viewerId, setViewerId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showModal, setShowModal] = useState(false);
  const [editing, setEditing] = useState<AthleteEvaluation | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(
        `${apiUrl}/api/athlete-evaluations.php?action=list&athlete_id=${athleteId}`,
        { headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` } },
      );
      const data: EvaluationListResponse = await res.json();

      if (!res.ok || !data.success) {
        // A real error state, never setEvaluations([]) — rendering "no
        // evaluations yet" on a failed read tells a family their child has never
        // been evaluated, which is a false empty.
        setError((data as any)?.error || 'Could not load evaluations.');
        return;
      }

      setAvailable(data.available !== false);
      setEvaluations(data.evaluations || []);
      setCanEvaluate(!readOnly && !!data.can_evaluate);
      setCanDelete(!readOnly && !!data.can_delete);
      setViewerId(data.viewer_id ?? null);
    } catch {
      setError('Unable to reach the server.');
    } finally {
      setLoading(false);
    }
  }, [apiUrl, athleteId, readOnly]);

  useEffect(() => {
    load();
  }, [load]);

  const handleDelete = async (evaluation: AthleteEvaluation) => {
    if (!window.confirm(`Delete the ${evaluation.season_label} evaluation? This cannot be undone.`)) {
      return;
    }
    try {
      const res = await fetch(
        `${apiUrl}/api/athlete-evaluations.php?action=delete&id=${evaluation.id}`,
        {
          method: 'DELETE',
          headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
        },
      );
      const data = await res.json();
      if (res.ok && data.success) {
        load();
      } else {
        setError(data.error || 'Could not delete the evaluation.');
      }
    } catch {
      setError('Unable to reach the server.');
    }
  };

  if (loading) {
    return <div className="text-sm text-gray-500">Loading evaluations...</div>;
  }

  return (
    <div>
      <div className="flex justify-between items-start mb-4">
        <div>
          <div className="text-sm font-bold uppercase tracking-wide">Mid-year evaluation</div>
          <p className="text-sm text-gray-600">
            {readOnly
              ? `What ${athleteName.split(' ')[0]}'s coaches have recorded this season and in past seasons.`
              : 'Season check-ins and individual development plans, scored on the club’s criteria.'}
          </p>
        </div>
        {canEvaluate && available && (
          <button
            type="button"
            onClick={() => {
              setEditing(null);
              setShowModal(true);
            }}
            className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary-hover font-semibold uppercase text-sm"
          >
            New evaluation
          </button>
        )}
      </div>

      {error && (
        <div className="mb-4 p-3 bg-red-50 border border-red-300 text-red-800 rounded-md text-sm">
          {error}
        </div>
      )}

      {/* `available: false` is a missing migration, not an empty history. Saying
          "no evaluations yet" here would be a false statement about a child. */}
      {!available && (
        <div className="p-4 border border-brand-secondary rounded-md text-sm text-gray-700">
          Evaluations are not switched on for this club yet.
        </div>
      )}

      {available && evaluations.length === 0 && !error && (
        <div className="p-4 border border-dashed border-brand-secondary rounded-md text-sm text-gray-600">
          No evaluations recorded yet.
          {canEvaluate && ' Use New evaluation to record the first one.'}
        </div>
      )}

      {available && evaluations.length > 0 && (
        <>
          <EvaluationTrendChart evaluations={evaluations} />

          <div className="space-y-3">
            {evaluations.map((evaluation) => {
              const isOpen = expanded === evaluation.id;
              const isAuthor = viewerId !== null && viewerId === evaluation.evaluator_id;
              return (
                <div
                  key={evaluation.id}
                  className="border border-brand-secondary rounded-md overflow-hidden"
                >
                  <button
                    type="button"
                    onClick={() => setExpanded(isOpen ? null : evaluation.id)}
                    aria-expanded={isOpen}
                    className="w-full flex justify-between items-center px-4 py-3 text-left hover:bg-gray-50"
                  >
                    <div>
                      <div className="font-medium text-brand-primary">
                        {evaluation.season_label}
                        {evaluation.team_name ? ` · ${evaluation.team_name}` : ''}
                      </div>
                      <div className="text-xs text-gray-500">
                        {/* formatDateOnly, never new Date(str) — see the
                            date-only rule in CLAUDE.md. */}
                        {formatDateOnly(evaluation.evaluated_at)}
                        {evaluation.evaluator_name ? ` · ${evaluation.evaluator_name}` : ''}
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-2xl font-bold text-brand-primary">
                        {/* null is "nothing was scored", not zero. */}
                        {evaluation.overall_score === null ? '—' : evaluation.overall_score}
                      </div>
                      <div className="text-xs text-gray-400 uppercase">Overall</div>
                    </div>
                  </button>

                  {isOpen && (
                    <div className="px-4 pb-4 border-t border-gray-200 pt-3">
                      {evaluation.scores.length > 0 && (
                        <div className="mb-3">
                          {evaluation.scores.map((s) => (
                            <div
                              key={s.criterion_name}
                              className="flex justify-between border-b border-gray-100 py-1 text-sm"
                            >
                              <span className="font-medium">{s.criterion_name}</span>
                              <span>
                                {s.score === null
                                  ? 'Not scored'
                                  : `${s.score}${s.max_score ? ` / ${s.max_score}` : ''}`}
                                {s.comment ? ` — ${s.comment}` : ''}
                              </span>
                            </div>
                          ))}
                        </div>
                      )}

                      {evaluation.notes && (
                        <div className="mb-3 text-sm">
                          <div className="font-medium text-brand-primary mb-1">Notes</div>
                          <p className="whitespace-pre-wrap text-gray-700">{evaluation.notes}</p>
                        </div>
                      )}

                      {evaluation.idp_goals.length > 0 && (
                        <div className="mb-3 text-sm">
                          <div className="font-medium text-brand-primary mb-1">
                            Development plan
                          </div>
                          <ul className="list-disc pl-5 space-y-1 text-gray-700">
                            {evaluation.idp_goals.map((g, i) => (
                              <li key={i}>
                                {g.goal}
                                {g.target_date ? ` (by ${formatDateOnly(g.target_date)})` : ''}
                              </li>
                            ))}
                          </ul>
                        </div>
                      )}

                      {(canDelete || (canEvaluate && isAuthor)) && (
                        <div className="flex gap-3 pt-2">
                          {canEvaluate && isAuthor && (
                            <button
                              type="button"
                              className="text-sm text-brand-primary underline"
                              onClick={() => {
                                setEditing(evaluation);
                                setShowModal(true);
                              }}
                            >
                              Edit
                            </button>
                          )}
                          {canDelete && (
                            <button
                              type="button"
                              className="text-sm text-red-700 underline"
                              onClick={() => handleDelete(evaluation)}
                            >
                              Delete
                            </button>
                          )}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </>
      )}

      {showModal && (
        <AthleteEvaluationModal
          athleteId={athleteId}
          athleteName={athleteName}
          teams={teams}
          existing={editing}
          apiUrl={apiUrl}
          onClose={() => setShowModal(false)}
          onSaved={() => {
            setShowModal(false);
            load();
          }}
        />
      )}
    </div>
  );
};

export default AthleteEvaluationsPanel;

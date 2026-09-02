import React from 'react';

/**
 * The criterion scoring form, shared by the tryout evaluation modal and the
 * mid-year athlete evaluation modal.
 *
 * It was extracted OUT of EvaluationModal rather than copied, and that direction
 * matters. A copy would have started identical and drifted — the tryout sheet and
 * the mid-year sheet are the same instrument in the coach's hands, and a club
 * that scores "Technical Skills" out of 5 in August must not find it scored out
 * of 10 in January because one of two components was edited. The tryout flow's
 * behaviour is unchanged: the same 1..max_score buttons, the same Poor/Excellent
 * labels, and the same weighted preview (see computeWeightedOverall).
 *
 * This component is deliberately presentational — no fetching, no auth, no
 * knowledge of registrations or athletes. Its two callers disagree about almost
 * everything else (one keys scores by criterion ID, the other by criterion NAME,
 * because a mid-year evaluation copies the name so its history survives the club
 * editing its criteria), so the seam between them is a plain `key` string.
 */

export interface ScoringCriterion {
  /** Whatever the caller keys its score map by: an id for tryouts, a name for IDP. */
  key: string;
  name: string;
  description?: string | null;
  max_score: number;
  weight: number;
}

/**
 * The weighted 0-100 preview shown above the form.
 *
 * Ported verbatim from EvaluationModal.calculatePreviewScore, including the two
 * behaviours it is easy to "tidy" away and should not be:
 *  - a score of 0 (i.e. not yet chosen — the buttons start at 1) contributes
 *    nothing rather than dragging the average down, so a half-filled sheet
 *    previews what has actually been assessed;
 *  - nothing scored yet renders '-', not '0.0'. A fabricated zero reads as a
 *    real assessment.
 *
 * The server recomputes this from the submitted scores and stores the result;
 * this is a preview, never the value of record.
 */
export function computeWeightedOverall(
  criteria: ScoringCriterion[],
  scores: Record<string, number>,
): string {
  let weightedSum = 0;
  let totalWeight = 0;

  criteria.forEach((c) => {
    const score = scores[c.key] || 0;
    if (score > 0 && c.max_score > 0) {
      const normalized = (score / c.max_score) * 100;
      weightedSum += normalized * c.weight;
      totalWeight += c.weight;
    }
  });

  if (totalWeight > 0) {
    return (weightedSum / totalWeight).toFixed(1);
  }
  return '-';
}

interface CriterionScoreListProps {
  criteria: ScoringCriterion[];
  scores: Record<string, number>;
  onScoreChange: (key: string, score: number) => void;
  /** Per-criterion free-text note. Omit to hide the comment inputs (tryouts). */
  comments?: Record<string, string>;
  onCommentChange?: (key: string, comment: string) => void;
  disabled?: boolean;
}

export const CriterionScoreList: React.FC<CriterionScoreListProps> = ({
  criteria,
  scores,
  onScoreChange,
  comments,
  onCommentChange,
  disabled = false,
}) => (
  <div className="space-y-6">
    {criteria.map((criterion) => (
      <div key={criterion.key} className="border-b border-gray-200 pb-4">
        <div className="flex justify-between items-start mb-2">
          <div>
            <div className="font-medium text-brand-primary">{criterion.name}</div>
            {criterion.description && (
              <div className="text-sm text-gray-500">{criterion.description}</div>
            )}
          </div>
          <div className="text-sm text-gray-500">Weight: {criterion.weight}x</div>
        </div>

        <div className="flex space-x-2 mt-3">
          {Array.from({ length: criterion.max_score }, (_, i) => i + 1).map((score) => (
            <button
              key={score}
              type="button"
              disabled={disabled}
              aria-label={`${criterion.name}: ${score}`}
              aria-pressed={scores[criterion.key] === score}
              onClick={() => onScoreChange(criterion.key, score)}
              className={`w-12 h-12 rounded-md font-semibold text-lg transition-colors disabled:opacity-50 ${
                scores[criterion.key] === score
                  ? 'bg-brand-primary text-white'
                  : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
              }`}
            >
              {score}
            </button>
          ))}
        </div>

        <div className="flex justify-between text-xs text-gray-400 mt-1 px-1">
          <span>Poor</span>
          <span>Excellent</span>
        </div>

        {onCommentChange && (
          <input
            type="text"
            className="mt-3 w-full border border-brand-secondary rounded-md px-3 py-1.5 text-sm"
            placeholder={`Note on ${criterion.name} (optional)`}
            aria-label={`${criterion.name} note`}
            disabled={disabled}
            value={comments?.[criterion.key] ?? ''}
            onChange={(e) => onCommentChange(criterion.key, e.target.value)}
          />
        )}
      </div>
    ))}
  </div>
);

export default CriterionScoreList;

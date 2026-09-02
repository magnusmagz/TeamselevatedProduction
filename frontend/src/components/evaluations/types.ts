/** Shared types for mid-year athlete evaluations (CKU R76/R77, migration 086). */

export interface EvaluationScore {
  criterion_name: string;
  /** null when the coach left this criterion unscored — not the same as 0. */
  score: number | null;
  max_score: number | null;
  weight: number | null;
  comment: string | null;
}

export interface IdpGoal {
  goal: string;
  /** Stored and displayed as the submitted YYYY-MM-DD string; never parsed. */
  target_date: string | null;
}

export interface AthleteEvaluation {
  id: number;
  athlete_id: number;
  team_id: number | null;
  team_name: string | null;
  evaluator_id: number;
  evaluator_name: string | null;
  /** YYYY-MM-DD. */
  evaluated_at: string;
  season_label: string;
  /** The frozen weighted 0-100 roll-up. null when nothing was scored. */
  overall_score: number | null;
  notes: string | null;
  idp_goals: IdpGoal[];
  scores: EvaluationScore[];
  created_at: string | null;
  updated_at: string | null;
}

export interface EvaluationCriterionOption {
  name: string;
  description: string | null;
  max_score: number;
  weight: number;
}

export interface EvaluationListResponse {
  success: boolean;
  /**
   * false means migration 086 has not been applied yet — NOT "no evaluations".
   * An empty list and a missing feature are opposite answers and the panel
   * renders them differently on purpose.
   */
  available: boolean;
  evaluations: AthleteEvaluation[];
  can_evaluate: boolean;
  can_delete: boolean;
  viewer_id?: number;
}

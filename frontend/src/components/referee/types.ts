/** One referee_feedback row as api/referee-feedback.php returns it (joined). */
export interface RefereeFeedbackRow {
  id: number;
  club_id: number;
  calendar_event_id: number;
  team_id: number;
  submitted_by: number;
  referee_name: string;
  /** Directory pick (Referees, migration 099); null for a free-typed name. */
  referee_id?: number | null;
  rating: number;
  categories: string[];
  comments: string | null;
  incident: boolean;
  created_at: string | null;
  updated_at: string | null;
  event_name: string;
  /** Stored YYYY-MM-DD. Display with formatDateOnly; never new Date(). */
  event_date: string;
  start_time: string | null;
  opponent_name: string | null;
  team_name: string | null;
  submitted_by_name: string;
}

export interface RefereeSummaryRow {
  referee_name: string;
  /** Grouped by id when the rows were directory picks, else by name. */
  referee_id?: number | null;
  count: number;
  average_rating: number;
  incident_count: number;
}

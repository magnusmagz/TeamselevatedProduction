/** One referees row as api/referees.php returns it (Referees directory, 2026-09-08). */
export interface RefereeRow {
  id: number;
  club_id: number;
  /** The account, when the club's row is linked to one. One person, many clubs. */
  user_id: number | null;
  first_name: string;
  last_name: string;
  name: string;
  email: string | null;
  /** E.164 as stored. */
  phone: string | null;
  grade: string | null;
  certification_level: string | null;
  notes: string | null;
  active: boolean;
  archived_at: string | null;
  /** lib/portal_status.php ladder; absent before the list has been through Postgres. */
  status?: string;
  first_login_at?: string | null;
  invited_at?: string | null;
}

/** A typeahead hit (`action=search`). */
export interface RefereeSearchHit {
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  email: string | null;
  grade: string | null;
  certification_level: string | null;
  user_id: number | null;
}

export type GameRefereeRole = 'referee' | 'center' | 'assistant' | 'fourth';

export const GAME_REFEREE_ROLES: GameRefereeRole[] = ['referee', 'center', 'assistant', 'fourth'];

export const GAME_REFEREE_ROLE_LABEL: Record<GameRefereeRole, string> = {
  referee: 'Referee',
  center: 'Center',
  assistant: 'Assistant',
  fourth: 'Fourth official',
};

/** One assignment on a game (`action=for-event`). */
export interface GameRefereeAssignment {
  assignment_id: number;
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string | null;
  grade: string | null;
  certification_level: string | null;
  role: GameRefereeRole | string;
  self_assigned: boolean;
  grade_override: boolean;
  /** Staff placed them on top of an overlapping game that day, knowingly. */
  conflict_override?: boolean;
}

/** What the create form holds before the game exists. */
export interface PendingRefereeAssignment {
  referee_id: number;
  name: string;
  grade: string | null;
  role: GameRefereeRole;
}

/** One of the signed-in referee's games (`action=my-games` / `open-games`). */
export interface RefereeGame {
  id: number;
  club_id: number | null;
  club_name: string | null;
  primary_color: string | null;
  name: string;
  /** Stored YYYY-MM-DD. Display with formatDateOnly; never new Date(). */
  event_date: string;
  start_time: string | null;
  end_time: string | null;
  opponent_name: string | null;
  location: string | null;
  status: string | null;
  venue_name: string | null;
  venue_address: string | null;
  venue_city: string | null;
  teams: { id: number; name: string; primary_color: string | null }[];
  /** my-games only */
  role?: string;
  self_assigned?: boolean;
  referee_id?: number;
  /** open-games only */
  min_referee_grade?: string | null;
  referees?: { id: number; name: string; role: string; grade: string | null }[];
  open_roles?: string[];
  /** open-games: overlaps a game they are already on — shown greyed, not hidden. */
  conflict?: boolean;
  conflict_reason?: string | null;
}

export interface RefereeClub {
  referee_id: number;
  club_id: number;
  club_name: string | null;
  primary_color: string | null;
  grade: string | null;
  certification_level: string | null;
}

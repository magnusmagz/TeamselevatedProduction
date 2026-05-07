// Tournament Module TypeScript Interfaces

export type TournamentStatus =
  | 'draft'
  | 'registration_open'
  | 'registration_closed'
  | 'scheduling'
  | 'in_progress'
  | 'weather_delay'
  | 'completed'
  | 'cancelled';

export type DivisionFormat =
  | 'round_robin'
  | 'group_knockout'
  | 'single_elimination'
  | 'double_elimination';

export type RegistrationStatus = 'pending' | 'accepted' | 'rejected' | 'waitlisted' | 'withdrawn';
export type PaymentStatus = 'unpaid' | 'paid' | 'refunded' | 'waived';
export type MatchStatus = 'scheduled' | 'in_progress' | 'completed' | 'cancelled' | 'postponed' | 'forfeit_home' | 'forfeit_away';
export type Gender = 'boys' | 'girls' | 'coed';

export type GoverningBody = 'usys' | 'us_club' | 'ayso' | 'aau' | 'state' | 'unaffiliated';

export const GOVERNING_BODY_LABELS: Record<GoverningBody, string> = {
  usys: 'USYS (US Youth Soccer)',
  us_club: 'US Club Soccer',
  ayso: 'AYSO',
  aau: 'AAU',
  state: 'State Association',
  unaffiliated: 'Unaffiliated',
};

export type CompetitiveLevel = 'premier' | 'gold' | 'silver' | 'bronze' | 'recreational' | 'open';

export const COMPETITIVE_LEVEL_LABELS: Record<CompetitiveLevel, string> = {
  premier: 'Premier',
  gold: 'Gold',
  silver: 'Silver',
  bronze: 'Bronze',
  recreational: 'Recreational',
  open: 'Open',
};

export interface Tournament {
  id: number;
  club_id: number;
  name: string;
  description: string | null;
  sport: string;
  start_date: string;
  end_date: string;
  // Venue link (preferred). Legacy location_* fields below remain as
  // override / fallback for tournaments without a venue selected.
  venue_id: number | null;
  daily_start_time: string;       // 'HH:MM:SS' — defaults '08:00:00'
  daily_end_time: string;         // 'HH:MM:SS' — defaults '20:00:00'
  // Joined venue summary from the get/list endpoints (read-only)
  venue_name?: string | null;
  venue_address?: string | null;
  venue_city?: string | null;
  venue_state?: string | null;
  venue_zip?: string | null;
  venue_field_count?: number;
  location_name: string | null;
  location_address: string | null;
  location_city: string | null;
  location_state: string | null;
  location_zip: string | null;
  location_coordinates: { lat: number; lng: number } | null;
  registration_open_date: string | null;
  registration_close_date: string | null;
  status: TournamentStatus;
  entry_fee_cents: number;
  max_teams_per_division: number | null;
  rules_document_url: string | null;
  contact_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  public_url_slug: string | null;
  season_id: number | null;
  faq_markdown: string | null;
  insurance_certificate_url: string | null;
  insurance_certificate_filename: string | null;
  insurance_expiry_date: string | null;       // ISO date 'YYYY-MM-DD'
  insurance_policy_number: string | null;
  insurance_provider: string | null;
  governing_body: GoverningBody | null;
  sanction_number: string | null;
  state_association: string | null;
  host_name: string | null;
  created_by: number;
  created_at: string;
  updated_at: string;
  // Computed fields from list endpoint
  division_count?: number;
  registration_count?: number;
  // Nested from get endpoint
  divisions?: TournamentDivision[];
}

export type ScoringSystem = 'standard' | 'ten_point';

export type OvertimeMode = 'none' | 'pks_only' | 'overtime_then_pks';

export interface OvertimeRules {
  mode: OvertimeMode;
  // Used when mode === 'overtime_then_pks'
  ot_periods?: number;
  ot_minutes_per_period?: number;
  golden_goal?: boolean;
}

export const OVERTIME_MODE_LABELS: Record<OvertimeMode, string> = {
  none: 'No overtime — ties stand',
  pks_only: 'Penalty shootout if tied at full time',
  overtime_then_pks: 'Overtime, then PKs if still tied',
};

export interface TournamentDivision {
  id: number;
  tournament_id: number;
  name: string;
  age_group: string;
  gender: Gender;
  format: DivisionFormat;
  game_duration_minutes: number;
  half_duration_minutes: number;
  max_roster_size: number;
  min_roster_size: number;
  max_teams: number | null;
  teams_per_group: number;
  teams_advancing_per_group: number;
  goal_differential_cap: number | null;
  tiebreaker_rules: string[];
  points_for_win: number;
  points_for_draw: number;
  points_for_loss: number;
  scoring_system: ScoringSystem;
  competitive_level: CompetitiveLevel | null;
  max_players_on_field: number | null;
  sport_rule_notes: string[] | null;
  overtime_rules: OvertimeRules | null;
  max_guest_players: number | null;
  guest_must_be_same_club: boolean;
  sort_order: number;
  created_at: string;
  updated_at: string;
  // Computed
  registration_count?: number;
  group_count?: number;
}

export interface TournamentGroup {
  id: number;
  division_id: number;
  name: string;
  sort_order: number;
  teams: TournamentRegistration[];
}

export type WaitlistOfferState = 'none' | 'offered' | 'declined' | 'expired';

export interface TournamentRegistration {
  id: number;
  tournament_id: number;
  division_id: number;
  team_id: number;
  team_name_override: string | null;
  club_name_override: string | null;
  display_name: string;
  registered_by: number;
  registered_by_name?: string;
  status: RegistrationStatus;
  payment_status: PaymentStatus;
  payment_amount_cents: number | null;
  payment_reference: string | null;
  seed: number | null;
  group_id: number | null;
  group_name?: string | null;
  division_name?: string;
  notes: string | null;
  // Waitlist cascade state — surfaced for rows with status='waitlisted' so
  // the manager can show "Offered (expires in 36h)" / "Declined" badges
  // and a Promote action.
  waitlist_position?: number | null;
  waitlist_offered_at?: string | null;
  waitlist_offer_expires_at?: string | null;
  waitlist_offer_state?: WaitlistOfferState;
  created_at: string;
  updated_at: string;
}

export interface TournamentMatch {
  id: number;
  division_id: number;
  group_id: number | null;
  round: string;
  match_number: number;
  home_registration_id: number | null;
  away_registration_id: number | null;
  home_team_name?: string;
  away_team_name?: string;
  home_placeholder: string | null;
  away_placeholder: string | null;
  home_source_match_id: number | null;
  away_source_match_id: number | null;
  field_id: number | null;
  field_name?: string;
  scheduled_time: string | null;
  scheduled_end_time: string | null;
  status: MatchStatus;
  home_score: number | null;
  away_score: number | null;
  home_penalty_score: number | null;
  away_penalty_score: number | null;
  winner_registration_id: number | null;
  group_name?: string;
  notes: string | null;
  // Match Center referee-report fields (migration 023)
  field_conditions?: string | null;
  incident_report?: string | null;
  match_card_photo_url?: string | null;
  scored_by: number | null;
  scored_at: string | null;
  created_at: string;
}

export interface TournamentStanding {
  position: number;
  registration_id: number;
  team_name: string;
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  goal_difference: number;
  points: number;
}

export interface SportPreset {
  id: number;
  sport: string;
  age_group: string;
  game_duration_minutes: number;
  half_duration_minutes: number;
  max_roster_size: number;
  min_roster_size: number;
  max_players_on_field: number;
  rule_notes: string[] | null;
}

export interface TournamentFormData {
  name: string;
  description: string;
  sport: string;
  start_date: string;
  end_date: string;
  venue_id: number | null;
  daily_start_time: string;       // 'HH:MM' or 'HH:MM:SS'
  daily_end_time: string;         // 'HH:MM' or 'HH:MM:SS'
  location_name: string;
  location_address: string;
  location_city: string;
  location_state: string;
  location_zip: string;
  registration_open_date: string;
  registration_close_date: string;
  entry_fee_cents: number;
  max_teams_per_division: number | null;
  contact_name: string;
  contact_email: string;
  contact_phone: string;
  public_url_slug: string;
  season_id: number | null;
  rules_document_url: string;
  faq_markdown: string;
  insurance_certificate_url: string;
  insurance_certificate_filename: string;
  insurance_expiry_date: string;
  insurance_policy_number: string;
  insurance_provider: string;
  governing_body: GoverningBody | '';
  sanction_number: string;
  state_association: string;
  host_name: string;
}

export interface VenueSummary {
  id: number;
  name: string;
  address?: string | null;
  city?: string | null;
  state?: string | null;
  zip_code?: string | null;
  field_count?: number;
}

// Status display config
export const TOURNAMENT_STATUS_CONFIG: Record<TournamentStatus, { label: string; color: string }> = {
  draft: { label: 'Draft', color: 'bg-gray-100 text-gray-700' },
  registration_open: { label: 'Registration Open', color: 'bg-green-100 text-green-700' },
  registration_closed: { label: 'Registration Closed', color: 'bg-yellow-100 text-yellow-700' },
  scheduling: { label: 'Scheduling', color: 'bg-blue-100 text-blue-700' },
  in_progress: { label: 'In Progress', color: 'bg-purple-100 text-purple-700' },
  weather_delay: { label: 'Weather Delay', color: 'bg-orange-100 text-orange-700' },
  completed: { label: 'Completed', color: 'bg-gray-100 text-gray-600' },
  cancelled: { label: 'Cancelled', color: 'bg-red-100 text-red-700' },
};

// Valid status transitions
export const VALID_STATUS_TRANSITIONS: Record<TournamentStatus, TournamentStatus[]> = {
  draft: ['registration_open', 'cancelled'],
  registration_open: ['registration_closed', 'cancelled'],
  registration_closed: ['scheduling', 'registration_open', 'cancelled'],
  scheduling: ['in_progress', 'cancelled'],
  in_progress: ['weather_delay', 'completed', 'cancelled'],
  weather_delay: ['in_progress', 'cancelled'],
  completed: [],
  cancelled: [],
};

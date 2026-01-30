export type ProgramType = 'league' | 'camp' | 'clinic' | 'tryout' | 'tournament' | 'drop_in';
export type ProgramStatus = 'draft' | 'published' | 'closed' | 'cancelled';
export type FieldType = 'text' | 'email' | 'tel' | 'number' | 'date' | 'select' | 'checkbox' | 'radio' | 'textarea';
export type RegistrationStatus = 'pending' | 'approved' | 'rejected' | 'waitlist';

// Tryout-specific types
export type TryoutStatus =
  | 'registered'
  | 'checked_in'
  | 'evaluated'
  | 'offered'
  | 'waitlist'
  | 'not_selected'
  | 'accepted'
  | 'declined'
  | 'rostered';

export type OfferType = 'roster' | 'waitlist' | 'not_selected';
export type OfferResponse = 'accepted' | 'declined';

export interface Program {
  id?: number;
  club_id?: number;
  name: string;
  type: ProgramType;
  description?: string;
  start_date?: string;
  end_date?: string;
  registration_opens?: string;
  registration_closes?: string;
  min_age?: number;
  max_age?: number;
  capacity?: number;
  registration_fee?: number;
  current_enrolled?: number;
  status: ProgramStatus;
  embed_code?: string;
  registration_count?: number;
  pending_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface FormField {
  id?: number;
  program_id?: number;
  field_name: string;
  field_label: string;
  field_type: FieldType;
  required: boolean;
  options?: string[] | { label: string; value: string }[];
  section?: string;
  display_order?: number;
}

export interface Registration {
  id?: number;
  program_id: number;
  form_data: Record<string, any>;
  status: RegistrationStatus;
  submitted_at?: string;
  reviewed_at?: string;
  reviewed_by?: number;
  notes?: string;
}

export interface DragDropField extends FormField {
  tempId?: string; // For tracking during drag-drop before saving
}

// Tryout-specific interfaces
export interface TryoutSession {
  id?: number;
  program_id: number;
  session_date: string;
  start_time?: string;
  end_time?: string;
  location?: string;
  venue_id?: number;
  venue_name?: string;
  notes?: string;
}

export interface EvaluationCriterion {
  id?: number;
  program_id?: number;
  name: string;
  description?: string;
  max_score: number;
  weight: number;
  display_order: number;
  tempId?: string; // For drag-drop before saving
}

export interface TryoutEvaluation {
  id?: number;
  registration_id: number;
  evaluator_id: number;
  evaluator_first?: string;
  evaluator_last?: string;
  session_id?: number;
  scores: Record<string, number>; // { criteria_id: score }
  overall_score?: number;
  notes?: string;
  created_at?: string;
  updated_at?: string;
}

export interface TryoutRegistration {
  id: number;
  athlete_id: number;
  first_name: string;
  last_name: string;
  date_of_birth?: string;
  gender?: string;
  tryout_status?: TryoutStatus;
  tryout_number?: string;
  overall_score?: number;
  ranking?: number;
  assigned_team_id?: number;
  assigned_team_name?: string;
  evaluation_count?: number;
  form_data?: Record<string, any>;
  submitted_at?: string;
}

export interface TryoutOffer {
  id?: number;
  registration_id: number;
  offer_type: OfferType;
  team_id?: number;
  team_name?: string;
  response?: OfferResponse;
  notes?: string;
  sent_at?: string;
  responded_at?: string;
  // Denormalized athlete info
  athlete_id?: number;
  first_name?: string;
  last_name?: string;
}

export interface TryoutRanking {
  id: number;
  athlete_id: number;
  first_name: string;
  last_name: string;
  date_of_birth?: string;
  tryout_status?: TryoutStatus;
  tryout_number?: string;
  overall_score?: number;
  ranking?: number;
  evaluation_count: number;
  avg_score?: number;
  min_score?: number;
  max_score?: number;
}
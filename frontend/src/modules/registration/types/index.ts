export type ProgramType = 'league' | 'camp' | 'clinic' | 'tryout' | 'tournament' | 'drop_in';
export type ProgramStatus = 'draft' | 'published' | 'closed' | 'cancelled';
export type FieldType = 'text' | 'email' | 'tel' | 'number' | 'date' | 'select' | 'checkbox' | 'radio' | 'textarea';
export type RegistrationStatus = 'pending' | 'approved' | 'rejected' | 'waitlist';

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
  current_enrolled?: number;
  status: ProgramStatus;
  embed_code?: string;
  registration_count?: number;
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
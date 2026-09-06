import { FieldSize } from '../../utils/lineupFormations';

/** Shapes returned by api/lineups.php. The server decides; these only describe. */

export type RosterStatus = 'active' | 'injured' | 'suspended';
export type AttendanceStatus = 'present' | 'absent' | 'late' | 'excused';

export interface RosterPlayer {
  athlete_id: number;
  first_name: string;
  last_name: string;
  name: string;
  jersey_number: number | null;
  primary_position: string | null;
  status: RosterStatus;
}

export interface LineupSlotRow {
  athlete_id: number;
  slot: string;
  sort_order: number;
  captain: boolean;
  note: string | null;
}

export interface LineupRow {
  id: number;
  team_id: number;
  calendar_event_id: number | null;
  is_template: boolean;
  name: string;
  formation: string;
  field_size: FieldSize;
  published_at: string | null;
  updated_at?: string | null;
  slots: LineupSlotRow[];
}

export interface LineupEvent {
  id: number;
  name: string;
  type: string;
  event_date: string;
  start_time: string | null;
  opponent_name: string | null;
  status: string | null;
}

export interface LineupStaffResponse {
  success: true;
  available: true;
  can_edit: true;
  team: {
    id: number;
    name: string;
    age_group: string | null;
    field_size: FieldSize;
    field_size_from_age_group: boolean;
  };
  event: LineupEvent | null;
  lineup: LineupRow | null;
  is_template: boolean;
  has_template: boolean;
  last_game: LineupEvent | null;
  formations: string[];
  roster: RosterPlayer[];
  attendance: Record<string, AttendanceStatus>;
}

export interface CrewSlot {
  athlete_id: number;
  name: string;
  jersey_number: number | null;
  captain: boolean;
  slot: string;
}

export interface CrewBench {
  athlete_id: number;
  name: string;
  jersey_number: number | null;
  captain: boolean;
}

export interface LineupCrewResponse {
  success: true;
  can_edit: false;
  team: { id: number; name: string };
  event: LineupEvent;
  lineup: {
    formation: string;
    field_size: FieldSize;
    published_at: string | null;
    slots: CrewSlot[];
    bench: CrewBench[];
  };
  my_athlete_ids: number[];
}

/** What the pitch draws in one slot. */
export interface PitchPlayer {
  athlete_id: number;
  name: string;
  last_name?: string;
  jersey_number: number | null;
  captain?: boolean;
  status?: RosterStatus;
  attendance?: AttendanceStatus;
}

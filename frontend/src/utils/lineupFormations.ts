/**
 * Formation presets for the lineup builder (slice 8.5, R67).
 *
 * GENERATED — one table, two languages. This file and lib/lineup_formations.php
 * were produced by the same script and LineupFormationConsistencyTest (PHP)
 * parses this copy and fails if they differ. Change both, or neither.
 *
 * x/y are on a normalised pitch (0–100, y = 0 is the opponent's goal line).
 * 4v4 has no goalkeeper. The first preset of each size is the default.
 */

export type FieldSize = '4v4' | '7v7' | '9v9' | '11v11';

export interface FormationSlot {
  slot: string;
  label: string;
  x: number;
  y: number;
}

export const FIELD_SIZES: FieldSize[] = ['4v4', '7v7', '9v9', '11v11'];

export const LINEUP_FORMATIONS: Record<FieldSize, Record<string, FormationSlot[]>> = {
  '4v4': {
    '1-2-1': [
      { slot: 'D1', label: 'CB', x: 50, y: 75 },
      { slot: 'M1', label: 'LM', x: 33, y: 45 },
      { slot: 'M2', label: 'RM', x: 67, y: 45 },
      { slot: 'F1', label: 'CF', x: 50, y: 15 },
    ],
    '2-2': [
      { slot: 'D1', label: 'LB', x: 33, y: 75 },
      { slot: 'D2', label: 'RB', x: 67, y: 75 },
      { slot: 'F1', label: 'LF', x: 33, y: 15 },
      { slot: 'F2', label: 'RF', x: 67, y: 15 },
    ],
  },
  '7v7': {
    '2-3-1': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 33, y: 75 },
      { slot: 'D2', label: 'RB', x: 67, y: 75 },
      { slot: 'M1', label: 'LM', x: 25, y: 45 },
      { slot: 'M2', label: 'CM', x: 50, y: 45 },
      { slot: 'M3', label: 'RM', x: 75, y: 45 },
      { slot: 'F1', label: 'CF', x: 50, y: 15 },
    ],
    '3-2-1': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 25, y: 75 },
      { slot: 'D2', label: 'CB', x: 50, y: 75 },
      { slot: 'D3', label: 'RB', x: 75, y: 75 },
      { slot: 'M1', label: 'LM', x: 33, y: 45 },
      { slot: 'M2', label: 'RM', x: 67, y: 45 },
      { slot: 'F1', label: 'CF', x: 50, y: 15 },
    ],
    '2-1-2-1': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 33, y: 75 },
      { slot: 'D2', label: 'RB', x: 67, y: 75 },
      { slot: 'DM1', label: 'DM', x: 50, y: 55 },
      { slot: 'AM1', label: 'LAM', x: 33, y: 35 },
      { slot: 'AM2', label: 'RAM', x: 67, y: 35 },
      { slot: 'F1', label: 'CF', x: 50, y: 15 },
    ],
  },
  '9v9': {
    '3-3-2': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 25, y: 75 },
      { slot: 'D2', label: 'CB', x: 50, y: 75 },
      { slot: 'D3', label: 'RB', x: 75, y: 75 },
      { slot: 'M1', label: 'LM', x: 25, y: 45 },
      { slot: 'M2', label: 'CM', x: 50, y: 45 },
      { slot: 'M3', label: 'RM', x: 75, y: 45 },
      { slot: 'F1', label: 'LF', x: 33, y: 15 },
      { slot: 'F2', label: 'RF', x: 67, y: 15 },
    ],
    '3-2-3': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 25, y: 75 },
      { slot: 'D2', label: 'CB', x: 50, y: 75 },
      { slot: 'D3', label: 'RB', x: 75, y: 75 },
      { slot: 'M1', label: 'LM', x: 33, y: 45 },
      { slot: 'M2', label: 'RM', x: 67, y: 45 },
      { slot: 'F1', label: 'LW', x: 25, y: 15 },
      { slot: 'F2', label: 'CF', x: 50, y: 15 },
      { slot: 'F3', label: 'RW', x: 75, y: 15 },
    ],
    '2-3-3': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 33, y: 75 },
      { slot: 'D2', label: 'RB', x: 67, y: 75 },
      { slot: 'M1', label: 'LM', x: 25, y: 45 },
      { slot: 'M2', label: 'CM', x: 50, y: 45 },
      { slot: 'M3', label: 'RM', x: 75, y: 45 },
      { slot: 'F1', label: 'LW', x: 25, y: 15 },
      { slot: 'F2', label: 'CF', x: 50, y: 15 },
      { slot: 'F3', label: 'RW', x: 75, y: 15 },
    ],
    '3-4-1': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 25, y: 75 },
      { slot: 'D2', label: 'CB', x: 50, y: 75 },
      { slot: 'D3', label: 'RB', x: 75, y: 75 },
      { slot: 'M1', label: 'LM', x: 20, y: 45 },
      { slot: 'M2', label: 'LCM', x: 40, y: 45 },
      { slot: 'M3', label: 'RCM', x: 60, y: 45 },
      { slot: 'M4', label: 'RM', x: 80, y: 45 },
      { slot: 'F1', label: 'CF', x: 50, y: 15 },
    ],
  },
  '11v11': {
    '4-3-3': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 20, y: 75 },
      { slot: 'D2', label: 'LCB', x: 40, y: 75 },
      { slot: 'D3', label: 'RCB', x: 60, y: 75 },
      { slot: 'D4', label: 'RB', x: 80, y: 75 },
      { slot: 'M1', label: 'LM', x: 25, y: 45 },
      { slot: 'M2', label: 'CM', x: 50, y: 45 },
      { slot: 'M3', label: 'RM', x: 75, y: 45 },
      { slot: 'F1', label: 'LW', x: 25, y: 15 },
      { slot: 'F2', label: 'CF', x: 50, y: 15 },
      { slot: 'F3', label: 'RW', x: 75, y: 15 },
    ],
    '4-4-2': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 20, y: 75 },
      { slot: 'D2', label: 'LCB', x: 40, y: 75 },
      { slot: 'D3', label: 'RCB', x: 60, y: 75 },
      { slot: 'D4', label: 'RB', x: 80, y: 75 },
      { slot: 'M1', label: 'LM', x: 20, y: 45 },
      { slot: 'M2', label: 'LCM', x: 40, y: 45 },
      { slot: 'M3', label: 'RCM', x: 60, y: 45 },
      { slot: 'M4', label: 'RM', x: 80, y: 45 },
      { slot: 'F1', label: 'LF', x: 33, y: 15 },
      { slot: 'F2', label: 'RF', x: 67, y: 15 },
    ],
    '4-2-3-1': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 20, y: 75 },
      { slot: 'D2', label: 'LCB', x: 40, y: 75 },
      { slot: 'D3', label: 'RCB', x: 60, y: 75 },
      { slot: 'D4', label: 'RB', x: 80, y: 75 },
      { slot: 'DM1', label: 'LDM', x: 33, y: 55 },
      { slot: 'DM2', label: 'RDM', x: 67, y: 55 },
      { slot: 'AM1', label: 'LAM', x: 25, y: 35 },
      { slot: 'AM2', label: 'AM', x: 50, y: 35 },
      { slot: 'AM3', label: 'RAM', x: 75, y: 35 },
      { slot: 'F1', label: 'CF', x: 50, y: 15 },
    ],
    '3-5-2': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 25, y: 75 },
      { slot: 'D2', label: 'CB', x: 50, y: 75 },
      { slot: 'D3', label: 'RB', x: 75, y: 75 },
      { slot: 'M1', label: 'LM', x: 17, y: 45 },
      { slot: 'M2', label: 'LCM', x: 33, y: 45 },
      { slot: 'M3', label: 'CM', x: 50, y: 45 },
      { slot: 'M4', label: 'RCM', x: 67, y: 45 },
      { slot: 'M5', label: 'RM', x: 83, y: 45 },
      { slot: 'F1', label: 'LF', x: 33, y: 15 },
      { slot: 'F2', label: 'RF', x: 67, y: 15 },
    ],
    '4-1-4-1': [
      { slot: 'GK', label: 'GK', x: 50, y: 92 },
      { slot: 'D1', label: 'LB', x: 20, y: 75 },
      { slot: 'D2', label: 'LCB', x: 40, y: 75 },
      { slot: 'D3', label: 'RCB', x: 60, y: 75 },
      { slot: 'D4', label: 'RB', x: 80, y: 75 },
      { slot: 'DM1', label: 'DM', x: 50, y: 55 },
      { slot: 'AM1', label: 'LAM', x: 20, y: 35 },
      { slot: 'AM2', label: 'LCAM', x: 40, y: 35 },
      { slot: 'AM3', label: 'RCAM', x: 60, y: 35 },
      { slot: 'AM4', label: 'RAM', x: 80, y: 35 },
      { slot: 'F1', label: 'CF', x: 50, y: 15 },
    ],
  },
};

export const BENCH = 'BENCH';

export const FIELD_PLAYERS: Record<FieldSize, number> = { '4v4': 4, '7v7': 7, '9v9': 9, '11v11': 11 };

export function isFieldSize(v: unknown): v is FieldSize {
  return typeof v === 'string' && (FIELD_SIZES as string[]).includes(v);
}

/** Formation names for a size, default first. */
export function formationsFor(size: FieldSize): string[] {
  return Object.keys(LINEUP_FORMATIONS[size] || {});
}

export function defaultFormation(size: FieldSize): string {
  return formationsFor(size)[0];
}

/** The slots of one preset, or null when the formation is not one for that size. */
export function slotsFor(size: FieldSize, formation: string): FormationSlot[] | null {
  return LINEUP_FORMATIONS[size]?.[formation] ?? null;
}

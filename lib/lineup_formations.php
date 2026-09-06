<?php
/**
 * Formation presets for the lineup builder (slice 8.5, R67).
 *
 * GENERATED — one table, two languages. This file and
 * frontend/src/utils/lineupFormations.ts were produced by the same script and
 * LineupFormationConsistencyTest parses the TS copy and fails if they differ.
 * Change both, or neither.
 *
 * Each preset expands to named slots with x/y on a normalised pitch (0–100,
 * y = 0 is the opponent's goal line) so the screen, the print view and the
 * crew view draw one shape. 4v4 has no goalkeeper (US Youth Soccer rule;
 * decision 4 — a club toggle later if asked). The first preset of each size
 * is the default.
 */

require_once __DIR__ . '/field_size.php';

const TE_LINEUP_FORMATIONS = [
    '4v4' => [
        '1-2-1' => [
            ['slot' => 'D1', 'label' => 'CB', 'x' => 50, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 33, 'y' => 45],
            ['slot' => 'M2', 'label' => 'RM', 'x' => 67, 'y' => 45],
            ['slot' => 'F1', 'label' => 'CF', 'x' => 50, 'y' => 15],
        ],
        '2-2' => [
            ['slot' => 'D1', 'label' => 'LB', 'x' => 33, 'y' => 75],
            ['slot' => 'D2', 'label' => 'RB', 'x' => 67, 'y' => 75],
            ['slot' => 'F1', 'label' => 'LF', 'x' => 33, 'y' => 15],
            ['slot' => 'F2', 'label' => 'RF', 'x' => 67, 'y' => 15],
        ],
    ],
    '7v7' => [
        '2-3-1' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 33, 'y' => 75],
            ['slot' => 'D2', 'label' => 'RB', 'x' => 67, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 25, 'y' => 45],
            ['slot' => 'M2', 'label' => 'CM', 'x' => 50, 'y' => 45],
            ['slot' => 'M3', 'label' => 'RM', 'x' => 75, 'y' => 45],
            ['slot' => 'F1', 'label' => 'CF', 'x' => 50, 'y' => 15],
        ],
        '3-2-1' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 25, 'y' => 75],
            ['slot' => 'D2', 'label' => 'CB', 'x' => 50, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RB', 'x' => 75, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 33, 'y' => 45],
            ['slot' => 'M2', 'label' => 'RM', 'x' => 67, 'y' => 45],
            ['slot' => 'F1', 'label' => 'CF', 'x' => 50, 'y' => 15],
        ],
        '2-1-2-1' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 33, 'y' => 75],
            ['slot' => 'D2', 'label' => 'RB', 'x' => 67, 'y' => 75],
            ['slot' => 'DM1', 'label' => 'DM', 'x' => 50, 'y' => 55],
            ['slot' => 'AM1', 'label' => 'LAM', 'x' => 33, 'y' => 35],
            ['slot' => 'AM2', 'label' => 'RAM', 'x' => 67, 'y' => 35],
            ['slot' => 'F1', 'label' => 'CF', 'x' => 50, 'y' => 15],
        ],
    ],
    '9v9' => [
        '3-3-2' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 25, 'y' => 75],
            ['slot' => 'D2', 'label' => 'CB', 'x' => 50, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RB', 'x' => 75, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 25, 'y' => 45],
            ['slot' => 'M2', 'label' => 'CM', 'x' => 50, 'y' => 45],
            ['slot' => 'M3', 'label' => 'RM', 'x' => 75, 'y' => 45],
            ['slot' => 'F1', 'label' => 'LF', 'x' => 33, 'y' => 15],
            ['slot' => 'F2', 'label' => 'RF', 'x' => 67, 'y' => 15],
        ],
        '3-2-3' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 25, 'y' => 75],
            ['slot' => 'D2', 'label' => 'CB', 'x' => 50, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RB', 'x' => 75, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 33, 'y' => 45],
            ['slot' => 'M2', 'label' => 'RM', 'x' => 67, 'y' => 45],
            ['slot' => 'F1', 'label' => 'LW', 'x' => 25, 'y' => 15],
            ['slot' => 'F2', 'label' => 'CF', 'x' => 50, 'y' => 15],
            ['slot' => 'F3', 'label' => 'RW', 'x' => 75, 'y' => 15],
        ],
        '2-3-3' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 33, 'y' => 75],
            ['slot' => 'D2', 'label' => 'RB', 'x' => 67, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 25, 'y' => 45],
            ['slot' => 'M2', 'label' => 'CM', 'x' => 50, 'y' => 45],
            ['slot' => 'M3', 'label' => 'RM', 'x' => 75, 'y' => 45],
            ['slot' => 'F1', 'label' => 'LW', 'x' => 25, 'y' => 15],
            ['slot' => 'F2', 'label' => 'CF', 'x' => 50, 'y' => 15],
            ['slot' => 'F3', 'label' => 'RW', 'x' => 75, 'y' => 15],
        ],
        '3-4-1' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 25, 'y' => 75],
            ['slot' => 'D2', 'label' => 'CB', 'x' => 50, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RB', 'x' => 75, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 20, 'y' => 45],
            ['slot' => 'M2', 'label' => 'LCM', 'x' => 40, 'y' => 45],
            ['slot' => 'M3', 'label' => 'RCM', 'x' => 60, 'y' => 45],
            ['slot' => 'M4', 'label' => 'RM', 'x' => 80, 'y' => 45],
            ['slot' => 'F1', 'label' => 'CF', 'x' => 50, 'y' => 15],
        ],
    ],
    '11v11' => [
        '4-3-3' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 20, 'y' => 75],
            ['slot' => 'D2', 'label' => 'LCB', 'x' => 40, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RCB', 'x' => 60, 'y' => 75],
            ['slot' => 'D4', 'label' => 'RB', 'x' => 80, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 25, 'y' => 45],
            ['slot' => 'M2', 'label' => 'CM', 'x' => 50, 'y' => 45],
            ['slot' => 'M3', 'label' => 'RM', 'x' => 75, 'y' => 45],
            ['slot' => 'F1', 'label' => 'LW', 'x' => 25, 'y' => 15],
            ['slot' => 'F2', 'label' => 'CF', 'x' => 50, 'y' => 15],
            ['slot' => 'F3', 'label' => 'RW', 'x' => 75, 'y' => 15],
        ],
        '4-4-2' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 20, 'y' => 75],
            ['slot' => 'D2', 'label' => 'LCB', 'x' => 40, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RCB', 'x' => 60, 'y' => 75],
            ['slot' => 'D4', 'label' => 'RB', 'x' => 80, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 20, 'y' => 45],
            ['slot' => 'M2', 'label' => 'LCM', 'x' => 40, 'y' => 45],
            ['slot' => 'M3', 'label' => 'RCM', 'x' => 60, 'y' => 45],
            ['slot' => 'M4', 'label' => 'RM', 'x' => 80, 'y' => 45],
            ['slot' => 'F1', 'label' => 'LF', 'x' => 33, 'y' => 15],
            ['slot' => 'F2', 'label' => 'RF', 'x' => 67, 'y' => 15],
        ],
        '4-2-3-1' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 20, 'y' => 75],
            ['slot' => 'D2', 'label' => 'LCB', 'x' => 40, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RCB', 'x' => 60, 'y' => 75],
            ['slot' => 'D4', 'label' => 'RB', 'x' => 80, 'y' => 75],
            ['slot' => 'DM1', 'label' => 'LDM', 'x' => 33, 'y' => 55],
            ['slot' => 'DM2', 'label' => 'RDM', 'x' => 67, 'y' => 55],
            ['slot' => 'AM1', 'label' => 'LAM', 'x' => 25, 'y' => 35],
            ['slot' => 'AM2', 'label' => 'AM', 'x' => 50, 'y' => 35],
            ['slot' => 'AM3', 'label' => 'RAM', 'x' => 75, 'y' => 35],
            ['slot' => 'F1', 'label' => 'CF', 'x' => 50, 'y' => 15],
        ],
        '3-5-2' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 25, 'y' => 75],
            ['slot' => 'D2', 'label' => 'CB', 'x' => 50, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RB', 'x' => 75, 'y' => 75],
            ['slot' => 'M1', 'label' => 'LM', 'x' => 17, 'y' => 45],
            ['slot' => 'M2', 'label' => 'LCM', 'x' => 33, 'y' => 45],
            ['slot' => 'M3', 'label' => 'CM', 'x' => 50, 'y' => 45],
            ['slot' => 'M4', 'label' => 'RCM', 'x' => 67, 'y' => 45],
            ['slot' => 'M5', 'label' => 'RM', 'x' => 83, 'y' => 45],
            ['slot' => 'F1', 'label' => 'LF', 'x' => 33, 'y' => 15],
            ['slot' => 'F2', 'label' => 'RF', 'x' => 67, 'y' => 15],
        ],
        '4-1-4-1' => [
            ['slot' => 'GK', 'label' => 'GK', 'x' => 50, 'y' => 92],
            ['slot' => 'D1', 'label' => 'LB', 'x' => 20, 'y' => 75],
            ['slot' => 'D2', 'label' => 'LCB', 'x' => 40, 'y' => 75],
            ['slot' => 'D3', 'label' => 'RCB', 'x' => 60, 'y' => 75],
            ['slot' => 'D4', 'label' => 'RB', 'x' => 80, 'y' => 75],
            ['slot' => 'DM1', 'label' => 'DM', 'x' => 50, 'y' => 55],
            ['slot' => 'AM1', 'label' => 'LAM', 'x' => 20, 'y' => 35],
            ['slot' => 'AM2', 'label' => 'LCAM', 'x' => 40, 'y' => 35],
            ['slot' => 'AM3', 'label' => 'RCAM', 'x' => 60, 'y' => 35],
            ['slot' => 'AM4', 'label' => 'RAM', 'x' => 80, 'y' => 35],
            ['slot' => 'F1', 'label' => 'CF', 'x' => 50, 'y' => 15],
        ],
    ],
];

/** Players on the field for a size — GK included where there is one. */
const TE_LINEUP_FIELD_PLAYERS = ['4v4' => 4, '7v7' => 7, '9v9' => 9, '11v11' => 11];

/** Formation names for a field size, default first. Empty for an unknown size. */
function te_lineup_formations_for(string $fieldSize): array
{
    return array_keys(TE_LINEUP_FORMATIONS[$fieldSize] ?? []);
}

function te_lineup_default_formation(string $fieldSize): ?string
{
    $names = te_lineup_formations_for($fieldSize);
    return $names[0] ?? null;
}

/** The slots of one preset, or null when the formation is not one for that size. */
function te_lineup_formation_slots(string $fieldSize, string $formation): ?array
{
    return TE_LINEUP_FORMATIONS[$fieldSize][$formation] ?? null;
}

function te_lineup_field_players(string $fieldSize): ?int
{
    return TE_LINEUP_FIELD_PLAYERS[$fieldSize] ?? null;
}

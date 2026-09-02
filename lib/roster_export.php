<?php
/**
 * Roster download — row building, kept out of the endpoint so it is testable
 * without HTTP.
 *
 * TWO FLAVOURS, one code path:
 *   'athletes' — the roster as the team page shows it, plus DOB and jersey number.
 *   'crew'     — the same rows widened with each athlete's crew (guardians):
 *                name, relationship, email, mobile phone.
 *
 * WHY THE CREW COLUMNS ARE DYNAMIC
 * A fixed "Guardian 1 / Guardian 2" pair silently drops the third contact on a
 * blended family, and a file that quietly omits a parent is worse than one with
 * an empty column: the coach has no way to know it happened. The header is built
 * from the widest athlete actually on the team, so nobody is dropped without the
 * column existing to show it.
 *
 * DATES ARE NEVER PARSED INTO A DATE OBJECT HERE
 * date_of_birth is a date-only value. Per the timezone rule in CLAUDE.md, a
 * date-only value must be read and written in the same zone — the safest form of
 * that is to not convert at all. The DOB is emitted as the stored YYYY-MM-DD and
 * the age is computed by comparing integer date parts, so this file has no
 * timezone behaviour to get wrong.
 */

/**
 * HARD CAPS on the generated file.
 *
 * A CSV is a download, so nothing about it is streamed back to the user to look
 * at — which is exactly why a cap here must never be silent. A file that stops
 * at row 1000 and says nothing is indistinguishable from a team that has 1000
 * players, and the coach finds out at the tournament. te_roster_export_sheet()
 * therefore reports what it dropped, the endpoint puts it in a response header
 * and the audit row, and the UI tells the person who pressed the button.
 *
 * 25 columns leaves room for 4 crew groups (7 + 4x4 = 23). Provisional numbers,
 * agreed 2026-08-25 — raise them here and the whole path follows.
 */
const TE_ROSTER_EXPORT_MAX_ROWS    = 1000;
const TE_ROSTER_EXPORT_MAX_COLUMNS = 25;

/** How many crew column-groups fit inside the column cap. */
function te_roster_export_max_crew_groups(): int
{
    return (int) floor((TE_ROSTER_EXPORT_MAX_COLUMNS - 7) / 4);
}

/** Age in whole years from two YYYY-MM-DD strings. NULL if the DOB is unusable. */
function te_roster_age(?string $dob, string $today): ?int
{
    if ($dob === null
        || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dob, $b)
        || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $today, $t)) {
        return null;
    }

    $age = (int) $t[1] - (int) $b[1];
    // Birthday not yet reached this year.
    if ((int) $t[2] < (int) $b[2]
        || ((int) $t[2] === (int) $b[2] && (int) $t[3] < (int) $b[3])) {
        $age--;
    }

    return $age >= 0 ? $age : null;
}

/**
 * The team's player rows, in the order the team page lists them.
 *
 * Soft-deleted athletes are excluded — they are gone from every other staff
 * surface and must not reappear in a file. `active_status` is deliberately NOT
 * filtered: it is a separate flag from deletion and filtering on it would drop
 * players whose value is NULL, silently shortening the roster.
 *
 * Every membership status (active / injured / suspended / inactive) is included
 * and reported in its own column — an injured player is still on the roster, and
 * a coach printing a sideline sheet needs to see them.
 */
function te_roster_export_players(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare("
        SELECT tm.athlete_id,
               tm.jersey_number,
               tm.primary_position,
               tm.status,
               a.first_name,
               a.last_name,
               a.date_of_birth
        FROM team_members tm
        JOIN athletes a ON a.id = tm.athlete_id
        WHERE tm.team_id = ?
          AND (tm.role = 'player' OR tm.role IS NULL)
          AND tm.leave_date IS NULL
          AND a.deleted_at IS NULL
        ORDER BY a.last_name, a.first_name
    ");
    $stmt->execute([$teamId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Crew for a set of athletes, keyed by athlete_id, primary contact first.
 *
 * One query for the whole team rather than one per athlete: a 25-player roster
 * would otherwise be 26 round trips to Neon for a button a coach presses on a
 * phone at a field.
 */
function te_roster_export_crew(PDO $pdo, array $athleteIds): array
{
    if (empty($athleteIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($athleteIds), '?'));
    $stmt = $pdo->prepare("
        SELECT ag.athlete_id,
               ag.relationship,
               g.first_name,
               g.last_name,
               g.email,
               g.mobile_phone
        FROM athlete_guardians ag
        JOIN guardians g ON g.id = ag.guardian_id
        WHERE ag.athlete_id IN ({$ph})
        -- Crew members are equal (2026-09-02): no primary leads a family's
        -- columns. Link id first so the column order is stable run to run.
        ORDER BY ag.athlete_id, ag.id, g.last_name, g.id
    ");
    $stmt->execute(array_values($athleteIds));

    $byAthlete = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byAthlete[(int) $row['athlete_id']][] = $row;
    }

    return $byAthlete;
}

/**
 * Build the full sheet.
 *
 * @param string $flavour 'athletes' or 'crew'
 * @return array{headers: string[], rows: array<int, array<int, string>>}
 */
function te_roster_export_sheet(PDO $pdo, int $teamId, string $flavour, string $today): array
{
    $players = te_roster_export_players($pdo, $teamId);
    $withCrew = ($flavour === 'crew');

    $totalPlayers = count($players);
    $omittedRows = max(0, $totalPlayers - TE_ROSTER_EXPORT_MAX_ROWS);
    if ($omittedRows > 0) {
        $players = array_slice($players, 0, TE_ROSTER_EXPORT_MAX_ROWS);
    }

    $crew = [];
    $widestFamily = 0;
    $maxCrew = 0;
    if ($withCrew) {
        $ids = array_map(static fn($p) => (int) $p['athlete_id'], $players);
        $crew = te_roster_export_crew($pdo, $ids);
        foreach ($crew as $list) {
            $widestFamily = max($widestFamily, count($list));
        }
        $maxCrew = min($widestFamily, te_roster_export_max_crew_groups());
    }
    $omittedCrewPerAthlete = max(0, $widestFamily - $maxCrew);

    $headers = ['Jersey #', 'Last Name', 'First Name', 'Date of Birth', 'Age', 'Position', 'Status'];
    for ($i = 1; $i <= $maxCrew; $i++) {
        $headers[] = "Crew {$i} Name";
        $headers[] = "Crew {$i} Relationship";
        $headers[] = "Crew {$i} Email";
        $headers[] = "Crew {$i} Phone";
    }

    $rows = [];
    foreach ($players as $p) {
        $dob = $p['date_of_birth'] !== null ? substr((string) $p['date_of_birth'], 0, 10) : '';
        $age = te_roster_age($dob !== '' ? $dob : null, $today);

        $row = [
            $p['jersey_number'] !== null ? (string) $p['jersey_number'] : '',
            (string) ($p['last_name'] ?? ''),
            (string) ($p['first_name'] ?? ''),
            $dob,
            $age !== null ? (string) $age : '',
            (string) ($p['primary_position'] ?? ''),
            (string) ($p['status'] ?? ''),
        ];

        if ($withCrew) {
            $list = $crew[(int) $p['athlete_id']] ?? [];
            for ($i = 0; $i < $maxCrew; $i++) {
                $c = $list[$i] ?? null;
                $name = $c ? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) : '';
                $row[] = $name;
                $row[] = $c ? (string) ($c['relationship'] ?? '') : '';
                $row[] = $c ? (string) ($c['email'] ?? '') : '';
                $row[] = $c ? (string) ($c['mobile_phone'] ?? '') : '';
            }
        }

        $rows[] = $row;
    }

    return [
        'headers'              => $headers,
        'rows'                 => $rows,
        'total_players'        => $totalPlayers,
        'omitted_rows'         => $omittedRows,
        // Crew members per athlete that did not fit inside the column cap.
        'omitted_crew_columns' => $omittedCrewPerAthlete,
    ];
}

/**
 * One sentence describing what the cap dropped, or NULL if the file is complete.
 * Shared by the response header, the audit row and the UI so all three say the
 * same thing.
 */
function te_roster_export_truncation_notice(array $sheet): ?string
{
    $parts = [];
    if (($sheet['omitted_rows'] ?? 0) > 0) {
        $parts[] = sprintf(
            '%d of %d players were left out (the file is capped at %d rows)',
            $sheet['omitted_rows'],
            $sheet['total_players'],
            TE_ROSTER_EXPORT_MAX_ROWS
        );
    }
    if (($sheet['omitted_crew_columns'] ?? 0) > 0) {
        $parts[] = sprintf(
            'up to %d crew member(s) per athlete were left out (the file is capped at %d columns)',
            $sheet['omitted_crew_columns'],
            TE_ROSTER_EXPORT_MAX_COLUMNS
        );
    }

    return $parts ? ucfirst(implode('; and ', $parts)) . '.' : null;
}

/**
 * Download filename.
 *
 * Everything outside [A-Za-z0-9._-] is collapsed to a hyphen. That is not
 * cosmetic: the team name is club-supplied text and it is being interpolated
 * into a Content-Disposition header, where a newline would let a club's team
 * name inject a response header.
 */
function te_roster_export_filename(string $teamName, string $flavour, string $today): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $teamName);
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
        $slug = 'team';
    }
    $suffix = ($flavour === 'crew') ? 'roster-and-crew' : 'roster';

    return "{$slug}-{$suffix}-{$today}.csv";
}

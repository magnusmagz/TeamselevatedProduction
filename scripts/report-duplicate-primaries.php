<?php
/**
 * Athletes with more than one primary crew member — and athletes with none.
 *
 * `athlete_guardians.is_primary` has no uniqueness constraint, and until
 * 2026-09-02 two writers set it to a literal TRUE on every link they created:
 * `registration/registrations-api.php` (a family registering again) and
 * `api/athletes.php`. So an athlete could accumulate several primaries, at which
 * point "who is the primary contact" had no answer — the athlete detail fetch
 * ordered by `is_primary DESC` with no tiebreak, and AthleteForm wrote whichever
 * row came back first straight back as THE primary. That is what reverted a
 * promotion made in the Crew modal (R78).
 *
 * The write paths are fixed. This reports the rows the old behaviour left behind,
 * WITHOUT touching them: which link should be primary in a two-parent household
 * is the club's call, not a migration's. Take the list to the club, then set the
 * right one in the Crew modal (which demotes the others correctly).
 *
 * NULL is reported alongside FALSE. A DESC sort puts NULLs first in Postgres, so
 * a NULL flag used to outrank a real primary — the ordering now says NULLS LAST,
 * but a NULL is still a link nobody ever decided about.
 *
 * Read-only. Issues no UPDATE, no INSERT, no DELETE.
 *
 *   php scripts/report-duplicate-primaries.php              # all clubs
 *   php scripts/report-duplicate-primaries.php --club=51
 *   php scripts/report-duplicate-primaries.php --issue=none # athletes with no primary
 *   php scripts/report-duplicate-primaries.php --include-deleted
 */

require_once __DIR__ . '/../config/env.php';

$club            = null;
$issue           = 'all';   // all | duplicate | none | null-flag
$includeDeleted  = in_array('--include-deleted', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--club='))  { $club  = (int) substr($a, 7); }
    if (str_starts_with($a, '--issue=')) { $issue = substr($a, 8); }
}

$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        Env::get('DB_HOST'), Env::get('DB_PORT', '5432'), Env::get('DB_NAME')
    ),
    Env::get('DB_USER'), Env::get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$where  = [];
$params = [];
if (!$includeDeleted) {
    $where[] = 'a.deleted_at IS NULL';
}
if ($club !== null) {
    $where[]  = 'a.club_id = ?';
    $params[] = $club;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// One row per athlete, with the counts that decide which bucket they land in.
$sql = "
    SELECT a.id            AS athlete_id,
           a.club_id,
           a.first_name || ' ' || a.last_name AS athlete_name,
           a.deleted_at,
           count(ag.id)                                          AS links,
           count(*) FILTER (WHERE ag.is_primary IS TRUE)          AS primaries,
           count(*) FILTER (WHERE ag.is_primary IS NULL)          AS null_flags
    FROM athletes a
    JOIN athlete_guardians ag ON ag.athlete_id = a.id
    $whereSql
    GROUP BY a.id, a.club_id, a.first_name, a.last_name, a.deleted_at
    ORDER BY count(*) FILTER (WHERE ag.is_primary IS TRUE) DESC, a.club_id, a.id
";

$rows = $pdo->prepare($sql);
$rows->execute($params);
$athletes = $rows->fetchAll(PDO::FETCH_ASSOC);

// The crew behind one athlete, in the order the athlete detail fetch now uses.
$crewStmt = $pdo->prepare("
    SELECT ag.id AS link_id,
           ag.is_primary,
           ag.relationship,
           ag.created_at,
           g.first_name || ' ' || g.last_name AS name,
           coalesce(nullif(btrim(g.email), ''), '(no email)') AS email
    FROM athlete_guardians ag
    JOIN guardians g ON g.id = ag.guardian_id
    WHERE ag.athlete_id = ?
    ORDER BY ag.is_primary DESC NULLS LAST, ag.id
");

$buckets = ['duplicate' => [], 'none' => [], 'null-flag' => []];
foreach ($athletes as $row) {
    if ((int) $row['primaries'] > 1) {
        $buckets['duplicate'][] = $row;
    } elseif ((int) $row['primaries'] === 0) {
        $buckets['none'][] = $row;
    }
    if ((int) $row['null_flags'] > 0) {
        $buckets['null-flag'][] = $row;
    }
}

$titles = [
    'duplicate' => 'MORE THAN ONE PRIMARY — "who is primary" has no answer',
    'none'      => 'NO PRIMARY AT ALL — the athlete list shows no primary guardian',
    'null-flag' => 'NULL is_primary — a link nobody ever decided about',
];

$scope = $club !== null ? "club $club" : 'all clubs';
printf("Primary crew member audit — %s, %d athletes with crew%s\n\n",
    $scope, count($athletes), $includeDeleted ? ' (including soft-deleted)' : '');

foreach ($titles as $key => $title) {
    if ($issue !== 'all' && $issue !== $key) {
        continue;
    }

    $list = $buckets[$key];
    printf("== %s: %d athlete%s ==\n", $title, count($list), count($list) === 1 ? '' : 's');

    foreach ($list as $row) {
        printf(
            "\n  athlete %-6d club %-5s %s%s\n",
            $row['athlete_id'],
            $row['club_id'] ?? '-',
            $row['athlete_name'],
            $row['deleted_at'] ? '  [soft-deleted]' : ''
        );
        $crewStmt->execute([$row['athlete_id']]);
        foreach ($crewStmt->fetchAll(PDO::FETCH_ASSOC) as $crew) {
            $flag = $crew['is_primary'] === null
                ? 'NULL '
                : ($crew['is_primary'] ? 'PRIM ' : '  -  ');
            printf(
                "      %s link %-6d %-28s %-34s %-16s %s\n",
                $flag,
                $crew['link_id'],
                $crew['name'],
                $crew['email'],
                $crew['relationship'] ?? '-',
                $crew['created_at'] ?? ''
            );
        }
    }
    echo "\n";
}

echo "Nothing above was changed. Set the right primary in the Crew modal — its PUT\n";
echo "demotes the others in the same transaction.\n";

<?php
/**
 * Crew (guardian) lists for a set of athletes.
 *
 * ⚠️ THERE IS NO PRIMARY GUARDIAN IN THIS PRODUCT (product rule, 2026-09-02).
 * Crew members are equal. `athlete_guardians.is_primary` still exists in Neon —
 * the schema is additive-only, so the column stays — but nothing reads it to
 * decide who represents a family and nothing writes it. Anything that needs "the
 * family's contact" wants ALL of them, which is what this returns.
 *
 * One query for a page of athletes rather than one per athlete: the athlete list
 * runs to a few hundred rows for a club, and N+1 round trips to Neon is what the
 * LATERAL join in legacy/athletes-gateway.php exists to avoid.
 *
 * Ordering is `ag.id` — the link id. It is deterministic and independent of
 * physical row order, which a vacuum can change. It carries no meaning beyond
 * "attached earlier"; do not present it as a ranking.
 */

/**
 * @param int[] $athleteIds
 * @return array<int, array<int, array{guardian_id:int, first_name:string,
 *         last_name:string, name:string, email:string, mobile_phone:?string,
 *         relationship:?string}>> keyed by athlete id, in link-id order.
 */
function te_crew_for_athletes(PDO $pdo, array $athleteIds): array
{
    $ids = [];
    foreach ($athleteIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (empty($ids)) {
        return [];
    }

    $ids = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT ag.athlete_id,
               ag.id AS link_id,
               ag.relationship,
               g.id AS guardian_id,
               g.first_name,
               g.last_name,
               g.email,
               g.mobile_phone
        FROM athlete_guardians ag
        JOIN guardians g ON g.id = ag.guardian_id
        WHERE ag.athlete_id IN ({$placeholders})
        ORDER BY ag.athlete_id, ag.id
    ");
    $stmt->execute($ids);

    $byAthlete = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $first = trim((string) ($row['first_name'] ?? ''));
        $last  = trim((string) ($row['last_name'] ?? ''));
        $byAthlete[(int) $row['athlete_id']][] = [
            'guardian_id'  => (int) $row['guardian_id'],
            'link_id'      => (int) $row['link_id'],
            'first_name'   => $first,
            'last_name'    => $last,
            'name'         => trim($first . ' ' . $last),
            'email'        => (string) ($row['email'] ?? ''),
            'mobile_phone' => $row['mobile_phone'] ?? null,
            'relationship' => $row['relationship'] ?? null,
        ];
    }

    return $byAthlete;
}

/**
 * Attach a `guardians` array to every row of an athlete list, in place.
 *
 * Every athlete gets the key, including those with no crew — an absent key and an
 * empty list read the same in JavaScript but not in a test, and "no crew on file"
 * is a real answer the UI must be able to show.
 *
 * @param array<int, array<string, mixed>> $athletes rows carrying an `id`
 */
function te_attach_crew_to_athletes(PDO $pdo, array &$athletes, string $idKey = 'id'): void
{
    if (empty($athletes)) {
        return;
    }

    $crew = te_crew_for_athletes($pdo, array_column($athletes, $idKey));

    foreach ($athletes as &$athlete) {
        $athlete['guardians'] = $crew[(int) ($athlete[$idKey] ?? 0)] ?? [];
    }
    unset($athlete);
}

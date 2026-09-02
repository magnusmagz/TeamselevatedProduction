<?php
/**
 * Document scope — who may read a document set, and whose targets a document
 * may be assigned to.
 *
 * Extracted out of `api/documents-gateway.php` so it can be tested at all: the
 * gateway's handler names (`handleDelete`, `handleCreate`, …) collide with every
 * other gateway in the tree, so requiring it from a test fatals on redeclare.
 * Same reason `lib/coach_scope.php`, `lib/club_standing.php`,
 * `lib/team_roster_scope.php` and `lib/event_standing.php` exist.
 *
 * Three predicates live here and they are NOT interchangeable — the recurring
 * shape in this codebase is that the predicate was right and the CALL SITE
 * picked the wrong one:
 *
 *   te_document_user_can_read()                    one document, by assignment
 *   te_document_user_can_read_athlete_docs()       one athlete's cascade
 *   te_document_user_can_read_target_docs()        everything on one target
 *
 * Club MEMBERSHIP (`$auth->canAccessClub()`) is none of them. It is true for any
 * role scoped to the club, `parent` included, and both `action=for-target` and
 * `action=expiring` used it — so a parent could enumerate the club's documents,
 * file URLs and all. Use `te_is_club_staff` / `te_is_club_admin` from
 * `lib/club_standing.php`, or one of the three above.
 */

require_once __DIR__ . '/club_standing.php';
require_once __DIR__ . '/AthleteScope.php';
require_once __DIR__ . '/guardian_identity.php';

/**
 * Raised when an assignment batch names a target that does not belong to the
 * document's club. Its own type so the handlers can answer 422 — a caller
 * pointing at another club's team is a bad request, not a server fault.
 */
class DocumentTargetScopeException extends Exception {
    /** @param string[] $foreignTargets human-readable "team 7" style labels */
    public array $foreignTargets;

    public function __construct(array $foreignTargets) {
        $this->foreignTargets = $foreignTargets;
        parent::__construct(
            'These assignment targets are not in this document\'s club: '
            . implode(', ', $foreignTargets)
        );
    }
}

/**
 * Does this target belong to $clubId?
 *
 * Every caller already knows the document's club; nothing checked that the
 * TARGETS shared it, so a club admin could assign their own document to another
 * club's team, athlete or user — and `listDocumentsForCascade` then served it to
 * that club's families, because it matches assignments by target id and the
 * document's own club never enters the target comparison.
 */
function te_document_target_is_in_club(PDO $conn, string $type, int $tid, int $clubId): bool {
    if ($type === 'club') {
        return $tid === $clubId;
    }
    if ($type === 'team') {
        $stmt = $conn->prepare("SELECT 1 FROM teams WHERE id = ? AND club_id = ? LIMIT 1");
        $stmt->execute([$tid, $clubId]);
        return (bool) $stmt->fetchColumn();
    }
    if ($type === 'athlete') {
        // An athlete's club is athletes.club_id UNION their teams' clubs — a
        // registered athlete with no team yet has only the first.
        return in_array($clubId, AthleteScope::athleteClubIds($conn, $tid), true);
    }
    if ($type === 'user') {
        // An ACTIVE, unrevoked role in the club. `active` and `revoked_at` can
        // disagree and the revocation is the newer fact (see lib/JWT.php).
        $stmt = $conn->prepare("
            SELECT 1 FROM user_club_access
            WHERE user_id = ? AND club_profile_id = ?
              AND active = TRUE AND revoked_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$tid, $clubId]);
        return (bool) $stmt->fetchColumn();
    }
    return false;
}

/**
 * @throws DocumentTargetScopeException if ANY target is outside $clubId. The
 *         batch is refused whole rather than partially applied: a half-written
 *         assignment set is harder to notice than a rejection.
 */
function te_document_insert_assignments(PDO $conn, int $docId, array $targets, int $assignedBy, int $clubId): void {
    $clean = [];
    $foreign = [];
    foreach ($targets as $t) {
        $type = $t['target_type'] ?? null;
        $tid = (int) ($t['target_id'] ?? 0);
        if (!in_array($type, ['club', 'team', 'athlete', 'user'], true) || $tid <= 0) {
            continue;
        }
        if (!te_document_target_is_in_club($conn, $type, $tid, $clubId)) {
            $foreign[] = "$type $tid";
            continue;
        }
        $clean[] = [$type, $tid];
    }
    if (!empty($foreign)) {
        throw new DocumentTargetScopeException($foreign);
    }

    $stmt = $conn->prepare("
        INSERT INTO document_assignments (document_id, target_type, target_id, assigned_by)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (document_id, target_type, target_id) DO NOTHING
    ");
    foreach ($clean as [$type, $tid]) {
        $stmt->execute([$docId, $type, $tid, $assignedBy]);
    }
}

function te_document_user_can_read(PDO $conn, $auth, array $doc): bool {
    $clubId = (int) $doc['club_profile_id'];
    if (te_is_club_admin($auth, $clubId)) {
        return true;
    }
    $userId = (int) $auth->getUserId();
    $stmt = $conn->prepare("
        SELECT 1 FROM document_assignments da
        WHERE da.document_id = ?
          AND (
            (da.target_type = 'club' AND da.target_id = ? AND (
                  EXISTS (SELECT 1 FROM user_club_access uca
                          WHERE uca.user_id = ? AND uca.club_profile_id = ?
                            AND uca.active = TRUE AND uca.revoked_at IS NULL)
                  OR EXISTS (SELECT 1 FROM athlete_guardians ag2
                             JOIN guardians g2 ON g2.id = ag2.guardian_id
                             JOIN users u2 ON " . te_guardian_link_sql('u2', 'g2') . "
                             JOIN athletes a2 ON a2.id = ag2.athlete_id
                             WHERE u2.id = ? AND a2.club_id = ?)
            ))
            OR (da.target_type = 'user' AND da.target_id = ?)
            OR (da.target_type = 'team' AND da.target_id IN (
                  SELECT t.id FROM teams t
                  LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
                  WHERE t.primary_coach_id = ? OR tm.id IS NOT NULL
            ))
            OR (da.target_type = 'athlete' AND da.target_id IN (
                  SELECT ag.athlete_id FROM athlete_guardians ag
                  JOIN guardians g ON g.id = ag.guardian_id
                  JOIN users u ON " . te_guardian_link_sql('u', 'g') . "
                  WHERE u.id = ?
            ))
          )
        LIMIT 1
    ");
    // A club-wide document is for the club's MEMBERS: staff via user_club_access, families
    // via the guardian chain. Without that clause any signed-in user of any club could read
    // it by id (found 2026-09-02 while tightening for-target).
    $stmt->execute([$doc['id'], $clubId, $userId, $clubId, $userId, $clubId, $userId, $userId, $userId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function te_document_user_can_read_athlete_docs(PDO $conn, $auth, int $athleteId, int $clubId, array $teamIds): bool {
    if (te_is_club_admin($auth, $clubId)) return true;
    $userId = (int) $auth->getUserId();

    if (!empty($teamIds)) {
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $conn->prepare("
            SELECT 1 FROM teams t
            LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
            WHERE t.id IN ($ph) AND (t.primary_coach_id = ? OR tm.id IS NOT NULL)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$userId], $teamIds, [$userId]));
        if ($stmt->fetchColumn()) return true;
    }

    // Guardian standing is resolved in one place — recorded links UNION the email
    // match. This join used to be `u.email = g.email`, case-sensitive, so one capital
    // letter on a guardian row hid a parent's own child's documents from them.
    return te_user_is_guardian_of_athlete($conn, $userId, $athleteId);
}

/**
 * May this user list everything assigned to one target?
 *
 * `action=for-target` gated on `canAccessClub()` — club MEMBERSHIP, which a
 * parent satisfies — so any signed-in club member could read the document set
 * of any team, athlete or coach in their club by walking target ids. The answer
 * has to be the one `te_document_user_can_read` gives, resolved per target_type.
 */
function te_document_user_can_read_target_docs(PDO $conn, $auth, string $type, int $tid, int $clubId): bool {
    if (te_is_club_admin($auth, $clubId)) {
        return true;
    }
    $userId = (int) $auth->getUserId();

    if ($type === 'club') {
        // The club-wide set is club-wide staff data; the admin branch above is
        // the only way in.
        return false;
    }
    if ($type === 'user') {
        return $tid === $userId;
    }
    if ($type === 'athlete') {
        return te_document_user_can_read_athlete_docs($conn, $auth, $tid, $clubId, te_document_athlete_team_ids($conn, $tid));
    }
    if ($type === 'team') {
        $stmt = $conn->prepare("
            SELECT 1 FROM teams t
            LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
            WHERE t.id = ? AND (t.primary_coach_id = ? OR tm.id IS NOT NULL)
            LIMIT 1
        ");
        $stmt->execute([$userId, $tid, $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
        // A guardian of an athlete on the team reads that team's documents —
        // the cascade `handleForAthlete` already gives them, reachable here by
        // team id instead of by child.
        $stmt = $conn->prepare("SELECT DISTINCT athlete_id FROM team_members WHERE team_id = ? AND athlete_id IS NOT NULL");
        $stmt->execute([$tid]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $athleteId) {
            if (te_user_is_guardian_of_athlete($conn, $userId, (int) $athleteId)) {
                return true;
            }
        }
    }
    return false;
}

/** Team ids an athlete is rostered on. */
function te_document_athlete_team_ids(PDO $conn, int $athleteId): array {
    $stmt = $conn->prepare("SELECT DISTINCT team_id FROM team_members WHERE athlete_id = ?");
    $stmt->execute([$athleteId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

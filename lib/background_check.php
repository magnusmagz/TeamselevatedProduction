<?php
/**
 * Volunteer background-check standing — one predicate, two callers.
 *
 * Moved out of api/volunteer-gateway.php on 2026-09-02 so controllers/TeamController.php
 * could apply the same gate. Until then the gateway's direct-assignment path blocked a
 * non-cleared volunteer and the controller's POST /api/teams/{id}/volunteers did not —
 * it took `background_check_status` from the request body and defaulted it to 'pending'
 * (roadmap R4, "volunteer assignment bypasses background-check block").
 *
 * Answers 'cleared' | 'expired' | 'pending' | 'none'. A volunteer may be assigned to a
 * team ONLY on 'cleared'. The guardian branch exists because parents' checks are tracked
 * on their guardian row; the email join is LOWER() on both sides per the guardian-email
 * rule.
 *
 * ⚠️ 'expired' IS NEW (GOTR G3, 2026-09-02) AND IT IS NOT 'cleared'.
 * `team_volunteers` stores a status per (team, user) with no expiry the predicate ever
 * read, and the old query was "any cleared row wins" — so a coach cleared on a team they
 * left in 2023 was cleared everywhere, forever. Person-level `person_credentials` rows
 * carry a computed `expires_at`, and a lapsed one now answers 'expired'. Both callers
 * already refuse anything but 'cleared', so this tightens the gate rather than opening
 * it, and the volunteer sees the real reason instead of a generic refusal.
 *
 * ⚠️ CREDENTIALS ARE READ FIRST, AND ONLY FALL BACK WHEN THERE IS NO ROW AT ALL.
 * A credential is a deliberate statement by an admin about this person; a
 * `team_volunteers` row is a per-team artefact of an assignment made at some point in
 * the past. When both exist the newer model wins, INCLUDING when it says 'rejected' —
 * otherwise marking someone rejected would leave them cleared through a stale row on a
 * team they no longer coach, which is exactly the failure this replaces.
 *
 * The credential read is behind TE_FEATURE_COMPLIANCE. This is a live child-safety gate,
 * so the new source of truth needs a switch that restores the old behaviour in one config
 * flip rather than a deploy.
 */

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/feature_flags.php';

function te_background_check_status(PDO $pdo, int $userId): string
{
    $fromCredentials = te_background_check_status_from_credentials($pdo, $userId);
    if ($fromCredentials !== null) {
        return $fromCredentials;
    }

    $stmt = $pdo->prepare("
        SELECT background_check_status FROM team_volunteers
        WHERE user_id = ? AND background_check_status = 'cleared'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'cleared';
    }

    $stmt = $pdo->prepare("
        SELECT g.background_check_status FROM guardians g
        JOIN users u ON LOWER(u.email) = LOWER(g.email)
        WHERE u.id = ? AND g.background_check_status = 'cleared'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'cleared';
    }

    $stmt = $pdo->prepare("
        SELECT background_check_status FROM team_volunteers
        WHERE user_id = ? AND background_check_status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'pending';
    }

    return 'none';
}

/**
 * The person-level answer, or NULL meaning "no credential row exists, use the old data".
 *
 * NULL and 'none' are different answers and conflating them is the bug to avoid: 'none'
 * asserts we looked and this person has nothing, which would stop the fallback from ever
 * running during the migration window.
 *
 * The requirements considered are those of kind='background_check' inherited by any club
 * the person holds a role in — te_compliance_requirements_for_club already resolves the
 * national/division/council chain, so a council that adds a state check on top of the
 * national one is covered without this file knowing the tree exists.
 *
 * Precedence is cleared > expired > pending. A person with two clubs' checks, one current
 * and one lapsed, is cleared: they hold a valid check. A 'rejected' or 'missing' row with
 * nothing better yields 'none' — a recorded refusal, not a reason to consult stale data.
 */
function te_background_check_status_from_credentials(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0 || !te_feature_enabled('COMPLIANCE') || !te_compliance_tables_present($pdo)) {
        return null;
    }

    $requirementIds = [];
    foreach (te_compliance_user_club_ids($pdo, $userId) as $clubId) {
        foreach (te_compliance_requirements_for_club($pdo, $clubId) as $req) {
            if ($req['kind'] === 'background_check' && !in_array($req['id'], $requirementIds, true)) {
                $requirementIds[] = $req['id'];
            }
        }
    }
    if (!$requirementIds) {
        return null;
    }

    try {
        $marks = implode(', ', array_fill(0, count($requirementIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT status, expires_at FROM person_credentials
              WHERE user_id = ? AND requirement_id IN ($marks)"
        );
        $stmt->execute(array_merge([$userId], $requirementIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('te_background_check_status_from_credentials: ' . $e->getMessage());
        return null;
    }
    if (!$rows) {
        return null;
    }

    $best = 'none';
    foreach ($rows as $row) {
        $status = strtolower(trim((string) $row['status']));
        $expiresAt = te_compliance_date_or_null($row['expires_at'] ?? null);
        $days = te_compliance_days_to($expiresAt);

        // Computed, not trusted: the nightly sweep may not have run, and a gate
        // must not clear somebody on the morning their check lapsed.
        if ($status === 'verified' && $days !== null && $days < 0) {
            $status = 'expired';
        }

        if ($status === 'verified') {
            return 'cleared';
        }
        if ($status === 'expired') {
            $best = 'expired';
        } elseif ($status === 'submitted' && $best !== 'expired') {
            $best = 'pending';
        }
    }
    return $best;
}

function te_background_check_cleared(PDO $pdo, int $userId): bool
{
    return te_background_check_status($pdo, $userId) === 'cleared';
}

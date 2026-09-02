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
 * Answers 'cleared' | 'pending' | 'none'. A volunteer may be assigned to a team ONLY on
 * 'cleared'. The guardian branch exists because parents' checks are tracked on their
 * guardian row; the email join is LOWER() on both sides per the guardian-email rule.
 */

function te_background_check_status(PDO $pdo, int $userId): string
{
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

function te_background_check_cleared(PDO $pdo, int $userId): bool
{
    return te_background_check_status($pdo, $userId) === 'cleared';
}

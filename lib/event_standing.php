<?php
/**
 * Staff standing on a calendar event — one predicate for every attendee-level read.
 *
 * Added 2026-09-02 (roadmap R81, "hide RSVPs from other parents"). The report blamed
 * calendar-events-gateway, which was already scoped. The actual surfaces were:
 *   - api/event-attendance.php  get / save / athlete-history: requireAuth() and then
 *     nothing — $auth was assigned and never read. Any signed-in user, a parent on the
 *     staff calendar included, could read every family's attendance on any event and
 *     rewrite it.
 *   - api/rsvp-webhook.php?action=status: no auth at all. Every attendee's name, EMAIL
 *     and RSVP status for any event id, to anyone on the internet.
 *
 * Answers true for: super admin, club_admin of the event's club, or coach of ANY team
 * on the event (calendar_event_teams ∩ the caller's coach teams). Null when the event
 * does not exist — a caller must 404, not treat that as false.
 *
 * Coach teams come from AthleteScope::coachTeamIdsForUser (the one implementation);
 * never re-derive them here.
 */

require_once __DIR__ . '/AthleteScope.php';

function te_event_staff_standing(PDO $pdo, AuthMiddleware $auth, int $eventId): ?bool
{
    $stmt = $pdo->prepare("SELECT club_id FROM calendar_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    if ($auth->isSuperAdmin()) {
        return true;
    }

    if ($row['club_id'] !== null && $auth->hasRole('club_admin', (int)$row['club_id'], 'club')) {
        return true;
    }

    $uid = (int)$auth->getUserId();
    if ($uid <= 0) {
        return false;
    }
    $coachTeams = AthleteScope::coachTeamIdsForUser($pdo, $uid);
    if (empty($coachTeams)) {
        return false;
    }

    $marks = implode(',', array_fill(0, count($coachTeams), '?'));
    $stmt = $pdo->prepare("SELECT 1 FROM calendar_event_teams WHERE event_id = ? AND team_id IN ($marks) LIMIT 1");
    $stmt->execute(array_merge([$eventId], array_values($coachTeams)));
    return (bool)$stmt->fetchColumn();
}

/** 404 / 403 and exit unless the caller has staff standing on the event. */
function te_require_event_staff(PDO $pdo, AuthMiddleware $auth, $eventId): int
{
    $eventId = (int)$eventId;
    $standing = $eventId > 0 ? te_event_staff_standing($pdo, $auth, $eventId) : null;
    if ($standing === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Event not found']);
        exit;
    }
    if ($standing === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only staff of this event\'s teams can view attendance']);
        exit;
    }
    return $eventId;
}

<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/role_cache.php';
require_once __DIR__ . '/../lib/pagination.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Every action on this gateway requires an authenticated user. Coaches are
// tenant data — never serve them (or let them be mutated) without a valid token
// AND a club scope. getAccessibleClubIds() returns the caller's club IDs, or
// null for super admins (who may see/act across all clubs).
$auth = AuthMiddleware::requireAuth();
$accessibleClubs = $auth->getAccessibleClubIds();

// Authenticated but scoped to no club (and not a super admin): nothing is visible.
$hasNoClubScope = ($accessibleClubs !== null && empty($accessibleClubs));

$action = $_GET['action'] ?? 'available';

try {
    switch ($action) {
        case 'available':
            // Get coaches the caller is allowed to see, scoped to their club(s).
            // A "coach of a club" = has a user_club_access coach role in the club,
            // OR is the primary_coach of a (non-deleted) team in the club. This
            // mirrors how JWT.php derives club-scoped coach roles.
            if ($hasNoClubScope) {
                // Shape note: see the response at the end of this branch. An
                // empty page still carries `page`, so a client never has to
                // guess whether a short list is the whole list.
                echo json_encode([
                    'success' => true,
                    'coaches' => [],
                    'page' => ['limit' => te_page_limit($_GET['limit'] ?? null), 'next_cursor' => null, 'truncated' => false],
                ]);
                break;
            }

            $params = [];
            if ($accessibleClubs === null) {
                // Super admin: no club filter.
                $teamClubFilter = '';
                $ucaClubFilter = '';
            } else {
                // CLUB ids: the caller's own clubs, a handful of values that came
                // out of the token. Deliberately NOT a subquery — allowlisted in
                // tests/php/NoScopeIdListsTest.php with the rest of the club-id
                // sites. Athlete and team id lists are the ones that do not scale.
                $ph = implode(',', array_fill(0, count($accessibleClubs), '?'));
                $teamClubFilter = "AND t.club_id IN ($ph)";
                $ucaClubFilter  = "AND uca.club_profile_id IN ($ph)";
                // Placeholders appear in this order in the SQL below:
                //   1) teams LEFT JOIN club filter, 2) uca EXISTS club filter.
                $params = array_merge($accessibleClubs, $accessibleClubs);
            }

            // Same portal-status columns the Crew page uses, so "has this person
            // actually signed in" has one answer across both screens.
            require_once __DIR__ . '/../lib/portal_status.php';

            $sql = "
                SELECT u.id, u.first_name, u.last_name, u.email,
                       COUNT(DISTINCT t.id) AS team_count,
                       " . te_portal_status_columns('u.email', 'u', 'coach_invite') . "
                FROM users u
                LEFT JOIN teams t
                       ON (
                            t.primary_coach_id = u.id
                            OR EXISTS (
                                SELECT 1 FROM team_members tm
                                WHERE tm.team_id = t.id AND tm.user_id = u.id
                                  AND tm.role IN ('assistant_coach', 'team_manager')
                                  AND tm.status = 'active'
                            )
                          )
                      AND t.deleted_at IS NULL
                      $teamClubFilter
                WHERE (
                    EXISTS (
                        SELECT 1 FROM user_club_access uca
                        WHERE uca.user_id = u.id
                          AND uca.active = true
                          AND uca.role = 'coach'
                          $ucaClubFilter
                    )
                    OR t.id IS NOT NULL
                )
                GROUP BY u.id, u.first_name, u.last_name, u.email,
                         u.password_hash, u.last_login_at
            ";

            // ─── Paginated (GOTR G2) ─────────────────────────────────────────
            // Keyset on (last_name, first_name, u.id). The keyset predicate goes
            // in HAVING, not WHERE: this query is grouped, and a WHERE clause
            // referencing the grouped row would be evaluated before the GROUP BY.
            // Every column in the key is in the GROUP BY list, so HAVING can see
            // them.
            $sortExprs = [
                te_page_text_key('u.last_name'),
                te_page_text_key('u.first_name'),
                'u.id',
            ];
            $limit = te_page_limit($_GET['limit'] ?? null);
            $cursor = te_page_decode_cursor($_GET['cursor'] ?? null, count($sortExprs));
            $keyset = te_page_keyset_clause($sortExprs, $cursor);
            if ($keyset['sql'] !== '') {
                $sql .= ' HAVING ' . substr($keyset['sql'], strlen(' AND '));
                $params = array_merge($params, $keyset['params']);
            }
            $sql .= ' ' . te_page_order_by($sortExprs) . ' LIMIT ' . te_page_fetch_limit($limit);

            $stmt = $connection->prepare($sql);
            $stmt->execute($params);

            $rawPage = te_page_finish(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                $limit,
                fn(array $row) => [
                    te_page_text_value($row['last_name'] ?? null),
                    te_page_text_value($row['first_name'] ?? null),
                    (int)$row['id'],
                ]
            );

            $coaches = [];
            foreach ($rawPage['rows'] as $r) {
                $s = te_portal_status($r, (string)$r['email'], 'coach');
                $coaches[] = [
                    'id'             => (int)$r['id'],
                    'first_name'     => $r['first_name'],
                    'last_name'      => $r['last_name'],
                    'email'          => $r['email'],
                    'team_count'     => (int)$r['team_count'],
                    'status'         => $s['status'],
                    'first_login_at' => $s['first_login_at'],
                    'invited_at'     => $s['invited_at'],
                    'shared_account' => $s['shared_account'],
                    'shared_reason'  => $s['shared_reason'],
                ];
            }
            // ⚠️ SHAPE CHANGE: this used to be a bare JSON array. It is now
            // `{success, coaches, page}`, because a list that can be cut off has
            // to be able to say so — a truncated array is indistinguishable from
            // a complete one. All four frontend callers (CoachManagement,
            // TeamFormWithTabs, ProgramStaffModal, ClubDocumentCenter) read
            // `Array.isArray(data) ? data : data.coaches`, so they work against
            // either backend. That is why the FRONTEND ships first.
            echo json_encode([
                'success' => true,
                'coaches' => $coaches,
                'page' => $rawPage['page'],
            ]);
            break;

        case 'create':
            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
                http_response_code(400);
                echo json_encode(['error' => 'first_name, last_name, and email are required']);
                exit();
            }

            // A new coach must be attached to one of the caller's clubs, otherwise
            // they would be invisible to the (now club-scoped) available list.
            // Prefer the caller's active club context; fall back to an explicit
            // club_id, or their sole club if they only belong to one.
            if ($hasNoClubScope) {
                http_response_code(403);
                echo json_encode(['error' => 'You are not scoped to a club']);
                exit();
            }

            $targetClub = null;
            $activeCtx = $auth->getActiveContext();
            if ($activeCtx && ($activeCtx->scope_type ?? null) === 'club') {
                $targetClub = (int)($activeCtx->scope_id ?? 0);
            } elseif (!empty($data['club_id'])) {
                $targetClub = (int)$data['club_id'];
            } elseif ($accessibleClubs !== null && count($accessibleClubs) === 1) {
                $targetClub = (int)$accessibleClubs[0];
            }

            if (!$targetClub) {
                http_response_code(400);
                echo json_encode(['error' => 'club_id is required to create a coach']);
                exit();
            }

            // Authorize the target club (super admins bypass).
            if ($accessibleClubs !== null &&
                !in_array($targetClub, array_map('intval', $accessibleClubs), true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this club']);
                exit();
            }

            // GOTR G6: no password is written here any more. Every coach made on
            // this page gets an account with NO credential and a single-use,
            // 7-day invite link; setting the password through that link is the
            // "accepted" fact the onboarding funnel counts. An address that
            // already has an account is attached to this club rather than
            // refused (users.email is UNIQUE), and one that can already sign in
            // is `already_active` — access added, no mail. Identity decisions
            // live in lib/coach_invite.php, shared with the importer.
            require_once __DIR__ . '/../lib/coach_invite.php';

            $invite = te_coach_invite_ensure_user_and_token(
                $connection,
                [
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'email'      => $data['email'],
                    'phone'      => $data['phone'] ?? '',
                ],
                $targetClub,
                (int) $auth->getUserId(),
                'coaches_page'
            );

            if ($invite['status'] === 'error') {
                http_response_code(400);
                echo json_encode(['error' => $invite['message']]);
                exit();
            }
            if ($invite['status'] === 'access_revoked') {
                http_response_code(409);
                echo json_encode([
                    'error' => 'This person\'s coach access to the club was revoked. Restore it rather than re-adding them.',
                    'id'    => $invite['user_id'],
                ]);
                exit();
            }

            $coachId = (int) $invite['user_id'];

            // The page sends inline — one admin, one coach, and they want to know
            // now whether the mail left. Imports go through the queue instead.
            $emailResult = null;
            if ($invite['status'] === 'invited') {
                $emailResult = te_coach_invite_send($connection, $coachId, $targetClub);
            }

            $message = $invite['status'] === 'already_active'
                ? 'This coach already has an account; they have been added to your club and can sign in as usual.'
                : (($emailResult['sent'] ?? false)
                    ? 'Coach added. An invitation to set their password has been emailed to them.'
                    : 'Coach added, but the invitation email was not sent'
                      . (($emailResult['feature_disabled'] ?? null) ? ' (invite emails are switched off).' : '. Use Resend later.'));

            echo json_encode([
                'success' => true,
                'id' => $coachId,
                'invite' => [
                    'status'     => $invite['status'],
                    'access'     => $invite['access'] ?? null,
                    'email_sent' => (bool) ($emailResult['sent'] ?? false),
                    'reason'     => $emailResult['reason'] ?? null,
                ],
                'message' => $message,
            ]);
            break;

        case 'update':
            $data = json_decode(file_get_contents("php://input"), true);
            $coachId = $_GET['id'] ?? null;

            if (!$coachId) {
                http_response_code(400);
                echo json_encode(['error' => 'Coach ID is required']);
                exit();
            }

            // Verify the target is a coach by the AUTHORITATIVE definition — a
            // user_club_access 'coach' role or a team's primary coach — NOT
            // users.role, which the 'available' list also ignores. Checking
            // users.role='coach' here 404'd coaches whose role lives only in
            // user_club_access (e.g. club admins who coach, or coaches imported
            // with users.role='parent'). Tenant scope is enforced separately below.
            $stmt = $connection->prepare("
                SELECT 1 FROM users u
                WHERE u.id = ?
                  AND (
                    EXISTS (
                        SELECT 1 FROM user_club_access uca
                        WHERE uca.user_id = u.id AND uca.active = true AND uca.role = 'coach'
                    )
                    OR EXISTS (
                        SELECT 1 FROM teams t
                        WHERE t.primary_coach_id = u.id AND t.deleted_at IS NULL
                    )
                  )
                LIMIT 1
            ");
            $stmt->execute([$coachId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Coach not found']);
                exit();
            }

            // Enforce tenant scope: the caller may only edit a coach who belongs to
            // one of their clubs (via club access or a team they coach). Super
            // admins bypass. Without this, any authenticated user could edit any
            // coach in any org.
            if ($accessibleClubs !== null) {
                if ($hasNoClubScope) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }
                // CLUB ids again — see the allowlist note above.
                $ph = implode(',', array_fill(0, count($accessibleClubs), '?'));
                $chk = $connection->prepare("
                    SELECT 1
                    WHERE EXISTS (
                        SELECT 1 FROM user_club_access uca
                        WHERE uca.user_id = ? AND uca.active = true
                          AND uca.club_profile_id IN ($ph)
                    )
                    OR EXISTS (
                        SELECT 1 FROM teams t
                        WHERE t.primary_coach_id = ? AND t.deleted_at IS NULL
                          AND t.club_id IN ($ph)
                    )
                    LIMIT 1
                ");
                $chk->execute(array_merge(
                    [$coachId], $accessibleClubs,
                    [$coachId], $accessibleClubs
                ));
                if (!$chk->fetch()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied to this coach']);
                    exit();
                }
            }

            // Update coach information
            $stmt = $connection->prepare("
                UPDATE users
                SET first_name = ?,
                    last_name = ?,
                    email = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $coachId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Coach updated successfully'
            ]);
            break;

        // ─── Team staff (assistant coaches / managers) ───────────────────
        // Stored as team_members rows with role assistant_coach|team_manager,
        // which is what the rest of the app reads for team staff access.

        case 'team-staff': {
            $teamId = (int)($_GET['team_id'] ?? 0);
            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['error' => 'team_id is required']);
                exit();
            }
            coachesGw_assertTeamAccess($connection, $accessibleClubs, $teamId);

            $stmt = $connection->prepare("
                SELECT tm.user_id, tm.role, u.first_name, u.last_name, u.email
                FROM team_members tm
                JOIN users u ON u.id = tm.user_id
                WHERE tm.team_id = ?
                  AND tm.role IN ('assistant_coach', 'team_manager')
                  AND (tm.status IS NULL OR tm.status = 'active')
                ORDER BY tm.role, u.last_name, u.first_name
            ");
            $stmt->execute([$teamId]);
            echo json_encode(['success' => true, 'staff' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'assign-staff': {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId = (int)($data['team_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $role   = $data['role'] ?? '';
            if (!$teamId || !$userId || !in_array($role, ['assistant_coach', 'team_manager'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'team_id, user_id and a valid role are required']);
                exit();
            }
            coachesGw_assertTeamAccess($connection, $accessibleClubs, $teamId);

            // Re-activate an existing row rather than duplicating it.
            $chk = $connection->prepare("SELECT id FROM team_members WHERE team_id = ? AND user_id = ? AND role = ? LIMIT 1");
            $chk->execute([$teamId, $userId, $role]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $connection->prepare("UPDATE team_members SET status = 'active', leave_date = NULL WHERE id = ?")
                           ->execute([$existing['id']]);
            } else {
                $connection->prepare("
                    INSERT INTO team_members (team_id, user_id, role, status, join_date)
                    VALUES (?, ?, ?, 'active', CURRENT_DATE)
                ")->execute([$teamId, $userId, $role]);
            }
            echo json_encode(['success' => true]);
            break;
        }

        case 'unassign-staff': {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId = (int)($data['team_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $role   = $data['role'] ?? '';
            if (!$teamId || !$userId || !in_array($role, ['assistant_coach', 'team_manager'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'team_id, user_id and a valid role are required']);
                exit();
            }
            coachesGw_assertTeamAccess($connection, $accessibleClubs, $teamId);

            $connection->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ? AND role = ?")
                       ->execute([$teamId, $userId, $role]);
            echo json_encode(['success' => true]);
            break;
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Tenant guard for team-staff actions: the team must live in one of the
 * caller's clubs (super admins pass $accessibleClubs === null and bypass).
 */
function coachesGw_assertTeamAccess(PDO $connection, $accessibleClubs, int $teamId): void {
    $stmt = $connection->prepare("SELECT club_id FROM teams WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        http_response_code(404);
        echo json_encode(['error' => 'Team not found']);
        exit();
    }
    if ($accessibleClubs !== null &&
        !in_array((int)$team['club_id'], array_map('intval', $accessibleClubs), true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this team']);
        exit();
    }
}
?>

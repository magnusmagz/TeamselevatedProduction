<?php
/**
 * Financial Permissions API
 * Controls access to financial data based on user roles
 *
 * Permission Levels:
 * - league_admin: Full access to all financial data for the league
 * - club_admin: Full access to financial data for their club
 * - treasurer: Full access to financial data (similar to admin)
 * - coach: Can see payment status for athletes on their teams (not amounts)
 * - parent/guardian: Can see their own children's payment details
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';

// Helper to extract JWT token — signature-verified (never trust an unverified payload).
function getJWTPayload() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (empty($authHeader) || !preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return null;
    }

    // Verify the HMAC/RS256 signature before trusting anything in the token.
    // A forged or tampered token fails verification and is treated as anonymous.
    $verified = JWT::verify($matches[1]);
    if (!$verified) {
        return null;
    }

    // Return the same associative-array shape the rest of this file expects.
    return json_decode(json_encode($verified), true);
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $action = $_GET['action'] ?? 'check';
    $payload = getJWTPayload();

    switch ($action) {
        case 'check':
            // Check permissions for current user
            if (!$payload) {
                echo json_encode([
                    'success' => true,
                    'authenticated' => false,
                    'permissions' => [
                        'can_view_revenue' => false,
                        'can_view_all_payments' => false,
                        'can_view_athlete_payments' => false,
                        'can_send_reminders' => false,
                        'can_process_payments' => false,
                        'can_view_transactions' => false,
                        'can_export_reports' => false,
                        'view_amounts' => false
                    ]
                ]);
                exit;
            }

            $userId = $payload['user_id'] ?? null;
            $userEmail = $payload['email'] ?? '';

            // Query database directly for roles (more reliable than JWT cache)
            $isLeagueAdmin = false;
            $isClubAdmin = false;
            $isTreasurer = false;
            $isCoach = false;
            $isParent = false;

            // Check roles from user_club_access (single source of truth)
            $stmt = $pdo->prepare("
                SELECT role FROM user_club_access
                WHERE user_id = :user_id AND active = TRUE
            ");
            $stmt->execute(['user_id' => $userId]);
            $clubRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($clubRoles as $role) {
                if ($role === 'club_admin') $isClubAdmin = true;
                if ($role === 'treasurer') $isTreasurer = true;
                if ($role === 'coach') $isCoach = true;
                if ($role === 'parent') $isParent = true;
            }

            // Aggregate across ALL guardian rows sharing this email — shared-household support.
            // NOTE: does NOT fix the separate bug where users.email != guardians.email —
            // that requires the Phase 2 user_guardians link table (see project_household_shared_email.md).
            $guardianStmt = $pdo->prepare("
                SELECT g.id, COUNT(ag.athlete_id) as athlete_count
                FROM guardians g
                LEFT JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE g.email = :email
                GROUP BY g.id
            ");
            $guardianStmt->execute(['email' => $userEmail]);
            $guardianRows = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);

            $guardianIds = [];
            $totalAthleteCount = 0;
            foreach ($guardianRows as $row) {
                $guardianIds[] = $row['id'];
                $totalAthleteCount += (int) $row['athlete_count'];
            }

            if ($totalAthleteCount > 0) {
                $isParent = true;
            }

            // Build permissions based on role
            $permissions = [
                // Full financial access for admins and treasurer
                'can_view_revenue' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_view_all_payments' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_send_reminders' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_process_payments' => $isLeagueAdmin || $isClubAdmin || $isTreasurer || $isParent,
                'can_view_transactions' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_export_reports' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_view_roster_fees' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,

                // Coaches can see roster payment status (not amounts)
                'can_view_athlete_payment_status' => $isLeagueAdmin || $isClubAdmin || $isTreasurer || $isCoach,

                // Everyone can see their own payments
                'can_view_own_payments' => true,

                // Amount visibility
                'view_amounts' => $isLeagueAdmin || $isClubAdmin || $isTreasurer || $isParent
            ];

            // ⚠️ TWO LISTS, AND THEY ARE NOT THE SAME QUESTION (2026-08-17).
            //
            //   $myChildren          — athletes this user is a GUARDIAN of. Family.
            //   $accessibleAthletes  — the above PLUS every athlete on the teams they
            //                          coach. "Whose finances may I look at."
            //
            // The union is correct for what this endpoint was built for (a coach
            // seeing payment status across their roster) and wrong for anything that
            // means "my family". The parent portal took the union, so a coach who is
            // also a parent got their whole team wherever the portal asked "who are
            // your children" — including ConsentGate, which then asked them to give
            // parental consent for ten other people's kids.
            //
            // That was not merely cosmetic. ConsentGate records one row per child and
            // throws on the first refusal, and consent.php?action=record correctly
            // 422s a non-guardian — so the gate could never be satisfied and the
            // parent portal was UNREACHABLE for those accounts. Luis Escamilla (157)
            // pressed Submit five times on 2026-08-17, writing his own son's consent
            // five times over and failing on the first teammate each time.
            //
            // Six live coach-parent accounts at club 51 were affected, each coaching
            // 2–11 athletes. Keep the lists separate; a caller must say which one it
            // means.
            $myChildren = [];
            $accessibleAthletes = [];

            if ($isParent && !empty($guardianIds)) {
                // Get athletes linked to ANY of the matching guardian rows.
                $placeholders = implode(',', array_fill(0, count($guardianIds), '?'));
                $athleteStmt = $pdo->prepare("
                    SELECT DISTINCT a.id, a.first_name, a.last_name
                    FROM athletes a
                    JOIN athlete_guardians ag ON a.id = ag.athlete_id
                    WHERE ag.guardian_id IN ($placeholders)
                      AND a.active_status = true
                ");
                $athleteStmt->execute($guardianIds);
                $myChildren = $athleteStmt->fetchAll(PDO::FETCH_ASSOC);
                $accessibleAthletes = $myChildren;
            }

            if ($isCoach) {
                // Athletes on the teams this user actually coaches.
                //
                // ⚠️ This query used to join `team_coaches` and `coaches`. NEITHER
                // TABLE EXISTS. Every request from anyone holding a coach role
                // therefore died with SQLSTATE 42P01 and the endpoint returned 500 —
                // and because the parent branch above runs FIRST and had already
                // filled $accessibleAthletes, a coach who is also a parent lost their
                // own children too. That is what made the parent portal tell Samantha
                // Archer she had no athletes while Crew and Athletes both showed Alia
                // (reported 2026-08-03). Parent-only accounts were unaffected, which
                // is why it survived a 148-family rollout.
                //
                // Team scoping is getCoachTeamIds() — primary_coach_id OR an active
                // assistant_coach / team_manager membership — the same predicate the
                // communications gateways use. Do not re-derive it here.
                require_once __DIR__ . '/../lib/coach_scope.php';

                $coachClubStmt = $pdo->prepare("
                    SELECT DISTINCT club_profile_id FROM user_club_access
                    WHERE user_id = :user_id AND active = TRUE AND role = 'coach'
                ");
                $coachClubStmt->execute(['user_id' => $userId]);

                $teamIds = [];
                foreach ($coachClubStmt->fetchAll(PDO::FETCH_COLUMN) as $coachClubId) {
                    foreach (getCoachTeamIds($pdo, $userId, $coachClubId) as $teamId) {
                        $teamIds[(int) $teamId] = true;
                    }
                }

                if ($teamIds) {
                    $ph = implode(',', array_fill(0, count($teamIds), '?'));
                    $teamAthleteStmt = $pdo->prepare("
                        SELECT DISTINCT a.id, a.first_name, a.last_name
                        FROM athletes a
                        JOIN team_members tm ON a.id = tm.athlete_id
                        WHERE tm.team_id IN ($ph)
                          AND a.active_status = true
                          AND a.deleted_at IS NULL
                    ");
                    $teamAthleteStmt->execute(array_keys($teamIds));
                    $accessibleAthletes = array_merge(
                        $accessibleAthletes,
                        $teamAthleteStmt->fetchAll(PDO::FETCH_ASSOC)
                    );
                }
            }

            // Unique athletes
            $athleteIds = [];
            $uniqueAthletes = [];
            foreach ($accessibleAthletes as $athlete) {
                if (!in_array($athlete['id'], $athleteIds)) {
                    $athleteIds[] = $athlete['id'];
                    $uniqueAthletes[] = $athlete;
                }
            }

            // my_children is already DISTINCT per the query, but a guardian can hold
            // two rows on the same email (six such households live), so a child can
            // arrive twice. De-duplicate the same way rather than trusting the query.
            $myChildIds = [];
            $uniqueChildren = [];
            foreach ($myChildren as $child) {
                if (!in_array($child['id'], $myChildIds)) {
                    $myChildIds[] = $child['id'];
                    $uniqueChildren[] = $child;
                }
            }

            echo json_encode([
                'success' => true,
                'authenticated' => true,
                'user_id' => $userId,
                'roles' => [
                    'is_league_admin' => $isLeagueAdmin,
                    'is_club_admin' => $isClubAdmin,
                    'is_treasurer' => $isTreasurer,
                    'is_coach' => $isCoach,
                    'is_parent' => $isParent
                ],
                'permissions' => $permissions,
                'accessible_athlete_ids' => $athleteIds,
                'accessible_athletes' => $uniqueAthletes,

                // Guardian-derived only. Anything meaning "my family" reads THESE —
                // never accessible_athletes, which includes a coach's whole roster.
                'my_children_ids' => $myChildIds,
                'my_children' => $uniqueChildren
            ]);
            break;

        case 'check-athlete':
            // Check if user can view a specific athlete's financial data
            $athleteId = $_GET['athlete_id'] ?? null;

            if (!$payload || !$athleteId) {
                echo json_encode([
                    'success' => false,
                    'can_view' => false,
                    'can_view_amounts' => false,
                    'message' => 'Missing required parameters'
                ]);
                exit;
            }

            $userId = $payload['user_id'] ?? null;
            $roles = $payload['roles'] ?? [];

            // Check for admin roles
            $isAdmin = false;
            $isCoach = false;
            foreach ($roles as $role) {
                $roleType = $role['role'] ?? '';
                if (in_array($roleType, ['league_admin', 'club_admin', 'treasurer'])) {
                    $isAdmin = true;
                }
                if (in_array($roleType, ['coach', 'head_coach', 'assistant_coach'])) {
                    $isCoach = true;
                }
            }

            if ($isAdmin) {
                echo json_encode([
                    'success' => true,
                    'can_view' => true,
                    'can_view_amounts' => true,
                    'reason' => 'admin'
                ]);
                exit;
            }

            // Check if user is guardian of this athlete
            $guardianCheck = $pdo->prepare("
                SELECT g.id
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE g.email = :email AND ag.athlete_id = :athlete_id
            ");
            $guardianCheck->execute([
                'email' => $payload['email'] ?? '',
                'athlete_id' => $athleteId
            ]);

            if ($guardianCheck->fetch()) {
                echo json_encode([
                    'success' => true,
                    'can_view' => true,
                    'can_view_amounts' => true,
                    'reason' => 'guardian'
                ]);
                exit;
            }

            // Check if coach has this athlete on their team
            if ($isCoach) {
                // Same phantom-table bug as `check` above — team_coaches and coaches
                // do not exist, so this threw 42P01 for every coach. It failed later
                // in the request than the other one (the guardian branch returns
                // first), so a coach-parent asking about their OWN child got a
                // correct answer and one about a team athlete got a 500.
                require_once __DIR__ . '/../lib/coach_scope.php';

                $coachClubStmt = $pdo->prepare("
                    SELECT DISTINCT club_profile_id FROM user_club_access
                    WHERE user_id = :user_id AND active = TRUE AND role = 'coach'
                ");
                $coachClubStmt->execute(['user_id' => $userId]);

                $teamIds = [];
                foreach ($coachClubStmt->fetchAll(PDO::FETCH_COLUMN) as $coachClubId) {
                    foreach (getCoachTeamIds($pdo, $userId, $coachClubId) as $teamId) {
                        $teamIds[(int) $teamId] = true;
                    }
                }

                $onCoachTeam = false;
                if ($teamIds) {
                    $ph = implode(',', array_fill(0, count($teamIds), '?'));
                    $coachCheck = $pdo->prepare("
                        SELECT tm.athlete_id
                        FROM team_members tm
                        WHERE tm.team_id IN ($ph) AND tm.athlete_id = ?
                        LIMIT 1
                    ");
                    $coachCheck->execute(array_merge(array_keys($teamIds), [$athleteId]));
                    $onCoachTeam = (bool) $coachCheck->fetch();
                }

                if ($onCoachTeam) {
                    echo json_encode([
                        'success' => true,
                        'can_view' => true,
                        'can_view_amounts' => false, // Coaches see status only
                        'reason' => 'coach'
                    ]);
                    exit;
                }
            }

            // No access
            echo json_encode([
                'success' => true,
                'can_view' => false,
                'can_view_amounts' => false,
                'reason' => 'no_access'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}

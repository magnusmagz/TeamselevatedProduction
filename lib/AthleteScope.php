<?php
/**
 * AthleteScope — centralized athlete access-control scoping.
 *
 * Decides which athletes a given authenticated user may read, enforcing:
 *   - club_admin: athletes belonging to a club the admin can access
 *   - coach:      athletes on a team the user coaches (primary_coach_id OR
 *                 active assistant_coach/team_manager team_members row)
 *   - guardian:   athletes the user is a guardian of (guardians.email matches
 *                 the requester's email, linked via athlete_guardians)
 *   - super_admin: everything
 *   - otherwise:  no access
 *
 * All DB access is injected (pass $pdo) so the logic is unit-testable against
 * an in-memory SQLite fixture or a PDO test double. No global state.
 *
 * Notes:
 *  - Athletes have no club_id column; an athlete's club(s) are derived via
 *    team_members.athlete_id -> teams.club_id (mirrors recipient-search-gateway).
 *  - Coach->teams definition mirrors models/Coach.php::getCoachTeams() and
 *    recipient-search-gateway::getCoachTeamIds().
 *  - Guardian detection mirrors api/financial-permissions.php (email match).
 *  - PostgreSQL booleans use TRUE/FALSE.
 */

require_once __DIR__ . '/AuthMiddleware.php';

class AthleteScope {

    /**
     * Team IDs the given user coaches.
     *
     * A coach's teams are those where teams.primary_coach_id = $userId OR the
     * user has an active team_members row with role assistant_coach/team_manager.
     * (Matches models/Coach.php and recipient-search-gateway.php.)
     *
     * @param PDO $pdo
     * @param int $userId
     * @return int[] distinct team IDs
     */
    public static function coachTeamIdsForUser(PDO $pdo, int $userId): array {
        $sql = "
            SELECT DISTINCT t.id
            FROM teams t
            LEFT JOIN team_members tm
                ON tm.team_id = t.id
                AND tm.user_id = :uid_tm
                AND tm.role IN ('assistant_coach', 'team_manager')
                AND tm.status = 'active'
            WHERE (t.primary_coach_id = :uid_primary OR tm.id IS NOT NULL)
              AND t.deleted_at IS NULL
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid_tm' => $userId, ':uid_primary' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Distinct club IDs an athlete belongs to.
     *
     * An athlete's club is derived from BOTH:
     *   - their direct athletes.club_id (set on creation/registration — covers
     *     club athletes who are not yet assigned to any team), and
     *   - their team memberships (team_members.athlete_id -> teams.club_id).
     *
     * Relying on team membership alone hid athletes who belong to a club but
     * have no team yet from the People -> Athletes list and detail view for the
     * club admin (CA-18). Including the direct club_id closes that gap while
     * keeping the team-derived path for athletes whose club_id was never set.
     *
     * @param PDO $pdo
     * @param int $athleteId
     * @return int[] distinct club IDs
     */
    public static function athleteClubIds(PDO $pdo, int $athleteId): array {
        $ids = [];

        // Team-derived clubs.
        $sql = "
            SELECT DISTINCT t.club_id
            FROM team_members tm
            JOIN teams t ON t.id = tm.team_id
            WHERE tm.athlete_id = :aid
              AND t.club_id IS NOT NULL
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':aid' => $athleteId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $clubId) {
            $ids[(int) $clubId] = true;
        }

        // Direct athletes.club_id (guarded: older fixtures may lack the column).
        try {
            $stmt = $pdo->prepare("SELECT club_id FROM athletes WHERE id = :aid AND club_id IS NOT NULL");
            $stmt->execute([':aid' => $athleteId]);
            $direct = $stmt->fetchColumn();
            if ($direct !== false && $direct !== null) {
                $ids[(int) $direct] = true;
            }
        } catch (\PDOException $e) {
            // athletes.club_id not present in this schema — ignore.
        }

        return array_keys($ids);
    }

    /**
     * Is this user a guardian of the given athlete?
     * Parent detection = guardians.email matches the requester's email
     * (aggregated across all guardian rows sharing that email), linked via
     * athlete_guardians to the athlete.
     *
     * @param PDO $pdo
     * @param string $email requester email
     * @param int $athleteId
     * @return bool
     */
    public static function isGuardianOfAthlete(PDO $pdo, string $email, int $athleteId): bool {
        if ($email === '') {
            return false;
        }
        $sql = "
            SELECT 1
            FROM guardians g
            JOIN athlete_guardians ag ON ag.guardian_id = g.id
            WHERE g.email = :email AND ag.athlete_id = :aid
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email, ':aid' => $athleteId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Does this user coach a team the athlete is a member of?
     *
     * @param PDO $pdo
     * @param int $userId
     * @param int $athleteId
     * @return bool
     */
    public static function coachesAthlete(PDO $pdo, int $userId, int $athleteId): bool {
        $teamIds = self::coachTeamIdsForUser($pdo, $userId);
        if (empty($teamIds)) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $sql = "
            SELECT 1 FROM team_members tm
            WHERE tm.athlete_id = ? AND tm.team_id IN ($placeholders)
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$athleteId], $teamIds));
        return $stmt->fetch() !== false;
    }

    /**
     * Core authorization decision: may this authenticated user read this athlete?
     *
     * Order of checks (any true => allow):
     *   1. super_admin
     *   2. club_admin of one of the athlete's clubs
     *   3. coach of a team the athlete is a member of
     *   4. guardian of the athlete (email match)
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth authenticated requester
     * @param int $athleteId
     * @return bool
     */
    public static function userCanAccessAthlete(PDO $pdo, AuthMiddleware $auth, int $athleteId): bool {
        // 1. Super admins can access everything.
        if ($auth->isSuperAdmin()) {
            return true;
        }

        // 2. Club admin: allow if requester has club_admin role for any club the
        //    athlete belongs to.
        $clubIds = self::athleteClubIds($pdo, $athleteId);
        foreach ($clubIds as $clubId) {
            if ($auth->hasRole('club_admin', $clubId, 'club')) {
                return true;
            }
        }

        // 3. Coach: allow if requester coaches a team the athlete is on.
        $userId = (int) $auth->getUserId();
        if ($userId > 0 && self::coachesAthlete($pdo, $userId, $athleteId)) {
            return true;
        }

        // 4. Guardian: allow if requester's email is a guardian of the athlete.
        $payload = $auth->getPayload();
        $email = '';
        if (is_object($payload) && isset($payload->email)) {
            $email = (string) $payload->email;
        } elseif (is_array($payload) && isset($payload['email'])) {
            $email = (string) $payload['email'];
        }
        if ($email !== '' && self::isGuardianOfAthlete($pdo, $email, $athleteId)) {
            return true;
        }

        return false;
    }

    /**
     * Build a WHERE fragment + params restricting a LIST query to athletes the
     * requester may access. Designed for queries that join athletes `a` to
     * team_members and (optionally) teams.
     *
     * Returns the set of accessible athlete IDs and a ready-made SQL fragment of
     * the form "AND a.id IN (?, ?, ...)" (or "AND 1=0" when nothing accessible,
     * or "" for super_admin = unrestricted).
     *
     * Using an explicit id list keeps the caller's existing query shape intact
     * regardless of how it joins, avoiding accidental row multiplication.
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth
     * @param string $athleteIdColumn column to scope (default 'a.id')
     * @return array{sql:string, params:array, athlete_ids:int[]|null}
     *               athlete_ids is null for super_admin (unrestricted).
     */
    public static function accessibleAthleteFilter(PDO $pdo, AuthMiddleware $auth, string $athleteIdColumn = 'a.id'): array {
        // Super admin: unrestricted.
        if ($auth->isSuperAdmin()) {
            return ['sql' => '', 'params' => [], 'athlete_ids' => null];
        }

        $ids = self::accessibleAthleteIds($pdo, $auth);

        if (empty($ids)) {
            return ['sql' => 'AND 1=0', 'params' => [], 'athlete_ids' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return [
            'sql' => "AND {$athleteIdColumn} IN ({$placeholders})",
            'params' => array_values($ids),
            'athlete_ids' => array_values($ids),
        ];
    }

    /**
     * The full set of athlete IDs the requester may access (non-super-admin).
     *
     * Union of:
     *   - athletes in clubs where requester is club_admin
     *   - athletes on teams the requester coaches
     *   - athletes the requester is a guardian of
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth
     * @return int[] distinct athlete IDs
     */
    public static function accessibleAthleteIds(PDO $pdo, AuthMiddleware $auth): array {
        $ids = [];

        // Club admin clubs (from JWT roles).
        $adminClubIds = self::clubAdminClubIds($auth);
        if (!empty($adminClubIds)) {
            $ph = implode(',', array_fill(0, count($adminClubIds), '?'));

            // (a) Athletes on a team in the admin's club(s).
            $sql = "
                SELECT DISTINCT tm.athlete_id
                FROM team_members tm
                JOIN teams t ON t.id = tm.team_id
                WHERE t.club_id IN ($ph)
                  AND tm.athlete_id IS NOT NULL
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($adminClubIds));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $ids[(int) $aid] = true;
            }

            // (b) Athletes directly linked to the admin's club(s) via
            //     athletes.club_id — covers club athletes with no team yet, so
            //     the People -> Athletes list shows the full club roster
            //     (CA-18). Guarded for fixtures that lack the column.
            try {
                $sql = "SELECT id FROM athletes WHERE club_id IN ($ph)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($adminClubIds));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                    $ids[(int) $aid] = true;
                }
            } catch (\PDOException $e) {
                // athletes.club_id not present in this schema — ignore.
            }
        }

        // Coached teams.
        $userId = (int) $auth->getUserId();
        if ($userId > 0) {
            $teamIds = self::coachTeamIdsForUser($pdo, $userId);
            if (!empty($teamIds)) {
                $ph = implode(',', array_fill(0, count($teamIds), '?'));
                $sql = "
                    SELECT DISTINCT tm.athlete_id
                    FROM team_members tm
                    WHERE tm.team_id IN ($ph)
                      AND tm.athlete_id IS NOT NULL
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($teamIds));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                    $ids[(int) $aid] = true;
                }
            }
        }

        // Guardian-of athletes.
        $payload = $auth->getPayload();
        $email = '';
        if (is_object($payload) && isset($payload->email)) {
            $email = (string) $payload->email;
        } elseif (is_array($payload) && isset($payload['email'])) {
            $email = (string) $payload['email'];
        }
        if ($email !== '') {
            $sql = "
                SELECT DISTINCT ag.athlete_id
                FROM guardians g
                JOIN athlete_guardians ag ON ag.guardian_id = g.id
                WHERE g.email = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $ids[(int) $aid] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Club IDs where the requester holds the club_admin role (from JWT roles).
     *
     * @param AuthMiddleware $auth
     * @return int[]
     */
    public static function clubAdminClubIds(AuthMiddleware $auth): array {
        $clubIds = [];
        $roles = $auth->getRoles();
        $rolesArray = is_array($roles) ? $roles : (array) $roles;
        foreach ($rolesArray as $role) {
            $r = (array) $role;
            if (($r['role'] ?? null) === 'club_admin'
                && ($r['scope_type'] ?? null) === 'club'
                && isset($r['scope_id'])) {
                $clubIds[(int) $r['scope_id']] = true;
            }
        }
        return array_keys($clubIds);
    }
}

<?php
/**
 * Authentication and Authorization Middleware
 *
 * Validates JWT tokens and enforces role-based access control (RBAC)
 * with organizational scoping (league/club hierarchy).
 */

require_once __DIR__ . '/JWT.php';

class AuthMiddleware {
    private $payload;
    private $userId;
    private $systemRole;
    private $orgId;
    private $orgType;
    private $roles;
    private $activeContext;

    /**
     * Validate JWT token and extract user context
     *
     * @param string $token JWT token from Authorization header
     * @return bool True if valid, false otherwise
     */
    public function validateToken($token) {
        // Remove "Bearer " prefix if present
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        // Verify token
        $this->payload = JWT::verify($token);

        if (!$this->payload) {
            error_log("AuthMiddleware: Token validation failed");
            return false;
        }

        // Extract user context
        $this->userId = $this->payload->user_id ?? null;
        $this->systemRole = $this->payload->system_role ?? 'user';
        $this->orgId = $this->payload->org_id ?? null;
        $this->orgType = $this->payload->org_type ?? null;
        $this->roles = $this->payload->roles ?? [];
        $this->activeContext = $this->payload->active_context ?? null;

        if (!$this->userId) {
            error_log("AuthMiddleware: No user_id in token");
            return false;
        }

        error_log("AuthMiddleware: Token validated for user {$this->userId}, system_role: {$this->systemRole}");
        return true;
    }

    /**
     * Check if user is a super admin
     *
     * @return bool
     */
    public function isSuperAdmin() {
        return $this->systemRole === 'super_admin';
    }

    /**
     * Check if user has a specific role
     *
     * @param string $role Role to check (e.g., 'league_admin', 'club_admin', 'coach')
     * @param int|null $scopeId Optional scope ID to check against
     * @param string|null $scopeType Optional scope type ('league' or 'club')
     * @return bool
     */
    public function hasRole($role, $scopeId = null, $scopeType = null) {
        // Super admins have all roles
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Convert roles object to array if needed
        $rolesArray = is_array($this->roles) ? $this->roles : (array)$this->roles;

        foreach ($rolesArray as $userRole) {
            $userRoleArray = (array)$userRole;

            // Check if role matches
            if (($userRoleArray['role'] ?? null) !== $role) {
                continue;
            }

            // If no scope specified, just check role name
            if ($scopeId === null && $scopeType === null) {
                return true;
            }

            // Check scope if specified
            if ($scopeId !== null && ($userRoleArray['scope_id'] ?? null) == $scopeId) {
                if ($scopeType === null || ($userRoleArray['scope_type'] ?? null) === $scopeType) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if user can access a specific league
     *
     * @param int $leagueId League ID to check
     * @return bool
     */
    public function canAccessLeague($leagueId) {
        // Super admins can access all leagues
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Check if user has league admin role for this league
        if ($this->hasRole('league_admin', $leagueId, 'league')) {
            return true;
        }

        // Check if user has any club role in this league
        $rolesArray = is_array($this->roles) ? $this->roles : (array)$this->roles;
        foreach ($rolesArray as $role) {
            $roleArray = (array)$role;
            if (($roleArray['scope_type'] ?? null) === 'club' && ($roleArray['league_id'] ?? null) == $leagueId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user can access a specific club
     *
     * @param int $clubId Club ID to check
     * @return bool
     */
    public function canAccessClub($clubId) {
        // Super admins can access all clubs
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Check if user has club role for this specific club
        $rolesArray = is_array($this->roles) ? $this->roles : (array)$this->roles;
        foreach ($rolesArray as $role) {
            $roleArray = (array)$role;
            if (($roleArray['scope_type'] ?? null) === 'club' && ($roleArray['scope_id'] ?? null) == $clubId) {
                return true;
            }
        }

        // Check if user is league admin for the club's league
        // This requires a database query, so we'll handle it in the caller
        // For now, return false and let the caller check via canAccessLeagueOfClub()

        return false;
    }

    /**
     * Check if user can access a club via league admin rights
     *
     * @param PDO $connection Database connection
     * @param int $clubId Club ID to check
     * @return bool
     */
    public function canAccessLeagueOfClub($connection, $clubId) {
        // Super admins can access everything
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Get the club's league_id
        $stmt = $connection->prepare("SELECT league_id FROM club_profile WHERE id = ?");
        $stmt->execute([$clubId]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$club || !$club['league_id']) {
            return false;
        }

        // Check if user can access this league
        return $this->canAccessLeague($club['league_id']);
    }

    /**
     * Get user's accessible league IDs
     *
     * @return array Array of league IDs
     */
    public function getAccessibleLeagueIds() {
        // Super admins can access all leagues (return null to indicate "all")
        if ($this->isSuperAdmin()) {
            return null;
        }

        $leagueIds = [];
        $rolesArray = is_array($this->roles) ? $this->roles : (array)$this->roles;

        foreach ($rolesArray as $role) {
            $roleArray = (array)$role;

            // Add league scope IDs
            if (($roleArray['scope_type'] ?? null) === 'league') {
                $leagueIds[] = $roleArray['scope_id'] ?? null;
            }

            // Add league IDs from club roles
            if (($roleArray['scope_type'] ?? null) === 'club' && isset($roleArray['league_id'])) {
                $leagueIds[] = $roleArray['league_id'];
            }
        }

        return array_unique(array_filter($leagueIds));
    }

    /**
     * Get user's accessible club IDs
     *
     * @return array|null Array of club IDs, or null if user can access all clubs
     */
    public function getAccessibleClubIds() {
        // Super admins can access all clubs
        if ($this->isSuperAdmin()) {
            return null;
        }

        $clubIds = [];
        $rolesArray = is_array($this->roles) ? $this->roles : (array)$this->roles;

        foreach ($rolesArray as $role) {
            $roleArray = (array)$role;

            // Add club scope IDs
            if (($roleArray['scope_type'] ?? null) === 'club') {
                $clubIds[] = $roleArray['scope_id'] ?? null;
            }
        }

        return array_unique(array_filter($clubIds));
    }

    /**
     * Get WHERE clause for scoping queries by accessible clubs
     *
     * @param PDO $connection Database connection
     * @param string $clubColumnName Name of the club_id column (default: 'club_id')
     * @return array Array with 'where' (SQL string) and 'params' (values for prepared statement)
     */
    public function getClubScopeWhereClause($connection, $clubColumnName = 'club_id') {
        // Super admins see everything
        if ($this->isSuperAdmin()) {
            return ['where' => '', 'params' => []];
        }

        $clubIds = $this->getAccessibleClubIds();

        // If user has direct club access, use those club IDs
        if (!empty($clubIds)) {
            $placeholders = implode(',', array_fill(0, count($clubIds), '?'));
            return [
                'where' => "AND $clubColumnName IN ($placeholders)",
                'params' => $clubIds
            ];
        }

        // Check if user has league admin access
        $leagueIds = $this->getAccessibleLeagueIds();
        if (!empty($leagueIds)) {
            // Get all club IDs in accessible leagues
            $placeholders = implode(',', array_fill(0, count($leagueIds), '?'));
            $stmt = $connection->prepare("SELECT id FROM club_profile WHERE league_id IN ($placeholders)");
            $stmt->execute($leagueIds);
            $clubs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($clubs)) {
                $placeholders = implode(',', array_fill(0, count($clubs), '?'));
                return [
                    'where' => "AND $clubColumnName IN ($placeholders)",
                    'params' => $clubs
                ];
            }
        }

        // No access - return impossible condition
        return ['where' => 'AND 1=0', 'params' => []];
    }

    /**
     * Get WHERE clause for scoping queries by accessible leagues
     *
     * @param string $leagueColumnName Name of the league_id column (default: 'league_id')
     * @return array Array with 'where' (SQL string) and 'params' (values for prepared statement)
     */
    public function getLeagueScopeWhereClause($leagueColumnName = 'league_id') {
        // Super admins see everything
        if ($this->isSuperAdmin()) {
            return ['where' => '', 'params' => []];
        }

        $leagueIds = $this->getAccessibleLeagueIds();

        if (empty($leagueIds)) {
            // No access - return impossible condition
            return ['where' => 'AND 1=0', 'params' => []];
        }

        $placeholders = implode(',', array_fill(0, count($leagueIds), '?'));
        return [
            'where' => "AND $leagueColumnName IN ($placeholders)",
            'params' => $leagueIds
        ];
    }

    /**
     * Check if user can perform an action based on role and scope
     *
     * @param string $action Action to check (e.g., 'create_team', 'edit_club', 'delete_league')
     * @param int|null $scopeId Scope ID (league or club ID)
     * @param string|null $scopeType Scope type ('league' or 'club')
     * @return bool
     */
    public function can($action, $scopeId = null, $scopeType = null) {
        // Super admins can do everything
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Map actions to required roles
        $actionRoleMap = [
            // League-level actions
            'create_league' => ['super_admin'],
            'edit_league' => ['super_admin', 'league_admin'],
            'delete_league' => ['super_admin'],
            'manage_league' => ['super_admin', 'league_admin'],

            // Club-level actions
            'create_club' => ['super_admin', 'league_admin'],
            'edit_club' => ['super_admin', 'league_admin', 'club_admin'],
            'delete_club' => ['super_admin', 'league_admin'],
            'manage_club' => ['super_admin', 'league_admin', 'club_admin'],

            // Team-level actions
            'create_team' => ['super_admin', 'league_admin', 'club_admin'],
            'edit_team' => ['super_admin', 'league_admin', 'club_admin', 'coach'],
            'delete_team' => ['super_admin', 'league_admin', 'club_admin'],
            'view_team' => ['super_admin', 'league_admin', 'club_admin', 'coach', 'parent'],

            // Athlete-level actions
            'register_athlete' => ['super_admin', 'league_admin', 'club_admin', 'parent'],
            'edit_athlete' => ['super_admin', 'league_admin', 'club_admin', 'parent'],
            'view_athlete' => ['super_admin', 'league_admin', 'club_admin', 'coach', 'parent'],
        ];

        $requiredRoles = $actionRoleMap[$action] ?? [];

        if (empty($requiredRoles)) {
            error_log("AuthMiddleware: Unknown action '$action'");
            return false;
        }

        // Check if user has any of the required roles in the appropriate scope
        foreach ($requiredRoles as $requiredRole) {
            if ($scopeId && $scopeType) {
                if ($this->hasRole($requiredRole, $scopeId, $scopeType)) {
                    return true;
                }
            } else {
                if ($this->hasRole($requiredRole)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Require authentication - send 401 if not authenticated
     *
     * @return void Exits with 401 if not authenticated
     */
    public static function requireAuth() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader) {
            http_response_code(401);
            echo json_encode(['error' => 'Authorization header required']);
            exit;
        }

        $middleware = new self();
        if (!$middleware->validateToken($authHeader)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            exit;
        }

        return $middleware;
    }

    // Getters
    public function getUserId() { return $this->userId; }
    public function getSystemRole() { return $this->systemRole; }
    public function getOrgId() { return $this->orgId; }
    public function getOrgType() { return $this->orgType; }
    public function getRoles() { return $this->roles; }
    public function getActiveContext() { return $this->activeContext; }
    public function getPayload() { return $this->payload; }
}

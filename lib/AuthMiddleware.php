<?php
/**
 * Authentication and Authorization Middleware
 *
 * Validates JWT tokens and enforces role-based access control (RBAC)
 * with organizational scoping (club hierarchy).
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
     * @param string $role Role to check (e.g., 'club_admin', 'coach', 'parent', 'player')
     * @param int|null $scopeId Optional scope ID to check against
     * @param string|null $scopeType Optional scope type ('club')
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

        return false;
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
     * @param string $clubColumnName Name of the club_id column (default: 'club_id')
     * @return array Array with 'where' (SQL string) and 'params' (values for prepared statement)
     */
    public function getClubScopeWhereClause($clubColumnName = 'club_id') {
        // Super admins see everything
        if ($this->isSuperAdmin()) {
            return ['where' => '', 'params' => []];
        }

        $clubIds = $this->getAccessibleClubIds();

        // If user has club access, use those club IDs
        if (!empty($clubIds)) {
            $placeholders = implode(',', array_fill(0, count($clubIds), '?'));
            return [
                'where' => "AND $clubColumnName IN ($placeholders)",
                'params' => $clubIds
            ];
        }

        // No access - return impossible condition
        return ['where' => 'AND 1=0', 'params' => []];
    }

    /**
     * Check if user can perform an action based on role and scope
     *
     * @param string $action Action to check (e.g., 'create_team', 'edit_club')
     * @param int|null $scopeId Scope ID (club ID)
     * @param string|null $scopeType Scope type ('club')
     * @return bool
     */
    public function can($action, $scopeId = null, $scopeType = null) {
        // Super admins can do everything
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Map actions to required roles
        $actionRoleMap = [
            // Club-level actions
            'create_club' => ['super_admin'],
            'edit_club' => ['super_admin', 'club_admin'],
            'delete_club' => ['super_admin'],
            'manage_club' => ['super_admin', 'club_admin'],

            // Team-level actions
            'create_team' => ['super_admin', 'club_admin'],
            'edit_team' => ['super_admin', 'club_admin', 'coach'],
            'delete_team' => ['super_admin', 'club_admin'],
            'view_team' => ['super_admin', 'club_admin', 'coach', 'parent'],

            // Athlete-level actions
            'register_athlete' => ['super_admin', 'club_admin', 'parent'],
            'edit_athlete' => ['super_admin', 'club_admin', 'parent'],
            'view_athlete' => ['super_admin', 'club_admin', 'coach', 'parent'],
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

    /**
     * Build an instance from an explicit context, bypassing JWT verification.
     *
     * Intended for unit tests and internal callers that have already resolved
     * the user's context by other means. Does NOT validate a token — never use
     * this on the request path in place of validateToken()/requireAuth().
     *
     * @param array $context keys: user_id, system_role, org_id, org_type,
     *                       roles (array), active_context, email
     * @return self
     */
    public static function fromContext(array $context) {
        $m = new self();
        $m->userId = $context['user_id'] ?? null;
        $m->systemRole = $context['system_role'] ?? 'user';
        $m->orgId = $context['org_id'] ?? null;
        $m->orgType = $context['org_type'] ?? null;
        $m->roles = $context['roles'] ?? [];
        $m->activeContext = $context['active_context'] ?? null;
        // Synthesize a payload object so getPayload()->email works for callers
        // that read the guardian email from the payload.
        $m->payload = (object) [
            'user_id' => $m->userId,
            'email' => $context['email'] ?? null,
            'system_role' => $m->systemRole,
            'roles' => $m->roles,
        ];
        return $m;
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

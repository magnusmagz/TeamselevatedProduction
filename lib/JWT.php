<?php
/**
 * JWT (JSON Web Token) Library
 *
 * Handles creation and verification of JWTs for authentication.
 * Supports both HS256 (HMAC) and RS256 (RSA) algorithms based on JWT_ALGORITHM environment variable.
 * - HS256: Uses shared secret (JWT_SECRET)
 * - RS256: Uses RSA private/public key pair
 */

require_once __DIR__ . '/feature_flags.php';

class JWT {
    private static $privateKey = null;
    private static $publicKey = null;
    private static $keyId = 'teamselevated-key-1';

    /**
     * Generate a JWT token with enhanced organizational context
     *
     * @param PDO $connection Database connection
     * @param int|string $userId User's database ID
     * @param string $email User's email
     * @param string $name User's full name
     * @param int|null $activeContextScopeId Optional specific scope to set as active context
     * @param string|null $activeContextType Optional scope type ('club')
     * @return string Signed JWT token with full organizational context
     */
    public static function generateEnhanced($connection, $userId, $email, $name, $activeContextScopeId = null, $activeContextType = null) {
        // Build organizational context
        $orgContext = self::buildOrganizationalContext($connection, $userId, $activeContextScopeId, $activeContextType);

        // Generate token with enhanced payload
        return self::generate($userId, $email, $name, $orgContext);
    }

    /**
     * Build organizational context for a user
     *
     * @param PDO $connection Database connection
     * @param int|string $userId User's database ID
     * @param int|null $activeContextScopeId Optional scope ID to set as active
     * @param string|null $activeContextType Optional scope type ('club')
     * @return array Organizational context with roles and active context
     */
    public static function buildOrganizationalContext($connection, $userId, $activeContextScopeId = null, $activeContextType = null) {
        return self::composeContext(
            self::loadRoleSet($connection, $userId),
            $activeContextScopeId,
            $activeContextType
        );
    }

    /**
     * The DATABASE half of the organizational context: system role + every
     * club-scoped role, in precedence order.
     *
     * Split out of buildOrganizationalContext() for G2 so it can be CACHED.
     * What is in here is scope-independent — it does not depend on which club
     * the request asked to be active — which is exactly the property that makes
     * it cacheable per user. Everything that does depend on the requested scope
     * lives in composeContext() and is recomputed on every request.
     *
     * @return array{system_role:string,roles:array<int,array>}
     */
    public static function loadRoleSet($connection, $userId) {
        // Get user's system role
        $stmt = $connection->prepare("SELECT system_role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $systemRole = $user['system_role'] ?? 'user';

        // Get all club-level roles
        $stmt = $connection->prepare("
            SELECT uca.role, uca.club_profile_id as club_id, c.name as club_name
            FROM user_club_access uca
            JOIN club_profile c ON uca.club_profile_id = c.id
            -- revoked_at is checked as well as active: the two can disagree, and
            -- when they do the revocation is the newer fact. One live row had
            -- active = TRUE with revoked_at set (2026-07-08), so a role that had
            -- been taken away was still being minted into that user's token.
            WHERE uca.user_id = ? AND uca.active = TRUE AND uca.revoked_at IS NULL
            -- ORDER BY is load-bearing. The active context is picked as roles[0]
            -- below, and without this that was PHYSICAL ROW ORDER — so which role
            -- a dual-role user 'is' was undefined and could change after a row
            -- update or a vacuum, with nothing in the code or their access having
            -- changed. Seven accounts hold two roles in one club.
            --
            -- Most privileged wins, so the answer is never a downgrade:
            -- club_admin > treasurer > coach > volunteer > parent > player.
            -- The frontend's OrgContext derives isClubAdmin (and therefore the
            -- whole admin nav) from this pick; backend authorization does NOT —
            -- AuthMiddleware::hasRole() checks every role and is unaffected.
            --
            -- club_profile_id breaks ties so a user in several clubs also lands
            -- somewhere stable rather than wherever the rows happened to sit.
            ORDER BY CASE uca.role
                        WHEN 'club_admin' THEN 1
                        WHEN 'treasurer'  THEN 2
                        WHEN 'coach'      THEN 3
                        WHEN 'volunteer'  THEN 4
                        WHEN 'parent'     THEN 5
                        WHEN 'player'     THEN 6
                        ELSE 99
                     END,
                     uca.club_profile_id
        ");
        $stmt->execute([$userId]);
        $clubRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build roles array
        $roles = [];
        $clubsWithRole = [];
        foreach ($clubRoles as $cr) {
            $roles[] = [
                'role' => $cr['role'],
                'scope_type' => 'club',
                'scope_id' => (int)$cr['club_id'],
                'scope_name' => $cr['club_name']
            ];
            $clubsWithRole[(int)$cr['club_id']] = true;
        }

        // Derive 'coach' role from team-level coaching: anyone who is a team's
        // primary_coach_id, or has an active team_members row with role
        // assistant_coach/team_manager, gets a club-scoped 'coach' role for that
        // team's club. Skip clubs where the user already has any role from
        // user_club_access (admin/parent/etc takes precedence).
        //
        // ⚠️ This is a UNION of two USER-BOUNDED queries, not one LEFT JOIN, and
        // that is the G2 change. The old form left-joined `team_members` onto the
        // whole `teams` table and filtered afterwards, so every request by every
        // user scanned every team on the platform — cost that grows with the
        // product rather than with the person. Both branches here start from the
        // user's own id.
        //
        // It is NOT bounded by the user's `user_club_access` clubs, which would
        // be the obvious way to "scope" it and is wrong: the loop below SKIPS any
        // club the user already holds a role in, so bounding to those clubs makes
        // this entire derivation dead code. A coach whose only standing is a team
        // membership (nine live accounts) would lose their role outright.
        $stmt = $connection->prepare("
            SELECT t.club_id AS club_id, c.name AS club_name
            FROM teams t
            JOIN club_profile c ON c.id = t.club_id
            WHERE t.deleted_at IS NULL
              AND t.primary_coach_id = ?
            UNION
            SELECT t.club_id AS club_id, c.name AS club_name
            FROM teams t
            JOIN club_profile c ON c.id = t.club_id
            JOIN team_members tm ON tm.team_id = t.id
            WHERE t.deleted_at IS NULL
              AND tm.user_id = ?
              AND tm.role IN ('assistant_coach', 'team_manager')
              AND tm.status = 'active'
            ORDER BY 1
        ");
        $stmt->execute([$userId, $userId]);
        $coachClubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($coachClubs as $cc) {
            $clubId = (int)$cc['club_id'];
            if (isset($clubsWithRole[$clubId])) {
                continue;
            }
            $roles[] = [
                'role' => 'coach',
                'scope_type' => 'club',
                'scope_id' => $clubId,
                'scope_name' => $cc['club_name']
            ];
            $clubsWithRole[$clubId] = true;
        }

        return [
            'system_role' => $systemRole,
            'roles' => $roles,
        ];
    }

    /**
     * The PURE half: pick the active context out of a role set and derive the
     * backward-compatible org_* claims from it. No database access, so a cached
     * role set and a freshly loaded one compose identically.
     *
     * @param array{system_role?:string,roles?:array} $roleSet
     */
    public static function composeContext(array $roleSet, $activeContextScopeId = null, $activeContextType = null) {
        $systemRole = $roleSet['system_role'] ?? 'user';
        $roles = $roleSet['roles'] ?? [];

        // Determine active context
        $activeContext = null;
        if ($activeContextScopeId && $activeContextType) {
            // Use provided context
            foreach ($roles as $role) {
                if ($role['scope_id'] == $activeContextScopeId && $role['scope_type'] == $activeContextType) {
                    $activeContext = $role;
                    break;
                }
            }
        }

        // If no active context set or not found, use first available role
        if (!$activeContext && !empty($roles)) {
            $activeContext = $roles[0];
        }

        // Determine primary organization (for backward compatibility)
        $orgId = null;
        $orgType = null;
        $orgName = null;

        if ($activeContext) {
            $orgId = $activeContext['scope_id'];
            $orgType = $activeContext['scope_type'];
            $orgName = $activeContext['scope_name'] ?? null;
        }

        return [
            'system_role' => $systemRole,
            'org_id' => $orgId,
            'org_type' => $orgType,
            'org_name' => $orgName,
            'roles' => $roles,
            'active_context' => $activeContext
        ];
    }

    /**
     * Roles kept inside the token when the diet is on.
     *
     * A slim role entry is ~55 bytes of JSON; the rest of the payload (identity,
     * the standard claims, org_* and a full active_context) is ~450. 40 roles
     * therefore lands a whole token around 3.4 KB, comfortably inside the 4 KB
     * budget and far inside the router's header limit. See SlimTokenTest, which
     * measures rather than trusts this arithmetic.
     */
    const TOKEN_ROLE_CAP = 40;

    /**
     * Shrink the organizational claims to something a 270-council admin can
     * actually carry in an Authorization header.
     *
     * Two changes, both behind TE_FEATURE_SLIM_TOKEN:
     *   - `scope_name` is dropped from every entry in `roles`. It is display
     *     text; nothing in AuthMiddleware reads it. The frontend gets the names
     *     from api/my-context.php.
     *   - `roles` is capped at TOKEN_ROLE_CAP entries, and `roles_truncated`
     *     is set when it was. The claim exists so the frontend can tell "this
     *     user has 40 roles" from "this token is showing you 40 of 300" —
     *     without it a picker would silently list a prefix.
     *
     * `active_context` is left INTACT, names and all: it is one object, the nav
     * renders from it, and it is the one role that must never be the entry the
     * cap threw away. For the same reason the active role is moved to the front
     * of the kept slice.
     *
     * ⚠️ This does not weaken authorization. `requireAuth()` re-derives every
     * role from the database on each request (SEC-11); the token's copy is a
     * display convenience and a fallback for a database blip. `user_id` is
     * untouched and still a STRING — see the id-type rule in CLAUDE.md.
     */
    public static function applyTokenDiet(array $claims) {
        if (!te_feature_enabled('SLIM_TOKEN')) {
            return $claims;
        }
        if (!isset($claims['roles']) || !is_array($claims['roles'])) {
            return $claims;
        }

        $active = $claims['active_context'] ?? null;
        $active = is_object($active) ? (array)$active : (is_array($active) ? $active : null);

        $head = [];
        $tail = [];
        foreach ($claims['roles'] as $role) {
            $role = is_object($role) ? (array)$role : (array)$role;
            unset($role['scope_name']);
            $isActive = $active !== null
                && ($role['role'] ?? null) === ($active['role'] ?? null)
                && ($role['scope_type'] ?? null) === ($active['scope_type'] ?? null)
                && ($role['scope_id'] ?? null) == ($active['scope_id'] ?? null);
            if ($isActive && empty($head)) {
                $head[] = $role;
            } else {
                $tail[] = $role;
            }
        }

        $slim = array_merge($head, $tail);
        if (count($slim) > self::TOKEN_ROLE_CAP) {
            $slim = array_slice($slim, 0, self::TOKEN_ROLE_CAP);
            $claims['roles_truncated'] = true;
        }
        $claims['roles'] = array_values($slim);

        return $claims;
    }

    /**
     * Generate a JWT token for authenticated user
     *
     * @param int|string $userId User's database ID
     * @param string $email User's email
     * @param string $name User's full name
     * @param array $additionalClaims Optional additional claims
     * @return string Signed JWT token
     */
    public static function generate($userId, $email, $name, $additionalClaims = []) {
        // G2 token diet. Applied HERE rather than in generateEnhanced() because
        // generate() is the one choke point every mint site passes through —
        // login, magic link, verify-session, switch-context and impersonate.
        // Slimming only the login path would leave the very next verify-session
        // (which runs on every page load) re-minting the fat token, which is
        // the same as not doing it at all.
        $additionalClaims = self::applyTokenDiet($additionalClaims);

        $algorithm = getenv('JWT_ALGORITHM') ?: 'HS256';
        error_log("JWT::generate - Using algorithm: $algorithm");

        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => $algorithm
        ];

        // Only add kid for RS256
        if ($algorithm === 'RS256') {
            $header['kid'] = self::$keyId;
        }

        // Payload with standard claims
        $now = time();
        $payload = array_merge([
            'user_id' => (string)$userId, // Neon expects string
            'email' => $email,
            'name' => $name,
            'iat' => $now,
            'exp' => $now + (24 * 60 * 60), // 24 hours
            'nbf' => $now, // Not before
            'iss' => 'teamselevated', // Issuer
        ], $additionalClaims);

        // Encode header and payload
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // Create signature
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        if ($algorithm === 'HS256') {
            // HMAC-based signature
            $secret = getenv('JWT_SECRET');
            if (!$secret) {
                error_log("JWT::generate - ERROR: JWT_SECRET not configured");
                throw new Exception('JWT_SECRET not configured');
            }
            error_log("JWT::generate - JWT_SECRET found, length: " . strlen($secret));
            $signature = hash_hmac('sha256', $signatureInput, $secret, true);
            $signatureEncoded = self::base64UrlEncode($signature);
            error_log("JWT::generate - HS256 signature created successfully");
        } else {
            // RS256: RSA-based signature
            error_log("JWT::generate - Using RS256, loading private key");
            $signature = '';
            if (!openssl_sign($signatureInput, $signature, self::getPrivateKey(), OPENSSL_ALGO_SHA256)) {
                error_log("JWT::generate - ERROR: Failed to sign JWT with RS256");
                throw new Exception('Failed to sign JWT');
            }
            $signatureEncoded = self::base64UrlEncode($signature);
            error_log("JWT::generate - RS256 signature created successfully");
        }

        return $signatureInput . '.' . $signatureEncoded;
    }

    /**
     * Verify and decode a JWT token
     *
     * @param string $token JWT token to verify
     * @return object|false Decoded payload if valid, false otherwise
     */
    public static function verify($token) {
        try {
            error_log("JWT::verify - Starting token verification");
            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                error_log("JWT::verify - ERROR: Token does not have 3 parts");
                return false;
            }

            list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

            // Decode header to determine algorithm
            $header = json_decode(self::base64UrlDecode($headerEncoded));
            if (!$header || !isset($header->alg)) {
                error_log("JWT::verify - ERROR: Invalid header or missing algorithm");
                return false;
            }

            $algorithm = $header->alg;
            error_log("JWT::verify - Token algorithm: $algorithm");

            // Verify signature
            $signature = self::base64UrlDecode($signatureEncoded);
            $signatureInput = $headerEncoded . '.' . $payloadEncoded;

            if ($algorithm === 'HS256') {
                // HMAC-based verification
                error_log("JWT::verify - Verifying with HS256");
                $secret = getenv('JWT_SECRET');
                if (!$secret) {
                    error_log('JWT::verify - ERROR: JWT_SECRET not configured');
                    return false;
                }
                error_log("JWT::verify - JWT_SECRET found, length: " . strlen($secret));
                $expectedSignature = hash_hmac('sha256', $signatureInput, $secret, true);
                $verified = hash_equals($expectedSignature, $signature);
                error_log("JWT::verify - HS256 verification result: " . ($verified ? 'PASS' : 'FAIL'));
            } elseif ($algorithm === 'RS256') {
                // RSA-based verification
                error_log("JWT::verify - Verifying with RS256");
                $verified = openssl_verify(
                    $signatureInput,
                    $signature,
                    self::getPublicKey(),
                    OPENSSL_ALGO_SHA256
                ) === 1;
                error_log("JWT::verify - RS256 verification result: " . ($verified ? 'PASS' : 'FAIL'));
            } else {
                error_log('JWT::verify - ERROR: Unsupported algorithm ' . $algorithm);
                return false;
            }

            if (!$verified) {
                error_log("JWT::verify - ERROR: Signature verification failed");
                return false;
            }

            // Decode payload
            $payload = json_decode(self::base64UrlDecode($payloadEncoded));

            if (!$payload) {
                error_log("JWT::verify - ERROR: Failed to decode payload");
                return false;
            }

            error_log("JWT::verify - Payload decoded successfully");

            // Check expiration
            if (isset($payload->exp) && $payload->exp < time()) {
                error_log("JWT::verify - ERROR: Token expired");
                return false; // Token expired
            }

            // Check not before
            if (isset($payload->nbf) && $payload->nbf > time()) {
                error_log("JWT::verify - ERROR: Token not yet valid");
                return false; // Token not yet valid
            }

            error_log("JWT::verify - Token verification SUCCESSFUL");
            return $payload;

        } catch (Exception $e) {
            error_log('JWT verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decode a JWT without verification (for debugging only)
     *
     * @param string $token JWT token
     * @return object|false Decoded payload
     */
    public static function decode($token) {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]));
        return $payload ?: false;
    }

    /**
     * Get the private key for signing
     *
     * @return resource OpenSSL private key resource
     */
    private static function getPrivateKey() {
        if (self::$privateKey === null) {
            $keyPath = __DIR__ . '/../keys/private.pem';

            if (!file_exists($keyPath)) {
                throw new Exception('Private key not found. Run setup/generate-keys.php first.');
            }

            $keyContent = file_get_contents($keyPath);
            self::$privateKey = openssl_pkey_get_private($keyContent);

            if (self::$privateKey === false) {
                throw new Exception('Failed to load private key');
            }
        }

        return self::$privateKey;
    }

    /**
     * Get the public key for verification
     *
     * @return resource OpenSSL public key resource
     */
    private static function getPublicKey() {
        if (self::$publicKey === null) {
            $keyPath = __DIR__ . '/../keys/public.pem';

            if (!file_exists($keyPath)) {
                throw new Exception('Public key not found. Run setup/generate-keys.php first.');
            }

            $keyContent = file_get_contents($keyPath);
            self::$publicKey = openssl_pkey_get_public($keyContent);

            if (self::$publicKey === false) {
                throw new Exception('Failed to load public key');
            }
        }

        return self::$publicKey;
    }

    /**
     * Base64 URL encode (JWT standard)
     *
     * @param string $data Data to encode
     * @return string Base64 URL encoded string
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode (JWT standard)
     *
     * @param string $data Data to decode
     * @return string Decoded string
     */
    private static function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Get the key ID used for signing
     *
     * @return string Key ID
     */
    public static function getKeyId() {
        return self::$keyId;
    }
}

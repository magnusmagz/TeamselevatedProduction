<?php
/**
 * JWT (JSON Web Token) Library
 *
 * Handles creation and verification of JWTs for authentication.
 * Supports both HS256 (HMAC) and RS256 (RSA) algorithms based on JWT_ALGORITHM environment variable.
 * - HS256: Uses shared secret (JWT_SECRET)
 * - RS256: Uses RSA private/public key pair
 */

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
        $stmt = $connection->prepare("
            SELECT DISTINCT t.club_id, c.name AS club_name
            FROM teams t
            JOIN club_profile c ON c.id = t.club_id
            LEFT JOIN team_members tm
                ON tm.team_id = t.id
                AND tm.user_id = ?
                AND tm.role IN ('assistant_coach', 'team_manager')
                AND tm.status = 'active'
            WHERE t.deleted_at IS NULL
              AND (t.primary_coach_id = ? OR tm.id IS NOT NULL)
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
            $orgName = $activeContext['scope_name'];
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
     * Generate a JWT token for authenticated user
     *
     * @param int|string $userId User's database ID
     * @param string $email User's email
     * @param string $name User's full name
     * @param array $additionalClaims Optional additional claims
     * @return string Signed JWT token
     */
    public static function generate($userId, $email, $name, $additionalClaims = []) {
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

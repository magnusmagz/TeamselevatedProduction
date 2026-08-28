<?php
/**
 * Canva Connect API client.
 *
 * Thin transport layer: OAuth, and one method per Canva endpoint we use. Business
 * logic (which template, which merge data, what to do with the PNG) belongs in
 * services/CanvaDesignService.php, not here — same split as StripeGateway.
 *
 * Model is HEADLESS: one token for a service user in OUR Canva Enterprise org.
 * Rationale and schema notes in database/migrations/069_canva_integration.sql.
 *
 * Two Canva behaviours shape this whole file:
 *
 *   1. REFRESH TOKENS ARE SINGLE-USE AND ROTATE. Every refresh invalidates the token
 *      it consumed. Concurrent refreshes therefore disconnect the integration, so the
 *      read-refresh-write cycle runs under a row lock.
 *
 *   2. AUTOFILL AND EXPORT ARE ASYNC JOBS. Both return a job id that must be polled.
 *      Nothing here returns a finished design synchronously, however much the method
 *      name might suggest it.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/Encryption.php';

class CanvaClient
{
    private const API_BASE      = 'https://api.canva.com/rest/v1';
    private const AUTHORIZE_URL = 'https://www.canva.com/api/oauth/authorize';
    private const TOKEN_URL     = 'https://api.canva.com/rest/v1/oauth/token';

    /**
     * Scopes requested at authorize time.
     *
     * Canva does NOT imply one scope from another — asset:write does not grant
     * asset:read. Each is listed explicitly, and adding an endpoint later means
     * re-authorising, not just editing this array: the granted set is fixed at
     * consent time and stored on the row.
     */
    public const SCOPES = [
        'asset:read',
        'asset:write',
        'brandtemplate:meta:read',
        'brandtemplate:content:read',
        'design:meta:read',
        'design:content:read',
        'design:content:write',
        'profile:read',
    ];

    /** Refresh this many seconds before nominal expiry, to cover clock skew + call time. */
    private const REFRESH_SKEW_SECONDS = 120;

    /** @var PDO */
    private $pdo;

    /** @var int|null club_profile_id; null = the platform service account. */
    private $clubProfileId;

    public function __construct(PDO $pdo, ?int $clubProfileId = null)
    {
        $this->pdo = $pdo;
        $this->clubProfileId = $clubProfileId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OAuth
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * PKCE verifier: 43-128 chars from [A-Za-z0-9-._~].
     *
     * base64url of 32 random bytes is 43 chars and uses only that alphabet, so it
     * satisfies the spec without any post-filtering.
     */
    public static function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** URL to send the operator to. State and verifier must be stashed server-side. */
    public static function authorizeUrl(string $state, string $codeVerifier, string $redirectUri): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => self::clientId(),
            'redirect_uri'          => $redirectUri,
            'scope'                 => implode(' ', self::SCOPES),
            'state'                 => $state,
            'code_challenge'        => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Exchange an authorization code for tokens and persist them.
     * Called once, by hand, when connecting the service account.
     */
    public function completeAuthorization(string $code, string $codeVerifier, string $redirectUri): array
    {
        $token = $this->postToken([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
        ]);

        $this->persistToken($token, true);
        return $token;
    }

    /**
     * A valid access token, refreshing if needed.
     *
     * The SELECT ... FOR UPDATE is load-bearing, not defensive: see the rotation
     * warning at the top of this file and in migration 069. The lock is held for the
     * duration of an outbound HTTP call, which is ugly but correct — the alternative
     * is two callers racing and killing the integration.
     */
    public function accessToken(): string
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $row = $this->lockRow();
            if (!$row) {
                throw new RuntimeException(
                    'Canva is not connected' .
                    ($this->clubProfileId ? " for club {$this->clubProfileId}" : ' (platform account)') .
                    '. Run scripts/canva-connect.php to authorise.'
                );
            }

            $expiresAt = strtotime($row['access_token_expires_at']);
            if ($expiresAt - self::REFRESH_SKEW_SECONDS > time()) {
                $token = Encryption::decrypt($row['access_token']);
                if ($ownsTransaction) $this->pdo->commit();
                return $token;
            }

            $fresh = $this->postToken([
                'grant_type'    => 'refresh_token',
                'refresh_token' => Encryption::decrypt($row['refresh_token']),
            ]);

            $this->persistToken($fresh, false);
            if ($ownsTransaction) $this->pdo->commit();

            return $fresh['access_token'];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upload an image into the account's content library by URL.
     *
     * The URL must be publicly reachable by Canva — api/club-logo.php is unauthenticated
     * precisely so email clients can fetch it, which makes it usable here too.
     *
     * Async: returns a job. Poll with waitForAssetUpload().
     * Rate limited by Canva to 30 requests/minute per user, and under the headless
     * model every club shares one user — so this is a platform-wide budget, not a
     * per-club one. Batch club onboarding accordingly.
     */
    public function createAssetUploadFromUrl(string $name, string $url): array
    {
        return $this->request('POST', '/asset-uploads', [
            'name' => $name,
            'url'  => $url,
        ]);
    }

    public function getAssetUploadJob(string $jobId): array
    {
        return $this->request('GET', '/asset-uploads/' . rawurlencode($jobId));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Brand templates
    // ─────────────────────────────────────────────────────────────────────────

    public function listBrandTemplates(?string $continuation = null): array
    {
        $q = $continuation ? '?continuation=' . rawurlencode($continuation) : '';
        return $this->request('GET', '/brand-templates' . $q);
    }

    /**
     * The autofillable fields of a template, and their types.
     *
     * Call this before autofilling. Autofill rejects the whole request on an unknown
     * field name, and the error does not always say which field — so validating the
     * payload against the dataset first turns a confusing 400 into a clear one.
     *
     * Fields are text / image / chart / sheet. There is NO color field type: a club's
     * brand color cannot be autofilled and must live in the template itself.
     */
    public function getBrandTemplateDataset(string $brandTemplateId): array
    {
        return $this->request('GET', '/brand-templates/' . rawurlencode($brandTemplateId) . '/dataset');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Autofill
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start an autofill job.
     *
     * $data maps field name => value, shaped per Canva's dataset types, e.g.
     *   'opponent'   => ['type' => 'text',  'text' => 'Wichita FC']
     *   'club_logo'  => ['type' => 'image', 'asset_id' => 'Msd59349...']
     *
     * Requires the token's user to be a member of a Canva Enterprise organization.
     * A 403 here on a working token almost always means that, not a scope problem.
     */
    public function createDesignAutofillJob(string $brandTemplateId, array $data, ?string $title = null): array
    {
        $payload = [
            'brand_template_id' => $brandTemplateId,
            'data'              => (object) $data,
        ];
        if ($title !== null) {
            $payload['title'] = $title;
        }
        return $this->request('POST', '/autofills', $payload);
    }

    public function getDesignAutofillJob(string $jobId): array
    {
        return $this->request('GET', '/autofills/' . rawurlencode($jobId));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Export
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start an export job. $format is Canva's export format object, e.g.
     *   ['type' => 'png', 'quality' => 'pro']  or  ['type' => 'jpg', 'quality' => 90]
     *
     * The resulting download URLs EXPIRE. Download the bytes in the same run and
     * persist them (club_media_assets.image_data); never store only the URL.
     */
    public function createDesignExportJob(string $designId, array $format): array
    {
        return $this->request('POST', '/exports', [
            'design_id' => $designId,
            'format'    => (object) $format,
        ]);
    }

    public function getDesignExportJob(string $jobId): array
    {
        return $this->request('GET', '/exports/' . rawurlencode($jobId));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Job polling
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Poll an async job to a terminal state.
     *
     * Canva jobs report status in_progress | success | failed. This blocks, so it
     * belongs in the queue worker, not in a web request — a slow export would
     * otherwise hold an HTTP connection open for a minute.
     *
     * @param callable $fetch fn(): array  returns the job envelope
     * @param string   $key   envelope key holding the job ('job')
     */
    public function pollJob(callable $fetch, string $key = 'job', int $timeoutSeconds = 120, int $intervalSeconds = 2): array
    {
        $deadline = time() + $timeoutSeconds;

        while (true) {
            $response = $fetch();
            $job = $response[$key] ?? $response;
            $status = $job['status'] ?? 'unknown';

            if ($status === 'success') {
                return $job;
            }

            if ($status === 'failed') {
                $reason = $job['error']['message'] ?? ($job['error']['code'] ?? 'unknown error');
                throw new RuntimeException('Canva job failed: ' . $reason);
            }

            if (time() >= $deadline) {
                // Deliberately distinct from 'failed': the job may yet succeed on
                // Canva's side, so the caller should record the job id rather than
                // treat the design as lost and regenerate it.
                throw new RuntimeException("Canva job did not finish within {$timeoutSeconds}s (status: {$status})");
            }

            sleep($intervalSeconds);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transport
    // ─────────────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/json',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw   = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($raw === false) {
            throw new RuntimeException("Canva request failed ({$method} {$path}): {$error}");
        }

        $decoded = json_decode($raw, true);

        if ($code >= 400) {
            $message = $decoded['message'] ?? $raw;
            $canvaCode = $decoded['code'] ?? '';

            // Worth naming explicitly — this is the failure everyone will hit first,
            // and "403 Forbidden" reads as a scope bug when it is a plan requirement.
            if ($code === 403 && strpos($path, '/autofills') === 0) {
                $message .= ' — autofill requires the token user to be a member of a '
                          . 'Canva Enterprise organization with MFA enabled.';
            }

            throw new RuntimeException("Canva API {$code} ({$method} {$path}) {$canvaCode}: {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** Token endpoint: form-encoded body, client credentials via HTTP Basic. */
    private function postToken(array $fields): array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode(self::clientId() . ':' . self::clientSecret()),
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($raw === false) {
            throw new RuntimeException("Canva token request failed: {$err}");
        }

        $decoded = json_decode($raw, true);

        if ($code >= 400 || empty($decoded['access_token'])) {
            // A failed REFRESH is the dangerous case: the stored refresh token may now
            // be spent, so the integration is disconnected until re-authorised. Say so,
            // rather than letting it look like a transient network blip.
            $hint = ($fields['grant_type'] ?? '') === 'refresh_token'
                ? ' — the stored refresh token may be spent or superseded; re-run scripts/canva-connect.php'
                : '';
            throw new RuntimeException("Canva token endpoint {$code}: " . ($decoded['message'] ?? $raw) . $hint);
        }

        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence
    // ─────────────────────────────────────────────────────────────────────────

    private function lockRow(): ?array
    {
        $sql = $this->clubProfileId === null
            ? "SELECT * FROM canva_integrations WHERE club_profile_id IS NULL FOR UPDATE"
            : "SELECT * FROM canva_integrations WHERE club_profile_id = ? FOR UPDATE";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->clubProfileId === null ? [] : [$this->clubProfileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Store a token response.
     *
     * The refresh token is written UNCONDITIONALLY when present, because Canva rotates
     * it on every refresh — keeping the old one is the same as disconnecting.
     */
    private function persistToken(array $token, bool $isNewConnection): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + (int) ($token['expires_in'] ?? 3600));

        $access  = Encryption::encrypt($token['access_token']);
        $refresh = !empty($token['refresh_token']) ? Encryption::encrypt($token['refresh_token']) : null;
        $scopes  = $token['scope'] ?? implode(' ', self::SCOPES);

        if ($isNewConnection) {
            // Re-authorising replaces the row outright: the previous lineage is dead
            // the moment a new consent is granted, so keeping it would only offer a
            // stale token for something to pick up.
            $delete = $this->pdo->prepare(
                $this->clubProfileId === null
                    ? "DELETE FROM canva_integrations WHERE club_profile_id IS NULL"
                    : "DELETE FROM canva_integrations WHERE club_profile_id = ?"
            );
            $delete->execute($this->clubProfileId === null ? [] : [$this->clubProfileId]);

            $insert = $this->pdo->prepare(
                "INSERT INTO canva_integrations
                    (club_profile_id, access_token, refresh_token, access_token_expires_at, scopes)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $insert->execute([$this->clubProfileId, $access, $refresh, $expiresAt, $scopes]);
            return;
        }

        $sql = "UPDATE canva_integrations
                   SET access_token = ?,
                       refresh_token = COALESCE(?, refresh_token),
                       access_token_expires_at = ?,
                       scopes = ?,
                       refresh_count = refresh_count + 1,
                       last_refreshed_at = CURRENT_TIMESTAMP,
                       updated_at = CURRENT_TIMESTAMP
                 WHERE " . ($this->clubProfileId === null ? "club_profile_id IS NULL" : "club_profile_id = ?");

        $params = [$access, $refresh, $expiresAt, $scopes];
        if ($this->clubProfileId !== null) {
            $params[] = $this->clubProfileId;
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    private static function clientId(): string
    {
        $v = Env::get('CANVA_CLIENT_ID', '');
        if ($v === '') throw new RuntimeException('CANVA_CLIENT_ID is not configured');
        return $v;
    }

    private static function clientSecret(): string
    {
        $v = Env::get('CANVA_CLIENT_SECRET', '');
        if ($v === '') throw new RuntimeException('CANVA_CLIENT_SECRET is not configured');
        return $v;
    }
}

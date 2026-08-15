<?php
/**
 * Support ticket helpers.
 *
 * Split out of api/support-gateway.php so the parts that decide whether to accept
 * something — attachment validation, rate limiting, device summarising — are unit
 * testable without a webhook, a browser or a live database.
 */

/** Longest description we store. Longer is truncated, never rejected. */
const TE_SUPPORT_MAX_DESCRIPTION = 5000;

/** Server-side attachment ceiling, AFTER the client downscales. */
const TE_SUPPORT_MAX_ATTACHMENT_BYTES = 2 * 1024 * 1024; // 2 MB

/** How long a screenshot link works for. */
const TE_SUPPORT_LINK_TTL_DAYS = 90;

/** Tickets allowed per reporter per hour. */
const TE_SUPPORT_RATE_LIMIT = 5;

/**
 * Image types we accept.
 *
 * Allowlist, never a blocklist, and matched against the DECODED BYTES rather than
 * the filename or the data-URI label — both are attacker-controlled. api/upload.php
 * already gets this right with finfo; this is the same check for base64 input.
 */
const TE_SUPPORT_ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

if (!function_exists('te_support_client_ip')) {
    /**
     * Originating client IP.
     *
     * Behind Heroku's router REMOTE_ADDR is the proxy, so prefer the first entry
     * of X-Forwarded-For. Same rule as lib/AuditLogger.php — kept identical
     * deliberately so the two agree about who someone is.
     */
    function te_support_client_ip(): ?string
    {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($fwd) && $fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '') {
                return substr($first, 0, 45);
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        return $remote ? substr((string) $remote, 0, 45) : null;
    }

    /**
     * Decode and validate a submitted screenshot.
     *
     * Accepts a bare base64 string or a full `data:image/png;base64,…` URI.
     *
     * @return array{filename:string,mime:string,size:int,base64:string}
     *               |array{error:string,reason:string}
     */
    function te_support_decode_attachment(string $raw, string $filename = 'screenshot'): array
    {
        $raw = trim($raw);

        // Strip a data URI prefix if present. The mime it CLAIMS is discarded —
        // the real type is sniffed from the bytes below.
        if (preg_match('#^data:([a-z0-9.+/-]+);base64,(.*)$#is', $raw, $m)) {
            $raw = $m[2];
        }

        $bytes = base64_decode($raw, true);   // strict: rejects malformed input
        if ($bytes === false || $bytes === '') {
            return ['error' => 'That screenshot could not be read', 'reason' => 'undecodable'];
        }

        $size = strlen($bytes);
        if ($size > TE_SUPPORT_MAX_ATTACHMENT_BYTES) {
            return [
                'error'  => 'That screenshot is too large — please send one under 2 MB',
                'reason' => 'too_large',
            ];
        }

        // Sniff the ACTUAL type. A .png extension on a PDF is still a PDF.
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_buffer($finfo, $bytes) ?: null;
                finfo_close($finfo);
            }
        }
        if ($mime === null) {
            // getimagesizefromstring covers the image types we allow and needs no
            // extension; used only when fileinfo is unavailable (some CLI builds).
            $info = @getimagesizefromstring($bytes);
            $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        }

        if (!$mime || !in_array($mime, TE_SUPPORT_ALLOWED_MIME, true)) {
            return [
                'error'  => 'Screenshots must be a PNG, JPEG, WebP or GIF image',
                'reason' => 'unsupported_type',
            ];
        }

        return [
            'filename' => substr(basename($filename) ?: 'screenshot', 0, 120),
            'mime'     => $mime,
            'size'     => $size,
            'base64'   => base64_encode($bytes),   // re-encode canonically
        ];
    }

    /**
     * Has this reporter filed too many tickets in the last hour?
     *
     * Keyed on the user when we know them, otherwise on IP. Both are needed:
     * `create` is reachable unauthenticated, so a user-only limit would be no
     * limit at all for exactly the callers that need one.
     *
     * Fails OPEN — if the count query errors we accept the ticket. Losing a real
     * report is worse than accepting a duplicate.
     */
    function te_support_is_rate_limited(PDO $pdo, ?int $userId, ?string $ip): bool
    {
        try {
            if ($userId !== null) {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM support_tickets
                     WHERE user_id = ? AND created_at > NOW() - INTERVAL '1 hour'"
                );
                $stmt->execute([$userId]);
            } elseif ($ip !== null && $ip !== '') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM support_tickets
                     WHERE user_id IS NULL AND ip_address = ?
                       AND created_at > NOW() - INTERVAL '1 hour'"
                );
                $stmt->execute([$ip]);
            } else {
                return false;
            }
            return (int) $stmt->fetchColumn() >= TE_SUPPORT_RATE_LIMIT;
        } catch (Throwable $e) {
            error_log('support rate limit check failed, allowing: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * One-line device description for the Slack post.
     *
     * The raw blob is kept on the row; this is the bit a human reads at a glance.
     */
    function te_support_device_summary(array $d): string
    {
        $parts = [];

        $ua = (string) ($d['user_agent'] ?? '');
        if ($ua !== '') {
            $os = 'Unknown OS';
            foreach ([
                'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
                'Mac OS X' => 'macOS', 'Windows' => 'Windows', 'Linux' => 'Linux',
            ] as $needle => $label) {
                if (stripos($ua, $needle) !== false) { $os = $label; break; }
            }

            $browser = 'Unknown browser';
            // Order matters: Edge and Chrome both contain "Safari", Edge contains
            // "Chrome". Most specific first.
            foreach ([
                'Edg' => 'Edge', 'CriOS' => 'Chrome (iOS)', 'FxiOS' => 'Firefox (iOS)',
                'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari',
            ] as $needle => $label) {
                if (stripos($ua, $needle) !== false) { $browser = $label; break; }
            }
            $parts[] = "$browser on $os";
        }

        if (!empty($d['viewport'])) {
            $parts[] = 'viewport ' . $d['viewport'];
        }
        if (isset($d['online']) && $d['online'] === false) {
            $parts[] = '⚠️ offline';
        }
        if (!empty($d['timezone'])) {
            $parts[] = (string) $d['timezone'];
        }

        return $parts ? implode(' · ', $parts) : '—';
    }
}

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

/** Pages of history kept ahead of the one the ticket was filed from. */
const TE_SUPPORT_MAX_TRAIL = 5;

/**
 * Query-string keys whose VALUE never reaches a support ticket.
 *
 * `/reset-password?token=…` and `/verify-magic-link?token=…` carry a live
 * credential in the URL, and a page trail is read by more people, for longer,
 * than a session is ever meant to be. The client redacts these too; this is the
 * copy that matters, because the client's is attacker-controlled.
 */
const TE_SUPPORT_REDACT_PARAMS = [
    'token', 'password', 'passwd', 'secret', 'key', 'code', 'auth',
    'access_token', 'id_token', 'signature', 'sig', 'email', 'session',
];

if (!function_exists('te_support_client_ip')) {
    /**
     * Base URL of THIS API, for building the screenshot link.
     *
     * Not `APP_URL` — that is the FRONTEND (teams-elevated.netlify.app), and the
     * attachment endpoint is PHP on Heroku. Using it produced a Slack link that
     * 404'd on Netlify, which the first end-to-end test caught.
     *
     * Prefers an explicit `API_BASE_URL`. The request-derived fallback exists so a
     * missing config var degrades to a working link rather than a broken one, but
     * `Host` is client-supplied: a poisoned header would put an attacker's domain
     * in front of a support link in our own Slack. Set API_BASE_URL in production
     * and the header is never consulted.
     */
    function te_support_api_base_url(): string
    {
        $configured = getenv('API_BASE_URL') ?: ($_ENV['API_BASE_URL'] ?? '');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) ? 'https' : 'http');
        $proto = str_contains($proto, ',') ? trim(explode(',', $proto)[0]) : $proto;
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

        return $host !== '' ? rtrim("$proto://$host", '/') : '';
    }

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

    /**
     * Strip anything secret out of a URL before it is stored or posted.
     *
     * Keeps the path (that is the whole point of a trail) and keeps harmless
     * query keys, because "which filter were they on" is often the bug. Values
     * of TE_SUPPORT_REDACT_PARAMS keys become `…`, and a path SEGMENT that looks
     * like a credential — long, and made only of token characters — is masked
     * too: `/contribute/<token>` puts one in the path rather than the query.
     */
    function te_support_redact_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Only the path onwards is kept. An absolute URL from another origin has
        // no business in a trail, and the host is already on the ticket.
        $parts = parse_url($url);
        $path  = (string) ($parts['path'] ?? $url);
        $query = (string) ($parts['query'] ?? '');

        $segments = array_map(function (string $seg): string {
            // 20+ chars of nothing but token alphabet, with no vowel-and-hyphen
            // shape to it, is a credential rather than a slug.
            if (strlen($seg) >= 20 && preg_match('/^[A-Za-z0-9._~-]+$/', $seg)
                && !str_contains($seg, ' ')) {
                return '…';
            }
            return $seg;
        }, explode('/', $path));
        $path = implode('/', $segments);

        if ($query !== '') {
            $keptPairs = [];
            foreach (explode('&', $query) as $pair) {
                if ($pair === '') { continue; }
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $lower = strtolower(urldecode($k));
                $isSecret = false;
                foreach (TE_SUPPORT_REDACT_PARAMS as $needle) {
                    if (str_contains($lower, $needle)) { $isSecret = true; break; }
                }
                $keptPairs[] = $isSecret ? ($k . '=…') : ($v === '' ? $k : "$k=$v");
            }
            if ($keptPairs) {
                $path .= '?' . implode('&', $keptPairs);
            }
        }

        return substr($path, 0, 300);
    }

    /**
     * Validate the client-supplied page trail.
     *
     * Shape, length and count are all re-checked here. The trail arrives in the
     * request body from a page anyone can open, so it is untrusted input that
     * gets rendered into our own Slack — an unbounded string or a hundred
     * entries would be a mess at best.
     *
     * Oldest first, newest last, so it reads as a walk toward the problem.
     *
     * @return list<array{path:string,at:?string}>
     */
    function te_support_sanitize_page_trail($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            // Tolerate a bare string as well as {path, at}: a caller sending the
            // simpler shape should get a usable trail, not a silently empty one.
            if (is_string($entry)) {
                $entry = ['path' => $entry];
            }
            if (!is_array($entry)) {
                continue;
            }

            $path = te_support_redact_url((string) ($entry['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $at = null;
            $rawAt = (string) ($entry['at'] ?? '');
            if ($rawAt !== '') {
                $ts = strtotime($rawAt);
                // A client clock can be anything at all, including wrong. An
                // unparseable or absurd timestamp drops the TIME, never the page.
                if ($ts !== false && $ts > strtotime('-1 year') && $ts < strtotime('+1 day')) {
                    $at = gmdate('c', $ts);
                }
            }

            $out[] = ['path' => $path, 'at' => $at];
        }

        // Keep the MOST RECENT five. A long session should surface the steps
        // just before the problem, not the first five pages of the day.
        return array_slice($out, -TE_SUPPORT_MAX_TRAIL);
    }

    /**
     * What the reporter is, resolved from the database.
     *
     * ⚠️ Every role is listed, not just the most privileged one. `lib/JWT.php`
     * picks a single active role by precedence because the nav can only show one
     * app; support is the opposite problem — a coach who is also a parent sees
     * two different surfaces, and which one they were looking at is usually the
     * question. Collapsing them to "club_admin" throws away the answer. See the
     * dual-role section of CLAUDE.md.
     *
     * Parent standing derived from the guardian chain is reported separately,
     * because that mismatch — a `guardians` row whose email is not the one they
     * signed in with — is itself a recurring support case, and a ticket saying
     * "parent (via guardian record)" while the role rows say nothing is the
     * fastest possible diagnosis of it.
     *
     * Fails soft: a query error yields an empty list, never an exception. This
     * runs while filing a bug report, and decorating the ticket must not be able
     * to stop it being filed.
     *
     * @return list<string>
     */
    function te_support_reporter_roles(PDO $pdo, ?int $userId, ?int $clubId = null): array
    {
        if ($userId === null) {
            return [];
        }

        $roles = [];
        try {
            $stmt = $pdo->prepare('SELECT system_role, email FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $systemRole = strtolower(trim((string) ($user['system_role'] ?? '')));
            if ($systemRole !== '' && $systemRole !== 'user') {
                $roles[] = $systemRole;
            }

            // active AND revoked_at, the same pair lib/JWT.php checks — the two
            // columns can disagree, and when they do the revocation is newer.
            $sql = "SELECT DISTINCT role FROM user_club_access
                    WHERE user_id = ? AND active = TRUE AND revoked_at IS NULL";
            $params = [$userId];
            if ($clubId !== null) {
                // Scope to the club they were actually working in when we know
                // it; a role held in some other club is not what they were using.
                $sql .= ' AND club_profile_id = ?';
                $params[] = $clubId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $role) {
                $role = trim((string) $role);
                if ($role !== '' && !in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }

            $email = trim((string) ($user['email'] ?? ''));
            if ($email !== '' && !in_array('parent', $roles, true)) {
                // LOWER() on both sides — a single capital letter in one of the
                // two columns is what silently empties a family's portal.
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM guardians g
                     JOIN athlete_guardians ag ON ag.guardian_id = g.id
                     WHERE LOWER(g.email) = LOWER(?)'
                );
                $stmt->execute([$email]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $roles[] = 'parent (via guardian record)';
                }
            }
        } catch (Throwable $e) {
            error_log('support role lookup failed, continuing without: ' . $e->getMessage());
        }

        return $roles;
    }

    /**
     * The stored / displayed form of the role list.
     *
     * A signed-in user with no roles at all is not the same as an anonymous
     * report, and it is a real state worth seeing on a ticket — it is exactly
     * what "I log in and the app is empty" looks like from this side.
     */
    function te_support_roles_summary(array $roles, bool $signedIn = true): string
    {
        if ($roles) {
            return implode(', ', $roles);
        }
        return $signedIn ? 'no roles assigned' : 'not signed in';
    }

    /**
     * Render the trail for Slack — one page per line, oldest first.
     *
     * Times are relative to now: "4m ago" answers "did they bounce straight
     * here or wander for an hour" without anyone doing arithmetic in their head.
     */
    function te_support_format_page_trail(array $trail, ?int $now = null): string
    {
        if (!$trail) {
            return '';
        }
        $now = $now ?? time();

        $lines = [];
        foreach ($trail as $entry) {
            $line = '• ' . ($entry['path'] ?? '');
            $at = $entry['at'] ?? null;
            if ($at) {
                $ts = strtotime((string) $at);
                if ($ts !== false) {
                    $secs = max(0, $now - $ts);
                    if ($secs < 60) {
                        $rel = $secs . 's ago';
                    } elseif ($secs < 3600) {
                        $rel = intdiv($secs, 60) . 'm ago';
                    } elseif ($secs < 86400) {
                        $rel = intdiv($secs, 3600) . 'h ago';
                    } else {
                        $rel = intdiv($secs, 86400) . 'd ago';
                    }
                    $line .= '  _' . $rel . '_';
                }
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}

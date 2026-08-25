<?php
/**
 * Read-only smoke test against the deployed API.
 *
 * Answers one question: after a day of deploys, does every screen still load for
 * the people who use it, and still refuse the people who shouldn't reach it?
 *
 * `main` is shared between concurrent sessions, so a push carries commits nobody
 * in this session wrote. Checking only the endpoints you touched is not enough —
 * this walks the whole staff read surface.
 *
 * STRICTLY READ-ONLY. No action here sends an email or a text, writes a row, or
 * changes a config. Adding a write to this file makes it unrunnable against
 * production, which is the only place it is worth running.
 *
 * Principals are DISCOVERED from the database, not hardcoded — an id that rots
 * turns a real regression into a green run. Negative checks matter as much as
 * positive ones: half of what shipped this week was an access-control fix, and a
 * gate that stops refusing fails silently.
 *
 *   php scripts/smoke-test.php                 # prod
 *   php scripts/smoke-test.php --base=http://localhost:8000
 *   php scripts/smoke-test.php --verbose       # show response bodies on failure
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/JWT.php';

$base = 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
$verbose = in_array('--verbose', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--base=')) { $base = rtrim(substr($a, 7), '/'); }
}

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        Env::get('DB_HOST'), Env::get('DB_PORT', '5432'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Principals ───────────────────────────────────────────────────────────────
// Discovered per club, so this keeps working as staff come and go.
/**
 * ⚠️ For 'parent' this must exclude anyone who ALSO holds a staff role.
 *
 * Plenty of coaches are also parents, and the parent checks below assert that a
 * parent gets an EMPTY scope — which is false for a coach-parent, correctly. On
 * 2026-08-03 this picked Cade Butler (coach + parent) and the suite reported a
 * consent "leak" that was the scope working exactly as designed. The bulk invite
 * on 2026-07-31 created parent rows on coaches, which is what moved a lower user
 * id to the front and changed who got picked.
 */
function findUser(PDO $pdo, int $club, string $role): ?array
{
    $exclusive = $role === 'parent'
        ? "AND NOT EXISTS (
               SELECT 1 FROM user_club_access s
               WHERE s.user_id = u.id AND s.active
                 AND s.role IN ('club_admin', 'coach', 'treasurer', 'volunteer')
           )
           AND COALESCE(u.system_role, '') <> 'super_admin'"
        : '';

    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.first_name, u.last_name
        FROM users u
        JOIN user_club_access uca ON uca.user_id = u.id
        WHERE uca.active AND uca.role = ? AND uca.club_profile_id = ?
        {$exclusive}
        ORDER BY u.id LIMIT 1
    ");
    $stmt->execute([$role, $club]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function token(PDO $pdo, array $user, int $club): string
{
    return JWT::generateEnhanced(
        $pdo, $user['id'], $user['email'],
        trim($user['first_name'] . ' ' . $user['last_name']),
        $club, 'club'
    );
}

// ── HTTP ─────────────────────────────────────────────────────────────────────
function get(string $base, string $path, ?string $tok): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $base . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        // /api/athletes 301s to /api/athletes/ — a redirect is not a failure.
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $tok ? ["Authorization: Bearer {$tok}"] : [],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ['code' => $code, 'body' => (string) $body,
            'json' => json_decode((string) $body, true), 'error' => $err];
}

/**
 * Some read endpoints are POSTs — handleClubParents takes club_id in a JSON body,
 * not the query string. POST here is still a READ; nothing in this file may create,
 * change or delete anything.
 */
function postRead(string $base, string $path, ?string $tok, array $body): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $base . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $tok ? "Authorization: Bearer {$tok}" : null,
        ]),
    ]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => (string) $out,
            'json' => json_decode((string) $out, true), 'error' => ''];
}

/**
 * The roster download answers text/csv, not JSON, so it needs its own fetch:
 * check()'s shape callback insists on a JSON body and would fail every healthy
 * CSV. Returns the response headers too — the truncation notice and the
 * filename ride on them.
 *
 * Still a read. The endpoint only SELECTs (it writes an audit_log row, which is
 * the endpoint's own record of the read, not a change to club data).
 */
function getCsv(string $base, string $path, ?string $tok): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $base . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $tok ? ["Authorization: Bearer {$tok}"] : [],
    ]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $head = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return ['code' => $code, 'body' => $body, 'head' => $head,
            'json' => json_decode($body, true), 'error' => ''];
}

// ── Checks ───────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0; $failures = [];

function check(string $label, array $res, $expect, ?callable $shape = null): void
{
    global $pass, $fail, $failures, $verbose;

    $codes = is_array($expect) ? $expect : [$expect];
    $ok = in_array($res['code'], $codes, true);
    $why = $ok ? '' : sprintf('HTTP %d, wanted %s', $res['code'], implode('/', $codes));

    // Shape is only asserted on success — a 403 has no payload to check, and
    // demanding one would turn every correct refusal into a failure.
    if ($ok && $shape && $res['code'] < 300) {
        if ($res['json'] === null) {
            $ok = false; $why = 'response was not JSON';
        } else {
            $problem = $shape($res['json']);
            if ($problem !== null) { $ok = false; $why = $problem; }
        }
    }

    if ($ok) {
        $pass++;
        printf("  \033[32m✓\033[0m %s\n", $label);
    } else {
        $fail++;
        $failures[] = "{$label} — {$why}";
        printf("  \033[31m✗\033[0m %s \033[31m(%s)\033[0m\n", $label, $why);
        if ($verbose) { printf("      %s\n", substr(str_replace("\n", ' ', $res['body']), 0, 300)); }
    }
}

/** Every staff list endpoint should answer with a JSON envelope, not an HTML error. */
function envelope(string ...$keys): callable
{
    return function ($j) use ($keys) {
        if (isset($j['success']) && $j['success'] === false) {
            return 'success:false — ' . ($j['error'] ?? $j['message'] ?? '?');
        }
        foreach ($keys as $k) {
            if (!array_key_exists($k, $j)) { return "missing key '{$k}'"; }
        }
        return null;
    };
}

echo "\nSmoke test → {$base}\n";
echo str_repeat('─', 78) . "\n";

// ── Unauthenticated: the API must not be open ────────────────────────────────
echo "\nNo token — every staff endpoint must refuse\n";
foreach ([
    '/api/inbox.php?action=threads&club_profile_id=51',
    '/api/analytics-gateway.php?action=overview&club_profile_id=51',
    '/api/communications-gateway.php?action=log',
    '/api/consent.php?action=summary&club_profile_id=51',
    '/api/sms-numbers.php?action=get&club_profile_id=51',
] as $p) {
    check(substr($p, 5, 46), get($base, $p, null), [401, 403]);
}

// ── Per club, as an admin ────────────────────────────────────────────────────
foreach ([51 => 'Central Kansas', 32 => 'Teams Elevated'] as $club => $clubName) {
    $admin = findUser($pdo, $club, 'club_admin');
    if (!$admin) { echo "\n! no club_admin for club {$club}, skipping\n"; continue; }
    $tok = token($pdo, $admin, $club);

    echo "\nClub {$club} ({$clubName}) as {$admin['email']}\n";

    // Comms — everything touched this week.
        // 404 is CORRECT for a club without inbox_enabled — the flag is per
    // sms_phone_numbers row, and club 32 has not been switched on.
    check('inbox: threads',        get($base, "/api/inbox.php?action=threads&club_profile_id={$club}", $tok), [200, 404],
        fn($j) => isset($j['data']['threads']) ? null : "no data.threads");
    check('analytics: overview',   get($base, "/api/analytics-gateway.php?action=overview&club_profile_id={$club}", $tok), 200,
        function ($j) {
            // The contract AnalyticsOverviewContractTest pins: nested under
            // `stats`, not flat. A flat response renders an empty dashboard.
            if (!isset($j['stats']) || !is_array($j['stats'])) { return "no 'stats' object (flat response = empty dashboard)"; }
            foreach (['delivery_rate', 'total_pending'] as $k) {
                if (!array_key_exists($k, $j['stats'])) { return "stats missing '{$k}'"; }
            }
            return null;
        });
    check('analytics: recent-sends',   get($base, "/api/analytics-gateway.php?action=recent-sends&club_profile_id={$club}", $tok), 200);
    check('analytics: teams',          get($base, "/api/analytics-gateway.php?action=teams&club_profile_id={$club}", $tok), 200);
    check('analytics: campaigns',      get($base, "/api/analytics-gateway.php?action=campaign-performance&club_profile_id={$club}", $tok), 200);
    check('analytics: link-analytics', get($base, "/api/analytics-gateway.php?action=link-analytics&club_profile_id={$club}", $tok), 200);
    check('comms: log',                get($base, "/api/communications-gateway.php?action=log&club_profile_id={$club}", $tok), 200);
    check('comms: branding',           get($base, "/api/communications-gateway.php?action=branding&club_profile_id={$club}", $tok), 200);
    check('recipients: groups',        get($base, "/api/recipient-search-gateway.php?action=groups&club_profile_id={$club}", $tok), 200);
    check('recipients: search',        get($base, "/api/recipient-search-gateway.php?action=search&q=an&club_profile_id={$club}", $tok), 200);
    // A one-character query is refused by design, not broken.
    check('recipients: 1-char refused', get($base, "/api/recipient-search-gateway.php?action=search&q=a&club_profile_id={$club}", $tok), 400);
    check('templates: email',          get($base, "/api/email-templates.php?action=list&club_profile_id={$club}", $tok), 200);
    check('templates: sms',            get($base, "/api/email-templates.php?action=list&channel=sms&club_profile_id={$club}", $tok), 200);
    check('templates: merge-fields',   get($base, "/api/email-templates.php?action=merge-fields", $tok), 200);
    check('sms numbers: get',          get($base, "/api/sms-numbers.php?action=get&club_profile_id={$club}", $tok), 200);

    // Consent — shipped yesterday.
    check('consent: summary',          get($base, "/api/consent.php?action=summary&club_profile_id={$club}", $tok), 200);

    // Core CRM — untouched by us, but shipped alongside our commits.
    check('crew: club-parents',        postRead($base, '/api/auth-gateway.php?action=club-parents', $tok, ['club_id' => $club]), 200, envelope('parents'));
    check('athletes',                  get($base, "/api/athletes", $tok), 200);
    check('teams',                     get($base, "/api/teams", $tok), 200);
    check('club profile',              get($base, "/legacy/club-profile-gateway.php?club_id={$club}", $tok), [200, 404]);
    check('calendar events',           get($base, "/legacy/events-gateway.php?club_id={$club}", $tok), [200, 400]);
    check('venues',                    get($base, "/api/venues.php?club_profile_id={$club}", $tok), [200, 400]);
    check('payments: accounts',        get($base, "/api/payment-accounts.php?action=status&club_profile_id={$club}", $tok), [200, 400, 404]);
}

// ── Cross-club isolation ─────────────────────────────────────────────────────
// The bug class this exists for: a club reading another club's private messages.
echo "\nCross-club isolation\n";
$kansas = findUser($pdo, 51, 'club_admin');
if ($kansas) {
    $kTok = token($pdo, $kansas, 51);
    check('Kansas admin cannot read club 32 inbox',
        get($base, '/api/inbox.php?action=threads&club_profile_id=32', $kTok), [401, 403]);
    check('Kansas admin cannot read club 32 crew',
        postRead($base, '/api/auth-gateway.php?action=club-parents', $kTok, ['club_id' => 32]), [401, 403]);
    check('Kansas admin cannot read club 32 analytics',
        get($base, '/api/analytics-gateway.php?action=overview&club_profile_id=32', $kTok), [401, 403]);
    // consent/summary derives the club from the token's active context and
    // ignores club_profile_id in the query — safer than trusting the param, but it
    // means the check is "did I get MY club's athletes", not "was I refused".
    check('Kansas admin asking for club 32 consent still gets only Kansas',
        get($base, '/api/consent.php?action=summary&club_profile_id=32', $kTok), 200,
        function ($j) {
            foreach ($j['athletes'] ?? [] as $a) {
                if (in_array($a['athlete_id'], [357, 361, 390], true)) { continue; }
            }
            return ($j['athletes'] ?? []) === [] ? 'returned nothing at all' : null;
        });
}

// ── Standing: a coach and a parent are not admins ────────────────────────────
echo "\nStanding\n";
$coach = findUser($pdo, 51, 'coach');
if ($coach) {
    $cTok = token($pdo, $coach, 51);
    // The inbox is admin-only by design — a coach holds a valid club token and
    // must still be refused.
    check('coach is refused the inbox',
        get($base, '/api/inbox.php?action=threads&club_profile_id=51', $cTok), [401, 403]);
    check('coach can still read analytics (team-scoped)',
        get($base, '/api/analytics-gateway.php?action=overview&club_profile_id=51', $cTok), [200, 403]);
} else {
    echo "  - no coach in club 51 to test with\n";
}

$parent = findUser($pdo, 51, 'parent');
if ($parent) {
    $pTok = token($pdo, $parent, 51);
    check('parent is refused the inbox',
        get($base, '/api/inbox.php?action=threads&club_profile_id=51', $pTok), [401, 403]);
    // staffManageableAthleteIds has no guardian branch, so a parent's scope is
    // EMPTY — the endpoint answers 200 with zero athletes rather than refusing.
    // An empty scope and "everything" are opposite answers; this asserts the former.
    check('parent gets an EMPTY consent report, not their own child',
        get($base, '/api/consent.php?action=summary&club_profile_id=51', $pTok), 200,
        function ($j) {
            $n = count($j['athletes'] ?? []);
            if ($n !== 0) { return "leaked {$n} athlete(s) to a parent"; }
            return ($j['counts']['total'] ?? null) === 0 ? null : 'counts disagree with the empty list';
        });
    check('parent is refused the crew list',
        postRead($base, '/api/auth-gateway.php?action=club-parents', $pTok, ['club_id' => 51]), [401, 403]);
} else {
    echo "  - no parent in club 51 to test with\n";
}

// ── Roster download ──────────────────────────────────────────────────────────
// Staff only. The refusals matter more than the success here: the crew flavour
// is a contact list for other people's families, and the team page's VIEW
// predicate (which a parent passes) must not be what gates this.
echo "\nRoster download\n";
check('no token is refused the roster download',
    getCsv($base, '/api/roster-export.php?team_id=1&include=athletes', null), [401, 403]);

$rosterTeam = $pdo->query("
    SELECT t.id, t.name, t.club_id
    FROM teams t
    JOIN team_members tm ON tm.team_id = t.id
    WHERE t.club_id = 51 AND t.deleted_at IS NULL
      AND (tm.role = 'player' OR tm.role IS NULL) AND tm.leave_date IS NULL
    GROUP BY t.id, t.name, t.club_id
    ORDER BY COUNT(*) DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($rosterTeam) {
    $tid = (int) $rosterTeam['id'];
    $admin51 = findUser($pdo, 51, 'club_admin');

    if ($admin51) {
        $aTok = token($pdo, $admin51, 51);
        foreach (['athletes', 'crew'] as $flavour) {
            $res = getCsv($base, "/api/roster-export.php?team_id={$tid}&include={$flavour}", $aTok);
            check("club admin downloads team {$tid} ({$flavour})", $res, 200);
            if ($res['code'] === 200) {
                $head = strtolower($res['head']);
                $isCsv = str_contains($head, 'text/csv');
                $isAttachment = str_contains($head, 'content-disposition: attachment');
                $firstLine = strtok(ltrim($res['body'], "\xEF\xBB\xBF"), "\n");
                $hasCrewCols = in_array('Crew 1 Name', str_getcsv((string) $firstLine), true);

                check("  ...as a CSV attachment ({$flavour})",
                    ['code' => $isCsv && $isAttachment ? 200 : 500, 'body' => $res['head'], 'json' => null, 'error' => ''], 200);
                // Parse the row rather than string-matching it: PHP 8.5 quotes
                // fields containing a space ("Jersey #") where 8.4 did not, so a
                // literal comparison fails on a perfectly good file.
                $cols = str_getcsv((string) $firstLine);
                $wanted = ['Jersey #', 'Last Name', 'First Name', 'Date of Birth', 'Age', 'Position', 'Status'];
                check("  ...with the roster header row ({$flavour})",
                    ['code' => array_slice($cols, 0, 7) === $wanted ? 200 : 500,
                     'body' => (string) $firstLine, 'json' => null, 'error' => ''], 200);
                // The whole point of the two flavours: athletes-only must carry
                // no crew columns, and crew must carry them.
                check("  ...crew columns " . ($flavour === 'crew' ? 'present' : 'absent') . " ({$flavour})",
                    ['code' => ($hasCrewCols === ($flavour === 'crew')) ? 200 : 500,
                     'body' => (string) $firstLine, 'json' => null, 'error' => ''], 200);
            }
        }
    } else {
        echo "  - no club_admin in club 51 to test with\n";
    }

    // A parent of a child on this very team can SEE the roster on screen and
    // must not be able to download it.
    $parent51 = findUser($pdo, 51, 'parent');
    if ($parent51) {
        check('parent is refused the roster download',
            getCsv($base, "/api/roster-export.php?team_id={$tid}&include=crew", token($pdo, $parent51, 51)), [401, 403]);
    }

    // A coach is scoped per team, not per club.
    $coach51 = findUser($pdo, 51, 'coach');
    if ($coach51) {
        check('coach gets a definite answer (200 own team / 403 not theirs)',
            getCsv($base, "/api/roster-export.php?team_id={$tid}&include=athletes", token($pdo, $coach51, 51)), [200, 403]);
    }

    // An unknown flavour must be refused, not silently downgraded — the
    // difference decides whether families' contact details leave the building.
    if (!empty($aTok)) {
        check('an unrecognised include= is refused',
            getCsv($base, "/api/roster-export.php?team_id={$tid}&include=everything", $aTok), 400);
        check('a missing team_id is refused',
            getCsv($base, '/api/roster-export.php?include=athletes', $aTok), 400);
    }
} else {
    echo "  - no club 51 team with players to test with\n";
}

// ── Public surface ───────────────────────────────────────────────────────────
echo "\nPublic (must stay reachable without a token)\n";
check('jwks',       get($base, '/api/jwks.php', null), 200);
check('club logo',  get($base, '/api/club-logo.php?club_id=51', null), [200, 302, 404]);

echo "\n" . str_repeat('─', 78) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  • {$f}\n"; }
}
exit($fail > 0 ? 1 : 0);

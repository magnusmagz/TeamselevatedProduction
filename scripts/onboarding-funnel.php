<?php
/**
 * Onboarding funnel: who needs inviting, who was invited but never signed up, and
 * who is actually on the platform. Covers CREW (guardians) and COACHES.
 *
 * Replaces eyeballing the Crew page, whose `active` badge is an email string match
 * rather than a recorded fact — on 2026-07-31 it reported two coaches as
 * portal-active who had never signed in and had never been invited at all.
 *
 * Evidence, strongest first:
 *   1. audit_log 'login_success'   — hard proof the account was signed into
 *   2. users.last_login_at         — written by the login path; corroborates (1)
 *   3. magic_link_tokens.used_at   — the emailed link was clicked, a password set
 *   4. consent_records             — ConsentGate only fires INSIDE the portal
 *
 * ⚠️ The two audiences are NOT onboarded the same way, and the funnel says so:
 *
 *   - CREW get a real invite (`magic_link_tokens` … ':parent_invite'), so every
 *     stage below is meaningful for them.
 *   - COACHES mostly do NOT. `invitations` holds 7 rows in total across the whole
 *     platform, 2 of them coaches. Coaches are created directly by an admin WITH a
 *     password already set, which is why the funnel gives them their own stage:
 *     ACCOUNT, NEVER INVITED — they can log in, but nobody ever told them they
 *     could. That is a real gap, not a data artifact, and it is invisible on the
 *     Crew page because a password reads as `active`.
 *
 * Read-only.
 *
 *   php scripts/onboarding-funnel.php                       # both, all clubs
 *   php scripts/onboarding-funnel.php --audience=coach
 *   php scripts/onboarding-funnel.php --club=51 --detail
 *   php scripts/onboarding-funnel.php --stage=needs-invite  # the chase list
 */

require_once __DIR__ . '/../config/env.php';

$audience = 'all';
$club     = null;
$stage    = null;
$detail   = in_array('--detail', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--audience=')) { $audience = substr($a, 11); }
    if (str_starts_with($a, '--club='))     { $club = (int) substr($a, 7); }
    if (str_starts_with($a, '--stage='))    { $stage = substr($a, 8); $detail = true; }
}

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        Env::get('DB_HOST'), Env::get('DB_PORT', '5432'), Env::get('DB_NAME')),
    Env::get('DB_USER'), Env::get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/**
 * Shared evidence columns. $emailExpr is whatever identifies the person.
 * Everything is LEFT JOINed: a person with no account is a funnel stage, not a
 * row to drop.
 */
function evidenceSelect(string $emailExpr): string
{
    return "
        u.id  AS user_id,
        u.role AS account_role,
        (u.password_hash IS NOT NULL AND u.password_hash <> '') AS has_password,
        u.last_login_at,
        (SELECT count(*) FROM audit_log al
          WHERE al.action = 'login_success'
            AND al.resource_type = 'users' AND al.resource_id = u.id)  AS logins,
        (SELECT count(*) FROM consent_records cr WHERE cr.guardian_id = u.id) AS consents,
        (SELECT min(t.created_at) FROM magic_link_tokens t
          WHERE t.email = lower(btrim({$emailExpr})) || ':parent_invite')     AS invited_at,
        (SELECT min(t.used_at) FROM magic_link_tokens t
          WHERE t.email = lower(btrim({$emailExpr})) || ':parent_invite')     AS invite_used_at,
        (SELECT count(*) FROM users u2 WHERE lower(u2.email) = lower(btrim({$emailExpr}))) AS accounts_on_email,
        (SELECT count(*) FROM athletes a2 WHERE a2.user_id = u.id)            AS athlete_shells,
        (SELECT string_agg(DISTINCT uca2.role, '/') FROM user_club_access uca2
          WHERE uca2.user_id = u.id AND uca2.active)                          AS all_roles
    ";
}

// ── CREW: every guardian attached to a live athlete ──────────────────────────
$crew = $pdo->query("
    SELECT 'crew' AS audience,
           g.id AS person_id,
           g.first_name || ' ' || g.last_name AS name,
           btrim(coalesce(g.email, '')) AS email,
           (SELECT min(a.club_id) FROM athlete_guardians ag2
             JOIN athletes a ON a.id = ag2.athlete_id AND a.deleted_at IS NULL
            WHERE ag2.guardian_id = g.id) AS club_id,
           " . evidenceSelect('g.email') . "
    FROM guardians g
    LEFT JOIN users u ON lower(u.email) = lower(btrim(g.email)) AND btrim(coalesce(g.email,'')) <> ''
    WHERE EXISTS (SELECT 1 FROM athlete_guardians ag
                  JOIN athletes a ON a.id = ag.athlete_id AND a.deleted_at IS NULL
                  WHERE ag.guardian_id = g.id)
")->fetchAll(PDO::FETCH_ASSOC);

// ── COACHES: an active coach role is the definition; there is no coaches table ─
$coaches = $pdo->query("
    SELECT 'coach' AS audience,
           u.id AS person_id,
           u.first_name || ' ' || u.last_name AS name,
           btrim(coalesce(u.email, '')) AS email,
           uca.club_profile_id AS club_id,
           " . evidenceSelect('u.email') . "
    FROM user_club_access uca
    JOIN users u ON u.id = uca.user_id
    WHERE uca.active AND uca.role = 'coach'
")->fetchAll(PDO::FETCH_ASSOC);

$people = array_merge($crew, $coaches);

/**
 * The funnel. Order matters — each stage is "got this far and no further".
 */
function stageOf(array $p): string
{
    if ($p['email'] === '')                       { return 'NO EMAIL ON FILE'; }

    $loggedIn = (int) $p['logins'] > 0 || $p['last_login_at'] !== null;
    if ($loggedIn) {
        return (int) $p['consents'] > 0 ? 'ON PLATFORM (+consent)' : 'ON PLATFORM';
    }
    if ($p['has_password']) {
        // Distinguishes "clicked the invite but stopped" from "an admin made them
        // an account and never told them" — different follow-ups entirely.
        return $p['invited_at'] ? 'ACCOUNT, NEVER IN' : 'ACCOUNT, NEVER INVITED';
    }
    if ($p['invited_at'])                         { return 'INVITED, NO ACCOUNT'; }
    return 'NEEDS INVITE';
}

/** Reasons a "yes" might not be about this person. */
function caveats(array $p): array
{
    $c = [];
    if ((int) $p['accounts_on_email'] > 1) { $c[] = $p['accounts_on_email'] . ' accounts share this email'; }
    if ((int) $p['athlete_shells'] > 0)    { $c[] = "an athlete points at this account"; }
    $roles = array_filter(explode('/', (string) $p['all_roles']));
    if ($p['audience'] === 'crew' && array_diff($roles, ['parent'])) {
        $c[] = 'also ' . implode('/', array_diff($roles, ['parent']));
    }
    return $c;
}

$ORDER = ['NEEDS INVITE', 'INVITED, NO ACCOUNT', 'ACCOUNT, NEVER INVITED',
          'ACCOUNT, NEVER IN', 'ON PLATFORM', 'ON PLATFORM (+consent)', 'NO EMAIL ON FILE'];

$byClub = [];
$rows = [];
foreach ($people as $p) {
    $s = stageOf($p);
    $cid = $p['club_id'] ?? 0;
    $byClub[$cid][$p['audience']][$s] = ($byClub[$cid][$p['audience']][$s] ?? 0) + 1;
    $rows[] = $p + ['stage' => $s, 'caveats' => caveats($p)];
}

$clubNames = [];
foreach ($pdo->query("SELECT id, name FROM club_profile") as $c) { $clubNames[$c['id']] = $c['name']; }

$stageFilter = [
    'needs-invite' => 'NEEDS INVITE',
    'no-account'   => 'INVITED, NO ACCOUNT',
    'never-in'     => 'ACCOUNT, NEVER IN',
    'never-told'   => 'ACCOUNT, NEVER INVITED',
    'active'       => 'ON PLATFORM',
][$stage] ?? null;

// ── Summary ──────────────────────────────────────────────────────────────────
ksort($byClub);
foreach ($byClub as $cid => $aud) {
    if ($club !== null && (int) $cid !== $club) { continue; }
    if (!$cid) { continue; }
    printf("\n%s  (club %d)\n", strtoupper($clubNames[$cid] ?? 'unknown club'), $cid);
    echo str_repeat('─', 74), "\n";
    foreach (['crew', 'coach'] as $a) {
        if ($audience !== 'all' && $audience !== $a) { continue; }
        if (empty($aud[$a])) { continue; }
        $total = array_sum($aud[$a]);
        printf("  %s  (%d)\n", strtoupper($a), $total);
        foreach ($ORDER as $s) {
            if (empty($aud[$a][$s])) { continue; }
            $n = $aud[$a][$s];
            printf("    %-24s %4d   %s\n", $s, $n,
                str_repeat('█', (int) round($n / max($total, 1) * 34)));
        }
        echo "\n";
    }
}

// ── Detail ───────────────────────────────────────────────────────────────────
if ($detail) {
    usort($rows, fn($x, $y) => [$x['club_id'], $x['audience'], array_search($x['stage'], $GLOBALS['ORDER']), $x['name']]
                          <=> [$y['club_id'], $y['audience'], array_search($y['stage'], $GLOBALS['ORDER']), $y['name']]);
    printf("\n%-24s %-6s %-30s %-23s %s\n", 'NAME', 'TYPE', 'EMAIL', 'STAGE', 'NOTE');
    echo str_repeat('─', 118), "\n";
    $shown = 0;
    foreach ($rows as $r) {
        if ($club !== null && (int) $r['club_id'] !== $club) { continue; }
        if ($audience !== 'all' && $r['audience'] !== $audience) { continue; }
        if ($stageFilter && !str_starts_with($r['stage'], $stageFilter)) { continue; }
        $note = $r['caveats'] ? '** ' . implode('; ', $r['caveats'])
              : ($r['last_login_at'] ? 'last in ' . substr($r['last_login_at'], 0, 16)
              : ($r['invited_at'] ? 'invited ' . substr($r['invited_at'], 0, 10) : ''));
        printf("%-24s %-6s %-30s %-23s %s\n",
            substr((string) $r['name'], 0, 23), $r['audience'],
            substr($r['email'] ?: '—', 0, 29), $r['stage'], substr($note, 0, 40));
        $shown++;
    }
    echo "\n{$shown} row(s).\n";
}

echo "\nACCOUNT, NEVER INVITED = the account has a password but no invite was ever\n";
echo "sent. They can sign in and were never told. Usual for coaches (admins create\n";
echo "them with a password); for CREW it normally means the address also belongs to\n";
echo "a staff account, which is the case the Crew page misreads as 'active'.\n";
echo "'** ' notes mean the evidence may not be about that person -- check before\n";
echo "telling a club they are on the platform.\n";

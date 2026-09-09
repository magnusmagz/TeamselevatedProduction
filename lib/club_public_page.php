<?php
/**
 * The public club link page — /club/<slug> (2026-09-09).
 *
 * Everything api/club-public-gateway.php serves comes from here, and every
 * query in this file is an EXPLICIT column allowlist. This is a public,
 * unauthenticated surface: `SELECT *` is how api/sponsors.php leaked sponsor
 * contact details and api/tournament-public-gateway.php leaked venue gate
 * codes. Nothing in this file may name a column it does not intend a stranger
 * to read.
 *
 * What is public, decided with Maggie 2026-09-09:
 *   - club:    name, tagline, phone, website, city + state, six socials,
 *              logo, two brand colours. NOT the club email (the contact form
 *              is the contact path), NOT the street address (Maggie 2026-09-09:
 *              no directions feature) and NOT description.
 *   - sponsors: name, website, logo. NOT contact_*.
 *   - coaches: one entry per person: name, role, photo, the teams they coach.
 *              NOT email, phone or bio, and never a player — team_members is
 *              joined for coach roles only. (Maggie 2026-09-09: "Our coaches",
 *              a 3×3 grid, not a teams list.)
 *   - events:  games and tournaments only, upcoming, not cancelled: name,
 *              type, date, times, opponent, venue name, field name. NOT
 *              description (gate codes, "bring Emma's inhaler"), NOT the free
 *              text `location` (a private residence), NOT attendees, RSVPs or
 *              referee columns.
 *
 * Slug rule (te_club_slug_from_name / te_club_slug_valid) mirrors the
 * backfill in migration 101 so the two cannot generate different URLs.
 */

require_once __DIR__ . '/event_field.php';
require_once __DIR__ . '/club_admins.php';

const TE_CLUB_PUBLIC_EVENT_TYPES = ['game', 'tournament'];
const TE_CLUB_PUBLIC_EVENTS_DEFAULT = 10;
const TE_CLUB_PUBLIC_EVENTS_MAX = 100;
const TE_CLUB_CONTACT_RATE_LIMIT = 5;          // per IP, per hour
const TE_CLUB_CONTACT_MAX_NAME = 100;
const TE_CLUB_CONTACT_MAX_EMAIL = 254;
const TE_CLUB_CONTACT_MAX_PHONE = 40;
const TE_CLUB_CONTACT_MAX_MESSAGE = 2000;
const TE_CLUB_SLUG_MIN = 3;
const TE_CLUB_SLUG_MAX = 60;

// ---------------------------------------------------------------- slugs

/** lowercase, [^a-z0-9]+ -> '-', trimmed, capped. '' when nothing survives. */
function te_club_slug_from_name(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    $s = substr($s, 0, TE_CLUB_SLUG_MAX);
    return trim($s, '-');
}

function te_club_slug_valid(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,58})[a-z0-9]$/', $slug)
        && strlen($slug) >= TE_CLUB_SLUG_MIN && strlen($slug) <= TE_CLUB_SLUG_MAX;
}

/** Is this slug held by a DIFFERENT club? Case-insensitive, like the unique index. */
function te_club_slug_taken(PDO $pdo, string $slug, ?int $exceptClubId = null): bool
{
    $stmt = $pdo->prepare('SELECT id FROM club_profile WHERE LOWER(slug) = LOWER(?) AND id <> ?');
    $stmt->execute([$slug, $exceptClubId ?? 0]);
    return (bool) $stmt->fetchColumn();
}

/** A free slug for the club: from its name, then -2, -3 ... */
function te_club_slug_generate(PDO $pdo, int $clubId, string $name): string
{
    $base = te_club_slug_from_name($name);
    if (strlen($base) < TE_CLUB_SLUG_MIN) {
        $base = 'club-' . $clubId;
    }
    $candidate = $base;
    $n = 1;
    while (te_club_slug_taken($pdo, $candidate, $clubId)) {
        $n++;
        $suffix = '-' . $n;
        $candidate = substr($base, 0, TE_CLUB_SLUG_MAX - strlen($suffix)) . $suffix;
    }
    return $candidate;
}

// ---------------------------------------------------------- column probe

/** Test seam for the migration-101 column probe. */
function te_club_public_page_probe_override(?bool $value = null)
{
    static $override = null;
    if (func_num_args() > 0) {
        $override = $value;
    }
    return $override;
}

/**
 * Are migration 101's club_profile columns live? `main` is shared and deploys
 * are by push, so this code can reach production before the SQL is applied.
 * Absent columns mean "enabled, no tagline" — the page works for any club
 * that already had a slug, and the admin tab reports the migration is pending.
 */
function te_club_public_page_columns_present(PDO $pdo): bool
{
    $override = te_club_public_page_probe_override();
    if ($override !== null) {
        return $override;
    }
    static $memo = null;
    $memo ??= new WeakMap();
    if (isset($memo[$pdo])) {
        return $memo[$pdo];
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_name = 'club_profile' AND column_name = 'public_page_enabled'"
            );
            $stmt->execute();
            return $memo[$pdo] = (bool) $stmt->fetchColumn();
        }
        $pdo->query('SELECT public_page_enabled FROM club_profile LIMIT 1');
        return $memo[$pdo] = true;
    } catch (Throwable $e) {
        return $memo[$pdo] = false;
    }
}

// ------------------------------------------------------------- the club

/**
 * The club behind a slug, or null when there is no such club or its page is
 * switched off. Both answer the same 404 to a visitor on purpose.
 *
 * @return array|null public club fields + 'id'
 */
function te_club_public_resolve(PDO $pdo, string $slug): ?array
{
    if ($slug === '' || strlen($slug) > TE_CLUB_SLUG_MAX) {
        return null;
    }
    $has = te_club_public_page_columns_present($pdo);
    $extra = $has ? ', public_page_enabled, public_page_tagline' : ', TRUE AS public_page_enabled, NULL AS public_page_tagline';
    $stmt = $pdo->prepare("
        SELECT id, name, slug, phone, website,
               city, state,
               social_facebook, social_instagram, social_twitter, social_tiktok, social_youtube, social_linkedin,
               logo_url, primary_color, secondary_color
               {$extra}
          FROM club_profile
         WHERE LOWER(slug) = LOWER(?)
    ");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $enabled = $row['public_page_enabled'];
    if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'f' || $enabled === 'false') {
        return null;
    }
    return $row;
}

/** Same validation EmailBranding::forClub applies: six hex digits, '#'-prefixed. */
function te_club_public_color(?string $raw, string $fallback): string
{
    $c = trim((string) $raw);
    if (preg_match('/^#?([0-9a-fA-F]{6})$/', $c, $m)) {
        return '#' . strtolower($m[1]);
    }
    return $fallback;
}

function te_club_public_url(?string $raw): ?string
{
    $u = trim((string) $raw);
    if ($u === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'https://' . $u;
    }
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : null;
}

/** The club block of the page payload. */
function te_club_public_club_payload(array $club): array
{
    $socials = [];
    foreach (['facebook', 'instagram', 'twitter', 'tiktok', 'youtube', 'linkedin'] as $s) {
        $u = te_club_public_url($club['social_' . $s] ?? null);
        if ($u !== null) {
            $socials[$s] = $u;
        }
    }
    $logo = trim((string) ($club['logo_url'] ?? ''));
    // A data: URI renders straight into <img>; anything else must be a URL.
    if ($logo !== '' && !preg_match('#^(data:image/[a-z0-9.+-]+;base64,|https?://)#i', $logo)) {
        $logo = '';
    }

    return [
        'id'              => (int) $club['id'],
        'name'            => (string) $club['name'],
        'slug'            => (string) $club['slug'],
        'tagline'         => ($club['public_page_tagline'] ?? null) !== null && trim((string) $club['public_page_tagline']) !== ''
                                ? trim((string) $club['public_page_tagline']) : null,
        'phone'           => trim((string) ($club['phone'] ?? '')) !== '' ? trim((string) $club['phone']) : null,
        'website'         => te_club_public_url($club['website'] ?? null),
        'city'            => trim((string) ($club['city'] ?? '')) ?: null,
        'state'           => trim((string) ($club['state'] ?? '')) ?: null,
        'socials'         => (object) $socials,
        'logo_url'        => $logo !== '' ? $logo : null,
        'primary_color'   => te_club_public_color($club['primary_color'] ?? null, '#12443e'),
        'secondary_color' => te_club_public_color($club['secondary_color'] ?? null, '#a3ebd1'),
    ];
}

// ---------------------------------------------------------------- lists

/** Active, undeleted sponsors: name, website, logo. Never contact_*. */
function te_club_public_sponsors(PDO $pdo, int $clubId): array
{
    $stmt = $pdo->prepare("
        SELECT id, name, website, logo_data
          FROM sponsors
         WHERE club_id = ? AND deleted_at IS NULL AND is_active = TRUE
         ORDER BY display_order ASC, name ASC
    ");
    $stmt->execute([$clubId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $logo = (string) ($r['logo_data'] ?? '');
        $out[] = [
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'website'  => te_club_public_url($r['website'] ?? null),
            'logo_data'=> preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $logo) ? $logo : null,
        ];
    }
    return $out;
}

const TE_CLUB_PUBLIC_COACH_ROLE_LABELS = [
    'head_coach'      => 'Head coach',
    'assistant_coach' => 'Assistant coach',
    'team_manager'    => 'Team manager',
];
const TE_CLUB_PUBLIC_COACH_ROLE_RANK = ['head_coach' => 0, 'assistant_coach' => 1, 'team_manager' => 2];

/**
 * "Our coaches" (Maggie, 2026-09-09: coaches, not teams — a 3×3 grid, nine to
 * a page). One entry per PERSON with every team they coach, by NAME, ROLE and
 * PHOTO. The derivation is api/team-coaches.php's UNION (teams.primary_coach_id
 * + team_members assistant_coach / team_manager) minus email, over the club's
 * live teams. team_members is touched for those two roles only — a 'player'
 * row is an athlete and never leaves this query.
 *
 * Ordering: head coaches first, then by last name — same as the parent portal.
 * Someone who is head coach of one team and assistant of another is listed
 * once, with their highest role and both teams.
 */
function te_club_public_coaches(PDO $pdo, int $clubId): array
{
    $stmt = $pdo->prepare("
        SELECT src.user_id, src.role, t.name AS team_name,
               u.first_name, u.last_name, u.profile_image_url
          FROM (
                SELECT t.id AS team_id, t.primary_coach_id AS user_id, 'head_coach' AS role
                  FROM teams t
                 WHERE t.club_id = ? AND t.deleted_at IS NULL AND t.primary_coach_id IS NOT NULL
                   AND (t.status IS NULL OR t.status NOT IN ('inactive', 'archived', 'disbanded'))
                UNION
                SELECT tm.team_id, tm.user_id, tm.role
                  FROM team_members tm
                  JOIN teams t2 ON t2.id = tm.team_id
                 WHERE t2.club_id = ? AND t2.deleted_at IS NULL
                   AND (t2.status IS NULL OR t2.status NOT IN ('inactive', 'archived', 'disbanded'))
                   AND tm.role IN ('assistant_coach', 'team_manager')
                   AND tm.status = 'active'
                   AND tm.user_id IS NOT NULL
          ) src
          JOIN teams t ON t.id = src.team_id
          JOIN users u ON u.id = src.user_id
         WHERE COALESCE(u.archived, FALSE) = FALSE
         ORDER BY u.last_name, u.first_name, t.name
    ");
    $stmt->execute([$clubId, $clubId]);

    $byUser = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int) $r['user_id'];
        $rank = TE_CLUB_PUBLIC_COACH_ROLE_RANK[$r['role']] ?? 9;
        if (!isset($byUser[$uid])) {
            $photo = trim((string) ($r['profile_image_url'] ?? ''));
            $byUser[$uid] = [
                'name'  => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                'role'  => TE_CLUB_PUBLIC_COACH_ROLE_LABELS[$r['role']] ?? 'Coach',
                'rank'  => $rank,
                'photo' => preg_match('#^(https?://|data:image/)#i', $photo) ? $photo : null,
                'teams' => [],
                'last'  => (string) $r['last_name'],
                'first' => (string) $r['first_name'],
            ];
        } elseif ($rank < $byUser[$uid]['rank']) {
            $byUser[$uid]['rank'] = $rank;
            $byUser[$uid]['role'] = TE_CLUB_PUBLIC_COACH_ROLE_LABELS[$r['role']] ?? 'Coach';
        }
        $team = (string) $r['team_name'];
        if (!in_array($team, $byUser[$uid]['teams'], true)) {
            $byUser[$uid]['teams'][] = $team;
        }
    }
    $list = array_values($byUser);
    usort($list, fn($a, $b) => [$a['rank'], $a['last'], $a['first']] <=> [$b['rank'], $b['last'], $b['first']]);
    return array_map(fn($c) => ['name' => $c['name'], 'role' => $c['role'], 'photo' => $c['photo'], 'teams' => $c['teams']], $list);
}

/**
 * Upcoming games and tournaments. The SELECT is the whole public contract:
 * no description, no location free text, no attendees, no referee columns.
 *
 * @param string|null $today  YYYY-MM-DD, for tests; defaults to CURRENT_DATE.
 */
function te_club_public_events(PDO $pdo, int $clubId, int $limit = TE_CLUB_PUBLIC_EVENTS_DEFAULT, ?string $today = null): array
{
    $limit = max(1, min(TE_CLUB_PUBLIC_EVENTS_MAX, $limit));
    $types = implode(',', array_fill(0, count(TE_CLUB_PUBLIC_EVENT_TYPES), '?'));
    $dateExpr = $today !== null ? '?' : 'CURRENT_DATE';
    $fieldSelect = te_event_field_select($pdo, 'e');
    $fieldJoin = te_event_field_join($pdo, 'e');

    $sql = "
        SELECT e.id, e.name, e.type, e.event_date, e.start_time, e.end_time, e.opponent_name,
               v.name AS venue_name
               {$fieldSelect}
          FROM calendar_events e
          LEFT JOIN venues v ON v.id = e.venue_id
          {$fieldJoin}
         WHERE e.club_id = ?
           AND e.type IN ({$types})
           AND e.event_date >= {$dateExpr}
           AND (e.status IS NULL OR e.status <> 'cancelled')
         ORDER BY e.event_date ASC, e.start_time ASC NULLS LAST, e.id ASC
         LIMIT {$limit}
    ";
    $params = array_merge([$clubId], TE_CLUB_PUBLIC_EVENT_TYPES);
    if ($today !== null) {
        $params[] = $today;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$events) {
        return [];
    }

    // Team NAMES only, from the join table — never a roster.
    $ids = array_map(fn($e) => (int) $e['id'], $events);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ts = $pdo->prepare("
        SELECT cet.event_id, t.name
          FROM calendar_event_teams cet
          JOIN teams t ON t.id = cet.team_id
         WHERE cet.event_id IN ({$in}) AND t.deleted_at IS NULL
         ORDER BY t.name
    ");
    $ts->execute($ids);
    $teamsByEvent = [];
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $teamsByEvent[(int) $r['event_id']][] = (string) $r['name'];
    }

    $out = [];
    foreach ($events as $e) {
        $out[] = [
            'id'            => (int) $e['id'],
            'name'          => (string) $e['name'],
            'type'          => (string) $e['type'],
            'event_date'    => substr((string) $e['event_date'], 0, 10),
            'start_time'    => $e['start_time'] !== null ? substr((string) $e['start_time'], 0, 5) : null,
            'end_time'      => $e['end_time'] !== null ? substr((string) $e['end_time'], 0, 5) : null,
            'opponent_name' => ($e['opponent_name'] ?? null) !== null && trim((string) $e['opponent_name']) !== '' ? trim((string) $e['opponent_name']) : null,
            'venue_name'    => ($e['venue_name'] ?? null) ?: null,
            'field_name'    => ($e['field_name'] ?? null) ?: null,
            'teams'         => $teamsByEvent[(int) $e['id']] ?? [],
        ];
    }
    return $out;
}

/** The whole page in one round trip. */
function te_club_public_page_payload(PDO $pdo, array $club, int $eventLimit = TE_CLUB_PUBLIC_EVENTS_DEFAULT): array
{
    $clubId = (int) $club['id'];
    return [
        'club'     => te_club_public_club_payload($club),
        'sponsors' => te_club_public_sponsors($pdo, $clubId),
        'coaches'  => te_club_public_coaches($pdo, $clubId),
        'events'   => te_club_public_events($pdo, $clubId, $eventLimit),
    ];
}

// ------------------------------------------------------------------ ICS

function te_ics_escape(string $s): string
{
    return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
}

/** Fold a content line at 75 octets per RFC 5545. */
function te_ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $out .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    return $out . $line;
}

/**
 * A text/calendar document of the same public events. Times are floating
 * local times (no TZID): the club's families are in the club's zone, and a
 * floating time is what a game "at 9:00" means to them. An event with no
 * start time is an all-day event.
 */
function te_club_public_ics(array $clubPayload, array $events, string $nowUtc = ''): string
{
    $nowUtc = $nowUtc !== '' ? $nowUtc : gmdate('Ymd\THis\Z');
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Teams Elevated//Club Public Calendar//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . te_ics_escape($clubPayload['name'] . ' — Games'),
    ];
    foreach ($events as $e) {
        $date = str_replace('-', '', $e['event_date']);
        $title = $e['name'];
        if ($e['opponent_name']) {
            $title .= ' vs ' . $e['opponent_name'];
        }
        $where = te_event_place_label($e['venue_name'], $e['field_name'], null, ', ');
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:club-event-' . $e['id'] . '@teamselevated.com';
        $lines[] = 'DTSTAMP:' . $nowUtc;
        if ($e['start_time']) {
            $lines[] = 'DTSTART:' . $date . 'T' . str_replace(':', '', $e['start_time']) . '00';
            if ($e['end_time']) {
                $lines[] = 'DTEND:' . $date . 'T' . str_replace(':', '', $e['end_time']) . '00';
            }
        } else {
            $lines[] = 'DTSTART;VALUE=DATE:' . $date;
        }
        $lines[] = 'SUMMARY:' . te_ics_escape($title);
        if ($where !== '') {
            $lines[] = 'LOCATION:' . te_ics_escape($where);
        }
        if ($e['teams']) {
            $lines[] = 'DESCRIPTION:' . te_ics_escape(implode(', ', $e['teams']));
        }
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('te_ics_fold', $lines)) . "\r\n";
}

// --------------------------------------------------------- contact form

/** First X-Forwarded-For entry (Heroku's router), else REMOTE_ADDR. Same as support. */
function te_club_contact_client_ip(): ?string
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
 * Validate a submission. Returns ['ok' => true, 'values' => [...]] or
 * ['ok' => false, 'error' => 'sentence', 'field' => 'name'].
 *
 * The honeypot (`website_url`) is checked by the caller: a filled honeypot is
 * answered with a SILENT 200 so the bot learns nothing, and that decision
 * belongs with the response, not the validator.
 */
function te_club_contact_validate(array $body): array
{
    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $phone = trim((string) ($body['phone'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));

    if ($name === '' || mb_strlen($name) > TE_CLUB_CONTACT_MAX_NAME) {
        return ['ok' => false, 'field' => 'name', 'error' => 'Please tell us your name (up to 100 characters).'];
    }
    if ($email === '' || strlen($email) > TE_CLUB_CONTACT_MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'field' => 'email', 'error' => 'Please enter an email address we can reply to.'];
    }
    if ($phone !== '' && mb_strlen($phone) > TE_CLUB_CONTACT_MAX_PHONE) {
        return ['ok' => false, 'field' => 'phone', 'error' => 'That phone number is too long.'];
    }
    if ($message === '') {
        return ['ok' => false, 'field' => 'message', 'error' => 'Please write a message.'];
    }
    if (mb_strlen($message) > TE_CLUB_CONTACT_MAX_MESSAGE) {
        $message = mb_substr($message, 0, TE_CLUB_CONTACT_MAX_MESSAGE);
    }
    return ['ok' => true, 'values' => [
        'name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null, 'message' => $message,
    ]];
}

/**
 * Too many messages from this IP in the last hour?
 *
 * Fails CLOSED, unlike the support-ticket limiter: this form causes outbound
 * mail to real people, and a limiter that cannot count must not let a burst
 * through. A visitor whose IP we cannot determine is refused for the same
 * reason.
 */
function te_club_contact_is_rate_limited(PDO $pdo, ?string $ip): bool
{
    if ($ip === null || $ip === '') {
        return true;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM club_contact_messages
              WHERE ip_address = ? AND created_at > NOW() - INTERVAL '1 hour'"
        );
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() >= TE_CLUB_CONTACT_RATE_LIMIT;
    } catch (Throwable $e) {
        error_log('club contact rate limit check failed, refusing: ' . $e->getMessage());
        return true;
    }
}

/** Store the message. Returns the new row id. Runs in the caller's transaction. */
function te_club_contact_store(PDO $pdo, int $clubId, array $v, ?string $ip, ?string $userAgent): int
{
    $stmt = $pdo->prepare("
        INSERT INTO club_contact_messages (club_id, name, email, phone, message, ip_address, user_agent, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'new')
        RETURNING id
    ");
    $stmt->execute([
        $clubId, $v['name'], $v['email'], $v['phone'], $v['message'],
        $ip, $userAgent !== null ? substr($userAgent, 0, 255) : null,
    ]);
    return (int) $stmt->fetchColumn();
}

/** Record the outcome of the notification. Never throws. */
function te_club_contact_mark(PDO $pdo, int $messageId, string $status, array $sentTo): void
{
    try {
        $stmt = $pdo->prepare('UPDATE club_contact_messages SET status = ?, sent_to = ? WHERE id = ?');
        $stmt->execute([$status, json_encode(array_values($sentTo)), $messageId]);
    } catch (Throwable $e) {
        error_log('club contact mark failed: ' . $e->getMessage());
    }
}

<?php

require_once __DIR__ . '/../config/env.php';

class MergeFieldService {
    /**
     * Merge tags whose value is supplied by the caller in the merge context
     * rather than loaded from a table. Kept as an explicit whitelist so a stray
     * context key (ids, tokens, internal flags) can never be substituted into an
     * email by naming a tag after it.
     */
    const CONTEXT_PASSTHROUGH_KEYS = [
        'accept_url',
        'decline_url',
        'offer_expires_at',
        'division_gender',
        'venue_name',
        'waitlist_position',
    ];

    private $pdo;
    private $cache = []; // Cache loaded data to avoid duplicate queries

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Resolve all {{variable}} placeholders in text
     * @param string $text - subject or body with {{placeholders}}
     * @param array $context - keys: athlete_id, guardian_id, user_id (sender), event_id, team_id, club_profile_id
     * @param bool $escapeHtml - when true, HTML-escape merge VALUES before substituting.
     *        Pass true when resolving into an HTML body (so a name/team/etc. containing
     *        markup can't inject script into the email or its in-app preview). Leave
     *        false for plain-text subjects and text bodies (which aren't rendered as HTML).
     * @return string - text with variables resolved
     */
    public function resolveVariables($text, $context, $escapeHtml = false) {
        // First check if there are any variables to resolve
        if (strpos($text, '{{') === false) return $text;

        // Build replacement map
        $replacements = [];

        // Only load data for variables actually present
        if (preg_match('/\{\{recipient_/', $text)) {
            $replacements = array_merge($replacements, $this->loadRecipientData($context));
        }
        if (preg_match('/\{\{athlete_/', $text)) {
            $data = $this->loadAthleteData($context['athlete_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{guardian_/', $text)) {
            $data = $this->loadGuardianData($context['guardian_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{sender_/', $text)) {
            $data = $this->loadSenderData($context['user_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{event_/', $text)) {
            $data = $this->loadEventData($context['event_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{team_/', $text)) {
            // Prefer an explicit team_id in the context; otherwise derive the team
            // from the event being previewed/sent (events.team_id is the canonical
            // event -> team link). This lets {{team_name}} resolve even when the
            // caller only supplies an event_id.
            $teamId = $context['team_id'] ?? null;
            if (!$teamId && !empty($context['event_id'])) {
                $teamId = $this->resolveEventTeamId($context['event_id']);
            }
            // Last resort: the recipient's own team. {{team_name}} is in 101 of the
            // 142 templates, and a plain team email carries no event — without this
            // the tag never resolved and the send guard 422'd the whole send.
            if (!$teamId && !empty($context['athlete_id'])) {
                $teamId = $this->resolveAthleteTeamId($context['athlete_id']);
            }
            $data = $this->loadTeamData($teamId);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{club_/', $text)) {
            $data = $this->loadClubData($context['club_profile_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{tournament_/', $text)) {
            $data = $this->loadTournamentData($context['tournament_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{division_/', $text)) {
            $data = $this->loadDivisionData($context['division_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{match_/', $text)) {
            $data = $this->loadMatchData($context['match_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{registration_/', $text)) {
            $data = $this->loadRegistrationData($context['registration_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }

        // Values the CALLER already resolved and passed in the context — the
        // waitlist accept/decline URLs, the offer deadline, the tournament venue.
        // They have no loader of their own (nothing to look up: WaitlistService
        // built them), and without this pass-through the templates that use them
        // mailed the literal "{{accept_url}}" to families instead of a working
        // button. Whitelisted rather than open — an arbitrary context key must not
        // become a substitutable tag.
        foreach (self::CONTEXT_PASSTHROUGH_KEYS as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== null && $context[$key] !== '') {
                $replacements[$key] = $context[$key];
            }
        }

        // Replace all found variables, leave unresolved ones as-is
        foreach ($replacements as $key => $value) {
            $replacement = $value ?? '';
            // XSS guard: escape merge VALUES going into an HTML body so attacker-
            // controlled data (e.g. an athlete/guardian name set to "<img onerror=...>")
            // renders as inert text instead of executing in the email or its preview.
            if ($escapeHtml) {
                $replacement = htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8');
            }
            $text = str_replace('{{' . $key . '}}', $replacement, $text);
        }

        return $text;
    }

    /**
     * Get list of all available merge fields with descriptions (for template editor UI)
     */
    public static function getAvailableFields() {
        return [
            // Whoever the email is addressed to (works club-wide; combines shared-email
            // households into "John & Jane"). Safest personalization for a broad send.
            ['key' => 'recipient_first_name', 'label' => 'Recipient First Name', 'group' => 'Recipient'],
            ['key' => 'recipient_name', 'label' => 'Recipient Full Name', 'group' => 'Recipient'],
            ['key' => 'athlete_first_name', 'label' => 'Athlete First Name', 'group' => 'Athlete'],
            ['key' => 'athlete_last_name', 'label' => 'Athlete Last Name', 'group' => 'Athlete'],
            ['key' => 'athlete_full_name', 'label' => 'Athlete Full Name', 'group' => 'Athlete'],
            // Keys stay guardian_* (identifiers used across templates + resolution); only the
            // staff-facing picker label/group is renamed to Crew.
            ['key' => 'guardian_first_name', 'label' => 'Crew First Name', 'group' => 'Crew'],
            ['key' => 'guardian_last_name', 'label' => 'Crew Last Name', 'group' => 'Crew'],
            ['key' => 'guardian_full_name', 'label' => 'Crew Full Name', 'group' => 'Crew'],
            ['key' => 'team_name', 'label' => 'Team Name', 'group' => 'Team'],
            ['key' => 'club_name', 'label' => 'Club Name', 'group' => 'Club'],
            ['key' => 'club_primary_color', 'label' => 'Club Primary Color (hex)', 'group' => 'Club'],
            ['key' => 'club_secondary_color', 'label' => 'Club Secondary Color (hex)', 'group' => 'Club'],
            ['key' => 'event_name', 'label' => 'Event Name', 'group' => 'Event'],
            ['key' => 'event_date', 'label' => 'Event Date', 'group' => 'Event'],
            ['key' => 'event_time', 'label' => 'Event Time', 'group' => 'Event'],
            ['key' => 'event_location', 'label' => 'Event Location', 'group' => 'Event'],
            ['key' => 'event_venue_name', 'label' => 'Event Venue', 'group' => 'Event'],
            ['key' => 'event_address', 'label' => 'Event Address', 'group' => 'Event'],
            ['key' => 'event_type', 'label' => 'Event Type', 'group' => 'Event'],
            ['key' => 'sender_first_name', 'label' => 'Sender First Name', 'group' => 'Sender'],
            ['key' => 'sender_last_name', 'label' => 'Sender Last Name', 'group' => 'Sender'],
            ['key' => 'sender_full_name', 'label' => 'Sender Full Name', 'group' => 'Sender'],
            ['key' => 'sender_email', 'label' => 'Sender Email', 'group' => 'Sender'],
            ['key' => 'tournament_name', 'label' => 'Tournament Name', 'group' => 'Tournament'],
            ['key' => 'tournament_start_date', 'label' => 'Tournament Start Date', 'group' => 'Tournament'],
            ['key' => 'tournament_end_date', 'label' => 'Tournament End Date', 'group' => 'Tournament'],
            ['key' => 'tournament_location', 'label' => 'Tournament Location', 'group' => 'Tournament'],
            ['key' => 'tournament_url', 'label' => 'Tournament Public URL', 'group' => 'Tournament'],
            ['key' => 'division_name', 'label' => 'Division Name', 'group' => 'Tournament'],
            ['key' => 'division_age_group', 'label' => 'Division Age Group', 'group' => 'Tournament'],
            ['key' => 'match_kickoff', 'label' => 'Match Kickoff Time', 'group' => 'Tournament'],
            ['key' => 'match_field_name', 'label' => 'Match Field', 'group' => 'Tournament'],
            ['key' => 'match_round', 'label' => 'Match Round', 'group' => 'Tournament'],
            ['key' => 'match_home_team', 'label' => 'Home Team', 'group' => 'Tournament'],
            ['key' => 'match_away_team', 'label' => 'Away Team', 'group' => 'Tournament'],
            ['key' => 'match_home_score', 'label' => 'Home Score', 'group' => 'Tournament'],
            ['key' => 'match_away_score', 'label' => 'Away Score', 'group' => 'Tournament'],
            ['key' => 'registration_team_name', 'label' => 'Registered Team Name', 'group' => 'Tournament'],
            ['key' => 'registration_status', 'label' => 'Registration Status', 'group' => 'Tournament'],
            // Supplied by WaitlistService in the merge context (see
            // CONTEXT_PASSTHROUGH_KEYS) — only resolve inside waitlist emails.
            ['key' => 'venue_name', 'label' => 'Tournament Venue', 'group' => 'Tournament'],
            ['key' => 'division_gender', 'label' => 'Division Gender', 'group' => 'Tournament'],
            ['key' => 'accept_url', 'label' => 'Waitlist Accept Link', 'group' => 'Waitlist'],
            ['key' => 'decline_url', 'label' => 'Waitlist Decline Link', 'group' => 'Waitlist'],
            ['key' => 'offer_expires_at', 'label' => 'Waitlist Offer Deadline', 'group' => 'Waitlist'],
            ['key' => 'waitlist_position', 'label' => 'Waitlist Position', 'group' => 'Waitlist'],
        ];
    }

    // Private data loaders — each caches its result

    /**
     * The person the email is addressed to. Prefers a name the caller already
     * resolved in the context (recipient_first_name / recipient_name) — that's
     * how household combining ("John & Jane") and coach recipients arrive, since
     * neither maps to a single guardian/athlete row. Falls back to deriving from
     * the recipient's own guardian/athlete record. Title-cased; "there" if blank.
     */
    private function loadRecipientData($context) {
        require_once __DIR__ . '/../lib/NameFormatter.php';

        $first = $context['recipient_first_name'] ?? null;
        $full  = $context['recipient_name'] ?? null;

        if ($first === null && $full === null) {
            if (!empty($context['guardian_id'])) {
                $g = $this->loadGuardianData($context['guardian_id']);
                $first = $g['guardian_first_name'] ?? null;
                $full  = $g['guardian_full_name'] ?? null;
            } elseif (!empty($context['athlete_id'])) {
                $a = $this->loadAthleteData($context['athlete_id']);
                $first = $a['athlete_first_name'] ?? null;
                $full  = $a['athlete_full_name'] ?? null;
            }
        }

        $firstClean = NameFormatter::titleCaseName((string) ($first ?? ''));
        $fullClean  = NameFormatter::titleCaseName((string) ($full ?? ''));
        return [
            'recipient_first_name' => $firstClean !== '' ? $firstClean : 'there',
            'recipient_name'       => $fullClean !== '' ? $fullClean : 'there',
        ];
    }

    private function loadAthleteData($athleteId) {
        if (!$athleteId) return [];
        $key = "athlete_$athleteId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM athletes WHERE id = ?");
        $stmt->execute([$athleteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = $row ? [
            'athlete_first_name' => $row['first_name'],
            'athlete_last_name' => $row['last_name'],
            'athlete_full_name' => $row['first_name'] . ' ' . $row['last_name'],
        ] : [];

        $this->cache[$key] = $data;
        return $data;
    }

    private function loadGuardianData($guardianId) {
        if (!$guardianId) return [];
        $key = "guardian_$guardianId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM guardians WHERE id = ?");
        $stmt->execute([$guardianId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = $row ? [
            'guardian_first_name' => $row['first_name'],
            'guardian_last_name' => $row['last_name'],
            'guardian_full_name' => $row['first_name'] . ' ' . $row['last_name'],
        ] : [];

        $this->cache[$key] = $data;
        return $data;
    }

    private function loadSenderData($userId) {
        if (!$userId) return [];
        $key = "sender_$userId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $stmt = $this->pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = $row ? [
            'sender_first_name' => $row['first_name'],
            'sender_last_name' => $row['last_name'],
            'sender_full_name' => $row['first_name'] . ' ' . $row['last_name'],
            'sender_email' => $row['email'],
        ] : [];

        $this->cache[$key] = $data;
        return $data;
    }

    private function loadEventData($eventId) {
        if (!$eventId) return [];
        $key = "event_$eventId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        // calendar_events, NOT events. The `events` table this used to query was
        // dropped, so every {{event_*}} tag the template editor advertises silently
        // resolved to nothing (or threw). The shape differs: `name` not `title`,
        // `event_date` + `start_time` as separate columns not `start_datetime`,
        // `type` not `event_type`, plus a free-text `location` fallback.
        $stmt = $this->pdo->prepare("
            SELECT
                e.name,
                e.event_date,
                e.start_time,
                e.type,
                e.location,
                v.name AS venue_name,
                v.address AS venue_address,
                v.city AS venue_city,
                v.state AS venue_state
            FROM calendar_events e
            LEFT JOIN venues v ON e.venue_id = v.id
            WHERE e.id = ?
        ");

        try {
            $stmt->execute([$eventId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('MergeFieldService::loadEventData - ' . $e->getMessage());
            $this->cache[$key] = [];
            return [];
        }

        if (!$row) {
            $this->cache[$key] = [];
            return [];
        }

        // A venue gives the richer string; `location` is the free-text fallback for
        // events booked somewhere without a venue record.
        $location = $this->buildLocationString($row);
        if ($location === '') {
            $location = (string) ($row['location'] ?? '');
        }

        $date = $row['event_date'] ? new \DateTime($row['event_date']) : null;
        $time = $row['start_time'] ? new \DateTime($row['start_time']) : null;

        // Venue and address are also exposed separately: the seeded templates say
        // "Venue: X / Address: Y", and pointing both at the combined string
        // repeated the venue name inside its own address line.
        $addressParts = array_filter([
            $row['venue_address'] ?? null,
            trim(implode(' ', array_filter([$row['venue_city'] ?? null, $row['venue_state'] ?? null]))) ?: null,
        ]);

        $data = [
            'event_name' => $row['name'] ?? '',
            'event_date' => $date ? $date->format('l, F j, Y') : '',
            'event_time' => $time ? $time->format('g:i A') : '',
            'event_type' => ucfirst((string) ($row['type'] ?? '')),
            'event_location' => $location,
            'event_venue_name' => $row['venue_name'] ?: (string) ($row['location'] ?? ''),
            'event_address' => $addressParts ? implode(', ', $addressParts) : (string) ($row['location'] ?? ''),
        ];

        $this->cache[$key] = $data;
        return $data;
    }

    /**
     * Build a human-readable location string from event row data
     */
    private function buildLocationString($row) {
        $parts = [];

        // Venue name takes priority
        if (!empty($row['venue_name'])) {
            $parts[] = $row['venue_name'];
        }

        // Append field name if present (e.g. "Main Stadium - Field A")
        if (!empty($row['field_name'])) {
            if (!empty($parts)) {
                $parts[0] .= ' - ' . $row['field_name'];
            } else {
                $parts[] = $row['field_name'];
            }
        }

        // Append address
        if (!empty($row['venue_address'])) {
            $addressLine = $row['venue_address'];
            if (!empty($row['venue_city'])) {
                $addressLine .= ', ' . $row['venue_city'];
            }
            if (!empty($row['venue_state'])) {
                $addressLine .= ', ' . $row['venue_state'];
            }
            $parts[] = $addressLine;
        } elseif (!empty($row['field_address'])) {
            $parts[] = $row['field_address'];
        }

        return implode(', ', $parts);
    }

    /**
     * Resolve the team an event belongs to.
     *
     * calendar_events has NO team_id — the link is the join table
     * calendar_event_teams, and an event can carry several teams. Takes the
     * lowest-id team so {{team_name}} is at least deterministic; a multi-team
     * event has no single right answer.
     */
    private function resolveEventTeamId($eventId) {
        if (!$eventId) return null;
        $key = "event_team_$eventId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $teamId = null;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT team_id FROM calendar_event_teams WHERE event_id = ? ORDER BY team_id LIMIT 1"
            );
            $stmt->execute([$eventId]);
            $val = $stmt->fetchColumn();
            $teamId = ($val !== false && $val !== null) ? (int)$val : null;
        } catch (\PDOException $e) {
            error_log('MergeFieldService::resolveEventTeamId - ' . $e->getMessage());
            $teamId = null;
        }

        $this->cache[$key] = $teamId;
        return $teamId;
    }

    /**
     * The team a recipient plays on — used for {{team_name}} when the caller gave
     * neither a team nor an event (the ordinary "email my team" send).
     *
     * An athlete can be rostered on several teams; takes the lowest active team_id
     * so the value is at least deterministic. Soft-deleted teams are excluded.
     */
    private function resolveAthleteTeamId($athleteId) {
        if (!$athleteId) return null;
        $key = "athlete_team_$athleteId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $teamId = null;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT tm.team_id
                   FROM team_members tm
                   JOIN teams t ON t.id = tm.team_id
                  WHERE tm.athlete_id = ?
                    AND tm.status = 'active'
                    AND t.deleted_at IS NULL
                  ORDER BY tm.team_id
                  LIMIT 1"
            );
            $stmt->execute([$athleteId]);
            $val = $stmt->fetchColumn();
            $teamId = ($val !== false && $val !== null) ? (int) $val : null;
        } catch (\PDOException $e) {
            error_log('MergeFieldService::resolveAthleteTeamId - ' . $e->getMessage());
            $teamId = null;
        }

        $this->cache[$key] = $teamId;
        return $teamId;
    }

    private function loadTeamData($teamId) {
        if (!$teamId) return [];
        $key = "team_$teamId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $stmt = $this->pdo->prepare("SELECT name FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = $row ? [
            'team_name' => $row['name'],
        ] : [];

        $this->cache[$key] = $data;
        return $data;
    }

    private function loadClubData($clubProfileId) {
        if (!$clubProfileId) return [];
        $key = "club_$clubProfileId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        // club_profile table uses club_name in production (organization-gateway inserts into club_name)
        // but test schema uses name — try club_name first, fall back to name
        $stmt = $this->pdo->prepare("SELECT * FROM club_profile WHERE id = ?");
        $stmt->execute([$clubProfileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->cache[$key] = [];
            return [];
        }

        // Resolve club name from whichever column exists
        $clubName = $row['club_name'] ?? $row['name'] ?? '';

        $data = [
            'club_name' => $clubName,
            // Brand colours so a template can paint its header/footer bands in the
            // club's own colours at send time. Always resolve to a valid hex —
            // an empty value would emit "background-color:;" and drop the band.
            'club_primary_color'   => $this->normalizeHexColor($row['primary_color'] ?? null, '#12443E'),
            'club_secondary_color' => $this->normalizeHexColor($row['secondary_color'] ?? null, '#C9A96E'),
        ];

        $this->cache[$key] = $data;
        return $data;
    }

    /**
     * Coerce a stored colour to a usable 6-digit hex, falling back when the club
     * has none or has something unparseable. Mirrors the validation in
     * CalendarInviteService::getClubBranding() so both email paths agree.
     */
    private function normalizeHexColor($value, $fallback) {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^#?[0-9a-fA-F]{6}$/', $value)) {
            return $fallback;
        }
        return (substr($value, 0, 1) === '#' ? '' : '#') . $value;
    }

    private function loadTournamentData($tournamentId) {
        if (!$tournamentId) return [];
        $key = "tournament_$tournamentId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $stmt = $this->pdo->prepare("
            SELECT id, name, start_date, end_date,
                   location_name, location_city, location_state,
                   public_url_slug
            FROM tournaments
            WHERE id = ?
        ");
        $stmt->execute([$tournamentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->cache[$key] = [];
            return [];
        }

        $startDate = $row['start_date'] ? new \DateTime($row['start_date']) : null;
        $endDate = $row['end_date'] ? new \DateTime($row['end_date']) : null;

        // Build location string: "Venue Name, City, ST" with sensible falls-throughs
        $locationParts = [];
        if (!empty($row['location_name'])) $locationParts[] = $row['location_name'];
        $cityState = trim(($row['location_city'] ?? '') . ' ' . ($row['location_state'] ?? ''));
        if ($cityState !== '') $locationParts[] = $cityState;
        $location = implode(', ', $locationParts);

        // Public URL: APP_URL + /tournament/{slug}; fall back to empty if no slug
        $appUrl = rtrim(Env::get('APP_URL', ''), '/');
        $url = '';
        if ($appUrl !== '' && !empty($row['public_url_slug'])) {
            $url = $appUrl . '/tournament/' . $row['public_url_slug'];
        }

        $data = [
            'tournament_name' => $row['name'] ?? '',
            'tournament_start_date' => $startDate ? $startDate->format('l, F j, Y') : '',
            'tournament_end_date' => $endDate ? $endDate->format('l, F j, Y') : '',
            'tournament_location' => $location,
            'tournament_url' => $url,
        ];

        $this->cache[$key] = $data;
        return $data;
    }

    private function loadDivisionData($divisionId) {
        if (!$divisionId) return [];
        $key = "division_$divisionId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        $stmt = $this->pdo->prepare("SELECT name, age_group FROM tournament_divisions WHERE id = ?");
        $stmt->execute([$divisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = $row ? [
            'division_name' => $row['name'],
            'division_age_group' => $row['age_group'],
        ] : [];

        $this->cache[$key] = $data;
        return $data;
    }

    private function loadMatchData($matchId) {
        if (!$matchId) return [];
        $key = "match_$matchId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        // Resolve home/away team names through the registration → team chain.
        // Falls back to home_placeholder / away_placeholder when team isn't slotted yet
        // (e.g., bracket placeholders like "Winner Match 5").
        $stmt = $this->pdo->prepare("
            SELECT
                m.round,
                m.scheduled_time,
                m.home_score,
                m.away_score,
                m.home_placeholder,
                m.away_placeholder,
                f.name AS field_name,
                COALESCE(NULLIF(rh.team_name_override, ''), th.name, m.home_placeholder, '') AS home_team_name,
                COALESCE(NULLIF(ra.team_name_override, ''), ta.name, m.away_placeholder, '') AS away_team_name
            FROM tournament_matches m
            LEFT JOIN fields f ON m.field_id = f.id
            LEFT JOIN tournament_registrations rh ON m.home_registration_id = rh.id
            LEFT JOIN teams th ON rh.team_id = th.id
            LEFT JOIN tournament_registrations ra ON m.away_registration_id = ra.id
            LEFT JOIN teams ta ON ra.team_id = ta.id
            WHERE m.id = ?
        ");
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->cache[$key] = [];
            return [];
        }

        $kickoff = $row['scheduled_time'] ? new \DateTime($row['scheduled_time']) : null;

        $data = [
            'match_round' => $row['round'] ?? '',
            'match_kickoff' => $kickoff ? $kickoff->format('l, F j, Y \a\t g:i A') : 'TBD',
            'match_field_name' => $row['field_name'] ?? 'TBD',
            'match_home_team' => $row['home_team_name'] ?? '',
            'match_away_team' => $row['away_team_name'] ?? '',
            'match_home_score' => $row['home_score'] !== null ? (string)$row['home_score'] : '',
            'match_away_score' => $row['away_score'] !== null ? (string)$row['away_score'] : '',
        ];

        $this->cache[$key] = $data;
        return $data;
    }

    private function loadRegistrationData($registrationId) {
        if (!$registrationId) return [];
        $key = "registration_$registrationId";
        if (isset($this->cache[$key])) return $this->cache[$key];

        // team_name_override wins for guest teams; otherwise fall through to teams.name.
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(NULLIF(r.team_name_override, ''), t.name, '') AS team_name,
                r.status
            FROM tournament_registrations r
            LEFT JOIN teams t ON r.team_id = t.id
            WHERE r.id = ?
        ");
        $stmt->execute([$registrationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = $row ? [
            'registration_team_name' => $row['team_name'],
            'registration_status' => $row['status'],
        ] : [];

        $this->cache[$key] = $data;
        return $data;
    }
}
?>

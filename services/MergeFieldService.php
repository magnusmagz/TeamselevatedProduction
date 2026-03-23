<?php

class MergeFieldService {
    private $pdo;
    private $cache = []; // Cache loaded data to avoid duplicate queries

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Resolve all {{variable}} placeholders in text
     * @param string $text - subject or body with {{placeholders}}
     * @param array $context - keys: athlete_id, guardian_id, user_id (sender), event_id, team_id, club_profile_id
     * @return string - text with variables resolved
     */
    public function resolveVariables($text, $context) {
        // First check if there are any variables to resolve
        if (strpos($text, '{{') === false) return $text;

        // Build replacement map
        $replacements = [];

        // Only load data for variables actually present
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
            $data = $this->loadTeamData($context['team_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }
        if (preg_match('/\{\{club_/', $text)) {
            $data = $this->loadClubData($context['club_profile_id'] ?? null);
            $replacements = array_merge($replacements, $data);
        }

        // Replace all found variables, leave unresolved ones as-is
        foreach ($replacements as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value ?? '', $text);
        }

        return $text;
    }

    /**
     * Get list of all available merge fields with descriptions (for template editor UI)
     */
    public static function getAvailableFields() {
        return [
            ['key' => 'athlete_first_name', 'label' => 'Athlete First Name', 'group' => 'Athlete'],
            ['key' => 'athlete_last_name', 'label' => 'Athlete Last Name', 'group' => 'Athlete'],
            ['key' => 'athlete_full_name', 'label' => 'Athlete Full Name', 'group' => 'Athlete'],
            ['key' => 'guardian_first_name', 'label' => 'Parent/Guardian First Name', 'group' => 'Parent'],
            ['key' => 'guardian_last_name', 'label' => 'Parent/Guardian Last Name', 'group' => 'Parent'],
            ['key' => 'guardian_full_name', 'label' => 'Parent/Guardian Full Name', 'group' => 'Parent'],
            ['key' => 'team_name', 'label' => 'Team Name', 'group' => 'Team'],
            ['key' => 'club_name', 'label' => 'Club Name', 'group' => 'Club'],
            ['key' => 'event_name', 'label' => 'Event Name', 'group' => 'Event'],
            ['key' => 'event_date', 'label' => 'Event Date', 'group' => 'Event'],
            ['key' => 'event_time', 'label' => 'Event Time', 'group' => 'Event'],
            ['key' => 'event_location', 'label' => 'Event Location', 'group' => 'Event'],
            ['key' => 'event_type', 'label' => 'Event Type', 'group' => 'Event'],
            ['key' => 'sender_first_name', 'label' => 'Sender First Name', 'group' => 'Sender'],
            ['key' => 'sender_last_name', 'label' => 'Sender Last Name', 'group' => 'Sender'],
            ['key' => 'sender_full_name', 'label' => 'Sender Full Name', 'group' => 'Sender'],
            ['key' => 'sender_email', 'label' => 'Sender Email', 'group' => 'Sender'],
        ];
    }

    // Private data loaders — each caches its result

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

        // Events table has: title, start_datetime, end_datetime, event_type
        // It may have venue_id (FK to venues) or field_id (FK to fields which belongs to a venue)
        // Query both paths to resolve a human-readable location
        $stmt = $this->pdo->prepare("
            SELECT
                e.title,
                e.start_datetime,
                e.event_type,
                v.name AS venue_name,
                v.address AS venue_address,
                v.city AS venue_city,
                v.state AS venue_state,
                f.name AS field_name
            FROM events e
            LEFT JOIN venues v ON e.venue_id = v.id
            LEFT JOIN fields f ON e.field_id = f.id
            WHERE e.id = ?
        ");

        try {
            $stmt->execute([$eventId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // If the JOIN on venues fails (column venue_id may not exist), fall back to fields only
            $stmt = $this->pdo->prepare("
                SELECT
                    e.title,
                    e.start_datetime,
                    e.event_type,
                    f.name AS field_name,
                    f.address AS field_address
                FROM events e
                LEFT JOIN fields f ON e.field_id = f.id
                WHERE e.id = ?
            ");
            $stmt->execute([$eventId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) {
            $this->cache[$key] = [];
            return [];
        }

        // Build a readable location string
        $location = $this->buildLocationString($row);

        // Parse date and time from start_datetime
        $startDt = $row['start_datetime'] ? new \DateTime($row['start_datetime']) : null;

        $data = [
            'event_name' => $row['title'] ?? '',
            'event_date' => $startDt ? $startDt->format('l, F j, Y') : '',
            'event_time' => $startDt ? $startDt->format('g:i A') : '',
            'event_type' => ucfirst($row['event_type'] ?? ''),
            'event_location' => $location,
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
        ];

        $this->cache[$key] = $data;
        return $data;
    }
}
?>

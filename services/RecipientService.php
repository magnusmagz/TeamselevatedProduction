<?php
/**
 * Recipient Service
 *
 * Collects email recipients for events based on team assignments
 */

class RecipientService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get all recipients for an event based on assigned teams
     *
     * @param int $eventId The event ID
     * @param array $teamIds Array of team IDs
     * @return array Array of recipient information
     */
    public function getEventRecipients($eventId, $teamIds) {
        $recipients = [];

        if (empty($teamIds)) {
            return $recipients;
        }

        // Get athletes and their guardians
        $athleteRecipients = $this->getAthleteAndGuardianRecipients($teamIds);
        $recipients = array_merge($recipients, $athleteRecipients);

        // Get coaches/team members
        $coachRecipients = $this->getCoachRecipients($teamIds);
        $recipients = array_merge($recipients, $coachRecipients);

        // Remove duplicates by email
        $recipients = $this->removeDuplicates($recipients);

        // Validate emails
        $recipients = $this->validateEmails($recipients);

        return $recipients;
    }

    /**
     * Get athletes and their guardians for specified teams
     */
    private function getAthleteAndGuardianRecipients($teamIds) {
        $recipients = [];
        $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';

        // Get athletes with their teams
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                a.id,
                a.first_name,
                a.last_name,
                a.email,
                tm.team_id
            FROM athletes a
            JOIN team_members tm ON a.id = tm.athlete_id
            WHERE tm.team_id IN ($placeholders)
                AND tm.status = 'active'
                AND a.active_status = 1
        ");
        $stmt->execute($teamIds);
        $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($athletes as $athlete) {
            // Add athlete if they have an email
            if (!empty($athlete['email']) && filter_var($athlete['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[] = [
                    'email' => $athlete['email'],
                    'name' => $athlete['first_name'] . ' ' . $athlete['last_name'],
                    'type' => 'athlete',
                    'id' => $athlete['id'],
                    'team_id' => $athlete['team_id']
                ];
            }

            // Get guardians for this athlete
            $guardianStmt = $this->pdo->prepare("
                SELECT DISTINCT
                    g.id,
                    g.first_name,
                    g.last_name,
                    g.email,
                    g.personal_email,
                    g.receive_invites
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE ag.athlete_id = ?
                    AND g.receive_invites = 1
            ");
            $guardianStmt->execute([$athlete['id']]);
            $guardians = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($guardians as $guardian) {
                // Check primary email
                if (!empty($guardian['email']) && filter_var($guardian['email'], FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = [
                        'email' => $guardian['email'],
                        'name' => $guardian['first_name'] . ' ' . $guardian['last_name'],
                        'type' => 'guardian',
                        'id' => $guardian['id'],
                        'athlete_id' => $athlete['id'],
                        'team_id' => $athlete['team_id']
                    ];
                }

                // Also check personal email if different
                if (!empty($guardian['personal_email']) &&
                    $guardian['personal_email'] !== $guardian['email'] &&
                    filter_var($guardian['personal_email'], FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = [
                        'email' => $guardian['personal_email'],
                        'name' => $guardian['first_name'] . ' ' . $guardian['last_name'],
                        'type' => 'guardian',
                        'id' => $guardian['id'],
                        'athlete_id' => $athlete['id'],
                        'team_id' => $athlete['team_id']
                    ];
                }
            }
        }

        return $recipients;
    }

    /**
     * Get coaches and team staff for specified teams
     */
    private function getCoachRecipients($teamIds) {
        $recipients = [];
        $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';

        // Get team members who are coaches or managers
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                tm.id,
                tm.team_id,
                tm.role,
                u.email,
                u.first_name,
                u.last_name
            FROM team_members tm
            JOIN users u ON tm.user_id = u.id
            WHERE tm.team_id IN ($placeholders)
                AND tm.role IN ('assistant_coach', 'team_manager')
                AND tm.status = 'active'
                AND u.email IS NOT NULL
        ");
        $stmt->execute($teamIds);
        $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($coaches as $coach) {
            if (!empty($coach['email']) && filter_var($coach['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[] = [
                    'email' => $coach['email'],
                    'name' => $coach['first_name'] . ' ' . $coach['last_name'],
                    'type' => 'coach',
                    'id' => $coach['id'],
                    'role' => $coach['role'],
                    'team_id' => $coach['team_id']
                ];
            }
        }

        // Also check if teams have primary coaches via user relationship
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                t.id as team_id,
                u.id as coach_id,
                u.email,
                u.first_name,
                u.last_name
            FROM teams t
            JOIN users u ON t.primary_coach_id = u.id
            WHERE t.id IN ($placeholders)
                AND u.email IS NOT NULL
        ");
        $stmt->execute($teamIds);
        $teamCoaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($teamCoaches as $coach) {
            if (!empty($coach['email']) && filter_var($coach['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[] = [
                    'email' => $coach['email'],
                    'name' => $coach['first_name'] . ' ' . $coach['last_name'],
                    'type' => 'coach',
                    'id' => $coach['coach_id'],
                    'role' => 'head_coach',
                    'team_id' => $coach['team_id']
                ];
            }
        }

        return $recipients;
    }

    /**
     * Remove duplicate recipients by email
     */
    private function removeDuplicates($recipients) {
        $unique = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            $email = strtolower($recipient['email']);
            if (!in_array($email, $seen)) {
                $seen[] = $email;
                $recipient['email'] = $email; // Normalize to lowercase
                $unique[] = $recipient;
            }
        }

        return $unique;
    }

    /**
     * Validate email addresses
     */
    public function validateEmails($recipients) {
        $valid = [];

        foreach ($recipients as $recipient) {
            if ($this->isValidEmail($recipient['email'])) {
                $valid[] = $recipient;
            }
        }

        return $valid;
    }

    /**
     * Check if email is valid
     */
    private function isValidEmail($email) {
        // Basic validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Additional checks
        $domain = substr(strrchr($email, "@"), 1);

        // Check domain has MX record (commented out for local testing)
        // if (!checkdnsrr($domain, "MX")) {
        //     return false;
        // }

        // Block obvious test emails
        $blockedDomains = ['example.com', 'test.com', 'localhost'];
        if (in_array($domain, $blockedDomains)) {
            return false;
        }

        return true;
    }

    /**
     * Get recipient count by type for an event
     */
    public function getRecipientStats($teamIds) {
        $recipients = $this->getEventRecipients(null, $teamIds);

        $stats = [
            'total' => count($recipients),
            'athletes' => 0,
            'guardians' => 0,
            'coaches' => 0
        ];

        foreach ($recipients as $recipient) {
            switch ($recipient['type']) {
                case 'athlete':
                    $stats['athletes']++;
                    break;
                case 'guardian':
                    $stats['guardians']++;
                    break;
                case 'coach':
                    $stats['coaches']++;
                    break;
            }
        }

        return $stats;
    }

    /**
     * Get recipients for a specific team only
     */
    public function getTeamRecipients($teamId) {
        return $this->getEventRecipients(null, [$teamId]);
    }

    /**
     * Check if a recipient has opted out of invites
     */
    public function hasOptedOut($email) {
        // Check guardians table
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM guardians
            WHERE (email = ? OR personal_email = ?)
                AND receive_invites = 0
        ");
        $stmt->execute([$email, $email]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get all recipients who have been invited to an event
     */
    public function getInvitedRecipients($eventId) {
        $stmt = $this->pdo->prepare("
            SELECT
                recipient_email as email,
                recipient_name as name,
                recipient_type as type,
                recipient_id as id,
                status,
                sent_at,
                responded_at
            FROM event_invitations
            WHERE event_id = ?
            ORDER BY recipient_type, recipient_name
        ");
        $stmt->execute([$eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<?php
require_once __DIR__ . '/../config/database.php';

class EmailController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get unique email addresses for sending notifications
     * Groups guardians by email to avoid duplicate sends
     */
    public function getUniqueGuardianEmails($filters = []) {
        $sql = "SELECT
                    email,
                    GROUP_CONCAT(DISTINCT CONCAT(g.first_name, ' ', g.last_name) SEPARATOR ', ') as guardian_names,
                    COUNT(DISTINCT g.id) as guardian_count,
                    GROUP_CONCAT(DISTINCT a.id) as athlete_ids,
                    GROUP_CONCAT(DISTINCT CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as athlete_names
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                JOIN athletes a ON ag.athlete_id = a.id
                WHERE ag.receives_communications = TRUE
                    AND ag.active_status = TRUE
                    AND a.active_status = TRUE";

        $params = [];

        if (!empty($filters['team_id'])) {
            $sql .= " AND a.id IN (SELECT athlete_id FROM team_members WHERE team_id = :team_id AND status = 'active')";
            $params[':team_id'] = $filters['team_id'];
        }

        if (!empty($filters['athlete_ids'])) {
            $athleteIds = implode(',', array_map('intval', $filters['athlete_ids']));
            $sql .= " AND a.id IN ($athleteIds)";
        }

        $sql .= " GROUP BY g.email";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Send email to guardians (grouped by unique email)
     */
    public function sendToGuardians() {
        $data = json_decode(file_get_contents('php://input'), true);

        // Get unique emails with guardian info
        $emails = $this->getUniqueGuardianEmails($data['filters'] ?? []);

        $sent = 0;
        $results = [];

        foreach ($emails as $emailGroup) {
            // Here you would integrate with your email service
            // For now, we'll just simulate the send

            $emailData = [
                'to' => $emailGroup['email'],
                'subject' => $data['subject'],
                'body' => $this->personalizeEmail($data['body'], $emailGroup),
                'guardian_names' => $emailGroup['guardian_names'],
                'athlete_names' => $emailGroup['athlete_names']
            ];

            // In production, you'd call your email service here
            // $this->sendEmail($emailData);

            $results[] = [
                'email' => $emailGroup['email'],
                'guardians' => $emailGroup['guardian_names'],
                'athletes' => $emailGroup['athlete_names'],
                'status' => 'sent'
            ];
            $sent++;
        }

        echo json_encode([
            'message' => "Email sent to $sent unique addresses",
            'total_guardians' => array_sum(array_column($emails, 'guardian_count')),
            'unique_emails' => $sent,
            'details' => $results
        ]);
    }

    /**
     * Personalize email content with guardian/athlete names
     */
    private function personalizeEmail($template, $emailGroup) {
        $guardianNames = $emailGroup['guardian_names'];
        $athleteNames = $emailGroup['athlete_names'];

        // Handle multiple guardians at same email
        if ($emailGroup['guardian_count'] > 1) {
            $greeting = "Dear $guardianNames";
        } else {
            $greeting = "Dear $guardianNames";
        }

        $template = str_replace('{{greeting}}', $greeting, $template);
        $template = str_replace('{{guardian_names}}', $guardianNames, $template);
        $template = str_replace('{{athlete_names}}', $athleteNames, $template);

        return $template;
    }

    /**
     * Preview how emails will be grouped
     */
    public function previewEmailGroups() {
        $data = json_decode(file_get_contents('php://input'), true);
        $emails = $this->getUniqueGuardianEmails($data['filters'] ?? []);

        $preview = [];
        foreach ($emails as $emailGroup) {
            $preview[] = [
                'email' => $emailGroup['email'],
                'recipients' => explode(', ', $emailGroup['guardian_names']),
                'athletes' => explode(', ', $emailGroup['athlete_names']),
                'will_receive_one_email' => true
            ];
        }

        echo json_encode([
            'total_guardians' => array_sum(array_column($emails, 'guardian_count')),
            'unique_emails' => count($emails),
            'email_groups' => $preview
        ]);
    }
}
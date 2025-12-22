<?php
/**
 * Mock Mailer Service for Testing
 *
 * This service simulates email sending and saves the emails to files
 * instead of actually sending them. Perfect for testing without SMTP.
 */

class MockMailerService {
    private $outputDir;
    private $logFile;
    private $emailCount = 0;

    public function __construct() {
        $this->outputDir = __DIR__ . '/../test-output/emails';
        $this->logFile = __DIR__ . '/../test-output/email-log.json';

        // Create output directory if it doesn't exist
        if (!file_exists($this->outputDir)) {
            mkdir($this->outputDir, 0777, true);
        }
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0777, true);
        }

        // Initialize or load existing log
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, json_encode(['emails' => []]));
        }
    }

    /**
     * Simulate sending an email by saving it to a file
     */
    public function send($to, $subject, $htmlBody, $icalContent, $type = 'invite') {
        $this->emailCount++;
        $timestamp = date('Y-m-d_H-i-s');
        $emailId = $timestamp . '_' . $this->emailCount;

        // Save HTML version
        $htmlFile = $this->outputDir . '/' . $emailId . '.html';
        $this->saveHtmlEmail($htmlFile, $to, $subject, $htmlBody, $icalContent);

        // Save iCal file
        $icalFile = $this->outputDir . '/' . $emailId . '.ics';
        file_put_contents($icalFile, $icalContent);

        // Log the email
        $this->logEmail([
            'id' => $emailId,
            'to' => $to,
            'subject' => $subject,
            'type' => $type,
            'timestamp' => date('Y-m-d H:i:s'),
            'html_file' => basename($htmlFile),
            'ical_file' => basename($icalFile)
        ]);

        return [
            'success' => true,
            'email_id' => $emailId,
            'preview_url' => '/test-output/emails/' . $emailId . '.html'
        ];
    }

    /**
     * Save HTML email with preview
     */
    private function saveHtmlEmail($file, $to, $subject, $body, $icalContent) {
        // Parse iCal for details
        $eventDetails = $this->parseIcal($icalContent);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email Preview: {$subject}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .email-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            margin-bottom: 0;
        }
        .email-meta {
            background: white;
            padding: 20px;
            border-left: 1px solid #e0e0e0;
            border-right: 1px solid #e0e0e0;
            margin: 0;
        }
        .email-meta div {
            margin: 5px 0;
            padding: 5px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .email-meta strong {
            display: inline-block;
            width: 100px;
            color: #666;
        }
        .email-content {
            background: white;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-top: none;
            border-radius: 0 0 8px 8px;
        }
        .ical-preview {
            background: #f8f9fa;
            border: 2px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .ical-preview h3 {
            color: #28a745;
            margin-top: 0;
        }
        .ical-detail {
            margin: 8px 0;
            padding-left: 20px;
        }
        .action-buttons {
            margin: 20px 0;
            padding: 15px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            color: white;
        }
        .btn-accept { background: #4caf50; }
        .btn-decline { background: #f44336; }
        .btn-tentative { background: #ff9800; }
        .test-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="test-notice">
        ⚠️ <strong>TEST MODE</strong>: This is a preview of the email that would be sent. No actual email was sent.
    </div>

    <div class="email-header">
        <h1>📧 Email Preview</h1>
    </div>

    <div class="email-meta">
        <div><strong>To:</strong> {$to}</div>
        <div><strong>Subject:</strong> {$subject}</div>
        <div><strong>Sent:</strong> {$eventDetails['timestamp']}</div>
        <div><strong>Type:</strong> Calendar {$eventDetails['method']}</div>
    </div>

    <div class="email-content">
        <div class="ical-preview">
            <h3>📅 Calendar Event Details (from .ics)</h3>
            <div class="ical-detail">📌 <strong>Event:</strong> {$eventDetails['summary']}</div>
            <div class="ical-detail">📍 <strong>Location:</strong> {$eventDetails['location']}</div>
            <div class="ical-detail">🕐 <strong>Start:</strong> {$eventDetails['start']}</div>
            <div class="ical-detail">🕑 <strong>End:</strong> {$eventDetails['end']}</div>
            <div class="ical-detail">📝 <strong>Description:</strong> {$eventDetails['description']}</div>
            <div class="ical-detail">🔔 <strong>Reminder:</strong> {$eventDetails['reminder']}</div>
            <div class="ical-detail">🆔 <strong>UID:</strong> {$eventDetails['uid']}</div>
            <div class="ical-detail">📊 <strong>Sequence:</strong> {$eventDetails['sequence']}</div>
        </div>

        <div class="action-buttons">
            <strong>In a real email client, these buttons would appear:</strong><br>
            <a href="#" class="btn btn-accept">✓ Accept</a>
            <a href="#" class="btn btn-tentative">? Maybe</a>
            <a href="#" class="btn btn-decline">✗ Decline</a>
        </div>

        <h3>Email Body Content:</h3>
        <div style="border: 1px solid #e0e0e0; padding: 20px; background: white;">
            {$body}
        </div>
    </div>
</body>
</html>
HTML;

        file_put_contents($file, $html);
    }

    /**
     * Parse iCal content for preview
     */
    private function parseIcal($icalContent) {
        $details = [
            'summary' => 'N/A',
            'location' => 'N/A',
            'start' => 'N/A',
            'end' => 'N/A',
            'description' => 'N/A',
            'reminder' => 'N/A',
            'uid' => 'N/A',
            'sequence' => '0',
            'method' => 'REQUEST',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Parse key fields
        if (preg_match('/SUMMARY:(.+)/i', $icalContent, $matches)) {
            $details['summary'] = $this->unescapeIcal(trim($matches[1]));
        }
        if (preg_match('/LOCATION:(.+)/i', $icalContent, $matches)) {
            $details['location'] = $this->unescapeIcal(trim($matches[1]));
        }
        if (preg_match('/DTSTART.*:(.+)/i', $icalContent, $matches)) {
            $details['start'] = $this->formatIcalDate(trim($matches[1]));
        }
        if (preg_match('/DTEND.*:(.+)/i', $icalContent, $matches)) {
            $details['end'] = $this->formatIcalDate(trim($matches[1]));
        }
        if (preg_match('/DESCRIPTION:(.+)/i', $icalContent, $matches)) {
            $details['description'] = $this->unescapeIcal(trim($matches[1]));
        }
        if (preg_match('/TRIGGER:(.+)/i', $icalContent, $matches)) {
            $details['reminder'] = trim($matches[1]);
        }
        if (preg_match('/UID:(.+)/i', $icalContent, $matches)) {
            $details['uid'] = trim($matches[1]);
        }
        if (preg_match('/SEQUENCE:(.+)/i', $icalContent, $matches)) {
            $details['sequence'] = trim($matches[1]);
        }
        if (preg_match('/METHOD:(.+)/i', $icalContent, $matches)) {
            $details['method'] = trim($matches[1]);
        }

        return $details;
    }

    /**
     * Format iCal date
     */
    private function formatIcalDate($date) {
        // Handle format: 20240315T140000
        if (preg_match('/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})/', $date, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]} {$matches[4]}:{$matches[5]}:{$matches[6]}";
        }
        return $date;
    }

    /**
     * Unescape iCal text
     */
    private function unescapeIcal($text) {
        $text = str_replace('\,', ',', $text);
        $text = str_replace('\;', ';', $text);
        $text = str_replace('\\n', "\n", $text);
        $text = str_replace('\\\\', '\\', $text);
        return $text;
    }

    /**
     * Log email to JSON file
     */
    private function logEmail($emailData) {
        $log = json_decode(file_get_contents($this->logFile), true);
        $log['emails'][] = $emailData;

        // Keep only last 100 emails
        if (count($log['emails']) > 100) {
            $log['emails'] = array_slice($log['emails'], -100);
        }

        file_put_contents($this->logFile, json_encode($log, JSON_PRETTY_PRINT));
    }

    /**
     * Get all logged emails
     */
    public function getEmailLog() {
        return json_decode(file_get_contents($this->logFile), true);
    }

    /**
     * Clear all test emails
     */
    public function clearTestEmails() {
        // Remove email files
        $files = glob($this->outputDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        // Clear log
        file_put_contents($this->logFile, json_encode(['emails' => []]));

        return true;
    }
}
?>
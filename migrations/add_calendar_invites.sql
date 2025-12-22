-- Migration: Add Calendar Invite System
-- Date: 2025-09-26
-- Description: Adds invitation tracking for calendar events

-- Note: email field already exists in athletes table
-- Note: receive_invites field already exists in guardians table

-- Create invite tracking table
CREATE TABLE IF NOT EXISTS event_invitations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255),
    recipient_type ENUM('athlete', 'guardian', 'coach', 'team_member') NOT NULL,
    recipient_id INT,
    status ENUM('pending', 'sent', 'accepted', 'declined', 'tentative', 'failed') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    responded_at TIMESTAMP NULL,
    sequence INT DEFAULT 0,
    calendar_uid VARCHAR(255) NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_recipient (event_id, recipient_email),
    INDEX idx_calendar_uid (calendar_uid),
    INDEX idx_status (status)
);

-- Create invite log for audit trail
CREATE TABLE IF NOT EXISTS invite_activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invitation_id INT,
    action ENUM('created', 'sent', 'updated', 'cancelled', 'bounced', 'responded'),
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invitation_id) REFERENCES event_invitations(id) ON DELETE CASCADE
);

-- Add indexes for faster email lookups (will skip if already exist)
-- CREATE INDEX idx_athlete_email ON athletes(email);
-- CREATE INDEX idx_guardian_email ON guardians(email);
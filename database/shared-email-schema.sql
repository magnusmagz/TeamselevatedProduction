-- Shared Email System for Guardians
-- This allows multiple guardians to share an email account

-- Create email accounts table
CREATE TABLE IF NOT EXISTS email_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    account_name VARCHAR(100), -- e.g., "The Jones Family"
    is_shared BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
);

-- Add email_account_id to guardians table (check if columns exist first)
ALTER TABLE guardians
ADD COLUMN email_account_id INT AFTER email;

ALTER TABLE guardians
ADD COLUMN personal_email VARCHAR(100) AFTER email_account_id;

ALTER TABLE guardians
ADD FOREIGN KEY (email_account_id) REFERENCES email_accounts(id);

-- Create a view for getting unique email addresses for notifications
CREATE OR REPLACE VIEW notification_emails AS
SELECT DISTINCT
    ea.id as email_account_id,
    ea.email,
    ea.account_name,
    ea.is_shared,
    a.id as athlete_id,
    CONCAT(a.first_name, ' ', a.last_name) as athlete_name,
    GROUP_CONCAT(DISTINCT CONCAT(g.first_name, ' ', g.last_name) SEPARATOR ', ') as guardian_names
FROM athletes a
JOIN athlete_guardians ag ON a.id = ag.athlete_id
JOIN guardians g ON ag.guardian_id = g.id
LEFT JOIN email_accounts ea ON g.email_account_id = ea.id
WHERE ag.receives_communications = TRUE
    AND ag.active_status = TRUE
    AND a.active_status = TRUE
GROUP BY ea.id, ea.email, a.id;

-- Migrate existing guardian emails to email_accounts
INSERT IGNORE INTO email_accounts (email, account_name, is_shared)
SELECT
    email,
    CASE
        WHEN COUNT(*) > 1 THEN CONCAT('Shared: ', GROUP_CONCAT(DISTINCT CONCAT(first_name, ' ', last_name) SEPARATOR ' & '))
        ELSE MAX(CONCAT(first_name, ' ', last_name))
    END as account_name,
    CASE WHEN COUNT(*) > 1 THEN TRUE ELSE FALSE END as is_shared
FROM guardians
WHERE email IS NOT NULL AND email != ''
GROUP BY email;

-- Update guardians to link to email_accounts
UPDATE guardians g
JOIN email_accounts ea ON g.email = ea.email
SET g.email_account_id = ea.id,
    g.personal_email = g.email;

-- Create stored procedure for smart email sending
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS GetUniqueGuardianEmails(IN team_id_param INT)
BEGIN
    -- Get unique email addresses for all guardians of athletes on a team
    SELECT DISTINCT
        ea.email,
        ea.account_name,
        ea.is_shared,
        GROUP_CONCAT(DISTINCT CONCAT(g.first_name, ' ', g.last_name) SEPARATOR ', ') as guardian_names,
        GROUP_CONCAT(DISTINCT CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as athlete_names
    FROM team_members tm
    JOIN athletes a ON tm.athlete_id = a.id
    JOIN athlete_guardians ag ON a.id = ag.athlete_id
    JOIN guardians g ON ag.guardian_id = g.id
    JOIN email_accounts ea ON g.email_account_id = ea.id
    WHERE tm.team_id = team_id_param
        AND tm.status = 'active'
        AND ag.receives_communications = TRUE
        AND ag.active_status = TRUE
    GROUP BY ea.id, ea.email, ea.account_name, ea.is_shared;
END$$
DELIMITER ;
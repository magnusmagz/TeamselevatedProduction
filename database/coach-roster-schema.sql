-- Coach Roster Management Schema Updates
-- Run this after the main schema.sql

USE teams_elevated;

-- Drop existing team_members table to rebuild with new structure
DROP TABLE IF EXISTS player_position_assignments;
DROP TABLE IF EXISTS team_members;

-- Recreate team_members with enhanced structure for positions and jerseys
CREATE TABLE team_members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('player', 'assistant_coach', 'team_manager') NOT NULL DEFAULT 'player',
    jersey_number INT NULL,
    jersey_number_alt INT NULL,
    positions JSON NULL,
    primary_position VARCHAR(50) NULL,
    team_priority ENUM('primary', 'secondary', 'guest') DEFAULT 'primary',
    status ENUM('active', 'injured', 'suspended') DEFAULT 'active',
    join_date DATE NOT NULL,
    leave_date DATE NULL,
    leave_reason VARCHAR(50) NULL,
    leave_notes TEXT NULL,
    removed_by INT NULL,
    sharing_notes TEXT NULL,
    guest_player_agreement_id INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (removed_by) REFERENCES users(id),
    UNIQUE KEY unique_team_user (team_id, user_id),
    INDEX idx_user_teams (user_id, team_id),
    INDEX idx_team_roster (team_id, leave_date)
);

-- Position-specific jersey numbers
CREATE TABLE player_position_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_member_id INT NOT NULL,
    position VARCHAR(50) NOT NULL,
    jersey_number INT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    assigned_date DATE,
    FOREIGN KEY (team_member_id) REFERENCES team_members(id) ON DELETE CASCADE,
    UNIQUE KEY unique_position_jersey (team_member_id, position, jersey_number)
);

-- Guest player requests/agreements
CREATE TABLE guest_player_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    player_id INT NOT NULL,
    from_team_id INT NOT NULL,
    to_team_id INT NOT NULL,
    requested_by INT NOT NULL,
    approved_by INT NULL,
    status ENUM('pending', 'approved', 'denied', 'expired') DEFAULT 'pending',
    valid_from DATE NOT NULL,
    valid_until DATE NULL,
    reason TEXT,
    approval_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    FOREIGN KEY (player_id) REFERENCES users(id),
    FOREIGN KEY (from_team_id) REFERENCES teams(id),
    FOREIGN KEY (to_team_id) REFERENCES teams(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Track guest player for specific games
CREATE TABLE guest_player_games (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_member_id INT NOT NULL,
    game_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_member_id) REFERENCES team_members(id),
    FOREIGN KEY (game_id) REFERENCES events(id),
    UNIQUE KEY unique_guest_game (team_member_id, game_id)
);

-- Roster change history
CREATE TABLE roster_change_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_member_id INT NOT NULL,
    changed_by INT NOT NULL,
    field_name VARCHAR(50),
    old_value TEXT,
    new_value TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_member_id) REFERENCES team_members(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
);

-- Attendance tracking
CREATE TABLE attendance_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    team_member_id INT NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
    arrival_time TIME NULL,
    notes TEXT,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id),
    FOREIGN KEY (team_member_id) REFERENCES team_members(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id),
    UNIQUE KEY unique_attendance (event_id, team_member_id)
);

-- Add more parent/guardian info for youth players
CREATE TABLE player_guardians (
    id INT PRIMARY KEY AUTO_INCREMENT,
    player_id INT NOT NULL,
    guardian_id INT NOT NULL,
    relationship VARCHAR(50),
    is_primary BOOLEAN DEFAULT FALSE,
    is_emergency_contact BOOLEAN DEFAULT TRUE,
    can_pickup BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES users(id),
    FOREIGN KEY (guardian_id) REFERENCES users(id),
    UNIQUE KEY unique_player_guardian (player_id, guardian_id)
);

-- Sample data for testing coach features
INSERT INTO users (email, password, first_name, last_name, phone, role) VALUES
('player1@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Alex', 'Johnson', '555-0101', 'player'),
('player2@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sam', 'Wilson', '555-0102', 'player'),
('player3@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jordan', 'Lee', '555-0103', 'player'),
('player4@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Taylor', 'Brown', '555-0104', 'player'),
('parent1@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Patricia', 'Johnson', '555-0201', 'parent'),
('parent2@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Robert', 'Wilson', '555-0202', 'parent');

-- Create test teams
INSERT INTO teams (name, age_group, division, season_id, primary_coach_id, home_field_id) VALUES
('Lightning U12', 'U12', 'Competitive', 1, 2, 1),
('Thunder U12', 'U12', 'Elite', 1, 3, 2);

-- Add some test players to teams with positions
INSERT INTO team_members (team_id, user_id, role, jersey_number, positions, primary_position, team_priority, status, join_date) VALUES
(1, 4, 'player', 10, '["Striker", "Midfielder"]', 'Striker', 'primary', 'active', '2024-03-01'),
(1, 5, 'player', 1, '["Goalkeeper"]', 'Goalkeeper', 'primary', 'active', '2024-03-01'),
(1, 6, 'player', 7, '["Midfielder", "Defender"]', 'Midfielder', 'primary', 'active', '2024-03-01'),
(2, 7, 'player', 9, '["Striker"]', 'Striker', 'primary', 'active', '2024-03-01');

-- Add position assignments
INSERT INTO player_position_assignments (team_member_id, position, jersey_number, is_active, assigned_date) VALUES
(1, 'Striker', 10, TRUE, '2024-03-01'),
(1, 'Midfielder', 10, TRUE, '2024-03-01'),
(2, 'Goalkeeper', 1, TRUE, '2024-03-01'),
(3, 'Midfielder', 7, TRUE, '2024-03-01'),
(3, 'Defender', 7, TRUE, '2024-03-01'),
(4, 'Striker', 9, TRUE, '2024-03-01');
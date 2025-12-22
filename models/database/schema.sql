-- Teams Elevated Database Schema
-- Sports Club Management System

CREATE DATABASE IF NOT EXISTS teams_elevated;
USE teams_elevated;

-- Users table (for coaches, managers, parents, players)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role ENUM('club_manager', 'coach', 'parent', 'player', 'volunteer') NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Seasons table
CREATE TABLE seasons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    registration_start DATE,
    registration_end DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Fields/Facilities table
CREATE TABLE fields (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255),
    capacity INT,
    field_type VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Teams table (Main entity from user stories)
CREATE TABLE teams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    logo_url VARCHAR(255),
    age_group ENUM('U6', 'U8', 'U10', 'U12', 'U14', 'U16', 'U18', 'Adult') NOT NULL,
    division ENUM('Recreational', 'Competitive', 'Elite') NOT NULL,
    season_id INT NOT NULL,
    primary_coach_id INT,
    home_field_id INT,
    max_players INT DEFAULT 20,
    updated_by INT,
    last_modified_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    deletion_reason VARCHAR(255),
    deleted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES seasons(id),
    FOREIGN KEY (primary_coach_id) REFERENCES users(id),
    FOREIGN KEY (home_field_id) REFERENCES fields(id),
    FOREIGN KEY (updated_by) REFERENCES users(id),
    FOREIGN KEY (deleted_by) REFERENCES users(id),
    UNIQUE KEY unique_team_name_season (name, season_id, deleted_at)
);

-- Team Members table (for players and assistant coaches)
CREATE TABLE team_members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('player', 'assistant_coach', 'guest_player') NOT NULL,
    jersey_number INT,
    join_date DATE NOT NULL,
    leave_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_team_member (team_id, user_id, leave_date)
);

-- Team Volunteers table (from User Story 5)
CREATE TABLE team_volunteers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    volunteer_role VARCHAR(50),
    start_date DATE,
    end_date DATE,
    background_check_status ENUM('pending', 'cleared', 'expired') DEFAULT 'pending',
    background_check_date DATE,
    notes TEXT,
    assigned_by INT,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id),
    UNIQUE KEY unique_team_user_role (team_id, user_id, volunteer_role)
);

-- Team Audit Log table (from User Story 3)
CREATE TABLE team_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    field_name VARCHAR(50),
    old_value TEXT,
    new_value TEXT,
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
);

-- Events table (for games and practices)
CREATE TABLE events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    event_type ENUM('game', 'practice', 'tournament', 'scrimmage') NOT NULL,
    title VARCHAR(200),
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    field_id INT,
    opponent_name VARCHAR(100),
    is_home_event BOOLEAN DEFAULT TRUE,
    cancelled BOOLEAN DEFAULT FALSE,
    cancellation_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (field_id) REFERENCES fields(id)
);

-- Notifications table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50),
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Indexes for performance
CREATE INDEX idx_teams_season ON teams(season_id);
CREATE INDEX idx_teams_coach ON teams(primary_coach_id);
CREATE INDEX idx_team_members_user ON team_members(user_id);
CREATE INDEX idx_events_team ON events(team_id);
CREATE INDEX idx_events_datetime ON events(start_datetime);
CREATE INDEX idx_notifications_user ON notifications(user_id, read_at);

-- Stored Procedure for checking team deletion (from User Story 6)
DELIMITER $$
CREATE PROCEDURE check_team_deletion(IN team_id_param INT)
BEGIN
    SELECT
        (SELECT COUNT(*) FROM team_members WHERE team_id = team_id_param AND leave_date IS NULL) as active_members,
        (SELECT COUNT(*) FROM events WHERE team_id = team_id_param AND start_datetime > NOW()) as future_events;
END$$
DELIMITER ;

-- Sample data for testing
INSERT INTO users (email, password, first_name, last_name, role) VALUES
('manager@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John', 'Smith', 'club_manager'),
('coach1@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mike', 'Johnson', 'coach'),
('coach2@teamselevated.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sarah', 'Williams', 'coach');

INSERT INTO seasons (name, start_date, end_date, is_active, registration_start, registration_end) VALUES
('Spring 2024', '2024-03-01', '2024-06-30', TRUE, '2024-01-15', '2024-02-28'),
('Fall 2024', '2024-09-01', '2024-12-15', TRUE, '2024-07-01', '2024-08-31');

INSERT INTO fields (name, address, capacity, field_type) VALUES
('Main Stadium', '123 Sports Ave', 500, 'grass'),
('Practice Field A', '123 Sports Ave', 200, 'turf'),
('Practice Field B', '456 Athletic Blvd', 200, 'grass');
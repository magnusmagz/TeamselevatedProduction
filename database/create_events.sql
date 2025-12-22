-- Create events table for practices, games, and other team events
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    event_type ENUM('practice', 'game', 'tournament', 'meeting', 'other') DEFAULT 'practice',
    title VARCHAR(200) NOT NULL,
    description TEXT,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    venue_id INT,
    field_id INT,
    status ENUM('scheduled', 'cancelled', 'postponed', 'completed') DEFAULT 'scheduled',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
    FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_team_date (team_id, start_datetime),
    INDEX idx_field_date (field_id, start_datetime),
    INDEX idx_status (status)
);

-- Create practice patterns table (for recurring practices)
CREATE TABLE IF NOT EXISTS practice_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    pattern_name VARCHAR(100),
    days_of_week VARCHAR(20), -- comma-separated: 'mon,wed,fri'
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    venue_id INT,
    field_id INT,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
    FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Create event conflicts table for tracking scheduling conflicts
CREATE TABLE IF NOT EXISTS event_conflicts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event1_id INT NOT NULL,
    event2_id INT NOT NULL,
    conflict_type ENUM('field', 'coach', 'player') NOT NULL,
    conflict_details TEXT,
    resolved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event1_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (event2_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_conflict (event1_id, event2_id)
);
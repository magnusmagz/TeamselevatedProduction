-- Simple Seasons Schema
-- Allows club managers to create named seasons with date ranges

CREATE TABLE IF NOT EXISTS seasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_season_dates (start_date, end_date),
    INDEX idx_season_active (is_active)
);

-- Add season_id to teams table to associate teams with seasons
ALTER TABLE teams
ADD COLUMN season_id INT AFTER division,
ADD FOREIGN KEY (season_id) REFERENCES seasons(id);

-- Add season_id to team_members to track which season a player was on a team
ALTER TABLE team_members
ADD COLUMN season_id INT AFTER team_id,
ADD FOREIGN KEY (season_id) REFERENCES seasons(id);

-- Create a view to get current season
CREATE OR REPLACE VIEW current_season AS
SELECT * FROM seasons
WHERE is_active = TRUE
AND start_date <= CURDATE()
AND end_date >= CURDATE()
ORDER BY start_date DESC
LIMIT 1;
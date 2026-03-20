-- Migration: Add team associations to fundraiser campaigns
-- Allows a fundraiser campaign to be associated with one or more teams

CREATE TABLE IF NOT EXISTS fundraiser_campaign_teams (
    id SERIAL PRIMARY KEY,
    campaign_id INTEGER NOT NULL REFERENCES fundraiser_campaigns(id) ON DELETE CASCADE,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (campaign_id, team_id)
);

CREATE INDEX IF NOT EXISTS idx_fct_campaign_id ON fundraiser_campaign_teams(campaign_id);
CREATE INDEX IF NOT EXISTS idx_fct_team_id ON fundraiser_campaign_teams(team_id);

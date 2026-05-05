-- Migration 018: ICS Calendar Subscriptions
-- Adds support for subscribing to external ICS calendar feeds and one-time .ics file uploads

-- Subscription metadata table
CREATE TABLE IF NOT EXISTS calendar_subscriptions (
    id SERIAL PRIMARY KEY,
    club_id INTEGER NOT NULL,
    team_id INTEGER,
    name VARCHAR(255) NOT NULL,
    feed_url TEXT,
    source_type VARCHAR(20) NOT NULL DEFAULT 'feed',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sync_interval_minutes INTEGER NOT NULL DEFAULT 60,
    last_synced_at TIMESTAMP WITH TIME ZONE,
    last_sync_status VARCHAR(20),
    last_sync_error TEXT,
    event_count INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_source_type CHECK (source_type IN ('feed', 'file_upload')),
    CONSTRAINT chk_sync_status CHECK (last_sync_status IN ('success', 'error') OR last_sync_status IS NULL),
    CONSTRAINT chk_feed_url_required CHECK (source_type != 'feed' OR feed_url IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_cal_sub_club ON calendar_subscriptions(club_id);
CREATE INDEX IF NOT EXISTS idx_cal_sub_team ON calendar_subscriptions(team_id);
CREATE INDEX IF NOT EXISTS idx_cal_sub_active ON calendar_subscriptions(is_active) WHERE is_active = TRUE;

-- Add columns to calendar_events for tracking imported events
ALTER TABLE calendar_events
    ADD COLUMN IF NOT EXISTS subscription_id INTEGER REFERENCES calendar_subscriptions(id) ON DELETE SET NULL;

ALTER TABLE calendar_events
    ADD COLUMN IF NOT EXISTS external_uid VARCHAR(500);

-- Unique constraint: one external UID per subscription (for upsert dedup)
CREATE UNIQUE INDEX IF NOT EXISTS idx_cal_events_sub_uid
    ON calendar_events(subscription_id, external_uid)
    WHERE subscription_id IS NOT NULL AND external_uid IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_cal_events_subscription
    ON calendar_events(subscription_id)
    WHERE subscription_id IS NOT NULL;

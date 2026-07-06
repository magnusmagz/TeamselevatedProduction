-- 046_comms_tables_baseline.sql
-- Codifies the communication/email tables that exist in production Neon but had NO
-- migration files (created ad-hoc). Reverse-engineered from the live schema so fresh
-- environments are reproducible. Fully IDEMPOTENT: on prod every statement is a no-op
-- (tables/constraints/indexes already exist); on a fresh DB it builds them from scratch.
--
-- Tables: email_templates, communication_log, broadcast_campaigns, email_events,
--         email_links, email_suppressions.
-- FKs are added AFTER all tables exist (they cross-reference each other) and are
-- guarded so re-running is safe.

-- ============================================================================
-- Tables (columns + PK + UNIQUE + CHECK; FKs added below)
-- ============================================================================

CREATE TABLE IF NOT EXISTS email_templates (
    id SERIAL PRIMARY KEY,
    club_profile_id integer,
    name character varying(255) NOT NULL,
    subject character varying(500),
    design_json jsonb,
    html_output text,
    category character varying(50) DEFAULT 'general'::character varying,
    is_active boolean DEFAULT true,
    scope character varying(20) DEFAULT 'club'::character varying,
    team_visibility jsonb DEFAULT '[]'::jsonb,
    cloned_from integer,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    channel character varying(10) DEFAULT 'email'::character varying,
    body_text text,
    tournament_event_kind character varying(60),
    CONSTRAINT email_templates_scope_check CHECK (((scope)::text = ANY ((ARRAY['club'::character varying, 'personal'::character varying, 'platform'::character varying])::text[]))),
    CONSTRAINT email_templates_channel_check CHECK (((channel)::text = ANY ((ARRAY['email'::character varying, 'sms'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS broadcast_campaigns (
    id SERIAL PRIMARY KEY,
    club_profile_id integer NOT NULL,
    user_id integer NOT NULL,
    template_id integer,
    name character varying(255),
    subject character varying(255) NOT NULL,
    channel character varying(10) NOT NULL DEFAULT 'email'::character varying,
    recipient_criteria jsonb DEFAULT '{}'::jsonb,
    status character varying(20) DEFAULT 'draft'::character varying,
    scheduled_at timestamp without time zone,
    sent_at timestamp without time zone,
    total_recipients integer DEFAULT 0,
    sent_count integer DEFAULT 0,
    skipped_count integer DEFAULT 0,
    failed_count integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT broadcast_campaigns_channel_check CHECK (((channel)::text = ANY ((ARRAY['email'::character varying, 'sms'::character varying])::text[]))),
    CONSTRAINT broadcast_campaigns_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'scheduled'::character varying, 'sending'::character varying, 'sent'::character varying, 'cancelled'::character varying, 'failed'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS communication_log (
    id SERIAL PRIMARY KEY,
    club_profile_id integer NOT NULL,
    user_id integer NOT NULL,
    channel character varying(10) NOT NULL,
    recipient_type character varying(20),
    recipient_id integer,
    recipient_email character varying(255),
    recipient_phone character varying(20),
    recipient_name character varying(200),
    athlete_id integer,
    subject character varying(500),
    body text,
    html_body text,
    status character varying(20) DEFAULT 'queued'::character varying,
    tracking_id character varying(64),
    sendgrid_message_id character varying(255),
    twilio_message_sid character varying(64),
    broadcast_campaign_id integer,
    event_id integer,
    template_id integer,
    open_count integer DEFAULT 0,
    click_count integer DEFAULT 0,
    sent_at timestamp without time zone,
    delivered_at timestamp without time zone,
    opened_at timestamp without time zone,
    last_opened_at timestamp without time zone,
    clicked_at timestamp without time zone,
    bounced_at timestamp without time zone,
    unsubscribed_at timestamp without time zone,
    failed_at timestamp without time zone,
    failure_reason text,
    retry_count integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    tournament_id integer,
    CONSTRAINT communication_log_tracking_id_key UNIQUE (tracking_id),
    CONSTRAINT communication_log_recipient_type_check CHECK (((recipient_type)::text = ANY ((ARRAY['athlete'::character varying, 'guardian'::character varying, 'coach'::character varying, 'user'::character varying])::text[]))),
    CONSTRAINT communication_log_channel_check CHECK (((channel)::text = ANY ((ARRAY['email'::character varying, 'sms'::character varying])::text[]))),
    CONSTRAINT communication_log_status_check CHECK (((status)::text = ANY ((ARRAY['queued'::character varying, 'sending'::character varying, 'sent'::character varying, 'delivered'::character varying, 'failed'::character varying, 'bounced'::character varying, 'rejected'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS email_events (
    id SERIAL PRIMARY KEY,
    communication_log_id integer NOT NULL,
    event_type character varying(50) NOT NULL,
    event_data jsonb,
    ip_address inet,
    user_agent text,
    occurred_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT email_events_event_type_check CHECK (((event_type)::text = ANY ((ARRAY['open'::character varying, 'click'::character varying, 'bounce'::character varying, 'spam_complaint'::character varying, 'unsubscribe'::character varying, 'delivered'::character varying, 'dropped'::character varying, 'deferred'::character varying])::text[])))
);

CREATE TABLE IF NOT EXISTS email_links (
    id SERIAL PRIMARY KEY,
    communication_log_id integer NOT NULL,
    link_id character varying(64) NOT NULL,
    original_url text NOT NULL,
    click_count integer DEFAULT 0,
    first_clicked_at timestamp without time zone,
    last_clicked_at timestamp without time zone,
    CONSTRAINT email_links_link_id_key UNIQUE (link_id)
);

CREATE TABLE IF NOT EXISTS email_suppressions (
    id SERIAL PRIMARY KEY,
    club_profile_id integer NOT NULL,
    email character varying(255),
    phone character varying(20),
    channel character varying(10) NOT NULL DEFAULT 'email'::character varying,
    reason character varying(50) NOT NULL,
    scope character varying(20) DEFAULT 'club'::character varying,
    team_id integer,
    suppressed_by integer,
    communication_log_id integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT email_suppressions_channel_check CHECK (((channel)::text = ANY ((ARRAY['email'::character varying, 'sms'::character varying])::text[]))),
    CONSTRAINT email_suppressions_reason_check CHECK (((reason)::text = ANY ((ARRAY['unsubscribe_all'::character varying, 'unsubscribe_team'::character varying, 'spam_complaint'::character varying, 'hard_bounce'::character varying, 'manual'::character varying, 'twilio_stop'::character varying])::text[]))),
    CONSTRAINT email_suppressions_scope_check CHECK (((scope)::text = ANY ((ARRAY['club'::character varying, 'team'::character varying])::text[])))
);

-- ============================================================================
-- Foreign keys (added after all tables exist; guarded so re-running is safe)
-- ============================================================================
DO $$
DECLARE
    fk RECORD;
BEGIN
    FOR fk IN
        SELECT * FROM (VALUES
            ('email_templates', 'email_templates_club_profile_id_fkey', 'FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE'),
            ('email_templates', 'email_templates_cloned_from_fkey',     'FOREIGN KEY (cloned_from) REFERENCES email_templates(id) ON DELETE SET NULL'),
            ('email_templates', 'email_templates_created_by_fkey',      'FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL'),
            ('email_templates', 'email_templates_updated_by_fkey',      'FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL'),
            ('broadcast_campaigns', 'broadcast_campaigns_club_profile_id_fkey', 'FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE'),
            ('broadcast_campaigns', 'broadcast_campaigns_user_id_fkey',        'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'),
            ('broadcast_campaigns', 'broadcast_campaigns_template_id_fkey',    'FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL'),
            ('communication_log', 'communication_log_club_profile_id_fkey',   'FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE'),
            ('communication_log', 'communication_log_user_id_fkey',           'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'),
            ('communication_log', 'communication_log_athlete_id_fkey',        'FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE SET NULL'),
            ('communication_log', 'communication_log_template_id_fkey',       'FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL'),
            ('communication_log', 'communication_log_broadcast_campaign_id_fkey', 'FOREIGN KEY (broadcast_campaign_id) REFERENCES broadcast_campaigns(id) ON DELETE SET NULL'),
            ('communication_log', 'communication_log_tournament_id_fkey',     'FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE SET NULL'),
            ('email_events', 'email_events_communication_log_id_fkey',        'FOREIGN KEY (communication_log_id) REFERENCES communication_log(id) ON DELETE CASCADE'),
            ('email_links', 'email_links_communication_log_id_fkey',          'FOREIGN KEY (communication_log_id) REFERENCES communication_log(id) ON DELETE CASCADE'),
            ('email_suppressions', 'email_suppressions_club_profile_id_fkey', 'FOREIGN KEY (club_profile_id) REFERENCES club_profile(id) ON DELETE CASCADE'),
            ('email_suppressions', 'email_suppressions_team_id_fkey',         'FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL'),
            ('email_suppressions', 'email_suppressions_suppressed_by_fkey',   'FOREIGN KEY (suppressed_by) REFERENCES users(id) ON DELETE SET NULL'),
            ('email_suppressions', 'email_suppressions_communication_log_id_fkey', 'FOREIGN KEY (communication_log_id) REFERENCES communication_log(id) ON DELETE SET NULL')
        ) AS v(tbl, conname, def)
    LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = fk.conname) THEN
            EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I %s', fk.tbl, fk.conname, fk.def);
        END IF;
    END LOOP;
END $$;

-- ============================================================================
-- Indexes
-- ============================================================================
CREATE INDEX IF NOT EXISTS idx_email_templates_active ON email_templates (is_active);
CREATE INDEX IF NOT EXISTS idx_email_templates_club ON email_templates (club_profile_id);
CREATE INDEX IF NOT EXISTS idx_email_templates_scope ON email_templates (scope);
CREATE INDEX IF NOT EXISTS idx_email_templates_category ON email_templates (category);
CREATE INDEX IF NOT EXISTS idx_email_templates_tournament_kind ON email_templates (tournament_event_kind) WHERE (tournament_event_kind IS NOT NULL);

CREATE INDEX IF NOT EXISTS idx_broadcast_campaigns_club ON broadcast_campaigns (club_profile_id);
CREATE INDEX IF NOT EXISTS idx_broadcast_campaigns_user ON broadcast_campaigns (user_id);
CREATE INDEX IF NOT EXISTS idx_broadcast_campaigns_status ON broadcast_campaigns (status);
CREATE INDEX IF NOT EXISTS idx_broadcast_campaigns_scheduled ON broadcast_campaigns (scheduled_at) WHERE ((status)::text = 'scheduled'::text);

CREATE INDEX IF NOT EXISTS idx_communication_log_club ON communication_log (club_profile_id);
CREATE INDEX IF NOT EXISTS idx_communication_log_user ON communication_log (user_id);
CREATE INDEX IF NOT EXISTS idx_communication_log_user_created ON communication_log (user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_communication_log_athlete ON communication_log (athlete_id);
CREATE INDEX IF NOT EXISTS idx_communication_log_broadcast ON communication_log (broadcast_campaign_id);
CREATE INDEX IF NOT EXISTS idx_communication_log_status ON communication_log (status);
CREATE INDEX IF NOT EXISTS idx_communication_log_channel ON communication_log (channel);
CREATE INDEX IF NOT EXISTS idx_communication_log_created ON communication_log (created_at);
CREATE INDEX IF NOT EXISTS idx_communication_log_tracking ON communication_log (tracking_id);
CREATE INDEX IF NOT EXISTS idx_communication_log_sendgrid ON communication_log (sendgrid_message_id);
CREATE INDEX IF NOT EXISTS idx_communication_log_twilio ON communication_log (twilio_message_sid);
CREATE INDEX IF NOT EXISTS idx_communication_log_recipient_email ON communication_log (recipient_email);

CREATE INDEX IF NOT EXISTS idx_email_events_type ON email_events (event_type);
CREATE INDEX IF NOT EXISTS idx_email_events_occurred ON email_events (occurred_at);
CREATE INDEX IF NOT EXISTS idx_email_events_log ON email_events (communication_log_id);

CREATE INDEX IF NOT EXISTS idx_email_links_log ON email_links (communication_log_id);
CREATE INDEX IF NOT EXISTS idx_email_links_link_id ON email_links (link_id);

CREATE INDEX IF NOT EXISTS idx_email_suppressions_channel ON email_suppressions (channel);
CREATE INDEX IF NOT EXISTS idx_email_suppressions_club_email ON email_suppressions (club_profile_id, email);
CREATE INDEX IF NOT EXISTS idx_email_suppressions_club_phone ON email_suppressions (club_profile_id, phone);
CREATE UNIQUE INDEX IF NOT EXISTS idx_email_suppressions_unique ON email_suppressions (club_profile_id, email, channel, scope, COALESCE(team_id, 0));

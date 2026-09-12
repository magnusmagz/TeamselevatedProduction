-- 103_user_activity_daily.sql
--
-- Product usage metrics: daily / weekly / monthly active users, bucketed by
-- club and by role (Maggie, 2026-09-12: "we want daily active users, weekly,
-- monthly and we want users bucketed into clubs and roles so we can see the
-- usage rates for these roles").
--
-- What "active" means here: the signed-in app loaded on that calendar day.
-- The frontend pings api/usage-ping.php once per user per local day
-- (useUsagePing, in AppContent so staff app, parent portal and referee page
-- are all one call), and the server writes one row per (user, club, role,
-- day). Nothing else on the platform records this — login_success in
-- audit_log only fires on a password login, and a 24 h token means most
-- return visits never log in again, so it undercounts by design.
--
-- Why one row per ROLE and not per user: a coach-parent is a coach in the
-- coaches' rate and a parent in the parents' rate. Roles are evaluated
-- independently (lib/usage_activity.php, the same rule for the numerator and
-- the denominator), never `$isParent = !$isCoach`. DAU per club is still
-- COUNT(DISTINCT user_id) so the same person is not counted twice there.
--
-- club_id 0 is the platform itself: a super admin has no club row, and a
-- NULL in a primary key is not allowed. Nothing else ever writes 0.
--
-- Written by lib/usage_activity.php (te_usage_record), read by
-- api/usage-metrics.php (super admin only). scripts/backfill-user-activity.php
-- seeds history from audit_log / chat_messages / last_login_at with
-- surface = 'backfill', so the first month of charts is not empty.

CREATE TABLE IF NOT EXISTS user_activity_daily (
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    club_id        INTEGER NOT NULL,           -- 0 = platform (super admin)
    role           VARCHAR(32) NOT NULL,       -- user_club_access role, or super_admin
    activity_date  DATE NOT NULL,              -- the user's local calendar day
    surface        VARCHAR(16) NOT NULL DEFAULT 'staff',  -- staff | parent | referee | backfill
    first_seen_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    last_seen_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, club_id, role, activity_date)
);

-- The metrics query is "who was active in this club in this window".
CREATE INDEX IF NOT EXISTS idx_user_activity_daily_club_date
    ON user_activity_daily (club_id, activity_date);
CREATE INDEX IF NOT EXISTS idx_user_activity_daily_date
    ON user_activity_daily (activity_date);

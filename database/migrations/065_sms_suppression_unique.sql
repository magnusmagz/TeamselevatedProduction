-- 065: make an SMS suppression idempotent (M2 of docs/sms-inbox-scope.md)
--
-- `idx_email_suppressions_unique` covers (club_profile_id, EMAIL, channel, scope,
-- COALESCE(team_id,0)). An SMS suppression has email = NULL, and Postgres treats
-- NULLs in a unique index as distinct — so that index never constrains a phone
-- row, and the `ON CONFLICT ... DO NOTHING` in
-- SmsSendService::handleStatusCallback silently never fires for a STOP.
--
-- Nothing has gone wrong yet only because there are zero SMS suppression rows in
-- production. The moment STOPs start being recorded (M2 does exactly that) a
-- family texting STOP twice — or texting it once while a send is failing with
-- 21610 — would accumulate duplicate rows, each one counted separately by the
-- preview's suppressed tally.
--
-- Partial, because it only makes sense for phone-bearing SMS rows and must not
-- interfere with the existing email index.

CREATE UNIQUE INDEX IF NOT EXISTS email_suppressions_sms_unique
    ON email_suppressions (club_profile_id, phone, scope, COALESCE(team_id, 0))
    WHERE channel = 'sms' AND phone IS NOT NULL;

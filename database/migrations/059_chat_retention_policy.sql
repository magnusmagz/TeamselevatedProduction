-- Bring chat under the documented retention regime.
--
-- Before this, `scripts/retention-check.php` had plans for health records,
-- consents and audit entries but nothing at all for chat — so chat was a
-- permanent record with no rule that ever aged anything out.
--
-- This is the COPPA side of the chat work, and worth stating plainly because the
-- reasoning is easy to get backwards: COPPA is an argument for DELETING children's
-- data, not for keeping it. It requires retention only as long as reasonably
-- necessary, gives parents a deletion right, and forbids indefinite retention.
-- What argues for keeping chat is child-safety recordkeeping (SafeSport-style
-- expectations around adult↔minor communication) and club defensibility. COPPA
-- supplies the ceiling; these policies are that ceiling.
--
-- Both ship with auto_delete = FALSE, matching all five existing policies —
-- flag for review, don't silently destroy. Nothing here deletes anything until
-- someone runs `retention-check.php --purge` AND the policy is armed.

-- Moderation-removed messages. Already invisible to every participant, so keeping
-- the row past the window is exposure with no product value. The window exists so
-- a bad moderation call can still be reversed.
-- Inert until admin moderation removal ships — nothing writes chat_messages.deleted_at
-- yet, so this reports zero. That is correct, not broken.
INSERT INTO data_retention_policy (data_type, retention_days, description, auto_delete, created_at, updated_at)
SELECT 'chat_messages_removed', 90,
       'Chat messages removed by an administrator. Hard-deleted after the reversal window; already hidden from all participants.',
       FALSE, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM data_retention_policy WHERE data_type = 'chat_messages_removed'
);

-- Chat messages generally. 1095 days (3 years) is a placeholder that REPORTS and
-- never deletes; how long a club's communications should live is a club policy
-- decision, not a number to guess at in code. Declaring it means the obligation
-- shows up in the retention report instead of being silently absent.
INSERT INTO data_retention_policy (data_type, retention_days, description, auto_delete, created_at, updated_at)
SELECT 'chat_messages', 1095,
       'Chat message history. Retention period is a placeholder pending a club policy decision; auto_delete FALSE so this reports only.',
       FALSE, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM data_retention_policy WHERE data_type = 'chat_messages'
);

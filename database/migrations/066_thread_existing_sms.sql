-- 066: put existing outbound SMS into their conversations (M4 of docs/sms-inbox-scope.md)
--
-- M1 gave inbound messages a conversation_id. Outbound ones never got one, so a
-- thread opened in the inbox began at the family's reply with no context — an
-- admin would read "I did not receive an email" with nothing above it explaining
-- what email. The message that prompted every one of those replies is already in
-- this table; it just was not threaded.
--
-- The key must match te_sms_conversation_id() in lib/inbound_sms.php exactly:
-- first 32 hex chars of sha256("<club>|<E.164 phone>"). Verified identical
-- against the PHP before running — a mismatch would silently create a SECOND
-- thread per contact rather than fail, which is the kind of bug you find months
-- later.
--
-- Safe on the live table: all 140 sms rows already store recipient_phone in E.164
-- (SmsSendService normalizes before insert), so no phone parsing is needed here.
-- Rows without a phone are skipped rather than guessed at.

UPDATE communication_log
SET conversation_id = substr(
        encode(sha256(convert_to(club_profile_id || '|' || recipient_phone, 'UTF8')), 'hex'),
        1, 32
    )
WHERE channel = 'sms'
  AND conversation_id IS NULL
  AND recipient_phone IS NOT NULL
  AND recipient_phone <> '';

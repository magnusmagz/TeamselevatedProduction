<?php
/**
 * Data-retention rules: how each policy in `data_retention_policy` identifies and
 * removes expired rows.
 *
 * Extracted from `scripts/retention-check.php` (2026-07-30) so the rules can be
 * unit-tested. That script connects to Neon at load, so requiring it from a test
 * would have meant a test that talks to the production database.
 *
 * Keyed by `data_retention_policy.data_type`:
 *   - `label`  human name for the report
 *   - `count`  SELECT of how many rows are expired
 *   - `before` OPTIONAL statements run in the same transaction BEFORE the delete,
 *              for clearing inbound references the delete would otherwise violate
 *   - `delete` removes them
 *
 * Anything without an entry here is reported as unsupported rather than guessed
 * at — a wrong DELETE is unrecoverable.
 *
 * Every statement takes exactly one bound parameter, `:days`.
 */

function te_retention_plans(): array
{
    /**
     * Both chat plans must null out inbound references before deleting.
     *
     * `chat_read_receipts.last_read_message_id` has a **NO ACTION** foreign key
     * onto `chat_messages` (verified against live Neon 2026-07-30), so deleting a
     * message any receipt still points at raises SQLSTATE 23503 and takes the
     * whole purge down with it. `chat_reactions` cascades and needs no help.
     *
     * `conversation_participants.last_read_message_id` has no FK, so it cannot
     * block the delete — it is cleared anyway so the watermark does not point at
     * a row that no longer exists.
     *
     * The table is empty today and nothing writes it (it predates the
     * `conversations` model and was superseded by `conversation_participants`),
     * which is exactly why this is worth handling now: the purge would work in
     * testing and fail the first time it mattered.
     */
    $clearChatReadPointers = static function (string $expiredIds): array {
        return [
            "UPDATE chat_read_receipts SET last_read_message_id = NULL
             WHERE last_read_message_id IN ($expiredIds)",
            "UPDATE conversation_participants SET last_read_message_id = NULL
             WHERE last_read_message_id IN ($expiredIds)",
        ];
    };

    $removedIds = "SELECT id FROM chat_messages
                   WHERE deleted_at IS NOT NULL
                     AND deleted_at < NOW() - (:days || ' days')::interval";

    $agedIds = "SELECT id FROM chat_messages
                WHERE created_at < NOW() - (:days || ' days')::interval";

    return [
        'athlete_medical' => [
            'label'  => 'Athlete health profiles',
            'count'  => "SELECT count(*) FROM athlete_medical m JOIN athletes a ON a.id = m.athlete_id
                         WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                           AND a.deleted_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM athlete_medical WHERE athlete_id IN (
                             SELECT a.id FROM athletes a
                             WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                               AND a.deleted_at < NOW() - (:days || ' days')::interval)",
        ],
        'medical_records' => [
            'label'  => 'Medical documents',
            'count'  => "SELECT count(*) FROM medical_records m JOIN athletes a ON a.id = m.athlete_id
                         WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                           AND a.deleted_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM medical_records WHERE athlete_id IN (
                             SELECT a.id FROM athletes a
                             WHERE a.active_status = FALSE AND a.deleted_at IS NOT NULL
                               AND a.deleted_at < NOW() - (:days || ' days')::interval)",
        ],
        'consent_records' => [
            'label'  => 'Revoked consents',
            'count'  => "SELECT count(*) FROM consent_records
                         WHERE revoked_at IS NOT NULL
                           AND revoked_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM consent_records
                         WHERE revoked_at IS NOT NULL
                           AND revoked_at < NOW() - (:days || ' days')::interval",
        ],
        'audit_logs' => [
            'label'  => 'Audit log entries',
            'count'  => "SELECT count(*) FROM audit_log WHERE created_at < NOW() - (:days || ' days')::interval",
            'delete' => "DELETE FROM audit_log WHERE created_at < NOW() - (:days || ' days')::interval",
        ],

        /**
         * Moderation-removed chat messages.
         *
         * These are already invisible to every participant — `deleted_at` is set
         * and all read paths filter it out. Retaining the row past the window is
         * pure exposure with no product value; the window exists only so a bad
         * moderation call can be reversed.
         *
         * Inert until admin moderation removal ships (Phase 2 of
         * docs/chat-archive-plan.md) — nothing writes `deleted_at` yet, so this
         * finds zero rows. That is correct, not broken.
         */
        'chat_messages_removed' => [
            'label'  => 'Removed chat messages',
            'count'  => "SELECT count(*) FROM chat_messages
                         WHERE deleted_at IS NOT NULL
                           AND deleted_at < NOW() - (:days || ' days')::interval",
            'before' => $clearChatReadPointers($removedIds),
            'delete' => "DELETE FROM chat_messages
                         WHERE deleted_at IS NOT NULL
                           AND deleted_at < NOW() - (:days || ' days')::interval",
        ],

        /**
         * Chat messages generally.
         *
         * Deliberately age-based on the message rather than scoped to inactive
         * athletes the way the health plans are. A health record's purpose lasts
         * as long as the child is enrolled; a three-year-old message about a
         * practice time has no purpose at all, and scoping this to departed
         * members would mean an active club's chat is retained forever — which is
         * the thing COPPA's retention-minimisation rule is aimed at.
         *
         * Ships with `auto_delete = FALSE`, like every other seeded policy. How
         * long a club's communications should live is Maggie's decision, not a
         * number to guess at in code. The point of declaring it now is that the
         * obligation shows up in the retention report instead of being silently
         * absent.
         */
        'chat_messages' => [
            'label'  => 'Chat messages',
            'count'  => "SELECT count(*) FROM chat_messages
                         WHERE created_at < NOW() - (:days || ' days')::interval",
            'before' => $clearChatReadPointers($agedIds),
            'delete' => "DELETE FROM chat_messages
                         WHERE created_at < NOW() - (:days || ' days')::interval",
        ],
    ];
}

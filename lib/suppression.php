<?php
/**
 * Email suppression check that respects the unsubscribe SCOPE.
 *
 * Extracted from EmailSendService::isSuppressed so the compliance behavior is
 * unit-testable. Rule:
 *   - A club-wide opt-out (scope='club', or a legacy row with no scope) blocks all
 *     of that club's email.
 *   - A team-scoped opt-out (scope='team', team_id=X) blocks ONLY sends that target
 *     team X — so unsubscribing from one team no longer silently stops all club email.
 *   - A tournament-scoped opt-out is ignored here; it only blocks tournament sends,
 *     which are a separate path.
 */
if (!function_exists('te_email_suppressed')) {
    /**
     * @param PDO    $pdo
     * @param string $email
     * @param int    $clubProfileId
     * @param array  $teamIds  Teams this send targets (empty = general/individual send).
     * @return bool   true if the recipient should be suppressed for this send.
     */
    function te_email_suppressed(PDO $pdo, string $email, int $clubProfileId, array $teamIds = []): bool
    {
        $sql = "SELECT COUNT(*) FROM email_suppressions
                WHERE email = ? AND club_profile_id = ? AND channel = 'email'
                  AND (scope = 'club' OR scope IS NULL";
        $params = [$email, $clubProfileId];

        if (!empty($teamIds)) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $sql .= " OR (scope = 'team' AND team_id IN ($placeholders))";
            $params = array_merge($params, array_map('intval', $teamIds));
        }
        $sql .= ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }
}

/**
 * ─── SMS side ────────────────────────────────────────────────────────────────
 *
 * The SMS skip decision has TWO independent inputs, and until 2026-07-30 the
 * send path and the preview path each got a different subset of them right:
 *
 *   - SmsSendService::queueSms checked email_suppressions AND guardians.sms_opt_out,
 *     comparing against the E.164-NORMALIZED phone.
 *   - handlePreviewBroadcast checked only email_suppressions, comparing against the
 *     RAW column value straight out of guardians.mobile_phone.
 *
 * Both mismatches push the same way: preview promised more recipients than the
 * send delivered. A club-wide "139 will receive" that quietly sends 131 is the
 * kind of thing you only notice from a parent complaint.
 *
 * These helpers are the single predicate both paths now use. They are bulk-load
 * shaped on purpose — resolveSpecialGroup's comment in recipient-search-gateway.php
 * explains why: per-recipient suppression queries are fine for a 20-person team and
 * time the request out on a 300-person club-wide send.
 */

if (!function_exists('te_normalize_sms_phone')) {
    /**
     * Canonical E.164 normalization. Non-throwing sibling of
     * SmsSendService::normalizePhone (which delegates here and throws instead).
     *
     * @return string|null Normalized E.164, or null if the input can't be resolved.
     */
    function te_normalize_sms_phone($phone): ?string
    {
        if ($phone === null || trim((string) $phone) === '') {
            return null;
        }

        $hasPlus = (substr(trim((string) $phone), 0, 1) === '+');
        $digits  = preg_replace('/[^\d]/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        // Already international — trust it if the length is plausible.
        if ($hasPlus) {
            $len = strlen($digits);
            return ($len >= 10 && $len <= 15) ? '+' . $digits : null;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }

        return null;
    }
}

if (!function_exists('te_sms_suppression_map')) {
    /**
     * All SMS suppressions for a club, keyed by NORMALIZED phone.
     *
     * Rows whose phone can't be normalized are keyed by their raw trimmed value so
     * a malformed stored suppression still blocks its exact match rather than
     * silently permitting the send.
     *
     * @return array<string, array{reason: string|null, scope: string|null, team_id: int|null}>
     */
    function te_sms_suppression_map(PDO $pdo, int $clubProfileId): array
    {
        $stmt = $pdo->prepare("
            SELECT phone, reason, scope, team_id
            FROM email_suppressions
            WHERE club_profile_id = ? AND channel = 'sms'
              AND phone IS NOT NULL AND trim(phone) <> ''
        ");
        $stmt->execute([$clubProfileId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = te_normalize_sms_phone($row['phone']) ?? trim((string) $row['phone']);
            // First row wins; a club-wide row outranks a team-scoped one for the
            // same number, so prefer it when both exist.
            $incomingScope = $row['scope'] ?? 'club';
            if (isset($map[$key]) && $map[$key]['scope'] === 'club') {
                continue;
            }
            $map[$key] = [
                'reason'  => $row['reason'],
                'scope'   => $incomingScope,
                'team_id' => isset($row['team_id']) ? (int) $row['team_id'] : null,
            ];
        }

        return $map;
    }
}

if (!function_exists('te_sms_opted_out_guardian_ids')) {
    /**
     * Every guardian id with sms_opt_out set — deliberately NOT club-scoped.
     *
     * `guardians.sms_opt_out` is a flag on the person, not on their membership of a
     * club, and the code this replaced read it as such (`SELECT sms_opt_out FROM
     * guardians WHERE id = ?`, no club filter). Scoping it through
     * athlete_guardians → athletes.club_id would narrow a STOP check, and a
     * narrowed opt-out check means texting someone who told us to stop. Suppression
     * ROWS are club-scoped, because email_suppressions carries club_profile_id; this
     * flag is not, and must not be made so for tidiness.
     *
     * Selective enough to load whole: it returns only people who have opted out.
     *
     * @return array<int, true> Set keyed by guardian id, for O(1) lookup.
     */
    function te_sms_opted_out_guardian_ids(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT id FROM guardians WHERE sms_opt_out = true");

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }
}

if (!function_exists('te_sms_skip_reason')) {
    /**
     * Why this recipient should NOT receive an SMS, or null if they should.
     *
     * Scope handling mirrors te_email_suppressed: a club-wide opt-out blocks
     * everything, a team-scoped one blocks only sends targeting that team. Twilio
     * STOP always writes scope='club' (SmsSendService::handleStatusCallback), so in
     * practice today every SMS suppression is club-wide — the team branch is here so
     * the two channels stay consistent rather than diverging again later.
     *
     * @param array  $recipient  Needs 'phone'; 'type' and 'id' enable the guardian check.
     * @param array  $teamIds    Teams this send targets (empty = general/club-wide).
     * @return array{reason: string, detail: string}|null
     */
    function te_sms_skip_reason(
        array $recipient,
        array $suppressionMap,
        array $optedOutGuardianIds,
        array $teamIds = []
    ): ?array {
        $raw   = $recipient['phone'] ?? null;
        $phone = te_normalize_sms_phone($raw);

        if ($phone === null) {
            return [
                'reason' => 'invalid_phone',
                'detail' => ($raw === null || trim((string) $raw) === '')
                    ? 'Phone number is empty'
                    : "Unable to normalize phone number: {$raw}",
            ];
        }

        if (isset($suppressionMap[$phone])) {
            $entry = $suppressionMap[$phone];
            $scope = $entry['scope'] ?? 'club';

            $applies = ($scope === 'club' || $scope === null)
                || ($scope === 'team' && $entry['team_id'] !== null
                    && in_array($entry['team_id'], array_map('intval', $teamIds), true));

            if ($applies) {
                return [
                    'reason' => 'suppressed',
                    'detail' => 'SMS suppression: ' . ($entry['reason'] ?? 'unknown'),
                ];
            }
        }

        $type = $recipient['type'] ?? null;
        $id   = $recipient['id'] ?? null;
        if ($type === 'guardian' && $id !== null && isset($optedOutGuardianIds[(int) $id])) {
            return [
                'reason' => 'opted_out',
                'detail' => 'Guardian has opted out of SMS',
            ];
        }

        return null;
    }
}

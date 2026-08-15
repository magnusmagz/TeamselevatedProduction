<?php
/**
 * Slack incoming-webhook poster.
 *
 * One rule, inherited from AuditLogger: **notifying must never break the thing it
 * is notifying about.** A failed post is logged to error_log and swallowed. A
 * Slack outage, a rotated webhook, or a network blip must not stop a user filing
 * a support ticket — the row is committed before this is ever called.
 *
 * Config var: SLACK_WEBHOOK_URL. Absent (local dev, tests) means "no Slack
 * configured", which is a silent no-op rather than an error — the feature still
 * works, it just doesn't announce itself.
 */

if (!function_exists('te_slack_configured')) {
    /** @return bool Whether a webhook URL is available. */
    function te_slack_configured(): bool
    {
        $url = getenv('SLACK_WEBHOOK_URL') ?: ($_ENV['SLACK_WEBHOOK_URL'] ?? '');
        return is_string($url) && trim($url) !== '';
    }

    /**
     * Post a Block Kit message.
     *
     * @param array $payload Slack message payload (blocks/text).
     * @return bool Whether Slack accepted it. Callers may ignore this.
     */
    function te_slack_post(array $payload): bool
    {
        $url = trim((string) (getenv('SLACK_WEBHOOK_URL') ?: ($_ENV['SLACK_WEBHOOK_URL'] ?? '')));
        if ($url === '') {
            error_log('Slack: SLACK_WEBHOOK_URL not set — skipping post');
            return false;
        }

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload),
                // Short. This runs inside the user's request, and they should not
                // wait on Slack to be told their ticket was filed.
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($code !== 200) {
                error_log("Slack: post failed HTTP $code " . substr((string) $body, 0, 200) . ' ' . $err);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            // Deliberately swallowed — see the file docblock.
            error_log('Slack: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build the support-ticket message.
     *
     * Kept pure (no I/O) so the formatting is unit-testable without a webhook.
     *
     * @param array       $t             Ticket fields.
     * @param string|null $screenshotUrl Signed, unauthenticated, expiring link.
     * @return array Slack payload.
     */
    function te_slack_support_ticket_payload(array $t, ?string $screenshotUrl = null): array
    {
        $verified = !empty($t['user_id']);
        $who = trim((string) ($t['reporter_name'] ?? '')) ?: 'Unknown';
        $email = trim((string) ($t['reporter_email'] ?? ''));
        if ($email !== '') {
            $who .= " <{$email}>";
        }
        // An unauthenticated report is worth having AND worth labelling: anyone
        // can file one, so nothing in it should be trusted as identity.
        if (!$verified) {
            $who .= '  ⚠️ not signed in — identity unverified';
        }

        $fields = [
            ['type' => 'mrkdwn', 'text' => "*From*\n" . $who],
            ['type' => 'mrkdwn', 'text' => "*Club*\n" . (($t['club_name'] ?? '') ?: '—')],
        ];
        if (!empty($t['page_url'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Page*\n" . $t['page_url']];
        }
        if (!empty($t['device_summary'])) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Device*\n" . $t['device_summary']];
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => '🎫 Support #' . ($t['id'] ?? '?'), 'emoji' => true],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => (string) ($t['description'] ?? '')],
            ],
            ['type' => 'section', 'fields' => $fields],
        ];

        if ($screenshotUrl) {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "📎 <{$screenshotUrl}|View screenshot>"],
            ];
        }

        return [
            // Fallback text for notifications and any client that can't render blocks.
            'text'   => 'Support #' . ($t['id'] ?? '?') . ' from ' . $who,
            'blocks' => $blocks,
        ];
    }
}

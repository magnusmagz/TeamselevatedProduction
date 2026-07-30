<?php
/**
 * Per-recipient merge-field resolution for SMS.
 *
 * Lives in lib/ rather than inside the gateway so it can be tested without
 * executing the gateway's auth and header side effects on include.
 */

if (!function_exists('resolveSmsBodies')) {
    /**
     * Resolve {{merge_tags}} in an SMS body, once per recipient.
     *
     * SMS had no merge resolution on ANY path — send-sms passed the body straight to
     * the queue, and the broadcast handler resolved only in its email branch. Every
     * SMS template in the library uses tags, so families were receiving the literal
     * "{{athlete_first_name}}".
     *
     * Returns [recipients each carrying _resolved_body, list of tags still unresolved].
     * The caller stops the send on anything unresolved, matching send-email: a raw
     * {{tag}} in a text is worse than a failed send, and it cannot be unsent.
     */
    function resolveSmsBodies(array $recipients, $body, $mergeFieldService, array $baseContext) {
        if (strpos((string) $body, '{{') === false) {
            return [$recipients, []];
        }

        $unresolved = [];
        foreach ($recipients as &$r) {
            $context = $baseContext;
            $context['athlete_id']  = $r['athlete_id'] ?? null;
            $context['guardian_id'] = (($r['type'] ?? '') === 'guardian') ? ($r['id'] ?? null) : null;
            // A phone belongs to one person, so there is no household combining here —
            // {{recipient_first_name}} resolves from that person's own record.
            //
            // Supply the display name ONLY when there is no guardian/athlete row to
            // derive from (a coach or bare user recipient). loadRecipientData()
            // derives from the DB only when BOTH recipient keys are absent, so
            // passing just the full name suppressed derivation and left the first
            // name empty — every "Hi {{recipient_first_name}}" came out "Hi there".
            // When we do supply it, supply both halves.
            $hasIdentity = !empty($context['guardian_id']) || !empty($context['athlete_id']);
            if (!$hasIdentity && isset($r['name']) && trim((string) $r['name']) !== '') {
                require_once __DIR__ . '/NameFormatter.php';
                $person = NameFormatter::splitName(trim((string) $r['name']));
                $context['recipient_first_name'] = $person['first'];
                $context['recipient_name'] = trim($person['first'] . ' ' . $person['last']);
            }

            $r['_resolved_body'] = $mergeFieldService->resolveVariables((string) $body, $context);

            if (preg_match_all('/\{\{[a-zA-Z0-9_]+\}\}/', $r['_resolved_body'], $m)) {
                foreach ($m[0] as $tag) {
                    $unresolved[$tag] = true;
                }
            }
        }
        unset($r);

        return [$recipients, array_keys($unresolved)];
    }
}

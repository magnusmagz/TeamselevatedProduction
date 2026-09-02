<?php
/**
 * Kill switches — one predicate, read from Heroku config vars.
 *
 *   if (!te_feature_enabled('TRANSACTIONAL_EMAIL')) { ...skip the send, say so... }
 *
 * Added 2026-09-02 for the roadmap's Phase 2 (sends and dispatchers that used to be
 * demo stubs). A bad template or a send storm is then a config-var flip
 * (`heroku config:set TE_FEATURE_TRANSACTIONAL_EMAIL=off`), not a deploy and not a
 * revert. Reads go through Env::get so local .env works too.
 *
 * Semantics, deliberately narrow:
 *   - Unset  → ON. A switch exists to turn a shipped feature off in an emergency; a
 *              feature must not silently stay dark because nobody set a var.
 *   - 'off' / '0' / 'false' / 'no' (any case) → OFF. Anything else → ON.
 *   - Names are UPPER_SNAKE without the TE_FEATURE_ prefix; the prefix is added here
 *     so a caller cannot read an unrelated var by accident.
 *
 * A caller that skips work because a switch is off must say so in its response or log
 * — `feature_disabled: TRANSACTIONAL_EMAIL` — never report success for a send that
 * did not happen. That is the whole point of Phase 2.
 *
 * FeatureFlagsTest pins these semantics and scans the send paths added under Phase 2
 * for a te_feature_enabled() check.
 */

require_once __DIR__ . '/../config/env.php';

function te_feature_enabled(string $name): bool
{
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
        throw new InvalidArgumentException("feature flag name must be UPPER_SNAKE: $name");
    }
    $raw = Env::get('TE_FEATURE_' . $name, null);
    if ($raw === null || $raw === '') {
        return true;
    }
    return !in_array(strtolower(trim((string)$raw)), ['off', '0', 'false', 'no'], true);
}

/** The response fragment a caller returns when it skipped work because a switch is off. */
function te_feature_disabled_response(string $name): array
{
    return ['success' => false, 'sent' => false, 'feature_disabled' => $name,
            'error' => "This action is switched off (TE_FEATURE_$name)"];
}

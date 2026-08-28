<?php
/**
 * One-time authorisation of the Canva Connect service account.
 *
 *   Step 1:  php scripts/canva-connect.php
 *            → prints a URL. Open it, sign in as the Teams Elevated service user,
 *              approve. The browser lands on the redirect URI and FAILS TO LOAD —
 *              that is expected and fine. Copy the ?code= value out of the address bar.
 *
 *   Step 2:  php scripts/canva-connect.php --code=<the code>
 *
 * The code is short-lived, so do step 2 within a minute or two of step 1.
 *
 * ⚠️ TWO SHELLS? PASS --verifier. ONE SHELL? YOU DO NOT HAVE TO.
 *
 * PKCE requires step 2 to present the same code_verifier that generated step 1's
 * challenge, and that verifier is held in a file in sys_get_temp_dir. Every
 * `heroku run` gets a FRESH one-off dyno with its own empty /tmp, so running the
 * two steps as two `heroku run` commands loses the verifier between them and the
 * code can never be exchanged. So:
 *
 *     heroku run bash -a teamselevated-backend
 *     $ php scripts/canva-connect.php                 # step 1, prints the URL
 *     ... approve in a browser, copy the ?code= ...
 *     $ php scripts/canva-connect.php --code=<code>   # step 2, same dyno
 *
 * The authorization code is short-lived, so do not let the dyno idle out between
 * them. That is the pressure --verifier removes: step 1 prints the verifier, and
 * step 2 can then be its own `heroku run` minutes later, from anywhere.
 *
 *     heroku run --no-tty -a … php scripts/canva-connect.php
 *     heroku run --no-tty -a … php scripts/canva-connect.php --code=X --verifier=Y
 *
 * The verifier is not a credential on its own — it is single-use, meaningless
 * without the matching code, and both are dead the moment the exchange succeeds.
 *
 * Run this against PRODUCTION config, once. The resulting refresh token rotates on
 * every use and lives in canva_integrations; re-running this replaces it, which
 * immediately invalidates the old one. Do not run it "just to check" — that
 * disconnects whatever is currently working.
 *
 * Env required: CANVA_CLIENT_ID, CANVA_CLIENT_SECRET, CANVA_REDIRECT_URI,
 *               MEDICAL_ENCRYPTION_KEY (the token columns are encrypted).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/CanvaClient.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// The verifier must survive between the two invocations. A file in sys_get_temp_dir
// is fine for a once-per-installation manual step; it is deleted on success.
$statePath = sys_get_temp_dir() . '/canva-connect-state.json';

$redirectUri = Env::get('CANVA_REDIRECT_URI', '');
if ($redirectUri === '') {
    fwrite(STDERR, "CANVA_REDIRECT_URI is not set.\n\n"
        . "Register one in the Canva Developer Portal (Integration → Authentication →\n"
        . "Redirect URLs) and set it here. A URL nothing listens on is fine — we only\n"
        . "need the ?code= from the address bar. Suggested:\n\n"
        . "    http://127.0.0.1:8910/canva/callback\n\n"
        . "If Canva rejects a localhost/http URL, register an https URL on the backend\n"
        . "host instead and read the code from the failed request's query string.\n");
    exit(1);
}

$opts = getopt('', ['code::', 'verifier::']);

// ── Step 2: exchange ────────────────────────────────────────────────────────
if (isset($opts['code']) && $opts['code'] !== false) {
    // --verifier lets step 2 run as its OWN `heroku run`, which is the difference
    // between a workable remote flow and a race. Without it both steps must share
    // one dyno's /tmp, so the operator holds an interactive shell open while they
    // browse — and an authorization code is short-lived enough that an idle-out or
    // a slow approval loses it. Passing the verifier explicitly removes the shared
    // state entirely.
    $verifier = null;
    if (isset($opts['verifier']) && $opts['verifier'] !== false && $opts['verifier'] !== '') {
        $verifier = $opts['verifier'];
    } elseif (file_exists($statePath)) {
        $saved = json_decode(file_get_contents($statePath), true);
        $verifier = $saved['verifier'] ?? null;
    }

    if ($verifier === null) {
        fwrite(STDERR, "No code_verifier available.\n\n"
            . "PKCE requires the SAME verifier that generated the challenge, and nothing\n"
            . "is stashed at {$statePath}. Either re-run step 1 in this same shell, or\n"
            . "pass the verifier from wherever step 1 ran:\n\n"
            . "    php scripts/canva-connect.php --code=<code> --verifier=<verifier>\n");
        exit(1);
    }

    $pdo = Database::getInstance()->getConnection();
    $client = new CanvaClient($pdo);

    try {
        $token = $client->completeAuthorization($opts['code'], $verifier, $redirectUri);
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }

    @unlink($statePath);

    echo "Connected.\n";
    echo "  scopes granted : " . ($token['scope'] ?? '(not reported)') . "\n";
    echo "  expires in     : " . ($token['expires_in'] ?? '?') . "s (refresh is automatic)\n\n";

    // Granted scopes can be narrower than requested if the portal integration was
    // configured with fewer. Silently missing one shows up much later as a confusing
    // 403 on a single endpoint, so surface it now.
    $granted = array_filter(explode(' ', (string) ($token['scope'] ?? '')));
    $missing = array_diff(CanvaClient::SCOPES, $granted);
    if ($granted && $missing) {
        echo "WARNING — requested but not granted: " . implode(', ', $missing) . "\n";
        echo "Enable these on the integration in the Canva Developer Portal, then re-run.\n\n";
    }

    echo "Next: php scripts/canva-smoke.php --club=<id>\n";
    exit(0);
}

// ── Step 1: build the authorize URL ─────────────────────────────────────────
$verifier = CanvaClient::generateCodeVerifier();
$state    = bin2hex(random_bytes(16));

file_put_contents($statePath, json_encode(['verifier' => $verifier, 'state' => $state]));
chmod($statePath, 0600);

echo "Open this URL, approve as the Teams Elevated service user:\n\n";
echo CanvaClient::authorizeUrl($state, $verifier, $redirectUri) . "\n\n";
echo "The browser will fail to load the redirect. That is expected.\n";
echo "Copy the ?code= value from the address bar, then run:\n\n";
echo "    php scripts/canva-connect.php --code=<code>\n\n";
echo "If step 2 will run in a DIFFERENT shell or dyno than this one, it cannot read\n";
echo "the stashed verifier and needs it passed explicitly:\n\n";
echo "    code_verifier: {$verifier}\n\n";
echo "    php scripts/canva-connect.php --code=<code> --verifier={$verifier}\n";

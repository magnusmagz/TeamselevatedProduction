<?php
/**
 * Canva Connect OAuth landing page.
 *
 *   GET /api/oauth/canva/callback?code=...&state=...
 *
 * Displays the authorization code so an operator can copy it. It deliberately
 * does NOT complete the exchange.
 *
 * PKCE requires the code_verifier that generated the challenge, and that lives
 * wherever `scripts/canva-connect.php` was run — a one-off dyno with its own
 * /tmp, not this web dyno. There is nothing here to exchange with, so this page
 * hands the code back to the person who started the flow.
 *
 * This exists because the alternative was "read it out of the address bar",
 * which is genuinely hard when the page body is a JSON 404 and the URL is 200
 * characters of percent-encoding. Losing the code means starting over, and the
 * code is short-lived.
 *
 * No auth: the caller is arriving from Canva's redirect and holds no session.
 * That is safe because the code alone is useless — the exchange also needs the
 * client secret and the matching verifier, and the code is single-use and
 * short-lived. Nothing is read from the database and nothing is written.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$code  = isset($_GET['code'])  ? (string) $_GET['code']  : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';
$error = isset($_GET['error']) ? (string) $_GET['error'] : '';
$desc  = isset($_GET['error_description']) ? (string) $_GET['error_description'] : '';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Canva authorisation</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.6 system-ui, -apple-system, sans-serif; margin: 0; padding: 40px 20px;
         display: flex; justify-content: center; }
  main { max-width: 640px; width: 100%; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  p.sub { margin: 0 0 28px; opacity: .7; }
  code { display: block; padding: 14px 16px; border: 1px solid rgba(128,128,128,.4);
         border-radius: 8px; font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace;
         word-break: break-all; background: rgba(128,128,128,.08); }
  button { margin-top: 12px; padding: 10px 18px; font-size: 15px; border-radius: 8px;
           border: 1px solid rgba(128,128,128,.5); background: transparent; cursor: pointer;
           color: inherit; }
  .err { border-left: 4px solid #c0392b; padding-left: 14px; }
  .note { margin-top: 28px; font-size: 14px; opacity: .7; }
</style>
<main>
<?php if ($error !== ''): ?>
  <h1>Canva refused the authorisation</h1>
  <p class="sub">Nothing was connected.</p>
  <div class="err">
    <p><strong><?= h($error) ?></strong></p>
    <?php if ($desc !== ''): ?><p><?= h($desc) ?></p><?php endif; ?>
  </div>
  <p class="note">Start again with <code>scripts/canva-connect.php</code>.</p>
<?php elseif ($code === ''): ?>
  <h1>No authorisation code</h1>
  <p class="sub">This page is the Canva redirect target and was opened without one.</p>
  <p class="note">Nothing to do here. Begin at <code>scripts/canva-connect.php</code>.</p>
<?php else: ?>
  <h1>Approved</h1>
  <p class="sub">Copy this code. It is single-use and expires within minutes.</p>
  <code id="c"><?= h($code) ?></code>
  <button onclick="navigator.clipboard.writeText(document.getElementById('c').textContent).then(()=>{this.textContent='Copied'})">Copy code</button>
  <?php if ($state !== ''): ?>
    <p class="note">state: <?= h($state) ?></p>
  <?php endif; ?>
  <p class="note">
    This page cannot finish the connection itself — PKCE needs the verifier from
    the shell that started the flow. Hand this code back there.
  </p>
<?php endif; ?>
</main>

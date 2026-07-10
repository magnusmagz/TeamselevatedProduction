<?php
// Proves the XSS guard: merge VALUES are HTML-escaped for HTML bodies (so a
// malicious name can't inject script into an email or its preview), but left raw
// for plain-text subjects.
require_once __DIR__ . '/../../services/MergeFieldService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)");
$payload = '<img src=x onerror=alert(document.cookie)>';
$pdo->prepare("INSERT INTO athletes (id, first_name, last_name) VALUES (1, ?, 'X')")->execute([$payload]);

$svc = new MergeFieldService($pdo);
$ctx = ['athlete_id' => 1];

$html  = $svc->resolveVariables('Hi {{athlete_first_name}}!', $ctx, true);   // HTML body
$plain = $svc->resolveVariables('Hi {{athlete_first_name}}!', $ctx, false);  // subject
$def   = $svc->resolveVariables('Hi {{athlete_first_name}}!', $ctx);         // default = plain

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  PASS $label\n"; } else { $fail++; echo "  FAIL $label\n"; } }

check('HTML body has NO raw <img (payload neutralized)', strpos($html, '<img') === false);
check('HTML body has escaped &lt;img',                   strpos($html, '&lt;img') !== false);
check('full payload is escaped to inert text',           strpos($html, '&lt;img src=x onerror=alert(document.cookie)&gt;') !== false);
check('plain subject leaves value raw (<img present)',   strpos($plain, '<img') !== false);
check('default (no flag) = plain, raw',                  strpos($def, '<img') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);

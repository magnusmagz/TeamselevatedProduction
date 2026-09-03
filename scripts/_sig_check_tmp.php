<?php
require __DIR__ . '/../config/database.php';
$pdo = (new Database())->getConnection();
$rows = $pdo->query("SELECT id, email, email_signature_format, length(email_signature) AS len, left(email_signature, 200) AS head FROM users WHERE email_signature IS NOT NULL AND email_signature <> '' AND (email_signature LIKE '%<%' OR email_signature LIKE '%&%') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "rows with markup: " . count($rows) . "\n";
foreach ($rows as $r) { echo "#{$r['id']} {$r['email']} fmt={$r['email_signature_format']} len={$r['len']}\n  " . str_replace("\n", "\\n", $r['head']) . "\n"; }
$n = $pdo->query("SELECT count(*) FROM users WHERE email_signature IS NOT NULL AND email_signature <> ''")->fetchColumn();
echo "total non-empty signatures: $n\n";

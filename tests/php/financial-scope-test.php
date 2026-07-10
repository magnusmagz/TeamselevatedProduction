<?php
// Validates te_club_for() — the id→club resolution behind financial scoping.
require_once __DIR__ . '/../../lib/financial_scope.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    "CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER)",
    "CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER)",
    "CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER)",
    "CREATE TABLE athlete_payments (id INTEGER PRIMARY KEY, athlete_id INTEGER)",
    "CREATE TABLE invoices (id INTEGER PRIMARY KEY, athlete_id INTEGER)",
    "INSERT INTO programs (id, club_id) VALUES (10, 5)",
    "INSERT INTO teams (id, club_id) VALUES (20, 5)",
    "INSERT INTO athletes (id, club_id) VALUES (30, 7)",
    "INSERT INTO athlete_payments (id, athlete_id) VALUES (40, 30)",
    "INSERT INTO invoices (id, athlete_id) VALUES (50, 30)",
] as $sql) { $pdo->exec($sql); }

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  PASS $label\n"; }
    else { $fail++; echo "  FAIL $label (got " . var_export($got, true) . ", want " . var_export($want, true) . ")\n"; }
}

check('league passthrough',          te_club_for($pdo, 'league', 5),       5);
check('club passthrough',            te_club_for($pdo, 'club', 7),         7);
check('program -> club',             te_club_for($pdo, 'program', 10),     5);
check('team -> club',                te_club_for($pdo, 'team', 20),        5);
check('athlete -> club',             te_club_for($pdo, 'athlete', 30),     7);
check('payment -> athlete -> club',  te_club_for($pdo, 'payment', 40),     7);
check('invoice -> athlete -> club',  te_club_for($pdo, 'invoice', 50),     7);
check('unknown program -> null',     te_club_for($pdo, 'program', 99999),  null);
check('null id -> null',             te_club_for($pdo, 'league', null),    null);
check('empty id -> null',            te_club_for($pdo, 'club', ''),        null);
check('unknown type -> null',        te_club_for($pdo, 'bogus', 5),        null);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);

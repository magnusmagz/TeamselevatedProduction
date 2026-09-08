<?php
require __DIR__ . '/../config/database.php';
$pdo = Database::getInstance()->getConnection();
foreach ($pdo->query("SELECT conname, pg_get_constraintdef(oid) d FROM pg_constraint WHERE conrelid='users'::regclass AND contype='c'") as $r) echo $r['conname'], ': ', $r['d'], "\n";

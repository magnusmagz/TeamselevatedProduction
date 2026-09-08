<?php
require __DIR__ . '/../config/database.php';
$pdo = Database::getInstance()->getConnection();
$q = $pdo->query("SELECT e.id, e.name, e.type, e.event_date, e.club_id,
  (SELECT string_agg(gr.role || ':' || r.first_name || ' ' || r.last_name || '(#' || r.id || ')', ', ') FROM game_referees gr JOIN referees r ON r.id = gr.referee_id WHERE gr.calendar_event_id = e.id) AS refs
  FROM calendar_events e WHERE e.name ILIKE '%wild%' OR e.opponent_name ILIKE '%tiger%' ORDER BY e.event_date DESC LIMIT 8");
foreach ($q as $r) echo json_encode($r), "\n";
echo "roles: "; foreach ($pdo->query("SELECT role, count(*) c FROM game_referees GROUP BY role") as $r) echo json_encode($r), ' ';
echo "\n";

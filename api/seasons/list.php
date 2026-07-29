<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Cors.php';
Cors::handle();


require_once '../../config/database.php';
require_once '../../controllers/SeasonController.php';

$controller = new SeasonController();
$controller->index();
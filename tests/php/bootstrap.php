<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads composer autoload (for PHPUnit) plus the lib classes under test.
 * Tests are pure unit tests against an in-memory SQLite PDO — they NEVER touch
 * the production Neon database. No DB credentials are read or required.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/AuthMiddleware.php';
require_once __DIR__ . '/../../lib/AthleteScope.php';
require_once __DIR__ . '/../../services/MergeFieldService.php';
require_once __DIR__ . '/../../services/PaymentService.php';
require_once __DIR__ . '/../../services/AttendanceService.php';

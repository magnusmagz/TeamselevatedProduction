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
require_once __DIR__ . '/../../lib/jersey_size.php';
require_once __DIR__ . '/../../lib/consent_capture.php';
require_once __DIR__ . '/../../lib/parent_invite_token.php';
require_once __DIR__ . '/../../lib/magic_link.php';
require_once __DIR__ . '/../../services/MergeFieldService.php';
require_once __DIR__ . '/../../services/PaymentService.php';
require_once __DIR__ . '/../../services/AttendanceService.php';
require_once __DIR__ . '/../../lib/StripeGateway.php';
require_once __DIR__ . '/../../lib/AuditLog.php';
require_once __DIR__ . '/../../lib/Encryption.php';
require_once __DIR__ . '/../../lib/AuditLogger.php';
require_once __DIR__ . '/../../services/StripeConnectService.php';
require_once __DIR__ . '/../../services/StripeCheckoutService.php';
require_once __DIR__ . '/../../services/ContributionLinkService.php';
require_once __DIR__ . '/../../services/PaymentReportService.php';

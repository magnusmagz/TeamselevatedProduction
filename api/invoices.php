<?php
/**
 * Invoices API
 * Parent-facing invoice management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/financial_scope.php';

try {
    $auth = AuthMiddleware::requireAuth();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $action = $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            // List invoices for athlete, guardian (resolved from JWT), or league
            $athlete_id = $_GET['athlete_id'] ?? null;
            $guardian_id = $_GET['guardian_id'] ?? null;
            $league_id = $_GET['league_id'] ?? null;
            $status = $_GET['status'] ?? null;

            // Scope: a league/athlete-filtered list must be within the caller's clubs.
            if ($league_id !== null || $athlete_id !== null) {
                te_assert_financial_scope($auth, $pdo, ['league' => $league_id, 'athlete' => $athlete_id]);
            }

            $whereClauses = [];
            $params = [];

            if ($athlete_id) {
                $whereClauses[] = 'i.athlete_id = :athlete_id';
                $params['athlete_id'] = $athlete_id;
            } elseif ($guardian_id) {
                // Resolve ALL guardian rows matching the authenticated user's email — shared-household support.
                $userEmail = $auth->getPayload()->email ?? '';
                $guardianStmt = $pdo->prepare("SELECT g.id FROM guardians g WHERE g.email = :email");
                $guardianStmt->execute(['email' => $userEmail]);
                $resolvedGuardianIds = $guardianStmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($resolvedGuardianIds)) {
                    echo json_encode(['success' => true, 'invoices' => [], 'summary' => ['total_invoices' => 0, 'total_outstanding' => 0, 'total_paid' => 0, 'overdue_count' => 0]]);
                    exit;
                }

                $guardianPlaceholders = [];
                foreach ($resolvedGuardianIds as $idx => $gid) {
                    $placeholder = ":guardian_id_{$idx}";
                    $guardianPlaceholders[] = $placeholder;
                    $params["guardian_id_{$idx}"] = $gid;
                }
                $whereClauses[] = 'i.athlete_id IN (
                    SELECT athlete_id FROM athlete_guardians WHERE guardian_id IN (' . implode(',', $guardianPlaceholders) . ')
                )';
            } elseif ($league_id) {
                $whereClauses[] = 'i.league_id = :league_id';
                $params['league_id'] = $league_id;
            } else {
                throw new Exception('athlete_id, guardian_id, or league_id is required');
            }

            if ($status) {
                $whereClauses[] = 'i.status = :status';
                $params['status'] = $status;
            }

            $whereClause = implode(' AND ', $whereClauses);

            $query = "
                SELECT
                    i.id,
                    i.invoice_number,
                    i.athlete_id,
                    i.athlete_payment_id,
                    i.program_id,
                    i.invoice_date,
                    i.due_date,
                    i.subtotal,
                    i.discount_amount,
                    i.total_amount,
                    i.amount_paid,
                    i.status,
                    i.memo,
                    i.sent_at,
                    i.paid_at,
                    i.created_at,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    p.name as program_name,
                    CASE
                        WHEN i.status != 'paid' AND i.due_date < CURRENT_DATE THEN true
                        ELSE false
                    END as is_overdue,
                    (i.total_amount - i.amount_paid) as amount_remaining
                FROM invoices i
                JOIN athletes a ON i.athlete_id = a.id
                LEFT JOIN programs p ON i.program_id = p.id
                WHERE $whereClause
                ORDER BY
                    CASE WHEN i.status = 'overdue' THEN 0
                         WHEN i.status = 'sent' THEN 1
                         WHEN i.status = 'viewed' THEN 2
                         WHEN i.status = 'draft' THEN 3
                         WHEN i.status = 'paid' THEN 4
                         ELSE 5
                    END,
                    i.due_date ASC
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary
            $summary = [
                'total_invoices' => count($invoices),
                'total_outstanding' => 0,
                'total_paid' => 0,
                'overdue_count' => 0
            ];

            foreach ($invoices as $inv) {
                if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled') {
                    $summary['total_outstanding'] += floatval($inv['amount_remaining']);
                }
                $summary['total_paid'] += floatval($inv['amount_paid']);
                if ($inv['is_overdue']) {
                    $summary['overdue_count']++;
                }
            }

            echo json_encode([
                'success' => true,
                'invoices' => $invoices,
                'summary' => $summary
            ]);
            break;

        case 'get':
            // Get single invoice with line items
            $invoice_id = $_GET['id'] ?? null;
            if (!$invoice_id) {
                throw new Exception('Invoice ID is required');
            }

            // Scope: caller must be able to access the invoice's club.
            te_assert_financial_scope($auth, $pdo, ['invoice' => $invoice_id]);

            $query = "
                SELECT
                    i.*,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    p.name as program_name,
                    g.first_name as guardian_first,
                    g.last_name as guardian_last,
                    g.email as guardian_email
                FROM invoices i
                JOIN athletes a ON i.athlete_id = a.id
                LEFT JOIN programs p ON i.program_id = p.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                WHERE i.id = :id
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute(['id' => $invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                throw new Exception('Invoice not found');
            }

            // Get line items
            $itemsQuery = "
                SELECT
                    ii.*,
                    pi.name as item_name,
                    pi.item_type,
                    pi.accounting_code
                FROM invoice_items ii
                LEFT JOIN payment_items pi ON ii.payment_item_id = pi.id
                WHERE ii.invoice_id = :invoice_id
                ORDER BY ii.id
            ";

            $stmt = $pdo->prepare($itemsQuery);
            $stmt->execute(['invoice_id' => $invoice_id]);
            $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'invoice' => $invoice
            ]);
            break;

        case 'create':
            // Create invoice from athlete_payment
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }

            $data = json_decode(file_get_contents('php://input'), true);

            $athlete_payment_id = $data['athlete_payment_id'] ?? null;
            $athlete_id = $data['athlete_id'] ?? null;
            $league_id = $data['league_id'] ?? null;

            // Scope: caller must be able to access the payment/athlete/league's club.
            te_assert_financial_scope($auth, $pdo, ['payment' => $athlete_payment_id, 'athlete' => $athlete_id, 'league' => $league_id]);

            if ($athlete_payment_id) {
                // Create from existing athlete_payment
                $query = "
                    SELECT
                        ap.*,
                        a.id as athlete_id,
                        pi.name as item_name,
                        pi.id as payment_item_id,
                        p.id as program_id,
                        p.name as program_name
                    FROM athlete_payments ap
                    JOIN athletes a ON ap.athlete_id = a.id
                    LEFT JOIN payment_items pi ON ap.payment_item_id = pi.id
                    LEFT JOIN programs p ON ap.program_id = p.id
                    WHERE ap.id = :id
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute(['id' => $athlete_payment_id]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$payment) {
                    throw new Exception('Athlete payment not found');
                }

                $athlete_id = $payment['athlete_id'];
                $league_id = null; // leagues not used
                $subtotal = floatval($payment['base_amount']);
                $discount = floatval($payment['discount_amount']) + floatval($payment['scholarship_amount']);
                $total = floatval($payment['final_amount']);
                $due_date = $payment['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
                $program_id = $payment['program_id'];
            } else {
                // Manual invoice creation
                $subtotal = floatval($data['subtotal'] ?? 0);
                $discount = floatval($data['discount_amount'] ?? 0);
                $total = floatval($data['total_amount'] ?? $subtotal - $discount);
                $due_date = $data['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
                $program_id = $data['program_id'] ?? null;
            }

            // Idempotency guard: the registration-approval PUT already creates a
            // draft invoice for this payment, and RegistrationsModal then ALSO calls
            // this endpoint — without this check that created a DUPLICATE invoice on
            // every approval. If one already exists for the payment, return it.
            if ($athlete_payment_id) {
                $existing = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE athlete_payment_id = :id ORDER BY id LIMIT 1");
                $existing->execute(['id' => $athlete_payment_id]);
                if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
                    echo json_encode([
                        'success' => true,
                        'invoice_id' => $row['id'],
                        'invoice_number' => $row['invoice_number'],
                        'message' => 'Invoice already exists for this payment',
                        'idempotent' => true
                    ]);
                    break;
                }
            }

            // Generate invoice number
            $stmt = $pdo->query("SELECT generate_invoice_number()");
            $invoice_number = $stmt->fetchColumn();

            // Insert invoice
            $insertQuery = "
                INSERT INTO invoices (
                    invoice_number, athlete_id, athlete_payment_id, program_id, league_id,
                    invoice_date, due_date, subtotal, discount_amount, total_amount,
                    status, memo
                ) VALUES (
                    :invoice_number, :athlete_id, :athlete_payment_id, :program_id, :league_id,
                    CURRENT_DATE, :due_date, :subtotal, :discount_amount, :total_amount,
                    'draft', :memo
                )
                RETURNING id
            ";

            $stmt = $pdo->prepare($insertQuery);
            $stmt->execute([
                'invoice_number' => $invoice_number,
                'athlete_id' => $athlete_id,
                'athlete_payment_id' => $athlete_payment_id,
                'program_id' => $program_id,
                'league_id' => $league_id,
                'due_date' => $due_date,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'memo' => $data['memo'] ?? null
            ]);

            $invoice_id = $stmt->fetchColumn();

            // Add line items if provided
            if (!empty($data['items'])) {
                $itemInsert = $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, payment_item_id, description, quantity, unit_price, line_total)
                    VALUES (:invoice_id, :payment_item_id, :description, :quantity, :unit_price, :line_total)
                ");

                foreach ($data['items'] as $item) {
                    $itemInsert->execute([
                        'invoice_id' => $invoice_id,
                        'payment_item_id' => $item['payment_item_id'] ?? null,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['unit_price'],
                        'line_total' => ($item['quantity'] ?? 1) * floatval($item['unit_price'])
                    ]);
                }
            } elseif ($athlete_payment_id && isset($payment)) {
                // Auto-create line item from payment
                // Use item_name if available, otherwise use program name or generic description
                $description = $payment['item_name']
                    ?? ($payment['program_name'] ? $payment['program_name'] . ' Registration' : 'Registration Fee');

                $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, payment_item_id, description, quantity, unit_price, line_total)
                    VALUES (:invoice_id, :payment_item_id, :description, 1, :unit_price, :line_total)
                ")->execute([
                    'invoice_id' => $invoice_id,
                    'payment_item_id' => $payment['payment_item_id'],
                    'description' => $description,
                    'unit_price' => $payment['final_amount'],
                    'line_total' => $payment['final_amount']
                ]);
            }

            echo json_encode([
                'success' => true,
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice_number,
                'message' => 'Invoice created successfully'
            ]);
            break;

        case 'mark-viewed':
            // Mark invoice as viewed (when parent opens it)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }

            $invoice_id = $_GET['id'] ?? null;
            if (!$invoice_id) {
                throw new Exception('Invoice ID is required');
            }

            // Scope: caller must be able to access the invoice's club.
            te_assert_financial_scope($auth, $pdo, ['invoice' => $invoice_id]);

            $stmt = $pdo->prepare("
                UPDATE invoices
                SET status = CASE WHEN status = 'sent' THEN 'viewed' ELSE status END,
                    viewed_at = COALESCE(viewed_at, CURRENT_TIMESTAMP),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute(['id' => $invoice_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Invoice marked as viewed'
            ]);
            break;

        case 'send':
            // Send invoice via email
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }

            $invoice_id = $_GET['id'] ?? null;
            if (!$invoice_id) {
                throw new Exception('Invoice ID is required');
            }

            // Scope: caller must be able to access the invoice's club.
            te_assert_financial_scope($auth, $pdo, ['invoice' => $invoice_id]);

            // Get invoice details
            $stmt = $pdo->prepare("
                SELECT
                    i.*,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    g.first_name as guardian_first,
                    g.email as guardian_email,
                    p.name as program_name
                FROM invoices i
                JOIN athletes a ON i.athlete_id = a.id
                LEFT JOIN programs p ON i.program_id = p.id
                LEFT JOIN athlete_guardians ag ON a.id = ag.athlete_id AND ag.is_primary = true
                LEFT JOIN guardians g ON ag.guardian_id = g.id
                WHERE i.id = :id
            ");
            $stmt->execute(['id' => $invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                throw new Exception('Invoice not found');
            }

            if (!$invoice['guardian_email']) {
                throw new Exception('No guardian email found for this athlete');
            }

            // Update invoice status
            $pdo->prepare("
                UPDATE invoices
                SET status = 'sent', sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute(['id' => $invoice_id]);

            // Log email
            $subject = "Invoice {$invoice['invoice_number']} - {$invoice['athlete_first']} {$invoice['athlete_last']}";
            $pdo->prepare("
                INSERT INTO invoice_emails (invoice_id, email_type, sent_to, subject)
                VALUES (:invoice_id, 'initial', :sent_to, :subject)
            ")->execute([
                'invoice_id' => $invoice_id,
                'sent_to' => $invoice['guardian_email'],
                'subject' => $subject
            ]);

            // In production, send actual email here
            error_log("DEMO: Would send invoice email to {$invoice['guardian_email']}");

            echo json_encode([
                'success' => true,
                'message' => 'Invoice sent successfully',
                'sent_to' => $invoice['guardian_email']
            ]);
            break;

        case 'family':
            // Get all invoices for the authenticated user's family.
            // Resolve ALL guardian rows matching the JWT email — shared-household support.
            $userEmail = $auth->getPayload()->email ?? '';
            $guardianStmt = $pdo->prepare("SELECT g.id FROM guardians g WHERE g.email = :email");
            $guardianStmt->execute(['email' => $userEmail]);
            $resolvedGuardianIds = $guardianStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($resolvedGuardianIds)) {
                echo json_encode([
                    'success' => true,
                    'athletes' => [],
                    'invoices' => [],
                    'summary' => [
                        'total_outstanding' => 0,
                        'total_paid' => 0,
                        'overdue_count' => 0
                    ]
                ]);
                exit;
            }

            // Union athletes across ALL matching guardian rows (DISTINCT so dual-linked athletes show once).
            $guardianPlaceholders = implode(',', array_fill(0, count($resolvedGuardianIds), '?'));
            $athletesQuery = "
                SELECT DISTINCT
                    a.id,
                    a.first_name,
                    a.last_name
                FROM athletes a
                JOIN athlete_guardians ag ON a.id = ag.athlete_id
                WHERE ag.guardian_id IN ($guardianPlaceholders)
                ORDER BY a.first_name
            ";
            $stmt = $pdo->prepare($athletesQuery);
            $stmt->execute($resolvedGuardianIds);
            $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $athlete_ids = array_column($athletes, 'id');

            if (empty($athlete_ids)) {
                echo json_encode([
                    'success' => true,
                    'athletes' => [],
                    'invoices' => [],
                    'summary' => [
                        'total_outstanding' => 0,
                        'total_paid' => 0,
                        'overdue_count' => 0
                    ]
                ]);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($athlete_ids), '?'));

            $invoicesQuery = "
                SELECT
                    i.*,
                    a.first_name as athlete_first,
                    a.last_name as athlete_last,
                    p.name as program_name,
                    CASE WHEN i.status != 'paid' AND i.due_date < CURRENT_DATE THEN true ELSE false END as is_overdue,
                    (i.total_amount - i.amount_paid) as amount_remaining
                FROM invoices i
                JOIN athletes a ON i.athlete_id = a.id
                LEFT JOIN programs p ON i.program_id = p.id
                WHERE i.athlete_id IN ($placeholders)
                ORDER BY i.due_date ASC
            ";

            $stmt = $pdo->prepare($invoicesQuery);
            $stmt->execute($athlete_ids);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate family summary
            $summary = [
                'total_outstanding' => 0,
                'total_paid' => 0,
                'overdue_count' => 0,
                'by_athlete' => []
            ];

            foreach ($invoices as $inv) {
                $aid = $inv['athlete_id'];
                if (!isset($summary['by_athlete'][$aid])) {
                    $summary['by_athlete'][$aid] = [
                        'name' => $inv['athlete_first'] . ' ' . $inv['athlete_last'],
                        'outstanding' => 0,
                        'paid' => 0
                    ];
                }

                if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled') {
                    $remaining = floatval($inv['amount_remaining']);
                    $summary['total_outstanding'] += $remaining;
                    $summary['by_athlete'][$aid]['outstanding'] += $remaining;
                }
                $paid = floatval($inv['amount_paid']);
                $summary['total_paid'] += $paid;
                $summary['by_athlete'][$aid]['paid'] += $paid;

                if ($inv['is_overdue']) {
                    $summary['overdue_count']++;
                }
            }

            echo json_encode([
                'success' => true,
                'athletes' => $athletes,
                'invoices' => $invoices,
                'summary' => $summary
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (PDOException $e) {
    // Never leak SQLSTATE/schema details to clients (e.g. type errors from a
    // malformed id used to surface raw "invalid input syntax for type integer").
    error_log('invoices.php database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load invoice data']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}

<?php
/**
 * Payment Items API
 * Get payment items with support for program, league, and season filtering
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $program_id = $_GET['program_id'] ?? null;
    $league_id = $_GET['league_id'] ?? null;
    $season = $_GET['season'] ?? null;

    // Build query based on filters
    $whereClauses = ['pi.active = true'];
    $params = [];

    if ($program_id) {
        $whereClauses[] = 'pi.program_id = :program_id';
        $params['program_id'] = $program_id;
    } elseif ($league_id) {
        $whereClauses[] = 'p.league_id = :league_id';
        $params['league_id'] = $league_id;
    } else {
        echo json_encode(['error' => 'program_id or league_id is required']);
        exit;
    }

    if ($season) {
        $whereClauses[] = 'p.season_type = :season';
        $params['season'] = $season;
    }

    $whereClause = implode(' AND ', $whereClauses);

    $query = "
        SELECT
            pi.id,
            pi.name,
            pi.description,
            pi.item_type,
            pi.base_price,
            pi.accounting_code,
            pi.is_recurring,
            pi.is_required,
            pi.allow_payment_plan,
            pi.active,
            pi.sibling_discount_enabled,
            pi.sibling_discount_type,
            pi.sibling_discount_value,
            p.name as program_name,
            p.season_type as season
        FROM payment_items pi
        JOIN programs p ON pi.program_id = p.id
        WHERE $whereClause
        ORDER BY
            p.name,
            CASE pi.item_type
                WHEN 'registration' THEN 1
                WHEN 'dues' THEN 2
                WHEN 'uniform' THEN 3
                WHEN 'tournament' THEN 4
                WHEN 'merchandise' THEN 5
                ELSE 6
            END,
            pi.name
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'items' => $items
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch payment items',
        'message' => $e->getMessage()
    ]);
}

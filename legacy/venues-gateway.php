<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Use centralized database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/field_size.php';
$auth = AuthMiddleware::requireAuth();
$accessibleClubIds = $auth->getAccessibleClubIds(); // null = super admin (all clubs)

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path_parts = parse_url($request_uri);
$query_string = $path_parts['query'] ?? '';
parse_str($query_string, $params);

$action = $params['action'] ?? 'list';
$venue_id = $params['id'] ?? null;

try {
    switch ($method) {
        case 'GET':
            if ($venue_id) {
                // Get specific venue with fields
                $stmt = $connection->prepare("
                    SELECT v.*,
                           COUNT(f.id) as field_count
                    FROM venues v
                    LEFT JOIN fields f ON v.id = f.venue_id
                    WHERE v.id = ?
                    GROUP BY v.id, v.name, v.address, v.city, v.state, v.zip_code, v.phone, v.email, v.website, v.notes, v.active, v.created_at, v.updated_at, v.map_url, v.venue_type, v.parking_type, v.parking_paid, v.parking_notes, v.has_lights, v.lights_notes, v.is_accessible, v.accessibility_notes, v.has_bathrooms, v.bathroom_count, v.has_concessions, v.concessions_notes, v.seating_type, v.seating_capacity, v.entry_cost, v.entry_cost_amount, v.payment_methods, v.venue_photos, v.maintenance_contact_name, v.maintenance_contact_phone, v.maintenance_contact_email, v.emergency_contact_name, v.emergency_contact_phone, v.emergency_contact_email, v.billing_contact_name, v.billing_contact_phone, v.billing_contact_email, v.gm_contact_name, v.gm_contact_phone, v.gm_contact_email
                ");
                $stmt->execute([$venue_id]);
                $venue = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($venue && !$auth->canAccessClub((int)($venue['club_id'] ?? 0))) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized for this facility']);
                    break;
                }

                if ($venue) {
                    // Get fields for this venue
                    $stmt = $connection->prepare("
                        SELECT * FROM fields
                        WHERE venue_id = ?
                        ORDER BY name
                    ");
                    $stmt->execute([$venue_id]);
                    $venue['fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode($venue);
            } else {
                // Get all venues — scoped to the caller's clubs (super admins see all).
                $scopeSql = '';
                $scopeParams = [];
                if ($accessibleClubIds !== null) {
                    if (empty($accessibleClubIds)) { echo json_encode([]); break; }
                    // CLUB ids: a handful of values, already in the token. Kept as a
                    // materialised IN list on purpose and allowlisted in
                    // tests/php/NoScopeIdListsTest.php — a subquery here would add a
                    // user_club_access join to save nothing.
                    $scopeSql = 'WHERE v.club_id IN (' . implode(',', array_fill(0, count($accessibleClubIds), '?')) . ')';
                    $scopeParams = $accessibleClubIds;
                }
                $stmt = $connection->prepare("
                    SELECT v.*,
                           COUNT(f.id) as field_count
                    FROM venues v
                    LEFT JOIN fields f ON v.id = f.venue_id
                    $scopeSql
                    GROUP BY v.id, v.name, v.address, v.city, v.state, v.zip_code, v.phone, v.email, v.website, v.notes, v.active, v.created_at, v.updated_at, v.map_url, v.venue_type, v.parking_type, v.parking_paid, v.parking_notes, v.has_lights, v.lights_notes, v.is_accessible, v.accessibility_notes, v.has_bathrooms, v.bathroom_count, v.has_concessions, v.concessions_notes, v.seating_type, v.seating_capacity, v.entry_cost, v.entry_cost_amount, v.payment_methods, v.venue_photos, v.maintenance_contact_name, v.maintenance_contact_phone, v.maintenance_contact_email, v.emergency_contact_name, v.emergency_contact_phone, v.emergency_contact_email, v.billing_contact_name, v.billing_contact_phone, v.billing_contact_email, v.gm_contact_name, v.gm_contact_phone, v.gm_contact_email
                    ORDER BY v.name
                ");
                $stmt->execute($scopeParams);
                $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($venues);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);

            // Owning club: an explicit club the caller can access, or (for a
            // single-club admin) their own. Super admins must name one.
            $requestedClub = $data['club_profile_id'] ?? $data['club_id'] ?? null;
            if ($requestedClub !== null) {
                $venueClubId = (int)$requestedClub;
                if (!$auth->canAccessClub($venueClubId)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not authorized for that club']);
                    break;
                }
            } elseif (is_array($accessibleClubIds) && count($accessibleClubIds) === 1) {
                $venueClubId = (int)$accessibleClubIds[0];
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'club_profile_id is required']);
                break;
            }

            // Begin transaction
            $connection->beginTransaction();

            try {
                // Insert venue. Includes contact roles + microsite-facing
                // extras (notes, gate code, GPS, medical coordinator, public
                // notes) — the frontend was already submitting most of these
                // and the legacy gateway was silently dropping them.
                $stmt = $connection->prepare("
                    INSERT INTO venues (name, address, city, state, zip_code, map_url, website,
                        venue_type, parking_type, parking_paid, parking_notes,
                        has_lights, lights_notes, is_accessible, accessibility_notes,
                        has_bathrooms, bathroom_count, has_concessions, concessions_notes,
                        seating_type, seating_capacity, entry_cost, entry_cost_amount,
                        payment_methods, venue_photos,
                        maintenance_contact_name, maintenance_contact_phone, maintenance_contact_email,
                        emergency_contact_name, emergency_contact_phone, emergency_contact_email,
                        billing_contact_name, billing_contact_phone, billing_contact_email,
                        gm_contact_name, gm_contact_phone, gm_contact_email,
                        notes, gate_code, latitude, longitude,
                        medical_coordinator_name, medical_coordinator_phone, medical_coordinator_email,
                        medical_station_notes, club_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['name'],
                    $data['address'],
                    $data['city'] ?? null,
                    $data['state'] ?? null,
                    $data['zip_code'] ?? $data['zip'] ?? null,
                    $data['map_url'] ?? null,
                    $data['website'] ?? null,
                    $data['venue_type'] ?? null,
                    $data['parking_type'] ?? null,
                    filter_var($data['parking_paid'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['parking_notes'] ?? null,
                    filter_var($data['has_lights'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['lights_notes'] ?? null,
                    filter_var($data['is_accessible'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['accessibility_notes'] ?? null,
                    filter_var($data['has_bathrooms'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['bathroom_count'] ?? null,
                    filter_var($data['has_concessions'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['concessions_notes'] ?? null,
                    $data['seating_type'] ?? null,
                    $data['seating_capacity'] ?? null,
                    $data['entry_cost'] ?? null,
                    $data['entry_cost_amount'] ?? null,
                    $data['payment_methods'] ?? null,
                    json_encode($data['venue_photos'] ?? []),
                    $data['maintenance_contact_name']  ?? null,
                    $data['maintenance_contact_phone'] ?? null,
                    $data['maintenance_contact_email'] ?? null,
                    $data['emergency_contact_name']    ?? null,
                    $data['emergency_contact_phone']   ?? null,
                    $data['emergency_contact_email']   ?? null,
                    $data['billing_contact_name']      ?? null,
                    $data['billing_contact_phone']     ?? null,
                    $data['billing_contact_email']     ?? null,
                    $data['gm_contact_name']           ?? null,
                    $data['gm_contact_phone']          ?? null,
                    $data['gm_contact_email']          ?? null,
                    $data['notes']      ?? null,
                    $data['gate_code']  ?? null,
                    !empty($data['latitude'])  ? $data['latitude']  : null,
                    !empty($data['longitude']) ? $data['longitude'] : null,
                    $data['medical_coordinator_name']  ?? null,
                    $data['medical_coordinator_phone'] ?? null,
                    $data['medical_coordinator_email'] ?? null,
                    $data['medical_station_notes']     ?? null,
                    $venueClubId,
                ]);

                $venue_id = $connection->lastInsertId();

                // Insert fields if provided
                if (!empty($data['fields'])) {
                    // `field_size` (migration 088) is written only when the column
                    // is actually there. `main` is shared and deploys are by push, so
                    // this file can reach production days before the migration runs,
                    // and on Postgres a missing column is 42703 — which would fail the
                    // whole facility save, not just drop the new value.
                    $withSize = te_field_size_available($connection);
                    $field_stmt = $connection->prepare($withSize
                        ? "INSERT INTO fields (venue_id, name, field_type, surface_type, dimensions, has_lights, status, field_size)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        : "INSERT INTO fields (venue_id, name, field_type, surface_type, dimensions, has_lights, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?)");

                    foreach ($data['fields'] as $field) {
                        // Explicitly convert has_lights to boolean for PostgreSQL
                        $hasLights = filter_var($field['has_lights'] ?? $field['lights'] ?? false, FILTER_VALIDATE_BOOLEAN);

                        $values = [
                            $venue_id,
                            $field['name'],
                            $field['field_type'] ?? 'Soccer',
                            $field['surface_type'] ?? $field['surface'] ?? 'Grass',
                            $field['dimensions'] ?? $field['size'] ?? null,
                            $hasLights ? 't' : 'f',  // PostgreSQL boolean format
                            $field['status'] ?? 'available'
                        ];
                        if ($withSize) {
                            // The form submits '' for a field nobody has sized, and
                            // '' violates the CHECK constraint — normalise to NULL.
                            $values[] = te_normalize_field_size($field['field_size'] ?? null);
                        }
                        $field_stmt->execute($values);
                    }
                }

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'id' => $venue_id,
                    'message' => 'Venue created successfully'
                ]);
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            if (!$venue_id) {
                throw new Exception('Venue ID required for update');
            }

            // Scope: caller must own the facility's club.
            $own = $connection->prepare("SELECT club_id FROM venues WHERE id = ?");
            $own->execute([$venue_id]);
            $venueClub = $own->fetchColumn();
            if ($venueClub === false || !$auth->canAccessClub((int)$venueClub)) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized for this facility']);
                break;
            }

            $data = json_decode(file_get_contents("php://input"), true);

            $connection->beginTransaction();

            try {
                // Update venue. Mirrors the POST column set so contacts +
                // microsite-facing extras can be edited after creation.
                $stmt = $connection->prepare("
                    UPDATE venues
                    SET name = ?, address = ?, city = ?, state = ?, zip_code = ?, map_url = ?, website = ?,
                        venue_type = ?, parking_type = ?, parking_paid = ?, parking_notes = ?,
                        has_lights = ?, lights_notes = ?, is_accessible = ?, accessibility_notes = ?,
                        has_bathrooms = ?, bathroom_count = ?, has_concessions = ?, concessions_notes = ?,
                        seating_type = ?, seating_capacity = ?, entry_cost = ?, entry_cost_amount = ?,
                        payment_methods = ?, venue_photos = ?,
                        maintenance_contact_name = ?, maintenance_contact_phone = ?, maintenance_contact_email = ?,
                        emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_email = ?,
                        billing_contact_name = ?, billing_contact_phone = ?, billing_contact_email = ?,
                        gm_contact_name = ?, gm_contact_phone = ?, gm_contact_email = ?,
                        notes = ?, gate_code = ?, latitude = ?, longitude = ?,
                        medical_coordinator_name = ?, medical_coordinator_phone = ?, medical_coordinator_email = ?,
                        medical_station_notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['name'],
                    $data['address'],
                    $data['city'] ?? null,
                    $data['state'] ?? null,
                    $data['zip_code'] ?? $data['zip'] ?? null,
                    $data['map_url'] ?? null,
                    $data['website'] ?? null,
                    $data['venue_type'] ?? null,
                    $data['parking_type'] ?? null,
                    filter_var($data['parking_paid'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['parking_notes'] ?? null,
                    filter_var($data['has_lights'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['lights_notes'] ?? null,
                    filter_var($data['is_accessible'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['accessibility_notes'] ?? null,
                    filter_var($data['has_bathrooms'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['bathroom_count'] ?? null,
                    filter_var($data['has_concessions'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f',
                    $data['concessions_notes'] ?? null,
                    $data['seating_type'] ?? null,
                    $data['seating_capacity'] ?? null,
                    $data['entry_cost'] ?? null,
                    $data['entry_cost_amount'] ?? null,
                    $data['payment_methods'] ?? null,
                    json_encode($data['venue_photos'] ?? []),
                    $data['maintenance_contact_name']  ?? null,
                    $data['maintenance_contact_phone'] ?? null,
                    $data['maintenance_contact_email'] ?? null,
                    $data['emergency_contact_name']    ?? null,
                    $data['emergency_contact_phone']   ?? null,
                    $data['emergency_contact_email']   ?? null,
                    $data['billing_contact_name']      ?? null,
                    $data['billing_contact_phone']     ?? null,
                    $data['billing_contact_email']     ?? null,
                    $data['gm_contact_name']           ?? null,
                    $data['gm_contact_phone']          ?? null,
                    $data['gm_contact_email']          ?? null,
                    $data['notes']      ?? null,
                    $data['gate_code']  ?? null,
                    !empty($data['latitude'])  ? $data['latitude']  : null,
                    !empty($data['longitude']) ? $data['longitude'] : null,
                    $data['medical_coordinator_name']  ?? null,
                    $data['medical_coordinator_phone'] ?? null,
                    $data['medical_coordinator_email'] ?? null,
                    $data['medical_station_notes']     ?? null,
                    $venue_id
                ]);

                // Delete existing fields
                $stmt = $connection->prepare("DELETE FROM fields WHERE venue_id = ?");
                $stmt->execute([$venue_id]);

                // Insert new fields
                if (!empty($data['fields'])) {
                    // `field_size` (migration 088) is written only when the column
                    // is actually there. `main` is shared and deploys are by push, so
                    // this file can reach production days before the migration runs,
                    // and on Postgres a missing column is 42703 — which would fail the
                    // whole facility save, not just drop the new value.
                    $withSize = te_field_size_available($connection);
                    $field_stmt = $connection->prepare($withSize
                        ? "INSERT INTO fields (venue_id, name, field_type, surface_type, dimensions, has_lights, status, field_size)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        : "INSERT INTO fields (venue_id, name, field_type, surface_type, dimensions, has_lights, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?)");

                    foreach ($data['fields'] as $field) {
                        // Explicitly convert has_lights to boolean for PostgreSQL
                        $hasLights = filter_var($field['has_lights'] ?? $field['lights'] ?? false, FILTER_VALIDATE_BOOLEAN);

                        $values = [
                            $venue_id,
                            $field['name'],
                            $field['field_type'] ?? 'Soccer',
                            $field['surface_type'] ?? $field['surface'] ?? 'Grass',
                            $field['dimensions'] ?? $field['size'] ?? null,
                            $hasLights ? 't' : 'f',  // PostgreSQL boolean format
                            $field['status'] ?? 'available'
                        ];
                        if ($withSize) {
                            // The form submits '' for a field nobody has sized, and
                            // '' violates the CHECK constraint — normalise to NULL.
                            $values[] = te_normalize_field_size($field['field_size'] ?? null);
                        }
                        $field_stmt->execute($values);
                    }
                }

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Venue updated successfully'
                ]);
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'DELETE':
            if (!$venue_id) {
                throw new Exception('Venue ID required for deletion');
            }

            // Scope: caller must own the facility's club.
            $own = $connection->prepare("SELECT club_id FROM venues WHERE id = ?");
            $own->execute([$venue_id]);
            $venueClub = $own->fetchColumn();
            if ($venueClub === false || !$auth->canAccessClub((int)$venueClub)) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized for this facility']);
                break;
            }

            $stmt = $connection->prepare("DELETE FROM venues WHERE id = ?");
            $stmt->execute([$venue_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Venue deleted successfully'
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
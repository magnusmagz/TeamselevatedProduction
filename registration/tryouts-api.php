<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once __DIR__ . '/../config/database.php';
try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($connection, $path);
            break;
        case 'POST':
            handlePost($connection, $path);
            break;
        case 'PUT':
            handlePut($connection, $path);
            break;
        case 'DELETE':
            handleDelete($connection, $path);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ============================================
// GET HANDLERS
// ============================================
function handleGet($connection, $path) {
    switch ($path) {
        case 'sessions':
            // Get sessions for a program
            $program_id = $_GET['program_id'] ?? 0;
            $stmt = $connection->prepare("
                SELECT ts.id, ts.program_id, ts.name, ts.session_date, ts.start_time, ts.end_time,
                       ts.location, ts.venue_id, ts.is_rain_date, ts.age_group, ts.gender,
                       ts.notes, ts.created_at, ts.updated_at, v.name as venue_name
                FROM tryout_sessions ts
                LEFT JOIN venues v ON ts.venue_id = v.id
                WHERE ts.program_id = ?
                ORDER BY ts.is_rain_date ASC, ts.session_date, ts.start_time
            ");
            $stmt->execute([$program_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'criteria':
            // Get evaluation criteria for a program
            $program_id = $_GET['program_id'] ?? 0;
            $stmt = $connection->prepare("
                SELECT * FROM tryout_evaluation_criteria
                WHERE program_id = ?
                ORDER BY display_order
            ");
            $stmt->execute([$program_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'registrations':
            // Get tryout registrations with evaluation data
            $program_id = $_GET['program_id'] ?? 0;
            $stmt = $connection->prepare("
                SELECT
                    r.id,
                    r.athlete_id,
                    r.tryout_status,
                    r.tryout_number,
                    r.overall_score,
                    r.ranking,
                    r.assigned_team_id,
                    r.form_data,
                    r.submitted_at,
                    a.first_name,
                    a.last_name,
                    a.date_of_birth,
                    a.gender,
                    t.name as assigned_team_name,
                    (SELECT COUNT(*) FROM tryout_evaluations te WHERE te.registration_id = r.id) as evaluation_count
                FROM registrations r
                LEFT JOIN athletes a ON r.athlete_id = a.id
                LEFT JOIN teams t ON r.assigned_team_id = t.id
                WHERE r.program_id = ?
                AND r.status != 'rejected'
                ORDER BY r.overall_score DESC NULLS LAST, r.submitted_at
            ");
            $stmt->execute([$program_id]);
            $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($registrations as &$reg) {
                $reg['form_data'] = json_decode($reg['form_data'], true);
            }

            echo json_encode($registrations);
            break;

        case 'evaluations':
            // Get evaluations for a registration
            $registration_id = $_GET['registration_id'] ?? 0;
            $stmt = $connection->prepare("
                SELECT
                    te.*,
                    u.first_name as evaluator_first,
                    u.last_name as evaluator_last
                FROM tryout_evaluations te
                JOIN users u ON te.evaluator_id = u.id
                WHERE te.registration_id = ?
                ORDER BY te.created_at
            ");
            $stmt->execute([$registration_id]);
            $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($evaluations as &$eval) {
                $eval['scores'] = json_decode($eval['scores'], true);
            }

            echo json_encode($evaluations);
            break;

        case 'rankings':
            // Get aggregated rankings for a program
            $program_id = $_GET['program_id'] ?? 0;
            $stmt = $connection->prepare("
                SELECT
                    r.id,
                    r.athlete_id,
                    r.tryout_status,
                    r.tryout_number,
                    r.overall_score,
                    r.ranking,
                    a.first_name,
                    a.last_name,
                    a.date_of_birth,
                    COUNT(te.id) as evaluation_count,
                    AVG(te.overall_score) as avg_score,
                    MIN(te.overall_score) as min_score,
                    MAX(te.overall_score) as max_score
                FROM registrations r
                JOIN athletes a ON r.athlete_id = a.id
                LEFT JOIN tryout_evaluations te ON te.registration_id = r.id
                WHERE r.program_id = ?
                AND r.status != 'rejected'
                AND (r.tryout_status IS NULL OR r.tryout_status NOT IN ('not_selected', 'declined'))
                GROUP BY r.id, a.id
                ORDER BY AVG(te.overall_score) DESC NULLS LAST
            ");
            $stmt->execute([$program_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'offers':
            // Get offers for a program
            $program_id = $_GET['program_id'] ?? 0;
            $stmt = $connection->prepare("
                SELECT
                    o.*,
                    r.athlete_id,
                    a.first_name,
                    a.last_name,
                    t.name as team_name
                FROM tryout_offers o
                JOIN registrations r ON o.registration_id = r.id
                JOIN athletes a ON r.athlete_id = a.id
                LEFT JOIN teams t ON o.team_id = t.id
                WHERE r.program_id = ?
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$program_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path']);
    }
}

// ============================================
// POST HANDLERS
// ============================================
function handlePost($connection, $path) {
    $data = json_decode(file_get_contents("php://input"), true);

    switch ($path) {
        case 'create':
            // Create tryout program with sessions and criteria
            $connection->beginTransaction();

            try {
                // Generate unique embed code
                $embed_code = 'TRY' . strtoupper(bin2hex(random_bytes(8)));

                // Create the program
                $stmt = $connection->prepare("
                    INSERT INTO programs (
                        club_id, name, type, description,
                        start_date, end_date, registration_opens, registration_closes,
                        min_age, max_age, capacity, status, embed_code
                    ) VALUES (?, ?, 'tryout', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['club_id'] ?? 1,
                    $data['name'],
                    $data['description'] ?? null,
                    $data['start_date'] ?? null,
                    $data['end_date'] ?? null,
                    $data['registration_opens'] ?? null,
                    $data['registration_closes'] ?? null,
                    $data['min_age'] ?? null,
                    $data['max_age'] ?? null,
                    $data['capacity'] ?? null,
                    $data['status'] ?? 'draft',
                    $embed_code
                ]);
                $program_id = $connection->lastInsertId();

                // Add default form fields for tryout registration
                $default_fields = [
                    ['field_name' => 'athlete_first', 'field_label' => 'Athlete First Name', 'field_type' => 'text', 'required' => true, 'section' => 'athlete_info', 'display_order' => 1],
                    ['field_name' => 'athlete_last', 'field_label' => 'Athlete Last Name', 'field_type' => 'text', 'required' => true, 'section' => 'athlete_info', 'display_order' => 2],
                    ['field_name' => 'athlete_birthday', 'field_label' => 'Date of Birth', 'field_type' => 'date', 'required' => true, 'section' => 'athlete_info', 'display_order' => 3],
                    ['field_name' => 'athlete_gender', 'field_label' => 'Gender', 'field_type' => 'select', 'required' => true, 'section' => 'athlete_info', 'display_order' => 4, 'options' => ['Male', 'Female', 'Non-binary', 'Prefer not to say']],
                    ['field_name' => 'athlete_grade', 'field_label' => 'Grade', 'field_type' => 'select', 'required' => true, 'section' => 'athlete_info', 'display_order' => 5, 'options' => ['Pre-K', 'Kindergarten', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th', '11th', '12th']],
                    ['field_name' => 'guardian_first', 'field_label' => 'Parent/Guardian First Name', 'field_type' => 'text', 'required' => true, 'section' => 'parent_info', 'display_order' => 6],
                    ['field_name' => 'guardian_last', 'field_label' => 'Parent/Guardian Last Name', 'field_type' => 'text', 'required' => true, 'section' => 'parent_info', 'display_order' => 7],
                    ['field_name' => 'guardian_email', 'field_label' => 'Email', 'field_type' => 'email', 'required' => true, 'section' => 'parent_info', 'display_order' => 8],
                    ['field_name' => 'mobile_phone', 'field_label' => 'Phone Number', 'field_type' => 'tel', 'required' => true, 'section' => 'parent_info', 'display_order' => 9]
                ];

                $field_stmt = $connection->prepare("
                    INSERT INTO program_form_fields (
                        program_id, field_name, field_label, field_type, required, options, section, display_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($default_fields as $field) {
                    $field_stmt->execute([
                        $program_id,
                        $field['field_name'],
                        $field['field_label'],
                        $field['field_type'],
                        $field['required'] ? 1 : 0,
                        isset($field['options']) ? json_encode($field['options']) : null,
                        $field['section'],
                        $field['display_order']
                    ]);
                }

                // Add sessions if provided
                if (!empty($data['sessions'])) {
                    $session_stmt = $connection->prepare("
                        INSERT INTO tryout_sessions (program_id, name, session_date, start_time, end_time, location, venue_id, is_rain_date, age_group, gender)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($data['sessions'] as $session) {
                        $session_stmt->execute([
                            $program_id,
                            $session['name'] ?? null,
                            $session['session_date'],
                            $session['start_time'] ?? null,
                            $session['end_time'] ?? null,
                            $session['location'] ?? null,
                            $session['venue_id'] ?? null,
                            $session['is_rain_date'] ?? false,
                            $session['age_group'] ?? null,
                            $session['gender'] ?? null
                        ]);
                    }
                }

                // Add evaluation criteria
                $criteria = $data['criteria'] ?? getDefaultCriteria();
                $criteria_stmt = $connection->prepare("
                    INSERT INTO tryout_evaluation_criteria (program_id, name, description, max_score, weight, display_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($criteria as $index => $criterion) {
                    $criteria_stmt->execute([
                        $program_id,
                        $criterion['name'],
                        $criterion['description'] ?? null,
                        $criterion['max_score'] ?? 5,
                        $criterion['weight'] ?? 1.00,
                        $criterion['display_order'] ?? $index
                    ]);
                }

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'id' => $program_id,
                    'embed_code' => $embed_code,
                    'message' => 'Tryout created successfully'
                ]);

            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'sessions':
            // Add a new session
            $stmt = $connection->prepare("
                INSERT INTO tryout_sessions (program_id, name, session_date, start_time, end_time, location, venue_id, is_rain_date, age_group, gender)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['program_id'],
                $data['name'] ?? null,
                $data['session_date'],
                $data['start_time'] ?? null,
                $data['end_time'] ?? null,
                $data['location'] ?? null,
                $data['venue_id'] ?? null,
                $data['is_rain_date'] ?? false,
                $data['age_group'] ?? null,
                $data['gender'] ?? null
            ]);

            echo json_encode([
                'success' => true,
                'id' => $connection->lastInsertId(),
                'message' => 'Session added successfully'
            ]);
            break;

        case 'criteria':
            // Add or update evaluation criteria
            if (!empty($data['criteria'])) {
                // Bulk update - delete existing and insert new
                $connection->beginTransaction();
                try {
                    $stmt = $connection->prepare("DELETE FROM tryout_evaluation_criteria WHERE program_id = ?");
                    $stmt->execute([$data['program_id']]);

                    $insert_stmt = $connection->prepare("
                        INSERT INTO tryout_evaluation_criteria (program_id, name, description, max_score, weight, display_order)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($data['criteria'] as $index => $criterion) {
                        $insert_stmt->execute([
                            $data['program_id'],
                            $criterion['name'],
                            $criterion['description'] ?? null,
                            $criterion['max_score'] ?? 5,
                            $criterion['weight'] ?? 1.00,
                            $criterion['display_order'] ?? $index
                        ]);
                    }

                    $connection->commit();
                    echo json_encode(['success' => true, 'message' => 'Criteria updated successfully']);
                } catch (Exception $e) {
                    $connection->rollBack();
                    throw $e;
                }
            } else {
                // Single criterion add
                $stmt = $connection->prepare("
                    INSERT INTO tryout_evaluation_criteria (program_id, name, description, max_score, weight, display_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['program_id'],
                    $data['name'],
                    $data['description'] ?? null,
                    $data['max_score'] ?? 5,
                    $data['weight'] ?? 1.00,
                    $data['display_order'] ?? 0
                ]);

                echo json_encode([
                    'success' => true,
                    'id' => $connection->lastInsertId(),
                    'message' => 'Criterion added successfully'
                ]);
            }
            break;

        case 'check-in':
            // Check in athlete at tryout with optional tryout number
            $tryoutNumber = $data['tryout_number'] ?? null;

            $stmt = $connection->prepare("
                UPDATE registrations
                SET tryout_status = 'checked_in',
                    tryout_number = COALESCE(?, tryout_number)
                WHERE id = ? AND (tryout_status IS NULL OR tryout_status = 'registered')
            ");
            $stmt->execute([$tryoutNumber, $data['registration_id']]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Athlete checked in', 'tryout_number' => $tryoutNumber]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Athlete already checked in or not found']);
            }
            break;

        case 'evaluate':
            // Submit coach evaluation
            $connection->beginTransaction();
            try {
                // Get program_id for calculating score
                $stmt = $connection->prepare("SELECT program_id FROM registrations WHERE id = ?");
                $stmt->execute([$data['registration_id']]);
                $program_id = $stmt->fetchColumn();

                // Calculate overall score using criteria weights
                $overall_score = calculateOverallScore($connection, $data['scores'], $program_id);

                // Insert or update evaluation
                $stmt = $connection->prepare("
                    INSERT INTO tryout_evaluations (registration_id, evaluator_id, session_id, scores, overall_score, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT (registration_id, evaluator_id)
                    DO UPDATE SET
                        session_id = EXCLUDED.session_id,
                        scores = EXCLUDED.scores,
                        overall_score = EXCLUDED.overall_score,
                        notes = EXCLUDED.notes,
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    $data['registration_id'],
                    $data['evaluator_id'],
                    $data['session_id'] ?? null,
                    json_encode($data['scores']),
                    $overall_score,
                    $data['notes'] ?? null
                ]);

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'overall_score' => $overall_score,
                    'message' => 'Evaluation submitted successfully'
                ]);
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'send-offers':
            // Batch send offers
            $connection->beginTransaction();
            try {
                $offer_stmt = $connection->prepare("
                    INSERT INTO tryout_offers (registration_id, offer_type, team_id, notes)
                    VALUES (?, ?, ?, ?)
                ");

                $status_stmt = $connection->prepare("
                    UPDATE registrations SET tryout_status = ? WHERE id = ?
                ");

                foreach ($data['offers'] as $offer) {
                    $offer_stmt->execute([
                        $offer['registration_id'],
                        $offer['offer_type'],
                        $offer['team_id'] ?? null,
                        $offer['notes'] ?? null
                    ]);

                    // Update registration status
                    $status = match($offer['offer_type']) {
                        'roster' => 'offered',
                        'waitlist' => 'waitlist',
                        'not_selected' => 'not_selected',
                        default => 'offered'
                    };
                    $status_stmt->execute([$status, $offer['registration_id']]);
                }

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'count' => count($data['offers']),
                    'message' => 'Offers sent successfully'
                ]);
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'update-offer':
            // Update offer response (accepted/declined)
            $connection->beginTransaction();
            try {
                $stmt = $connection->prepare("
                    UPDATE tryout_offers
                    SET response = ?, responded_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$data['response'], $data['offer_id']]);

                // Get registration_id from offer
                $stmt = $connection->prepare("SELECT registration_id FROM tryout_offers WHERE id = ?");
                $stmt->execute([$data['offer_id']]);
                $registration_id = $stmt->fetchColumn();

                // Update registration status
                $status = $data['response'] === 'accepted' ? 'accepted' : 'declined';
                $stmt = $connection->prepare("UPDATE registrations SET tryout_status = ? WHERE id = ?");
                $stmt->execute([$status, $registration_id]);

                $connection->commit();

                echo json_encode(['success' => true, 'message' => 'Offer response recorded']);
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'add-to-roster':
            // Add accepted athlete to team roster
            $connection->beginTransaction();
            try {
                // Get athlete_id from registration
                $stmt = $connection->prepare("SELECT athlete_id FROM registrations WHERE id = ?");
                $stmt->execute([$data['registration_id']]);
                $athlete_id = $stmt->fetchColumn();

                if (!$athlete_id) {
                    throw new Exception('Registration not found');
                }

                // Add to team_members
                $stmt = $connection->prepare("
                    INSERT INTO team_members (team_id, user_id, athlete_id, status, joined_date)
                    VALUES (?, NULL, ?, 'active', CURRENT_DATE)
                    ON CONFLICT DO NOTHING
                ");
                $stmt->execute([$data['team_id'], $athlete_id]);

                // Update registration
                $stmt = $connection->prepare("
                    UPDATE registrations
                    SET tryout_status = 'rostered', assigned_team_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$data['team_id'], $data['registration_id']]);

                $connection->commit();

                echo json_encode(['success' => true, 'message' => 'Athlete added to roster']);
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'update-rankings':
            // Recalculate and update rankings for a program
            $stmt = $connection->prepare("
                WITH ranked AS (
                    SELECT
                        r.id,
                        ROW_NUMBER() OVER (ORDER BY AVG(te.overall_score) DESC NULLS LAST) as rank
                    FROM registrations r
                    LEFT JOIN tryout_evaluations te ON te.registration_id = r.id
                    WHERE r.program_id = ?
                    AND r.status != 'rejected'
                    GROUP BY r.id
                )
                UPDATE registrations r
                SET ranking = ranked.rank
                FROM ranked
                WHERE r.id = ranked.id
            ");
            $stmt->execute([$data['program_id']]);

            echo json_encode(['success' => true, 'message' => 'Rankings updated']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path']);
    }
}

// ============================================
// PUT HANDLERS
// ============================================
function handlePut($connection, $path) {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = $_GET['id'] ?? 0;

    switch ($path) {
        case 'update':
            // Update tryout program with sessions and criteria
            $connection->beginTransaction();

            try {
                // Update the program
                $stmt = $connection->prepare("
                    UPDATE programs SET
                        name = ?,
                        description = ?,
                        start_date = ?,
                        end_date = ?,
                        registration_opens = ?,
                        registration_closes = ?,
                        min_age = ?,
                        max_age = ?,
                        capacity = ?,
                        status = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['name'],
                    $data['description'] ?? null,
                    $data['start_date'] ?? null,
                    $data['end_date'] ?? null,
                    $data['registration_opens'] ?? null,
                    $data['registration_closes'] ?? null,
                    $data['min_age'] ?? null,
                    $data['max_age'] ?? null,
                    $data['capacity'] ?? null,
                    $data['status'] ?? 'published',
                    $id
                ]);

                // Update sessions - delete existing and insert new
                if (isset($data['sessions'])) {
                    $stmt = $connection->prepare("DELETE FROM tryout_sessions WHERE program_id = ?");
                    $stmt->execute([$id]);

                    if (!empty($data['sessions'])) {
                        $session_stmt = $connection->prepare("
                            INSERT INTO tryout_sessions (program_id, name, session_date, start_time, end_time, location, venue_id, is_rain_date, age_group, gender)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        foreach ($data['sessions'] as $session) {
                            $session_stmt->execute([
                                $id,
                                $session['name'] ?? null,
                                $session['session_date'],
                                $session['start_time'] ?? null,
                                $session['end_time'] ?? null,
                                $session['location'] ?? null,
                                $session['venue_id'] ?? null,
                                $session['is_rain_date'] ?? false,
                                $session['age_group'] ?? null,
                                $session['gender'] ?? null
                            ]);
                        }
                    }
                }

                // Update evaluation criteria - delete existing and insert new
                if (isset($data['criteria'])) {
                    $stmt = $connection->prepare("DELETE FROM tryout_evaluation_criteria WHERE program_id = ?");
                    $stmt->execute([$id]);

                    if (!empty($data['criteria'])) {
                        $criteria_stmt = $connection->prepare("
                            INSERT INTO tryout_evaluation_criteria (program_id, name, description, max_score, weight, display_order)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        foreach ($data['criteria'] as $index => $criterion) {
                            $criteria_stmt->execute([
                                $id,
                                $criterion['name'],
                                $criterion['description'] ?? null,
                                $criterion['max_score'] ?? 5,
                                $criterion['weight'] ?? 1.00,
                                $criterion['display_order'] ?? $index
                            ]);
                        }
                    }
                }

                $connection->commit();

                echo json_encode([
                    'success' => true,
                    'id' => $id,
                    'message' => 'Tryout updated successfully'
                ]);

            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }
            break;

        case 'sessions':
            $stmt = $connection->prepare("
                UPDATE tryout_sessions
                SET name = ?, session_date = ?, start_time = ?, end_time = ?, location = ?, venue_id = ?, is_rain_date = ?, age_group = ?, gender = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'] ?? null,
                $data['session_date'],
                $data['start_time'] ?? null,
                $data['end_time'] ?? null,
                $data['location'] ?? null,
                $data['venue_id'] ?? null,
                $data['is_rain_date'] ?? false,
                $data['age_group'] ?? null,
                $data['gender'] ?? null,
                $id
            ]);
            echo json_encode(['success' => true, 'message' => 'Session updated']);
            break;

        case 'criteria':
            $stmt = $connection->prepare("
                UPDATE tryout_evaluation_criteria
                SET name = ?, description = ?, max_score = ?, weight = ?, display_order = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['max_score'] ?? 5,
                $data['weight'] ?? 1.00,
                $data['display_order'] ?? 0,
                $id
            ]);
            echo json_encode(['success' => true, 'message' => 'Criterion updated']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path']);
    }
}

// ============================================
// DELETE HANDLERS
// ============================================
function handleDelete($connection, $path) {
    $id = $_GET['id'] ?? 0;

    switch ($path) {
        case 'sessions':
            $stmt = $connection->prepare("DELETE FROM tryout_sessions WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Session deleted']);
            break;

        case 'criteria':
            $stmt = $connection->prepare("DELETE FROM tryout_evaluation_criteria WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Criterion deleted']);
            break;

        case 'evaluations':
            $stmt = $connection->prepare("DELETE FROM tryout_evaluations WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Evaluation deleted']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path']);
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function getDefaultCriteria() {
    return [
        ['name' => 'Technical Skills', 'description' => 'Ball control, passing, shooting accuracy', 'max_score' => 5, 'weight' => 1.00, 'display_order' => 0],
        ['name' => 'Tactical Awareness', 'description' => 'Game understanding, positioning, decision making', 'max_score' => 5, 'weight' => 1.00, 'display_order' => 1],
        ['name' => 'Physical Fitness', 'description' => 'Speed, endurance, strength, agility', 'max_score' => 5, 'weight' => 1.00, 'display_order' => 2],
        ['name' => 'Attitude/Coachability', 'description' => 'Effort, listening, teamwork, positive attitude', 'max_score' => 5, 'weight' => 1.00, 'display_order' => 3]
    ];
}

function calculateOverallScore($connection, $scores, $program_id) {
    // Get criteria weights
    $stmt = $connection->prepare("
        SELECT id, weight, max_score FROM tryout_evaluation_criteria WHERE program_id = ?
    ");
    $stmt->execute([$program_id]);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $weighted_sum = 0;
    $total_weight = 0;

    foreach ($criteria as $criterion) {
        $criteria_id = $criterion['id'];
        if (isset($scores[$criteria_id])) {
            $score = $scores[$criteria_id];
            $normalized = ($score / $criterion['max_score']) * 100;
            $weighted_sum += $normalized * $criterion['weight'];
            $total_weight += $criterion['weight'];
        }
    }

    if ($total_weight > 0) {
        return round($weighted_sum / $total_weight, 2);
    }

    return null;
}
?>

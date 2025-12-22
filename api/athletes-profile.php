<?php
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';

// Get athlete ID from URL
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathSegments = explode('/', trim($pathInfo, '/'));
$athleteId = isset($pathSegments[0]) ? intval($pathSegments[0]) : 0;

if (!$athleteId) {
    http_response_code(400);
    echo json_encode(['error' => 'Athlete ID is required']);
    exit;
}

try {
    // Fetch athlete basic information
    $athleteQuery = "SELECT * FROM athletes WHERE id = ? AND active_status = 1";
    $stmt = $pdo->prepare($athleteQuery);
    $stmt->execute([$athleteId]);
    $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$athlete) {
        http_response_code(404);
        echo json_encode(['error' => 'Athlete not found']);
        exit;
    }

    // Fetch guardians
    $guardiansQuery = "
        SELECT g.*, ag.relationship_type, ag.is_primary_contact,
               ag.can_authorize_medical, ag.can_pickup, ag.financial_responsible
        FROM guardians g
        INNER JOIN athlete_guardians ag ON g.id = ag.guardian_id
        WHERE ag.athlete_id = ? AND ag.active_status = 1
        ORDER BY ag.is_primary_contact DESC, g.last_name, g.first_name
    ";
    $stmt = $pdo->prepare($guardiansQuery);
    $stmt->execute([$athleteId]);
    $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch team assignments
    $teamsQuery = "
        SELECT tm.*, t.team_name, t.sport, t.age_group, t.gender
        FROM team_members tm
        INNER JOIN teams t ON tm.team_id = t.id
        WHERE tm.athlete_id = ?
        ORDER BY FIELD(tm.team_priority, 'primary', 'secondary', 'guest'), t.team_name
    ";
    $stmt = $pdo->prepare($teamsQuery);
    $stmt->execute([$athleteId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse JSON positions for each team
    foreach ($teams as &$team) {
        $team['positions'] = json_decode($team['positions'] ?: '[]', true);
    }

    // Fetch emergency contacts
    $emergencyQuery = "
        SELECT * FROM emergency_contacts
        WHERE athlete_id = ?
        ORDER BY priority_order
    ";
    $stmt = $pdo->prepare($emergencyQuery);
    $stmt->execute([$athleteId]);
    $emergencyContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch medical record
    $medicalQuery = "SELECT * FROM medical_records WHERE athlete_id = ?";
    $stmt = $pdo->prepare($medicalQuery);
    $stmt->execute([$athleteId]);
    $medicalRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch allergies
    $allergiesQuery = "
        SELECT * FROM allergies
        WHERE athlete_id = ?
        ORDER BY reaction_severity DESC
    ";
    $stmt = $pdo->prepare($allergiesQuery);
    $stmt->execute([$athleteId]);
    $allergies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch medications
    $medicationsQuery = "
        SELECT * FROM medications
        WHERE athlete_id = ?
        ORDER BY is_active DESC, medication_name
    ";
    $stmt = $pdo->prepare($medicationsQuery);
    $stmt->execute([$athleteId]);
    $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch insurance
    $insuranceQuery = "
        SELECT * FROM insurance_policies
        WHERE athlete_id = ?
        ORDER BY is_primary DESC
    ";
    $stmt = $pdo->prepare($insuranceQuery);
    $stmt->execute([$athleteId]);
    $insurance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch documents
    $documentsQuery = "
        SELECT * FROM documents
        WHERE athlete_id = ?
        ORDER BY is_required DESC, document_type, document_name
    ";
    $stmt = $pdo->prepare($documentsQuery);
    $stmt->execute([$athleteId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch sports
    $sportsQuery = "
        SELECT * FROM athlete_sports
        WHERE athlete_id = ?
        ORDER BY sport_type
    ";
    $stmt = $pdo->prepare($sportsQuery);
    $stmt->execute([$athleteId]);
    $sports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse dietary restrictions if stored as JSON
    if ($athlete['dietary_restrictions']) {
        $athlete['dietary_restrictions'] = json_decode($athlete['dietary_restrictions'], true);
    }

    // Prepare response
    $response = [
        'athlete' => $athlete,
        'guardians' => $guardians,
        'teams' => $teams,
        'emergencyContacts' => $emergencyContacts,
        'medicalRecord' => $medicalRecord,
        'allergies' => $allergies,
        'medications' => $medications,
        'insurance' => $insurance,
        'documents' => $documents,
        'sports' => $sports
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Database error in athletes-profile.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
}
<?php
/**
 * Tryouts API — sessions, evaluation criteria, registrations, evaluations,
 * rankings and offers for a tryout program.
 *
 * ⚠️ Until 2026-09-02 this file performed NO authentication of ANY kind. Every
 * ?path= on every method was reachable from the internet with no token:
 *
 *     GET    ?path=registrations&program_id=N   every registrant's name, DOB, gender
 *     GET    ?path=rankings&program_id=N        scores and rankings
 *     POST   ?path=send-offers                  offered/waitlisted/cut an athlete
 *     POST   ?path=add-to-roster                put an athlete on a team
 *     POST   ?path=create                       created a program (club 1 by default)
 *     DELETE ?path=evaluations&id=N             deleted an evaluation by integer id
 *
 * Nothing upstream authenticates for this file — it is reached directly, not
 * through index.php, which has no auth layer either. Same lesson as
 * legacy/guardian-gateway.php and AthleteController: the absence of a UI is not
 * an access control, and a handler's auth is the handler's own to write.
 *
 * Exactly ONE path is public: GET ?path=sessions. The public tryout registration
 * page (frontend/src/modules/registration/pages/PublicTryoutRegistration.tsx:63)
 * renders a program's session list to families who have no account, and that is
 * the only tryouts-api call that page makes. Everything else requires a token
 * AND club staff standing in the club that owns the program being touched.
 *
 * The club is resolved from the DATABASE, from the id in the request — never
 * from the request body. `create` is the one path with no program to resolve
 * from, so it reads club_id from the body and is club-admin only; it used to
 * default to `?? 1`, which silently planted programs in club 1.
 *
 * ⚠️ DEPLOY ORDERING: as of this change, TryoutManagement.tsx, EvaluationModal.tsx
 * and TryoutCreationWizard.tsx send NO Authorization header on any tryouts-api
 * call. Only ProgramScheduleBuilder.tsx does. The frontend must ship those
 * headers BEFORE this file reaches Heroku, or the Tryouts tabs 401.
 */

require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/feature_flags.php';
require_once __DIR__ . '/../lib/tryout_offer_notify.php';
require_once __DIR__ . '/../lib/tryout_coach_invite.php';

// ============================================
// SCOPE HELPERS
//
// Kept above the TE_TRYOUTS_LIB_ONLY guard so a test can load them without a
// database connection, CORS, or the request dispatch below.
// ============================================

/** Refuse with a JSON body and stop. */
function tryout_refuse(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['error' => $error]);
    exit;
}

/**
 * The club that owns a program, or null when the program does not exist.
 *
 * A program that is not there is a 404, never a pass. Returning "no club known"
 * and letting the handler run is how a scope check becomes a no-op for any id
 * a caller cares to invent.
 */
function tryout_programClubId($connection, $programId): ?int
{
    $programId = (int) $programId;
    if ($programId <= 0) {
        return null;
    }
    $stmt = $connection->prepare("SELECT club_id FROM programs WHERE id = ?");
    $stmt->execute([$programId]);
    $clubId = $stmt->fetchColumn();

    return ($clubId === false || $clubId === null) ? null : (int) $clubId;
}

/** registrations.program_id — the anchor for registration/evaluation/offer ids. */
function tryout_programIdForRegistration($connection, $registrationId): ?int
{
    $registrationId = (int) $registrationId;
    if ($registrationId <= 0) {
        return null;
    }
    $stmt = $connection->prepare("SELECT program_id FROM registrations WHERE id = ?");
    $stmt->execute([$registrationId]);
    $programId = $stmt->fetchColumn();

    return ($programId === false || $programId === null) ? null : (int) $programId;
}

/** tryout_sessions.program_id */
function tryout_programIdForSession($connection, $sessionId): ?int
{
    $sessionId = (int) $sessionId;
    if ($sessionId <= 0) {
        return null;
    }
    $stmt = $connection->prepare("SELECT program_id FROM tryout_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $programId = $stmt->fetchColumn();

    return ($programId === false || $programId === null) ? null : (int) $programId;
}

/**
 * tryout_evaluation_criteria.program_id.
 *
 * The table is `tryout_evaluation_criteria`, not `tryout_criteria` — verified
 * against tests/fixtures/production-schema.json. Only the ?path= is `criteria`.
 */
function tryout_programIdForCriterion($connection, $criterionId): ?int
{
    $criterionId = (int) $criterionId;
    if ($criterionId <= 0) {
        return null;
    }
    $stmt = $connection->prepare("SELECT program_id FROM tryout_evaluation_criteria WHERE id = ?");
    $stmt->execute([$criterionId]);
    $programId = $stmt->fetchColumn();

    return ($programId === false || $programId === null) ? null : (int) $programId;
}

/** tryout_evaluations.registration_id -> registrations.program_id */
function tryout_programIdForEvaluation($connection, $evaluationId): ?int
{
    $evaluationId = (int) $evaluationId;
    if ($evaluationId <= 0) {
        return null;
    }
    $stmt = $connection->prepare("SELECT registration_id FROM tryout_evaluations WHERE id = ?");
    $stmt->execute([$evaluationId]);
    $registrationId = $stmt->fetchColumn();

    return ($registrationId === false || $registrationId === null)
        ? null
        : tryout_programIdForRegistration($connection, $registrationId);
}

/** tryout_offers.registration_id -> registrations.program_id */
function tryout_programIdForOffer($connection, $offerId): ?int
{
    $offerId = (int) $offerId;
    if ($offerId <= 0) {
        return null;
    }
    $stmt = $connection->prepare("SELECT registration_id FROM tryout_offers WHERE id = ?");
    $stmt->execute([$offerId]);
    $registrationId = $stmt->fetchColumn();

    return ($registrationId === false || $registrationId === null)
        ? null
        : tryout_programIdForRegistration($connection, $registrationId);
}

/**
 * 'admin' | 'staff' | null — standing in one club, with no output and no exit.
 *
 * `te_is_club_staff` is club admin OR coach (lib/club_standing.php). Never
 * `AuthMiddleware::canAccessClub()`: that is club MEMBERSHIP and a `parent` row
 * satisfies it, which is exactly how handleClubParents handed every guardian in
 * a club to any parent in it.
 */
function tryout_clubStanding($auth, int $clubId): ?string
{
    if (te_is_club_admin($auth, $clubId)) {
        return 'admin';
    }
    if (te_is_club_staff($auth, $clubId)) {
        return 'staff';
    }

    return null;
}

/**
 * Standing in the club that owns $programId, or null for "no program" / "no
 * standing". The testable form: no output, no exit.
 */
function tryout_clubStaffStanding($connection, $auth, $programId): ?string
{
    $clubId = tryout_programClubId($connection, $programId);
    if ($clubId === null) {
        return null;
    }

    return tryout_clubStanding($auth, $clubId);
}

/** Shared body of the two exit-wrappers. Returns the resolved club id. */
function tryout_requireStanding($connection, $auth, $programId, bool $adminOnly): int
{
    $clubId = tryout_programClubId($connection, $programId);
    if ($clubId === null) {
        // Includes the case where an intermediate row (registration, session,
        // criterion, evaluation, offer) did not resolve to a program.
        tryout_refuse(404, 'Program not found');
    }

    $standing = tryout_clubStanding($auth, $clubId);
    if ($standing === null || ($adminOnly && $standing !== 'admin')) {
        tryout_refuse(403, 'You do not have access to this tryout');
    }

    return $clubId;
}

/**
 * Club admin OR coach in the program's club.
 *
 * Every non-public path uses this one. To make a path club-admin only, pass
 * `true` for $adminOnly above — but read the deploy note at the top of this file
 * first: the Tryouts UI gives coaches every button on this API today, so
 * narrowing one is a product decision, not a security fix.
 */
function tryout_requireClubStaff($connection, $auth, $programId): int
{
    return tryout_requireStanding($connection, $auth, $programId, false);
}

/**
 * `create` has no program yet, so the club comes from the body — the one place
 * that is unavoidable, and therefore the one place it must be required rather
 * than defaulted. It used to be `$data['club_id'] ?? 1`.
 */
function tryout_requireClubAdminForClub($auth, $clubId): int
{
    if ($clubId === null || $clubId === '' || (int) $clubId <= 0) {
        tryout_refuse(400, 'club_id is required');
    }
    // Staff, not admin-only: /program-management has no role gate and every coach
    // sees "Create Tryout" today (App.tsx nav, TryoutManagement.tsx). Narrowing to
    // te_is_club_admin is a product decision, not a security fix — one token here.
    if (!te_is_club_staff($auth, (int) $clubId)) {
        tryout_refuse(403, 'You do not have access to this club');
    }

    return (int) $clubId;
}

/**
 * A tryout offer names a team, and the team id is taken from the body. Refuse a
 * team that belongs to a DIFFERENT club than the program.
 *
 * A team whose club_id is NULL passes deliberately: two live teams are in that
 * state, and refusing them would break rostering for reasons unrelated to this
 * change.
 */
function tryout_requireTeamInClub($connection, $teamId, int $clubId): void
{
    $teamId = (int) $teamId;
    if ($teamId <= 0) {
        tryout_refuse(400, 'team_id is required');
    }
    $stmt = $connection->prepare("SELECT club_id FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $teamClubId = $stmt->fetchColumn();

    if ($teamClubId === false) {
        tryout_refuse(404, 'Team not found');
    }
    if ($teamClubId !== null && (int) $teamClubId !== $clubId) {
        tryout_refuse(403, 'That team belongs to a different club');
    }
}

/**
 * Refuse the three coach-invite paths while migration 087 is unapplied.
 *
 * Migrations are applied to Neon by hand and `main` is shared, so this file can
 * reach production days before `tryout_coach_invites` exists. On Postgres a
 * query against a missing table is 42P01 — a 500 that would read as the Tryouts
 * screen being broken. A 503 with a sentence says which feature is not there
 * yet, and only this feature is affected.
 */
function tryout_requireCoachInvitesTable($connection): void
{
    if (!te_tryout_coach_invites_table_present($connection)) {
        tryout_refuse(503, TE_TRYOUT_COACH_INVITE_UNAVAILABLE);
    }
}

// Tests require this file for the scope helpers above. PHP early-binds top-level
// functions, so returning here still defines handleGet/handlePost/handlePut/
// handleDelete while skipping CORS, the headers, the request dispatch and the
// Neon connect. Never defined in production — this must stay above everything
// with a side effect. Same pattern as api/communications-gateway.php:15.
if (defined('TE_TRYOUTS_LIB_ONLY')) {
    return;
}

header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Convert empty strings to null for database fields
function emptyToNull($val) { return (is_string($val) && trim($val) === '') ? null : $val; }

// Database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/jersey_size.php';
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

// The ONE public path. PublicTryoutRegistration.tsx renders a program's session
// list to families who have no account (that page's only tryouts-api call).
// Everything else authenticates here, before dispatch — requireAuth() exits 401
// on its own, so there is no branch below that can forget to check.
$isPublicSessionList = ($method === 'GET' && $path === 'sessions');
$auth = $isPublicSessionList ? null : AuthMiddleware::requireAuth();

try {
    switch ($method) {
        case 'GET':
            handleGet($connection, $path, $auth);
            break;
        case 'POST':
            handlePost($connection, $path, $auth);
            break;
        case 'PUT':
            handlePut($connection, $path, $auth);
            break;
        case 'DELETE':
            handleDelete($connection, $path, $auth);
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
function handleGet($connection, $path, $auth) {
    switch ($path) {
        case 'sessions':
            // PUBLIC — no auth. A family deciding whether to register needs the
            // dates, times and locations, and has no account yet. Session rows
            // carry no personal data. This is the only unauthenticated path in
            // this file; do not add a second one without saying why here.
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
            tryout_requireClubStaff($connection, $auth, $program_id);
            $stmt = $connection->prepare("
                SELECT * FROM tryout_evaluation_criteria
                WHERE program_id = ?
                ORDER BY display_order
            ");
            $stmt->execute([$program_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'registrations':
            // Get tryout registrations with evaluation data.
            // Returns every registrant's name, date of birth and gender, so this
            // was the worst of the open reads.
            $program_id = $_GET['program_id'] ?? 0;
            tryout_requireClubStaff($connection, $auth, $program_id);
            // TODO (Phase 6): CKU report R84 — a coach sees the whole club's
            // tryout list, not just their own age group. The AUTHORIZATION half
            // is fixed here (a coach must at least be staff in the owning club);
            // narrowing a coach to their age group is NOT done, because the age
            // rule is unresolved — frontend/src/utils/ageGroup.ts rolls the
            // season year on Aug 1 while services/AgeEligibilityService.php uses
            // the tournament start_date year. Pick one before filtering on it.
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
            tryout_requireClubStaff($connection, $auth, tryout_programIdForRegistration($connection, $registration_id));
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
            tryout_requireClubStaff($connection, $auth, $program_id);
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
            tryout_requireClubStaff($connection, $auth, $program_id);
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

        case 'coach-invites':
            // The director's view (CKU R86, slice 8.2): every coach's claim on
            // this program's registrants, and what happened next.
            //
            // Staff-gated like every other read here — a coach sees the whole
            // list, which is deliberate: the point of the table is that two
            // coaches wanting the same player is visible to everyone running
            // the tryout, not only to the director.
            $program_id = $_GET['program_id'] ?? 0;
            tryout_requireClubStaff($connection, $auth, $program_id);
            tryout_requireCoachInvitesTable($connection);

            // `rostered` is COMPUTED in this query from tryout_offers and
            // team_members, never read from a column. See lib/tryout_coach_invite.php.
            echo json_encode(te_tryout_coach_invite_list($connection, (int) $program_id));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path']);
    }
}

// ============================================
// POST HANDLERS
// ============================================
function handlePost($connection, $path, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);

    switch ($path) {
        case 'create':
            // Create tryout program with sessions and criteria.
            // No program exists yet, so the club can only come from the body —
            // which is why it must be REQUIRED and admin-gated rather than
            // defaulted. It used to be `$data['club_id'] ?? 1`.
            $club_id = tryout_requireClubAdminForClub($auth, $data['club_id'] ?? null);
            $connection->beginTransaction();

            try {
                // Generate unique embed code
                $embed_code = 'TRY' . strtoupper(bin2hex(random_bytes(8)));

                // Create the program
                $stmt = $connection->prepare("
                    INSERT INTO programs (
                        club_id, name, type, description,
                        start_date, end_date, registration_opens, registration_closes,
                        min_age, max_age, capacity, status, embed_code, what_to_bring
                    ) VALUES (?, ?, 'tryout', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $club_id,
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
                    $embed_code,
                    isset($data['what_to_bring']) ? json_encode($data['what_to_bring']) : null
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
                    ['field_name' => 'mobile_phone', 'field_label' => 'Phone Number', 'field_type' => 'tel', 'required' => true, 'section' => 'parent_info', 'display_order' => 9],
                    // Optional. Collected at tryout time so an athlete who converts
                    // to a roster spot already has a size on file. See the longer
                    // note on the same field in programs-api.php.
                    ['field_name' => 'jersey_size', 'field_label' => 'Jersey Size', 'field_type' => 'select', 'required' => false, 'section' => 'athlete_info', 'display_order' => 10, 'options' => te_jersey_size_options()]
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
                            emptyToNull($session['name'] ?? null),
                            emptyToNull($session['session_date']),
                            emptyToNull($session['start_time'] ?? null),
                            emptyToNull($session['end_time'] ?? null),
                            emptyToNull($session['location'] ?? null),
                            emptyToNull($session['venue_id'] ?? null),
                            !empty($session['is_rain_date']) ? 't' : 'f',
                            emptyToNull($session['age_group'] ?? null),
                            emptyToNull($session['gender'] ?? null)
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
            tryout_requireClubStaff($connection, $auth, $data['program_id'] ?? 0);
            $stmt = $connection->prepare("
                INSERT INTO tryout_sessions (program_id, name, session_date, start_time, end_time, location, venue_id, is_rain_date, age_group, gender)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['program_id'],
                emptyToNull($data['name'] ?? null),
                emptyToNull($data['session_date']),
                emptyToNull($data['start_time'] ?? null),
                emptyToNull($data['end_time'] ?? null),
                emptyToNull($data['location'] ?? null),
                emptyToNull($data['venue_id'] ?? null),
                !empty($data['is_rain_date']) ? 't' : 'f',
                emptyToNull($data['age_group'] ?? null),
                emptyToNull($data['gender'] ?? null)
            ]);

            echo json_encode([
                'success' => true,
                'id' => $connection->lastInsertId(),
                'message' => 'Session added successfully'
            ]);
            break;

        case 'criteria':
            // Add or update evaluation criteria
            tryout_requireClubStaff($connection, $auth, $data['program_id'] ?? 0);
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
            tryout_requireClubStaff($connection, $auth, tryout_programIdForRegistration($connection, $data['registration_id'] ?? 0));
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
            tryout_requireClubStaff($connection, $auth, tryout_programIdForRegistration($connection, $data['registration_id'] ?? 0));
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
            // Batch send offers. Every offer is scoped on its OWN registration —
            // the batch is a list of ids from the client, so checking the first
            // one would let a second id in the same array reach another club.
            foreach (($data['offers'] ?? []) as $offer) {
                tryout_requireClubStaff($connection, $auth, tryout_programIdForRegistration($connection, $offer['registration_id'] ?? 0));
            }
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
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }

            // ── Telling the families ────────────────────────────────────────
            // Until 2026-09-02 this handler answered "Offers sent successfully"
            // with no send of any kind in it: rows were written, statuses were
            // flipped, and nobody was told. Staff believed families had heard.
            //
            // The send runs AFTER commit and OUTSIDE the transaction, on purpose.
            // An email cannot be rolled back, so a transport failure must not
            // undo an offer staff have already made; and an offer that failed to
            // write must never produce a mail. Sequential, never nested.
            $offer_count = count($data['offers'] ?? []);

            if (!te_feature_enabled('TRYOUT_OFFER_EMAIL')) {
                // Never the word "sent" when nothing was sent. The offers are
                // real and recorded; the notification is what is switched off,
                // and the response has to say which.
                echo json_encode([
                    'success' => true,
                    'count' => $offer_count,
                    'notified' => 0,
                    'feature_disabled' => 'TRYOUT_OFFER_EMAIL',
                    'message' => 'Offers recorded; notifications are switched off'
                ]);
            } else {
                // Only OFFERS are emailed. 'not_selected' rows are recorded but the family
                // is not told by automated email — telling a child they did not make the
                // team is a conversation the club owns (decisions doc, item 12).
                $notifiable = array_values(array_filter(
                    $data['offers'] ?? [],
                    fn($o) => ($o['offer_type'] ?? '') !== 'not_selected'
                ));
                $notify = te_tryout_offer_notify_all(
                    $connection,
                    $notifiable,
                    $auth ? $auth->getUserId() : null
                );
                $notifiableCount = count($notifiable);

                echo json_encode([
                    'success' => true,
                    'count' => $offer_count,
                    'notified' => $notify['notified'],
                    'failed' => $notify['failed'],
                    'emails_sent' => $notify['emails_sent'],
                    'sms_queued' => $notify['sms_queued'],
                    'not_notified_not_selected' => $offer_count - $notifiableCount,
                    'message' => empty($notify['failed'])
                        ? "Offers sent to {$notify['notified']} of {$notifiableCount} families"
                        : ("Offers recorded. Notified {$notify['notified']} of {$notifiableCount} families; "
                           . count($notify['failed']) . ' could not be reached.')
                ]);
            }
            break;

        case 'update-offer':
            // Update offer response (accepted/declined)
            tryout_requireClubStaff($connection, $auth, tryout_programIdForOffer($connection, $data['offer_id'] ?? 0));
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
            $roster_club_id = tryout_requireClubStaff($connection, $auth, tryout_programIdForRegistration($connection, $data['registration_id'] ?? 0));
            // team_id is taken from the body, so it needs its own check — staff
            // standing in the program's club says nothing about a team elsewhere.
            tryout_requireTeamInClub($connection, $data['team_id'] ?? 0, $roster_club_id);
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
                    -- team_members has join_date, not joined_date. This threw 42703,
                    -- so accepting a tryout offer never actually added the athlete
                    -- to the team.
                    INSERT INTO team_members (team_id, user_id, athlete_id, status, join_date)
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
            tryout_requireClubStaff($connection, $auth, $data['program_id'] ?? 0);
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

        case 'coach-invite':
            // "Invite to my team" (CKU R86, slice 8.2). A coach claims a
            // registrant; the family is told with the EXISTING team-invitation
            // email carrying registration instructions.
            //
            // Scope first, then the table probe: the club is resolved from the
            // registration, which lives in a table that certainly exists.
            $ci_registration_id = (int) ($data['registration_id'] ?? 0);
            $ci_club_id = tryout_requireClubStaff(
                $connection,
                $auth,
                tryout_programIdForRegistration($connection, $ci_registration_id)
            );
            tryout_requireCoachInvitesTable($connection);

            // team_id comes from the body, so it needs its own check — staff
            // standing in the program's club says nothing about a team
            // elsewhere. It is OPTIONAL here: a coach may want a player before
            // the team they will land on is decided.
            $ci_team_id = ($data['team_id'] ?? null);
            $ci_team_id = ($ci_team_id === null || $ci_team_id === '' || (int) $ci_team_id <= 0)
                ? null
                : (int) $ci_team_id;
            if ($ci_team_id !== null) {
                tryout_requireTeamInClub($connection, $ci_team_id, $ci_club_id);
            }

            // invited_by is the TOKEN's user, never the body. The whole value of
            // this table is attributing a selection to a person, so a claim in
            // the request would make it worthless as a record.
            $ci_user_id = $auth ? (int) $auth->getUserId() : 0;
            if ($ci_user_id <= 0) {
                tryout_refuse(401, 'Sign in again to invite a player');
            }

            // The row is written and committed BEFORE any send. An email cannot
            // be rolled back, so a transport failure must not undo a selection
            // the coach has already made; and a selection that failed to write
            // must never produce a mail.
            $ci_invite = te_tryout_coach_invite_record(
                $connection,
                $ci_registration_id,
                $ci_team_id,
                $ci_user_id
            );

            $ci_response = [
                'invited'         => true,
                'invite_id'       => $ci_invite['id'],
                'already_invited' => !$ci_invite['created'],
                'status'          => $ci_invite['status'],
                'email_sent'      => null,
                'email_sent_at'   => $ci_invite['email_sent_at'],
            ];

            if (!te_feature_enabled('TRYOUT_COACH_INVITE_EMAIL')) {
                // Never the word "sent" when nothing was sent. The claim is real
                // and recorded; the notification is what is switched off, and
                // the response has to say which.
                $ci_response['feature_disabled'] = 'TRYOUT_COACH_INVITE_EMAIL';
                $ci_response['message'] = 'Player invited; the invitation email is switched off';
            } elseif ($ci_invite['email_sent_at'] !== null) {
                // Already mailed on an earlier press. email_sent stays null —
                // nothing was attempted now, and reporting true would claim a
                // send this request did not make.
                $ci_response['message'] = 'Player already invited; the family was emailed earlier';
            } else {
                $ci_ctx = te_tryout_coach_invite_context($connection, $ci_registration_id, $ci_team_id);
                $ci_sent = false;
                if ($ci_ctx !== null) {
                    $ci_sent = te_tryout_coach_invite_send(
                        $connection,
                        $ci_ctx,
                        te_tryout_coach_invite_coach_name($connection, $ci_user_id)
                    );
                }
                if ($ci_sent) {
                    te_tryout_coach_invite_mark_sent($connection, $ci_invite['id']);
                }
                $ci_response['email_sent'] = $ci_sent;
                $ci_response['message'] = $ci_sent
                    ? 'Player invited and the family has been emailed'
                    : 'Player invited. The family could not be emailed — check their contact details.';
            }

            echo json_encode($ci_response);
            break;

        case 'coach-invite-status':
            // Close the loop on a claim: declined (the family said no) or
            // withdrawn (the coach changed their mind).
            //
            // The table probe runs FIRST here, because resolving the program
            // means reading tryout_coach_invites — which is the table that may
            // not exist yet.
            tryout_requireCoachInvitesTable($connection);
            $cis_invite_id = (int) ($data['invite_id'] ?? 0);
            tryout_requireClubStaff(
                $connection,
                $auth,
                te_tryout_coach_invite_program_id($connection, $cis_invite_id)
            );

            $cis_status = (string) ($data['status'] ?? '');
            if ($cis_status === 'registered') {
                // Refused deliberately, with the reason. Whether the athlete has
                // been rostered is computed at read time from tryout_offers and
                // team_members; a stored copy drifts the first time someone is
                // rostered by hand in psql.
                tryout_refuse(400, 'Registration is computed from the roster, not set here');
            }
            if (!te_tryout_coach_invite_set_status($connection, $cis_invite_id, $cis_status)) {
                tryout_refuse(400, 'status must be one of: '
                    . implode(', ', TE_TRYOUT_COACH_INVITE_STATUSES));
            }

            echo json_encode(['success' => true, 'invite_id' => $cis_invite_id, 'status' => $cis_status]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path']);
    }
}

// ============================================
// PUT HANDLERS
// ============================================
function handlePut($connection, $path, $auth) {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = $_GET['id'] ?? 0;

    switch ($path) {
        case 'update':
            // Update tryout program with sessions and criteria.
            // Staff, not admin, on purpose: TryoutCreationWizard's edit mode is
            // reachable by any coach today (see the note at the top of this
            // file), so narrowing it here would be a product change wearing a
            // security fix's clothes. `create` is admin-only because it is the
            // path that chooses a club_id.
            tryout_requireClubStaff($connection, $auth, $id);
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
                        what_to_bring = ?,
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
                    isset($data['what_to_bring']) ? json_encode($data['what_to_bring']) : null,
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
                                emptyToNull($session['name'] ?? null),
                                emptyToNull($session['session_date']),
                                emptyToNull($session['start_time'] ?? null),
                                emptyToNull($session['end_time'] ?? null),
                                emptyToNull($session['location'] ?? null),
                                emptyToNull($session['venue_id'] ?? null),
                                !empty($session['is_rain_date']) ? 't' : 'f',
                                emptyToNull($session['age_group'] ?? null),
                                emptyToNull($session['gender'] ?? null)
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
            tryout_requireClubStaff($connection, $auth, tryout_programIdForSession($connection, $id));
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
                !empty($data['is_rain_date']) ? 't' : 'f',
                $data['age_group'] ?? null,
                $data['gender'] ?? null,
                $id
            ]);
            echo json_encode(['success' => true, 'message' => 'Session updated']);
            break;

        case 'criteria':
            tryout_requireClubStaff($connection, $auth, tryout_programIdForCriterion($connection, $id));
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
function handleDelete($connection, $path, $auth) {
    $id = $_GET['id'] ?? 0;

    switch ($path) {
        case 'sessions':
            tryout_requireClubStaff($connection, $auth, tryout_programIdForSession($connection, $id));
            $stmt = $connection->prepare("DELETE FROM tryout_sessions WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Session deleted']);
            break;

        case 'criteria':
            tryout_requireClubStaff($connection, $auth, tryout_programIdForCriterion($connection, $id));
            $stmt = $connection->prepare("DELETE FROM tryout_evaluation_criteria WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Criterion deleted']);
            break;

        case 'evaluations':
            tryout_requireClubStaff($connection, $auth, tryout_programIdForEvaluation($connection, $id));
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

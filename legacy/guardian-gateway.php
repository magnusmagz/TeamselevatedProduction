<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/db_actor.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Require an authenticated user — this gateway returns and edits guardian PII
// (names, emails, phone numbers), so it must not be reachable without a valid token.
$auth = AuthMiddleware::requireAuth();

// Tell the connection who is acting, so migration 070's athlete_guardians trigger can
// attribute the change. This gateway both creates and DELETES guardian links, which is
// the mutation that went unrecorded on 2026-07-31.
te_db_set_actor($pdo, (int) $auth->getUserId());

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get guardians for an athlete
            $athleteId = isset($_GET['athlete_id']) ? (int)$_GET['athlete_id'] : null;

            if ($athleteId) {
                // Get guardians for specific athlete
                $stmt = $pdo->prepare("
                    SELECT ag.id as relationship_id,
                           ag.relationship as relationship_type,
                           ag.can_pickup,
                           ag.emergency_contact,
                           g.id as guardian_id,
                           g.first_name,
                           g.last_name,
                           g.email,
                           g.mobile_phone,
                           g.work_phone
                    FROM athlete_guardians ag
                    JOIN guardians g ON ag.guardian_id = g.id
                    WHERE ag.athlete_id = ?
                    -- Crew members are equal (2026-09-02): no primary leads the
                    -- list. Link id first so the order is stable across vacuums.
                    ORDER BY ag.id
                ");
                $stmt->execute([$athleteId]);
                $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'guardians' => $guardians]);
            } else {
                // Get all guardians
                $stmt = $pdo->prepare("
                    SELECT g.id,
                           g.first_name,
                           g.last_name,
                           g.email,
                           g.mobile_phone,
                           g.work_phone,
                           COUNT(ag.id) as athlete_count
                    FROM guardians g
                    LEFT JOIN athlete_guardians ag ON g.id = ag.guardian_id
                    GROUP BY g.id
                    ORDER BY g.first_name ASC, g.last_name ASC
                ");
                $stmt->execute();
                $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'guardians' => $guardians]);
            }
            break;

        case 'POST':
            // Add new guardian to athlete
            $input = json_decode(file_get_contents('php://input'), true);
            $athleteId = $input['athlete_id'] ?? null;

            if (!$athleteId) {
                throw new Exception('Athlete ID is required');
            }

            // STAFF ONLY, and this handler had NO scope check at all until
            // 2026-07-30 — it took athlete_id straight from the request body.
            //
            // That was a privilege-escalation chain, not just a tidiness problem:
            // any authenticated user (a parent, a player, a volunteer — anyone
            // with a token) could POST a guardian row carrying their OWN email
            // against any athlete_id in any club. AthleteScope::isGuardianOfAthlete
            // matches guardians on email, so that single request made the caller a
            // guardian of a stranger's child, which in turn satisfies
            // userCanAccessAthlete and unlocks that child's athlete record AND
            // their health data through legacy/medical-gateway.php.
            //
            // Both callers are staff-side (AthleteForm, GuardianManagement), so
            // requiring staff standing costs nothing.
            if (!AthleteScope::staffCanManageAthlete($pdo, $auth, (int) $athleteId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }

            // Required fields
            $first_name = $input['first_name'] ?? null;
            $last_name = $input['last_name'] ?? null;
            $email = $input['email'] ?? null;
            $mobile_phone = $input['mobile_phone'] ?? null;
            $relationship_type = $input['relationship_type'] ?? 'Other';

            if (!$first_name || !$last_name || !$email || !$mobile_phone) {
                throw new Exception('First name, last name, email, and mobile phone are required');
            }

            $pdo->beginTransaction();

            try {
                // Find an existing guardian by the FULL identity (email + first +
                // last), NOT email alone. Households share one email across two
                // people (e.g. John & Jane at thejones@gmail.com); matching on
                // email only would silently link the wrong person and merge two
                // guardians into one. This mirrors api/athletes.php and
                // AthleteController::createOrFindGuardian() so the per-person
                // guardian / shared-email model is preserved.
                // A BLANK email matches nothing. 25 guardians carry email = '' (an
                // empty string, not NULL), and '' = '' is true — so an emailless
                // person could attach to an unrelated one with the same name.
                // Comparison is case- and whitespace-insensitive so "Taylor" and
                // "taylor " resolve to the same person instead of two rows.
                $existingGuardian = false;
                if (trim($email) !== '') {
                    $stmt = $pdo->prepare(
                        "SELECT id FROM guardians
                         WHERE lower(trim(email))      = lower(?)
                           AND lower(trim(first_name)) = lower(?)
                           AND lower(trim(last_name))  = lower(?)"
                    );
                    $stmt->execute([trim($email), trim($first_name), trim($last_name)]);
                    $existingGuardian = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                // Editing an existing crew member: AthleteForm carries the
                // athlete_guardians LINK id, so prefer resolving the guardian from
                // it. Identity matching cannot handle an edit TO the identity —
                // changing a name or an email finds nothing, inserts a second
                // guardian and leaves the old one attached to the athlete.
                $linkId = $input['id'] ?? null;
                if ($linkId) {
                    $linkStmt = $pdo->prepare(
                        "SELECT guardian_id FROM athlete_guardians WHERE id = ? AND athlete_id = ?"
                    );
                    $linkStmt->execute([$linkId, $athleteId]);
                    $linkedGuardianId = $linkStmt->fetchColumn();
                    if ($linkedGuardianId) {
                        $existingGuardian = ['id' => $linkedGuardianId];
                    }
                }

                if ($existingGuardian) {
                    $guardianId = $existingGuardian['id'];

                    // Persist the submitted contact details. Until 2026-07-30 this
                    // branch took the id and moved on, so editing a crew member's
                    // phone, email or name did nothing at all — the request still
                    // returned success, and NOTHING in the codebase issued an
                    // UPDATE against guardians' contact columns. Only keys actually
                    // present are written, so a partial payload cannot blank a
                    // field the caller never sent.
                    $contactFields = [
                        'first_name', 'last_name', 'email', 'mobile_phone', 'work_phone',
                        'home_phone', 'address_line1', 'address_line2', 'city', 'state', 'zip_code',
                    ];
                    $setParts = [];
                    $setValues = [];
                    foreach ($contactFields as $f) {
                        if (!array_key_exists($f, $input)) {
                            continue;
                        }
                        $value = is_string($input[$f]) ? trim($input[$f]) : $input[$f];
                        // mobile_phone is NOT NULL; the required-field check above
                        // already rejects an empty one. Other columns take null.
                        if ($value === '' && $f !== 'mobile_phone') {
                            $value = null;
                        }
                        $setParts[] = "$f = ?";
                        $setValues[] = $value;
                    }
                    if ($setParts) {
                        $setValues[] = $guardianId;
                        $pdo->prepare('UPDATE guardians SET ' . implode(', ', $setParts) . ' WHERE id = ?')
                            ->execute($setValues);
                    }
                } else {
                    // Create new guardian
                    $stmt = $pdo->prepare("
                        INSERT INTO guardians (first_name, last_name, email, mobile_phone, work_phone)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $first_name,
                        $last_name,
                        $email,
                        $mobile_phone,
                        $input['work_phone'] ?? null
                    ]);
                    $guardianId = $pdo->lastInsertId();
                }

                // Link guardian to athlete.
                //
                // `is_primary` is NOT written here, and an `is_primary_contact` in
                // the body is ignored rather than rejected. There is no primary
                // guardian in this product (2026-09-02) — crew members are equal —
                // and a deployed older bundle still posts the key on every athlete
                // save, so a 400 would break saves that are otherwise valid.
                //
                // ⚠️ Omitting the key from the payload is NOT enough on its own:
                // this branch used to coerce a missing key to 'false' and write it,
                // so it always decided the column. The column had to leave the
                // statements, which is what the lines below do.
                // Convert boolean values to proper format for PostgreSQL
                $canPickup = (!isset($input['can_pickup']) || $input['can_pickup'] === true || $input['can_pickup'] === 'true' || $input['can_pickup'] === 1) ? 'true' : 'false';
                $emergencyContact = !empty($input['emergency_contact']) && $input['emergency_contact'] !== 'false' ? 'true' : 'false';

                // Don't create a duplicate link if this guardian is already
                // attached to the athlete; update the relationship instead.
                $existingLink = $pdo->prepare(
                    "SELECT id FROM athlete_guardians WHERE athlete_id = ? AND guardian_id = ?"
                );
                $existingLink->execute([$athleteId, $guardianId]);
                $linkRow = $existingLink->fetch(PDO::FETCH_ASSOC);

                if ($linkRow) {
                    $stmt = $pdo->prepare("
                        UPDATE athlete_guardians
                        SET relationship = ?,
                            can_pickup = ?::boolean, emergency_contact = ?::boolean
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $relationship_type,
                        $canPickup,
                        $emergencyContact,
                        $linkRow['id']
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO athlete_guardians (
                            athlete_id, guardian_id, relationship,
                            can_pickup, emergency_contact
                        ) VALUES (?, ?, ?, ?::boolean, ?::boolean)
                    ");
                    $stmt->execute([
                        $athleteId,
                        $guardianId,
                        $relationship_type,
                        $canPickup,
                        $emergencyContact
                    ]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Guardian added successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            // Update guardian relationship
            $input = json_decode(file_get_contents('php://input'), true);
            $relationshipId = $input['id'] ?? null;

            if (!$relationshipId) {
                throw new Exception('Guardian relationship ID is required');
            }

            // STAFF ONLY. Like the POST above, this had no scope check at all —
            // it trusted a bare athlete_guardians row id, so any authenticated
            // user could walk ids and flip `can_pickup` or `emergency_contact`
            // on any family in any club. `can_pickup` decides who is
            // allowed to collect a child from a session, which makes this a child
            // safety field rather than a preference.
            $ownerStmt = $pdo->prepare("SELECT athlete_id FROM athlete_guardians WHERE id = ?");
            $ownerStmt->execute([$relationshipId]);
            $ownerAthleteId = $ownerStmt->fetchColumn();

            if (!$ownerAthleteId) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Guardian relationship not found']);
                exit;
            }

            if (!AthleteScope::staffCanManageAthlete($pdo, $auth, (int) $ownerAthleteId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }

            $updateFields = [];
            $updateValues = [];

            // [inputField => [dbColumn, isBoolean]]
            //
            // `is_primary_contact` is deliberately absent. Crew members are equal
            // (2026-09-02), so there is no primary to set — and because this loop
            // binds on array_key_exists, dropping the entry is exactly what makes
            // the key ignored rather than rejected: an older bundle can keep
            // sending it and the rest of the update still applies.
            $fieldMapping = [
                'relationship_type'  => ['relationship',       false],
                'can_pickup'         => ['can_pickup',         true],
                'emergency_contact'  => ['emergency_contact',  true],
            ];

            foreach ($fieldMapping as $inputField => [$dbField, $isBoolean]) {
                if (array_key_exists($inputField, $input)) {
                    if ($isBoolean) {
                        $value = $input[$inputField];
                        $coerced = (!empty($value) && $value !== 'false') ? 'true' : 'false';
                        $updateFields[] = "$dbField = ?::boolean";
                        $updateValues[] = $coerced;
                    } else {
                        $updateFields[] = "$dbField = ?";
                        $updateValues[] = $input[$inputField];
                    }
                }
            }

            if (!empty($updateFields)) {
                $pdo->beginTransaction();
                try {
                    $updateValues[] = $relationshipId;
                    $stmt = $pdo->prepare("
                        UPDATE athlete_guardians
                        SET " . implode(', ', $updateFields) . "
                        WHERE id = ?
                    ");
                    $stmt->execute($updateValues);
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }

            echo json_encode(['success' => true, 'message' => 'Guardian relationship updated successfully']);
            break;

        case 'DELETE':
            // Remove guardian relationship
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

            if (!$id) {
                throw new Exception('Guardian relationship ID is required');
            }

            // This endpoint required a valid token but never checked WHOSE athlete
            // the link belonged to, so any signed-in user could unlink any guardian
            // from any athlete in any club by guessing an id. Resolve the link's
            // athlete and apply the same scope rule the athlete endpoints use.
            $ownerStmt = $pdo->prepare("SELECT athlete_id FROM athlete_guardians WHERE id = ?");
            $ownerStmt->execute([$id]);
            $ownerAthleteId = $ownerStmt->fetchColumn();

            if (!$ownerAthleteId) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Guardian relationship not found']);
                exit;
            }

            // STAFF ONLY. This one did check scope, but with the READ predicate,
            // which passes guardians — so either parent in a two-guardian family
            // could delete the OTHER parent's link to their shared child, ending
            // that parent's access to the record with no trace beyond a missing
            // row. Custody disputes are exactly the situation where this endpoint
            // gets found.
            if (!AthleteScope::staffCanManageAthlete($pdo, $auth, (int) $ownerAthleteId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM athlete_guardians WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Guardian relationship removed successfully']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
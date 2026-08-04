<?php
/**
 * Parental consent & right-to-erasure API.
 *
 * COPPA-COMPLIANCE.md documents this file as deployed (Feb 2026) with six
 * actions. It is absent from the tree and from git history — the same loss as
 * lib/Encryption.php and the CORS lockdown. This is a fresh implementation of
 * that contract against the surviving `consent_records` table.
 *
 * Actions:
 *   record            POST  record consent, mint a 48h token, email for confirmation
 *   confirm-email     GET   validate the token from that email, stamp confirmation
 *   status            GET   consent state for an athlete
 *   list              GET   all consents for a guardian
 *   summary           GET   STAFF-ONLY roll-up across the caller's athletes —
 *                           who still owes consent (added 2026-07-31)
 *   revoke            POST  withdraw a consent
 *   request-deletion  POST  right to erasure — purge health data, soft-delete athlete
 *
 * AUTH NOTE — deliberate deviation from the doc.
 * COPPA-COMPLIANCE.md's testing guide shows `status` and `list` being called with
 * no credentials. That would let anyone enumerate which children are registered
 * and what was consented to, so every action here requires a valid token EXCEPT
 * confirm-email, which is authenticated by the single-use token in the emailed
 * link (the recipient is not logged in when they click it).
 */

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/Email.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/consent_capture.php';

const CONSENT_TOKEN_TTL_HOURS = 48;
// Single source of truth, shared with the registration capture path so the two
// cannot drift into recording different versions of the same agreement.
const CONSENT_VERSION = TE_CONSENT_VERSION;

/**
 * consent_records.consent_type carries a CHECK constraint. Validate here so a bad
 * value returns a helpful 400 listing the options, rather than a raw 23514.
 */
const CONSENT_TYPES = ['data_collection', 'medical_data', 'emergency_treatment', 'tos_privacy'];

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/** Thin wrapper; AuditLogger owns the never-throw contract. */
function consentAudit(PDO $pdo, ?int $userId, string $action, string $resourceType, ?int $resourceId, array $details = []): void
{
    AuditLogger::log($pdo, $userId, $action, $resourceType, $resourceId, $details);
}

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/** As fail(), plus a stable code the UI can branch on. */
function failWithReason(int $code, string $message, string $reason): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message, 'reason' => $reason]);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

/**
 * A guardian may act on their own athlete; staff may act within their scope.
 * Anything else is refused — consent is about a specific child.
 */
function requireAthleteAccess(PDO $pdo, AuthMiddleware $auth, int $athleteId): void
{
    if (!AthleteScope::userCanAccessAthlete($pdo, $auth, $athleteId)) {
        fail(403, 'Access denied');
    }
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        case 'record': {
            if ($method !== 'POST') fail(405, 'Method not allowed');
            $auth = AuthMiddleware::requireAuth();
            $d = body();

            $athleteId  = (int) ($d['athlete_id'] ?? 0);
            $guardianId = (int) ($d['guardian_id'] ?? 0);
            $type       = trim((string) ($d['consent_type'] ?? ''));
            $given      = !empty($d['consent_given']);

            if (!$athleteId || !$guardianId || $type === '') {
                fail(400, 'athlete_id, guardian_id and consent_type are required');
            }
            if (!in_array($type, CONSENT_TYPES, true)) {
                fail(400, 'consent_type must be one of: ' . implode(', ', CONSENT_TYPES));
            }
            requireAthleteAccess($pdo, $auth, $athleteId);

            // consent_records.guardian_id is a FOREIGN KEY to users(id) — the
            // consenting adult's ACCOUNT, not a guardians-table row. The two are
            // linked by email (see AthleteScope::isGuardianOfAthlete), which is
            // also how the rest of the app derives the parent role.
            $u = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE id = ?');
            $u->execute([$guardianId]);
            $guardianUser = $u->fetch(PDO::FETCH_ASSOC);
            if (!$guardianUser) {
                fail(422, 'guardian_id must be the user account of the consenting adult');
            }

            // That account must actually be a guardian of this athlete, or consent
            // could be recorded by (or against) an unrelated party.
            if (!AthleteScope::isGuardianOfAthlete($pdo, (string) $guardianUser['email'], $athleteId)) {
                fail(422, 'That user is not a guardian of that athlete');
            }

            $token = bin2hex(random_bytes(32));

            // source='portal' and the frozen identity: this endpoint is only ever
            // called by ConsentGate, and a consent record is evidence of what
            // happened rather than a live relationship — so who agreed is copied
            // in, not left to a join that would later return today's answer.
            // Registration-sourced consent is written by lib/consent_capture.php.
            $stmt = $pdo->prepare(
                'INSERT INTO consent_records
                    (guardian_id, athlete_id, consent_type, consent_given, consented_at,
                     ip_address, user_agent, consent_version, confirmation_token, email_sent_at,
                     source, guardian_email, guardian_name)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, NULL, ?, ?, ?)
                 RETURNING id'
            );
            $stmt->execute([
                $guardianId, $athleteId, $type, $given ? 'true' : 'false',
                $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null,
                CONSENT_VERSION, $token,
                'portal',
                $guardianUser['email'] ?? null,
                trim(($guardianUser['first_name'] ?? '') . ' ' . ($guardianUser['last_name'] ?? '')),
            ]);
            $consentId = (int) $stmt->fetchColumn();

            // Email the guardian to confirm. A send failure must not lose the
            // consent record itself — it is already stored and auditable.
            $emailed = false;
            $guardian = $guardianUser; // users row resolved above

            $a = $pdo->prepare('SELECT first_name, last_name, club_id FROM athletes WHERE id = ?');
            $a->execute([$athleteId]);
            $athlete = $a->fetch(PDO::FETCH_ASSOC);

            if ($guardian && !empty($guardian['email'])) {
                $appUrl = rtrim(getenv('FRONTEND_URL') ?: 'https://teams-elevated.netlify.app', '/');
                $confirmLink = $appUrl . '/consent/confirm?token=' . urlencode($token);
                try {
                    // Send as the club — the parent is confirming consent for their child at a
                    // specific club, so that is the name they should recognise.
                    $mailer = (new Email())->forClub($pdo, $athlete['club_id'] ?? null);
                    $emailed = (bool) $mailer->sendConsentConfirmation(
                        $guardian['email'],
                        trim(($guardian['first_name'] ?? '') . ' ' . ($guardian['last_name'] ?? '')),
                        trim(($athlete['first_name'] ?? '') . ' ' . ($athlete['last_name'] ?? '')),
                        $confirmLink
                    );
                    if ($emailed) {
                        $pdo->prepare('UPDATE consent_records SET email_sent_at = NOW() WHERE id = ?')
                            ->execute([$consentId]);
                    }
                } catch (Exception $e) {
                    error_log('consent confirmation email failed: ' . $e->getMessage());
                }
            }

            consentAudit($pdo, (int) $auth->getUserId(), 'consent_given', 'consent_records', $consentId, [
                'athlete_id' => $athleteId, 'guardian_id' => $guardianId,
                'consent_type' => $type, 'emailed' => $emailed,
            ]);

            echo json_encode([
                'success' => true,
                'consent_id' => $consentId,
                'confirmation_email_sent' => $emailed,
            ]);
            break;
        }

        // ------------------------------------------------------------------
        // Public by necessity: the guardian clicks this from their inbox while
        // logged out. The single-use token IS the credential.
        case 'confirm-email': {
            $token = (string) ($_GET['token'] ?? '');
            if ($token === '') fail(400, 'token is required');

            $stmt = $pdo->prepare(
                'SELECT id, athlete_id, guardian_id, consent_type, email_confirmed_at, revoked_at, consented_at
                 FROM consent_records WHERE confirmation_token = ? LIMIT 1'
            );
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Same response for "no such token" and "expired": a differing reply
            // would let someone probe which tokens exist. `reason` is a stable
            // machine-readable code so the page can show a calm "link expired"
            // state rather than a red system-error one — an expired link is an
            // ordinary event, not a fault.
            if (!$row) failWithReason(400, 'This confirmation link is invalid or has expired', 'invalid_or_expired');
            if (!empty($row['revoked_at'])) fail(410, 'This consent has been withdrawn');

            $ctx = consentDisplayContext($pdo, (int) $row['athlete_id'], (int) $row['guardian_id'])
                 + ['consent_type' => $row['consent_type']];

            if (!empty($row['email_confirmed_at'])) {
                echo json_encode(['success' => true, 'already_confirmed' => true] + $ctx);
                break;
            }

            $age = (time() - strtotime($row['consented_at'])) / 3600;
            if ($age > CONSENT_TOKEN_TTL_HOURS) {
                failWithReason(400, 'This confirmation link is invalid or has expired', 'invalid_or_expired');
            }

            // Clear the token on confirmation so the link is single-use.
            $pdo->prepare('UPDATE consent_records SET email_confirmed_at = NOW(), confirmation_token = NULL WHERE id = ?')
                ->execute([$row['id']]);

            consentAudit($pdo, null, 'consent_email_confirmed', 'consent_records', (int) $row['id'], [
                'athlete_id' => (int) $row['athlete_id'], 'guardian_id' => (int) $row['guardian_id'],
            ]);

            echo json_encode(['success' => true, 'confirmed' => true] + $ctx);
            break;
        }

        // ------------------------------------------------------------------
        case 'status': {
            $auth = AuthMiddleware::requireAuth();
            $athleteId = (int) ($_GET['athlete_id'] ?? 0);
            if (!$athleteId) fail(400, 'athlete_id is required');
            requireAthleteAccess($pdo, $auth, $athleteId);

            $stmt = $pdo->prepare(
                // `source` is part of the contract with ConsentGate: the portal
                // re-affirmation is keyed off it, so dropping it from this SELECT
                // would make the gate prompt families who had already confirmed.
                'SELECT id, guardian_id, consent_type, consent_given, consented_at,
                        email_sent_at, email_confirmed_at, revoked_at, consent_version,
                        source, guardian_email, guardian_name
                 FROM consent_records WHERE athlete_id = ? ORDER BY consented_at DESC'
            );
            $stmt->execute([$athleteId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // "Active" means given, confirmed by email, and not withdrawn.
            $active = array_values(array_filter($rows, fn($r) =>
                !empty($r['consent_given']) && !empty($r['email_confirmed_at']) && empty($r['revoked_at'])
            ));

            echo json_encode([
                'success' => true,
                'athlete_id' => $athleteId,
                'has_active_consent' => count($active) > 0,
                'active_consent_types' => array_values(array_unique(array_column($active, 'consent_type'))),
                'consents' => $rows,
            ]);
            break;
        }

        // ------------------------------------------------------------------
        // STAFF-ONLY roll-up: which children in my scope still owe consent?
        //
        // Scoped with staffManageableAthleteIds, NOT accessibleAthleteIds — a
        // caller who is only a parent gets an empty list rather than a one-row
        // report about their own child, and a coach sees their teams rather than
        // the whole club. `status` and `list` already answer the per-athlete and
        // per-guardian questions; this exists because a club cannot chase
        // outstanding consent one athlete at a time.
        case 'summary': {
            $auth = AuthMiddleware::requireAuth();

            $isSuper = $auth->isSuperAdmin();
            $scopeIds = $isSuper ? [] : AthleteScope::staffManageableAthleteIds($pdo, $auth);

            // Empty scope and "everything" are opposite answers; only a super
            // admin gets the unrestricted branch, and never by falling through.
            if (!$isSuper && empty($scopeIds)) {
                echo json_encode([
                    'success' => true, 'athletes' => [], 'counts' => te_consent_summary_counts([]),
                ]);
                break;
            }

            $where = 'a.active_status = true AND a.deleted_at IS NULL';
            $params = [];
            if (!$isSuper) {
                $ph = implode(',', array_fill(0, count($scopeIds), '?'));
                $where .= " AND a.id IN ($ph)";
                $params = array_values($scopeIds);
            }

            // Optional narrowing to one club, for a super admin or a multi-club admin.
            $clubId = (int) ($_GET['club_id'] ?? 0);
            if ($clubId > 0) {
                $where .= ' AND a.club_id = ?';
                $params[] = $clubId;
            }

            // One row per athlete with their consent rows aggregated, rather than
            // a query per athlete — this view exists to be run over a whole club.
            $stmt = $pdo->prepare(
                "SELECT a.id, a.first_name, a.last_name,
                        COALESCE(
                            json_agg(
                                json_build_object(
                                    'consent_type', cr.consent_type,
                                    'source', cr.source,
                                    'consented_at', cr.consented_at,
                                    'email_confirmed_at', cr.email_confirmed_at,
                                    'guardian_name', cr.guardian_name
                                )
                                ORDER BY cr.consented_at
                            ) FILTER (WHERE cr.id IS NOT NULL),
                            '[]'::json
                        ) AS consents
                 FROM athletes a
                 LEFT JOIN consent_records cr
                        ON cr.athlete_id = a.id
                       AND cr.consent_given = TRUE
                       AND cr.revoked_at IS NULL
                 WHERE $where
                 GROUP BY a.id, a.first_name, a.last_name
                 ORDER BY a.last_name, a.first_name"
            );
            $stmt->execute($params);

            $athletes = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows = json_decode($row['consents'] ?? '[]', true) ?: [];
                $athletes[] = [
                    'athlete_id' => (int) $row['id'],
                    'first_name' => $row['first_name'],
                    'last_name'  => $row['last_name'],
                    'status'     => te_consent_rollup_status($rows),
                    'consents'   => $rows,
                ];
            }

            echo json_encode([
                'success' => true,
                'athletes' => $athletes,
                'counts' => te_consent_summary_counts($athletes),
            ]);
            break;
        }

        // ------------------------------------------------------------------
        case 'list': {
            $auth = AuthMiddleware::requireAuth();
            // guardian_id here is a users(id), matching the column's foreign key.
            $guardianId = (int) ($_GET['guardian_id'] ?? 0);
            if (!$guardianId) fail(400, 'guardian_id is required (the user id of the consenting adult)');

            // Only list consents for athletes the caller may see, so a guardian
            // id cannot be used to enumerate another family's records.
            $stmt = $pdo->prepare(
                'SELECT cr.*, a.first_name AS athlete_first_name, a.last_name AS athlete_last_name
                 FROM consent_records cr
                 JOIN athletes a ON a.id = cr.athlete_id
                 WHERE cr.guardian_id = ? ORDER BY cr.consented_at DESC'
            );
            $stmt->execute([$guardianId]);
            $rows = array_values(array_filter(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                fn($r) => AthleteScope::userCanAccessAthlete($pdo, $auth, (int) $r['athlete_id'])
            ));

            echo json_encode(['success' => true, 'guardian_id' => $guardianId, 'consents' => $rows]);
            break;
        }

        // ------------------------------------------------------------------
        case 'revoke': {
            if ($method !== 'POST') fail(405, 'Method not allowed');
            $auth = AuthMiddleware::requireAuth();
            $d = body();
            $consentId = (int) ($d['consent_id'] ?? 0);
            if (!$consentId) fail(400, 'consent_id is required');

            $stmt = $pdo->prepare('SELECT id, athlete_id, guardian_id, revoked_at FROM consent_records WHERE id = ?');
            $stmt->execute([$consentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) fail(404, 'Consent record not found');

            requireAthleteAccess($pdo, $auth, (int) $row['athlete_id']);

            if (!empty($row['revoked_at'])) {
                echo json_encode(['success' => true, 'already_revoked' => true]);
                break;
            }

            $pdo->prepare('UPDATE consent_records SET revoked_at = NOW(), confirmation_token = NULL WHERE id = ?')
                ->execute([$consentId]);

            consentAudit($pdo, (int) $auth->getUserId(), 'consent_revoked', 'consent_records', $consentId, [
                'athlete_id' => (int) $row['athlete_id'], 'guardian_id' => (int) $row['guardian_id'],
            ]);

            echo json_encode(['success' => true, 'revoked' => true]);
            break;
        }

        // ------------------------------------------------------------------
        // Right to erasure. Destructive and irreversible for health data, so it
        // is transactional, audited, and refuses anyone outside the athlete's scope.
        case 'request-deletion': {
            if ($method !== 'POST') fail(405, 'Method not allowed');
            $auth = AuthMiddleware::requireAuth();
            $d = body();
            $athleteId = (int) ($d['athlete_id'] ?? 0);
            if (!$athleteId) fail(400, 'athlete_id is required');

            // Explicit confirmation, so a stray call cannot erase a child's record.
            if (($d['confirm'] ?? null) !== true) {
                fail(400, 'Set "confirm": true to proceed — this permanently deletes health data');
            }

            requireAthleteAccess($pdo, $auth, $athleteId);

            $deleted = [];
            $pdo->beginTransaction();
            try {
                // insurance_policies from the original spec does not exist in this
                // database; skipped rather than faked.
                foreach (['athlete_medical', 'medical_records', 'medications', 'allergies'] as $table) {
                    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE athlete_id = ?");
                    $stmt->execute([$athleteId]);
                    $deleted[$table] = $stmt->rowCount();
                }

                // Soft delete, per the documented flow: the athlete row is retained
                // so historical rosters and audit references stay coherent.
                $pdo->prepare('UPDATE athletes SET active_status = FALSE, deleted_at = NOW() WHERE id = ?')
                    ->execute([$athleteId]);

                $rev = $pdo->prepare('UPDATE consent_records SET revoked_at = NOW(), confirmation_token = NULL
                                      WHERE athlete_id = ? AND revoked_at IS NULL');
                $rev->execute([$athleteId]);
                $deleted['consents_revoked'] = $rev->rowCount();

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('consent request-deletion failed: ' . $e->getMessage());
                fail(500, 'Deletion failed; nothing was removed');
            }

            consentAudit($pdo, (int) $auth->getUserId(), 'data_deletion', 'athletes', $athleteId, $deleted);

            echo json_encode(['success' => true, 'athlete_id' => $athleteId, 'deleted' => $deleted]);
            break;
        }

        // ------------------------------------------------------------------
        default:
            fail(400, 'Unknown action: ' . $action);
    }
} catch (Exception $e) {
    error_log('consent.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Request failed']);
}

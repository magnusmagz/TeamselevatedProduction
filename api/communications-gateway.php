<?php
/**
 * Communications Gateway API
 *
 * Main API for sending emails/SMS, viewing communication logs,
 * and handling unsubscribes. Supports both authenticated and
 * public (unsubscribe) actions.
 */

// Tests require this file for its recipient-resolution helpers. PHP early-binds
// top-level functions, so returning here still defines them while skipping CORS,
// the headers, the request dispatch, and the Neon connect. Never defined in
// production — this must stay above everything with a side effect.
// Same pattern as api/recipient-search-gateway.php:13.
if (defined('TE_COMMUNICATIONS_LIB_ONLY')) {
    require_once __DIR__ . '/../lib/suppression.php';
    require_once __DIR__ . '/../lib/coach_scope.php';
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/suppression.php';
require_once __DIR__ . '/../lib/coach_scope.php';
require_once __DIR__ . '/../services/EmailSendService.php';
require_once __DIR__ . '/../services/SmsSendService.php';
require_once __DIR__ . '/../services/MergeFieldService.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ──────────────────────────────────────────────
// Public actions (no auth required)
// ──────────────────────────────────────────────
if ($action === 'unsubscribe' && $method === 'GET') {
    handleUnsubscribePage($connection);
    exit();
}

if ($action === 'process-unsubscribe' && $method === 'POST') {
    handleProcessUnsubscribe($connection);
    exit();
}

// ──────────────────────────────────────────────
// All other actions require authentication
// ──────────────────────────────────────────────
try {
    $auth = AuthMiddleware::requireAuth();

    $emailService = new EmailSendService($connection);
    $smsService = new SmsSendService($connection);
    $mergeFieldService = new MergeFieldService($connection);

    switch ($action) {
        // ─── SEND EMAIL ───────────────────────────
        case 'send-email':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handleSendEmail($auth, $connection, $emailService, $mergeFieldService);
            break;

        // ─── SEND SMS ─────────────────────────────
        case 'send-sms':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handleSendSms($auth, $connection, $smsService);
            break;

        // ─── SEND BROADCAST ──────────────────────
        case 'send-broadcast':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handleSendBroadcast($auth, $connection, $emailService, $smsService, $mergeFieldService);
            break;

        // ─── PREVIEW BROADCAST ───────────────────
        case 'preview-broadcast':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handlePreviewBroadcast($auth, $connection);
            break;

        // ─── CONTACT HISTORY ─────────────────────
        case 'contact-history':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handleContactHistory($auth, $connection);
            break;

        // ─── COMMUNICATION LOG ───────────────────
        case 'log':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handleLog($auth, $connection);
            break;

        // ─── LOG DETAIL ──────────────────────────
        case 'log-detail':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handleLogDetail($auth, $connection);
            break;

        // ─── PREVIEW EMAIL ───────────────────────
        case 'preview-email':
            if ($method !== 'POST' && $method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handlePreviewEmail($auth, $connection, $mergeFieldService);
            break;

        // ─── CLUB BRANDING (header/footer markup) ─
        case 'branding':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            handleBranding($auth, $connection);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action: ' . ($action ?? 'none')]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log("Communications Gateway Error: " . $e->getMessage());
}


// ════════════════════════════════════════════════
// ACTION HANDLERS
// ════════════════════════════════════════════════

/**
 * send-email — Queue emails for one or more recipients.
 */
function handleSendEmail($auth, $connection, $emailService, $mergeFieldService) {
    $data = json_decode(file_get_contents('php://input'), true);

    $clubProfileId = $data['club_profile_id'] ?? null;
    $recipients    = $data['recipients'] ?? [];
    $subject       = $data['subject'] ?? null;
    $htmlBody      = $data['html_body'] ?? null;
    $body          = $data['body'] ?? null;
    $templateId    = $data['template_id'] ?? null;
    $eventId       = $data['event_id'] ?? null;
    // Optional: lets {{team_name}} resolve when the caller knows the team (a team
    // group selection). Without it MergeFieldService falls back to the recipient's
    // own roster row.
    $teamId        = $data['team_id'] ?? null;

    // Validate required fields
    if (!$clubProfileId || empty($recipients) || !$subject || !$htmlBody) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id, recipients, subject, and html_body are required']);
        return;
    }

    // Check club access
    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return;
    }

    // Validate recipient scope (coaches can only reach their team)
    if (!validateRecipientScope($auth, $connection, $clubProfileId, $recipients)) {
        http_response_code(403);
        echo json_encode(['error' => 'One or more recipients are outside your permission scope']);
        return;
    }

    // Recipient-name personalization + household combining. {{recipient_first_name}}
    // is the safe, always-resolvable greeting for broad sends. When the content has
    // NO per-person tags ({{athlete_}}/{{guardian_}}), collapse shared-email households
    // to ONE send with the names combined ("John & Jane"); person-specific content
    // stays one-email-per-recipient so nobody's details get merged away.
    require_once __DIR__ . '/../lib/NameFormatter.php';
    $combineHousehold = !preg_match('/\{\{(athlete_|guardian_)/', (string)$subject . (string)$htmlBody . (string)$body);
    if ($combineHousehold) {
        $byEmail = [];
        foreach ($recipients as $r) {
            $email = strtolower(trim((string)($r['email'] ?? '')));
            if ($email === '') continue; // no-email recipients can't be mailed
            if (!isset($byEmail[$email])) { $byEmail[$email] = $r; $byEmail[$email]['_people'] = []; }
            $nm = trim((string)($r['name'] ?? ''));
            if ($nm !== '') $byEmail[$email]['_people'][mb_strtolower($nm)] = NameFormatter::splitName($nm);
        }
        $recipients = [];
        foreach ($byEmail as $r) {
            $people = array_values($r['_people']);
            $r['recipient_first_name'] = NameFormatter::combineFirstNames(array_column($people, 'first'));
            $r['recipient_name']       = NameFormatter::combineFullNames($people);
            if ($r['recipient_name'] !== '') $r['name'] = $r['recipient_name']; // combined To: display
            unset($r['_people']);
            $recipients[] = $r;
        }
    } else {
        foreach ($recipients as &$rr) {
            $person = NameFormatter::splitName(trim((string)($rr['name'] ?? '')));
            $rr['recipient_first_name'] = NameFormatter::combineFirstNames([$person['first']]);
            $rr['recipient_name']       = NameFormatter::combineFullNames([$person]);
        }
        unset($rr);
    }

    // Resolve merge fields per recipient whenever there is anything to resolve.
    // Deliberately NOT gated on $eventId: event-less templates (welcome notes,
    // club announcements) carry {{club_name}} and friends, and used to send with
    // the raw placeholders intact. MergeFieldService only loads a data source the
    // text actually references, so a null event_id costs nothing here.
    if ($templateId || strpos((string)$subject . (string)$htmlBody, '{{') !== false) {
        foreach ($recipients as &$r) {
            $context = [
                'event_id'             => $eventId,
                'team_id'              => $teamId,
                'athlete_id'           => $r['athlete_id'] ?? null,
                'guardian_id'          => ($r['type'] === 'guardian') ? ($r['id'] ?? null) : null,
                'user_id'              => $auth->getUserId(),
                'club_profile_id'      => $clubProfileId,
                'recipient_first_name' => $r['recipient_first_name'] ?? null,
                'recipient_name'       => $r['recipient_name'] ?? null,
            ];
            // Resolve subject and body per recipient
            $r['_resolved_subject']   = $mergeFieldService->resolveVariables($subject, $context);
            $r['_resolved_html_body'] = $mergeFieldService->resolveVariables($htmlBody, $context, true);
            $r['_resolved_body']      = $body ? $mergeFieldService->resolveVariables($body, $context) : null;
        }
        unset($r);

        // Safety guard: no email leaves with an unfilled {{merge_tag}}. If any
        // resolved subject/body still contains a {{word}} placeholder, stop the
        // WHOLE send with a clear message rather than mailing a raw tag to families
        // (an unknown/misspelled field, e.g. a camelCase {{teamName}} the resolver
        // doesn't know). Real values fall back gracefully; this catches authoring
        // mistakes before they reach anyone.
        $unresolved = [];
        foreach ($recipients as $r) {
            $blob = (string)($r['_resolved_subject'] ?? '') . ' '
                  . (string)($r['_resolved_html_body'] ?? '') . ' '
                  . (string)($r['_resolved_body'] ?? '');
            if (preg_match_all('/\{\{[a-zA-Z0-9_]+\}\}/', $blob, $mm)) {
                foreach ($mm[0] as $tag) {
                    // Filled later, by design: the unsubscribe token needs the
                    // communication_log row, so EmailSendService::processHtml()
                    // substitutes it after queueing. Not an authoring mistake.
                    if ($tag === '{{unsubscribe_link}}') continue;
                    $unresolved[$tag] = true;
                }
            }
        }
        unset($r);
        if (!empty($unresolved)) {
            http_response_code(422);
            echo json_encode([
                'error' => 'This email still has unfilled fields: ' . implode(', ', array_keys($unresolved))
                         . '. Remove or fix them before sending.',
                'unresolved_tags' => array_keys($unresolved),
            ]);
            return;
        }

        // Queue individually with resolved content
        $totalQueued  = 0;
        $totalSkipped = 0;
        $allSkippedDetails = [];

        foreach ($recipients as $recipient) {
            $result = $emailService->queueEmail([
                'user_id'         => $auth->getUserId(),
                'club_profile_id' => $clubProfileId,
                'recipients'      => [[
                    'email'      => $recipient['email'],
                    'name'       => $recipient['name'] ?? '',
                    'type'       => $recipient['type'] ?? 'user',
                    'id'         => $recipient['id'] ?? null,
                    'athlete_id' => $recipient['athlete_id'] ?? null,
                ]],
                'subject'       => $recipient['_resolved_subject'],
                'html_body'     => $recipient['_resolved_html_body'],
                'body'          => $recipient['_resolved_body'],
                'template_id'   => $templateId,
                'event_id'      => $eventId,
            ]);
            $totalQueued  += $result['queued'];
            $totalSkipped += $result['skipped'];
            $allSkippedDetails = array_merge($allSkippedDetails, $result['skipped_details']);
        }

        echo json_encode([
            'success' => true,
            'data'    => [
                'queued'          => $totalQueued,
                'skipped'         => $totalSkipped,
                'skipped_details' => $allSkippedDetails,
            ]
        ]);
        return;
    }

    // Standard send (no per-recipient merge needed)
    $result = $emailService->queueEmail([
        'user_id'         => $auth->getUserId(),
        'club_profile_id' => $clubProfileId,
        'recipients'      => $recipients,
        'subject'         => $subject,
        'html_body'       => $htmlBody,
        'body'            => $body,
        'template_id'     => $templateId,
        'event_id'        => $eventId,
    ]);

    echo json_encode([
        'success' => true,
        'data'    => [
            'queued'          => $result['queued'],
            'skipped'         => $result['skipped'],
            'skipped_details' => $result['skipped_details'],
        ]
    ]);
}


/**
 * send-sms — Queue SMS messages for one or more recipients.
 */
function handleSendSms($auth, $connection, $smsService) {
    $data = json_decode(file_get_contents('php://input'), true);

    $clubProfileId = $data['club_profile_id'] ?? null;
    $recipients    = $data['recipients'] ?? [];
    $body          = $data['body'] ?? null;

    if (!$clubProfileId || empty($recipients) || !$body) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id, recipients, and body are required']);
        return;
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return;
    }

    if (!validateRecipientScope($auth, $connection, $clubProfileId, $recipients)) {
        http_response_code(403);
        echo json_encode(['error' => 'One or more recipients are outside your permission scope']);
        return;
    }

    $result = $smsService->queueSms([
        'user_id'         => $auth->getUserId(),
        'club_profile_id' => $clubProfileId,
        'recipients'      => $recipients,
        'body'            => $body,
    ]);

    echo json_encode([
        'success' => true,
        'data'    => [
            'queued'          => $result['queued'],
            'skipped'         => $result['skipped'],
            'skipped_details' => $result['skipped_details'],
        ]
    ]);
}


/**
 * send-broadcast — Bulk send to teams by channel.
 */
function handleSendBroadcast($auth, $connection, $emailService, $smsService, $mergeFieldService) {
    $data = json_decode(file_get_contents('php://input'), true);

    $clubProfileId  = $data['club_profile_id'] ?? null;
    $channel        = $data['channel'] ?? 'email';
    $teamIds        = $data['team_ids'] ?? [];
    $recipientTypes = $data['recipient_types'] ?? ['athlete', 'guardian'];
    $excludeIds     = $data['exclude_ids'] ?? [];
    $subject        = $data['subject'] ?? null;
    $htmlBody       = $data['html_body'] ?? null;
    $body           = $data['body'] ?? null;
    $templateId     = $data['template_id'] ?? null;
    $eventId        = $data['event_id'] ?? null;
    $scheduledAt    = $data['scheduled_at'] ?? null;
    $scope          = $data['scope'] ?? 'teams';

    if (!in_array($scope, ['teams', 'club'], true)) {
        http_response_code(400);
        echo json_encode(['error' => "scope must be 'teams' or 'club'"]);
        return;
    }

    $isClubWide = ($scope === 'club');

    if (!$clubProfileId || !$body || (!$isClubWide && empty($teamIds))) {
        http_response_code(400);
        echo json_encode(['error' => $isClubWide
            ? 'club_profile_id and body are required'
            : 'club_profile_id, team_ids, and body are required']);
        return;
    }

    if ($isClubWide) {
        $teamIds = [];
    }

    if ($channel === 'email' && (!$subject || !$htmlBody)) {
        http_response_code(400);
        echo json_encode(['error' => 'subject and html_body are required for email broadcasts']);
        return;
    }

    // INTERIM (silent-failure fix): no worker dispatches due 'scheduled' campaigns
    // yet, so a scheduled broadcast would be stored and silently never sent. Reject
    // scheduling with a clear message until the dispatcher ships, rather than
    // accepting input that never happens. Remove this guard when the dispatcher lands.
    if (!empty($scheduledAt)) {
        http_response_code(400);
        echo json_encode(['error' => 'Scheduled sending is not available yet — please send now.']);
        return;
    }

    $authError = broadcastAuthError($auth, $connection, $clubProfileId, $isClubWide, $teamIds);
    if ($authError !== null) {
        http_response_code($authError['code']);
        echo json_encode(['error' => $authError['error']]);
        return;
    }

    // Create broadcast_campaigns record
    $stmt = $connection->prepare("
        INSERT INTO broadcast_campaigns
            (club_profile_id, user_id, template_id, name, subject, channel, recipient_criteria, status, scheduled_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, CURRENT_TIMESTAMP)
        RETURNING id
    ");
    $campaignName = ($channel === 'email' ? $subject : substr($body, 0, 80));
    $criteria = json_encode([
        'scope'           => $scope,
        'team_ids'        => $teamIds,
        'recipient_types' => $recipientTypes,
        'exclude_ids'     => $excludeIds,
    ]);
    $status = $scheduledAt ? 'scheduled' : 'sending';

    $stmt->execute([
        $clubProfileId,
        $auth->getUserId(),
        $templateId,
        $campaignName,
        $subject ?? '',
        $channel,
        $criteria,
        $status,
        $scheduledAt,
    ]);
    $campaignId = $stmt->fetchColumn();

    // If scheduled for later, return the campaign record
    if ($scheduledAt) {
        echo json_encode([
            'success' => true,
            'data'    => [
                'campaign_id' => $campaignId,
                'status'      => 'scheduled',
                'scheduled_at' => $scheduledAt,
            ]
        ]);
        return;
    }

    // Resolve all recipients
    $recipients = resolveBroadcastRecipients(
        $connection, $teamIds, $recipientTypes, $excludeIds, $channel, $scope, $clubProfileId
    );

    // Update campaign total
    $updateStmt = $connection->prepare("UPDATE broadcast_campaigns SET total_recipients = ? WHERE id = ?");
    $updateStmt->execute([count($recipients), $campaignId]);

    // Queue all messages
    $totalQueued  = 0;
    $totalSkipped = 0;

    if ($channel === 'email') {
        // Resolve merge fields per recipient whenever there is anything to resolve.
        // See the note on the same guard in the direct-send path above — event-less
        // templates must merge too, or {{club_name}} ships literally.
        if ($templateId || strpos((string)$subject . (string)$htmlBody, '{{') !== false) {
            foreach ($recipients as $recipient) {
                $context = [
                    'event_id'        => $eventId,
                    'athlete_id'      => $recipient['athlete_id'] ?? null,
                    'guardian_id'     => ($recipient['type'] === 'guardian') ? ($recipient['id'] ?? null) : null,
                    'user_id'         => $auth->getUserId(),
                    'club_profile_id' => $clubProfileId,
                ];
                $resolvedSubject  = $mergeFieldService->resolveVariables($subject, $context);
                $resolvedHtmlBody = $mergeFieldService->resolveVariables($htmlBody, $context, true);
                $resolvedBody     = $body ? $mergeFieldService->resolveVariables($body, $context) : null;

                $result = $emailService->queueEmail([
                    'user_id'               => $auth->getUserId(),
                    'club_profile_id'       => $clubProfileId,
                    'recipients'            => [$recipient],
                    'subject'               => $resolvedSubject,
                    'html_body'             => $resolvedHtmlBody,
                    'body'                  => $resolvedBody,
                    'template_id'           => $templateId,
                    'event_id'              => $eventId,
                    'broadcast_campaign_id' => $campaignId,
                    'team_ids'              => $teamIds,
                ]);
                $totalQueued  += $result['queued'];
                $totalSkipped += $result['skipped'];
            }
        } else {
            $result = $emailService->queueEmail([
                'user_id'               => $auth->getUserId(),
                'club_profile_id'       => $clubProfileId,
                'recipients'            => $recipients,
                'subject'               => $subject,
                'html_body'             => $htmlBody,
                'body'                  => $body,
                'template_id'           => $templateId,
                'event_id'              => $eventId,
                'broadcast_campaign_id' => $campaignId,
                'team_ids'              => $teamIds,
            ]);
            $totalQueued  = $result['queued'];
            $totalSkipped = $result['skipped'];
        }
    } else {
        // SMS broadcast
        $result = $smsService->queueSms([
            'user_id'               => $auth->getUserId(),
            'club_profile_id'       => $clubProfileId,
            'recipients'            => $recipients,
            'body'                  => $body,
            'broadcast_campaign_id' => $campaignId,
            'team_ids'              => $teamIds,
        ]);
        $totalQueued  = $result['queued'];
        $totalSkipped = $result['skipped'];
    }

    // Update broadcast_campaigns with counts
    $updateStmt = $connection->prepare("
        UPDATE broadcast_campaigns
        SET sent_count = ?, skipped_count = ?, status = 'sent', sent_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $updateStmt->execute([$totalQueued, $totalSkipped, $campaignId]);

    echo json_encode([
        'success' => true,
        'data'    => [
            'campaign_id'      => $campaignId,
            'total_recipients' => count($recipients),
            'queued'           => $totalQueued,
            'skipped'          => $totalSkipped,
        ]
    ]);
}


/**
 * preview-broadcast — Preview recipient counts without sending.
 */
function handlePreviewBroadcast($auth, $connection) {
    $data = json_decode(file_get_contents('php://input'), true);

    $clubProfileId  = $data['club_profile_id'] ?? null;
    $channel        = $data['channel'] ?? 'email';
    $teamIds        = $data['team_ids'] ?? [];
    $recipientTypes = $data['recipient_types'] ?? ['athlete', 'guardian'];
    $excludeIds     = $data['exclude_ids'] ?? [];
    $scope          = $data['scope'] ?? 'teams';

    if (!in_array($scope, ['teams', 'club'], true)) {
        http_response_code(400);
        echo json_encode(['error' => "scope must be 'teams' or 'club'"]);
        return;
    }

    $isClubWide = ($scope === 'club');

    if (!$clubProfileId || (!$isClubWide && empty($teamIds))) {
        http_response_code(400);
        echo json_encode(['error' => $isClubWide
            ? 'club_profile_id is required'
            : 'club_profile_id and team_ids are required']);
        return;
    }

    if ($isClubWide) {
        $teamIds = [];
    }

    $authError = broadcastAuthError($auth, $connection, $clubProfileId, $isClubWide, $teamIds);
    if ($authError !== null) {
        http_response_code($authError['code']);
        echo json_encode(['error' => $authError['error']]);
        return;
    }

    $allRecipients = resolveBroadcastRecipients(
        $connection, $teamIds, $recipientTypes, $excludeIds, $channel, $scope, $clubProfileId
    );

    // Count who will NOT receive this.
    //
    // For SMS this MUST use te_sms_skip_reason — the same predicate queueSms uses.
    // The previous version here compared the raw column value against
    // email_suppressions.phone (which stores E.164) and never checked
    // guardians.sms_opt_out at all, so it under-reported suppressions twice over
    // and promised more recipients than the send delivered.
    $suppressed = 0;

    if ($channel === 'sms') {
        $suppressionMap = te_sms_suppression_map($connection, (int) $clubProfileId);
        $optedOutIds    = te_sms_opted_out_guardian_ids($connection);

        foreach ($allRecipients as $r) {
            if (te_sms_skip_reason($r, $suppressionMap, $optedOutIds, $teamIds) !== null) {
                $suppressed++;
            }
        }
    } else {
        foreach ($allRecipients as $r) {
            $contact = $r['email'] ?? null;
            if (!$contact) {
                $suppressed++;
                continue;
            }
            // Scope-aware, matching EmailSendService.
            if (te_email_suppressed($connection, $contact, (int) $clubProfileId, $teamIds)) {
                $suppressed++;
            }
        }
    }

    $total = count($allRecipients);
    $withContactInfo = $total; // all resolved have contact info by query design
    $finalCount = $total - $suppressed;

    echo json_encode([
        'success' => true,
        'data'    => [
            'total'             => $total,
            'with_contact_info' => $withContactInfo,
            'suppressed'        => $suppressed,
            'final_count'       => $finalCount,
        ]
    ]);
}


/**
 * contact-history — Communication history for a specific contact.
 */
function handleContactHistory($auth, $connection) {
    $contactType   = $_GET['contact_type'] ?? null;
    $contactId     = $_GET['contact_id'] ?? null;
    $clubProfileId = $_GET['club_profile_id'] ?? null;

    if (!$contactType || !$contactId || !$clubProfileId) {
        http_response_code(400);
        echo json_encode(['error' => 'contact_type, contact_id, and club_profile_id are required']);
        return;
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return;
    }

    $params = [];
    $whereClauses = ['cl.club_profile_id = ?'];
    $params[] = $clubProfileId;

    if ($contactType === 'athlete') {
        // For athletes: get direct sends AND parent sends about this athlete
        $whereClauses[] = '(cl.athlete_id = ? OR (cl.recipient_type = \'athlete\' AND cl.recipient_id = ?))';
        $params[] = $contactId;
        $params[] = $contactId;
    } elseif ($contactType === 'guardian') {
        // For guardians: get sends to this guardian + sends about their athletes
        $whereClauses[] = '(
            (cl.recipient_type = \'guardian\' AND cl.recipient_id = ?)
            OR cl.athlete_id IN (
                SELECT ag.athlete_id FROM athlete_guardians ag WHERE ag.guardian_id = ?
            )
        )';
        $params[] = $contactId;
        $params[] = $contactId;
    } elseif ($contactType === 'user') {
        $whereClauses[] = '(cl.recipient_type = \'user\' AND cl.recipient_id = ?)';
        $params[] = $contactId;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'contact_type must be athlete, guardian, or user']);
        return;
    }

    // Coach scoping: only show logs for recipients in coach's teams
    if (!$auth->hasRole('club_admin', $clubProfileId, 'club')) {
        $coachTeamIds = getCoachTeamIds($connection, $auth->getUserId(), $clubProfileId);
        if (empty($coachTeamIds)) {
            echo json_encode(['success' => true, 'data' => []]);
            return;
        }
        // Verify the contact itself is within coach scope
        $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
        if ($contactType === 'athlete') {
            $scopeCheck = $connection->prepare(
                "SELECT 1 FROM team_members WHERE athlete_id = ? AND status = 'active' AND team_id IN ($placeholders) LIMIT 1"
            );
            $scopeCheck->execute(array_merge([$contactId], $coachTeamIds));
        } elseif ($contactType === 'guardian') {
            $scopeCheck = $connection->prepare("
                SELECT 1 FROM athlete_guardians ag
                JOIN team_members tm ON ag.athlete_id = tm.athlete_id AND tm.status = 'active'
                WHERE ag.guardian_id = ? AND tm.team_id IN ($placeholders) LIMIT 1
            ");
            $scopeCheck->execute(array_merge([$contactId], $coachTeamIds));
        } else {
            // user type — check if user is a team member in coach's teams
            $scopeCheck = $connection->prepare(
                "SELECT 1 FROM team_members WHERE user_id = ? AND status = 'active' AND team_id IN ($placeholders) LIMIT 1"
            );
            $scopeCheck->execute(array_merge([$contactId], $coachTeamIds));
        }
        if (!$scopeCheck->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Contact is outside your permission scope']);
            return;
        }
    }

    $where = implode(' AND ', $whereClauses);
    // Column set must match what CommunicationHistory.tsx renders: it reads the
    // full body (HTML for emails / text for SMS), sender first+last name, open
    // and click counts, and recipient identity. Returning a reduced/renamed set
    // (body_preview, sender_name only) left the contact's Communications tab
    // showing blank sender/body and no open/click metrics (CA-25).
    $sql = "
        SELECT
            cl.id,
            cl.channel,
            cl.recipient_type,
            cl.recipient_name,
            cl.recipient_email,
            cl.recipient_phone,
            cl.subject,
            cl.body,
            cl.status,
            u.first_name AS sender_first_name,
            u.last_name  AS sender_last_name,
            CONCAT(u.first_name, ' ', u.last_name) AS sender_name,
            COALESCE(cl.open_count, 0)  AS open_count,
            COALESCE(cl.click_count, 0) AS click_count,
            cl.sent_at,
            cl.delivered_at,
            cl.opened_at,
            cl.created_at
        FROM communication_log cl
        LEFT JOIN users u ON cl.user_id = u.id
        WHERE $where
        ORDER BY cl.created_at DESC
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'entries' => $rows,
    ]);
}


/**
 * log — Paginated communication log with filters.
 */
function handleLog($auth, $connection) {
    $clubProfileId = $_GET['club_profile_id'] ?? null;
    $channel       = $_GET['channel'] ?? null;
    $status        = $_GET['status'] ?? null;
    $dateFrom      = $_GET['date_from'] ?? null;
    $dateTo        = $_GET['date_to'] ?? null;
    $teamId        = $_GET['team_id'] ?? null;
    $page          = max(1, (int)($_GET['page'] ?? 1));
    $perPage       = min(100, max(1, (int)($_GET['per_page'] ?? 25)));

    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return;
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return;
    }

    $params = [];
    $whereClauses = ['cl.club_profile_id = ?'];
    $params[] = $clubProfileId;

    if ($channel) {
        $whereClauses[] = 'cl.channel = ?';
        $params[] = $channel;
    }
    if ($status) {
        $whereClauses[] = 'cl.status = ?';
        $params[] = $status;
    }
    if ($dateFrom) {
        $whereClauses[] = 'cl.created_at >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $whereClauses[] = 'cl.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    // Team filter
    if ($teamId) {
        $whereClauses[] = '(
            cl.athlete_id IN (SELECT tm.athlete_id FROM team_members tm WHERE tm.team_id = ? AND tm.status = \'active\')
            OR cl.recipient_id IN (
                SELECT ag.guardian_id FROM athlete_guardians ag
                JOIN team_members tm ON ag.athlete_id = tm.athlete_id AND tm.team_id = ? AND tm.status = \'active\'
            )
        )';
        $params[] = $teamId;
        $params[] = $teamId;
    }

    // Coach scoping: restrict to recipients within coach's teams
    if (!$auth->hasRole('club_admin', $clubProfileId, 'club')) {
        $coachTeamIds = getCoachTeamIds($connection, $auth->getUserId(), $clubProfileId);
        if (empty($coachTeamIds)) {
            echo json_encode(['success' => true, 'data' => [], 'pagination' => ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 0]]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
        $whereClauses[] = "(
            cl.athlete_id IN (SELECT tm.athlete_id FROM team_members tm WHERE tm.team_id IN ($placeholders) AND tm.status = 'active')
            OR cl.recipient_id IN (
                SELECT ag.guardian_id FROM athlete_guardians ag
                JOIN team_members tm2 ON ag.athlete_id = tm2.athlete_id AND tm2.team_id IN ($placeholders) AND tm2.status = 'active'
            )
            OR cl.user_id = ?
        )";
        $params = array_merge($params, $coachTeamIds, $coachTeamIds, [$auth->getUserId()]);
    }

    $where = implode(' AND ', $whereClauses);

    // Count total
    $countSql = "SELECT COUNT(*) FROM communication_log cl WHERE $where";
    $countStmt = $connection->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $totalPages = (int)ceil($total / $perPage);

    // Fetch page
    $sql = "
        SELECT
            cl.id,
            cl.channel,
            cl.recipient_type,
            cl.recipient_name,
            cl.recipient_email,
            cl.recipient_phone,
            cl.subject,
            SUBSTRING(cl.body, 1, 100) AS body_preview,
            cl.status,
            cl.open_count,
            cl.click_count,
            CONCAT(u.first_name, ' ', u.last_name) AS sender_name,
            cl.broadcast_campaign_id,
            cl.sent_at,
            cl.delivered_at,
            cl.opened_at,
            cl.bounced_at,
            cl.created_at
        FROM communication_log cl
        LEFT JOIN users u ON cl.user_id = u.id
        WHERE $where
        ORDER BY cl.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $params[] = $perPage;
    $params[] = $offset;

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'data'       => $rows,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ]
    ]);
}


/**
 * log-detail — Single communication log entry with email_events and email_links.
 */
function handleLogDetail($auth, $connection) {
    $logId = $_GET['id'] ?? null;

    if (!$logId) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }

    // Fetch the log entry
    $stmt = $connection->prepare("
        SELECT
            cl.*,
            CONCAT(u.first_name, ' ', u.last_name) AS sender_name,
            u.email AS sender_email
        FROM communication_log cl
        LEFT JOIN users u ON cl.user_id = u.id
        WHERE cl.id = ?
    ");
    $stmt->execute([$logId]);
    $logEntry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$logEntry) {
        http_response_code(404);
        echo json_encode(['error' => 'Communication log entry not found']);
        return;
    }

    // Check club access
    if (!$auth->canAccessClub($logEntry['club_profile_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    // Coach scoping: verify the log entry is within scope
    if (!$auth->hasRole('club_admin', $logEntry['club_profile_id'], 'club')) {
        $coachTeamIds = getCoachTeamIds($connection, $auth->getUserId(), $logEntry['club_profile_id']);
        if (empty($coachTeamIds)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
        $inScope = false;

        if ($logEntry['athlete_id']) {
            $scopeStmt = $connection->prepare(
                "SELECT 1 FROM team_members WHERE athlete_id = ? AND status = 'active' AND team_id IN ($placeholders) LIMIT 1"
            );
            $scopeStmt->execute(array_merge([$logEntry['athlete_id']], $coachTeamIds));
            $inScope = (bool)$scopeStmt->fetch();
        }
        // Also allow if sender is the coach
        if ($logEntry['user_id'] == $auth->getUserId()) {
            $inScope = true;
        }
        if (!$inScope) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }
    }

    // Fetch email_events
    $eventsStmt = $connection->prepare("
        SELECT id, event_type, event_data, ip_address, user_agent, occurred_at
        FROM email_events
        WHERE communication_log_id = ?
        ORDER BY occurred_at ASC
    ");
    $eventsStmt->execute([$logId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode event_data JSON
    foreach ($events as &$evt) {
        if ($evt['event_data']) {
            $evt['event_data'] = json_decode($evt['event_data'], true);
        }
    }
    unset($evt);

    // Fetch email_links
    $linksStmt = $connection->prepare("
        SELECT id, link_id, original_url, click_count, first_clicked_at, last_clicked_at
        FROM email_links
        WHERE communication_log_id = ?
        ORDER BY id ASC
    ");
    $linksStmt->execute([$logId]);
    $links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => [
            'log'    => $logEntry,
            'events' => $events,
            'links'  => $links,
        ]
    ]);
}


/**
 * preview-email — Resolve merge variables in a subject + HTML body for preview.
 *
 * Accepts JSON: { template_id?, subject, body_html, event_id?, team_id?, club_profile_id }
 * Returns: { success:true, preview_subject, preview_html }
 *
 * Resolution context carries event_id and team_id (when provided) so that
 * event_* and team_name merge fields resolve. When an event is supplied without
 * an explicit team_id, MergeFieldService derives the event's team automatically.
 */
function handlePreviewEmail($auth, $connection, $mergeFieldService) {
    // Accept body params on POST; allow query params as a fallback for GET.
    $data = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'POST') {
        $raw = file_get_contents('php://input');
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    // Merge in query params (query string never overrides an explicit body value).
    $data = array_merge($_GET, $data);

    $clubProfileId = $data['club_profile_id'] ?? null;
    $subject       = $data['subject'] ?? '';
    $bodyHtml      = $data['body_html'] ?? '';
    $templateId    = $data['template_id'] ?? null;
    $eventId       = $data['event_id'] ?? null;
    $teamId        = $data['team_id'] ?? null;

    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return;
    }

    // Enforce club access (same pattern as the other authenticated actions).
    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return;
    }

    // If a template_id was supplied but no inline body/subject, pull them from the
    // stored template so a preview works straight off a template selection.
    if ($templateId && ($subject === '' && $bodyHtml === '')) {
        $tplStmt = $connection->prepare(
            "SELECT subject, html_output, body_text, club_profile_id, scope
             FROM email_templates WHERE id = ?"
        );
        $tplStmt->execute([$templateId]);
        $tpl = $tplStmt->fetch(PDO::FETCH_ASSOC);
        if ($tpl) {
            // Only allow club templates the caller can access (platform templates are global).
            if (($tpl['scope'] ?? null) !== 'platform' && !$auth->canAccessClub($tpl['club_profile_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this template']);
                return;
            }
            $subject  = $subject !== '' ? $subject : ($tpl['subject'] ?? '');
            $bodyHtml = $bodyHtml !== '' ? $bodyHtml : ($tpl['html_output'] ?? $tpl['body_text'] ?? '');
        }
    }

    // Build resolution context. team_id is carried so loadTeamData runs; when only
    // an event is provided, MergeFieldService derives the event's team.
    $context = [
        'club_profile_id' => $clubProfileId,
        'user_id'         => $auth->getUserId(),
    ];
    if ($eventId) { $context['event_id'] = $eventId; }
    if ($teamId)  { $context['team_id']  = $teamId; }

    $previewSubject = $mergeFieldService->resolveVariables((string)$subject, $context);
    $previewHtml    = $mergeFieldService->resolveVariables((string)$bodyHtml, $context, true);

    // Show the club-branded header/footer that EmailSendService::processHtml adds at
    // send time, so the preview is what the recipient actually gets. The unsubscribe
    // link is inert here — real sends carry a signed per-recipient token.
    require_once __DIR__ . '/../lib/EmailBranding.php';
    $previewHtml = EmailBranding::wrap(
        $previewHtml,
        EmailBranding::forClub($connection, $clubProfileId)
    );

    echo json_encode([
        'success'         => true,
        'preview_subject' => $previewSubject,
        'preview_html'    => $previewHtml,
    ]);
}


/**
 * branding — The club's email header and footer markup.
 *
 * Lets the template editor render the real branding on its canvas instead of
 * reimplementing it in TypeScript. The editor injects these as display-only rows
 * and strips them again before saving, so the stored template stays club-agnostic
 * and `EmailSendService::processHtml()` remains the only thing that brands a send.
 */
function handleBranding($auth, $connection) {
    $clubProfileId = (int)($_GET['club_profile_id'] ?? 0);
    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['error' => 'club_profile_id is required']);
        return;
    }
    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this club']);
        return;
    }

    require_once __DIR__ . '/../lib/EmailBranding.php';
    $brand = EmailBranding::forClub($connection, $clubProfileId);

    echo json_encode([
        'success'     => true,
        'club_name'   => $brand['name'],
        'has_logo'    => EmailBranding::hasLogo($brand),
        // The unsubscribe link is inert here — a real send carries a signed
        // per-recipient token generated at queue time.
        'header_html' => EmailBranding::headerHtml($brand),
        'footer_html' => EmailBranding::footerHtml($brand),
    ]);
}

/**
 * unsubscribe — Render branded unsubscribe form (public, no auth).
 */
function handleUnsubscribePage($connection) {
    // Switch to HTML output
    header('Content-Type: text/html; charset=UTF-8');

    $token = $_GET['token'] ?? null;

    if (!$token) {
        echo renderUnsubscribeError('Invalid unsubscribe link. No token provided.');
        return;
    }

    $emailService = new EmailSendService($connection);
    $tokenData = $emailService->validateUnsubscribeToken($token);

    if (!$tokenData) {
        echo renderUnsubscribeError('This unsubscribe link is invalid or has expired.');
        return;
    }

    $email         = $tokenData['email'];
    $clubProfileId = $tokenData['club_profile_id'];

    // Fetch club name
    $clubName = 'Your Organization';
    $stmt = $connection->prepare("SELECT club_name, name FROM club_profile WHERE id = ?");
    $stmt->execute([$clubProfileId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($club) {
        $clubName = htmlspecialchars($club['club_name'] ?? $club['name'] ?? 'Your Organization');
    }

    $safeEmail = htmlspecialchars($email);
    $safeToken = htmlspecialchars($token);

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe - Teams Elevated</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            max-width: 480px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .brand-bar {
            background-color: #12443E;
            height: 6px;
            border-radius: 12px 12px 0 0;
            margin: -40px -40px 30px -40px;
        }
        .logo-text {
            color: #12443E;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .club-name {
            color: #666;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .email-display {
            background: #f0f4f3;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            color: #12443E;
            font-weight: 500;
            margin-bottom: 24px;
            word-break: break-all;
        }
        h2 {
            font-size: 20px;
            color: #333;
            margin-bottom: 12px;
        }
        p.description {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 16px;
        }
        .option-btn {
            display: block;
            width: 100%;
            padding: 14px 20px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }
        .option-btn:hover { opacity: 0.9; }
        .option-btn:active { transform: scale(0.98); }
        .btn-club {
            background-color: #12443E;
            color: #fff;
        }
        .btn-team {
            background-color: #fff;
            color: #12443E;
            border: 2px solid #12443E;
        }
        .btn-tournament {
            background-color: #fff;
            color: #1a56db;
            border: 2px solid #1a56db;
        }
        .footer-text {
            font-size: 12px;
            color: #999;
            margin-top: 24px;
            line-height: 1.4;
        }
        .spinner { display: none; }
        .processing .option-btn { pointer-events: none; opacity: 0.5; }
        .processing .spinner { display: inline-block; }
    </style>
</head>
<body>
    <div class="container" id="unsubContainer">
        <div class="brand-bar"></div>
        <div class="logo-text">Teams Elevated</div>
        <div class="club-name">{$clubName}</div>
        <div class="email-display">{$safeEmail}</div>
        <h2>Manage Email Preferences</h2>
        <p class="description">Choose how you would like to update your email preferences. You can unsubscribe from all emails from this organization, only team-specific emails, or only tournament alerts.</p>
        <div class="options">
            <button class="option-btn btn-club" onclick="processUnsubscribe('club')">
                <span class="spinner">&#8987; </span>Unsubscribe from all {$clubName} emails
            </button>
            <button class="option-btn btn-team" onclick="processUnsubscribe('team')">
                <span class="spinner">&#8987; </span>Unsubscribe from team emails only
            </button>
            <button class="option-btn btn-tournament" onclick="processUnsubscribe('tournament')">
                <span class="spinner">&#8987; </span>Unsubscribe from tournament alerts only
            </button>
        </div>
        <p class="footer-text">
            This will update your preferences immediately. If you believe you received this email in error,
            you can ignore this page.
        </p>
    </div>
    <script>
        function processUnsubscribe(scope) {
            var container = document.getElementById('unsubContainer');
            container.classList.add('processing');

            fetch(window.location.pathname + '?action=process-unsubscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: '{$safeToken}', scope: scope })
            })
            .then(function(response) { return response.text(); })
            .then(function(html) {
                document.body.innerHTML = html;
            })
            .catch(function(err) {
                container.classList.remove('processing');
                alert('Something went wrong. Please try again.');
            });
        }
    </script>
</body>
</html>
HTML;
}


/**
 * process-unsubscribe — Handle the unsubscribe form submission (public, no auth).
 */
function handleProcessUnsubscribe($connection) {
    // Switch to HTML output
    header('Content-Type: text/html; charset=UTF-8');

    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? null;
    $scope = $data['scope'] ?? null;

    if (!$token || !$scope || !in_array($scope, ['club', 'team', 'tournament'])) {
        echo renderUnsubscribeError('Invalid request. Please use the link in your email.');
        return;
    }

    $emailService = new EmailSendService($connection);
    $tokenData = $emailService->validateUnsubscribeToken($token);

    if (!$tokenData) {
        echo renderUnsubscribeError('This unsubscribe link is invalid or has expired.');
        return;
    }

    $communicationLogId = $tokenData['communication_log_id'];
    $email             = $tokenData['email'];
    $clubProfileId     = $tokenData['club_profile_id'];

    $reason = match ($scope) {
        'club'       => 'unsubscribe_all',
        'team'       => 'unsubscribe_team',
        'tournament' => 'unsubscribe_tournament',
        default      => 'unsubscribe',
    };

    // Determine team_id if team-scoped unsubscribe
    $teamId = null;
    if ($scope === 'team') {
        // Look up the team from the communication log's athlete -> team_members
        $stmt = $connection->prepare("
            SELECT tm.team_id
            FROM communication_log cl
            LEFT JOIN team_members tm ON cl.athlete_id = tm.athlete_id AND tm.status = 'active'
            WHERE cl.id = ?
            LIMIT 1
        ");
        $stmt->execute([$communicationLogId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $teamId = $row['team_id'] ?? null;
    }

    // Insert suppression record
    try {
        $stmt = $connection->prepare("
            INSERT INTO email_suppressions
                (club_profile_id, email, channel, reason, scope, team_id, communication_log_id, created_at)
            VALUES (?, ?, 'email', ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (club_profile_id, email, channel, scope, COALESCE(team_id, 0))
            DO NOTHING
        ");
        $stmt->execute([
            $clubProfileId,
            $email,
            $reason,
            $scope,
            $teamId,
            $communicationLogId,
        ]);
    } catch (Exception $e) {
        error_log("Unsubscribe suppression insert error: " . $e->getMessage());
    }

    // Update communication_log.unsubscribed_at
    try {
        $stmt = $connection->prepare("
            UPDATE communication_log SET unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ? AND unsubscribed_at IS NULL
        ");
        $stmt->execute([$communicationLogId]);
    } catch (Exception $e) {
        error_log("Unsubscribe log update error: " . $e->getMessage());
    }

    // Fetch club name for confirmation
    $clubName = 'your organization';
    $stmt = $connection->prepare("SELECT club_name, name FROM club_profile WHERE id = ?");
    $stmt->execute([$clubProfileId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($club) {
        $clubName = htmlspecialchars($club['club_name'] ?? $club['name'] ?? 'your organization');
    }

    $scopeLabel = match ($scope) {
        'club'       => "all emails from <strong>{$clubName}</strong>",
        'team'       => "team-specific emails from <strong>{$clubName}</strong>",
        'tournament' => "tournament alerts from <strong>{$clubName}</strong>",
        default      => "emails from <strong>{$clubName}</strong>",
    };

    echo renderUnsubscribeConfirmation($scopeLabel, htmlspecialchars($email));
}


// ════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════

/**
 * Get team IDs that a coach has access to.
 */
// getCoachTeamIds now lives in lib/coach_scope.php — required at the top of this
// file, and by recipient-search-gateway.php, which used to hold its own copy.

/**
 * Validate that all recipients are within the sender's permission scope.
 */
function validateRecipientScope($auth, $connection, $clubProfileId, $recipients) {
    // Club admins can send to anyone in the club
    if ($auth->hasRole('club_admin', $clubProfileId, 'club')) {
        return true;
    }

    // Coaches: verify every recipient is in one of their teams
    $coachTeamIds = getCoachTeamIds($connection, $auth->getUserId(), $clubProfileId);
    if (empty($coachTeamIds)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));

    foreach ($recipients as $r) {
        $type = $r['type'] ?? '';
        $id   = $r['id'] ?? null;

        if (!$id) {
            continue; // Skip recipients without an ID (freeform entry)
        }

        $stmt = null;

        if ($type === 'guardian') {
            // Check guardian is linked to an athlete on coach's team
            $stmt = $connection->prepare("
                SELECT 1 FROM athlete_guardians ag
                JOIN team_members tm ON ag.athlete_id = tm.athlete_id AND tm.status = 'active'
                WHERE ag.guardian_id = ? AND tm.team_id IN ($placeholders)
                LIMIT 1
            ");
            $stmt->execute(array_merge([$id], $coachTeamIds));
        } elseif ($type === 'athlete') {
            $stmt = $connection->prepare("
                SELECT 1 FROM team_members
                WHERE athlete_id = ? AND status = 'active' AND team_id IN ($placeholders)
                LIMIT 1
            ");
            $stmt->execute(array_merge([$id], $coachTeamIds));
        } elseif ($type === 'coach' || $type === 'user') {
            // Check if user is a team member or primary coach in one of the coach's teams
            $stmt = $connection->prepare("
                SELECT 1 FROM (
                    SELECT user_id FROM team_members WHERE team_id IN ($placeholders) AND status = 'active'
                    UNION
                    SELECT primary_coach_id AS user_id FROM teams WHERE id IN ($placeholders)
                ) AS accessible_users
                WHERE user_id = ?
                LIMIT 1
            ");
            $stmt->execute(array_merge($coachTeamIds, $coachTeamIds, [$id]));
        }

        if ($stmt && !$stmt->fetch()) {
            return false;
        }
    }

    return true;
}

/**
 * Authorization for a broadcast, shared by send and preview.
 *
 * These two MUST agree. If preview is more permissive, the UI shows a recipient
 * count for a send that then 403s; if it is stricter, a legitimate send looks
 * impossible. Keeping one function is the only way that stays true.
 *
 * @return array{code: int, error: string}|null Null when the send is allowed.
 */
function broadcastAuthError($auth, $connection, $clubProfileId, $isClubWide, array $teamIds): ?array
{
    if (!$auth->canAccessClub($clubProfileId)) {
        return ['code' => 403, 'error' => 'Access denied to this club'];
    }

    if ($auth->hasRole('club_admin', $clubProfileId, 'club')) {
        return null;
    }

    // A club-wide send reaches families a coach has no relationship with,
    // including athletes on no roster at all. The team picker IS the coach's
    // boundary, so widening past it is an escalation, not a bigger team send.
    if ($isClubWide) {
        return ['code' => 403, 'error' => 'Only club admins can send a club-wide broadcast'];
    }

    $coachTeamIds = getCoachTeamIds($connection, $auth->getUserId(), $clubProfileId);
    foreach ($teamIds as $tid) {
        if (!in_array($tid, $coachTeamIds)) {
            return [
                'code'  => 403,
                'error' => 'You do not have access to one or more of the selected teams',
            ];
        }
    }

    return null;
}

/**
 * Resolve recipients for a broadcast.
 *
 * Two scopes, ONE contract: $recipientTypes means exactly the same thing whether
 * you are sending to three teams or to the whole club. That is why club-wide is
 * implemented here rather than by routing through the `special_group` ids in
 * recipient-search-gateway.php — those ids ('all', 'all_crew') bake the type
 * selection into the group name and cannot express "athletes only, club-wide".
 *
 * ⚠️ $recipientTypes values are SINGULAR — 'athlete', 'guardian', 'coach'. The
 * resolve-group endpoint in recipient-search-gateway.php takes the PLURAL forms.
 * Both are correct for their own endpoint; passing the plural array here resolves
 * zero recipients and sends nothing, with a 200 and total_recipients: 0. Locked by
 * BroadcastRecipientResolutionTest::testPluralRecipientTypesResolveNobody.
 *
 * @param string   $scope         'teams' (default, uses $teamIds) or 'club'.
 * @param int|null $clubProfileId Required when $scope is 'club'.
 */
function resolveBroadcastRecipients(
    $connection,
    $teamIds,
    $recipientTypes,
    $excludeIds,
    $channel,
    $scope = 'teams',
    $clubProfileId = null
) {
    $recipients = [];
    $isClubWide = ($scope === 'club');

    if ($isClubWide && $clubProfileId === null) {
        throw new InvalidArgumentException('club_profile_id is required for club-wide resolution');
    }
    if (!$isClubWide && empty($teamIds)) {
        return [];
    }

    $placeholders = $isClubWide ? '' : implode(',', array_fill(0, count($teamIds), '?'));
    $scopeParams  = $isClubWide ? [$clubProfileId] : $teamIds;

    // Exclusions are keyed "type:id". A bare numeric id is accepted for backward
    // compatibility and matches ANY type — which is the pre-existing behavior, and
    // its bug: athlete 5 and guardian 5 are different people, so an untyped
    // exclusion of one silently drops the other. The compose UI sends typed keys.
    $excludeLookup = [];
    foreach ((array) $excludeIds as $ex) {
        if (is_string($ex) && strpos($ex, ':') !== false) {
            $excludeLookup[$ex] = true;
        } else {
            $excludeLookup['*:' . (int) $ex] = true;
        }
    }
    $isExcluded = function ($type, $id) use ($excludeLookup) {
        return isset($excludeLookup["{$type}:{$id}"]) || isset($excludeLookup["*:{$id}"]);
    };

    // Athletes
    if (in_array('athlete', $recipientTypes)) {
        $contactCol = ($channel === 'email') ? 'a.email' : 'a.phone';

        if ($isClubWide) {
            // Club-scoped deliberately does NOT join team_members: an athlete who
            // has registered but is not yet rostered is exactly who a club-wide
            // announcement is for, and a team join would drop them. Mirrors
            // resolveSpecialGroup (recipient-search-gateway.php:1042).
            $sql = "
                SELECT DISTINCT
                    a.id, a.first_name, a.last_name, {$contactCol} AS contact,
                    (SELECT tm.team_id FROM team_members tm
                      WHERE tm.athlete_id = a.id AND tm.status = 'active'
                      ORDER BY tm.team_id LIMIT 1) AS team_id
                FROM athletes a
                WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
                    AND {$contactCol} IS NOT NULL AND {$contactCol} != ''
            ";
        } else {
            $sql = "
                SELECT DISTINCT
                    a.id, a.first_name, a.last_name, {$contactCol} AS contact, tm.team_id
                FROM athletes a
                JOIN team_members tm ON a.id = tm.athlete_id
                WHERE tm.team_id IN ($placeholders) AND tm.status = 'active' AND a.active_status = true
                    AND {$contactCol} IS NOT NULL AND {$contactCol} != ''
            ";
        }

        $stmt = $connection->prepare($sql);
        $stmt->execute($scopeParams);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($isExcluded('athlete', $row['id'])) continue;
            $r = [
                'name'    => $row['first_name'] . ' ' . $row['last_name'],
                'type'    => 'athlete',
                'id'      => $row['id'],
                'team_id' => $row['team_id'],
            ];
            $r[($channel === 'email') ? 'email' : 'phone'] = $row['contact'];
            $recipients[] = $r;
        }
    }

    // Guardians
    if (in_array('guardian', $recipientTypes)) {
        if ($channel === 'email') {
            // receive_invites gates email only — it is about deliverability of
            // account/invite mail, and has never gated SMS.
            $select = 'g.email AS contact, g.personal_email';
            $filter = "AND g.receive_invites = true AND (g.email IS NOT NULL AND g.email != '')";
        } else {
            $select = 'g.mobile_phone AS contact, NULL AS personal_email';
            $filter = "AND g.mobile_phone IS NOT NULL AND g.mobile_phone != ''";
        }

        if ($isClubWide) {
            $sql = "
                SELECT DISTINCT
                    g.id, g.first_name, g.last_name, {$select},
                    ag.athlete_id,
                    (SELECT tm.team_id FROM team_members tm
                      WHERE tm.athlete_id = ag.athlete_id AND tm.status = 'active'
                      ORDER BY tm.team_id LIMIT 1) AS team_id
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                JOIN athletes a ON a.id = ag.athlete_id
                WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
                    {$filter}
            ";
        } else {
            $sql = "
                SELECT DISTINCT
                    g.id, g.first_name, g.last_name, {$select},
                    ag.athlete_id, tm.team_id
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                JOIN team_members tm ON ag.athlete_id = tm.athlete_id AND tm.status = 'active'
                JOIN athletes a ON a.id = ag.athlete_id AND a.active_status = true
                WHERE tm.team_id IN ($placeholders)
                    {$filter}
            ";
        }

        $stmt = $connection->prepare($sql);
        $stmt->execute($scopeParams);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($isExcluded('guardian', $row['id'])) continue;
            $r = [
                'name'       => $row['first_name'] . ' ' . $row['last_name'],
                'type'       => 'guardian',
                'id'         => $row['id'],
                'athlete_id' => $row['athlete_id'],
                'team_id'    => $row['team_id'],
            ];
            if ($channel === 'email') {
                $r['email'] = $row['contact'];
                $recipients[] = $r;
                // A distinct personal_email is a second address for the same person.
                if (!empty($row['personal_email']) && $row['personal_email'] !== $row['contact']) {
                    $r2 = $r;
                    $r2['email'] = $row['personal_email'];
                    $recipients[] = $r2;
                }
            } else {
                $r['phone'] = $row['contact'];
                $recipients[] = $r;
            }
        }
    }

    // Coaches
    if (in_array('coach', $recipientTypes)) {
        $contactCol = ($channel === 'email') ? 'u.email' : 'u.phone';
        $coachSqls  = [];

        if ($isClubWide) {
            // user_club_access is authoritative for club roles, NOT users.role.
            $coachSqls[] = "
                SELECT DISTINCT
                    u.id, u.first_name, u.last_name, {$contactCol} AS contact, NULL AS team_id
                FROM users u
                JOIN user_club_access uca ON u.id = uca.user_id
                WHERE uca.club_profile_id = ? AND uca.active = true
                    AND uca.role IN ('coach', 'club_admin')
                    AND {$contactCol} IS NOT NULL AND {$contactCol} != ''
            ";
        } else {
            $coachSqls[] = "
                SELECT DISTINCT
                    u.id, u.first_name, u.last_name, {$contactCol} AS contact, tm.team_id
                FROM team_members tm
                JOIN users u ON tm.user_id = u.id
                WHERE tm.team_id IN ($placeholders)
                    AND tm.role IN ('assistant_coach', 'team_manager')
                    AND tm.status = 'active'
                    AND {$contactCol} IS NOT NULL AND {$contactCol} != ''
            ";
            $coachSqls[] = "
                SELECT DISTINCT
                    u.id, u.first_name, u.last_name, {$contactCol} AS contact, t.id AS team_id
                FROM teams t
                JOIN users u ON t.primary_coach_id = u.id
                WHERE t.id IN ($placeholders)
                    AND {$contactCol} IS NOT NULL AND {$contactCol} != ''
            ";
        }

        foreach ($coachSqls as $sql) {
            $stmt = $connection->prepare($sql);
            $stmt->execute($scopeParams);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($isExcluded('coach', $row['id'])) continue;
                $r = [
                    'name'    => $row['first_name'] . ' ' . $row['last_name'],
                    'type'    => 'coach',
                    'id'      => $row['id'],
                    'team_id' => $row['team_id'],
                ];
                $r[($channel === 'email') ? 'email' : 'phone'] = $row['contact'];
                $recipients[] = $r;
            }
        }
    }

    // De-duplicate by the address we will actually send to. SMS keys on the
    // NORMALIZED number so "(360) 555-1234" and "3605551234" — the same person
    // entered twice, or a guardian who is also a coach — collapse to one message
    // instead of two. The raw-string comparison this replaces did not catch that.
    $seen = [];
    $unique = [];
    foreach ($recipients as $r) {
        if ($channel === 'email') {
            $key = strtolower(trim((string) ($r['email'] ?? '')));
        } else {
            // Fall back to the raw value when a number won't normalize, so an
            // unusable phone still RESOLVES and gets counted and reported as
            // skipped. Dropping it here instead would make preview say "0
            // recipients" where the truth is "1 recipient we can't reach" —
            // a silent drop, which is exactly what the compose UI promises not
            // to do. te_sms_skip_reason is what actually excludes them.
            $key = te_normalize_sms_phone($r['phone'] ?? null)
                ?? trim((string) ($r['phone'] ?? ''));
        }
        if ($key && !isset($seen[$key])) {
            $seen[$key] = true;
            if ($channel === 'email') {
                $r['email'] = $key;
            }
            $unique[] = $r;
        }
    }

    return $unique;
}

/**
 * Render an HTML error page for unsubscribe failures.
 */
function renderUnsubscribeError($message) {
    $safeMessage = htmlspecialchars($message);
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe - Teams Elevated</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            max-width: 480px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .brand-bar {
            background-color: #12443E;
            height: 6px;
            border-radius: 12px 12px 0 0;
            margin: -40px -40px 30px -40px;
        }
        .logo-text {
            color: #12443E;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .error-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        h2 {
            font-size: 20px;
            color: #333;
            margin-bottom: 12px;
        }
        p {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="brand-bar"></div>
        <div class="logo-text">Teams Elevated</div>
        <div class="error-icon">&#9888;</div>
        <h2>Unable to Process</h2>
        <p>{$safeMessage}</p>
    </div>
</body>
</html>
HTML;
}

/**
 * Render an HTML confirmation page after successful unsubscribe.
 */
function renderUnsubscribeConfirmation($scopeLabel, $email) {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribed - Teams Elevated</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            max-width: 480px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .brand-bar {
            background-color: #12443E;
            height: 6px;
            border-radius: 12px 12px 0 0;
            margin: -40px -40px 30px -40px;
        }
        .logo-text {
            color: #12443E;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .check-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background-color: #12443E;
            color: #fff;
            font-size: 32px;
            line-height: 64px;
            margin: 0 auto 20px auto;
        }
        h2 {
            font-size: 20px;
            color: #333;
            margin-bottom: 12px;
        }
        p {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
        }
        .email-display {
            background: #f0f4f3;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            color: #12443E;
            font-weight: 500;
            margin: 16px 0;
            word-break: break-all;
        }
        .footer-text {
            font-size: 12px;
            color: #999;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="brand-bar"></div>
        <div class="logo-text">Teams Elevated</div>
        <div class="check-icon">&#10003;</div>
        <h2>You've Been Unsubscribed</h2>
        <p>You have been successfully unsubscribed from {$scopeLabel}.</p>
        <div class="email-display">{$email}</div>
        <p>This change takes effect immediately. You will no longer receive these emails.</p>
        <p class="footer-text">If you unsubscribed by mistake, please contact your club administrator to re-subscribe.</p>
    </div>
</body>
</html>
HTML;
}
?>

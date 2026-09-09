<?php
/**
 * Club Public Gateway — the data behind /club/<slug> (2026-09-09).
 *
 * PUBLIC. No token, on purpose: this page is the club's shareable link for
 * Instagram bios and email signatures. Everything it answers is decided by
 * lib/club_public_page.php's allowlists; nothing here selects a column.
 *
 *   GET  ?action=page&slug=…            the whole page in one round trip
 *        [&events=all]                  the full upcoming games list (cap 100)
 *   GET  ?action=ics&slug=…             text/calendar of the same games
 *   POST ?action=contact&slug=…         {name, email, phone?, message, website_url (honeypot)}
 *
 * The contact form: the message is STORED first (club_contact_messages), the
 * transaction committed, and only then are the club's administrators mailed —
 * a SendGrid outage cannot lose a family's message. The visitor's address goes
 * in Reply-To, never in From (SendGrid domain authentication). Rate limited
 * 5/hour per IP and it FAILS CLOSED, unlike the support ticket limiter: this
 * form causes outbound mail to real people. A filled honeypot is answered with
 * a silent 200. Behind TE_FEATURE_PUBLIC_CLUB_CONTACT (FeatureFlagsTest::GATED).
 *
 * A club whose page is switched off, and a slug that does not exist, both
 * answer 404 with the same body.
 */

if (defined('TE_CLUB_PUBLIC_LIB_ONLY')) {
    require_once __DIR__ . '/../lib/club_public_page.php';
    return;
}

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/club_public_page.php';
require_once __DIR__ . '/../lib/feature_flags.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

$pdo = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'page';
$slug = trim((string) ($_GET['slug'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'];

function te_club_public_not_found(): void
{
    http_response_code(404);
    echo json_encode(['error' => 'This club page is not available.', 'reason' => 'not_found']);
    exit();
}

try {
    $club = te_club_public_resolve($pdo, $slug);
    if ($club === null) {
        te_club_public_not_found();
    }

    switch ($action) {
        case 'page':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                break;
            }
            $limit = (($_GET['events'] ?? '') === 'all') ? TE_CLUB_PUBLIC_EVENTS_MAX : TE_CLUB_PUBLIC_EVENTS_DEFAULT;
            header('Cache-Control: public, max-age=300');
            echo json_encode(['success' => true] + te_club_public_page_payload($pdo, $club, $limit));
            break;

        case 'ics':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                break;
            }
            $payload = te_club_public_club_payload($club);
            $events = te_club_public_events($pdo, (int) $club['id'], TE_CLUB_PUBLIC_EVENTS_MAX);
            header('Content-Type: text/calendar; charset=UTF-8');
            header('Content-Disposition: inline; filename="' . $payload['slug'] . '.ics"');
            header('Cache-Control: public, max-age=900');
            echo te_club_public_ics($payload, $events);
            break;

        case 'contact':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                break;
            }
            if (!te_feature_enabled('PUBLIC_CLUB_CONTACT')) {
                http_response_code(503);
                echo json_encode(te_feature_disabled_response('PUBLIC_CLUB_CONTACT'));
                break;
            }
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = [];
            }

            // Honeypot: a real browser never fills a field that is not rendered.
            // Answer as if it worked so a bot learns nothing from the response.
            if (trim((string) ($body['website_url'] ?? '')) !== '') {
                echo json_encode(['success' => true, 'sent' => false]);
                break;
            }

            $ip = te_club_contact_client_ip();
            if (te_club_contact_is_rate_limited($pdo, $ip)) {
                http_response_code(429);
                echo json_encode([
                    'success' => false, 'reason' => 'rate_limited',
                    'error' => 'You have sent a few messages already. Please try again in an hour.',
                ]);
                break;
            }

            $v = te_club_contact_validate($body);
            if (!$v['ok']) {
                http_response_code(422);
                echo json_encode(['success' => false, 'reason' => 'invalid', 'field' => $v['field'], 'error' => $v['error']]);
                break;
            }
            $values = $v['values'];
            $clubId = (int) $club['id'];

            // 1. Store. Commit BEFORE anything can fail.
            $pdo->beginTransaction();
            try {
                $messageId = te_club_contact_store($pdo, $clubId, $values, $ip, $_SERVER['HTTP_USER_AGENT'] ?? null);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            AuditLogger::log($pdo, null, 'club_contact_message', 'club_contact_messages', $messageId, [
                'club_id' => $clubId, 'from_email' => $values['email'],
            ]);

            // 2. Notify every club administrator. A failed send is recorded,
            //    never surfaced as a failed submission — the message is safe.
            require_once __DIR__ . '/../lib/Email.php';
            $admins = te_club_admin_recipients($pdo, $clubId);
            $sentTo = [];
            foreach ($admins as $admin) {
                try {
                    $ok = (new Email())
                        ->forClub($pdo, $clubId)
                        ->replyTo($values['email'], $values['name'])
                        ->sendClubContactMessage(
                            $admin['email'],
                            (string) $admin['first_name'],
                            (string) $club['name'],
                            $values
                        );
                    if ($ok) {
                        $sentTo[] = $admin['email'];
                    }
                } catch (Throwable $e) {
                    error_log('club contact notify failed for admin ' . $admin['id'] . ': ' . $e->getMessage());
                }
            }
            te_club_contact_mark($pdo, $messageId, $sentTo ? 'sent' : 'failed', $sentTo);

            // Never echo who was mailed: the admin list is the club's business.
            echo json_encode(['success' => true, 'sent' => $sentTo !== []]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('club-public-gateway: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong loading this club page.']);
}

<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * One From address, the club's name.
 *
 * Before 2026-08-04 there were three senders across three paths, two of them on
 * hardcoded fallbacks nobody had chosen:
 *
 *   lib/Email.php              maggie@eyeinteams.com            "Teams Elevated"
 *   services/EmailSendService  notifications@teamselevated.com  <staff member's name>
 *   services/CalendarInvite    maggie@eyeinteams.com            "Maggie - Teams Elevated"
 *
 * A family registering, being invited, then receiving a club broadcast saw mail
 * from two domains under three names — and the one they should recognise, their
 * own club's, appeared nowhere.
 */
class EmailSenderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE club_profile (
                id INTEGER PRIMARY KEY, name TEXT, primary_color TEXT, email TEXT,
                website TEXT, social_facebook TEXT, social_instagram TEXT,
                logo_png BLOB, logo_w INTEGER, logo_h INTEGER
            );
        ");
        $this->pdo->sqliteCreateFunction('md5', 'md5', 1);
        $this->pdo->exec("INSERT INTO club_profile (id, name, primary_color)
                          VALUES (51, 'Central Kansas United', '#12443e')");

        // EmailBranding caches per club id across calls; clear it so tests do not
        // leak into each other.
        if (class_exists('EmailBranding') && property_exists('EmailBranding', 'cache')) {
            $ref = new \ReflectionProperty('EmailBranding', 'cache');
            $ref->setAccessible(true);
            $ref->setValue(null, []);
        }
    }

    public function testTheAddressIsTheSingleNotificationsOne(): void
    {
        $this->assertSame('notifications@teamselevated.com', te_email_from_address());
    }

    public function testAKnownClubSendsUnderItsOwnName(): void
    {
        $this->assertSame('Central Kansas United', te_email_from_name($this->pdo, 51));
    }

    /**
     * Password reset and magic-link sign-in from the login page have no club —
     * the person may belong to several or none. Guessing one would be worse than
     * being generic.
     */
    public function testNoClubContextFallsBackToThePlatformName(): void
    {
        $this->assertSame('Teams Elevated', te_email_from_name());
        $this->assertSame('Teams Elevated', te_email_from_name($this->pdo, null));
        $this->assertSame('Teams Elevated', te_email_from_name(null, 51));
    }

    /**
     * EmailBranding answers 'Your Club' for an id it cannot find. That reads fine
     * as a page heading and terrible as a sender, so it must never reach an inbox.
     */
    public function testUnknownClubNeverSendsAsYourClub(): void
    {
        $name = te_email_from_name($this->pdo, 999999);

        $this->assertNotSame('Your Club', $name);
        $this->assertSame('Teams Elevated', $name);
    }

    public function testFromPayloadShapeMatchesSendGrid(): void
    {
        $from = te_email_from($this->pdo, 51);

        $this->assertSame(
            ['email' => 'notifications@teamselevated.com', 'name' => 'Central Kansas United'],
            $from
        );
    }

    /**
     * Source guard: no send path may keep its own hardcoded sender. Two of the
     * three did, which is why families saw two domains.
     */
    public function testNoSendPathHardcodesItsOwnSender(): void
    {
        $paths = [
            'lib/Email.php',
            'services/EmailSendService.php',
            'services/CalendarInviteService.php',
        ];

        foreach ($paths as $rel) {
            $src = file_get_contents(__DIR__ . '/../../' . $rel);

            $this->assertDoesNotMatchRegularExpression(
                "/'maggie@eyeinteams\\.com'/",
                $src,
                "$rel must not carry its own From address as a literal"
            );
            $this->assertStringContainsString(
                'te_email_from',
                $src,
                "$rel must resolve its sender through lib/email_sender.php"
            );
        }
    }

    /**
     * The bulk path shows the CLUB in From and the staff member in Reply-To. A
     * parent should recognise the club in their inbox; a reply still has to reach
     * a person.
     */
    public function testBulkPathSendsAsClubButRepliesToTheSender(): void
    {
        $src = file_get_contents(__DIR__ . '/../../services/EmailSendService.php');

        $fromAt = strpos($src, "'from' => te_email_from(");
        $replyAt = strpos($src, "'reply_to' => [");
        $this->assertNotFalse($fromAt, 'from must come from the shared resolver');
        $this->assertNotFalse($replyAt);

        $replyBlock = substr($src, $replyAt, 200);
        $this->assertStringContainsString("\$senderInfo['email']", $replyBlock);
    }

    /** The calendar path stamps the club per event, since PHPMailer holds one From. */
    public function testCalendarPathReStampsTheSenderPerEvent(): void
    {
        $src = file_get_contents(__DIR__ . '/../../services/CalendarInviteService.php');

        $this->assertStringContainsString('private function sendAsClub(', $src);
        // Every public send path must stamp it — a new one that forgets sends as
        // the platform, which is the bug this replaces.
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($src, '$this->sendAsClub('),
            'each public send entry point should stamp the club sender'
        );
    }

    /**
     * Every transactional send site that HAS a club must brand as it.
     *
     * The exceptions are deliberate and listed, so adding a new unbranded send
     * requires a decision rather than an oversight:
     *   - auth-gateway's magic link and password reset come from the login page,
     *     where the person may span several clubs or none.
     *   - organization-gateway invites people TO the platform; they have no club yet.
     */
    public function testEveryClubAwareSendSiteBrandsAsTheClub(): void
    {
        $shouldBrand = [
            'api/consent.php',
            'api/invitations-gateway.php',
            'api/calendar-events-gateway.php',
            'api/campaign-donations.php',
            'api/webhooks/stripe-connect.php',
            'api/portal-access.php',
        ];

        foreach ($shouldBrand as $rel) {
            $src = file_get_contents(__DIR__ . '/../../' . $rel);
            $constructs = preg_match_all('/new\s+Email\s*\(\s*\)/', $src);
            $branded = substr_count($src, '->forClub(');

            $this->assertGreaterThan(0, $constructs, "$rel should construct Email");
            $this->assertSame(
                $constructs,
                $branded,
                "$rel constructs $constructs Email(s) but brands $branded — every send with a club must call forClub()"
            );
        }
    }

    /** The two platform-level paths stay unbranded on purpose. */
    public function testPlatformLevelSendsAreDeliberatelyUnbranded(): void
    {
        foreach (['api/organization-gateway.php'] as $rel) {
            $src = file_get_contents(__DIR__ . '/../../' . $rel);
            $this->assertStringNotContainsString(
                '->forClub(',
                $src,
                "$rel invites people to the platform before they have a club"
            );
        }
    }
}

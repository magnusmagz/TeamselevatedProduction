<?php

namespace TeamsElevated\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

if (!defined('TE_CLUB_PUBLIC_LIB_ONLY')) {
    define('TE_CLUB_PUBLIC_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/club-public-gateway.php';

/**
 * /club/<slug> is a PUBLIC surface. These tests are about what a stranger can
 * and cannot see, executed against a SQLite fixture whose tables mirror
 * tests/fixtures/production-schema.json, plus a scan of the SQL in
 * lib/club_public_page.php for the columns that must never appear there.
 *
 * The bug shape this guards: `SELECT *` on a public route. It is how
 * api/sponsors.php leaked sponsor contact details and
 * api/tournament-public-gateway.php leaked venue gate codes.
 */
class ClubPublicPageTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Postgres has md5(); the logo cache-buster uses it. SQLite gets PHP's.
        $this->pdo->sqliteCreateFunction('md5', 'md5', 1);
        $this->pdo->exec("
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, phone TEXT, email TEXT, website TEXT,
                address_line1 TEXT, address_line2 TEXT, city TEXT, state TEXT, zip_code TEXT, description TEXT,
                social_facebook TEXT, social_instagram TEXT, social_twitter TEXT, social_tiktok TEXT, social_youtube TEXT, social_linkedin TEXT,
                logo_url TEXT, primary_color TEXT, secondary_color TEXT,
                public_page_enabled INTEGER DEFAULT 1, public_page_tagline TEXT, logo_png TEXT);
            CREATE TABLE sponsors (id INTEGER PRIMARY KEY, club_id INTEGER, name TEXT, website TEXT,
                contact_name TEXT, contact_email TEXT, contact_phone TEXT, logo_data TEXT,
                display_order INTEGER, is_active INTEGER DEFAULT 1, deleted_at TEXT);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT,
                profile_image_url TEXT, coaching_background TEXT, archived INTEGER DEFAULT 0);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, primary_coach_id INTEGER,
                age_group TEXT, gender TEXT, division TEXT, status TEXT, deleted_at TEXT, primary_color TEXT, team_color TEXT, logo_url TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER, athlete_id INTEGER,
                role TEXT, status TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, date_of_birth TEXT, club_id INTEGER);
            CREATE TABLE venues (id INTEGER PRIMARY KEY, name TEXT, gate_code TEXT, address TEXT);
            CREATE TABLE fields (id INTEGER PRIMARY KEY, venue_id INTEGER, name TEXT, active INTEGER DEFAULT 1, field_size TEXT);
            CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, club_id INTEGER, name TEXT, type TEXT, event_date TEXT,
                start_time TEXT, end_time TEXT, venue_id INTEGER, field_id INTEGER, location TEXT, description TEXT,
                status TEXT, opponent_name TEXT, min_referee_grade TEXT);
            CREATE TABLE calendar_event_teams (id INTEGER PRIMARY KEY, event_id INTEGER, team_id INTEGER);
            CREATE TABLE calendar_event_attendees (id INTEGER PRIMARY KEY, event_id INTEGER, user_id INTEGER, email TEXT, rsvp_token TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                active INTEGER DEFAULT 1, revoked_at TEXT);

            INSERT INTO club_profile VALUES (51, 'Central Kansas United', 'central-kansas-united', '785-555-0100',
                'admin@cku.org', 'centralkansassoccer.org', '123 Main St', 'Suite 2', 'Salina', 'KS', '67401', 'private notes',
                'https://facebook.com/cku', 'https://instagram.com/cku', '', NULL, NULL, NULL,
                'data:image/png;base64,AAAA', '#323c50', '919fba', 1, 'Youth soccer for Salina', 'iVBORw0KGgo=');
            INSERT INTO club_profile VALUES (52, 'Hidden FC', 'hidden-fc', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
                NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL);
            INSERT INTO club_profile VALUES (53, 'No Logo FC', 'no-logo-fc', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
                NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL);

            INSERT INTO sponsors VALUES (1, 51, 'Salina Ford', 'salinaford.com', 'Bob Dealer', 'bob@salinaford.com', '785-555-0199',
                'data:image/png;base64,BBBB', 1, 1, NULL);
            INSERT INTO sponsors VALUES (2, 51, 'Gone Inc', NULL, NULL, NULL, NULL, NULL, 2, 1, '2026-01-01');
            INSERT INTO sponsors VALUES (3, 51, 'Paused Co', NULL, NULL, NULL, NULL, NULL, 3, 0, NULL);

            INSERT INTO users VALUES (10, 'Jamie', 'Rodriguez', 'jamie@example.com', '785-555-0111', 'https://cdn/jamie.jpg', 'A bio', 0);
            INSERT INTO users VALUES (11, 'Ana', 'Lopez', 'ana@example.com', NULL, NULL, NULL, 0);
            INSERT INTO users VALUES (12, 'Old', 'Coach', 'old@example.com', NULL, NULL, NULL, 1);
            INSERT INTO users VALUES (13, 'Kid', 'Player', 'kid@example.com', NULL, NULL, NULL, 0);

            INSERT INTO teams VALUES (1, 'U12 Boys', 51, 10, 'U12', 'Male', 'D1', 'active', NULL, '#ff0000', NULL, NULL);
            INSERT INTO teams VALUES (2, 'U10 Girls', 51, 10, 'U10', 'Female', NULL, 'forming', NULL, NULL, '#00ff00', NULL);
            INSERT INTO teams VALUES (3, 'Deleted', 51, 11, 'U14', 'Male', NULL, 'active', '2026-01-01', NULL, NULL, NULL);
            INSERT INTO teams VALUES (4, 'Other Club', 99, 11, 'U14', 'Male', NULL, 'active', NULL, NULL, NULL, NULL);
            INSERT INTO teams VALUES (5, 'U16', 51, 12, 'U16', 'Male', NULL, 'active', NULL, NULL, NULL, NULL);

            INSERT INTO team_members VALUES (1, 1, 11, NULL, 'assistant_coach', 'active');
            INSERT INTO team_members VALUES (2, 1, 13, 500, 'player', 'active');
            INSERT INTO team_members VALUES (3, 1, 11, NULL, 'team_manager', 'active');

            INSERT INTO venues VALUES (7, 'Bill Burke Park', '1234', '1 Park Rd');
            INSERT INTO fields VALUES (70, 7, 'Field 3', 1, '9v9');

            INSERT INTO calendar_events VALUES (100, 51, 'U12 Boys', 'game', '2026-09-13', '09:00:00', '10:30:00', 7, 70,
                'the Johnsons back yard', 'gate code 1234, bring Emma inhaler', 'scheduled', 'Salina Storm', 'National');
            INSERT INTO calendar_events VALUES (101, 51, 'Practice', 'practice', '2026-09-14', '17:00:00', NULL, 7, NULL, NULL, NULL, 'scheduled', NULL, NULL);
            INSERT INTO calendar_events VALUES (102, 51, 'Cancelled game', 'game', '2026-09-15', '09:00:00', NULL, NULL, NULL, NULL, NULL, 'cancelled', NULL, NULL);
            INSERT INTO calendar_events VALUES (103, 51, 'Old game', 'game', '2026-09-01', '09:00:00', NULL, NULL, NULL, NULL, NULL, 'scheduled', NULL, NULL);
            INSERT INTO calendar_events VALUES (104, 51, 'Sunflower Cup', 'tournament', '2026-09-27', NULL, NULL, NULL, NULL, 'Wichita', NULL, 'scheduled', NULL, NULL);
            INSERT INTO calendar_events VALUES (105, 99, 'Other club game', 'game', '2026-09-13', '09:00:00', NULL, NULL, NULL, NULL, NULL, 'scheduled', NULL, NULL);
            INSERT INTO calendar_event_teams VALUES (1, 100, 1);
            INSERT INTO calendar_event_attendees VALUES (1, 100, 10, 'jamie@example.com', 'secret-token');
        ");
        te_club_public_page_probe_override(true);
        te_event_field_probe_override(true);
    }

    protected function tearDown(): void
    {
        te_club_public_page_probe_override(null);
        te_event_field_probe_override(null);
    }

    // ------------------------------------------------------------ resolve

    public function testSlugResolvesCaseInsensitively(): void
    {
        $this->assertSame(51, (int) te_club_public_resolve($this->pdo, 'Central-Kansas-UNITED')['id']);
    }

    public function testASwitchedOffPageAndAMissingSlugAreTheSameAnswer(): void
    {
        $this->assertNull(te_club_public_resolve($this->pdo, 'hidden-fc'));
        $this->assertNull(te_club_public_resolve($this->pdo, 'no-such-club'));
        $this->assertNull(te_club_public_resolve($this->pdo, ''));
    }

    public function testAbsentMigrationColumnsReadAsEnabled(): void
    {
        te_club_public_page_probe_override(false);
        // hidden-fc has public_page_enabled = 0, but with the columns "absent" the
        // gateway cannot know that; the honest answer is the page (a club that
        // wants it off will get the switch the moment 101 is applied).
        $this->assertNotNull(te_club_public_resolve($this->pdo, 'hidden-fc'));
    }

    // --------------------------------------------------------------- club

    public function testClubPayloadCarriesNoContactEmailOrStreetAddress(): void
    {
        $club = te_club_public_club_payload(te_club_public_resolve($this->pdo, 'central-kansas-united'));
        $this->assertSame('Central Kansas United', $club['name']);
        $this->assertSame('Salina', $club['city']);
        $this->assertSame('KS', $club['state']);
        $this->assertSame('785-555-0100', $club['phone']);
        $this->assertSame('https://centralkansassoccer.org', $club['website'], 'a bare hostname is made a URL');
        $this->assertSame('Youth soccer for Salina', $club['tagline']);
        $this->assertSame('#323c50', $club['primary_color']);
        $this->assertSame('#919fba', $club['secondary_color'], 'a colour without # is normalised');
        $this->assertSame(['facebook' => 'https://facebook.com/cku', 'instagram' => 'https://instagram.com/cku'], (array) $club['socials']);
        foreach (['email', 'address', 'address_line1', 'address_line2', 'zip', 'description', 'map_url'] as $k) {
            $this->assertArrayNotHasKey($k, $club, "$k must not be public");
        }
    }

    public function testOgImageIsTheRasterLogoUrlOrNull(): void
    {
        $club = te_club_public_club_payload(te_club_public_resolve($this->pdo, 'central-kansas-united'));
        $this->assertMatchesRegularExpression('#^https://.+/api/club-logo\.php\?club_id=51&v=[0-9a-f]{8}$#', $club['og_image']);
        $none = te_club_public_club_payload(te_club_public_resolve($this->pdo, 'no-logo-fc'));
        $this->assertNull($none['og_image'], 'no cached PNG means no image, never a data: URI');
    }

    public function testAnUnreadableColourFallsBackToThePlatformColour(): void
    {
        $this->assertSame('#12443e', te_club_public_color('red', '#12443e'));
        $this->assertSame('#12443e', te_club_public_color(null, '#12443e'));
        $this->assertSame('#abcdef', te_club_public_color('ABCDEF', '#000000'));
    }

    // ------------------------------------------------------------ sponsors

    public function testSponsorsAreActiveUndeletedAndCarryNoContactDetails(): void
    {
        $s = te_club_public_sponsors($this->pdo, 51);
        $this->assertCount(1, $s);
        $this->assertSame('Salina Ford', $s[0]['name']);
        $this->assertSame('https://salinaford.com', $s[0]['website']);
        $this->assertSame(['id', 'name', 'website', 'logo_data'], array_keys($s[0]));
    }

    // ------------------------------------------------------------- coaches

    public function testCoachesAreOnePerPersonWithRoleAndTeamsAndNoContactDetails(): void
    {
        $c = te_club_public_coaches($this->pdo, 51);
        $names = array_column($c, 'name');
        $this->assertSame(['Jamie Rodriguez', 'Ana Lopez'], $names, 'head coaches first; archived users and other clubs excluded');
        $this->assertSame('Head coach', $c[0]['role']);
        $this->assertSame(['U10 Girls', 'U12 Boys'], $c[0]['teams'], 'one entry for a person coaching two teams');
        $this->assertSame('https://cdn/jamie.jpg', $c[0]['photo']);
        $this->assertSame('Assistant coach', $c[1]['role'], 'highest role wins when someone is assistant AND manager');
        foreach ($c as $coach) {
            $this->assertSame(['name', 'role', 'photo', 'teams'], array_keys($coach));
        }
    }

    public function testAPlayerRowNeverBecomesACoach(): void
    {
        $names = array_column(te_club_public_coaches($this->pdo, 51), 'name');
        $this->assertNotContains('Kid Player', $names);
    }

    // -------------------------------------------------------------- events

    public function testEventsAreUpcomingGamesAndTournamentsOnly(): void
    {
        $e = te_club_public_events($this->pdo, 51, 10, '2026-09-10');
        $this->assertSame([100, 104], array_column($e, 'id'), 'no practice, no cancelled, no past, no other club');
        $this->assertSame('Salina Storm', $e[0]['opponent_name']);
        $this->assertSame('Bill Burke Park', $e[0]['venue_name']);
        $this->assertSame('Field 3', $e[0]['field_name']);
        $this->assertSame('09:00', $e[0]['start_time']);
        $this->assertSame(['U12 Boys'], $e[0]['teams']);
        $this->assertNull($e[1]['start_time'], 'a tournament with no time is all-day');
    }

    public function testEventPayloadCarriesNoDescriptionLocationOrAttendees(): void
    {
        $e = te_club_public_events($this->pdo, 51, 10, '2026-09-10');
        $this->assertSame(
            ['id', 'name', 'type', 'event_date', 'start_time', 'end_time', 'opponent_name', 'venue_name', 'field_name', 'teams'],
            array_keys($e[0])
        );
        $json = json_encode($e);
        $this->assertStringNotContainsString('inhaler', $json);
        $this->assertStringNotContainsString('Johnsons', $json);
        $this->assertStringNotContainsString('secret-token', $json);
        $this->assertStringNotContainsString('1234', $json, 'the venue gate code');
    }

    public function testEventLimitIsClamped(): void
    {
        $this->assertCount(1, te_club_public_events($this->pdo, 51, 1, '2026-09-10'));
        $this->assertCount(2, te_club_public_events($this->pdo, 51, 100000, '2026-09-10'));
    }

    public function testWholePagePayloadShape(): void
    {
        $club = te_club_public_resolve($this->pdo, 'central-kansas-united');
        $p = te_club_public_page_payload($this->pdo, $club);
        $this->assertSame(['club', 'sponsors', 'coaches', 'events'], array_keys($p));
    }

    // ----------------------------------------------------------------- ICS

    public function testIcsIsWellFormedAndCarriesNoDescriptionText(): void
    {
        $club = te_club_public_club_payload(te_club_public_resolve($this->pdo, 'central-kansas-united'));
        $ics = te_club_public_ics($club, te_club_public_events($this->pdo, 51, 10, '2026-09-10'), '20260910T120000Z');
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("SUMMARY:U12 Boys vs Salina Storm\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20260913T090000\r\n", $ics);
        $this->assertStringContainsString("DTEND:20260913T103000\r\n", $ics);
        $this->assertStringContainsString("LOCATION:Bill Burke Park\\, Field 3\r\n", $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260927\r\n", $ics);
        $this->assertStringNotContainsString('inhaler', $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), 'lines fold at 75 octets');
        }
    }

    // ---------------------------------------------------------------- slug

    public function testSlugGenerationMirrorsTheMigrationRule(): void
    {
        $this->assertSame('central-kansas-united', te_club_slug_from_name('  Central   Kansas United! '));
        $this->assertSame('cku-f-c', te_club_slug_from_name('CKU F.C.'));
        $this->assertSame('', te_club_slug_from_name('---'));
        $this->assertTrue(te_club_slug_valid('central-kansas-united'));
        $this->assertFalse(te_club_slug_valid('Central'));
        $this->assertFalse(te_club_slug_valid('ab'));
        $this->assertFalse(te_club_slug_valid('-leading'));
        $this->assertFalse(te_club_slug_valid(str_repeat('a', 61)));
    }

    public function testSlugGenerationDeduplicatesAgainstOtherClubs(): void
    {
        $this->assertSame('central-kansas-united-2', te_club_slug_generate($this->pdo, 77, 'Central Kansas United'));
        $this->assertSame('central-kansas-united', te_club_slug_generate($this->pdo, 51, 'Central Kansas United'), 'a club keeps its own');
        $this->assertSame('club-77', te_club_slug_generate($this->pdo, 77, '!!'));
    }

    // ---------------------------------------------------------------- scan

    /**
     * The SQL in the public lib must never name the columns a stranger must not
     * read. Scans the SQL literals, not the prose.
     */
    public function testPublicQueriesNeverNameForbiddenColumns(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/club_public_page.php');
        preg_match_all('/(?:prepare|query)\s*\(\s*["\']((?:[^"\'\\\\]|\\\\.)*)["\']|<<<\'?SQL\'?(.*?)SQL;/s', $src, $m);
        preg_match_all('/"\s*\n?\s*SELECT.*?"\s*\)/s', $src, $sql);
        $all = implode("\n", array_merge($m[1], $m[2], $sql[0]));
        $this->assertNotSame('', $all);
        $this->assertStringNotContainsString('SELECT *', $all);
        foreach (['description', 'e.location', 'gate_code', 'contact_email', 'contact_phone', 'contact_name',
                  'medical_', 'calendar_event_attendees', 'rsvp', 'athletes', 'date_of_birth', 'u.email', 'u.phone',
                  'address_line', 'zip_code', 'coaching_background'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all, "public SQL names $forbidden");
        }
    }

    public function testTheGatewayNeverAuthenticatesAndNeverSelects(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/club-public-gateway.php');
        $this->assertStringNotContainsString('requireAuth', $src, 'the page is public by design');
        $this->assertStringNotContainsString('SELECT', $src, 'every column is decided in lib/club_public_page.php');
        $this->assertStringContainsString("te_feature_enabled('PUBLIC_CLUB_CONTACT')", $src);
    }
}

<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use MergeFieldService;

/**
 * Unit tests for MergeFieldService merge-variable resolution.
 *
 * Focus areas (CA-41 / CA-42):
 *   - event_* fields resolve from the calendar_events row (name/date/time/location).
 *   - {{team_name}} resolves from an explicit team_id in the context.
 *   - {{team_name}} resolves from the event's team when only an event_id is
 *     supplied (via the calendar_event_teams join table).
 *
 * Uses an in-memory SQLite database seeded with a minimal fixture. The service's
 * queries use portable SQL (LEFT JOIN / prepared statements) that runs the same
 * on SQLite and PostgreSQL. No production data or credentials are touched.
 *
 * The fixture MUST mirror the live schema. It used to CREATE TABLE events with
 * the pre-calendar shape (title / start_datetime / event_type / team_id), so this
 * suite stayed green for months while every {{event_*}} tag in production
 * resolved to nothing — the table had been dropped. Check
 * tests/fixtures/production-schema.json before changing anything here.
 */
class MergeFieldServiceTest extends TestCase
{
    private PDO $pdo;
    private MergeFieldService $svc;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
        $this->svc = new MergeFieldService($this->pdo);
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT
            );
            CREATE TABLE calendar_events (
                id INTEGER PRIMARY KEY,
                club_id INTEGER,
                name TEXT,
                type TEXT,
                event_date TEXT,
                start_time TEXT,
                end_time TEXT,
                venue_id INTEGER,
                location TEXT
            );
            CREATE TABLE calendar_event_teams (
                id INTEGER PRIMARY KEY,
                event_id INTEGER,
                team_id INTEGER
            );
            CREATE TABLE venues (
                id INTEGER PRIMARY KEY,
                name TEXT,
                address TEXT,
                city TEXT,
                state TEXT
            );
            CREATE TABLE fields (
                id INTEGER PRIMARY KEY,
                name TEXT,
                address TEXT
            );
        ");
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO teams (id, name) VALUES (10, 'Eagles U14')");
        $this->pdo->exec("INSERT INTO venues (id, name, address, city, state)
            VALUES (5, 'Main Stadium', '123 Main St', 'Springfield', 'IL')");
        $this->pdo->exec("INSERT INTO fields (id, name, address)
            VALUES (7, 'Field A', NULL)");
        // Event 1 is at venue 5; calendar_events has no team_id — the link is the
        // calendar_event_teams join table.
        $this->pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, venue_id, location)
            VALUES (1, 100, 'Team Dinner', 'meeting', '2026-03-25', '18:00:00', 5, NULL)");
        // Event 2 has no venue — only the free-text location column.
        $this->pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, venue_id, location)
            VALUES (2, 100, 'Practice', 'practice', '2026-04-01', '17:30:00', NULL, 'The back field')");
        $this->pdo->exec("INSERT INTO calendar_event_teams (id, event_id, team_id) VALUES (1, 1, 10), (2, 2, 10)");
    }

    // ---- event_* fields ----

    public function testEventFieldsResolveFromEvent(): void
    {
        $context = ['event_id' => 1, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables(
            'You are invited to {{event_name}} on {{event_date}} at {{event_time}}.',
            $context
        );
        $this->assertStringContainsString('Team Dinner', $out);
        $this->assertStringContainsString('Wednesday, March 25, 2026', $out);
        $this->assertStringContainsString('6:00 PM', $out);
    }

    public function testEventLocationResolvesFromVenue(): void
    {
        $context = ['event_id' => 1, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables('Location: {{event_location}}', $context);
        $this->assertStringContainsString('Main Stadium', $out);
        $this->assertStringContainsString('123 Main St', $out);
    }

    /**
     * The seeded templates say "Venue: X / Address: Y". Pointing both at
     * {{event_location}} repeated the venue name inside its own address line, so
     * venue and address resolve separately.
     */
    public function testVenueAndAddressResolveSeparately(): void
    {
        $context = ['event_id' => 1, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables(
            'Venue: {{event_venue_name}} | Address: {{event_address}}',
            $context
        );
        $this->assertSame('Venue: Main Stadium | Address: 123 Main St, Springfield IL', $out);
    }

    /** An event booked without a venue record falls back to the free-text column. */
    public function testEventWithoutAVenueFallsBackToTheLocationColumn(): void
    {
        $context = ['event_id' => 2, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables(
            '{{event_location}} / {{event_venue_name}} / {{event_address}}',
            $context
        );
        $this->assertSame('The back field / The back field / The back field', $out);
    }

    /**
     * Values the caller resolved itself (WaitlistService builds these) are
     * substituted from the context. Without it the waitlist offer emailed a
     * literal "{{accept_url}}" in place of the accept button.
     */
    public function testWhitelistedContextValuesResolve(): void
    {
        $context = [
            'club_profile_id' => 100,
            'accept_url' => 'https://app.example/accept?token=abc',
            'decline_url' => 'https://app.example/decline?token=abc',
            'offer_expires_at' => '2026-08-01 12:00:00',
            'division_gender' => 'Girls',
            'venue_name' => 'Salina Complex',
        ];
        $out = $this->svc->resolveVariables(
            '{{accept_url}} {{decline_url}} {{offer_expires_at}} {{division_gender}} {{venue_name}}',
            $context
        );
        $this->assertSame(
            'https://app.example/accept?token=abc https://app.example/decline?token=abc '
            . '2026-08-01 12:00:00 Girls Salina Complex',
            $out
        );
    }

    /** Only the whitelist passes through — never an arbitrary context key. */
    public function testNonWhitelistedContextKeysAreNotSubstituted(): void
    {
        $context = ['club_profile_id' => 100, 'waitlist_offer_token' => 'secret-token'];
        $out = $this->svc->resolveVariables('{{waitlist_offer_token}}', $context);
        $this->assertSame('{{waitlist_offer_token}}', $out);
    }

    // ---- {{team_name}} resolution (CA-42) ----

    public function testTeamNameResolvesFromExplicitTeamId(): void
    {
        $context = ['team_id' => 10, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables('Go {{team_name}}!', $context);
        $this->assertSame('Go Eagles U14!', $out);
    }

    public function testTeamNameResolvesFromEventWhenNoTeamIdGiven(): void
    {
        // The previous bug: only event_id supplied, {{team_name}} never resolved.
        $context = ['event_id' => 1, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables('{{team_name}}: {{event_name}}', $context);
        $this->assertSame('Eagles U14: Team Dinner', $out);
    }

    public function testTeamNameAndEventTogetherResolve(): void
    {
        $context = ['event_id' => 2, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables(
            'Reminder: {{team_name}} {{event_name}} on {{event_date}}.',
            $context
        );
        $this->assertStringContainsString('Eagles U14', $out);
        $this->assertStringContainsString('Practice', $out);
        $this->assertStringContainsString('April 1, 2026', $out);
    }

    public function testExplicitTeamIdWinsOverEventTeam(): void
    {
        $this->pdo->exec("INSERT INTO teams (id, name) VALUES (11, 'Hawks U16')");
        // event_id 1 -> team 10, but caller explicitly passes team 11.
        $context = ['event_id' => 1, 'team_id' => 11, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables('{{team_name}}', $context);
        $this->assertSame('Hawks U16', $out);
    }

    // ---- unresolved / no-data handling ----

    public function testUnknownTeamLeavesPlaceholder(): void
    {
        // No team_id and no event_id => loadTeamData(null) returns [] and the
        // placeholder is left intact.
        $context = ['club_profile_id' => 100];
        $out = $this->svc->resolveVariables('{{team_name}}', $context);
        $this->assertSame('{{team_name}}', $out);
    }

    public function testTextWithoutPlaceholdersIsUnchanged(): void
    {
        $context = ['event_id' => 1];
        $out = $this->svc->resolveVariables('No variables here.', $context);
        $this->assertSame('No variables here.', $out);
    }
}

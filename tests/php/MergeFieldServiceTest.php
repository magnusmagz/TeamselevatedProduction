<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use MergeFieldService;

/**
 * Unit tests for MergeFieldService merge-variable resolution.
 *
 * Focus areas (CA-41 / CA-42):
 *   - event_* fields resolve from the events row (name/date/time/location).
 *   - {{team_name}} resolves from an explicit team_id in the context.
 *   - {{team_name}} resolves from the event's team when only an event_id is
 *     supplied (events.team_id is the canonical event -> team link).
 *
 * Uses an in-memory SQLite database seeded with a minimal fixture. The service's
 * queries use portable SQL (LEFT JOIN / prepared statements) that runs the same
 * on SQLite and PostgreSQL. No production data or credentials are touched.
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
            CREATE TABLE events (
                id INTEGER PRIMARY KEY,
                team_id INTEGER,
                title TEXT,
                start_datetime TEXT,
                event_type TEXT,
                venue_id INTEGER,
                field_id INTEGER
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
        // Event 1 belongs to team 10, at venue 5 / field 7.
        $this->pdo->exec("INSERT INTO events (id, team_id, title, start_datetime, event_type, venue_id, field_id)
            VALUES (1, 10, 'Team Dinner', '2026-03-25 18:00:00', 'meeting', 5, 7)");
        // Event 2 belongs to team 10 but has no venue/field.
        $this->pdo->exec("INSERT INTO events (id, team_id, title, start_datetime, event_type, venue_id, field_id)
            VALUES (2, 10, 'Practice', '2026-04-01 17:30:00', 'practice', NULL, NULL)");
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

    public function testEventLocationResolvesFromVenueAndField(): void
    {
        $context = ['event_id' => 1, 'club_profile_id' => 100];
        $out = $this->svc->resolveVariables('Location: {{event_location}}', $context);
        $this->assertStringContainsString('Main Stadium', $out);
        $this->assertStringContainsString('Field A', $out);
        $this->assertStringContainsString('123 Main St', $out);
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

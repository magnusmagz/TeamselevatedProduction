<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use MergeFieldService;

require_once __DIR__ . '/../../lib/sms_merge.php';

/**
 * SMS merge-field resolution.
 *
 * Regression: SMS had no merge resolution on ANY path. `send-sms` passed the body
 * straight to the queue, and `send-broadcast` resolved only inside its email
 * branch — the SMS branch queued the raw body. Every template in the SMS library
 * uses merge tags, so families received the literal "{{athlete_first_name}}".
 *
 * The body must resolve PER RECIPIENT (each person's own name/team), which is why
 * it rides on the recipient as `_resolved_body` rather than being resolved once
 * for the batch.
 */
class SmsMergeFieldTest extends TestCase
{
    private PDO $pdo;
    private MergeFieldService $svc;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
             CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
             CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT);
             CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER, status TEXT);
             CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, primary_color TEXT, secondary_color TEXT);'
        );
        $this->pdo->exec("INSERT INTO athletes VALUES (1, 'Ava', 'Adams'), (2, 'Luis', 'Ramirez')");
        $this->pdo->exec("INSERT INTO guardians VALUES (5, 'Jane', 'Jones')");
        $this->pdo->exec("INSERT INTO teams VALUES (10, 'Salina Storm U12', NULL), (11, 'Hawks U16', NULL)");
        $this->pdo->exec(
            "INSERT INTO team_members VALUES (1, 10, 1, 'active'), (2, 11, 2, 'active')"
        );
        $this->pdo->exec("INSERT INTO club_profile VALUES (51, 'Central Kansas United', '#323c50', '#c9a96e')");

        $this->svc = new MergeFieldService($this->pdo);
    }

    private function base(): array
    {
        return ['user_id' => 99, 'club_profile_id' => 51, 'event_id' => null, 'team_id' => null];
    }

    /** Each recipient gets their OWN text, not one body shared across the batch. */
    public function testBodyResolvesPerRecipient(): void
    {
        $recipients = [
            ['type' => 'athlete', 'id' => 1, 'athlete_id' => 1, 'name' => 'Ava Adams', 'phone' => '+17855550001'],
            ['type' => 'athlete', 'id' => 2, 'athlete_id' => 2, 'name' => 'Luis Ramirez', 'phone' => '+17855550002'],
        ];

        [$out, $unresolved] = resolveSmsBodies(
            $recipients,
            'Reminder for {{athlete_first_name}} on {{team_name}}!',
            $this->svc,
            $this->base()
        );

        $this->assertSame([], $unresolved);
        $this->assertSame('Reminder for Ava on Salina Storm U12!', $out[0]['_resolved_body']);
        $this->assertSame('Reminder for Luis on Hawks U16!', $out[1]['_resolved_body']);
    }

    public function testGuardianRecipientResolvesTheirOwnName(): void
    {
        $recipients = [['type' => 'guardian', 'id' => 5, 'athlete_id' => 1, 'name' => 'Jane Jones', 'phone' => '+17855550003']];

        [$out, $unresolved] = resolveSmsBodies(
            $recipients,
            'Hi {{guardian_first_name}}, {{athlete_first_name}} has practice.',
            $this->svc,
            $this->base()
        );

        $this->assertSame([], $unresolved);
        $this->assertSame('Hi Jane, Ava has practice.', $out[0]['_resolved_body']);
    }

    public function testClubNameResolves(): void
    {
        $recipients = [['type' => 'athlete', 'id' => 1, 'athlete_id' => 1, 'name' => 'Ava', 'phone' => '+17855550001']];

        [$out] = resolveSmsBodies($recipients, 'From {{club_name}}', $this->svc, $this->base());

        $this->assertSame('From Central Kansas United', $out[0]['_resolved_body']);
    }

    /**
     * A tag nothing can fill must stop the send. A raw {{tag}} in a text cannot be
     * unsent, so this mirrors the send-email guard rather than shipping it.
     */
    public function testUnknownTagIsReportedSoTheSendCanBeStopped(): void
    {
        $recipients = [['type' => 'athlete', 'id' => 1, 'athlete_id' => 1, 'name' => 'Ava', 'phone' => '+17855550001']];

        [, $unresolved] = resolveSmsBodies($recipients, 'Hi {{playerName}} from {{club_name}}', $this->svc, $this->base());

        $this->assertSame(['{{playerName}}'], $unresolved);
    }

    /** A body with no tags is left exactly as typed, and costs no queries. */
    public function testPlainBodyIsUntouched(): void
    {
        $recipients = [['type' => 'athlete', 'id' => 1, 'athlete_id' => 1, 'name' => 'Ava', 'phone' => '+17855550001']];

        [$out, $unresolved] = resolveSmsBodies($recipients, 'Practice is cancelled.', $this->svc, $this->base());

        $this->assertSame([], $unresolved);
        $this->assertArrayNotHasKey('_resolved_body', $out[0]);
    }

    /** An explicit team in the context wins over the recipient's own roster row. */
    public function testExplicitTeamContextWins(): void
    {
        $recipients = [['type' => 'athlete', 'id' => 1, 'athlete_id' => 1, 'name' => 'Ava', 'phone' => '+17855550001']];
        $base = $this->base();
        $base['team_id'] = 11;

        [$out] = resolveSmsBodies($recipients, 'Go {{team_name}}', $this->svc, $base);

        $this->assertSame('Go Hawks U16', $out[0]['_resolved_body']);
    }
}

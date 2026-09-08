<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/event_field.php';

/**
 * A game's field (migration 100): the validation rule, the probe's degrade
 * path, the place label, and — by parsing, since legacy/events-gateway.php
 * authenticates at load — that both write branches validate and 422.
 */
class EventFieldTest extends TestCase
{
    private const GATEWAY = __DIR__ . '/../../legacy/events-gateway.php';
    private PDO $pdo;

    protected function setUp(): void
    {
        te_event_field_probe_override(null);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE venues (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE fields (id INTEGER PRIMARY KEY, venue_id INTEGER, name TEXT, active INTEGER, field_size TEXT);
            CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, venue_id INTEGER, field_id INTEGER, location TEXT);
            INSERT INTO venues VALUES (7, 'North Park'), (8, 'South Park');
            INSERT INTO fields VALUES (70, 7, 'Field 1', 1, '9v9'), (71, 7, 'Field 2', 0, '7v7'), (80, 8, 'Pitch A', 1, NULL);
        ");
    }

    protected function tearDown(): void
    {
        te_event_field_probe_override(null);
    }

    public function testNoFieldIsNotAnError(): void
    {
        foreach ([null, '', 0, '0'] as $raw) {
            $this->assertSame(['value' => null, 'error' => null], te_event_field_validate($this->pdo, $raw, 7));
        }
    }

    public function testFieldMustBelongToTheChosenVenue(): void
    {
        $r = te_event_field_validate($this->pdo, 80, 7);
        $this->assertNull($r['value']);
        $this->assertStringContainsString('not at the chosen facility', $r['error']);

        $r = te_event_field_validate($this->pdo, 70, 7);
        $this->assertSame(['value' => 70, 'error' => null], $r);
    }

    public function testInactiveFieldIsRefused(): void
    {
        $r = te_event_field_validate($this->pdo, 71, 7);
        $this->assertNull($r['value']);
        $this->assertStringContainsString('no longer active', $r['error']);
    }

    public function testFieldWithoutAVenueIsRefusedAndUnknownIdsAreRefused(): void
    {
        $this->assertNotNull(te_event_field_validate($this->pdo, 70, null)['error'], 'a field needs its venue in the same request');
        $this->assertNotNull(te_event_field_validate($this->pdo, 999, 7)['error']);
        $this->assertNotNull(te_event_field_validate($this->pdo, 'abc', 7)['error']);
    }

    public function testProbeDegradesToNullColumnsWhenTheMigrationIsNotApplied(): void
    {
        te_event_field_probe_override(false);
        $this->assertSame(', NULL AS field_id, NULL AS field_name, NULL AS field_size', te_event_field_select($this->pdo));
        $this->assertSame('', te_event_field_join($this->pdo));

        te_event_field_probe_override(true);
        $this->assertStringContainsString('e.field_id AS field_id', te_event_field_select($this->pdo));
        $this->assertStringContainsString('ef.name AS field_name', te_event_field_select($this->pdo));
        $this->assertStringContainsString('LEFT JOIN fields ef ON ef.id = e.field_id', te_event_field_join($this->pdo));

        // The degraded fragments are valid SQL together.
        te_event_field_probe_override(false);
        $sql = 'SELECT e.id' . te_event_field_select($this->pdo) . ' FROM calendar_events e' . te_event_field_join($this->pdo);
        $this->pdo->exec("INSERT INTO calendar_events VALUES (1, 7, 70, NULL)");
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['field_name']);
    }

    public function testSqliteProbeSeesTheColumnWhenItExists(): void
    {
        $this->assertTrue(te_event_field_column_present($this->pdo));
        $sql = 'SELECT e.id' . te_event_field_select($this->pdo) . ' FROM calendar_events e' . te_event_field_join($this->pdo);
        $this->pdo->exec("INSERT INTO calendar_events VALUES (1, 7, 70, NULL)");
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Field 1', $row['field_name']);
    }

    public function testPlaceLabelPrefersVenueAndField(): void
    {
        $this->assertSame('North Park · Field 1', te_event_place_label('North Park', 'Field 1', 'ignored'));
        $this->assertSame('North Park', te_event_place_label('North Park', null, 'ignored'));
        $this->assertSame('Away at Rivals', te_event_place_label(null, null, 'Away at Rivals'));
        $this->assertSame('North Park, Field 1', te_event_place_label('North Park', 'Field 1', null, ', '));
        $this->assertSame('', te_event_place_label(null, '', ''));
    }

    // ---- the gateway, by parsing ----

    private function branch(string $from, string $to): string
    {
        $src = file_get_contents(self::GATEWAY);
        $start = strpos($src, $from);
        $end = strpos($src, $to, $start);
        return substr($src, $start, $end - $start);
    }

    public function testBothWriteBranchesValidateTheFieldAgainstTheVenueAnd422(): void
    {
        $post = $this->branch("case 'POST':", "case 'PUT':");
        $put = $this->branch("case 'PUT':", "case 'DELETE':");
        foreach (['POST' => $post, 'PUT' => $put] as $name => $branch) {
            $this->assertStringContainsString('te_event_field_validate(', $branch, "$name validates the field");
            $pos = strpos($branch, 'te_event_field_validate(');
            $this->assertLessThan(strpos($branch, '$pdo->beginTransaction()'), $pos, "$name validates before the transaction opens");
            $after = substr($branch, $pos, 400);
            $this->assertStringContainsString('http_response_code(422)', $after, "$name answers 422 to a foreign or inactive field");
            $this->assertStringContainsString('te_event_field_available(', $branch, "$name tolerates the column being absent");
        }
        // PUT: an older bundle that never sends field_id must not keep a field from a venue it just changed.
        $this->assertStringContainsString("array_key_exists('field_id', \$data)", $put);
    }

    public function testReadsCarryTheFieldFromOneJoin(): void
    {
        $src = file_get_contents(self::GATEWAY);
        $get = substr($src, strpos($src, "case 'GET':"), strpos($src, "case 'POST':") - strpos($src, "case 'GET':"));
        $this->assertSame(2, substr_count($get, 'te_event_field_select('), 'single and list reads');
        $this->assertSame(2, substr_count($get, 'te_event_field_join('));
    }
}

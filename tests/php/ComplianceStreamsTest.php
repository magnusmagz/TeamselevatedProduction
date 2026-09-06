<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/compliance.php';
require_once __DIR__ . '/../../lib/compliance_reminders.php';
require_once __DIR__ . '/../../lib/compliance_streams.php';
require_once __DIR__ . '/ComplianceG7Fixture.php';

/**
 * GOTR G7 — admin-authored reminder streams.
 *
 * What is pinned, in order of how much damage the alternative does:
 *
 * - EXACTLY ONE STREAM APPLIES to a credential: the club's own, else the
 *   nearest ancestor org unit's, else the default 90/60/30/7. Steps are never
 *   merged across tiers, and deactivating a stream falls back to the next
 *   tier — never to silence.
 * - A STEP NEVER SENDS TWICE. The log is keyed on (credential, stream, step),
 *   an edit to the stream does not resend what is already logged, and a
 *   post-expiry step goes at most once.
 * - AN UNKNOWN MERGE TAG IS A 422 AT SAVE TIME, and an unresolved one at send
 *   time blocks that send and says so — a coach is never mailed `{{tag}}`.
 * - AUTHORING IS GATED ON THE TIER: a club admin attaches at their club, an
 *   org_admin at their unit, and nobody attaches anywhere else.
 */
class ComplianceStreamsTest extends TestCase
{
    use ComplianceG7Fixture;

    // ------------------------------------------------------------ validation

    public function testStepsAreNormalisedAndOrderedLargestOffsetFirst(): void
    {
        $result = te_compliance_stream_validate_steps([
            ['days_before' => '14', 'subject' => ' Two weeks ', 'body' => 'Hi {{first_name}}'],
            ['days_before' => 60, 'subject' => 'Sixty', 'body' => 'Body', 'channel' => 'email'],
            ['days_before' => -7, 'subject' => 'Late', 'body' => 'Body'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([60, 14, -7], array_column($result['steps'], 'days_before'));
        $this->assertSame('Two weeks', $result['steps'][1]['subject']);
        $this->assertSame('email', $result['steps'][0]['channel']);
    }

    public function testAnUnknownMergeTagIsRefusedAndNamed(): void
    {
        $result = te_compliance_stream_validate_steps([
            ['days_before' => 30, 'subject' => 'Hello {{last_name}}', 'body' => 'See {{portal}} and {{first_name}}'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_tag', $result['reason']);
        $this->assertSame(['last_name', 'portal'], $result['unknown_tags']);
        $this->assertStringContainsString('last_name', $result['error']);
    }

    public function testDuplicateOffsetsAndBlankCopyAreRefused(): void
    {
        $dup = te_compliance_stream_validate_steps([
            ['days_before' => 30, 'subject' => 'A', 'body' => 'a'],
            ['days_before' => 30, 'subject' => 'B', 'body' => 'b'],
        ]);
        $this->assertFalse($dup['ok']);
        $this->assertSame('duplicate_offset', $dup['reason']);

        $blank = te_compliance_stream_validate_steps([['days_before' => 30, 'subject' => '', 'body' => 'x']]);
        $this->assertFalse($blank['ok']);
        $this->assertSame('blank_subject', $blank['reason']);

        $blankBody = te_compliance_stream_validate_steps([['days_before' => 30, 'subject' => 'x', 'body' => '  ']]);
        $this->assertFalse($blankBody['ok']);
        $this->assertSame('blank_body', $blankBody['reason']);

        $empty = te_compliance_stream_validate_steps([]);
        $this->assertFalse($empty['ok']);
        $this->assertSame('no_steps', $empty['reason']);

        $sms = te_compliance_stream_validate_steps([['days_before' => 30, 'subject' => 'x', 'body' => 'y', 'channel' => 'sms']]);
        $this->assertFalse($sms['ok']);
        $this->assertSame('bad_channel', $sms['reason']);

        $far = te_compliance_stream_validate_steps([['days_before' => 4000, 'subject' => 'x', 'body' => 'y']]);
        $this->assertFalse($far['ok']);
        $this->assertSame('bad_offset', $far['reason']);
    }

    public function testRenderingReportsEveryTagItCouldNotFill(): void
    {
        $out = te_compliance_stream_render('Hi {{first_name}}, {{requirement_name}} on {{expires_on}}', [
            'first_name' => 'Hana', 'requirement_name' => 'SafeSport', 'expires_on' => null,
        ]);
        $this->assertSame('Hi Hana, SafeSport on {{expires_on}}', $out['text']);
        $this->assertSame(['expires_on'], $out['missing']);

        $full = te_compliance_stream_render('{{first_name}}', ['first_name' => 'Hana']);
        $this->assertSame([], $full['missing']);
    }

    // ------------------------------------------------------------ resolution

    /** Club > ancestor > default, and the nearest ancestor wins over a farther one. */
    public function testTheMostSpecificActiveStreamApplies(): void
    {
        $pdo = $this->g7pdo();
        $this->assertNull(te_compliance_stream_resolve($pdo, 10, 100), 'nothing authored: default applies');

        $national = $this->g7stream($pdo, 10, null, 1, $this->g7steps());
        $this->assertSame($national, te_compliance_stream_resolve($pdo, 10, 100)['id']);
        $this->assertSame('org_unit', te_compliance_stream_resolve($pdo, 10, 100)['tier']);

        $division = $this->g7stream($pdo, 10, null, 2, $this->g7steps());
        $this->assertSame($division, te_compliance_stream_resolve($pdo, 10, 100)['id'], 'the nearer ancestor wins');

        $club = $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->assertSame($club, te_compliance_stream_resolve($pdo, 10, 100)['id'], "the club's own wins");
        $this->assertSame('club', te_compliance_stream_resolve($pdo, 10, 100)['tier']);

        // A sibling council's club is untouched by club 100's stream and still
        // takes the division's.
        $this->assertSame($division, te_compliance_stream_resolve($pdo, 10, 101)['id']);

        // A club in another tree entirely gets the default.
        $this->assertNull(te_compliance_stream_resolve($pdo, 10, 102));
    }

    public function testDeactivatingFallsBackToTheNextTierNeverToSilence(): void
    {
        $pdo = $this->g7pdo();
        $division = $this->g7stream($pdo, 10, null, 2, $this->g7steps());
        $club = $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->assertSame($club, te_compliance_stream_resolve($pdo, 10, 100)['id']);

        te_compliance_stream_set_active($pdo, $club, false);
        $this->assertSame($division, te_compliance_stream_resolve($pdo, 10, 100)['id']);

        te_compliance_stream_set_active($pdo, $division, false);
        $this->assertNull(te_compliance_stream_resolve($pdo, 10, 100), 'no active stream: the default cadence takes over');

        // The describe shape the panel renders from says which one it is.
        $described = te_compliance_stream_describe($pdo, 10, 100);
        $this->assertSame('default', $described['applies']);
        $this->assertNotNull($described['own'], "the club's inactive stream is still listed so it can be reactivated");
        $this->assertFalse($described['own']['active']);
    }

    public function testDescribeNamesTheTierAStreamIsInheritedFrom(): void
    {
        $pdo = $this->g7pdo();
        $this->g7stream($pdo, 10, null, 2, $this->g7steps());
        $described = te_compliance_stream_describe($pdo, 10, 100);
        $this->assertSame('inherited', $described['applies']);
        $this->assertSame('West', $described['inherited_from']['name']);
        $this->assertSame('division', $described['inherited_from']['type']);
        $this->assertNull($described['own']);

        $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->assertSame('own', te_compliance_stream_describe($pdo, 10, 100)['applies']);
    }

    /** Steps are never merged: the club's 14-day step does not add to the division's list. */
    public function testStepsAreNeverMergedAcrossTiers(): void
    {
        $pdo = $this->g7pdo();
        $this->g7stream($pdo, 10, null, 2, [['days_before' => 45, 'subject' => 'D', 'body' => 'd']]);
        $this->g7stream($pdo, 10, 100, null, [['days_before' => 14, 'subject' => 'C', 'body' => 'c']]);
        $resolved = te_compliance_stream_resolve($pdo, 10, 100);
        $this->assertSame([14], array_column($resolved['steps'], 'days_before'));
    }

    // ------------------------------------------------------------ authoring

    public function testSaveRefusesAnUnknownTagAndABadTier(): void
    {
        $pdo = $this->g7pdo();
        $bad = te_compliance_stream_save($pdo, [
            'requirement_id' => 10, 'club_profile_id' => 100,
            'steps' => [['days_before' => 30, 'subject' => '{{nope}}', 'body' => 'x']],
        ], 90);
        $this->assertFalse($bad['ok']);
        $this->assertSame('unknown_tag', $bad['reason']);

        $both = te_compliance_stream_save($pdo, [
            'requirement_id' => 10, 'club_profile_id' => 100, 'org_unit_id' => 2, 'steps' => $this->g7steps(),
        ], 90);
        $this->assertFalse($both['ok']);
        $this->assertSame('one_tier', $both['reason']);

        // Requirement 13 belongs to club 100 and is not inherited by club 101.
        $foreign = te_compliance_stream_save($pdo, [
            'requirement_id' => 13, 'club_profile_id' => 101, 'steps' => $this->g7steps(),
        ], 90);
        $this->assertFalse($foreign['ok']);
        $this->assertSame('requirement_not_at_tier', $foreign['reason']);

        // Requirement 13 (a club's) cannot get a stream at the division.
        $up = te_compliance_stream_save($pdo, [
            'requirement_id' => 13, 'org_unit_id' => 2, 'steps' => $this->g7steps(),
        ], 90);
        $this->assertFalse($up['ok']);
        $this->assertSame('requirement_not_at_tier', $up['reason']);
    }

    public function testSaveCreatesOnceThenUpdatesTheSameTierRow(): void
    {
        $pdo = $this->g7pdo();
        $first = te_compliance_stream_save($pdo, [
            'requirement_id' => 10, 'club_profile_id' => 100, 'steps' => $this->g7steps(), 'active' => true,
        ], 90);
        $this->assertTrue($first['ok'], $first['error'] ?? '');

        $second = te_compliance_stream_save($pdo, [
            'id' => $first['id'], 'steps' => [['days_before' => 3, 'subject' => 'S', 'body' => 'b']],
        ], 90);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM compliance_reminder_streams')->fetchColumn());
        $this->assertSame([3], array_column(te_compliance_stream_get($pdo, $first['id'])['steps'], 'days_before'));

        // A second CREATE at the same tier for the same requirement is the same
        // row, not a duplicate that would make the resolution rule ambiguous.
        $third = te_compliance_stream_save($pdo, [
            'requirement_id' => 10, 'club_profile_id' => 100, 'steps' => $this->g7steps(),
        ], 90);
        $this->assertSame($first['id'], $third['id']);
    }

    /** The tier check for authoring: club admin at their club, org_admin at their unit, nobody else. */
    public function testAuthoringStandingIsTheTiersOwn(): void
    {
        $pdo = $this->g7pdo();
        $clubAdmin = $this->g7auth(70, false, [100]);
        $divisionAdmin = $this->g7auth(90);
        $nobody = $this->g7auth(71);

        $this->assertTrue(te_compliance_stream_can_author($pdo, $clubAdmin, 100, 0));
        $this->assertFalse(te_compliance_stream_can_author($pdo, $clubAdmin, 101, 0));
        $this->assertFalse(te_compliance_stream_can_author($pdo, $clubAdmin, 0, 3), 'a club admin does not author at the council tier');

        $this->assertTrue(te_compliance_stream_can_author($pdo, $divisionAdmin, 0, 2));
        $this->assertTrue(te_compliance_stream_can_author($pdo, $divisionAdmin, 0, 3), 'standing inherits down');
        $this->assertTrue(te_compliance_stream_can_author($pdo, $divisionAdmin, 100, 0), 'a division admin administers its councils');
        $this->assertFalse(te_compliance_stream_can_author($pdo, $divisionAdmin, 0, 1), 'and never up');

        $this->assertFalse(te_compliance_stream_can_author($pdo, $nobody, 100, 0));
        $this->assertFalse(te_compliance_stream_can_author($pdo, $nobody, 0, 3));
        $this->assertFalse(te_compliance_stream_can_author($pdo, $nobody, 0, 0));
    }

    // ------------------------------------------------------------- dispatch

    private function collectingMailer(array &$sent): Closure
    {
        return function (array $envelope, array $copy, array $person) use (&$sent): bool {
            $sent[] = [
                'user' => $envelope['user_id'], 'stream' => $envelope['stream_id'] ?? null,
                'days' => $envelope['threshold'], 'subject' => $copy['subject'], 'body' => $copy['body'] ?? null,
            ];
            return true;
        };
    }

    public function testTheStreamStepIsPickedByTheSmallestEligibleOffset(): void
    {
        $steps = $this->g7steps(); // 60, 14, -7
        $this->assertSame(60, te_compliance_stream_step_due($steps, 60, [])['days_before']);
        $this->assertSame(60, te_compliance_stream_step_due($steps, 30, [])['days_before']);
        $this->assertSame(14, te_compliance_stream_step_due($steps, 14, [])['days_before']);
        $this->assertSame(14, te_compliance_stream_step_due($steps, 0, [])['days_before']);
        $this->assertNull(te_compliance_stream_step_due($steps, -3, []), 'between expiry and the post-expiry step: nothing');
        $this->assertSame(-7, te_compliance_stream_step_due($steps, -7, [])['days_before']);
        $this->assertSame(-7, te_compliance_stream_step_due($steps, -40, [])['days_before']);
        $this->assertNull(te_compliance_stream_step_due($steps, 61, []));
        // Already sent: the step is skipped, and a larger one is never revisited.
        $this->assertNull(te_compliance_stream_step_due($steps, 30, [60 => true]));
        $this->assertNull(te_compliance_stream_step_due($steps, -40, [-7 => true]));
    }

    public function testACredentialUnderAStreamTakesTheStreamNotTheDefault(): void
    {
        $pdo = $this->g7pdo();
        $streamId = $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->g7verified($pdo, 50, 10, '2026-10-06'); // 30 days out
        $sent = [];

        $result = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $this->collectingMailer($sent)]);
        $this->assertSame(1, $result['sent'], implode("\n", $result['errors']));
        $this->assertSame($streamId, $sent[0]['stream']);
        $this->assertSame(60, $sent[0]['days'], 'at 30 days out the 60-day step is the smallest eligible one');
        $this->assertSame('SafeSport expires in 30 days', $sent[0]['subject']);
        $this->assertStringContainsString('Hi Hana, your SafeSport for GOTR Kansas expires on October 6, 2026.', $sent[0]['body']);
        $this->assertStringNotContainsString('{{', $sent[0]['body']);

        // Logged under the stream, and the default stream sent nothing.
        $rows = $pdo->query('SELECT stream_id, days_before FROM compliance_reminder_log')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame($streamId, (int) $rows[0]['stream_id']);
        $this->assertSame(60, (int) $rows[0]['days_before']);
    }

    public function testAStepNeverSendsTwiceEvenAcrossAnEditAndTheNextOneStillDoes(): void
    {
        $pdo = $this->g7pdo();
        $streamId = $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->g7verified($pdo, 50, 10, '2026-10-06');
        $sent = [];
        $mailer = $this->collectingMailer($sent);

        te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $mailer]);
        foreach (['2026-09-06', '2026-09-07', '2026-09-20'] as $day) {
            $again = te_compliance_dispatch_reminders($pdo, ['today' => $day, 'mailer' => $mailer]);
            $this->assertSame(0, $again['sent'], "re-sent on $day");
        }
        $this->assertCount(1, $sent);

        // An edit to the copy does not resend the step already logged.
        te_compliance_stream_save($pdo, ['id' => $streamId, 'steps' => [
            ['days_before' => 60, 'subject' => 'Edited', 'body' => 'Edited {{first_name}}'],
            ['days_before' => 14, 'subject' => 'Two weeks', 'body' => 'Soon {{first_name}}'],
        ]], 90);
        $this->assertSame(0, te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-08', 'mailer' => $mailer])['sent']);

        // Crossing into the 14-day step does send, once.
        $this->assertSame(1, te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-25', 'mailer' => $mailer])['sent']);
        $this->assertSame(0, te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-26', 'mailer' => $mailer])['sent']);
        $this->assertSame([60, 14], array_column($sent, 'days'));
    }

    public function testAPostExpiryStepSendsOnceAndOnlyAfterExpiry(): void
    {
        $pdo = $this->g7pdo();
        $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        // Expired 10 days ago, already swept to 'expired'.
        $this->g7verified($pdo, 50, 10, '2026-08-27', 'expired');
        $sent = [];
        $mailer = $this->collectingMailer($sent);

        $this->assertSame(1, te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $mailer])['sent']);
        $this->assertSame(-7, $sent[0]['days']);
        $this->assertStringContainsString('expired 10 days ago', $sent[0]['body']);

        foreach (['2026-09-07', '2026-10-06', '2027-01-01'] as $day) {
            $this->assertSame(0, te_compliance_dispatch_reminders($pdo, ['today' => $day, 'mailer' => $mailer])['sent'], $day);
        }

        // Not yet 7 days past: nothing.
        $pdo2 = $this->g7pdo();
        $this->g7stream($pdo2, 10, 100, null, $this->g7steps());
        $this->g7verified($pdo2, 50, 10, '2026-09-03', 'expired');
        $none = [];
        $this->assertSame(0, te_compliance_dispatch_reminders($pdo2, ['today' => '2026-09-06', 'mailer' => $this->collectingMailer($none)])['sent']);
    }

    /** The default cadence has no post-expiry step and must not grow one by accident. */
    public function testTheDefaultStreamStillNeverMailsAboutAnExpiredCredential(): void
    {
        $pdo = $this->g7pdo();
        $this->g7verified($pdo, 50, 10, '2026-08-27', 'expired');
        $sent = [];
        $this->assertSame(0, te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $this->collectingMailer($sent)])['sent']);
    }

    public function testDeactivatingMidWayFallsBackToTheDefaultCadenceWithoutResending(): void
    {
        $pdo = $this->g7pdo();
        $streamId = $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->g7verified($pdo, 50, 10, '2026-10-06');
        $sent = [];
        $mailer = $this->collectingMailer($sent);

        te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $mailer]);
        te_compliance_stream_set_active($pdo, $streamId, false);

        $result = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-07', 'mailer' => $mailer]);
        $this->assertSame(1, $result['sent'], 'the default 30-day threshold now applies');
        $this->assertNull($sent[1]['stream']);
        $this->assertSame(30, $sent[1]['days']);
    }

    public function testAnUnresolvedTagBlocksThatSendAndSaysSo(): void
    {
        $pdo = $this->g7pdo();
        $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->g7verified($pdo, 58, 10, '2026-10-06'); // no first name
        $sent = [];

        $result = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $this->collectingMailer($sent)]);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame([], $sent, 'nothing reached the mailer');
        $this->assertStringContainsString('first_name', implode("\n", $result['errors']));
        // The claim is released so a fix to the record is picked up next tick.
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM compliance_reminder_log')->fetchColumn());
    }

    public function testAStreamOnAnAncestorAppliesToEveryClubBeneathIt(): void
    {
        $pdo = $this->g7pdo();
        $streamId = $this->g7stream($pdo, 10, null, 2, $this->g7steps());
        $this->g7verified($pdo, 50, 10, '2026-10-06'); // Kansas
        $this->g7verified($pdo, 56, 10, '2026-10-06'); // California
        $this->g7verified($pdo, 57, 10, '2026-10-06'); // other tree — but req 10 is not theirs anyway
        $sent = [];

        $result = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $this->collectingMailer($sent)]);
        $this->assertSame(2, $result['sent'], implode("\n", $result['errors']));
        $this->assertSame([$streamId, $streamId], array_column($sent, 'stream'));
        $this->assertSame([50, 56], array_column($sent, 'user'));
    }

    public function testTheSwitchesStillGateStreamSends(): void
    {
        $pdo = $this->g7pdo();
        $this->g7stream($pdo, 10, 100, null, $this->g7steps());
        $this->g7verified($pdo, 50, 10, '2026-10-06');
        putenv('TE_FEATURE_COMPLIANCE_REMINDERS=off');
        $_ENV['TE_FEATURE_COMPLIANCE_REMINDERS'] = 'off';
        try {
            $sent = [];
            $result = te_compliance_dispatch_reminders($pdo, ['today' => '2026-09-06', 'mailer' => $this->collectingMailer($sent)]);
            $this->assertSame(0, $result['sent']);
            $this->assertContains('feature_disabled: COMPLIANCE_REMINDERS', $result['errors']);
        } finally {
            putenv('TE_FEATURE_COMPLIANCE_REMINDERS');
            unset($_ENV['TE_FEATURE_COMPLIANCE_REMINDERS']);
        }
    }

    /** The stream send path brands as the club, through lib/Email.php, and nothing else. */
    public function testStreamStepsSendThroughTheBrandedTransactionalPath(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/compliance_reminders.php');
        $this->assertStringContainsString('sendComplianceStreamStep', $src);
        $this->assertFalse(strpos($src, 'new EmailSendService'), 'never construct EmailSendService here');
        $this->assertFalse(strpos($src, 'EmailSendService('), 'never call EmailSendService here');
        $this->assertStringContainsString("te_feature_enabled('COMPLIANCE_REMINDERS')", $src);
    }

    // -------------------------------------------------------------- gateway

    public function testTheStreamsGatewayAuthenticatesGatesAndAudits(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/compliance-streams.php');
        $auth = strpos($src, 'AuthMiddleware::requireAuth()');
        $this->assertNotFalse($auth);
        $flag = strpos($src, "if (!te_feature_enabled('COMPLIANCE'))");
        $this->assertNotFalse($flag);
        $this->assertLessThan($flag, $auth);
        foreach (['for-requirement', 'save', 'set-active', 'preview'] as $action) {
            $offset = strpos($src, "\$action === '$action'");
            $this->assertNotFalse($offset, "action $action missing");
            $this->assertLessThan($offset, $flag, "$action dispatches before the switch");
        }
        $this->assertStringContainsString('te_compliance_stream_can_author(', $src);
        foreach (['compliance_stream_saved', 'compliance_stream_activated', 'compliance_stream_deactivated'] as $a) {
            $this->assertStringContainsString("'$a'", $src, "no audit row for $a");
        }
        // The unknown-tag refusal is a 422, so the form can show it as a validation error.
        $this->assertStringContainsString('422', $src);
    }
}

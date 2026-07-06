<?php
/**
 * Unit test for te_expand_recurrence() / te_recurrence_label() — the
 * recurring-event date expansion behind legacy/events-gateway.php POST.
 * Pure functions, no DB.
 *
 * Run:  php tests/php/event-recurrence-test.php
 * Exit: 0 = all passed, 1 = a check failed.
 */

require_once __DIR__ . '/../../lib/event_recurrence.php';

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $failures++; }
}

// 2026-07-10 is a Friday.

echo "Weekly — every Friday, 4 times\n";
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [5], 'end_type' => 'count', 'count' => 4]);
check('4 dates', count($d) === 4);
check('consecutive Fridays', $d === ['2026-07-10', '2026-07-17', '2026-07-24', '2026-07-31']);

echo "Every other Friday until a date\n";
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'weekly', 'interval' => 2, 'weekdays' => [5], 'end_type' => 'date', 'end_date' => '2026-08-31']);
check('alternating Fridays', $d === ['2026-07-10', '2026-07-24', '2026-08-07', '2026-08-21']);

echo "Weekly defaults to the start date's weekday\n";
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'weekly', 'end_type' => 'count', 'count' => 2]);
check('defaults to Friday', $d === ['2026-07-10', '2026-07-17']);

echo "Weekly on Tue+Thu\n";
$d = te_expand_recurrence('2026-07-07', ['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [2, 4], 'end_type' => 'count', 'count' => 4]);
check('Tue/Thu pattern', $d === ['2026-07-07', '2026-07-09', '2026-07-14', '2026-07-16']);

echo "Daily\n";
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'daily', 'end_type' => 'count', 'count' => 3]);
check('3 consecutive days', $d === ['2026-07-10', '2026-07-11', '2026-07-12']);

echo "Every weekday skips the weekend\n";
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'weekday', 'end_type' => 'count', 'count' => 3]);
check('Fri, Mon, Tue', $d === ['2026-07-10', '2026-07-13', '2026-07-14']);

echo "Monthly on the same date\n";
$d = te_expand_recurrence('2026-07-15', ['frequency' => 'monthly_date', 'end_type' => 'count', 'count' => 3]);
check('15th each month', $d === ['2026-07-15', '2026-08-15', '2026-09-15']);

echo "Monthly on day 31 skips short months\n";
$d = te_expand_recurrence('2026-07-31', ['frequency' => 'monthly_date', 'end_type' => 'count', 'count' => 3]);
check('Jul 31, Aug 31, Oct 31 (no Sep 31)', $d === ['2026-07-31', '2026-08-31', '2026-10-31']);

echo "Monthly on the 2nd Friday\n";
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'monthly_weekday', 'end_type' => 'count', 'count' => 3]);
check('2nd Friday each month', $d === ['2026-07-10', '2026-08-14', '2026-09-11']);

echo "Caps\n";
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'daily', 'end_type' => 'count', 'count' => 500]);
check('occurrences capped at 52', count($d) === TE_RECURRENCE_MAX_OCCURRENCES);
$d = te_expand_recurrence('2026-07-10', ['frequency' => 'monthly_date', 'end_type' => 'date', 'end_date' => '2036-01-01']);
check('horizon capped at ~1 year', count($d) <= 13);

echo "Validation\n";
try {
    te_expand_recurrence('2026-07-10', ['frequency' => 'yearly', 'end_type' => 'count', 'count' => 2]);
    check('invalid frequency throws', false);
} catch (InvalidArgumentException $e) {
    check('invalid frequency throws', true);
}
try {
    te_expand_recurrence('2026-07-10', ['frequency' => 'weekly', 'end_type' => 'date', 'end_date' => '2026-07-01']);
    check('end date before start throws', false);
} catch (InvalidArgumentException $e) {
    check('end date before start throws', true);
}

echo "RRULE round-trip — every repeat shape must reproduce our expansion\n";
$shapes = [
    ['2026-07-10', ['frequency' => 'daily', 'end_type' => 'count', 'count' => 5], 'daily'],
    ['2026-07-10', ['frequency' => 'weekday', 'end_type' => 'count', 'count' => 8], 'weekday'],
    ['2026-07-10', ['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [5], 'end_type' => 'count', 'count' => 4], 'weekly Fri'],
    ['2026-07-10', ['frequency' => 'weekly', 'interval' => 2, 'weekdays' => [5], 'end_type' => 'count', 'count' => 6], 'every other Fri'],
    ['2026-07-07', ['frequency' => 'weekly', 'interval' => 2, 'weekdays' => [2, 4], 'end_type' => 'count', 'count' => 7], 'biweekly Tue+Thu'],
    ['2026-07-08', ['frequency' => 'weekly', 'interval' => 3, 'weekdays' => [1, 3, 5], 'end_type' => 'count', 'count' => 9], 'every 3 weeks Mo/We/Fr'],
    ['2026-07-15', ['frequency' => 'monthly_date', 'end_type' => 'count', 'count' => 6], 'monthly 15th'],
    ['2026-07-31', ['frequency' => 'monthly_date', 'end_type' => 'count', 'count' => 5], 'monthly 31st (skips short months)'],
    ['2026-07-10', ['frequency' => 'monthly_weekday', 'end_type' => 'count', 'count' => 6], '2nd Friday'],
    ['2026-07-29', ['frequency' => 'monthly_weekday', 'end_type' => 'count', 'count' => 4], '5th Wednesday (skips most months)'],
];
foreach ($shapes as [$startDate, $rec, $label]) {
    $expanded = te_expand_recurrence($startDate, $rec);
    $rrule = te_recurrence_rrule($startDate, $rec, count($expanded));
    $roundTrip = $rrule !== null ? te_expand_rrule($startDate, $rrule) : [];
    check("round-trip: {$label}", $roundTrip === $expanded);
}

echo "RRULE strings\n";
check('every-other-Friday rrule', te_recurrence_rrule('2026-07-10', ['frequency' => 'weekly', 'interval' => 2, 'weekdays' => [5]], 4) === 'FREQ=WEEKLY;WKST=MO;INTERVAL=2;BYDAY=FR;COUNT=4');
check('2nd-Friday rrule', te_recurrence_rrule('2026-07-10', ['frequency' => 'monthly_weekday'], 3) === 'FREQ=MONTHLY;BYDAY=2FR;COUNT=3');
check('weekday rrule', te_recurrence_rrule('2026-07-10', ['frequency' => 'weekday'], 8) === 'FREQ=WEEKLY;WKST=MO;BYDAY=MO,TU,WE,TH,FR;COUNT=8');
check('invalid frequency -> null', te_recurrence_rrule('2026-07-10', ['frequency' => 'nope'], 3) === null);

echo "Labels\n";
$label = te_recurrence_label('2026-07-10', ['frequency' => 'weekly', 'interval' => 2, 'weekdays' => [5]], 4);
check('every-2-weeks label', $label === 'Every 2 weeks on Fri · 4 events');
$label = te_recurrence_label('2026-07-10', ['frequency' => 'monthly_weekday'], 3);
check('nth-weekday label', $label === 'Monthly on the 2nd Friday · 3 events');

echo $failures === 0 ? "\nALL CHECKS PASSED\n" : "\n$failures CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);

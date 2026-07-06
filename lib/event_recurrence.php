<?php
/**
 * Recurring-event date expansion.
 *
 * Occurrences are materialized at creation time (legacy/events-gateway.php
 * POST): one calendar_events row per date, tied together by a
 * recurrence_group_id. These helpers are pure (no DB) so they can be
 * unit-tested directly.
 *
 * Recurrence shape (from the Add Event form):
 *   frequency: daily | weekday | weekly | monthly_date | monthly_weekday
 *   interval:  1..4        (weeks between occurrences; weekly only)
 *   weekdays:  int[] 0-6   (0=Sun .. 6=Sat; weekly only, defaults to the
 *                           start date's weekday)
 *   end_type:  date | count
 *   end_date:  Y-m-d       (when end_type=date)
 *   count:     1..52       (when end_type=count)
 */

const TE_RECURRENCE_MAX_OCCURRENCES = 52;
const TE_RECURRENCE_MAX_DAYS = 366;

if (!function_exists('te_expand_recurrence')) {
    /**
     * Expand a recurrence rule into a list of Y-m-d dates, starting at (and
     * including) $startDate when it matches the rule. Result is capped at
     * TE_RECURRENCE_MAX_OCCURRENCES dates within TE_RECURRENCE_MAX_DAYS days.
     *
     * @param string $startDate Y-m-d first (anchor) date
     * @param array  $rec       recurrence config (see file docblock)
     * @return string[] Y-m-d dates, ascending
     * @throws InvalidArgumentException on an invalid rule
     */
    function te_expand_recurrence(string $startDate, array $rec): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        if (!$start) {
            throw new InvalidArgumentException('Invalid start date');
        }

        $frequency = $rec['frequency'] ?? null;
        $validFrequencies = ['daily', 'weekday', 'weekly', 'monthly_date', 'monthly_weekday'];
        if (!in_array($frequency, $validFrequencies, true)) {
            throw new InvalidArgumentException('Invalid recurrence frequency');
        }

        $interval = max(1, min(4, (int) ($rec['interval'] ?? 1)));

        $weekdays = [];
        if ($frequency === 'weekly') {
            foreach ((array) ($rec['weekdays'] ?? []) as $d) {
                $d = (int) $d;
                if ($d >= 0 && $d <= 6) {
                    $weekdays[$d] = true;
                }
            }
            if (empty($weekdays)) {
                $weekdays[(int) $start->format('w')] = true;
            }
        }

        $endType = ($rec['end_type'] ?? 'count') === 'date' ? 'date' : 'count';
        $maxCount = TE_RECURRENCE_MAX_OCCURRENCES;
        $endDate = null;
        if ($endType === 'date') {
            $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($rec['end_date'] ?? ''));
            if (!$endDate || $endDate < $start) {
                throw new InvalidArgumentException('Recurrence end date must be on or after the start date');
            }
        } else {
            $maxCount = max(1, min(TE_RECURRENCE_MAX_OCCURRENCES, (int) ($rec['count'] ?? 0)));
        }

        $horizon = $start->modify('+' . TE_RECURRENCE_MAX_DAYS . ' days');
        if ($endDate === null || $endDate > $horizon) {
            $endDate = $horizon;
        }

        // Anchors for the alignment math.
        $startWeekMonday = $start->modify('monday this week'); // for weekly interval alignment
        $dayOfMonth = (int) $start->format('j');               // for monthly_date
        $nthWeekday = intdiv($dayOfMonth - 1, 7) + 1;          // for monthly_weekday (1st..5th)
        $weekdayOfStart = (int) $start->format('w');

        $dates = [];
        for ($d = $start; $d <= $endDate && count($dates) < $maxCount; $d = $d->modify('+1 day')) {
            $dow = (int) $d->format('w');
            $matches = false;

            switch ($frequency) {
                case 'daily':
                    $matches = true;
                    break;
                case 'weekday':
                    $matches = $dow >= 1 && $dow <= 5;
                    break;
                case 'weekly':
                    if (isset($weekdays[$dow])) {
                        $weeksSinceStart = intdiv(
                            (int) $startWeekMonday->diff($d->modify('monday this week'))->format('%a'),
                            7
                        );
                        $matches = ($weeksSinceStart % $interval) === 0;
                    }
                    break;
                case 'monthly_date':
                    $matches = (int) $d->format('j') === $dayOfMonth;
                    break;
                case 'monthly_weekday':
                    $matches = $dow === $weekdayOfStart
                        && (intdiv((int) $d->format('j') - 1, 7) + 1) === $nthWeekday;
                    break;
            }

            if ($matches) {
                $dates[] = $d->format('Y-m-d');
            }
        }

        return $dates;
    }
}

if (!function_exists('te_recurrence_label')) {
    /**
     * Human-readable summary of a recurrence rule, stored on each occurrence
     * (calendar_events.recurrence_rule) for display.
     */
    function te_recurrence_label(string $startDate, array $rec, int $occurrences): string
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $interval = max(1, min(4, (int) ($rec['interval'] ?? 1)));

        switch ($rec['frequency'] ?? '') {
            case 'daily':
                $base = 'Daily';
                break;
            case 'weekday':
                $base = 'Every weekday (Mon-Fri)';
                break;
            case 'weekly':
                $days = [];
                foreach ((array) ($rec['weekdays'] ?? []) as $d) {
                    $d = (int) $d;
                    if ($d >= 0 && $d <= 6) {
                        $days[$d] = $dayNames[$d];
                    }
                }
                if (empty($days) && $start) {
                    $days[(int) $start->format('w')] = $dayNames[(int) $start->format('w')];
                }
                ksort($days);
                $dayList = implode(', ', $days);
                $base = $interval === 1
                    ? "Weekly on {$dayList}"
                    : "Every {$interval} weeks on {$dayList}";
                break;
            case 'monthly_date':
                $base = $start ? ('Monthly on day ' . $start->format('j')) : 'Monthly';
                break;
            case 'monthly_weekday':
                if ($start) {
                    $nth = intdiv((int) $start->format('j') - 1, 7) + 1;
                    $ordinals = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'];
                    $base = 'Monthly on the ' . $ordinals[$nth] . ' ' . $start->format('l');
                } else {
                    $base = 'Monthly';
                }
                break;
            default:
                $base = 'Repeats';
        }

        return $base . ' · ' . $occurrences . ' event' . ($occurrences === 1 ? '' : 's');
    }
}

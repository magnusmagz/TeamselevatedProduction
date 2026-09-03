<?php
/**
 * Building the compliance CSV: the sheet, the cap, the notice, the filename
 * (GOTR G4).
 *
 * Split out of api/compliance-export.php for the same reason lib/roster_export.php
 * is split out of api/roster-export.php: the endpoint emits headers and exits, so
 * it cannot be required by a test, and everything it decides has to live
 * somewhere that can. The endpoint keeps the authorization and the headers; this
 * file keeps the answer.
 */

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/compliance_origin.php';

/**
 * The most rows one file carries. Provisional, set 2026-09-02.
 *
 * A row is one (person × requirement), so a 300-coach council with five
 * inherited requirements is 1,500 rows and WILL be truncated — which is the
 * point of reporting it rather than quietly shipping a short file. Raising it is
 * a decision about how large a CSV a council's spreadsheet can open, not a
 * number to bump because somebody hit it.
 */
const TE_COMPLIANCE_EXPORT_MAX_ROWS = 1000;

/** The filters the report accepts. Validated, never defaulted — see the endpoint. */
const TE_COMPLIANCE_EXPORT_FILTERS = ['compliant', 'expiring', 'expired', 'missing'];

/**
 * One row per (person × requirement).
 *
 * Not one row per person with a column per requirement: that shape would have to
 * be rebuilt every time a council adds a rule, and two councils inheriting
 * different sets could not share a file layout. Long-form sorts, filters and
 * pivots in the tool the reader already has.
 *
 * ⚠️ The count keeps going past the cap. `total_rows` is what the club actually
 * has, `rows` is what fits; the notice needs both, and a cap that stopped
 * counting could only say "1000" — which is indistinguishable from a club with
 * exactly 1000 rows.
 *
 * @return array{headers: string[], rows: array<int, array>, people: int,
 *               total_rows: int, omitted_rows: int}
 */
function te_compliance_export_sheet(PDO $pdo, int $clubId, string $filter, string $today): array
{
    $headers = [
        'Last name', 'First name', 'Email', 'Staff roles',
        'Requirement', 'Required by', 'Category', 'Required',
        'Status', 'Completed', 'Expires', 'Days to expiry', 'Recorded via',
    ];

    $rows = [];
    $people = 0;
    $totalRows = 0;

    foreach (te_compliance_club_staff($pdo, $clubId) as $person) {
        $status = te_compliance_status($pdo, (int) $person['user_id'], $clubId, $today);
        $rollup = $status['rollup'];

        // The same predicate the club-status screen filters on, so the file
        // matches the page it was downloaded from. A filter that disagreed with
        // the screen would be reported as data loss.
        $keep = match ($filter) {
            'compliant' => $rollup['compliant'],
            'expiring'  => $rollup['expiring_30'] > 0,
            'expired'   => $rollup['expired'] > 0,
            'missing'   => $rollup['missing'] > 0,
            default     => true,
        };
        if (!$keep) {
            continue;
        }

        $people++;
        $staffRoles = implode(', ', te_compliance_staff_roles($pdo, (int) $person['user_id'], $clubId));
        $decorated = te_compliance_decorate_origins(
            $pdo,
            array_map(static fn (array $r): array => $r['requirement'], $status['requirements']),
            $clubId
        );

        foreach ($status['requirements'] as $index => $row) {
            $totalRows++;
            if (count($rows) >= TE_COMPLIANCE_EXPORT_MAX_ROWS) {
                continue;
            }
            $requirement = $decorated[$index] ?? $row['requirement'];
            $rows[] = [
                (string) ($person['last_name'] ?? ''),
                (string) ($person['first_name'] ?? ''),
                (string) ($person['email'] ?? ''),
                $staffRoles,
                (string) $requirement['name'],
                (string) ($requirement['origin']['label'] ?? 'Inherited'),
                (string) ($requirement['kind'] ?? 'custom'),
                $requirement['required'] ? 'Yes' : 'No',
                (string) $row['status'],
                // Emitted as the stored 'YYYY-MM-DD' and never parsed — this path
                // has no timezone behaviour to get wrong, and a spreadsheet sorts
                // ISO dates correctly as text.
                (string) ($row['completed_at'] ?? ''),
                (string) ($row['expires_at'] ?? ''),
                $row['days_to_expiry'] === null ? '' : (string) $row['days_to_expiry'],
                (string) ($row['source'] ?? ''),
            ];
        }
    }

    return [
        'headers'      => $headers,
        'rows'         => $rows,
        'people'       => $people,
        'total_rows'   => $totalRows,
        'omitted_rows' => max(0, $totalRows - count($rows)),
    ];
}

/** The one sentence that says the file is short, or null when it is complete. */
function te_compliance_export_truncation_notice(array $sheet): ?string
{
    if (($sheet['omitted_rows'] ?? 0) <= 0) {
        return null;
    }
    return sprintf(
        '%d of %d rows were left out (the file is capped at %d rows). Filter the report and download again to see the rest.',
        $sheet['omitted_rows'],
        $sheet['total_rows'],
        TE_COMPLIANCE_EXPORT_MAX_ROWS
    );
}

/**
 * A filename carrying the club, the filter and the date it was taken.
 *
 * ⚠️ The club name is club-supplied text going into a Content-Disposition
 * header. Everything outside [A-Za-z0-9] becomes a hyphen, so a newline in a
 * club name cannot inject a response header and a quote cannot break out of the
 * filename="…" attribute.
 */
function te_compliance_export_filename(string $clubName, string $filter, string $today): string
{
    $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $clubName));
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'club';
    }
    $suffix = in_array($filter, TE_COMPLIANCE_EXPORT_FILTERS, true) ? $filter : 'all';
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? $today : te_compliance_today();
    return "compliance-{$slug}-{$suffix}-{$day}.csv";
}

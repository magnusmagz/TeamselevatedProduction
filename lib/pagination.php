<?php
/**
 * Cursor pagination for the club-scale list endpoints.
 *
 * Until now no list endpoint in this codebase paginated. A club's roster, its
 * volunteers, its coaches and the super-admin club and user lists all returned
 * every row, and the frontends rendered every row. That is survivable at CKU's
 * 148 families and is not survivable at Girls on the Run's ~270 councils and
 * ~30,000 coaches (docs/gotr-hierarchy-plan-2026-09.md §5).
 *
 * The contract, identical on every endpoint that adopts this:
 *
 *   REQUEST   ?limit=<1..1000>   default 200
 *             ?cursor=<opaque>   omit for the first page
 *   RESPONSE  the existing rows key, unchanged, plus
 *             "page": { "limit": 200, "next_cursor": "…"|null, "truncated": true|false }
 *
 * ⚠️ **A caller that sends nothing gets the FIRST PAGE, not everything.** That
 * is the deliberate part, and the reason `truncated` exists: an old deployed
 * bundle asking for a club roster now receives 200 rows and no way to know it.
 * `main` is shared and deploys are by push, so that state is real for minutes at
 * least — which is why the FRONTEND ships first. A page that cannot read
 * `page.truncated` silently presents 200 rows as "all of them", and a club admin
 * reading a compliance list has no way to tell a short list from a complete one.
 *
 * ⚠️ **Keyset, not OFFSET.** `LIMIT n OFFSET m` re-scans and re-sorts the whole
 * result for every page — the cost this is meant to remove — and it double-counts
 * or skips rows when anything is inserted between two requests. The cursor
 * carries the sort key of the last row returned and the next page asks for
 * "strictly after that", which is stable under concurrent writes.
 *
 * ⚠️ **The sort key must end in a unique column.** Two people named Smith with
 * the same first name are one keyset boundary, and a cursor that cannot separate
 * them either loops or drops one. Every te_page_keyset_* call therefore ends
 * with the row's id, and the ORDER BY must be the SAME expression list in the
 * SAME order — `te_page_order_by()` builds it from the same array so the two
 * cannot drift.
 *
 * ⚠️ **NULLs are coalesced, in both places.** `last_name` is nullable in several
 * of these tables, and SQL row comparison against NULL is NULL — neither greater
 * nor less — so a NULL surname would silently end the pagination. Pass sort
 * expressions already wrapped in COALESCE (te_page_text_key() does it), and the
 * ORDER BY built from the same array inherits it.
 *
 * The cursor is OPAQUE, not secret. It encodes a position in a result set, never
 * a permission: the scope predicate runs on every page regardless of what the
 * cursor says, so a forged one can move someone within their own results and
 * nowhere else. It is validated for shape and ignored when it does not parse —
 * a broken cursor returns the first page rather than an error, because the row
 * the caller was reading may genuinely have been deleted.
 */

const TE_PAGE_DEFAULT_LIMIT = 200;
const TE_PAGE_MAX_LIMIT = 1000;

/**
 * Resolve `?limit=`.
 *
 * Anything unparseable, zero, negative or over the ceiling becomes the default
 * or the ceiling — never an error. A list endpoint refusing to answer because of
 * a malformed query string is a worse outcome than a sensible page.
 */
function te_page_limit($raw, int $default = TE_PAGE_DEFAULT_LIMIT, int $max = TE_PAGE_MAX_LIMIT): int
{
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return $default;
    }
    $n = (int) $raw;
    if ($n < 1) {
        return $default;
    }
    return min($n, $max);
}

/**
 * A text sort key that is safe on both sides of the comparison.
 *
 * LOWER so the ordering does not depend on the database's collation of case,
 * COALESCE so a NULL cannot make the keyset comparison return NULL and end the
 * listing early. The cursor value is normalised the same way in PHP.
 */
function te_page_text_key(string $column): string
{
    return "LOWER(COALESCE({$column}, ''))";
}

/** Normalise a PHP value to match te_page_text_key()'s SQL expression. */
function te_page_text_value($value): string
{
    return strtolower((string) ($value ?? ''));
}

/** `ORDER BY` for a keyset sort. Always ascending; the id tiebreaker is the caller's last entry. */
function te_page_order_by(array $sortExpressions): string
{
    return 'ORDER BY ' . implode(', ', $sortExpressions) . ' ASC';
}

/**
 * Encode a cursor. Base64url of a JSON array — opaque to the client, trivially
 * inspectable by us when something goes wrong.
 */
function te_page_encode_cursor(array $values): string
{
    return rtrim(strtr(base64_encode(json_encode(array_values($values))), '+/', '-_'), '=');
}

/**
 * Decode a cursor, or null if it does not parse into exactly $arity scalars.
 *
 * Null means "start at the beginning". A cursor from a different endpoint, a
 * truncated copy-paste, or one built before the sort key changed all land here,
 * and the first page is a better answer than a 400 for any of them.
 */
function te_page_decode_cursor($raw, int $arity): ?array
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $padded = strtr($raw, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $json = base64_decode($padded, true);
    if ($json === false) {
        return null;
    }
    $values = json_decode($json, true);
    if (!is_array($values) || count($values) !== $arity) {
        return null;
    }
    foreach ($values as $v) {
        if (is_array($v) || is_object($v)) {
            return null;
        }
    }
    return array_values($values);
}

/**
 * The `AND (…) > (…)` keyset predicate, or '' for the first page.
 *
 * Row comparison rather than the expanded OR-chain: one expression, no chance of
 * getting the nesting wrong, and supported by both Postgres and the SQLite the
 * tests run on.
 *
 * @param string[] $sortExpressions same array, same order, as te_page_order_by()
 * @param array|null $cursor decoded cursor values, aligned to $sortExpressions
 * @return array{sql:string, params:array}
 */
function te_page_keyset_clause(array $sortExpressions, ?array $cursor): array
{
    if ($cursor === null || count($cursor) !== count($sortExpressions)) {
        return ['sql' => '', 'params' => []];
    }

    $lhs = '(' . implode(', ', $sortExpressions) . ')';
    $rhs = '(' . implode(', ', array_fill(0, count($sortExpressions), '?')) . ')';

    return ['sql' => " AND {$lhs} > {$rhs}", 'params' => array_values($cursor)];
}

/**
 * How many rows to actually fetch: one more than asked for.
 *
 * That extra row is how "is there another page" is answered without a second
 * COUNT over the same predicate — and a COUNT would be a second chance to get
 * the scope wrong.
 */
function te_page_fetch_limit(int $limit): int
{
    return $limit + 1;
}

/**
 * Trim the over-fetched row and build the `page` block.
 *
 * $cursorOf receives the LAST row that is actually being returned and must give
 * back the same values, in the same order, as the sort expressions.
 *
 * @param array $rows rows as fetched with te_page_fetch_limit()
 * @param callable(array):array $cursorOf
 * @return array{rows:array, page:array{limit:int, next_cursor:?string, truncated:bool}}
 */
function te_page_finish(array $rows, int $limit, callable $cursorOf): array
{
    $truncated = count($rows) > $limit;
    if ($truncated) {
        $rows = array_slice($rows, 0, $limit);
    }

    $next = null;
    if ($truncated && $rows) {
        $next = te_page_encode_cursor($cursorOf($rows[count($rows) - 1]));
    }

    return [
        'rows' => $rows,
        'page' => [
            'limit' => $limit,
            'next_cursor' => $next,
            // TRUE means "there are more rows after this page", which is exactly
            // what a UI needs to decide between "Load more" and "that's all".
            'truncated' => $truncated,
        ],
    ];
}

/**
 * The client half of the cursor-pagination contract (lib/pagination.php).
 *
 *   request   ?limit=<1..1000>&cursor=<opaque>
 *   response  { …rows…, page: { limit, next_cursor: string|null, truncated: boolean } }
 *
 * ⚠️ **The point of `truncated` is that a short list must not look complete.**
 * These endpoints used to return every row; they now return the first page to a
 * caller that asks for nothing. A screen that renders `data.volunteers` and
 * ignores `data.page` shows 200 of 900 volunteers with no indication, and a club
 * admin reading a compliance list has no way to tell. Every list that adopts
 * this must render <LoadMore>, or say in some other way that there is more.
 *
 * ⚠️ **`page` may be ABSENT, and absent is not "complete".** `main` is shared
 * and deploys are by push, so the frontend ships BEFORE the backend here — an
 * older backend returns no `page` key at all, and that genuinely means "you have
 * everything", because that backend was not paginating. `readPage` returns null
 * for that, and callers treat null as "no more pages". The reverse mistake — a
 * NEW backend whose `page` is dropped — cannot happen, because the key is
 * written unconditionally by every paginated handler.
 */

export interface PageMeta {
  limit: number;
  /** Pass back as ?cursor= to get the next page. Null when this is the last one. */
  nextCursor: string | null;
  /** True when more rows exist after this page. */
  truncated: boolean;
}

/**
 * Read the `page` block off a response body.
 *
 * Returns null when the response carries no usable one — an older backend, or an
 * error body. Null means "there is no next page", which is the safe reading:
 * offering a Load more button that fetches nothing is worse than not offering it.
 */
export function readPage(data: unknown): PageMeta | null {
  if (!data || typeof data !== 'object') return null;
  const raw = (data as Record<string, unknown>).page;
  if (!raw || typeof raw !== 'object') return null;

  const p = raw as Record<string, unknown>;
  const next = typeof p.next_cursor === 'string' && p.next_cursor !== '' ? p.next_cursor : null;

  return {
    limit: typeof p.limit === 'number' ? p.limit : 0,
    nextCursor: next,
    // Trust the flag when it is a boolean; otherwise infer from the cursor.
    // These disagree only if a backend sets one and not the other, and having a
    // next cursor is the more actionable of the two.
    truncated: typeof p.truncated === 'boolean' ? p.truncated : next !== null,
  };
}

/**
 * The `limit`/`cursor` query fragment, already prefixed with `&`.
 *
 * Returns '' for a first page with the default limit, so a URL that had no query
 * string keeps not having one — which keeps the diffs on existing fetch calls
 * small and readable.
 */
export function pageQuery(cursor: string | null, limit?: number): string {
  const parts: string[] = [];
  if (limit) parts.push(`limit=${limit}`);
  if (cursor) parts.push(`cursor=${encodeURIComponent(cursor)}`);
  return parts.length ? `&${parts.join('&')}` : '';
}

/**
 * Rows out of a response that may be an ARRAY (old backend) or an object with a
 * named key (new one).
 *
 * `legacy/coaches-gateway.php?action=available` changed shape in this slice: it
 * was a bare JSON array and is now `{success, coaches, page}`. A truncated array
 * is indistinguishable from a complete one, which is the whole reason it had to
 * change. Every caller reads through here so both backends work during the
 * deploy window — and so the fallback is written once rather than four times.
 */
export function rowsFrom<T>(data: unknown, key: string): T[] {
  if (Array.isArray(data)) return data as T[];
  if (data && typeof data === 'object') {
    const v = (data as Record<string, unknown>)[key];
    if (Array.isArray(v)) return v as T[];
  }
  return [];
}

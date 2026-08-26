/**
 * The last few pages a reporter visited, for attaching to a support ticket.
 *
 * "What were you doing before this?" is the first question on nearly every
 * ticket, and the honest answer — "I clicked a few things" — is not one anyone
 * can act on. The app already knows, so it should say.
 *
 * ─── Why sessionStorage ───────────────────────────────────────────────────────
 * A crash-and-reload is exactly the case worth capturing, and in-memory state
 * does not survive one. sessionStorage does, while still being per-tab and
 * cleared when the tab closes — so a shared or kiosk browser does not hand one
 * family's trail to the next person, the way localStorage would.
 *
 * Every read and write is wrapped: Safari in private mode throws on access, and
 * failing to record a breadcrumb must never take a page down with it.
 */

/** Pages kept BEFORE the current one. Matches TE_SUPPORT_MAX_TRAIL server-side. */
export const MAX_TRAIL = 5;

const STORAGE_KEY = 'te_page_trail';

export interface PageVisit {
  /** Redacted path + query. Never a full URL. */
  path: string;
  /** ISO timestamp of arrival. */
  at: string;
}

/**
 * Query keys whose value is stripped before the path is ever stored.
 *
 * `/reset-password?token=…` and `/verify-magic-link?token=…` carry a live
 * credential. A trail is read by support staff, sits in Slack, and outlives the
 * session — so a token must not be in one, even for the seconds before the
 * server redacts it again. The server does redact independently; this copy
 * keeps the secret from being written to disk in the first place.
 */
const REDACT_PARAMS = [
  'token', 'password', 'passwd', 'secret', 'key', 'code', 'auth',
  'access_token', 'id_token', 'signature', 'sig', 'email', 'session',
];

/**
 * Redact a path before it is stored.
 *
 * Query KEYS survive — "which filter were they on" is often the bug — and only
 * the values of sensitive ones are replaced. A path segment that is 20+
 * characters of token alphabet is masked too: `/contribute/<token>` puts a
 * credential in the path rather than the query.
 */
export function redactPath(pathAndQuery: string): string {
  const [rawPath, rawQuery] = pathAndQuery.split('?');

  const path = rawPath
    .split('/')
    .map((seg) => (seg.length >= 20 && /^[A-Za-z0-9._~-]+$/.test(seg) ? '…' : seg))
    .join('/');

  if (!rawQuery) return path.slice(0, 300);

  const pairs = rawQuery
    .split('&')
    .filter(Boolean)
    .map((pair) => {
      const eq = pair.indexOf('=');
      const key = eq >= 0 ? pair.slice(0, eq) : pair;
      const lower = decodeURIComponent(key).toLowerCase();
      const secret = REDACT_PARAMS.some((needle) => lower.includes(needle));
      if (secret) return `${key}=…`;
      return pair;
    });

  return `${path}?${pairs.join('&')}`.slice(0, 300);
}

function read(): PageVisit[] {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed.filter((v) => v && typeof v.path === 'string') : [];
  } catch {
    return [];
  }
}

function write(trail: PageVisit[]): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(trail));
  } catch {
    // Private mode, quota, storage disabled. A missing trail is a slightly
    // thinner ticket, never a broken page.
  }
}

/**
 * Record a page the user has landed on.
 *
 * Called on every route change, so it is deliberately cheap and deliberately
 * quiet. Consecutive duplicates are collapsed — React Router re-renders and
 * replaces state more often than a person actually navigates, and five entries
 * of the same path is a trail that says nothing.
 *
 * `MAX_TRAIL + 1` is kept: the newest entry is the page they are ON, which
 * `getPageTrail()` drops. Keeping only MAX_TRAIL would mean the current page
 * evicts the oldest of the five that were asked for.
 */
export function recordPageVisit(pathAndQuery: string, now: Date = new Date()): void {
  const path = redactPath(pathAndQuery);
  if (!path) return;

  const trail = read();
  if (trail.length && trail[trail.length - 1].path === path) return;

  trail.push({ path, at: now.toISOString() });
  write(trail.slice(-(MAX_TRAIL + 1)));
}

/**
 * The pages visited BEFORE the current one, oldest first.
 *
 * The page the ticket was filed from is already on the ticket as `page_url`, so
 * repeating it here would spend one of five slots saying something we know.
 */
export function getPageTrail(): PageVisit[] {
  const trail = read();
  return trail.slice(0, -1).slice(-MAX_TRAIL);
}

/** For tests and for a deliberate reset; not called in the app. */
export function clearPageTrail(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    /* see write() */
  }
}

/**
 * Compare two user ids that may not have the same JavaScript type.
 *
 * ⚠️ **The chat server sends `senderId` as a STRING.** `lib/JWT.php:201` mints
 * the token claim as `(string)$userId` ("Neon expects string"), the chat server
 * passes `payload.user_id` straight through as `senderId`, and the client types
 * it as `number` — so the value and the type disagree and TypeScript cannot see
 * it. `"75" === 75` is false, and that one mismatch caused two visible bugs:
 *
 *  - **Your own message came back as someone else's.** `isOwnMessage` was false
 *    for the echo, so a parent saw their own message render left-aligned with
 *    their own avatar and name on their own screen.
 *  - **Every message you sent appeared twice.** The optimistic-message
 *    reconciliation in useChat matched on sender id, never matched, so the
 *    temp bubble was never replaced — it stayed stuck on "Sending…" and the
 *    server echo appended as a second message. The message was only ever
 *    STORED once; both copies were a rendering artifact.
 *
 * `ChatMessageList` had already been patched locally with `String(a) === String(b)`
 * while the other two sites were missed — which is why the staff widget and the
 * parent portal showed different symptoms for the same root cause. Hence one
 * predicate, used everywhere, rather than a coercion repeated at each site.
 *
 * Fixing the JWT cast instead is not an option: `lib/JWT.php` is on the
 * do-not-modify list, and every other consumer of that claim currently expects
 * a string.
 */
export function sameUser(a: string | number | null | undefined, b: string | number | null | undefined): boolean {
  // A missing id must never match another missing id. Two people who are both
  // "unknown" are not the same person, and treating them as such would render
  // a stranger's message as your own.
  if (a === null || a === undefined || a === '') return false;
  if (b === null || b === undefined || b === '') return false;

  return String(a) === String(b);
}

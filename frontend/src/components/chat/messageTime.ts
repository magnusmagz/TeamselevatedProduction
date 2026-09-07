/**
 * The ONE formatter for "when was this sent" in chat, on every surface.
 *
 * It formats in the viewer's own timezone, from the ISO timestamp the chat
 * server sends. Until 2026-09-06 the staff chat preferred a `time` string the
 * server had pre-formatted on its own clock — a Heroku dyno, UTC — so a live
 * message read "7:03 PM" while the same message after a reload read "2:03 PM".
 * `time` is display-only server output and must never be shown; the scan in
 * messageTime.test.ts fails if a chat surface reads it again.
 */
export function formatMessageTime(timestamp: string | Date, now: Date = new Date()): string {
  const date = timestamp instanceof Date ? timestamp : new Date(timestamp);
  if (Number.isNaN(date.getTime())) return '';
  const time = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
  if (date.toDateString() === now.toDateString()) return time;
  const yesterday = new Date(now);
  yesterday.setDate(yesterday.getDate() - 1);
  if (date.toDateString() === yesterday.toDateString()) return `Yesterday ${time}`;
  const sameYear = date.getFullYear() === now.getFullYear();
  const day = date.toLocaleDateString('en-US', sameYear ? { month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' });
  return `${day} ${time}`;
}

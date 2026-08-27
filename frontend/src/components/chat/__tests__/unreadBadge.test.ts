import { sameUser } from '../sameUser';

/**
 * The unread badge could never increment live.
 *
 * The server sends `receiveMessage` to the conversation ROOM, and a client joins
 * that room only for the conversation it currently has open. Everyone else
 * receives `conversationUpdated` — and that handler updated only the preview.
 *
 * So the badge was correct on a fresh page load and never moved again, which is
 * why signing out and back in appeared to fix it. Reported 2026-08-26; wrong
 * since chat shipped rather than a regression.
 *
 * These cover the decision the handler makes, extracted as the rule it applies.
 */
function shouldCountAsUnread(
  senderId: string | number | undefined,
  myId: string | number | undefined,
  updatedConversationId: number,
  openConversationId: number | null
): boolean {
  const isOwn = sameUser(senderId, myId);
  const isOpen = openConversationId === updatedConversationId;
  return !isOwn && !isOpen;
}

describe('unread badge increments', () => {
  it('counts a message in a conversation I do not have open', () => {
    expect(shouldCountAsUnread(74, 96, 63, null)).toBe(true);
  });

  it('counts one while I am looking at a DIFFERENT conversation', () => {
    expect(shouldCountAsUnread(74, 96, 63, 55)).toBe(true);
  });

  /** It is being read right now; a badge would be wrong the instant it appeared. */
  it('does not count a message in the conversation already on screen', () => {
    expect(shouldCountAsUnread(74, 96, 63, 63)).toBe(false);
  });

  it('never counts my own message', () => {
    expect(shouldCountAsUnread(96, 96, 63, null)).toBe(false);
  });

  /**
   * The server sends the id as a string (lib/JWT.php casts the claim) while the
   * client holds a number. Comparing with === would make every one of your own
   * messages increment your own badge — the same mismatch that made messages
   * appear twice.
   */
  it('does not count my own message when the ids differ in type', () => {
    expect(shouldCountAsUnread('96', 96, 63, null)).toBe(false);
    expect(shouldCountAsUnread(96, '96', 63, null)).toBe(false);
  });

  /**
   * An absent senderId must not read as "mine". Missing information should mean
   * the message still shows up, not that it silently disappears from the count.
   */
  it('counts the message when the sender is unknown', () => {
    expect(shouldCountAsUnread(undefined, 96, 63, null)).toBe(true);
  });
});

import { sameUser } from './sameUser';

/**
 * The string-vs-number mismatch that made every message you sent appear twice.
 *
 * lib/JWT.php:201 mints the token claim as `(string)$userId`, the chat server
 * echoes it unchanged as `senderId`, and the client held it as a `number`.
 * `"75" === 75` is false, which broke both the own-message check and the
 * optimistic-message reconciliation. Reported from production 2026-08-26.
 */
describe('sameUser', () => {
  /** The exact production case. */
  it('matches a string id from the server against a number id from the client', () => {
    expect(sameUser('75', 75)).toBe(true);
    expect(sameUser(75, '75')).toBe(true);
  });

  it('still matches when both sides agree on a type', () => {
    expect(sameUser(75, 75)).toBe(true);
    expect(sameUser('75', '75')).toBe(true);
  });

  it('does not match different people', () => {
    expect(sameUser('75', 96)).toBe(false);
    expect(sameUser(75, 96)).toBe(false);
  });

  /**
   * Two unknowns are not the same person. Treating them as equal would render
   * a stranger's message as your own, which is worse than the bug being fixed.
   */
  it('never matches when either side is missing', () => {
    expect(sameUser(null, null)).toBe(false);
    expect(sameUser(undefined, undefined)).toBe(false);
    expect(sameUser('', '')).toBe(false);
    expect(sameUser(null, 75)).toBe(false);
    expect(sameUser(75, undefined)).toBe(false);
    expect(sameUser(75, '')).toBe(false);
  });

  /** No loose-equality surprises: '' == 0 is true in JS, and must not be here. */
  it('does not fall into loose-equality traps', () => {
    expect(sameUser('', 0)).toBe(false);
    expect(sameUser(0, '')).toBe(false);
  });

  /**
   * A scan, not a unit test, because the bug was never in the predicate — it was
   * in which sites called it. ChatMessageList had already been patched locally
   * with String() while useChat and TeamChatPage were missed, which is why the
   * staff widget and the parent portal showed different symptoms for one cause.
   */
  it('is used at every senderId comparison site', () => {
    const fs = require('fs');
    const path = require('path');
    const root = path.join(__dirname, '..', '..');

    const sites = [
      'components/chat/useChat.ts',
      'components/chat/ChatMessageList.tsx',
      'parent-portal/pages/TeamChatPage.tsx',
    ];

    for (const site of sites) {
      const src = fs.readFileSync(path.join(root, site), 'utf8');
      const lines: string[] = src.split('\n');

      const rawComparisons = lines.filter(
        (l) => /senderId\s*===|===\s*.*senderId/.test(l) && !l.trim().startsWith('//') && !l.trim().startsWith('*')
      );

      expect(rawComparisons).toEqual([]);
      expect(src).toContain('sameUser');
    }
  });
});

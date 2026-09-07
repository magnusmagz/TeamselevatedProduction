import fs from 'fs';
import path from 'path';
import { formatMessageTime } from './messageTime';

// The suite is pinned to America/Chicago (jest.globalSetup.js), which is the
// whole point: a UTC instant must render in the viewer's zone, not the server's.
describe('formatMessageTime', () => {
  const now = new Date('2026-09-06T20:00:00Z'); // 3:00 PM Chicago, 6 Sep

  test('renders a UTC instant in the viewer timezone, not UTC', () => {
    expect(formatMessageTime('2026-09-06T19:03:00.000Z', now)).toBe('2:03 PM');
  });

  test('yesterday and older days carry the date', () => {
    expect(formatMessageTime('2026-09-05T23:30:00.000Z', now)).toBe('Yesterday 6:30 PM');
    expect(formatMessageTime('2026-08-30T14:05:00.000Z', now)).toBe('Aug 30 9:05 AM');
    expect(formatMessageTime('2025-12-24T14:05:00.000Z', now)).toBe('Dec 24, 2025 8:05 AM');
  });

  test('an unparseable timestamp renders nothing rather than "Invalid Date"', () => {
    expect(formatMessageTime('not a date', now)).toBe('');
  });

  /**
   * The chat server sends a pre-formatted `time` on live messages, built on the
   * dyno's clock (UTC). No chat surface may display it: that is how live
   * messages read five hours off from the same message after a reload.
   */
  test('no chat surface displays the server-formatted `time` field', () => {
    const files = [
      path.join(__dirname, 'ChatMessageList.tsx'),
      path.join(__dirname, 'ConversationList.tsx'),
      path.join(__dirname, '..', '..', 'parent-portal', 'pages', 'TeamChatPage.tsx'),
    ];
    for (const f of files) {
      const src = fs.readFileSync(f, 'utf8');
      expect({ file: path.basename(f), usesServerTime: /\b(msg|message)\.time\b/.test(src) }).toEqual({
        file: path.basename(f),
        usesServerTime: false,
      });
      expect({ file: path.basename(f), usesSharedFormatter: src.includes('formatMessageTime(') }).toEqual({
        file: path.basename(f),
        usesSharedFormatter: true,
      });
    }
  });
});

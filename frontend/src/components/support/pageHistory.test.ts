import {
  recordPageVisit,
  getPageTrail,
  clearPageTrail,
  redactPath,
  MAX_TRAIL,
} from './pageHistory';

describe('support page trail', () => {
  beforeEach(() => clearPageTrail());

  describe('redaction', () => {
    // The reason this module redacts at all: these two routes carry a live
    // credential in the query string, and a trail outlives the session.
    it('never writes a live token to storage', () => {
      expect(redactPath('/reset-password?token=abc123secret')).toBe('/reset-password?token=…');
      expect(redactPath('/verify-magic-link?token=deadbeef')).toBe('/verify-magic-link?token=…');
    });

    it('masks a credential carried in the path', () => {
      expect(redactPath(`/contribute/${'a1b2'.repeat(8)}`)).toBe('/contribute/…');
    });

    // Redaction that ate the ordinary query string would gut the feature —
    // which filter someone was on is frequently the bug itself.
    it('keeps harmless parameters and short route params', () => {
      expect(redactPath('/athletes?team=12&status=active')).toBe('/athletes?team=12&status=active');
      expect(redactPath('/teams/12/roster')).toBe('/teams/12/roster');
    });

    it('keeps the key of a redacted parameter so the reader sees what was withheld', () => {
      expect(redactPath('/x?keep=1&access_token=zzz')).toBe('/x?keep=1&access_token=…');
    });
  });

  describe('recording', () => {
    it('returns the pages before the current one, oldest first', () => {
      ['/a', '/b', '/c'].forEach((p) => recordPageVisit(p));

      // /c is the page they are ON — it is already the ticket's page_url, so
      // repeating it here would spend a slot on something we know.
      expect(getPageTrail().map((v) => v.path)).toEqual(['/a', '/b']);
    });

    it(`keeps ${MAX_TRAIL} pages of history regardless of how long the session ran`, () => {
      for (let i = 1; i <= 12; i += 1) recordPageVisit(`/page${i}`);

      const trail = getPageTrail();
      expect(trail).toHaveLength(MAX_TRAIL);
      // The steps just before the problem, not the first five of the day.
      expect(trail[trail.length - 1].path).toBe('/page11');
    });

    // React Router re-renders and replaces state more often than a person
    // navigates; five identical entries is a trail that says nothing.
    it('collapses consecutive duplicates', () => {
      ['/a', '/a', '/a', '/b', '/b', '/c'].forEach((p) => recordPageVisit(p));

      expect(getPageTrail().map((v) => v.path)).toEqual(['/a', '/b']);
    });

    it('records a revisit that is not consecutive', () => {
      ['/a', '/b', '/a', '/c'].forEach((p) => recordPageVisit(p));

      expect(getPageTrail().map((v) => v.path)).toEqual(['/a', '/b', '/a']);
    });

    it('stamps each visit with a time', () => {
      recordPageVisit('/a', new Date('2026-08-26T14:03:11Z'));
      recordPageVisit('/b');

      expect(getPageTrail()[0].at).toBe('2026-08-26T14:03:11.000Z');
    });

    it('survives a reload, which is the case worth capturing', () => {
      recordPageVisit('/a');
      recordPageVisit('/b');
      // sessionStorage is the store precisely so a crash-and-reload — which
      // wipes in-memory state — keeps the steps that led to the crash.
      expect(getPageTrail().map((v) => v.path)).toEqual(['/a']);
    });
  });

  describe('when storage is unavailable', () => {
    // Safari in private mode throws on access. A missing breadcrumb must never
    // take a page down with it.
    it('records and reads without throwing', () => {
      const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new Error('denied');
      });
      const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('denied');
      });

      expect(() => recordPageVisit('/a')).not.toThrow();
      expect(getPageTrail()).toEqual([]);

      getItem.mockRestore();
      setItem.mockRestore();
    });
  });
});

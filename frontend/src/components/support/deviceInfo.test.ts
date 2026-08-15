import { describeDevice, dataUrlBytes, collectDeviceInfo, DeviceInfo } from './deviceInfo';

const base: DeviceInfo = {
  user_agent: '',
  viewport: '390x844',
  screen: '390x844',
  pixel_ratio: 3,
  language: 'en-US',
  timezone: 'America/Chicago',
  online: true,
  route: '/dashboard',
};

describe('describeDevice', () => {
  /**
   * Edge's UA contains "Chrome", and Chrome's contains "Safari". A naive check
   * reports every Edge user as Chrome and every Chrome user as Safari, which
   * sends support chasing the wrong browser.
   */
  it('is not fooled by user-agent substrings', () => {
    const edge = describeDevice({
      ...base,
      user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64) AppleWebKit/537.36 Chrome/120 Safari/537.36 Edg/120',
    });
    const chrome = describeDevice({
      ...base,
      user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
    });
    const safari = describeDevice({
      ...base,
      user_agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17 Safari/605.1.15',
    });

    expect(edge).toContain('Edge on Windows');
    expect(chrome).toContain('Chrome on Windows');
    expect(safari).toContain('Safari on macOS');
  });

  it('identifies iOS browsers, which do not report Chrome or Firefox by name', () => {
    const criOS = describeDevice({
      ...base,
      user_agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6_0 like Mac OS X) AppleWebKit/605.1.15 CriOS/151.0 Mobile',
    });

    // Chrome on iOS says "CriOS" and also matches "Mac OS X" — both traps.
    expect(criOS).toContain('Chrome on iPhone');
  });

  it('includes the viewport, because layout bugs are size-specific', () => {
    expect(describeDevice({ ...base, user_agent: 'Mozilla/5.0 (Android) Chrome/120' })).toContain('390x844');
  });

  it('degrades to something honest for an unrecognised agent', () => {
    const out = describeDevice({ ...base, user_agent: 'some-crawler/1.0' });

    expect(out).toContain('Unknown OS');
    expect(out).not.toContain('undefined');
  });
});

describe('dataUrlBytes', () => {
  it('measures the payload, not the header', () => {
    // "hello" -> aGVsbG8= : 5 bytes
    expect(dataUrlBytes('data:image/png;base64,aGVsbG8=')).toBe(5);
  });

  it('handles a bare base64 string', () => {
    expect(dataUrlBytes('aGVsbG8=')).toBe(5);
  });

  it('accounts for both padding lengths', () => {
    expect(dataUrlBytes('data:image/png;base64,YWJjZA==')).toBe(4); // "abcd"
    expect(dataUrlBytes('data:image/png;base64,YWJjZGU=')).toBe(5); // "abcde"
  });
});

describe('collectDeviceInfo', () => {
  it('collects without throwing and reports the current route', () => {
    const d = collectDeviceInfo();

    expect(d.user_agent).toBe(navigator.userAgent);
    expect(d.route).toBe(window.location.pathname + window.location.search);
    expect(typeof d.online).toBe('boolean');
  });

  /**
   * Intl can throw on locked-down browsers. Failing to read a timezone must never
   * stop someone filing a report.
   */
  it('survives Intl being unavailable', () => {
    const original = Intl.DateTimeFormat;
    // @ts-expect-error deliberately breaking it
    Intl.DateTimeFormat = () => { throw new Error('nope'); };

    try {
      expect(collectDeviceInfo().timezone).toBe('unknown');
    } finally {
      Intl.DateTimeFormat = original;
    }
  });
});

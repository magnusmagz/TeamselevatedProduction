import { renderHook } from '@testing-library/react';
import { useUsagePing, localDateString, surfaceForPath } from './useUsagePing';

/**
 * The daily-active ping. What matters: once per user per local day, never
 * without a signed-in user, and never loud.
 */
describe('useUsagePing', () => {
  const originalFetch = global.fetch;

  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem('auth_token', 'test-token');
    global.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ recorded: true }) }) as unknown as typeof fetch;
  });

  afterEach(() => {
    global.fetch = originalFetch;
    localStorage.clear();
    jest.restoreAllMocks();
  });

  it('pings once with the local date and the surface, then dedupes for the day', async () => {
    const { rerender } = renderHook(({ path }) => useUsagePing(42, path), { initialProps: { path: '/dashboard' } });
    await Promise.resolve();
    expect(global.fetch).toHaveBeenCalledTimes(1);
    const [url, init] = (global.fetch as jest.Mock).mock.calls[0];
    expect(url).toMatch(/\/api\/usage-ping\.php$/);
    expect(init.method).toBe('POST');
    expect(init.headers.Authorization).toBe('Bearer test-token');
    expect(JSON.parse(init.body)).toEqual({ local_date: localDateString(), surface: 'staff' });

    await Promise.resolve();
    expect(localStorage.getItem('te_usage_ping:42')).toBe(localDateString());

    rerender({ path: '/parent/dashboard' });
    rerender({ path: '/teams' });
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it('does not ping without a user, and pings a different user separately', async () => {
    const { rerender } = renderHook(({ uid }) => useUsagePing(uid, '/parent'), { initialProps: { uid: null as number | null } });
    expect(global.fetch).not.toHaveBeenCalled();
    rerender({ uid: 7 });
    await Promise.resolve();
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(JSON.parse((global.fetch as jest.Mock).mock.calls[0][1].body).surface).toBe('parent');
  });

  it('skips when already recorded today', () => {
    localStorage.setItem('te_usage_ping:42', localDateString());
    renderHook(() => useUsagePing(42, '/referee'));
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('swallows a failed request and does not mark the day', async () => {
    global.fetch = jest.fn().mockRejectedValue(new Error('down')) as unknown as typeof fetch;
    renderHook(() => useUsagePing(42, '/dashboard'));
    await Promise.resolve();
    await Promise.resolve();
    expect(localStorage.getItem('te_usage_ping:42')).toBeNull();
  });

  it('derives the surface from the path and the date from local time', () => {
    expect(surfaceForPath('/referee')).toBe('referee');
    expect(surfaceForPath('/referee/games')).toBe('referee');
    expect(surfaceForPath('/parent/chat')).toBe('parent');
    expect(surfaceForPath('/dashboard')).toBe('staff');
    // 23:30 local on the 12th is the 12th, whatever UTC says.
    expect(localDateString(new Date(2026, 8, 12, 23, 30))).toBe('2026-09-12');
  });
});

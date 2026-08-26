import { renderHook, waitFor } from '@testing-library/react';
import { useModerationOpenCount } from './useModerationOpenCount';

/**
 * The nav badge for reported chat messages.
 *
 * The rule worth pinning is that a failure renders NOTHING rather than a zero.
 * On a compliance surface "0" reads as "all clear", which is the wrong thing to
 * show when the truth is "we could not ask" — the same reason the consent column
 * renders Unknown instead of a blank cell, and the same reason setVenues([]) on
 * failure was rejected as worse than an error.
 */
describe('useModerationOpenCount', () => {
  const originalFetch = global.fetch;

  beforeEach(() => {
    localStorage.setItem('auth_token', 'test-token');
  });

  afterEach(() => {
    global.fetch = originalFetch;
    localStorage.clear();
    jest.restoreAllMocks();
  });

  it('reports the open counts for an admin', async () => {
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, open_total: 4, open_high: 1 }),
    }) as unknown as typeof fetch;

    const { result } = renderHook(() => useModerationOpenCount(true, 51));

    await waitFor(() => expect(result.current).toEqual({ openTotal: 4, openHigh: 1 }));
  });

  it('renders nothing rather than zero when the request fails', async () => {
    global.fetch = jest.fn().mockRejectedValue(new Error('network down')) as unknown as typeof fetch;

    const { result } = renderHook(() => useModerationOpenCount(true, 51));

    await waitFor(() => expect(result.current).toBeNull());
    expect(result.current).not.toEqual({ openTotal: 0, openHigh: 0 });
  });

  it('renders nothing on a 403 rather than treating it as an empty queue', async () => {
    global.fetch = jest.fn().mockResolvedValue({
      ok: false,
      json: async () => ({ success: false, error: 'Only club administrators can review reported messages' }),
    }) as unknown as typeof fetch;

    const { result } = renderHook(() => useModerationOpenCount(true, 51));

    await waitFor(() => expect(result.current).toBeNull());
  });

  it('does not call the endpoint at all when the caller is not an admin', async () => {
    const fetchMock = jest.fn();
    global.fetch = fetchMock as unknown as typeof fetch;

    const { result } = renderHook(() => useModerationOpenCount(false, 51));

    await waitFor(() => expect(result.current).toBeNull());
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('asks the cheap count endpoint, not the 90-day summary', async () => {
    const fetchMock = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, open_total: 0, open_high: 0 }),
    });
    global.fetch = fetchMock as unknown as typeof fetch;

    renderHook(() => useModerationOpenCount(true, 51));

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());

    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain('action=open-count');
    expect(url).not.toContain('action=summary');
    expect(url).toContain('club_id=51');
  });
});

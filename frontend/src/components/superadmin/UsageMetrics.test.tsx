import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import UsageMetrics, { pct, roleLabel } from './UsageMetrics';

describe('UsageMetrics', () => {
  const originalFetch = global.fetch;
  beforeEach(() => localStorage.setItem('auth_token', 't'));
  afterEach(() => {
    global.fetch = originalFetch;
    localStorage.clear();
  });

  it('renders rates against holders, and a zero row rather than nothing', async () => {
    global.fetch = jest.fn().mockImplementation((url: string) => {
      if (url.includes('action=summary')) {
        return Promise.resolve({
          ok: true,
          json: async () => ({
            available: true, as_of: '2026-09-12', week_start: '2026-09-06', month_start: '2026-08-14',
            rows: [
              { club_id: 51, club_name: 'Central Kansas United', role: 'coach', holders: 20, dau: 3, wau: 10, mau: 15 },
              { club_id: 51, club_name: 'Central Kansas United', role: 'treasurer', holders: 1, dau: 0, wau: 0, mau: 0 },
            ],
            clubs: [{ club_id: 51, club_name: 'Central Kansas United', holders: 200, dau: 40, wau: 80, mau: 120 }],
          }),
        });
      }
      return Promise.resolve({ ok: true, json: async () => ({ available: true, weeks: [{ start: '2026-09-06', end: '2026-09-12' }], series: [{ club_id: 51, club_name: 'Central Kansas United', role: '*', values: [100] }] }) });
    }) as unknown as typeof fetch;

    render(<UsageMetrics />);
    await waitFor(() => expect(screen.getByText('Coaches')).toBeInTheDocument());
    expect(screen.getByText('Treasurers')).toBeInTheDocument();
    expect(screen.getByText('50%')).toBeInTheDocument(); // coach WAU 10/20
    expect(screen.getByText('40%')).toBeInTheDocument(); // club WAU 80/200
    expect(screen.getByText('60%')).toBeInTheDocument(); // club MAU 120/200
    expect(screen.getByText('75%')).toBeInTheDocument(); // coach MAU 15/20
    expect(screen.getAllByText('0%').length).toBeGreaterThan(0); // treasurer
    expect(screen.getByText('Anyone')).toBeInTheDocument();
  });

  it('shows the endpoint sentence when tracking is not set up', async () => {
    global.fetch = jest.fn().mockResolvedValue({ ok: false, json: async () => ({ available: false, error: 'Usage tracking is not set up yet (migration 103 is not applied).' }) }) as unknown as typeof fetch;
    render(<UsageMetrics />);
    await waitFor(() => expect(screen.getByText(/migration 103/)).toBeInTheDocument());
  });

  it('helpers', () => {
    expect(pct(1, 0)).toBe('–');
    expect(pct(1, 3)).toBe('33%');
    expect(roleLabel('parent')).toBe('Crew (parents)');
    expect(roleLabel('weird')).toBe('weird');
  });
});

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import RosterDownloadButton from './RosterDownloadButton';

const mockUser: any = { current: null };
jest.mock('../hooks/useAuth', () => ({
  useAuth: () => ({ user: mockUser.current, isLoading: false }),
}));

const staff = { id: 1, email: 'coach@club.test', name: 'Coach', roles: [{ role: 'coach' }] };
const parent = { id: 2, email: 'mum@club.test', name: 'Parent', roles: [{ role: 'parent' }] };

let clicked: HTMLAnchorElement | null = null;

beforeEach(() => {
  mockUser.current = staff;
  clicked = null;
  (window.URL as any).createObjectURL = jest.fn(() => 'blob:roster');
  (window.URL as any).revokeObjectURL = jest.fn();
  localStorage.setItem('auth_token', 'tok');

  // Capture the synthetic <a> the component clicks instead of navigating.
  jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    clicked = this;
  });
});

afterEach(() => {
  jest.restoreAllMocks();
  delete (global as any).fetch;
});

const okResponse = (headers: Record<string, string> = {}) => ({
  ok: true,
  status: 200,
  headers: { get: (k: string) => headers[k] ?? null },
  blob: () => Promise.resolve(new Blob(['a,b'], { type: 'text/csv' })),
});

test('a parent never sees the control', () => {
  mockUser.current = parent;
  const { container } = render(<RosterDownloadButton teamId={5} />);
  expect(container).toBeEmptyDOMElement();
});

test('offers both flavours and requests the athletes-only one', async () => {
  (global as any).fetch = jest.fn(() => Promise.resolve(okResponse()));
  render(<RosterDownloadButton teamId={5} />);

  fireEvent.click(screen.getByRole('button', { name: /download/i }));
  expect(screen.getByText(/Athletes \(CSV\)/)).toBeInTheDocument();
  fireEvent.click(screen.getByText(/Athletes \(CSV\)/));

  await waitFor(() => expect((global as any).fetch).toHaveBeenCalled());
  const url = String((global as any).fetch.mock.calls[0][0]);
  expect(url).toContain('/api/roster-export.php?team_id=5&include=athletes');
  expect((global as any).fetch.mock.calls[0][1].headers.Authorization).toBe('Bearer tok');
});

test('the crew flavour asks for crew', async () => {
  (global as any).fetch = jest.fn(() => Promise.resolve(okResponse()));
  render(<RosterDownloadButton teamId={5} />);

  fireEvent.click(screen.getByRole('button', { name: /download/i }));
  fireEvent.click(screen.getByText(/Athletes \+ Crew \(CSV\)/));

  await waitFor(() => expect((global as any).fetch).toHaveBeenCalled());
  expect(String((global as any).fetch.mock.calls[0][0])).toContain('include=crew');
});

test('uses the filename the server chose', async () => {
  (global as any).fetch = jest.fn(() =>
    Promise.resolve(okResponse({ 'Content-Disposition': 'attachment; filename="Sharks-U12-roster-2026-08-25.csv"' }))
  );
  render(<RosterDownloadButton teamId={5} />);

  fireEvent.click(screen.getByRole('button', { name: /download/i }));
  fireEvent.click(screen.getByText(/Athletes \(CSV\)/));

  await waitFor(() => expect(clicked).not.toBeNull());
  expect(clicked!.download).toBe('Sharks-U12-roster-2026-08-25.csv');
});

/**
 * The load-bearing one: fetch() does not reject on 403, so without the ok check
 * the JSON error body would be saved as a .csv that opens empty.
 */
test('a refusal is shown, not downloaded', async () => {
  (global as any).fetch = jest.fn(() =>
    Promise.resolve({
      ok: false,
      status: 403,
      headers: { get: () => null },
      json: () => Promise.resolve({ error: 'You do not have permission to download this team\'s roster' }),
    })
  );
  render(<RosterDownloadButton teamId={5} />);

  fireEvent.click(screen.getByRole('button', { name: /download/i }));
  fireEvent.click(screen.getByText(/Athletes \(CSV\)/));

  expect(await screen.findByText(/do not have permission/i)).toBeInTheDocument();
  expect(clicked).toBeNull();
});

/** A capped file must say it was capped. */
test('reports truncation after downloading', async () => {
  (global as any).fetch = jest.fn(() =>
    Promise.resolve(
      okResponse({ 'X-Roster-Export-Truncated': '2 of 1002 players were left out (the file is capped at 1000 rows).' })
    )
  );
  render(<RosterDownloadButton teamId={5} />);

  fireEvent.click(screen.getByRole('button', { name: /download/i }));
  fireEvent.click(screen.getByText(/Athletes \(CSV\)/));

  await waitFor(() => expect(clicked).not.toBeNull());
  expect(await screen.findByText(/not everything fit/i)).toBeInTheDocument();
  expect(screen.getByText(/2 of 1002 players/)).toBeInTheDocument();
});

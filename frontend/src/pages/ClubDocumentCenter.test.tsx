import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import ClubDocumentCenter from './ClubDocumentCenter';

/**
 * The Coaches / Volunteers section of the document assignment picker.
 *
 * It never rendered. The page fetched
 * `legacy/coaches-gateway.php?action=list`, and that gateway has no `list`
 * case and no `default:` — so the request returned 200 with an EMPTY BODY,
 * `.json()` threw on it, a bare `.catch(() => null)` swallowed the throw, and
 * `availableCoaches` stayed `[]`. The section is rendered behind
 * `availableCoaches.length > 0`, so an admin simply could not assign a document
 * to a coach or volunteer, with nothing on screen saying why.
 *
 * The correct action is `available`, and it returns a BARE ARRAY rather than
 * `{coaches: [...]}` — which is why the `Array.isArray` branch is the one that
 * has to fire. Both halves are asserted here: teams come back in the wrapped
 * shape, coaches in the bare one, and both must land.
 */

const mockUseOrg = jest.fn();
jest.mock('../contexts/OrgContext', () => ({ useOrg: () => mockUseOrg() }));

const coaches = [
  { id: 161, first_name: 'Kyle', last_name: 'Smith', email: 'kyle@example.com' },
  { id: 158, first_name: 'Morgan', last_name: 'Long', email: 'morgan@example.com' },
];

/** Captures every URL the page requests, so we can assert the action name. */
let requested: string[] = [];

/** `coachesResponse` is what the coaches-gateway call resolves to. */
function mockApi(coachesResponse: { ok?: boolean; body?: any; empty?: boolean }) {
  (global.fetch as jest.Mock).mockImplementation((url: string) => {
    requested.push(url);

    if (url.includes('coaches-gateway')) {
      if (coachesResponse.empty) {
        // What the broken `action=list` actually did: 200, empty body, and
        // `.json()` rejects on it.
        return Promise.resolve({
          ok: true,
          json: () => Promise.reject(new SyntaxError('Unexpected end of JSON input')),
        });
      }
      return Promise.resolve({
        ok: coachesResponse.ok ?? true,
        json: () => Promise.resolve(coachesResponse.body),
      });
    }

    if (url.includes('teams.php')) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ teams: [{ id: 1, name: '7th-8th Team 1' }] }),
      });
    }
    if (url.includes('athletes-gateway')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ athletes: [] }) });
    }
    // documents-gateway list
    return Promise.resolve({
      ok: true,
      json: () =>
        Promise.resolve({ success: true, slots: [], custom_documents: [] }),
    });
  });
}

/** Open the Add Document modal and reveal the assignment picker. */
async function openPicker() {
  fireEvent.click(await screen.findByRole('button', { name: /upload document/i }));
  fireEvent.click(await screen.findByRole('button', { name: /edit assignments/i }));
}

beforeEach(() => {
  requested = [];
  jest.clearAllMocks();
  mockUseOrg.mockReturnValue({
    activeContext: { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Club' },
  });
  global.fetch = jest.fn();
});

describe('ClubDocumentCenter assignment picker', () => {
  it('asks coaches-gateway for `available`, never the non-existent `list`', async () => {
    mockApi({ body: coaches });
    render(<ClubDocumentCenter />);
    await openPicker();

    await waitFor(() => {
      expect(requested.some((u) => u.includes('coaches-gateway'))).toBe(true);
    });
    const coachCall = requested.find((u) => u.includes('coaches-gateway'))!;
    expect(coachCall).toContain('action=available');
    expect(coachCall).not.toContain('action=list');
  });

  /** THE REGRESSION: two coaches come back and the section renders them. */
  it('renders the coach section when `available` returns two coaches', async () => {
    mockApi({ body: coaches });
    render(<ClubDocumentCenter />);
    await openPicker();

    expect(await screen.findByText(/Coaches \/ Volunteers \(2\)/i)).toBeInTheDocument();
    expect(screen.getByText('Kyle Smith')).toBeInTheDocument();
    expect(screen.getByText('Morgan Long')).toBeInTheDocument();
  });

  it('selects a coach as a `user` assignment target', async () => {
    mockApi({ body: coaches });
    render(<ClubDocumentCenter />);
    await openPicker();

    const kyle = await screen.findByText('Kyle Smith');
    fireEvent.click(kyle.previousElementSibling as HTMLInputElement);

    // Club-wide is pre-selected on a new document, so adding Kyle makes 2.
    expect(await screen.findByText(/Assigned To \(2\)/i)).toBeInTheDocument();
  });

  /**
   * The failure must be visible. An empty coach list and an unreachable
   * endpoint looked identical before — the section was simply absent, which
   * reads as "this club has no coaches" rather than "we could not ask".
   */
  it('shows an error on the section instead of silently omitting it', async () => {
    mockApi({ empty: true });
    render(<ClubDocumentCenter />);
    await openPicker();

    expect(
      await screen.findByText(/could not load coaches and volunteers/i)
    ).toBeInTheDocument();
    expect(screen.queryByText(/Coaches \/ Volunteers \(/i)).not.toBeInTheDocument();
  });

  it('surfaces the gateway error text when there is one', async () => {
    mockApi({ ok: false, body: { error: 'Access denied' } });
    render(<ClubDocumentCenter />);
    await openPicker();

    expect(await screen.findByText(/Access denied/i)).toBeInTheDocument();
  });

  /**
   * A coach failure must not take the other target types with it. The whole
   * point of the error state is that the admin can still assign to the club,
   * a team or an athlete.
   */
  it('still offers teams when the coach fetch fails', async () => {
    mockApi({ empty: true });
    render(<ClubDocumentCenter />);
    await openPicker();

    expect(await screen.findByText(/Teams \(1\)/i)).toBeInTheDocument();
  });
});

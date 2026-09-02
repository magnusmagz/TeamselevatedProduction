import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import DocumentsPage from './DocumentsPage';

/**
 * A refusal is not an empty shelf.
 *
 * `action=for-athlete` answering `{success: false, error: …}` set
 * `documents = []` and no error, so the page rendered its empty state —
 * "No Documents / No documents have been uploaded yet." A parent was therefore
 * told, as a statement of fact, that their child's club had filed nothing,
 * when the truth was that the request had been rejected.
 *
 * This is the failure mode nobody reports, because it looks like an answer. It
 * is the same shape as `setVenues([])` on a 500 (CLAUDE.md: "never a false
 * empty") and the same shape as the coach typeahead that returned 200 with
 * nobody in it.
 */

jest.mock('../hooks/useParentAthletes', () => ({
  useParentAthletes: () => ({
    athletes: [{ id: 10, first_name: 'Sofia', last_name: 'Devora' }],
    selectedAthleteId: 10,
    selectAthlete: jest.fn(),
    loading: false,
    error: null,
  }),
}));

jest.mock('../components/ParentHeader', () => ({
  ParentHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));
jest.mock('../components/AthleteSelector', () => ({
  AthleteSelector: () => <div>selector</div>,
}));
jest.mock('react-router-dom', () => ({ useParams: () => ({}) }));

function respond(body: any) {
  (global.fetch as jest.Mock).mockResolvedValue({
    ok: true,
    json: () => Promise.resolve(body),
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  global.fetch = jest.fn();
});

describe('parent portal DocumentsPage', () => {
  /** THE REGRESSION. */
  it('shows an error, not "No Documents", when the API answers success:false', async () => {
    respond({ success: false, error: 'Access denied' });
    render(<DocumentsPage />);

    expect(await screen.findByText('Access denied')).toBeInTheDocument();
    expect(screen.queryByText('No Documents')).not.toBeInTheDocument();
  });

  it('falls back to a generic message when the failure carries no error text', async () => {
    respond({ success: false });
    render(<DocumentsPage />);

    expect(await screen.findByText(/failed to load documents/i)).toBeInTheDocument();
    expect(screen.queryByText('No Documents')).not.toBeInTheDocument();
  });

  /**
   * A genuinely empty list is a real answer and must still read as empty —
   * the fix must not turn "this club has filed nothing yet" into an error.
   */
  it('still shows the empty state when the club really has no documents', async () => {
    respond({ success: true, documents: [] });
    render(<DocumentsPage />);

    expect(await screen.findByText('No Documents')).toBeInTheDocument();
    expect(screen.queryByText(/failed to load/i)).not.toBeInTheDocument();
  });

  it('renders documents on success', async () => {
    respond({
      success: true,
      documents: [
        {
          id: 1,
          title: 'Concussion Protocol',
          is_required: true,
          expires_at: null,
          created_at: '2026-07-01T00:00:00Z',
        },
      ],
    });
    render(<DocumentsPage />);

    expect(await screen.findByText('Concussion Protocol')).toBeInTheDocument();
    expect(screen.queryByText('No Documents')).not.toBeInTheDocument();
  });

  /**
   * A network throw was already handled; pin it so the new success:false
   * branch cannot be written in a way that swallows it.
   */
  it('shows an error when the fetch itself rejects', async () => {
    (global.fetch as jest.Mock).mockRejectedValue(new Error('offline'));
    render(<DocumentsPage />);

    expect(await screen.findByText(/failed to load documents/i)).toBeInTheDocument();
  });
});

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import RefereeFeedback from './RefereeFeedback';

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 100, isClubAdmin: true }),
}));

const rows = [
  {
    id: 1, club_id: 100, calendar_event_id: 500, team_id: 10, submitted_by: 50,
    referee_name: 'J. Whistle', rating: 4, categories: ['control', 'safety'], comments: 'Good.',
    incident: false, created_at: '2026-09-01 12:00:00', updated_at: null,
    event_name: 'League match', event_date: '2026-09-01', start_time: '10:00', opponent_name: 'Rivals FC',
    team_name: 'U12 Blue', submitted_by_name: 'Cora Coach',
  },
  {
    id: 2, club_id: 100, calendar_event_id: 500, team_id: 11, submitted_by: 51,
    referee_name: 'J. Whistle', rating: 2, categories: ['safety'], comments: 'Missed a tackle.',
    incident: true, created_at: '2026-09-01 13:00:00', updated_at: null,
    event_name: 'League match', event_date: '2026-09-01', start_time: '10:00', opponent_name: 'Rivals FC',
    team_name: 'U12 White', submitted_by_name: 'Sam Second',
  },
];

const summary = [
  { referee_name: 'J. Whistle', count: 2, average_rating: 3, incident_count: 1 },
];

function listResponse(over: Record<string, unknown> = {}) {
  return {
    ok: true,
    status: 200,
    headers: { get: () => null },
    json: async () => ({ success: true, available: true, feedback: rows, summary, ...over }),
  };
}

let clicked: HTMLAnchorElement | null = null;

beforeEach(() => {
  global.fetch = jest.fn();
  localStorage.setItem('auth_token', 'tok');
  clicked = null;
  (window.URL as any).createObjectURL = jest.fn(() => 'blob:ref');
  (window.URL as any).revokeObjectURL = jest.fn();
  jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    clicked = this;
  });
});

afterEach(() => {
  jest.restoreAllMocks();
  delete (global as any).fetch;
});

describe('RefereeFeedback (admin page)', () => {
  it('lists the club rows, marks incidents and shows the per-referee summary', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(listResponse());

    render(<RefereeFeedback />);

    expect(await screen.findByText('Sam Second')).toBeInTheDocument();
    const url: string = (global.fetch as jest.Mock).mock.calls[0][0];
    expect(url).toContain('/api/referee-feedback.php?action=list&club_id=100');

    // Incident row is marked (data attribute on the Game cell, tint on the
    // row); the other is not.
    const incidentRow = screen.getByText('Sam Second').closest('tr')!;
    expect(incidentRow.querySelector('[data-incident]')).toHaveAttribute('data-incident', 'true');
    expect(incidentRow).toHaveClass('bg-red-50');
    const calmRow = screen.getByText('Cora Coach').closest('tr')!;
    expect(calmRow.querySelector('[data-incident]')).toHaveAttribute('data-incident', 'false');
    expect(calmRow).not.toHaveClass('bg-red-50');

    // Summary: count, average, incident count. The testid sits on the name
    // cell; the assertions are about the whole row.
    const summaryRow = screen.getByTestId('summary-J. Whistle').closest('tr')!;
    expect(summaryRow).toHaveTextContent('2');
    expect(summaryRow).toHaveTextContent('3.0');
    expect(summaryRow).toHaveTextContent('1');

    // Stored date rendered on its own day.
    expect(screen.getAllByText(/Sep 1, 2026/).length).toBeGreaterThan(0);
  });

  it('sends the filters as query parameters', async () => {
    (global.fetch as jest.Mock).mockResolvedValue(listResponse());

    render(<RefereeFeedback />);
    await screen.findByText('Sam Second');

    fireEvent.change(screen.getByLabelText(/Referee/i), { target: { value: 'whistle' } });
    fireEvent.click(screen.getByLabelText(/Incidents only/i));
    fireEvent.change(screen.getByLabelText(/From/i), { target: { value: '2026-08-01' } });
    fireEvent.click(screen.getByRole('button', { name: /Apply/i }));

    await waitFor(() => {
      const last: string = (global.fetch as jest.Mock).mock.calls.slice(-1)[0][0];
      expect(last).toContain('referee_name=whistle');
      expect(last).toContain('incident=1');
      expect(last).toContain('from=2026-08-01');
    });
  });

  it('downloads the CSV with the bearer token and reports truncation', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(listResponse());
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 200,
      headers: {
        get: (k: string) =>
          ({
            'Content-Disposition': 'attachment; filename="referee-feedback-2026-09-06.csv"',
            'X-Referee-Feedback-Export-Truncated': '1 of 5001 feedback rows were left out (the file is capped at 5000 rows).',
          } as Record<string, string>)[k] ?? null,
      },
      blob: () => Promise.resolve(new Blob(['a,b'], { type: 'text/csv' })),
    });

    render(<RefereeFeedback />);
    await screen.findByText('Sam Second');

    fireEvent.click(screen.getByRole('button', { name: /Download CSV/i }));

    await waitFor(() => expect(clicked).not.toBeNull());
    expect(clicked!.download).toBe('referee-feedback-2026-09-06.csv');
    const [url, init] = (global.fetch as jest.Mock).mock.calls[1];
    expect(url).toContain('action=export&club_id=100');
    expect(init.headers.Authorization).toBe('Bearer tok');
    expect(await screen.findByText(/left out/)).toBeInTheDocument();
  });

  it('says the feature is not switched on rather than showing an empty table', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(listResponse({ available: false, feedback: [], summary: [] }));

    render(<RefereeFeedback />);

    expect(await screen.findByText(/not switched on/i)).toBeInTheDocument();
  });
});

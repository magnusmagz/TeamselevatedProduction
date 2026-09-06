import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import RefereeFeedbackModal from '../RefereeFeedbackModal';
import { REFEREE_FEEDBACK_CATEGORIES } from '../../../constants/refereeFeedbackCategories';

const API = 'https://api.test';

function eventResponse(over: Record<string, unknown> = {}) {
  return {
    ok: true,
    status: 200,
    json: async () => ({
      success: true,
      available: true,
      event: { id: 500, name: 'League match', event_date: '2026-09-01', opponent_name: 'Rivals FC' },
      can_submit: true,
      reason: null,
      teams: [{ id: 10, name: 'U12 Blue' }],
      categories: REFEREE_FEEDBACK_CATEGORIES.map((c) => c.value),
      feedback: [],
      ...over,
    }),
  };
}

const existing = {
  id: 7,
  club_id: 100,
  calendar_event_id: 500,
  team_id: 10,
  submitted_by: 50,
  referee_name: 'J. Whistle',
  rating: 2,
  categories: ['safety'],
  comments: 'Missed a bad tackle.',
  incident: true,
  created_at: '2026-09-01 12:00:00',
  updated_at: null,
  event_name: 'League match',
  event_date: '2026-09-01',
  start_time: '10:00',
  opponent_name: 'Rivals FC',
  team_name: 'U12 Blue',
  submitted_by_name: 'Cora Coach',
};

beforeEach(() => {
  global.fetch = jest.fn();
  localStorage.setItem('auth_token', 'tok');
});

afterEach(() => {
  delete (global as any).fetch;
});

describe('RefereeFeedbackModal', () => {
  it('renders the form for a played game and submits a create with the canonical fields', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(eventResponse());
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ success: true, id: 9, feedback: { ...existing, id: 9, incident: false } }),
    });
    const onSaved = jest.fn();

    render(<RefereeFeedbackModal eventId={500} apiUrl={API} onClose={() => {}} onSaved={onSaved} />);

    // The game header shows the stored date on its own calendar day.
    expect(await screen.findByText(/Rivals FC/)).toBeInTheDocument();
    expect(screen.getByText(/Sep 1, 2026/)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/Referee name/i), { target: { value: '  M. Flag ' } });
    fireEvent.click(screen.getByRole('radio', { name: /4/ }));
    fireEvent.click(screen.getByRole('button', { name: /Player safety/ }));
    fireEvent.click(screen.getByRole('button', { name: /Game control/ }));
    fireEvent.change(screen.getByLabelText(/Comments/i), { target: { value: 'Fine.' } });
    fireEvent.click(screen.getByRole('button', { name: /Save feedback/i }));

    await waitFor(() => expect(onSaved).toHaveBeenCalled());

    const [url, init] = (global.fetch as jest.Mock).mock.calls[1];
    expect(url).toBe(`${API}/api/referee-feedback.php?action=create`);
    expect(init.method).toBe('POST');
    expect(init.headers.Authorization).toBe('Bearer tok');
    const body = JSON.parse(init.body);
    expect(body).toEqual({
      event_id: 500,
      team_id: 10,
      referee_name: 'M. Flag',
      rating: 4,
      categories: ['control', 'safety'],
      comments: 'Fine.',
      incident: false,
    });
  });

  it('shows existing feedback and edits it through update, never a second create', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(eventResponse({ feedback: [existing] }));
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ success: true, id: 7, feedback: existing }),
    });

    render(<RefereeFeedbackModal eventId={500} apiUrl={API} onClose={() => {}} onSaved={() => {}} />);

    expect(await screen.findByText('J. Whistle')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Edit/ }));

    expect((screen.getByLabelText(/Referee name/i) as HTMLInputElement).value).toBe('J. Whistle');
    expect((screen.getByLabelText(/Flag as incident/i) as HTMLInputElement).checked).toBe(true);
    expect(screen.getByRole('button', { name: /Player safety/ })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('radio', { name: /1/ }));
    fireEvent.click(screen.getByRole('button', { name: /Save feedback/i }));

    await waitFor(() => expect((global.fetch as jest.Mock).mock.calls.length).toBe(2));
    const [url, init] = (global.fetch as jest.Mock).mock.calls[1];
    expect(url).toBe(`${API}/api/referee-feedback.php?action=update`);
    expect(init.method).toBe('PUT');
    const body = JSON.parse(init.body);
    expect(body.id).toBe(7);
    expect(body.rating).toBe(1);
    expect(body.incident).toBe(true);
    expect(body.event_id).toBeUndefined();
  });

  it('refuses the form when the server says the game cannot be rated', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(
      eventResponse({ can_submit: false, reason: 'This game has not been played yet.' })
    );

    render(<RefereeFeedbackModal eventId={501} apiUrl={API} onClose={() => {}} onSaved={() => {}} />);

    expect(await screen.findByText(/has not been played yet/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Save feedback/i })).not.toBeInTheDocument();
  });

  it('shows the server sentence when the feature is not switched on yet', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(
      eventResponse({ available: false, can_submit: false, reason: 'Referee feedback is not switched on for this club yet.' })
    );

    render(<RefereeFeedbackModal eventId={500} apiUrl={API} onClose={() => {}} onSaved={() => {}} />);

    expect(await screen.findByText(/not switched on/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Referee name/i)).not.toBeInTheDocument();
  });

  it('surfaces a 403 as an error rather than an empty form', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 403,
      json: async () => ({ success: false, error: 'Only a coach of a team on this game, or a club admin, can record referee feedback' }),
    });

    render(<RefereeFeedbackModal eventId={500} apiUrl={API} onClose={() => {}} onSaved={() => {}} />);

    expect(await screen.findByText(/Only a coach of a team on this game/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Referee name/i)).not.toBeInTheDocument();
  });

  it('asks which team when the caller has more than one on the game', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce(
      eventResponse({ teams: [{ id: 10, name: 'U12 Blue' }, { id: 11, name: 'U12 White' }] })
    );

    render(<RefereeFeedbackModal eventId={500} apiUrl={API} onClose={() => {}} onSaved={() => {}} />);

    const select = (await screen.findByLabelText(/Your team/i)) as HTMLSelectElement;
    expect(select.options.length).toBe(3); // placeholder + 2
  });
});

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import AthleteEvaluationsPanel from '../AthleteEvaluationsPanel';
import { AthleteEvaluation } from '../types';

jest.mock('recharts', () => {
  const Stub = ({ children }: any) => <div>{children}</div>;
  return {
    ResponsiveContainer: Stub,
    LineChart: Stub,
    Line: Stub,
    XAxis: Stub,
    YAxis: Stub,
    CartesianGrid: Stub,
    Tooltip: Stub,
  };
});

const evaluations: AthleteEvaluation[] = [
  {
    id: 2,
    athlete_id: 1,
    team_id: 10,
    team_name: 'Team A',
    evaluator_id: 50,
    evaluator_name: 'Cora Coach',
    evaluated_at: '2026-01-15',
    season_label: '2025-26',
    overall_score: 80,
    notes: 'Strong first half.',
    idp_goals: [{ goal: 'Weaker foot passing', target_date: '2026-04-01' }],
    scores: [
      { criterion_name: 'Technical Skills', score: 4, max_score: 5, weight: 1, comment: null },
    ],
    created_at: null,
    updated_at: null,
  },
  {
    id: 1,
    athlete_id: 1,
    team_id: null,
    team_name: null,
    evaluator_id: 50,
    evaluator_name: 'Cora Coach',
    evaluated_at: '2025-01-10',
    season_label: '2024-25',
    overall_score: 60,
    notes: null,
    idp_goals: [],
    scores: [],
    created_at: null,
    updated_at: null,
  },
];

function mockList(over: Record<string, unknown> = {}) {
  (global.fetch as jest.Mock).mockResolvedValueOnce({
    ok: true,
    json: async () => ({
      success: true,
      available: true,
      evaluations,
      can_evaluate: false,
      can_delete: false,
      viewer_id: 80,
      ...over,
    }),
  });
}

describe('AthleteEvaluationsPanel', () => {
  beforeEach(() => {
    global.fetch = jest.fn();
    localStorage.setItem('auth_token', 'test-token');
  });

  it('lists past evaluations and charts the two seasons', async () => {
    mockList();
    render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );

    // The row heading and the chart's accessible list both carry the label, so
    // the row is addressed as the disclosure BUTTON it is.
    await waitFor(() => expect(screen.getByRole('button', { name: /2025-26/ })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /2024-25/ })).toBeInTheDocument();
    expect(screen.getByText('80')).toBeInTheDocument();
    expect(screen.getByTestId('evaluation-trend-chart')).toBeInTheDocument();
  });

  it('shows the scores, notes and development plan when an evaluation is opened', async () => {
    mockList();
    render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /2025-26/ })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /2025-26/ }));

    expect(screen.getByText('Technical Skills')).toBeInTheDocument();
    expect(screen.getByText('Strong first half.')).toBeInTheDocument();
    expect(screen.getByText(/Weaker foot passing/)).toBeInTheDocument();
  });

  /**
   * The button follows the SERVER's answer, never a locally inferred role — a
   * button that leads to a 403 and a hidden button over an endpoint that would
   * have accepted are both symptoms of two answers to one question.
   */
  it('offers New evaluation only when the server says the viewer may write', async () => {
    mockList({ can_evaluate: false });
    const { unmount } = render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );
    await waitFor(() => expect(screen.getByRole('button', { name: /2025-26/ })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /new evaluation/i })).not.toBeInTheDocument();
    unmount();

    mockList({ can_evaluate: true });
    render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /new evaluation/i })).toBeInTheDocument()
    );
  });

  /**
   * The parent portal renders this same component. readOnly must suppress every
   * write control even if the API somehow answered can_evaluate — the portal is
   * a reading surface and a guardian's write is refused server-side anyway.
   */
  it('renders no write controls in readOnly mode even when the API says can_evaluate', async () => {
    mockList({ can_evaluate: true, can_delete: true });
    render(
      <AthleteEvaluationsPanel
        athleteId={1}
        athleteName="Anna Aaron"
        readOnly
        apiUrl="http://api.test"
      />
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /2025-26/ })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /new evaluation/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /2025-26/ }));
    expect(screen.queryByRole('button', { name: /^edit$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^delete$/i })).not.toBeInTheDocument();
  });

  /**
   * available:false is a migration that has not been applied, NOT an empty
   * history. Rendering "no evaluations recorded yet" there would state something
   * false about a child.
   */
  it('distinguishes a missing migration from an empty history', async () => {
    mockList({ available: false, evaluations: [] });
    const { unmount } = render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );
    await waitFor(() => expect(screen.getByText(/not switched on/i)).toBeInTheDocument());
    expect(screen.queryByText(/no evaluations recorded yet/i)).not.toBeInTheDocument();
    unmount();

    mockList({ evaluations: [] });
    render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );
    await waitFor(() =>
      expect(screen.getByText(/no evaluations recorded yet/i)).toBeInTheDocument()
    );
  });

  /**
   * A failed read must be a visible error, never a false empty. "No evaluations"
   * on a 500 is indistinguishable from a child who has never been evaluated.
   */
  it('shows an error rather than an empty list when the read fails', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      json: async () => ({ success: false, error: 'Access denied' }),
    });
    render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );

    await waitFor(() => expect(screen.getByText('Access denied')).toBeInTheDocument());
    expect(screen.queryByText(/no evaluations recorded yet/i)).not.toBeInTheDocument();
  });

  it('renders an unscored evaluation as a dash, not as zero', async () => {
    mockList({
      evaluations: [{ ...evaluations[0], overall_score: null }],
    });
    render(
      <AthleteEvaluationsPanel athleteId={1} athleteName="Anna Aaron" apiUrl="http://api.test" />
    );

    await waitFor(() => expect(screen.getByText('—')).toBeInTheDocument());
    expect(screen.queryByText('0')).not.toBeInTheDocument();
  });
});

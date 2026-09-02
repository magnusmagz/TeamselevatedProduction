import React from 'react';
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import EvaluationTrendChart, { buildSeasonSeries } from '../EvaluationTrendChart';
import { AthleteEvaluation } from '../types';

// recharts has no layout in jsdom (zero width, so it renders nothing and warns).
// Stub the pieces this chart uses, the same way EmailReporting.test.tsx does.
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

const evaluation = (over: Partial<AthleteEvaluation>): AthleteEvaluation => ({
  id: 1,
  athlete_id: 1,
  team_id: null,
  team_name: null,
  evaluator_id: 50,
  evaluator_name: 'Cora Coach',
  evaluated_at: '2026-01-15',
  season_label: '2025-26',
  overall_score: 70,
  notes: null,
  idp_goals: [],
  scores: [],
  created_at: null,
  updated_at: null,
  ...over,
});

describe('buildSeasonSeries', () => {
  it('produces one point per season across two seasons', () => {
    const series = buildSeasonSeries([
      evaluation({ id: 2, season_label: '2025-26', evaluated_at: '2026-01-15', overall_score: 80 }),
      evaluation({ id: 1, season_label: '2024-25', evaluated_at: '2025-01-10', overall_score: 60 }),
    ]);

    expect(series).toHaveLength(2);
    expect(series.map((p) => p.season)).toEqual(['2024-25', '2025-26']);
    expect(series.map((p) => p.score)).toEqual([60, 80]);
  });

  it('averages several evaluations in one season rather than plotting each', () => {
    // Three check-ins in one season must not read as three years of history.
    const series = buildSeasonSeries([
      evaluation({ id: 1, season_label: '2025-26', evaluated_at: '2025-09-01', overall_score: 60 }),
      evaluation({ id: 2, season_label: '2025-26', evaluated_at: '2026-01-15', overall_score: 70 }),
      evaluation({ id: 3, season_label: '2025-26', evaluated_at: '2026-04-01', overall_score: 80 }),
    ]);

    expect(series).toHaveLength(1);
    expect(series[0].score).toBe(70);
    expect(series[0].count).toBe(3);
  });

  it('orders by date, not by label, so a free-text season cannot invert the trend', () => {
    // Alphabetically 'Fall 2024' precedes 'Spring 2024'; chronologically it does
    // not. Sorting the strings would draw the improvement as a decline.
    const series = buildSeasonSeries([
      evaluation({ id: 1, season_label: 'Spring 2024', evaluated_at: '2024-03-01', overall_score: 50 }),
      evaluation({ id: 2, season_label: 'Fall 2024', evaluated_at: '2024-09-01', overall_score: 90 }),
    ]);

    expect(series.map((p) => p.season)).toEqual(['Spring 2024', 'Fall 2024']);
  });

  it('excludes unscored evaluations instead of charting them as zero', () => {
    const series = buildSeasonSeries([
      evaluation({ id: 1, season_label: '2024-25', evaluated_at: '2025-01-10', overall_score: null }),
      evaluation({ id: 2, season_label: '2025-26', evaluated_at: '2026-01-15', overall_score: 80 }),
    ]);

    expect(series).toHaveLength(1);
    expect(series[0].season).toBe('2025-26');
  });
});

describe('EvaluationTrendChart', () => {
  it('charts two seasons', () => {
    render(
      <EvaluationTrendChart
        evaluations={[
          evaluation({ id: 1, season_label: '2024-25', evaluated_at: '2025-01-10', overall_score: 60 }),
          evaluation({ id: 2, season_label: '2025-26', evaluated_at: '2026-01-15', overall_score: 80 }),
        ]}
      />
    );

    expect(screen.getByTestId('evaluation-trend-chart')).toBeInTheDocument();
    // The accessible list carries the same numbers the line does, so the chart
    // is not the only way to read the data.
    expect(screen.getByText('2024-25: 60')).toBeInTheDocument();
    expect(screen.getByText('2025-26: 80')).toBeInTheDocument();
  });

  it('says so rather than drawing a single-point line for one season', () => {
    render(<EvaluationTrendChart evaluations={[evaluation({ overall_score: 70 })]} />);

    expect(screen.queryByTestId('evaluation-trend-chart')).not.toBeInTheDocument();
    expect(screen.getByText(/second season/i)).toBeInTheDocument();
  });

  it('renders nothing when no evaluation has been scored', () => {
    const { container } = render(
      <EvaluationTrendChart evaluations={[evaluation({ overall_score: null })]} />
    );
    expect(container).toBeEmptyDOMElement();
  });
});

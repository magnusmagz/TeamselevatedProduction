import React from 'react';
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
} from 'recharts';
import { AthleteEvaluation } from './types';

/**
 * Year-over-year overall score, by season (CKU R77).
 *
 * recharts is already a dependency (frontend/package.json, used by
 * EmailReporting) so this adds nothing to the bundle that was not already there.
 */

export interface SeasonPoint {
  season: string;
  /** Mean of the season's scored evaluations, to one decimal. */
  score: number;
  /** How many evaluations that mean is drawn from — shown in the tooltip. */
  count: number;
  /** Earliest evaluated_at in the season; the sort key. */
  firstDate: string;
}

/**
 * Collapse a flat evaluation list into one point per season.
 *
 * Three decisions worth keeping:
 *
 *  - **A season with several evaluations becomes their MEAN, and the tooltip
 *    says how many.** Showing each evaluation as its own point would make a
 *    season with three check-ins look like three years of history, which is the
 *    opposite of what a year-over-year graph is for.
 *  - **Unscored evaluations are excluded, and a season with nothing scored does
 *    not appear at all.** overall_score is null when a coach recorded notes and
 *    goals without scoring anything; treating that as 0 would draw a collapse
 *    that never happened.
 *  - **Seasons are ordered by their earliest date, not alphabetically.** Labels
 *    are free text ('2025-26', 'Fall 2025', 'Spring 26'), so sorting the strings
 *    would put the x-axis in an arbitrary order and silently invert a trend.
 */
export function buildSeasonSeries(evaluations: AthleteEvaluation[]): SeasonPoint[] {
  const bySeason = new Map<string, { total: number; count: number; firstDate: string }>();

  evaluations.forEach((e) => {
    if (e.overall_score === null || e.overall_score === undefined) return;
    const season = (e.season_label || '').trim();
    if (!season) return;

    const existing = bySeason.get(season);
    if (existing) {
      existing.total += e.overall_score;
      existing.count += 1;
      // String comparison, not Date comparison: these are YYYY-MM-DD values and
      // lexical order IS chronological order for that format. Parsing them would
      // reintroduce the timezone bug that shifted Schedule Practices by a day.
      if (e.evaluated_at && e.evaluated_at < existing.firstDate) {
        existing.firstDate = e.evaluated_at;
      }
    } else {
      bySeason.set(season, {
        total: e.overall_score,
        count: 1,
        firstDate: e.evaluated_at || '',
      });
    }
  });

  return Array.from(bySeason.entries())
    .map(([season, agg]) => ({
      season,
      score: Math.round((agg.total / agg.count) * 10) / 10,
      count: agg.count,
      firstDate: agg.firstDate,
    }))
    .sort((a, b) => (a.firstDate < b.firstDate ? -1 : a.firstDate > b.firstDate ? 1 : 0));
}

interface EvaluationTrendChartProps {
  evaluations: AthleteEvaluation[];
}

export const EvaluationTrendChart: React.FC<EvaluationTrendChartProps> = ({ evaluations }) => {
  const series = buildSeasonSeries(evaluations);

  if (series.length === 0) {
    return null;
  }

  // One season is a dot, not a trend. Saying so is better than drawing a
  // single-point "line" that implies more history than exists.
  if (series.length === 1) {
    return (
      <div className="border border-brand-secondary rounded-md p-4 mb-6">
        <div className="text-sm font-bold uppercase tracking-wide mb-1">Year over year</div>
        <p className="text-sm text-gray-600">
          One season on file ({series[0].season}, overall {series[0].score}). A second season
          of evaluations will chart the trend here.
        </p>
      </div>
    );
  }

  return (
    <div className="border border-brand-secondary rounded-md p-4 mb-6">
      <div className="text-sm font-bold uppercase tracking-wide mb-3">Year over year</div>
      <div style={{ width: '100%', height: 220 }} data-testid="evaluation-trend-chart">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={series} margin={{ top: 8, right: 16, bottom: 8, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
            <XAxis dataKey="season" tick={{ fontSize: 12 }} />
            {/* Fixed 0-100 domain: the score is already a percentage, and an
                auto-scaled axis would make a 2-point improvement look dramatic. */}
            <YAxis domain={[0, 100]} tick={{ fontSize: 12 }} />
            <Tooltip
              formatter={(value: any, _name: any, entry: any) => [
                `${value} (${entry?.payload?.count ?? 1} evaluation${
                  (entry?.payload?.count ?? 1) === 1 ? '' : 's'
                })`,
                'Overall',
              ]}
            />
            <Line
              type="monotone"
              dataKey="score"
              stroke="#1f5c3d"
              strokeWidth={2}
              dot={{ r: 4 }}
            />
          </LineChart>
        </ResponsiveContainer>
      </div>
      {/* The chart is not the only way to read this. Everything it plots is in
          the list below it, which is what a screen reader and a printout get. */}
      <ul className="sr-only">
        {series.map((p) => (
          <li key={p.season}>{`${p.season}: ${p.score}`}</li>
        ))}
      </ul>
    </div>
  );
};

export default EvaluationTrendChart;

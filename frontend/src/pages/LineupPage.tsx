import React from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import LineupBuilder from '../components/lineup/LineupBuilder';

/**
 * /teams/:teamId/lineup?event=:eventId — the coach's lineup screen. Without
 * ?event it edits the team's default (template) lineup.
 */
const LineupPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { teamId } = useParams<{ teamId: string }>();
  const [params] = useSearchParams();
  const eventParam = params.get('event');
  const eventId = eventParam && /^\d+$/.test(eventParam) ? parseInt(eventParam, 10) : null;
  const id = teamId && /^\d+$/.test(teamId) ? parseInt(teamId, 10) : 0;

  if (!id) {
    return <main className="p-6 text-center text-brand-primary">Team not found</main>;
  }
  const printHref = `/teams/${id}/lineup/print${eventId ? `?event=${eventId}` : ''}`;
  return (
    <main className="min-h-screen bg-gray-50">
      <div className="mx-auto max-w-lg px-4 pt-3 text-sm">
        <Link to={`/team/${id}`} className="text-brand-primary hover:underline">← Team</Link>
      </div>
      <LineupBuilder teamId={id} eventId={eventId} apiUrl={API_URL} printHref={printHref} />
    </main>
  );
};

export default LineupPage;

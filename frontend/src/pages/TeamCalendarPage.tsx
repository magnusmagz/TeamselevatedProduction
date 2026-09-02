import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import TeamCalendarView from '../components/TeamCalendarView';

interface Team {
  id: number;
  name: string;
  age_group?: string;
  division?: string;
}

const TeamCalendarPage: React.FC = () => {
  const { teamId } = useParams<{ teamId: string }>();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [team, setTeam] = useState<Team | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchTeam = async () => {
      if (!teamId) {
        setError('No team ID provided');
        setLoading(false);
        return;
      }

      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${API_URL}/legacy/teams-gateway.php?id=${teamId}`, {
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });

        const data = await response.json();

        if (data.error) {
          setError(data.error);
        } else if (data.id) {
          setTeam({
            id: data.id,
            name: data.name,
            age_group: data.age_group,
            division: data.division
          });
        } else {
          setError('Team not found');
        }
      } catch (err: any) {
        setError(`Failed to load team: ${err?.message}`);
      } finally {
        setLoading(false);
      }
    };

    fetchTeam();
  }, [teamId, API_URL]);

  if (loading) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="animate-pulse">
          <div className="h-6 bg-gray-200 rounded w-32 mb-4"></div>
          <div className="h-10 bg-gray-200 rounded w-64 mb-2"></div>
          <div className="h-4 bg-gray-200 rounded w-48 mb-8"></div>
          <div className="h-96 bg-gray-200 rounded"></div>
        </div>
      </div>
    );
  }

  if (error || !team) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
          <h2 className="text-xl font-bold text-red-800 mb-2">Error</h2>
          <p className="text-red-600 mb-4">{error || 'Team not found'}</p>
          <Link
            to="/teams"
            className="inline-block bg-brand-primary text-white px-6 py-2 rounded font-medium hover:opacity-90"
          >
            Back to Teams
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div>
      {/* Breadcrumb Navigation */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6">
        <nav className="flex items-center space-x-2 text-sm">
          <Link to="/teams" className="text-brand-primary hover:underline">
            Teams
          </Link>
          <span className="text-gray-400">/</span>
          <Link to={`/team/${team.id}`} className="text-brand-primary hover:underline">
            {team.name}
          </Link>
          <span className="text-gray-400">/</span>
          <span className="text-gray-600">Calendar</span>
        </nav>
      </div>

      {/* Calendar View */}
      <TeamCalendarView
        teamId={team.id}
        teamName={team.name}
        title={`${team.name} Calendar`}
        showTeamFilter={false}
        showAddEvent={true}
        readOnly={false}
      />

      {/* Link to Club Calendar */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-8">
        <div className="text-center">
          <Link
            to="/calendar"
            className="text-brand-primary hover:underline text-sm uppercase font-medium"
          >
            View Full Club Calendar &rarr;
          </Link>
        </div>
      </div>
    </div>
  );
};

export default TeamCalendarPage;

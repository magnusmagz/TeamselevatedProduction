import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import PlayerCard from '../components/PlayerCard';

interface TeamMember {
  id: number;
  athlete_id: number;
  first_name: string;
  last_name: string;
  date_of_birth?: string;
  photo_url?: string;
  gender?: string;
  jersey_number?: number;
  primary_position?: string;
}

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

const PlayerCards: React.FC = () => {
  const { teamId } = useParams<{ teamId: string }>();
  const { activeContext } = useOrg();
  const [teamName, setTeamName] = useState('');
  const [roster, setRoster] = useState<TeamMember[]>([]);
  const [loading, setLoading] = useState(true);

  const clubName = activeContext?.scope_name || '';

  useEffect(() => {
    const fetchRoster = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${API_URL}/legacy/team-players-gateway.php?team_id=${teamId}`, {
          headers: token ? { 'Authorization': `Bearer ${token}` } : {},
        });
        const data = await response.json();
        if (data.success && data.team_members) {
          setRoster(data.team_members.map((tm: any) => ({
            id: tm.id,
            athlete_id: tm.athlete_id,
            first_name: tm.first_name,
            last_name: tm.last_name,
            date_of_birth: tm.date_of_birth || undefined,
            photo_url: tm.photo_url || undefined,
            gender: tm.gender || undefined,
            jersey_number: tm.jersey_number ? parseInt(tm.jersey_number) : undefined,
            primary_position: tm.primary_position || undefined,
          })));
          // Extract team name from first member or response
          if (data.team_name) {
            setTeamName(data.team_name);
          } else if (data.team_members.length > 0 && data.team_members[0].team_name) {
            setTeamName(data.team_members[0].team_name);
          }
        }
      } catch (error) {
        console.error('Error fetching roster:', error);
      } finally {
        setLoading(false);
      }
    };

    if (teamId) {
      fetchRoster();
    }
  }, [teamId]);

  return (
    <>
      {/* Print styles */}
      <style>{`
        @media print {
          body * {
            visibility: hidden;
          }
          .player-cards-print-area,
          .player-cards-print-area * {
            visibility: visible;
          }
          .player-cards-print-area {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
          }
          .no-print {
            display: none !important;
          }
          .player-cards-grid {
            display: grid;
            grid-template-columns: repeat(2, 3.375in);
            grid-template-rows: repeat(4, 2.125in);
            gap: 0.15in;
            justify-content: center;
            page-break-after: always;
          }
          .player-card {
            box-shadow: none !important;
            border: 1px solid #ccc !important;
            border-radius: 6px !important;
          }
          @page {
            size: letter landscape;
            margin: 0.3in;
          }
        }
      `}</style>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Header */}
        <div className="no-print mb-6 flex items-center justify-between border-b border-brand-secondary pb-6">
          <div>
            <div className="mb-2">
              <Link to={`/teams`} className="text-sm text-brand-primary hover:underline">
                &larr; Back to Teams
              </Link>
            </div>
            <h1 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">
              {teamName ? `${teamName} \u2014 Player Cards` : 'Player Cards'}
            </h1>
            <p className="text-gray-600 mt-1">{roster.length} players</p>
          </div>
          <div className="flex gap-3">
            <button
              disabled
              title="Coming soon"
              className="bg-gray-200 text-gray-500 border border-gray-300 rounded-md px-4 py-2 uppercase font-semibold text-sm cursor-not-allowed"
            >
              Game Day
            </button>
            <button
              onClick={() => window.print()}
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:opacity-90 uppercase font-semibold text-sm"
            >
              Print Cards
            </button>
          </div>
        </div>

        {/* Cards */}
        {loading ? (
          <div className="text-center text-brand-primary py-12">Loading player cards...</div>
        ) : roster.length === 0 ? (
          <div className="text-center text-gray-500 py-12">No players on this team yet.</div>
        ) : (
          <div className="player-cards-print-area">
            <div className="player-cards-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4 justify-items-center">
              {roster.map((member, index) => (
                <React.Fragment key={member.athlete_id}>
                  {/* Page break every 8 cards for print */}
                  {index > 0 && index % 8 === 0 && (
                    <div className="hidden print:block col-span-2" style={{ pageBreakBefore: 'always' }} />
                  )}
                  <PlayerCard
                    player={{
                      id: member.athlete_id,
                      first_name: member.first_name,
                      last_name: member.last_name,
                      date_of_birth: member.date_of_birth,
                      photo_url: member.photo_url,
                      gender: member.gender,
                    }}
                    team={{
                      name: teamName,
                      jersey_number: member.jersey_number,
                      primary_position: member.primary_position,
                    }}
                    club={{
                      name: clubName,
                    }}
                  />
                </React.Fragment>
              ))}
            </div>
          </div>
        )}
      </div>
    </>
  );
};

export default PlayerCards;

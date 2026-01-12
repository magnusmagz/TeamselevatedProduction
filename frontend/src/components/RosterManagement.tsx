import React, { useState, useEffect } from 'react';

interface Team {
  id: number;
  name: string;
}

interface Athlete {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  created_at: string;
}

interface RosterManagementProps {
  team: Team;
}

const RosterManagement: React.FC<RosterManagementProps> = ({ team }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [roster, setRoster] = useState<Athlete[]>([]);
  const [loading, setLoading] = useState(true);
  const [availableAthletes, setAvailableAthletes] = useState<Athlete[]>([]);
  const [allAthletes, setAllAthletes] = useState<Athlete[]>([]);
  const [addingAthleteId, setAddingAthleteId] = useState<number | null>(null);

  const fetchRoster = async () => {
    try {
      const response = await fetch(`${API_URL}/legacy/team-players-gateway.php?team_id=${team.id}`);
      const data = await response.json();
      if (data.success && data.team_players) {
        // Transform team_players data to athlete format
        const athletes = data.team_players.map((tp: any) => ({
          id: tp.user_id,
          first_name: tp.first_name,
          last_name: tp.last_name,
          email: tp.email,
          created_at: tp.created_at || ''
        }));
        setRoster(athletes);
      }
    } catch (error) {
      console.error('Error fetching roster:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchAllAthletes = async () => {
    try {
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php`);
      const data = await response.json();
      const athletes = data.athletes || [];
      setAllAthletes(athletes);
    } catch (error) {
      console.error('Error fetching all athletes:', error);
      setAllAthletes([]);
    }
  };

  const filterAvailableAthletes = () => {
    const teamAthleteIds = roster.map(athlete => athlete.id);
    const available = allAthletes.filter(athlete => !teamAthleteIds.includes(athlete.id));
    setAvailableAthletes(available);
  };

  useEffect(() => {
    fetchRoster();
    fetchAllAthletes();
  }, [team.id]);

  useEffect(() => {
    filterAvailableAthletes();
  }, [allAthletes, roster]);

  const handleRemoveAthlete = async (athleteId: number) => {
    if (!window.confirm('Are you sure you want to remove this athlete from the team?')) return;

    try {
      const response = await fetch(`${API_URL}/legacy/team-players-gateway.php?team_id=${team.id}&player_id=${athleteId}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' }
      });

      if (response.ok) {
        fetchRoster();
      }
    } catch (error) {
      console.error('Error removing athlete:', error);
    }
  };

  const handleAddAthlete = async (athlete: Athlete) => {
    setAddingAthleteId(athlete.id);
    try {
      const response = await fetch(`${API_URL}/legacy/team-players-gateway.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          team_id: team.id,
          player_id: athlete.id,
          status: 'active'
        })
      });

      const data = await response.json();

      if (data.success) {
        await fetchRoster();
      } else {
        console.error('Failed to add athlete:', data.error || data.message);
        alert('Failed to add athlete: ' + (data.error || data.message || 'Unknown error'));
      }
    } catch (error) {
      console.error('Error adding athlete to team:', error);
      alert('Error adding athlete to team');
    } finally {
      setAddingAthleteId(null);
    }
  };


  return (
    <div>
      <div className="mb-6 flex justify-between items-center">
        <div>
          <h2 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">{team.name} Roster</h2>
          <p className="text-gray-600 mt-2">{roster.length} players total</p>
        </div>
      </div>


      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading roster...</div>
      ) : (
        <div className="grid grid-cols-2 gap-6">
          {/* Available Athletes */}
          <div className="bg-white border border-brand-secondary rounded-md p-4">
            <h3 className="text-xl font-bold text-brand-primary mb-4 uppercase tracking-wide">
              Available Athletes
            </h3>
            <div className="space-y-2 max-h-96 overflow-y-auto">
              {availableAthletes.map((athlete) => (
                <div
                  key={athlete.id}
                  className="bg-gray-50 border border-gray-300 p-3 hover:bg-gray-100 transition-colors flex justify-between items-center"
                >
                  <div>
                    <div className="font-medium text-brand-primary">
                      {athlete.first_name} {athlete.last_name}
                    </div>
                    <div className="text-sm text-gray-600">
                      {athlete.email}
                    </div>
                  </div>
                  <button
                    onClick={() => handleAddAthlete(athlete)}
                    disabled={addingAthleteId === athlete.id}
                    className="bg-brand-primary text-white px-3 py-1 rounded text-sm font-medium hover:opacity-90 disabled:opacity-50 flex items-center gap-1"
                  >
                    {addingAthleteId === athlete.id ? (
                      'Adding...'
                    ) : (
                      <>Add <span aria-hidden="true">→</span></>
                    )}
                  </button>
                </div>
              ))}
              {availableAthletes.length === 0 && (
                <div className="text-center text-gray-500 py-8">
                  No available athletes to add
                </div>
              )}
            </div>
          </div>

          {/* Team Roster */}
          <div className="bg-white border-2 border-brand-primary p-4">
            <h3 className="text-xl font-bold text-brand-primary mb-4 uppercase tracking-wide">
              Team Roster ({roster.length} players)
            </h3>
            <div className="space-y-2 max-h-96 overflow-y-auto">
              {roster.map((athlete) => (
                <div
                  key={athlete.id}
                  className="bg-brand-secondary border border-brand-secondary p-3"
                >
                  <div className="flex justify-between items-start">
                    <div>
                      <div className="font-medium text-brand-primary">
                        {athlete.first_name} {athlete.last_name}
                      </div>
                      <div className="text-sm text-brand-primary">
                        {athlete.email}
                      </div>
                    </div>
                    <button
                      onClick={() => handleRemoveAthlete(athlete.id)}
                      className="text-red-600 hover:text-red-800 text-sm uppercase"
                    >
                      Remove
                    </button>
                  </div>
                </div>
              ))}
              {roster.length === 0 && (
                <div className="text-center py-8 border-2 border-dashed border-gray-300 text-gray-500">
                  Click "Add →" on an athlete to add them to the team
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default RosterManagement;
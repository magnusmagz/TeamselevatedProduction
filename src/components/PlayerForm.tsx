import React, { useState, useEffect } from 'react';

interface PlayerFormProps {
  team: { id: number; name: string };
  player: any | null;
  onSubmit: () => void;
  onClose: () => void;
}

const POSITIONS = ['Goalkeeper', 'Defender', 'Midfielder', 'Forward', 'Striker'];

const PlayerForm: React.FC<PlayerFormProps> = ({ team, player, onSubmit, onClose }) => {
  const [formData, setFormData] = useState({
    user_id: '',
    positions: [] as string[],
    primary_position: '',
    jersey_number: '',
    jersey_number_alt: '',
    team_priority: 'primary',
    status: 'active',
    position_assignments: [] as { position: string; jersey_number: string }[]
  });

  const [searchTerm, setSearchTerm] = useState('');
  const [searchResults, setSearchResults] = useState<any[]>([]);
  const [searching, setSearching] = useState(false);

  useEffect(() => {
    if (player) {
      setFormData({
        user_id: player.user_id,
        positions: player.positions || [],
        primary_position: player.primary_position || '',
        jersey_number: player.jersey_number?.toString() || '',
        jersey_number_alt: player.jersey_number_alt?.toString() || '',
        team_priority: player.team_priority || 'primary',
        status: player.status || 'active',
        position_assignments: player.position_jerseys || []
      });
    }
  }, [player]);

  const searchPlayers = async () => {
    if (searchTerm.length < 2) return;

    setSearching(true);
    try {
      const response = await fetch(
        `http://localhost:8888/teamselevated-backend/api/coach/players/search?search=${searchTerm}&exclude_team=${team.id}`
      );
      const data = await response.json();
      setSearchResults(data);
    } catch (error) {
      console.error('Error searching players:', error);
    } finally {
      setSearching(false);
    }
  };

  const selectPlayer = (player: any) => {
    setFormData({ ...formData, user_id: player.id });
    setSearchResults([]);
    setSearchTerm(`${player.first_name} ${player.last_name}`);
  };

  const togglePosition = (position: string) => {
    const newPositions = formData.positions.includes(position)
      ? formData.positions.filter(p => p !== position)
      : [...formData.positions, position];

    const newAssignments = newPositions.map(pos => {
      const existing = formData.position_assignments.find(pa => pa.position === pos);
      return existing || { position: pos, jersey_number: formData.jersey_number };
    });

    setFormData({
      ...formData,
      positions: newPositions,
      position_assignments: newAssignments,
      primary_position: newPositions.includes(formData.primary_position)
        ? formData.primary_position
        : newPositions[0] || ''
    });
  };

  const updatePositionJersey = (position: string, jersey: string) => {
    const newAssignments = formData.position_assignments.map(pa =>
      pa.position === position ? { ...pa, jersey_number: jersey } : pa
    );
    setFormData({ ...formData, position_assignments: newAssignments });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const url = player
      ? `http://localhost:8888/teamselevated-backend/api/coach/teams/${team.id}/roster/${player.user_id}/positions`
      : `http://localhost:8888/teamselevated-backend/api/coach/teams/${team.id}/roster`;

    const method = player ? 'PUT' : 'POST';

    try {
      const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        onSubmit();
      } else {
        const error = await response.json();
        alert(error.errors ? Object.values(error.errors).join('\n') : error.error);
      }
    } catch (error) {
      console.error('Error saving player:', error);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-forest-800 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div className="bg-forest-950 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-white">
            {player ? 'Edit Player' : 'Add Player to Roster'}
          </h3>
          <button
            onClick={onClose}
            className="text-forest-200 hover:text-white text-2xl"
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          {!player && (
            <div className="mb-6">
              <label className="block text-forest-200 text-sm font-medium mb-2">
                Search Player *
              </label>
              <div className="relative">
                <input
                  type="text"
                  className="w-full bg-forest-700 text-white px-4 py-2"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  onKeyUp={searchPlayers}
                  placeholder="Search by name or email..."
                />
                {searchResults.length > 0 && (
                  <div className="absolute z-10 w-full mt-1 bg-forest-700 border border-forest-600 max-h-48 overflow-y-auto">
                    {searchResults.map(player => (
                      <div
                        key={player.id}
                        className="px-4 py-2 hover:bg-forest-600 cursor-pointer text-white"
                        onClick={() => selectPlayer(player)}
                      >
                        <div>{player.first_name} {player.last_name}</div>
                        <div className="text-sm text-forest-400">{player.email}</div>
                        {player.current_teams && (
                          <div className="text-xs text-forest-500">
                            Current teams: {player.current_teams}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                )}
                {searching && (
                  <div className="absolute right-2 top-2 text-forest-400">Searching...</div>
                )}
              </div>
            </div>
          )}

          <div className="mb-6">
            <label className="block text-forest-200 text-sm font-medium mb-2">
              Positions * (Select all that apply)
            </label>
            <div className="grid grid-cols-3 gap-2">
              {POSITIONS.map(position => (
                <label
                  key={position}
                  className={`px-4 py-2 cursor-pointer ${
                    formData.positions.includes(position)
                      ? 'bg-forest-600 text-white'
                      : 'bg-forest-700 text-forest-200'
                  }`}
                >
                  <input
                    type="checkbox"
                    className="mr-2"
                    checked={formData.positions.includes(position)}
                    onChange={() => togglePosition(position)}
                  />
                  {position}
                </label>
              ))}
            </div>
          </div>

          {formData.positions.length > 0 && (
            <>
              <div className="mb-6">
                <label className="block text-forest-200 text-sm font-medium mb-2">
                  Primary Position *
                </label>
                <select
                  className="w-full bg-forest-700 text-white px-4 py-2"
                  value={formData.primary_position}
                  onChange={(e) => setFormData({ ...formData, primary_position: e.target.value })}
                  required
                >
                  <option value="">Select primary position...</option>
                  {formData.positions.map(pos => (
                    <option key={pos} value={pos}>{pos}</option>
                  ))}
                </select>
              </div>

              <div className="mb-6">
                <label className="block text-forest-200 text-sm font-medium mb-2">
                  Position-Specific Jersey Numbers
                </label>
                <div className="space-y-2">
                  {formData.positions.map(position => (
                    <div key={position} className="flex items-center space-x-4">
                      <span className="text-white w-32">{position}:</span>
                      <input
                        type="number"
                        className="bg-forest-700 text-white px-4 py-2 w-24"
                        value={formData.position_assignments.find(pa => pa.position === position)?.jersey_number || ''}
                        onChange={(e) => updatePositionJersey(position, e.target.value)}
                        min="0"
                        max="99"
                        placeholder="#"
                      />
                    </div>
                  ))}
                </div>
              </div>
            </>
          )}

          <div className="grid grid-cols-2 gap-4 mb-6">
            <div>
              <label className="block text-forest-200 text-sm font-medium mb-2">
                Primary Jersey Number
              </label>
              <input
                type="number"
                className="w-full bg-forest-700 text-white px-4 py-2"
                value={formData.jersey_number}
                onChange={(e) => setFormData({ ...formData, jersey_number: e.target.value })}
                min="0"
                max="99"
              />
            </div>

            <div>
              <label className="block text-forest-200 text-sm font-medium mb-2">
                Alternate Jersey Number
              </label>
              <input
                type="number"
                className="w-full bg-forest-700 text-white px-4 py-2"
                value={formData.jersey_number_alt}
                onChange={(e) => setFormData({ ...formData, jersey_number_alt: e.target.value })}
                min="0"
                max="99"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4 mb-6">
            <div>
              <label className="block text-forest-200 text-sm font-medium mb-2">
                Team Priority
              </label>
              <select
                className="w-full bg-forest-700 text-white px-4 py-2"
                value={formData.team_priority}
                onChange={(e) => setFormData({ ...formData, team_priority: e.target.value as any })}
              >
                <option value="primary">Primary Team</option>
                <option value="secondary">Secondary Team</option>
                <option value="guest">Guest Player</option>
              </select>
            </div>

            <div>
              <label className="block text-forest-200 text-sm font-medium mb-2">
                Status
              </label>
              <select
                className="w-full bg-forest-700 text-white px-4 py-2"
                value={formData.status}
                onChange={(e) => setFormData({ ...formData, status: e.target.value as any })}
              >
                <option value="active">Active</option>
                <option value="injured">Injured</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
          </div>

          <div className="flex justify-end space-x-4">
            <button
              type="button"
              onClick={onClose}
              className="bg-forest-600 text-white px-6 py-2 hover:bg-forest-500"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="bg-forest-500 text-white px-6 py-2 hover:bg-forest-400 font-semibold"
            >
              {player ? 'Update Player' : 'Add Player'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default PlayerForm;
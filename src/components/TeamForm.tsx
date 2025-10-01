import React, { useState, useEffect } from 'react';

interface TeamFormProps {
  team: any | null;
  onSubmit: (data: any) => void;
  onClose: () => void;
}

const TeamForm: React.FC<TeamFormProps> = ({ team, onSubmit, onClose }) => {
  const [formData, setFormData] = useState({
    name: '',
    age_group: 'U10',
    division: 'Recreational',
    season_id: '',
    primary_coach_id: '',
    home_field_id: '',
    max_players: 20
  });

  const [coaches, setCoaches] = useState<any[]>([]);
  const [seasons, setSeasons] = useState<any[]>([]);
  const [fields, setFields] = useState<any[]>([]);
  const [errors, setErrors] = useState<any>({});

  useEffect(() => {
    if (team) {
      setFormData({
        name: team.name,
        age_group: team.age_group,
        division: team.division,
        season_id: team.season_id,
        primary_coach_id: team.primary_coach_id || '',
        home_field_id: team.home_field_id || '',
        max_players: team.max_players || 20
      });
    }
    fetchDropdownData();
  }, [team]);

  const fetchDropdownData = async () => {
    try {
      // Fetch each resource independently to handle failures gracefully

      // Fetch coaches
      try {
        const coachesRes = await fetch('http://localhost:8889/coaches-gateway.php?action=available');
        if (coachesRes.ok) {
          const coachesData = await coachesRes.json();
          setCoaches(coachesData || []);
        }
      } catch (error) {
        console.error('Error fetching coaches:', error);
        setCoaches([]);
      }

      // Fetch seasons
      try {
        const seasonsRes = await fetch('http://localhost:8889/seasons-gateway.php?action=list');
        if (seasonsRes.ok) {
          const seasonsData = await seasonsRes.json();
          console.log('Seasons data received:', seasonsData);
          setSeasons(seasonsData.success ? seasonsData.seasons : []);
        }
      } catch (error) {
        console.error('Error fetching seasons:', error);
        setSeasons([]);
      }

      // Fetch fields
      try {
        const fieldsRes = await fetch('http://localhost:8889/fields-gateway.php');
        if (fieldsRes.ok) {
          const fieldsData = await fieldsRes.json();
          setFields(fieldsData || []);
        }
      } catch (error) {
        console.error('Error fetching fields:', error);
        setFields([]);
      }
    } catch (error) {
      console.error('Error in fetchDropdownData:', error);
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const newErrors: any = {};

    if (!formData.name || formData.name.length > 100) {
      newErrors.name = 'Team name is required and must be less than 100 characters';
    }

    if (!formData.age_group) {
      newErrors.age_group = 'Age group is required';
    }

    if (!formData.division) {
      newErrors.division = 'Division is required';
    }

    if (!formData.season_id) {
      newErrors.season_id = 'Season is required';
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      return;
    }

    onSubmit(formData);
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-2xl">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
            {team ? 'Edit Team' : 'Create New Team'}
          </h3>
          <button
            onClick={onClose}
            className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Team Name *
              </label>
              <input
                type="text"
                className={`w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600 ${
                  errors.name ? 'border-2 border-red-500' : ''
                }`}
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g., Lightning U12"
              />
              {errors.name && (
                <p className="text-red-400 text-sm mt-1">{errors.name}</p>
              )}
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Age Group *
              </label>
              <select
                className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                value={formData.age_group}
                onChange={(e) => setFormData({ ...formData, age_group: e.target.value })}
              >
                <option value="U6">U6</option>
                <option value="U8">U8</option>
                <option value="U10">U10</option>
                <option value="U12">U12</option>
                <option value="U14">U14</option>
                <option value="U16">U16</option>
                <option value="U18">U18</option>
                <option value="Adult">Adult</option>
              </select>
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Division *
              </label>
              <select
                className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                value={formData.division}
                onChange={(e) => setFormData({ ...formData, division: e.target.value })}
              >
                <option value="Recreational">Recreational</option>
                <option value="Competitive">Competitive</option>
                <option value="Elite">Elite</option>
              </select>
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Season *
              </label>
              <select
                className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                value={formData.season_id}
                onChange={(e) => setFormData({ ...formData, season_id: e.target.value })}
              >
                <option value="">Select a season...</option>
                {seasons && seasons.length > 0 ? (
                  seasons.map((season) => (
                    <option key={season.id} value={season.id}>
                      {season.name}
                    </option>
                  ))
                ) : (
                  <option disabled>No seasons available</option>
                )}
              </select>
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Head Coach
              </label>
              <select
                className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                value={formData.primary_coach_id}
                onChange={(e) => setFormData({ ...formData, primary_coach_id: e.target.value })}
              >
                <option value="">Select a coach...</option>
                {coaches.map((coach) => (
                  <option key={coach.id} value={coach.id}>
                    {coach.first_name} {coach.last_name}
                    {coach.team_count > 0 && ` (${coach.team_count} teams)`}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Home Field
              </label>
              <select
                className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                value={formData.home_field_id}
                onChange={(e) => setFormData({ ...formData, home_field_id: e.target.value })}
              >
                <option value="">Select a field...</option>
                {fields.map((field) => (
                  <option key={field.id} value={field.id}>
                    {field.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Max Players
              </label>
              <input
                type="number"
                className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                value={formData.max_players}
                onChange={(e) => setFormData({ ...formData, max_players: parseInt(e.target.value) })}
                min="1"
                max="50"
              />
            </div>
          </div>

          <div className="flex justify-end space-x-4 mt-6">
            <button
              type="button"
              onClick={onClose}
              className="bg-white text-forest-800 border-2 border-forest-800 px-6 py-2 hover:bg-gray-100 uppercase"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 font-semibold uppercase"
            >
              {team ? 'Update Team' : 'Create Team'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default TeamForm;
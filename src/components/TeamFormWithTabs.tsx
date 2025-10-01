import React, { useState, useEffect } from 'react';
import { LogoColorExtractor } from './LogoColorExtractor';

interface TeamFormProps {
  team: any | null;
  onSubmit: (data: any) => void;
  onClose: () => void;
}

const TeamFormWithTabs: React.FC<TeamFormProps> = ({ team, onSubmit, onClose }) => {
  const [activeTab, setActiveTab] = useState<'info' | 'branding'>('info');
  const [formData, setFormData] = useState({
    name: '',
    age_group: 'U10',
    division: 'Recreational',
    season_id: '',
    primary_coach_id: '',
    home_field_id: '',
    max_players: 20,
    logo_data: '',
    logo_filename: '',
    primary_color: '',
    secondary_color: '',
    accent_color: ''
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
        max_players: team.max_players || 20,
        logo_data: team.logo_data || '',
        logo_filename: team.logo_filename || '',
        primary_color: team.primary_color || '',
        secondary_color: team.secondary_color || '',
        accent_color: team.accent_color || ''
      });
    }
    fetchDropdownData();
  }, [team]);

  const fetchDropdownData = async () => {
    try {
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
      setActiveTab('info'); // Switch to info tab if there are validation errors
      return;
    }

    onSubmit(formData);
  };

  const handleBrandingUpdate = async (brandingData: any) => {
    const updatedData = {
      ...formData,
      logo_data: brandingData.logoBase64,
      logo_filename: brandingData.logoFilename,
      primary_color: brandingData.primaryColor,
      secondary_color: brandingData.secondaryColor,
      accent_color: brandingData.accentColor
    };
    setFormData(updatedData);

    // If editing an existing team, save immediately
    if (team?.id) {
      onSubmit(updatedData);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="border-b-2 border-forest-800 px-6 py-4">
          <div className="flex justify-between items-center mb-4">
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

          {/* Tabs */}
          <div className="flex space-x-8 border-b-2 border-forest-800 -mb-0.5">
            <button
              onClick={() => setActiveTab('info')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'info'
                  ? 'border-forest-800 text-forest-800'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Team Information
            </button>
            <button
              onClick={() => setActiveTab('branding')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'branding'
                  ? 'border-forest-800 text-forest-800'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Branding
            </button>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          {activeTab === 'info' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {/* Team Name */}
              <div className="md:col-span-2">
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Team Name *
                </label>
                <input
                  type="text"
                  className={`w-full bg-white text-forest-800 border-2 ${
                    errors.name ? 'border-red-500' : 'border-forest-800'
                  } px-4 py-2 focus:outline-none focus:border-forest-600`}
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g., Lightning Bolts"
                />
                {errors.name && <p className="text-red-500 text-sm mt-1">{errors.name}</p>}
              </div>

              {/* Age Group */}
              <div>
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Age Group *
                </label>
                <select
                  className={`w-full bg-white text-forest-800 border-2 ${
                    errors.age_group ? 'border-red-500' : 'border-forest-800'
                  } px-4 py-2 focus:outline-none focus:border-forest-600`}
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
                {errors.age_group && <p className="text-red-500 text-sm mt-1">{errors.age_group}</p>}
              </div>

              {/* Division */}
              <div>
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Division *
                </label>
                <select
                  className={`w-full bg-white text-forest-800 border-2 ${
                    errors.division ? 'border-red-500' : 'border-forest-800'
                  } px-4 py-2 focus:outline-none focus:border-forest-600`}
                  value={formData.division}
                  onChange={(e) => setFormData({ ...formData, division: e.target.value })}
                >
                  <option value="Recreational">Recreational</option>
                  <option value="Competitive">Competitive</option>
                  <option value="Elite">Elite</option>
                </select>
                {errors.division && <p className="text-red-500 text-sm mt-1">{errors.division}</p>}
              </div>

              {/* Season */}
              <div>
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Season *
                </label>
                <select
                  className={`w-full bg-white text-forest-800 border-2 ${
                    errors.season_id ? 'border-red-500' : 'border-forest-800'
                  } px-4 py-2 focus:outline-none focus:border-forest-600`}
                  value={formData.season_id}
                  onChange={(e) => setFormData({ ...formData, season_id: e.target.value })}
                >
                  <option value="">Select a season</option>
                  {seasons.map(season => (
                    <option key={season.id} value={season.id}>
                      {season.name} ({season.year})
                    </option>
                  ))}
                </select>
                {errors.season_id && <p className="text-red-500 text-sm mt-1">{errors.season_id}</p>}
              </div>

              {/* Primary Coach */}
              <div>
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Primary Coach
                </label>
                <select
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  value={formData.primary_coach_id}
                  onChange={(e) => setFormData({ ...formData, primary_coach_id: e.target.value })}
                >
                  <option value="">No coach assigned</option>
                  {coaches.map(coach => (
                    <option key={coach.id} value={coach.id}>
                      {coach.first_name} {coach.last_name}
                    </option>
                  ))}
                </select>
              </div>

              {/* Home Field */}
              <div>
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Home Field
                </label>
                <select
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  value={formData.home_field_id}
                  onChange={(e) => setFormData({ ...formData, home_field_id: e.target.value })}
                >
                  <option value="">No field assigned</option>
                  {fields.map(field => (
                    <option key={field.id} value={field.id}>
                      {field.name}
                    </option>
                  ))}
                </select>
              </div>

              {/* Max Players */}
              <div>
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Max Players
                </label>
                <input
                  type="number"
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  value={formData.max_players}
                  onChange={(e) => setFormData({ ...formData, max_players: parseInt(e.target.value) || 20 })}
                  min="1"
                  max="50"
                />
              </div>
            </div>
          )}

          {activeTab === 'branding' && (
            <LogoColorExtractor
              initialData={{
                logoData: formData.logo_data,
                logoFilename: formData.logo_filename,
                primaryColor: formData.primary_color,
                secondaryColor: formData.secondary_color,
                accentColor: formData.accent_color
              }}
              onSave={handleBrandingUpdate}
            />
          )}

          {activeTab === 'info' && (
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
          )}
        </form>
      </div>
    </div>
  );
};

export default TeamFormWithTabs;
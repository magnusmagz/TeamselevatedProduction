import React, { useState, useEffect } from 'react';
import { LogoColorExtractor } from './LogoColorExtractor';

interface TeamFormProps {
  team: any | null;
  onSubmit: (data: any) => void;
  onClose: () => void;
}

const TeamFormWithTabs: React.FC<TeamFormProps> = ({ team, onSubmit, onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
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
    accent_color: '',
    social_facebook: '',
    social_instagram: '',
    social_twitter: '',
    social_tiktok: '',
    social_youtube: '',
    social_linkedin: ''
  });

  const [coaches, setCoaches] = useState<any[]>([]);
  const [seasons, setSeasons] = useState<any[]>([]);
  const [fields, setFields] = useState<any[]>([]);
  const [errors, setErrors] = useState<any>({});
  const [showSeasonForm, setShowSeasonForm] = useState(false);
  const [seasonFormData, setSeasonFormData] = useState({
    name: '',
    start_date: '',
    end_date: ''
  });

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
        accent_color: team.accent_color || '',
        social_facebook: team.social_facebook || '',
        social_instagram: team.social_instagram || '',
        social_twitter: team.social_twitter || '',
        social_tiktok: team.social_tiktok || '',
        social_youtube: team.social_youtube || '',
        social_linkedin: team.social_linkedin || ''
      });
    }
    fetchDropdownData();
  }, [team]);

  const fetchDropdownData = async () => {
    try {
      // Fetch coaches
      try {
        const coachesRes = await fetch(`${API_URL}/legacy/coaches-gateway.php?action=available`);
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
        const seasonsRes = await fetch(`${API_URL}/legacy/seasons-gateway.php?action=list`);
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
        const fieldsRes = await fetch(`${API_URL}/legacy/fields-gateway.php`);
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

  const handleCreateSeason = async (e: React.FormEvent) => {
    e.preventDefault();
    e.stopPropagation(); // Prevent event from bubbling to parent form

    // Validate required fields
    if (!seasonFormData.name || !seasonFormData.name.trim()) {
      alert('Season name is required');
      return;
    }

    if (!seasonFormData.start_date) {
      alert('Start date is required');
      return;
    }

    if (!seasonFormData.end_date) {
      alert('End date is required');
      return;
    }

    if (seasonFormData.end_date < seasonFormData.start_date) {
      alert('End date must be after start date');
      return;
    }

    try {
      const response = await fetch(`${API_URL}/legacy/seasons-gateway.php?action=create`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(seasonFormData)
      });

      if (response.ok) {
        const result = await response.json();
        alert('Season created successfully!');

        // Refresh seasons list
        await fetchDropdownData();

        // Auto-select the newly created season
        if (result.season && result.season.id) {
          setFormData({ ...formData, season_id: result.season.id.toString() });
        }

        // Reset and hide form
        setSeasonFormData({ name: '', start_date: '', end_date: '' });
        setShowSeasonForm(false);
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to create season');
      }
    } catch (error) {
      console.error('Error creating season:', error);
      alert('Failed to create season');
    }
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
      <div className="bg-white border border-brand-secondary rounded-md w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="border-b border-brand-secondary px-6 py-4">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
              {team ? 'Edit Team' : 'Create New Team'}
            </h3>
            <button
              onClick={onClose}
              className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
            >
              ×
            </button>
          </div>

          {/* Tabs */}
          <div className="flex space-x-8 border-b border-brand-secondary -mb-0.5">
            <button
              onClick={() => setActiveTab('info')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'info'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Team Information
            </button>
            <button
              onClick={() => setActiveTab('branding')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'branding'
                  ? 'border-brand-primary text-brand-primary'
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
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Team Name *
                </label>
                <input
                  type="text"
                  className={`w-full bg-white text-brand-primary border rounded-md ${
                    errors.name ? 'border-red-500' : 'border-brand-secondary'
                  } px-4 py-2 focus:outline-none focus:border-brand-accent`}
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g., Lightning Bolts"
                />
                {errors.name && <p className="text-red-500 text-sm mt-1">{errors.name}</p>}
              </div>

              {/* Age Group */}
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Age Group *
                </label>
                <select
                  className={`w-full bg-white text-brand-primary border rounded-md ${
                    errors.age_group ? 'border-red-500' : 'border-brand-secondary'
                  } px-4 py-2 focus:outline-none focus:border-brand-accent`}
                  value={formData.age_group}
                  onChange={(e) => setFormData({ ...formData, age_group: e.target.value })}
                >
                  <option value="U5">U5</option>
                  <option value="U6">U6</option>
                  <option value="U7">U7</option>
                  <option value="U8">U8</option>
                  <option value="U9">U9</option>
                  <option value="U10">U10</option>
                  <option value="U11">U11</option>
                  <option value="U12">U12</option>
                  <option value="U13">U13</option>
                  <option value="U14">U14</option>
                  <option value="U15">U15</option>
                  <option value="U16">U16</option>
                  <option value="U17">U17</option>
                  <option value="U18">U18</option>
                  <option value="Adult">Adult</option>
                </select>
                {errors.age_group && <p className="text-red-500 text-sm mt-1">{errors.age_group}</p>}
              </div>

              {/* Division */}
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Division *
                </label>
                <select
                  className={`w-full bg-white text-brand-primary border rounded-md ${
                    errors.division ? 'border-red-500' : 'border-brand-secondary'
                  } px-4 py-2 focus:outline-none focus:border-brand-accent`}
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
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Season *
                </label>
                <select
                  className={`w-full bg-white text-brand-primary border rounded-md ${
                    errors.season_id ? 'border-red-500' : 'border-brand-secondary'
                  } px-4 py-2 focus:outline-none focus:border-brand-accent`}
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
                <button
                  type="button"
                  onClick={() => setShowSeasonForm(!showSeasonForm)}
                  className="text-brand-primary hover:underline text-sm mt-2"
                >
                  + Create New Season
                </button>

                {/* Inline Season Creation Form */}
                {showSeasonForm && (
                  <div className="border border-brand-secondary rounded-md p-4 mt-3 bg-gray-50">
                    <h4 className="text-sm font-semibold text-brand-primary mb-3 uppercase">
                      Create New Season
                    </h4>
                    <div className="space-y-3">
                      <div>
                        <label className="block text-brand-primary text-xs font-medium mb-1 uppercase">
                          Season Name *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 focus:outline-none focus:border-brand-accent text-sm"
                          value={seasonFormData.name}
                          onChange={(e) => setSeasonFormData({ ...seasonFormData, name: e.target.value })}
                          placeholder="e.g., Spring 2024"
                          required
                        />
                      </div>

                      <div className="grid grid-cols-2 gap-3">
                        <div>
                          <label className="block text-brand-primary text-xs font-medium mb-1 uppercase">
                            Start Date *
                          </label>
                          <input
                            type="date"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 focus:outline-none focus:border-brand-accent text-sm"
                            value={seasonFormData.start_date}
                            onChange={(e) => setSeasonFormData({ ...seasonFormData, start_date: e.target.value })}
                            required
                          />
                        </div>

                        <div>
                          <label className="block text-brand-primary text-xs font-medium mb-1 uppercase">
                            End Date *
                          </label>
                          <input
                            type="date"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 focus:outline-none focus:border-brand-accent text-sm"
                            value={seasonFormData.end_date}
                            onChange={(e) => setSeasonFormData({ ...seasonFormData, end_date: e.target.value })}
                            min={seasonFormData.start_date}
                            required
                          />
                        </div>
                      </div>

                      <div className="flex justify-end space-x-2 pt-2">
                        <button
                          type="button"
                          onClick={() => {
                            setShowSeasonForm(false);
                            setSeasonFormData({ name: '', start_date: '', end_date: '' });
                          }}
                          className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-1 hover:bg-gray-100 uppercase text-sm"
                        >
                          Cancel
                        </button>
                        <button
                          type="button"
                          onClick={(e) => handleCreateSeason(e as any)}
                          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-1 hover:bg-brand-primary font-semibold uppercase text-sm"
                        >
                          Save Season
                        </button>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Primary Coach */}
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Primary Coach
                </label>
                <select
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
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
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Home Field
                </label>
                <select
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
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
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Max Players
                </label>
                <input
                  type="number"
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  value={formData.max_players}
                  onChange={(e) => setFormData({ ...formData, max_players: parseInt(e.target.value) || 20 })}
                  min="1"
                  max="50"
                />
              </div>

              {/* Social Media Section */}
              <div className="md:col-span-2 mt-4">
                <h3 className="text-sm font-bold text-brand-primary uppercase tracking-wide mb-3">Social Media</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Facebook URL
                    </label>
                    <input
                      type="url"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={formData.social_facebook}
                      onChange={(e) => setFormData({ ...formData, social_facebook: e.target.value })}
                      placeholder="https://facebook.com/yourclub"
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Instagram URL
                    </label>
                    <input
                      type="url"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={formData.social_instagram}
                      onChange={(e) => setFormData({ ...formData, social_instagram: e.target.value })}
                      placeholder="https://instagram.com/yourclub"
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      X / Twitter URL
                    </label>
                    <input
                      type="url"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={formData.social_twitter}
                      onChange={(e) => setFormData({ ...formData, social_twitter: e.target.value })}
                      placeholder="https://twitter.com/yourclub"
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      TikTok URL
                    </label>
                    <input
                      type="url"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={formData.social_tiktok}
                      onChange={(e) => setFormData({ ...formData, social_tiktok: e.target.value })}
                      placeholder="https://tiktok.com/@yourclub"
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      YouTube URL
                    </label>
                    <input
                      type="url"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={formData.social_youtube}
                      onChange={(e) => setFormData({ ...formData, social_youtube: e.target.value })}
                      placeholder="https://youtube.com/@yourclub"
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      LinkedIn URL
                    </label>
                    <input
                      type="url"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={formData.social_linkedin}
                      onChange={(e) => setFormData({ ...formData, social_linkedin: e.target.value })}
                      placeholder="https://linkedin.com/company/yourclub"
                    />
                  </div>
                </div>
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
              title="Team Logo"
            />
          )}

          {activeTab === 'info' && (
            <div className="flex justify-end space-x-4 mt-6">
              <button
                type="button"
                onClick={onClose}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase"
              >
                Cancel
              </button>
              <button
                type="submit"
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase"
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
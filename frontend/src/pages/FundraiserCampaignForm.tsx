import React, { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import PageHeader from '../components/ui/PageHeader';

interface CampaignFormData {
  title: string;
  slug: string;
  description: string;
  image_data: string;
  image_filename: string;
  goal_amount: string;
  start_date: string;
  end_date: string;
  show_donor_names: boolean;
  show_donor_amounts: boolean;
  show_progress: boolean;
  allow_comments: boolean;
  allow_exceed_goal: boolean;
  status: string;
  team_ids: number[];
}

interface Team {
  id: number;
  name: string;
}

interface FundraiserCampaignFormProps {
  clubId: number;
  clubSlug: string;
  userId: number;
}

/**
 * FundraiserCampaignForm - Create or edit a fundraiser campaign
 * Routes: /admin/fundraisers/new and /admin/fundraisers/:id/edit
 */
export const FundraiserCampaignForm: React.FC<FundraiserCampaignFormProps> = ({
  clubId,
  clubSlug,
  userId
}) => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const isEditing = Boolean(id);

  const [loading, setLoading] = useState(isEditing);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [availableTeams, setAvailableTeams] = useState<Team[]>([]);

  // Fetch teams for this club
  useEffect(() => {
    fetch(`${API_URL}/legacy/teams-gateway.php`, {
      headers: { 'Authorization': `Bearer ${localStorage.getItem('auth_token')}` }
    })
      .then(r => r.json())
      .then(data => {
        const teams = (data.teams || []).filter((t: any) => t.club_id === clubId || !t.club_id);
        setAvailableTeams(teams.map((t: any) => ({ id: t.id, name: t.name })));
      })
      .catch(() => {});
  }, [clubId, API_URL]);

  const [formData, setFormData] = useState<CampaignFormData>({
    title: '',
    slug: '',
    description: '',
    image_data: '',
    image_filename: '',
    goal_amount: '',
    start_date: new Date().toISOString().split('T')[0],
    end_date: '',
    show_donor_names: true,
    show_donor_amounts: true,
    show_progress: true,
    allow_comments: true,
    allow_exceed_goal: true,
    status: 'draft',
    team_ids: []
  });

  // Fetch existing campaign data when editing
  useEffect(() => {
    const fetchCampaign = async () => {
      try {
        const response = await fetch(`${API_URL}/api/fundraiser-campaigns.php?action=get&id=${id}`);
        const data = await response.json();

        if (data.error) {
          setError(data.error);
        } else {
          setFormData({
            title: data.title || '',
            slug: data.slug || '',
            description: data.description || '',
            image_data: data.image_data || data.image_url || '',
            image_filename: data.image_filename || '',
            goal_amount: data.goal_amount?.toString() || '',
            start_date: data.start_date || '',
            end_date: data.end_date || '',
            show_donor_names: data.show_donor_names ?? true,
            show_donor_amounts: data.show_donor_amounts ?? true,
            show_progress: data.show_progress ?? true,
            allow_comments: data.allow_comments ?? true,
            allow_exceed_goal: data.allow_exceed_goal ?? true,
            status: data.status || 'draft',
            team_ids: (data.teams || []).map((t: any) => t.id)
          });
        }
      } catch (err) {
        setError('Failed to load campaign');
        console.error('Error fetching campaign:', err);
      } finally {
        setLoading(false);
      }
    };

    if (isEditing && id) {
      fetchCampaign();
    }
  }, [id, isEditing, API_URL]);

  // Auto-generate slug from title
  const generateSlug = (title: string) => {
    return title
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
      .substring(0, 100);
  };

  const handleTitleChange = (value: string) => {
    setFormData((prev) => ({
      ...prev,
      title: value,
      // Auto-generate slug only if it hasn't been manually edited
      slug: prev.slug === generateSlug(prev.title) || !prev.slug ? generateSlug(value) : prev.slug
    }));
  };

  const handleImageUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      if (file.size > 5 * 1024 * 1024) {
        setError('Image file size must be less than 5MB');
        return;
      }

      const reader = new FileReader();
      reader.onloadend = () => {
        setFormData({
          ...formData,
          image_data: reader.result as string,
          image_filename: file.name
        });
        setError(null);
      };
      reader.readAsDataURL(file);
    }
  };

  const handleRemoveImage = () => {
    setFormData({
      ...formData,
      image_data: '',
      image_filename: ''
    });
  };

  const handleSubmit = async (e: React.FormEvent, publish = false) => {
    e.preventDefault();
    setError(null);
    setSaving(true);

    // Validation
    if (!formData.title.trim()) {
      setError('Campaign title is required');
      setSaving(false);
      return;
    }

    const goalAmount = parseFloat(formData.goal_amount);
    if (isNaN(goalAmount) || goalAmount <= 0) {
      setError('Please enter a valid goal amount');
      setSaving(false);
      return;
    }

    if (!formData.start_date || !formData.end_date) {
      setError('Start and end dates are required');
      setSaving(false);
      return;
    }

    if (new Date(formData.end_date) <= new Date(formData.start_date)) {
      setError('End date must be after start date');
      setSaving(false);
      return;
    }

    try {
      const action = isEditing ? 'update' : 'create';
      const payload: any = {
        club_id: clubId,
        title: formData.title.trim(),
        slug: formData.slug || generateSlug(formData.title),
        description: formData.description.trim(),
        image_data: formData.image_data || null,
        image_filename: formData.image_filename || null,
        goal_amount: goalAmount,
        start_date: formData.start_date,
        end_date: formData.end_date,
        show_donor_names: formData.show_donor_names,
        show_donor_amounts: formData.show_donor_amounts,
        show_progress: formData.show_progress,
        allow_comments: formData.allow_comments,
        allow_exceed_goal: formData.allow_exceed_goal,
        status: publish ? 'active' : formData.status,
        created_by: userId,
        team_ids: formData.team_ids
      };

      if (isEditing) {
        payload.id = parseInt(id!);
      }

      const response = await fetch(
        `${API_URL}/api/fundraiser-campaigns.php?action=${action}`,
        {
          method: isEditing ? 'PUT' : 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        }
      );

      const result = await response.json();

      if (result.success) {
        navigate(`/admin/fundraisers/${result.id || id}`);
      } else {
        setError(result.error || 'Failed to save campaign');
      }
    } catch (err) {
      setError('An error occurred. Please try again.');
      console.error('Error saving campaign:', err);
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="p-6">
        <div className="animate-pulse">
          <div className="h-8 bg-gray-200 rounded w-1/4 mb-6"></div>
          <div className="space-y-4">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="h-12 bg-gray-100 rounded"></div>
            ))}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-3xl">
      <PageHeader
        title={isEditing ? 'Edit Campaign' : 'Create New Campaign'}
        backTo="/admin/fundraisers"
        backLabel="Back to Campaigns"
      />

      {error && (
        <div className="mb-6 bg-red-50 border border-red-200 rounded-md p-4 text-red-700">
          {error}
        </div>
      )}

      <form onSubmit={(e) => handleSubmit(e, false)} className="space-y-6">
        {/* Basic Info */}
        <div className="bg-white rounded-md border border-brand-secondary p-6">
          <h2 className="text-lg font-semibold text-brand-primary mb-4 uppercase">Campaign Details</h2>

          <div className="space-y-4">
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Campaign Title *
              </label>
              <input
                type="text"
                value={formData.title}
                onChange={(e) => handleTitleChange(e.target.value)}
                required
                placeholder="e.g., New Court Floors"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              />
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                URL Slug
              </label>
              <div className="flex items-center gap-2">
                <span className="text-gray-500 text-sm">/donate/{clubSlug}/campaign/</span>
                <input
                  type="text"
                  value={formData.slug}
                  onChange={(e) => setFormData({ ...formData, slug: generateSlug(e.target.value) })}
                  placeholder="new-court-floors"
                  className="flex-1 bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent"
                />
              </div>
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Description
              </label>
              <textarea
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                rows={4}
                placeholder="Tell supporters why this campaign matters..."
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent resize-none"
              />
            </div>

            {/* Image Upload */}
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Campaign Image
              </label>
              <div className="border border-brand-secondary rounded-md p-4">
                {formData.image_data ? (
                  <div className="flex items-center space-x-4">
                    <img
                      src={formData.image_data}
                      alt="Campaign preview"
                      className="h-24 max-w-48 object-contain rounded"
                    />
                    <div>
                      <p className="text-sm text-gray-600">{formData.image_filename}</p>
                      <button
                        type="button"
                        onClick={handleRemoveImage}
                        className="text-red-600 hover:text-red-500 text-sm mt-1"
                      >
                        Remove Image
                      </button>
                    </div>
                  </div>
                ) : (
                  <div>
                    <input
                      type="file"
                      accept="image/*"
                      onChange={handleImageUpload}
                      className="w-full text-brand-primary"
                    />
                    <p className="text-gray-500 text-xs mt-2">Max file size: 5MB. Recommended: PNG or JPG, 1200x630px for best display.</p>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>

        {/* Goal & Dates */}
        <div className="bg-white rounded-md border border-brand-secondary p-6">
          <h2 className="text-lg font-semibold text-brand-primary mb-4 uppercase">Goal & Timeline</h2>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Fundraising Goal *
              </label>
              <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                <input
                  type="number"
                  value={formData.goal_amount}
                  onChange={(e) => setFormData({ ...formData, goal_amount: e.target.value })}
                  required
                  min="1"
                  step="1"
                  placeholder="5000"
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md pl-8 pr-4 py-2 focus:outline-none focus:border-brand-accent"
                />
              </div>
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Start Date *
              </label>
              <input
                type="date"
                value={formData.start_date}
                onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                required
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              />
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                End Date *
              </label>
              <input
                type="date"
                value={formData.end_date}
                onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                required
                min={formData.start_date}
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              />
            </div>
          </div>
        </div>

        {/* Team Associations */}
        <div className="bg-white rounded-md border border-brand-secondary p-6">
          <h2 className="text-lg font-semibold text-brand-primary mb-1 uppercase">Associated Teams</h2>
          <p className="text-sm text-gray-500 mb-4">Optionally associate this campaign with one or more teams.</p>

          {availableTeams.length === 0 ? (
            <p className="text-gray-400 text-sm">No teams found for this club.</p>
          ) : (
            <div className="space-y-2 max-h-48 overflow-y-auto border border-brand-secondary rounded-md p-3">
              {availableTeams.map((team) => (
                <label key={team.id} className="flex items-center gap-3 cursor-pointer hover:bg-gray-50 p-1 rounded">
                  <input
                    type="checkbox"
                    checked={formData.team_ids.includes(team.id)}
                    onChange={(e) => {
                      const ids = e.target.checked
                        ? [...formData.team_ids, team.id]
                        : formData.team_ids.filter(id => id !== team.id);
                      setFormData({ ...formData, team_ids: ids });
                    }}
                    className="w-4 h-4 border border-brand-secondary rounded"
                  />
                  <span className="text-brand-primary font-medium">{team.name}</span>
                </label>
              ))}
            </div>
          )}

          {formData.team_ids.length > 0 && (
            <p className="text-xs text-gray-500 mt-2">{formData.team_ids.length} team{formData.team_ids.length > 1 ? 's' : ''} selected</p>
          )}
        </div>

        {/* Privacy Settings */}
        <div className="bg-white rounded-md border border-brand-secondary p-6">
          <h2 className="text-lg font-semibold text-brand-primary mb-4 uppercase">Privacy Settings</h2>

          <div className="space-y-4">
            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.show_progress}
                onChange={(e) => setFormData({ ...formData, show_progress: e.target.checked })}
                className="w-4 h-4 border border-brand-secondary rounded"
              />
              <div>
                <p className="font-medium text-brand-primary">Show progress bar</p>
                <p className="text-sm text-gray-500">Display amount raised and progress toward goal</p>
              </div>
            </label>

            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.show_donor_names}
                onChange={(e) => setFormData({ ...formData, show_donor_names: e.target.checked })}
                className="w-4 h-4 border border-brand-secondary rounded"
              />
              <div>
                <p className="font-medium text-brand-primary">Show donor names</p>
                <p className="text-sm text-gray-500">Display supporter names on the donor wall</p>
              </div>
            </label>

            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.show_donor_amounts}
                onChange={(e) => setFormData({ ...formData, show_donor_amounts: e.target.checked })}
                className="w-4 h-4 border border-brand-secondary rounded"
              />
              <div>
                <p className="font-medium text-brand-primary">Show donation amounts</p>
                <p className="text-sm text-gray-500">Display individual donation amounts publicly</p>
              </div>
            </label>

            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.allow_comments}
                onChange={(e) => setFormData({ ...formData, allow_comments: e.target.checked })}
                className="w-4 h-4 border border-brand-secondary rounded"
              />
              <div>
                <p className="font-medium text-brand-primary">Allow donor messages</p>
                <p className="text-sm text-gray-500">Let donors leave public messages with their donations</p>
              </div>
            </label>

            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.allow_exceed_goal}
                onChange={(e) => setFormData({ ...formData, allow_exceed_goal: e.target.checked })}
                className="w-4 h-4 border border-brand-secondary rounded"
              />
              <div>
                <p className="font-medium text-brand-primary">Allow donations to exceed goal</p>
                <p className="text-sm text-gray-500">Continue accepting donations after goal is reached</p>
              </div>
            </label>
          </div>
        </div>

        {/* Actions */}
        <div className="flex items-center justify-between gap-4 pt-4">
          <Link
            to="/admin/fundraisers"
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase font-semibold"
          >
            Cancel
          </Link>

          <div className="flex items-center gap-3">
            <button
              type="submit"
              disabled={saving}
              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase font-semibold disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Save as Draft'}
            </button>

            <button
              type="button"
              onClick={(e) => handleSubmit(e, true)}
              disabled={saving}
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary-hover uppercase font-semibold disabled:opacity-50"
            >
              {saving ? 'Publishing...' : isEditing ? 'Save & Publish' : 'Publish Campaign'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

export default FundraiserCampaignForm;

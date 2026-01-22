import React, { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';

interface CampaignFormData {
  title: string;
  slug: string;
  description: string;
  image_url: string;
  goal_amount: string;
  start_date: string;
  end_date: string;
  show_donor_names: boolean;
  show_donor_amounts: boolean;
  show_progress: boolean;
  allow_comments: boolean;
  status: string;
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

  const [formData, setFormData] = useState<CampaignFormData>({
    title: '',
    slug: '',
    description: '',
    image_url: '',
    goal_amount: '',
    start_date: new Date().toISOString().split('T')[0],
    end_date: '',
    show_donor_names: true,
    show_donor_amounts: true,
    show_progress: true,
    allow_comments: true,
    status: 'draft'
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
            image_url: data.image_url || '',
            goal_amount: data.goal_amount?.toString() || '',
            start_date: data.start_date || '',
            end_date: data.end_date || '',
            show_donor_names: data.show_donor_names ?? true,
            show_donor_amounts: data.show_donor_amounts ?? true,
            show_progress: data.show_progress ?? true,
            allow_comments: data.allow_comments ?? true,
            status: data.status || 'draft'
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
        image_url: formData.image_url.trim() || null,
        goal_amount: goalAmount,
        start_date: formData.start_date,
        end_date: formData.end_date,
        show_donor_names: formData.show_donor_names,
        show_donor_amounts: formData.show_donor_amounts,
        show_progress: formData.show_progress,
        allow_comments: formData.allow_comments,
        status: publish ? 'active' : formData.status,
        created_by: userId
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
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/admin/fundraisers"
          className="text-brand-primary hover:text-brand-primary-hover mb-2 inline-flex items-center gap-1 text-sm"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
          Back to Campaigns
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEditing ? 'Edit Campaign' : 'Create New Campaign'}
        </h1>
      </div>

      {error && (
        <div className="mb-6 bg-red-50 border border-red-200 rounded-lg p-4 text-red-700">
          {error}
        </div>
      )}

      <form onSubmit={(e) => handleSubmit(e, false)} className="space-y-6">
        {/* Basic Info */}
        <div className="bg-white rounded-lg border border-gray-200 p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Campaign Details</h2>

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Campaign Title *
              </label>
              <input
                type="text"
                value={formData.title}
                onChange={(e) => handleTitleChange(e.target.value)}
                required
                placeholder="e.g., New Court Floors"
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                URL Slug
              </label>
              <div className="flex items-center gap-2">
                <span className="text-gray-500 text-sm">/donate/{clubSlug}/campaign/</span>
                <input
                  type="text"
                  value={formData.slug}
                  onChange={(e) => setFormData({ ...formData, slug: generateSlug(e.target.value) })}
                  placeholder="new-court-floors"
                  className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                />
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Description
              </label>
              <textarea
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                rows={4}
                placeholder="Tell supporters why this campaign matters..."
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent resize-none"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Hero Image URL
              </label>
              <input
                type="url"
                value={formData.image_url}
                onChange={(e) => setFormData({ ...formData, image_url: e.target.value })}
                placeholder="https://example.com/image.jpg"
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
              {formData.image_url && (
                <div className="mt-2">
                  <img
                    src={formData.image_url}
                    alt="Preview"
                    className="h-32 w-full object-cover rounded-lg"
                    onError={(e) => {
                      (e.target as HTMLImageElement).style.display = 'none';
                    }}
                  />
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Goal & Dates */}
        <div className="bg-white rounded-lg border border-gray-200 p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Goal & Timeline</h2>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
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
                  className="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                />
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Start Date *
              </label>
              <input
                type="date"
                value={formData.start_date}
                onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                End Date *
              </label>
              <input
                type="date"
                value={formData.end_date}
                onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                required
                min={formData.start_date}
                className="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-brand-primary focus:border-transparent"
              />
            </div>
          </div>
        </div>

        {/* Privacy Settings */}
        <div className="bg-white rounded-lg border border-gray-200 p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Privacy Settings</h2>

          <div className="space-y-4">
            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.show_progress}
                onChange={(e) => setFormData({ ...formData, show_progress: e.target.checked })}
                className="w-5 h-5 text-brand-primary focus:ring-brand-primary border-gray-300 rounded"
              />
              <div>
                <p className="font-medium text-gray-900">Show progress bar</p>
                <p className="text-sm text-gray-500">Display amount raised and progress toward goal</p>
              </div>
            </label>

            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.show_donor_names}
                onChange={(e) => setFormData({ ...formData, show_donor_names: e.target.checked })}
                className="w-5 h-5 text-brand-primary focus:ring-brand-primary border-gray-300 rounded"
              />
              <div>
                <p className="font-medium text-gray-900">Show donor names</p>
                <p className="text-sm text-gray-500">Display supporter names on the donor wall</p>
              </div>
            </label>

            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.show_donor_amounts}
                onChange={(e) => setFormData({ ...formData, show_donor_amounts: e.target.checked })}
                className="w-5 h-5 text-brand-primary focus:ring-brand-primary border-gray-300 rounded"
              />
              <div>
                <p className="font-medium text-gray-900">Show donation amounts</p>
                <p className="text-sm text-gray-500">Display individual donation amounts publicly</p>
              </div>
            </label>

            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={formData.allow_comments}
                onChange={(e) => setFormData({ ...formData, allow_comments: e.target.checked })}
                className="w-5 h-5 text-brand-primary focus:ring-brand-primary border-gray-300 rounded"
              />
              <div>
                <p className="font-medium text-gray-900">Allow donor messages</p>
                <p className="text-sm text-gray-500">Let donors leave public messages with their donations</p>
              </div>
            </label>
          </div>
        </div>

        {/* Actions */}
        <div className="flex items-center justify-between gap-4 pt-4">
          <Link
            to="/admin/fundraisers"
            className="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50"
          >
            Cancel
          </Link>

          <div className="flex items-center gap-3">
            <button
              type="submit"
              disabled={saving}
              className="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Save as Draft'}
            </button>

            <button
              type="button"
              onClick={(e) => handleSubmit(e, true)}
              disabled={saving}
              className="px-6 py-3 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-primary-hover disabled:opacity-50"
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

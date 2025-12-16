import React, { useState } from 'react';

interface CoachProfileData {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  profile_image_url: string | null;
  coaching_background: string | null;
}

interface CoachProfileEditProps {
  coach: CoachProfileData;
  onClose: () => void;
  onSave: (updatedCoach: CoachProfileData) => void;
}

const CoachProfileEdit: React.FC<CoachProfileEditProps> = ({ coach, onClose, onSave }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8888/teamselevated-backend';

  const [formData, setFormData] = useState({
    first_name: coach.first_name,
    last_name: coach.last_name,
    email: coach.email,
    phone: coach.phone || '',
    profile_image_url: coach.profile_image_url || '',
    coaching_background: coach.coaching_background || ''
  });

  const [imageInputType, setImageInputType] = useState<'url' | 'upload'>('url');
  const [uploading, setUploading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({
      ...prev,
      [name]: value
    }));
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Validate file type
    if (!file.type.startsWith('image/')) {
      setError('Please select a valid image file');
      return;
    }

    // Validate file size (max 5MB)
    if (file.size > 5 * 1024 * 1024) {
      setError('Image file size must be less than 5MB');
      return;
    }

    setUploading(true);
    setError(null);

    try {
      // Create FormData for file upload
      const formDataUpload = new FormData();
      formDataUpload.append('file', file);
      formDataUpload.append('type', 'coach_profile');

      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/upload.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`
        },
        body: formDataUpload
      });

      const data = await response.json();

      if (data.success && data.url) {
        setFormData((prev) => ({
          ...prev,
          profile_image_url: data.url
        }));
      } else {
        setError(data.error || 'Failed to upload image');
      }
    } catch (err) {
      console.error('Error uploading image:', err);
      setError('Failed to upload image');
    } finally {
      setUploading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSaving(true);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/coach-profile.php?id=${coach.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(formData)
      });

      const data = await response.json();

      if (data.success && data.coach) {
        onSave(data.coach);
        onClose();
      } else {
        setError(data.error || 'Failed to update profile');
      }
    } catch (err) {
      console.error('Error updating profile:', err);
      setError('Failed to update profile');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-md p-8 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <h3 className="text-2xl font-bold text-forest-800 mb-6 uppercase tracking-wide">
          Edit Profile
        </h3>

        {error && (
          <div className="bg-red-50 border border-red-200 text-red-800 rounded-md p-4 mb-6">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit}>
          {/* Name Fields */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                First Name *
              </label>
              <input
                type="text"
                name="first_name"
                value={formData.first_name}
                onChange={handleInputChange}
                required
                className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
              />
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Last Name *
              </label>
              <input
                type="text"
                name="last_name"
                value={formData.last_name}
                onChange={handleInputChange}
                required
                className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
              />
            </div>
          </div>

          {/* Contact Fields */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Email *
              </label>
              <input
                type="email"
                name="email"
                value={formData.email}
                onChange={handleInputChange}
                required
                className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
              />
            </div>

            <div>
              <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                Phone Number
              </label>
              <input
                type="tel"
                name="phone"
                value={formData.phone}
                onChange={handleInputChange}
                placeholder="(555) 123-4567"
                className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
              />
            </div>
          </div>

          {/* Profile Image */}
          <div className="mb-4">
            <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
              Profile Image
            </label>

            <div className="flex gap-4 mb-3">
              <button
                type="button"
                onClick={() => setImageInputType('url')}
                className={`px-4 py-2 rounded-md font-medium uppercase text-sm ${
                  imageInputType === 'url'
                    ? 'bg-forest-800 text-white'
                    : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                }`}
              >
                Image URL
              </button>
              <button
                type="button"
                onClick={() => setImageInputType('upload')}
                className={`px-4 py-2 rounded-md font-medium uppercase text-sm ${
                  imageInputType === 'upload'
                    ? 'bg-forest-800 text-white'
                    : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                }`}
              >
                Upload File
              </button>
            </div>

            {imageInputType === 'url' ? (
              <input
                type="url"
                name="profile_image_url"
                value={formData.profile_image_url}
                onChange={handleInputChange}
                placeholder="https://example.com/image.jpg"
                className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
              />
            ) : (
              <div>
                <input
                  type="file"
                  accept="image/*"
                  onChange={handleFileUpload}
                  disabled={uploading}
                  className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600"
                />
                {uploading && (
                  <p className="text-sm text-gray-600 mt-2">Uploading image...</p>
                )}
              </div>
            )}

            {formData.profile_image_url && (
              <div className="mt-3">
                <img
                  src={formData.profile_image_url}
                  alt="Profile preview"
                  className="w-24 h-24 rounded-full object-cover border-2 border-forest-200"
                  onError={(e) => {
                    (e.target as HTMLImageElement).style.display = 'none';
                  }}
                />
              </div>
            )}
          </div>

          {/* Coaching Background */}
          <div className="mb-6">
            <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
              Coaching Background
            </label>
            <textarea
              name="coaching_background"
              value={formData.coaching_background}
              onChange={handleInputChange}
              rows={6}
              placeholder="Describe your coaching experience, certifications, philosophy, etc..."
              className="w-full bg-white text-forest-800 border border-forest-200 rounded-md px-4 py-2 focus:outline-none focus:border-forest-600 resize-none"
            />
          </div>

          {/* Action Buttons */}
          <div className="flex gap-4 justify-end">
            <button
              type="button"
              onClick={onClose}
              disabled={saving}
              className="px-6 py-2 border-2 border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 font-semibold uppercase disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving || uploading}
              className="px-6 py-2 bg-forest-800 text-white rounded-md hover:bg-forest-700 font-semibold uppercase disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default CoachProfileEdit;

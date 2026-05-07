import React, { useState, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { LogoColorExtractor } from '../components/LogoColorExtractor';
import ClubUserManagement from '../components/ClubUserManagement';
import ImportTilesGrid from '../components/ImportTilesGrid';
import { clearBrandingCache } from '../components/BrandingLogo';
import { useTheme } from '../contexts/ThemeContext';
import GooglePlacesAutocomplete from '../components/GooglePlacesAutocomplete';

interface ClubProfile {
  id?: number;
  club_name: string;
  address: string;
  city: string;
  state: string;
  zip: string;
  website?: string;
  phone?: string;
  email?: string;
  logo_data?: string;
  logo_filename?: string;
  primary_color?: string;
  secondary_color?: string;
  accent_color?: string;
  latitude?: number;
  longitude?: number;
  social_facebook?: string;
  social_instagram?: string;
  social_twitter?: string;
  social_tiktok?: string;
  social_youtube?: string;
  social_linkedin?: string;
}

const ClubProfilePage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const navigate = useNavigate();
  const { updateTheme } = useTheme();
  const [activeTab, setActiveTab] = useState<'info' | 'branding' | 'documents' | 'users' | 'imports'>('info');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [formData, setFormData] = useState<ClubProfile>({
    club_name: '',
    address: '',
    city: '',
    state: '',
    zip: '',
    website: '',
    phone: '',
    email: '',
    social_facebook: '',
    social_instagram: '',
    social_twitter: '',
    social_tiktok: '',
    social_youtube: '',
    social_linkedin: ''
  });

  useEffect(() => {
    fetchClubProfile();
  }, []);

  const fetchClubProfile = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/club-profile-gateway.php`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      if (data.id) {
        setFormData(data);
      }
    } catch (error) {
      console.error('Error fetching club profile:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/club-profile-gateway.php`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        const result = await response.json();
        console.log('Profile saved:', result);
        // Clear branding cache and trigger refresh so header updates
        clearBrandingCache();
        if (formData.primary_color) {
          updateTheme(formData.primary_color);
        }
      }
    } catch (error) {
      console.error('Error saving club profile:', error);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="min-h-screen bg-white">
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Back Button */}

        {/* Header */}
        <div className="mb-6">
          <h1 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">CLUB PROFILE</h1>
          <p className="text-gray-600 mt-2">Manage your club's information and settings</p>
        </div>

        {/* Tabs */}
        <div className="border-b border-brand-secondary mb-6 overflow-x-auto">
          <nav className="-mb-0.5 flex space-x-4 sm:space-x-8 min-w-max">
            <button
              onClick={() => setActiveTab('info')}
              className={`py-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'info'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Club Information
            </button>
            <button
              onClick={() => setActiveTab('branding')}
              className={`py-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'branding'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Branding
            </button>
            <button
              onClick={() => setActiveTab('documents')}
              className={`py-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'documents'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Documents
            </button>
            <button
              onClick={() => setActiveTab('users')}
              className={`py-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'users'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              User Management
            </button>
            <button
              onClick={() => setActiveTab('imports')}
              className={`py-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'imports'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Imports
            </button>
          </nav>
        </div>

        {loading ? (
          <div className="text-center text-brand-primary py-12">Loading club profile...</div>
        ) : (
          <>
            {activeTab === 'info' && (
              <form onSubmit={handleSubmit} className="space-y-6">
                <div className="bg-white border border-brand-secondary rounded-md p-6">
                  <h2 className="text-xl font-semibold text-brand-primary mb-6 uppercase">Basic Information</h2>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Club Name *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.club_name}
                        onChange={(e) => setFormData({ ...formData, club_name: e.target.value })}
                        required
                        placeholder="Enter your club name"
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Email *
                      </label>
                      <input
                        type="email"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.email || ''}
                        onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                        required
                        placeholder="club@example.com"
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Phone
                      </label>
                      <input
                        type="tel"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.phone || ''}
                        onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                        placeholder="(555) 123-4567"
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Website
                      </label>
                      <input
                        type="url"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.website || ''}
                        onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                        placeholder="https://www.yourclub.com"
                      />
                    </div>
                  </div>
                </div>

                <div className="bg-white border border-brand-secondary rounded-md p-6">
                  <h2 className="text-xl font-semibold text-brand-primary mb-6 uppercase">Location</h2>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="md:col-span-2">
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Street Address *
                      </label>
                      <GooglePlacesAutocomplete
                        defaultValue={formData.address}
                        placeholder="Search for an address or place..."
                        onPlaceSelect={(place) => {
                          setFormData({
                            ...formData,
                            address: place.address_line1,
                            city: place.city,
                            state: place.state,
                            zip: place.zip_code,
                            latitude: place.lat,
                            longitude: place.lng,
                          });
                        }}
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        City *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.city}
                        onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                        required
                        placeholder="San Francisco"
                      />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          State *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.state}
                          onChange={(e) => setFormData({ ...formData, state: e.target.value })}
                          required
                          placeholder="CA"
                          maxLength={2}
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Zip Code *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.zip}
                          onChange={(e) => setFormData({ ...formData, zip: e.target.value })}
                          required
                          placeholder="94102"
                        />
                      </div>
                    </div>
                  </div>
                </div>

                <div className="bg-white border border-brand-secondary rounded-md p-6">
                  <h2 className="text-sm font-bold text-brand-primary uppercase tracking-wide mb-3">Social Media</h2>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Facebook URL
                      </label>
                      <input
                        type="url"
                        className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                        value={formData.social_facebook || ''}
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
                        value={formData.social_instagram || ''}
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
                        value={formData.social_twitter || ''}
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
                        value={formData.social_tiktok || ''}
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
                        value={formData.social_youtube || ''}
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
                        value={formData.social_linkedin || ''}
                        onChange={(e) => setFormData({ ...formData, social_linkedin: e.target.value })}
                        placeholder="https://linkedin.com/company/yourclub"
                      />
                    </div>
                  </div>
                </div>

                <div className="flex justify-end">
                  <button
                    type="submit"
                    disabled={saving}
                    className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary font-semibold uppercase disabled:opacity-50"
                  >
                    {saving ? 'Saving...' : 'Save Changes'}
                  </button>
                </div>
              </form>
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
                onSave={async (data) => {
                  const updatedData = {
                    ...formData,
                    logo_data: data.logoBase64,
                    logo_filename: data.logoFilename,
                    primary_color: data.primaryColor,
                    secondary_color: data.secondaryColor,
                    accent_color: data.accentColor
                  };

                  const token = localStorage.getItem('auth_token');
                  const response = await fetch(`${API_URL}/legacy/club-profile-gateway.php`, {
                    method: 'PUT',
                    headers: {
                      'Content-Type': 'application/json',
                      'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify(updatedData)
                  });

                  if (response.ok) {
                    setFormData(updatedData);
                    console.log('Brand settings saved successfully');
                    // Clear branding cache and trigger refresh so header updates
                    clearBrandingCache();
                    if (data.primaryColor) {
                      updateTheme(data.primaryColor);
                    }
                  } else {
                    throw new Error('Failed to save brand settings');
                  }
                }}
              />
            )}

            {activeTab === 'documents' && (
              <div className="text-center py-12 border border-brand-secondary rounded-md bg-white">
                <p className="text-gray-600 mb-4">
                  Documents are now managed in the Document Center.
                </p>
                <Link
                  to="/club-documents"
                  className="inline-block bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
                >
                  Open Document Center →
                </Link>
              </div>
            )}

            {activeTab === 'users' && (
              <ClubUserManagement />
            )}

            {activeTab === 'imports' && (
              <div>
                <h2 className="text-lg font-semibold text-brand-primary mb-2">Bulk Import</h2>
                <p className="text-sm text-gray-600 mb-6">
                  Import athletes, facilities, volunteers, coaches, or teams from a CSV.
                  Each importer walks you through column mapping and live progress tracking.
                </p>
                <ImportTilesGrid />
              </div>
            )}
          </>
        )}
      </main>
    </div>
  );
};

export default ClubProfilePage;
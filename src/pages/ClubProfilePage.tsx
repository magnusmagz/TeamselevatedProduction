import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { LogoColorExtractor } from '../components/LogoColorExtractor';

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
}

const ClubProfilePage: React.FC = () => {
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState<'info' | 'branding'>('info');
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
    email: ''
  });

  useEffect(() => {
    fetchClubProfile();
  }, []);

  const fetchClubProfile = async () => {
    try {
      const response = await fetch('http://localhost:8889/club-profile-gateway.php');
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
      const response = await fetch('http://localhost:8889/club-profile-gateway.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        const result = await response.json();
        console.log('Profile saved:', result);
        // Could show a success message here
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
          <h1 className="text-3xl font-bold text-forest-800 uppercase tracking-wide">CLUB PROFILE</h1>
          <p className="text-gray-600 mt-2">Manage your club's information and settings</p>
        </div>

        {/* Tabs */}
        <div className="border-b-2 border-forest-800 mb-6">
          <nav className="-mb-0.5 flex space-x-8">
            <button
              onClick={() => setActiveTab('info')}
              className={`py-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'info'
                  ? 'border-forest-800 text-forest-800'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Club Information
            </button>
            <button
              onClick={() => setActiveTab('branding')}
              className={`py-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'branding'
                  ? 'border-forest-800 text-forest-800'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Branding
            </button>
          </nav>
        </div>

        {loading ? (
          <div className="text-center text-forest-800 py-12">Loading club profile...</div>
        ) : (
          <>
            {activeTab === 'info' && (
              <form onSubmit={handleSubmit} className="space-y-6">
                <div className="bg-white border-2 border-forest-800 p-6">
                  <h2 className="text-xl font-semibold text-forest-800 mb-6 uppercase">Basic Information</h2>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        Club Name *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.club_name}
                        onChange={(e) => setFormData({ ...formData, club_name: e.target.value })}
                        required
                        placeholder="Enter your club name"
                      />
                    </div>

                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        Email *
                      </label>
                      <input
                        type="email"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.email || ''}
                        onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                        required
                        placeholder="club@example.com"
                      />
                    </div>

                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        Phone
                      </label>
                      <input
                        type="tel"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.phone || ''}
                        onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                        placeholder="(555) 123-4567"
                      />
                    </div>

                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        Website
                      </label>
                      <input
                        type="url"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.website || ''}
                        onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                        placeholder="https://www.yourclub.com"
                      />
                    </div>
                  </div>
                </div>

                <div className="bg-white border-2 border-forest-800 p-6">
                  <h2 className="text-xl font-semibold text-forest-800 mb-6 uppercase">Location</h2>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="md:col-span-2">
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        Street Address *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.address}
                        onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                        required
                        placeholder="123 Main Street"
                      />
                    </div>

                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        City *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.city}
                        onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                        required
                        placeholder="San Francisco"
                      />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          State *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          value={formData.state}
                          onChange={(e) => setFormData({ ...formData, state: e.target.value })}
                          required
                          placeholder="CA"
                          maxLength={2}
                        />
                      </div>

                      <div>
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Zip Code *
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          value={formData.zip}
                          onChange={(e) => setFormData({ ...formData, zip: e.target.value })}
                          required
                          placeholder="94102"
                        />
                      </div>
                    </div>
                  </div>
                </div>

                <div className="flex justify-end">
                  <button
                    type="submit"
                    disabled={saving}
                    className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-3 hover:bg-forest-700 font-semibold uppercase disabled:opacity-50"
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

                  const response = await fetch('http://localhost:8889/club-profile-gateway.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(updatedData)
                  });

                  if (response.ok) {
                    setFormData(updatedData);
                    console.log('Brand settings saved successfully');
                  } else {
                    throw new Error('Failed to save brand settings');
                  }
                }}
              />
            )}
          </>
        )}
      </main>
    </div>
  );
};

export default ClubProfilePage;
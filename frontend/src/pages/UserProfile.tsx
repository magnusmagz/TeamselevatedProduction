import React, { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';

interface UserProfileData {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  phone: string;
  email_signature: string;
  created_at: string;
}

const UserProfile: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user, updateUser } = useAuth();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const [profileData, setProfileData] = useState<UserProfileData | null>(null);
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    email_signature: ''
  });

  const [passwordData, setPasswordData] = useState({
    current_password: '',
    new_password: '',
    confirm_password: ''
  });

  useEffect(() => {
    fetchProfile();
  }, []);

  const fetchProfile = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/user-profile.php`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      const data = await response.json();

      if (data.success && data.user) {
        setProfileData(data.user);
        setFormData({
          first_name: data.user.first_name || '',
          last_name: data.user.last_name || '',
          email: data.user.email || '',
          phone: data.user.phone || '',
          email_signature: data.user.email_signature || ''
        });
      } else {
        setMessage({ type: 'error', text: data.error || 'Failed to load profile' });
      }
    } catch (error) {
      console.error('Error fetching profile:', error);
      setMessage({ type: 'error', text: 'Failed to load profile' });
    } finally {
      setLoading(false);
    }
  };

  const handleProfileUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    setMessage(null);
    setSaving(true);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/user-profile.php`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(formData)
      });

      const data = await response.json();

      if (data.success) {
        setMessage({ type: 'success', text: 'Profile updated successfully!' });
        setProfileData(data.user);

        // Update auth context if user object exists
        if (updateUser && data.user) {
          updateUser({
            ...user,
            name: `${data.user.first_name} ${data.user.last_name}`,
            email: data.user.email
          });
        }
      } else {
        setMessage({ type: 'error', text: data.error || 'Failed to update profile' });
      }
    } catch (error) {
      console.error('Error updating profile:', error);
      setMessage({ type: 'error', text: 'Failed to update profile' });
    } finally {
      setSaving(false);
    }
  };

  const handlePasswordUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    setMessage(null);

    // Validate passwords
    if (passwordData.new_password !== passwordData.confirm_password) {
      setMessage({ type: 'error', text: 'New passwords do not match' });
      return;
    }

    if (passwordData.new_password.length < 8) {
      setMessage({ type: 'error', text: 'New password must be at least 8 characters' });
      return;
    }

    setSaving(true);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/user-profile.php`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          current_password: passwordData.current_password,
          new_password: passwordData.new_password
        })
      });

      const data = await response.json();

      if (data.success) {
        setMessage({ type: 'success', text: 'Password updated successfully!' });
        setPasswordData({ current_password: '', new_password: '', confirm_password: '' });
      } else {
        setMessage({ type: 'error', text: data.error || 'Failed to update password' });
      }
    } catch (error) {
      console.error('Error updating password:', error);
      setMessage({ type: 'error', text: 'Failed to update password' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="text-brand-primary">Loading profile...</div>
      </div>
    );
  }

  return (
    <div className="max-w-4xl mx-auto">
      <div className="mb-8">
        <h2 className="text-3xl font-bold text-brand-primary mb-2 uppercase tracking-wide">My Profile</h2>
        <p className="text-gray-600">Manage your personal information and account settings</p>
      </div>

      {message && (
        <div
          className={`mb-6 p-4 rounded-md ${
            message.type === 'success'
              ? 'bg-green-50 border border-green-200 text-green-800'
              : 'bg-red-50 border border-red-200 text-red-800'
          }`}
        >
          {message.text}
        </div>
      )}

      {/* Profile Information Section */}
      <div className="bg-white border border-brand-secondary rounded-md p-6 mb-6">
        <h3 className="text-xl font-semibold text-brand-primary mb-4 uppercase tracking-wide">
          Profile Information
        </h3>
        <form onSubmit={handleProfileUpdate}>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                First Name
              </label>
              <input
                type="text"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                value={formData.first_name}
                onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                required
              />
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Last Name
              </label>
              <input
                type="text"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                value={formData.last_name}
                onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                required
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Email Address
              </label>
              <input
                type="email"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                required
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Phone Number
              </label>
              <input
                type="tel"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                value={formData.phone}
                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                placeholder="(555) 555-5555"
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Email Signature
              </label>
              <textarea
                className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                rows={4}
                value={formData.email_signature}
                onChange={(e) => setFormData({ ...formData, email_signature: e.target.value })}
                placeholder="e.g. Coach Smith&#10;Riverside Soccer Club&#10;(555) 123-4567"
              />
              <p className="text-gray-500 text-xs mt-1">This will be appended to all outbound emails you send.</p>
            </div>
          </div>

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={saving}
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>

      {/* Change Password Section */}
      <div className="bg-white border border-brand-secondary rounded-md p-6">
        <h3 className="text-xl font-semibold text-brand-primary mb-4 uppercase tracking-wide">
          Change Password
        </h3>
        <form onSubmit={handlePasswordUpdate}>
          <div className="grid grid-cols-1 gap-4 mb-6">
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Current Password
              </label>
              <input
                type="password"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                value={passwordData.current_password}
                onChange={(e) => setPasswordData({ ...passwordData, current_password: e.target.value })}
                required
              />
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                New Password
              </label>
              <input
                type="password"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                value={passwordData.new_password}
                onChange={(e) => setPasswordData({ ...passwordData, new_password: e.target.value })}
                required
                minLength={8}
              />
              <p className="text-gray-500 text-xs mt-1">Must be at least 8 characters</p>
            </div>

            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Confirm New Password
              </label>
              <input
                type="password"
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                value={passwordData.confirm_password}
                onChange={(e) => setPasswordData({ ...passwordData, confirm_password: e.target.value })}
                required
              />
            </div>
          </div>

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={saving}
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase disabled:opacity-50"
            >
              {saving ? 'Updating...' : 'Update Password'}
            </button>
          </div>
        </form>
      </div>

      {profileData && (
        <div className="mt-6 text-center text-sm text-gray-500">
          Member since {new Date(profileData.created_at).toLocaleDateString()}
        </div>
      )}
    </div>
  );
};

export default UserProfile;

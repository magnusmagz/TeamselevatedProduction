import React, { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import PushNotificationToggle from '../components/PushNotificationToggle';
import SignatureEditor from '../components/SignatureEditor';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';
import {
  signatureTextToHtml,
  signatureHtmlToText,
  isSignatureHtmlEmpty,
} from '../utils/signatureHtml';

interface UserProfileData {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  phone: string;
  email_signature: string;
  // 'text' | 'html'. Widened to string because a backend that predates migration
  // 092 answers with the literal 'text' and a backend that predates THIS deploy
  // omits the key entirely — a narrow union here would assert something the wire
  // does not guarantee, which is the mistake that hid the senderId type bug.
  email_signature_format?: string;
  created_at: string;
}

type SignatureMode = 'text' | 'html';

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

  // The rich signature, kept apart from the textarea's value so switching
  // between the two surfaces cannot let one overwrite the other silently.
  const [signatureMode, setSignatureMode] = useState<SignatureMode>('text');
  const [signatureHtml, setSignatureHtml] = useState('');
  // Remounts the editor when a fetch replaces its content. tiptap owns its own
  // document after mount, so feeding a new `value` into the live instance would
  // be ignored; a key is the honest way to say "this is a different document".
  const [editorKey, setEditorKey] = useState(0);

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

        // ?? not ||. An ABSENT format means a backend older than this deploy and
        // must fall back to 'text', which is the escaping path and therefore the
        // safe one. Only the string 'html' opts into rich rendering.
        const storedFormat = data.user.email_signature_format ?? 'text';
        const storedSignature: string = data.user.email_signature || '';
        const isHtml = storedFormat === 'html' && storedSignature !== '';

        setSignatureMode(isHtml ? 'html' : 'text');
        setSignatureHtml(isHtml ? storedSignature : '');
        setEditorKey((k) => k + 1);

        setFormData({
          first_name: data.user.first_name || '',
          last_name: data.user.last_name || '',
          email: data.user.email || '',
          phone: data.user.phone || '',
          email_signature: isHtml ? '' : storedSignature
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

  /**
   * Move an existing plain-text signature into the rich editor.
   *
   * The stored text is escaped on the way across (signatureTextToHtml), because
   * it is exactly the untrusted value the send path escapes — a staff member who
   * literally typed "<b>" must not have it promoted to markup by opening an
   * editor.
   */
  const switchToRichSignature = () => {
    setSignatureHtml(signatureTextToHtml(formData.email_signature));
    setEditorKey((k) => k + 1);
    setSignatureMode('html');
  };

  /**
   * Move back to the plain textarea. Formatting is genuinely lost, so this says
   * so before it happens rather than after — the alternative is a staff member
   * discovering it in an email they already sent.
   */
  const switchToPlainSignature = () => {
    const asText = signatureHtmlToText(signatureHtml);

    if (
      !isSignatureHtmlEmpty(signatureHtml) &&
      !window.confirm('Switching to plain text removes the formatting from your signature. Continue?')
    ) {
      return;
    }

    setFormData((current) => ({ ...current, email_signature: asText }));
    setSignatureHtml('');
    setSignatureMode('text');
  };

  // What the last SAVE produced, which is what actually ships. A text signature
  // is previewed escaped, matching te_render_signature_html() — the preview has
  // to show the escape, or the plain path silently looks like it renders markup.
  const savedSignature = profileData?.email_signature || '';
  const savedFormat = profileData?.email_signature_format ?? 'text';
  const savedSignaturePreview =
    savedSignature === ''
      ? ''
      : savedFormat === 'html'
        ? savedSignature
        : signatureTextToHtml(savedSignature);

  const signatureIsUnsaved =
    signatureMode === 'html'
      ? signatureHtml !== savedSignature
      : formData.email_signature !== savedSignature;

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
        // Exactly ONE of the two signature keys is sent, and which one is what
        // stamps users.email_signature_format server-side. Sending both would
        // leave the endpoint choosing, and a staff member who moved back to the
        // plain textarea would keep a row claiming to be HTML — which is the row
        // whose contents ship unescaped.
        body: JSON.stringify(
          signatureMode === 'html'
            ? {
                ...formData,
                email_signature: undefined,
                email_signature_html: isSignatureHtmlEmpty(signatureHtml) ? '' : signatureHtml,
              }
            : formData
        )
      });

      const data = await response.json();

      if (data.success) {
        setMessage({ type: 'success', text: 'Profile updated successfully!' });
        setProfileData(data.user);

        // The round trip. The preview below renders what the SERVER stored, not
        // what the editor produced, so a tag the sanitiser removed is visibly
        // gone rather than quietly different from what actually ships.
        if (signatureMode === 'html') {
          setSignatureHtml(data.user?.email_signature || '');
          setEditorKey((k) => k + 1);
        }

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
      <PageHeader
        title="My Profile"
        subtitle="Manage your personal information and account settings"
      />

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
              <div className="flex items-center justify-between mb-2">
                <label className="block text-brand-primary text-sm font-medium uppercase">
                  Email Signature
                </label>
                {signatureMode === 'text' ? (
                  <Button variant="link" size="sm" onClick={switchToRichSignature}>
                    Use formatting
                  </Button>
                ) : (
                  <Button variant="link" size="sm" onClick={switchToPlainSignature}>
                    Switch to plain text
                  </Button>
                )}
              </div>

              {signatureMode === 'text' ? (
                <textarea
                  className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full"
                  rows={4}
                  aria-label="Email signature"
                  value={formData.email_signature}
                  onChange={(e) => setFormData({ ...formData, email_signature: e.target.value })}
                  placeholder="e.g. Coach Smith&#10;Riverside Soccer Club&#10;(555) 123-4567"
                />
              ) : (
                <SignatureEditor
                  key={editorKey}
                  value={signatureHtml}
                  onChange={setSignatureHtml}
                />
              )}

              <p className="text-gray-500 text-xs mt-1">This will be appended to all outbound emails you send.</p>

              {savedSignaturePreview && (
                <div className="mt-3" data-testid="signature-preview">
                  <p className="text-gray-500 text-xs mb-1 uppercase tracking-wide">
                    Preview — as saved
                  </p>
                  {/* Rendered from the SERVER's stored value, which has been
                      through te_sanitize_signature_html(). Previewing the
                      editor's live output instead would show formatting the
                      sanitiser removes, so what a staff member approved would
                      not be what families receive. */}
                  <div
                    className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary bg-gray-50"
                    dangerouslySetInnerHTML={{ __html: savedSignaturePreview }}
                  />
                  {signatureIsUnsaved && (
                    <p className="text-gray-500 text-xs mt-1">
                      You have unsaved changes — save to update the preview.
                    </p>
                  )}
                </div>
              )}
            </div>
          </div>

          <div className="flex justify-end">
            <Button type="submit" loading={saving}>
              Save Changes
            </Button>
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
            <Button type="submit" loading={saving}>
              Update Password
            </Button>
          </div>
        </form>
      </div>

      {/* Notifications. Rendered for staff and families alike — this page serves
          both routes (/profile and /parent/settings). */}
      <div className="bg-white border border-brand-secondary rounded-md p-6 mb-6">
        <h2 className="text-lg font-semibold text-brand-primary mb-4">Notifications</h2>
        <PushNotificationToggle />
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

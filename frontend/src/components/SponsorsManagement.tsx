import React, { useState, useEffect } from 'react';
import { useOrg } from '../contexts/OrgContext';
import CanvaGraphicActions from './canva/CanvaGraphicActions';
import PageHeader from './ui/PageHeader';

interface Sponsor {
  id?: number;
  club_id: number;
  name: string;
  website?: string;
  contact_name?: string;
  contact_email?: string;
  contact_phone?: string;
  logo_data?: string;
  logo_filename?: string;
  link_1_label?: string;
  link_1_url?: string;
  link_2_label?: string;
  link_2_url?: string;
  link_3_label?: string;
  link_3_url?: string;
  display_order?: number;
  is_active?: boolean;
}

const SponsorsManagement: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { currentClubId } = useOrg();
  const [sponsors, setSponsors] = useState<Sponsor[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedSponsor, setSelectedSponsor] = useState<Sponsor | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showInactive, setShowInactive] = useState(false);

  const emptyFormData: Sponsor = {
    club_id: currentClubId || 0,
    name: '',
    website: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    logo_data: '',
    logo_filename: '',
    link_1_label: '',
    link_1_url: '',
    link_2_label: '',
    link_2_url: '',
    link_3_label: '',
    link_3_url: '',
    is_active: true
  };

  const [formData, setFormData] = useState<Sponsor>(emptyFormData);

  // Auto-prefix URLs with https:// if missing
  const normalizeUrl = (url: string): string => {
    if (!url || url.trim() === '') return '';
    const trimmed = url.trim();
    if (!/^https?:\/\//i.test(trimmed)) {
      return `https://${trimmed}`;
    }
    return trimmed;
  };

  const handleUrlBlur = (field: keyof Sponsor) => {
    const value = formData[field];
    if (typeof value === 'string' && value) {
      setFormData({ ...formData, [field]: normalizeUrl(value) });
    }
  };

  useEffect(() => {
    if (currentClubId) {
      fetchSponsors();
    }
  }, [currentClubId, showInactive]);

  const fetchSponsors = async () => {
    try {
      const url = `${API_URL}/api/sponsors.php?club_id=${currentClubId}${showInactive ? '&include_inactive=true' : ''}`;
      const response = await fetch(url);
      const data = await response.json();
      setSponsors(Array.isArray(data) ? data : []);
    } catch (error) {
      console.error('Error fetching sponsors:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleAddSponsor = () => {
    setSelectedSponsor(null);
    setFormData({ ...emptyFormData, club_id: currentClubId || 0 });
    setShowForm(true);
  };

  const handleEditSponsor = (sponsor: Sponsor) => {
    setSelectedSponsor(sponsor);
    setFormData(sponsor);
    setShowForm(true);
  };

  const handleDeleteSponsor = async (sponsorId: number) => {
    if (!window.confirm('Are you sure you want to delete this sponsor?')) {
      return;
    }

    try {
      const response = await fetch(`${API_URL}/api/sponsors.php?id=${sponsorId}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      });

      if (response.ok) {
        fetchSponsors();
      }
    } catch (error) {
      console.error('Error deleting sponsor:', error);
    }
  };

  const handleLogoUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      if (file.size > 2 * 1024 * 1024) {
        alert('Logo file size must be less than 2MB');
        return;
      }

      const reader = new FileReader();
      reader.onloadend = () => {
        setFormData({
          ...formData,
          logo_data: reader.result as string,
          logo_filename: file.name
        });
      };
      reader.readAsDataURL(file);
    }
  };

  const handleRemoveLogo = () => {
    setFormData({
      ...formData,
      logo_data: '',
      logo_filename: ''
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);

    try {
      const url = selectedSponsor
        ? `${API_URL}/api/sponsors.php`
        : `${API_URL}/api/sponsors.php`;

      const response = await fetch(url, {
        method: selectedSponsor ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        setShowForm(false);
        fetchSponsors();
      }
    } catch (error) {
      console.error('Error saving sponsor:', error);
    } finally {
      setSaving(false);
    }
  };

  const renderLinks = (sponsor: Sponsor) => {
    const links = [];
    if (sponsor.link_1_label && sponsor.link_1_url) {
      links.push({ label: sponsor.link_1_label, url: sponsor.link_1_url });
    }
    if (sponsor.link_2_label && sponsor.link_2_url) {
      links.push({ label: sponsor.link_2_label, url: sponsor.link_2_url });
    }
    if (sponsor.link_3_label && sponsor.link_3_url) {
      links.push({ label: sponsor.link_3_label, url: sponsor.link_3_url });
    }
    return links;
  };

  if (!currentClubId) {
    return (
      <div className="text-center text-brand-primary py-12">
        Please select a club to manage sponsors.
      </div>
    );
  }

  return (
    <div>
      {/* Header */}
      <PageHeader
        title="SPONSOR MANAGEMENT"
        subtitle="Manage your club's sponsors and promotional links"
        meta={<span className="text-gray-600">{sponsors.length} sponsor{sponsors.length !== 1 ? 's' : ''} total</span>}
        actions={
          <button
            onClick={handleAddSponsor}
            className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold"
          >
            + Add Sponsor
          </button>
        }
      />

      {/* Filter */}
      <div className="mb-6 flex items-center space-x-4">
        <label className="flex items-center space-x-2 text-sm">
          <input
            type="checkbox"
            checked={showInactive}
            onChange={(e) => setShowInactive(e.target.checked)}
            className="border border-brand-secondary rounded"
          />
          <span className="text-gray-600">Show inactive</span>
        </label>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading sponsors...</div>
      ) : sponsors.length === 0 ? (
        <div className="border border-brand-secondary rounded-md p-12 text-center bg-white">
          <p className="text-gray-600 text-lg">No sponsors yet.</p>
          <p className="text-gray-500 mt-2">Click "Add Sponsor" to add your first sponsor.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {sponsors.map((sponsor) => (
            <div
              key={sponsor.id}
              className={`border border-brand-secondary rounded-md bg-white overflow-hidden ${
                !sponsor.is_active ? 'opacity-60' : ''
              }`}
            >
              {/* Logo Section */}
              <div className="h-32 bg-gray-50 flex items-center justify-center border-b border-brand-secondary">
                {sponsor.logo_data ? (
                  <img
                    src={sponsor.logo_data}
                    alt={sponsor.name}
                    className="max-h-24 max-w-full object-contain"
                  />
                ) : (
                  <div className="text-gray-400 text-4xl font-bold">
                    {sponsor.name.charAt(0).toUpperCase()}
                  </div>
                )}
              </div>

              {/* Content Section */}
              <div className="p-4">
                <div className="flex justify-between items-start mb-2">
                  <h3 className="text-lg font-semibold text-brand-primary">{sponsor.name}</h3>
                  {!sponsor.is_active && (
                    <span className="text-xs bg-gray-200 text-gray-600 px-2 py-1 rounded uppercase">
                      Inactive
                    </span>
                  )}
                </div>

                {sponsor.website && (
                  <a
                    href={sponsor.website}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-brand-primary hover:text-brand-primary text-sm block mb-2 truncate"
                  >
                    {sponsor.website}
                  </a>
                )}

                {sponsor.contact_name && (
                  <p className="text-gray-600 text-sm">Contact: {sponsor.contact_name}</p>
                )}

                {/* Links */}
                {renderLinks(sponsor).length > 0 && (
                  <div className="mt-3 flex flex-wrap gap-2">
                    {renderLinks(sponsor).map((link, index) => (
                      <a
                        key={index}
                        href={link.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs bg-brand-secondary text-brand-primary px-2 py-1 rounded hover:bg-brand-secondary"
                      >
                        {link.label}
                      </a>
                    ))}
                  </div>
                )}

                {/* Actions */}
                <div className="mt-4 pt-3 border-t border-brand-secondary flex justify-end space-x-3">
                  {sponsor.id && currentClubId && (
                    <CanvaGraphicActions
                      clubId={Number(currentClubId)}
                      subjectKind="sponsor"
                      subjectId={sponsor.id}
                      subjectName={sponsor.name}
                      apiUrl={API_URL}
                    />
                  )}
                  <button
                    onClick={() => handleEditSponsor(sponsor)}
                    className="text-brand-primary hover:text-brand-primary uppercase text-xs font-semibold"
                  >
                    Edit
                  </button>
                  <button
                    onClick={() => sponsor.id && handleDeleteSponsor(sponsor.id)}
                    className="text-red-600 hover:text-red-500 uppercase text-xs font-semibold"
                  >
                    Delete
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Form Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-brand-secondary rounded-md w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                {selectedSponsor ? 'Edit Sponsor' : 'Add Sponsor'}
              </h3>
              <button
                onClick={() => setShowForm(false)}
                className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
              >
                ×
              </button>
            </div>

            <form onSubmit={handleSubmit} className="p-6">
              <div className="space-y-6">
                {/* Basic Information */}
                <div>
                  <h4 className="text-brand-primary font-semibold mb-4 uppercase">Basic Information</h4>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="col-span-2">
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Sponsor Name *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                        value={formData.name}
                        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        required
                      />
                    </div>

                    <div className="col-span-2">
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Website
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                        value={formData.website || ''}
                        onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                        onBlur={() => handleUrlBlur('website')}
                        placeholder="example.com"
                      />
                    </div>

                    <div className="col-span-2">
                      <label className="flex items-center space-x-2">
                        <input
                          type="checkbox"
                          checked={formData.is_active ?? true}
                          onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                          className="border border-brand-secondary rounded"
                        />
                        <span className="text-brand-primary text-sm">Active (visible on public pages)</span>
                      </label>
                    </div>
                  </div>
                </div>

                {/* Contact Information */}
                <div>
                  <h4 className="text-brand-primary font-semibold mb-4 uppercase">Contact Information</h4>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Contact Name
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                        value={formData.contact_name || ''}
                        onChange={(e) => setFormData({ ...formData, contact_name: e.target.value })}
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Contact Email
                      </label>
                      <input
                        type="email"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                        value={formData.contact_email || ''}
                        onChange={(e) => setFormData({ ...formData, contact_email: e.target.value })}
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Contact Phone
                      </label>
                      <input
                        type="tel"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                        value={formData.contact_phone || ''}
                        onChange={(e) => setFormData({ ...formData, contact_phone: e.target.value })}
                      />
                    </div>
                  </div>
                </div>

                {/* Logo Upload */}
                <div>
                  <h4 className="text-brand-primary font-semibold mb-4 uppercase">Logo</h4>
                  <div className="border border-brand-secondary rounded-md p-4">
                    {formData.logo_data ? (
                      <div className="flex items-center space-x-4">
                        <img
                          src={formData.logo_data}
                          alt="Sponsor logo"
                          className="h-20 max-w-40 object-contain"
                        />
                        <div>
                          <p className="text-sm text-gray-600">{formData.logo_filename}</p>
                          <button
                            type="button"
                            onClick={handleRemoveLogo}
                            className="text-red-600 hover:text-red-500 text-sm mt-1"
                          >
                            Remove Logo
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div>
                        <input
                          type="file"
                          accept="image/*"
                          onChange={handleLogoUpload}
                          className="w-full text-brand-primary"
                        />
                        <p className="text-gray-500 text-xs mt-2">Max file size: 2MB. Recommended: PNG or JPG.</p>
                      </div>
                    )}
                  </div>
                </div>

                {/* Promotional Links */}
                <div>
                  <h4 className="text-brand-primary font-semibold mb-4 uppercase">Promotional Links</h4>
                  <div className="space-y-4">
                    {/* Link 1 */}
                    <div className="grid grid-cols-3 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2">
                          Link 1 Label
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                          value={formData.link_1_label || ''}
                          onChange={(e) => setFormData({ ...formData, link_1_label: e.target.value })}
                          placeholder="e.g., Shop Now"
                        />
                      </div>
                      <div className="col-span-2">
                        <label className="block text-brand-primary text-sm font-medium mb-2">
                          Link 1 URL
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                          value={formData.link_1_url || ''}
                          onChange={(e) => setFormData({ ...formData, link_1_url: e.target.value })}
                          onBlur={() => handleUrlBlur('link_1_url')}
                          placeholder="example.com/page"
                        />
                      </div>
                    </div>

                    {/* Link 2 */}
                    <div className="grid grid-cols-3 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2">
                          Link 2 Label
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                          value={formData.link_2_label || ''}
                          onChange={(e) => setFormData({ ...formData, link_2_label: e.target.value })}
                          placeholder="e.g., Team Discounts"
                        />
                      </div>
                      <div className="col-span-2">
                        <label className="block text-brand-primary text-sm font-medium mb-2">
                          Link 2 URL
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                          value={formData.link_2_url || ''}
                          onChange={(e) => setFormData({ ...formData, link_2_url: e.target.value })}
                          onBlur={() => handleUrlBlur('link_2_url')}
                          placeholder="example.com/page"
                        />
                      </div>
                    </div>

                    {/* Link 3 */}
                    <div className="grid grid-cols-3 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2">
                          Link 3 Label
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                          value={formData.link_3_label || ''}
                          onChange={(e) => setFormData({ ...formData, link_3_label: e.target.value })}
                          placeholder="e.g., Contact Us"
                        />
                      </div>
                      <div className="col-span-2">
                        <label className="block text-brand-primary text-sm font-medium mb-2">
                          Link 3 URL
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-primary"
                          value={formData.link_3_url || ''}
                          onChange={(e) => setFormData({ ...formData, link_3_url: e.target.value })}
                          onBlur={() => handleUrlBlur('link_3_url')}
                          placeholder="example.com/page"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Form Actions */}
              <div className="flex justify-end space-x-4 mt-6">
                <button
                  type="button"
                  onClick={() => setShowForm(false)}
                  className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase disabled:opacity-50"
                >
                  {saving ? 'Saving...' : selectedSponsor ? 'Update Sponsor' : 'Add Sponsor'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default SponsorsManagement;

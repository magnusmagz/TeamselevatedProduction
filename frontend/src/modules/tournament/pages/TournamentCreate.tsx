import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import Button from '../../../components/ui/Button';
import { useAuth } from '../../../contexts/AuthContext';
import { useOrg } from '../../../contexts/OrgContext';
import { TournamentFormData, GOVERNING_BODY_LABELS, GoverningBody } from '../types';
import { createTournament, getTournament, updateTournament } from '../api/tournamentApi';
import VenuePicker from '../components/VenuePicker';
import MarkdownEditor from '../components/MarkdownEditor';
import PageHeader from '../../../components/ui/PageHeader';

function generateSlug(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

const EMPTY_FORM: TournamentFormData = {
  name: '',
  description: '',
  sport: 'soccer',
  start_date: '',
  end_date: '',
  venue_id: null,
  daily_start_time: '08:00',
  daily_end_time: '20:00',
  location_name: '',
  location_address: '',
  location_city: '',
  location_state: '',
  location_zip: '',
  registration_open_date: '',
  registration_close_date: '',
  entry_fee_cents: 0,
  max_teams_per_division: null,
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  public_url_slug: '',
  season_id: null,
  rules_document_url: '',
  faq_markdown: '',
  insurance_certificate_url: '',
  insurance_certificate_filename: '',
  insurance_expiry_date: '',
  insurance_policy_number: '',
  insurance_provider: '',
  governing_body: '',
  sanction_number: '',
  state_association: '',
  host_name: '',
};

const TournamentCreate: React.FC = () => {
  const { user } = useAuth();
  const { currentClubId, activeContext } = useOrg();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const isEdit = Boolean(id);
  // Source club from the active org context (like ProgramManagement / comms),
  // falling back to the legacy user.organization.orgId.
  const clubId = currentClubId ?? activeContext?.scope_id ?? user?.organization?.orgId;

  const [form, setForm] = useState<TournamentFormData>(EMPTY_FORM);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(isEdit);
  const [entryFeeDisplay, setEntryFeeDisplay] = useState('');

  useEffect(() => {
    if (isEdit && id) {
      getTournament(Number(id))
        .then((t) => {
          setForm({
            name: t.name,
            description: t.description || '',
            sport: t.sport || 'soccer',
            start_date: t.start_date,
            end_date: t.end_date,
            venue_id: t.venue_id ?? null,
            daily_start_time: (t.daily_start_time || '08:00:00').slice(0, 5),
            daily_end_time: (t.daily_end_time || '20:00:00').slice(0, 5),
            location_name: t.location_name || '',
            location_address: t.location_address || '',
            location_city: t.location_city || '',
            location_state: t.location_state || '',
            location_zip: t.location_zip || '',
            registration_open_date: t.registration_open_date?.slice(0, 16) || '',
            registration_close_date: t.registration_close_date?.slice(0, 16) || '',
            entry_fee_cents: t.entry_fee_cents || 0,
            max_teams_per_division: t.max_teams_per_division,
            contact_name: t.contact_name || '',
            contact_email: t.contact_email || '',
            contact_phone: t.contact_phone || '',
            public_url_slug: t.public_url_slug || '',
            season_id: t.season_id,
            rules_document_url: t.rules_document_url || '',
            faq_markdown: t.faq_markdown || '',
            insurance_certificate_url: t.insurance_certificate_url || '',
            insurance_certificate_filename: t.insurance_certificate_filename || '',
            insurance_expiry_date: t.insurance_expiry_date || '',
            insurance_policy_number: t.insurance_policy_number || '',
            insurance_provider: t.insurance_provider || '',
            governing_body: (t.governing_body as GoverningBody) || '',
            sanction_number: t.sanction_number || '',
            state_association: t.state_association || '',
            host_name: t.host_name || '',
          });
          setEntryFeeDisplay(t.entry_fee_cents ? (t.entry_fee_cents / 100).toFixed(2) : '');
        })
        .catch((err) => console.error('Failed to load tournament:', err))
        .finally(() => setLoading(false));
    }
  }, [isEdit, id]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
    setErrors((prev) => ({ ...prev, [name]: '' }));

    // Auto-generate slug from name
    if (name === 'name' && !isEdit) {
      setForm((prev) => ({ ...prev, public_url_slug: generateSlug(value) }));
    }
  };

  const handleFeeChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value;
    setEntryFeeDisplay(value);
    const cents = Math.round(parseFloat(value || '0') * 100);
    setForm((prev) => ({ ...prev, entry_fee_cents: isNaN(cents) ? 0 : cents }));
  };

  const validate = (): boolean => {
    const errs: Record<string, string> = {};
    if (!form.name.trim()) errs.name = 'Tournament name is required';
    if (!form.host_name.trim()) errs.host_name = 'Host is required';
    if (!form.start_date) errs.start_date = 'Start date is required';
    if (!form.end_date) errs.end_date = 'End date is required';
    if (form.start_date && form.end_date && form.start_date > form.end_date) {
      errs.end_date = 'End date must be on or after start date';
    }
    if (form.registration_open_date && form.registration_close_date && form.registration_open_date >= form.registration_close_date) {
      errs.registration_close_date = 'Registration close must be after open date';
    }
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!validate() || !clubId) return;

    setSaving(true);
    try {
      if (isEdit && id) {
        await updateTournament(Number(id), form);
        navigate(`/tournaments/${id}`);
      } else {
        const result = await createTournament(clubId, form);
        navigate(`/tournaments/${result.id}`);
      }
    } catch (err: any) {
      setErrors({ submit: err.message || 'Failed to save tournament' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <main className="max-w-6xl mx-auto px-4 py-8">
        <div className="text-center text-gray-500">Loading...</div>
      </main>
    );
  }

  return (
    <main className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title={isEdit ? 'Edit Tournament' : 'Create Tournament'}
        backTo="/tournaments"
        backLabel="Back to Tournaments"
      />

      <form onSubmit={handleSubmit} className="space-y-6">
        {errors.submit && (
          <div className="bg-red-50 border border-red-200 rounded-md p-4 text-red-700 text-sm">
            {errors.submit}
          </div>
        )}

        {/*
          Two-column grid on lg+. Sections that benefit from the full width
          (Description, FAQ) get lg:col-span-2 explicitly. Default flow keeps
          shorter sections paired side-by-side.
        */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {/* Basic Info */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4 lg:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900">Basic Information</h2>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Tournament Name *</label>
              <input
                type="text"
                name="name"
                value={form.name}
                onChange={handleChange}
                className={`w-full border rounded-md px-3 py-2 text-sm ${errors.name ? 'border-red-500' : 'border-gray-300'}`}
                placeholder="Spring Classic 2026"
              />
              {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Host *</label>
              <input
                type="text"
                name="host_name"
                value={form.host_name}
                onChange={handleChange}
                className={`w-full border rounded-md px-3 py-2 text-sm ${errors.host_name ? 'border-red-500' : 'border-gray-300'}`}
                placeholder="e.g. Phoenix Rising Soccer Club"
              />
              {errors.host_name
                ? <p className="text-red-500 text-xs mt-1">{errors.host_name}</p>
                : <p className="text-xs text-gray-400 mt-1">Shown on the public page as "Hosted by"</p>}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea
              name="description"
              value={form.description}
              onChange={handleChange}
              rows={3}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              placeholder="Annual spring tournament..."
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Sport</label>
              <select
                name="sport"
                value={form.sport}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              >
                <option value="soccer">Soccer</option>
                <option value="basketball">Basketball</option>
                <option value="baseball">Baseball</option>
                <option value="softball">Softball</option>
                <option value="lacrosse">Lacrosse</option>
                <option value="volleyball">Volleyball</option>
                <option value="field_hockey">Field Hockey</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">URL Slug</label>
              <input
                type="text"
                name="public_url_slug"
                value={form.public_url_slug}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="spring-classic-2026"
              />
            </div>
          </div>
        </section>

        {/* Dates */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
          <h2 className="text-lg font-semibold text-gray-900">Dates</h2>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
              <input
                type="date"
                name="start_date"
                value={form.start_date}
                onChange={handleChange}
                className={`w-full border rounded-md px-3 py-2 text-sm ${errors.start_date ? 'border-red-500' : 'border-gray-300'}`}
              />
              {errors.start_date && <p className="text-red-500 text-xs mt-1">{errors.start_date}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">End Date *</label>
              <input
                type="date"
                name="end_date"
                value={form.end_date}
                onChange={handleChange}
                className={`w-full border rounded-md px-3 py-2 text-sm ${errors.end_date ? 'border-red-500' : 'border-gray-300'}`}
              />
              {errors.end_date && <p className="text-red-500 text-xs mt-1">{errors.end_date}</p>}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Registration Opens</label>
              <input
                type="datetime-local"
                name="registration_open_date"
                value={form.registration_open_date}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Registration Closes</label>
              <input
                type="datetime-local"
                name="registration_close_date"
                value={form.registration_close_date}
                onChange={handleChange}
                className={`w-full border rounded-md px-3 py-2 text-sm ${errors.registration_close_date ? 'border-red-500' : 'border-gray-300'}`}
              />
              {errors.registration_close_date && <p className="text-red-500 text-xs mt-1">{errors.registration_close_date}</p>}
            </div>
          </div>
        </section>

        {/* Venue */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
          <h2 className="text-lg font-semibold text-gray-900">Venue</h2>
          <p className="text-xs text-gray-500 -mt-2">
            Select the venue where this tournament's matches will be played. The schedule generator will use this venue's fields.
          </p>

          <VenuePicker
            value={form.venue_id}
            onChange={(venueId) => setForm((prev) => ({ ...prev, venue_id: venueId }))}
          />
        </section>

        {/* Daily Window */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
          <h2 className="text-lg font-semibold text-gray-900">Daily Schedule Window</h2>
          <p className="text-xs text-gray-500 -mt-2">
            The earliest and latest times matches can be scheduled each day. The generator will roll matches to the next day if they would extend past the end time.
          </p>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Earliest match start</label>
              <input
                type="time"
                name="daily_start_time"
                value={form.daily_start_time}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Latest match end</label>
              <input
                type="time"
                name="daily_end_time"
                value={form.daily_end_time}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              />
            </div>
          </div>
        </section>

        {/* Fees & Settings */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
          <h2 className="text-lg font-semibold text-gray-900">Fees & Settings</h2>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Entry Fee ($)</label>
              <input
                type="number"
                step="0.01"
                min="0"
                value={entryFeeDisplay}
                onChange={handleFeeChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="500.00"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Max Teams per Division</label>
              <input
                type="number"
                min="2"
                name="max_teams_per_division"
                value={form.max_teams_per_division ?? ''}
                onChange={(e) => setForm((prev) => ({ ...prev, max_teams_per_division: e.target.value ? Number(e.target.value) : null }))}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="16"
              />
            </div>
          </div>
        </section>

        {/* Contact */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4 lg:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900">Contact Information</h2>

          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Contact Name</label>
              <input type="text" name="contact_name" value={form.contact_name} onChange={handleChange} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Contact Email</label>
              <input type="email" name="contact_email" value={form.contact_email} onChange={handleChange} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Contact Phone</label>
              <input type="tel" name="contact_phone" value={form.contact_phone} onChange={handleChange} className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
            </div>
          </div>
        </section>

        {/* Sanctioning & Affiliation */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
          <h2 className="text-lg font-semibold text-gray-900">Sanctioning &amp; Affiliation</h2>
          <p className="text-xs text-gray-500 -mt-2">
            Cup orgs look for these on the public page as a trust signal. Also used by the age-eligibility checker when adding players to a tournament roster.
          </p>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Governing body</label>
            <select
              name="governing_body"
              value={form.governing_body}
              onChange={handleChange}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
            >
              <option value="">— Not specified —</option>
              {(Object.keys(GOVERNING_BODY_LABELS) as GoverningBody[]).map((b) => (
                <option key={b} value={b}>{GOVERNING_BODY_LABELS[b]}</option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Sanction number</label>
              <input
                type="text"
                name="sanction_number"
                value={form.sanction_number}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="e.g. USYS-2026-44210"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">State association (optional)</label>
              <input
                type="text"
                name="state_association"
                value={form.state_association}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="e.g. Cal North, NTSSA"
              />
            </div>
          </div>
        </section>

        {/* Insurance Certificate */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4 lg:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900">Insurance Certificate</h2>
          <p className="text-xs text-gray-500 -mt-2">
            Required by most governing bodies (USYS / US Club / AYSO). Upload the certificate, set the expiry, and record the policy number / provider for compliance reference.
          </p>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Certificate file</label>
              {form.insurance_certificate_url ? (
                <div className="flex items-center gap-3 p-3 border border-gray-200 rounded-md bg-gray-50">
                  <span className="text-sm text-gray-700 truncate flex-1">
                    📄 {form.insurance_certificate_filename || 'Insurance certificate'}
                  </span>
                  <a
                    href={form.insurance_certificate_url.startsWith('http') ? form.insurance_certificate_url : `${process.env.REACT_APP_API_URL || ''}${form.insurance_certificate_url}`}
                    target="_blank" rel="noopener noreferrer"
                    className="text-xs text-brand-primary hover:underline"
                  >View</a>
                  <Button variant="danger-link" size="sm" onClick={() => setForm((p) => ({ ...p, insurance_certificate_url: '', insurance_certificate_filename: '' }))}>Remove</Button>
                </div>
              ) : (
                <label className="block border-2 border-dashed border-gray-300 rounded-md p-3 text-center cursor-pointer hover:border-brand-primary text-sm text-gray-500">
                  📎 Click to upload PDF or image
                  <input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    className="hidden"
                    onChange={async (e) => {
                      const file = e.target.files?.[0];
                      if (!file) return;
                      const token = localStorage.getItem('auth_token');
                      const fd = new FormData();
                      fd.append('file', file);
                      fd.append('type', 'tournament-insurance');
                      try {
                        const res = await fetch(`${process.env.REACT_APP_API_URL || 'http://localhost:8889'}/api/upload.php`, {
                          method: 'POST',
                          headers: { Authorization: `Bearer ${token}` },
                          body: fd,
                        });
                        if (!res.ok) { const err = await res.json(); throw new Error(err.error || 'Upload failed'); }
                        const data = await res.json();
                        const url = data.url || data.path || '';
                        setForm((p) => ({
                          ...p,
                          insurance_certificate_url: url,
                          insurance_certificate_filename: file.name,
                        }));
                      } catch (err: any) {
                        setErrors((prev) => ({ ...prev, insurance_certificate_url: err.message || 'Upload failed' }));
                      }
                    }}
                  />
                </label>
              )}
              {errors.insurance_certificate_url && (
                <p className="text-red-500 text-xs mt-1">{errors.insurance_certificate_url}</p>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Expires</label>
              <input
                type="date"
                name="insurance_expiry_date"
                value={form.insurance_expiry_date}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Policy number</label>
              <input
                type="text"
                name="insurance_policy_number"
                value={form.insurance_policy_number}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="e.g., POL-2026-44210"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Provider</label>
              <input
                type="text"
                name="insurance_provider"
                value={form.insurance_provider}
                onChange={handleChange}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                placeholder="e.g., Sadler Sports Insurance"
              />
            </div>
          </div>
        </section>

        {/* Public FAQ */}
        <section className="bg-white border border-gray-200 rounded-lg p-6 space-y-4 lg:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900">Public FAQ</h2>
          <p className="text-xs text-gray-500 -mt-2">
            Common questions (parking, check-in, weather, food). Renders on the public tournament page under an "Info" tab.
          </p>
          <MarkdownEditor
            value={form.faq_markdown}
            onChange={(md) => setForm((prev) => ({ ...prev, faq_markdown: md }))}
            placeholder="Add sections like Check-In, Parking, Weather Policy..."
            minHeight={260}
          />
        </section>

        </div>
        {/* /grid */}

        {/* Actions */}
        <div className="flex justify-end space-x-3">
          <Button variant="secondary" onClick={() => navigate(isEdit ? `/tournaments/${id}` : '/tournaments')}>
            Cancel
          </Button>
          <Button type="submit" loading={saving}>
            {isEdit ? 'Save Changes' : 'Create Tournament'}
          </Button>
        </div>
      </form>
    </main>
  );
};

export default TournamentCreate;

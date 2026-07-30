import React, { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';
import { ParentHeader } from '../components/ParentHeader';

/**
 * Crew-facing medical record.
 *
 * TWO THINGS THIS PAGE GOT WRONG BEFORE 2026-07-30
 *
 * 1. It read `allergies` / `medications` / `blood_type` / `insurance_*` off
 *    `api/athletes/?action=get`, i.e. off the `athletes` row. Those columns live
 *    in `athlete_medical` and have never existed on `athletes`, so every field
 *    came back undefined — and because the old InfoRow hid empty values, the page
 *    rendered as an empty shell rather than an error. It was blank for everyone,
 *    silently, for as long as it has existed. The record now comes from
 *    `legacy/medical-gateway.php`, which is the only reader that decrypts the PHI.
 *
 * 2. It was read-only. A parent is the authoritative source for their own child's
 *    allergies and medications, so they can now edit — a decision made
 *    deliberately rather than inherited (see CLAUDE.md, Roles & Permissions).
 *
 * WHAT A PARENT MAY EDIT, AND WHAT THEY MAY NOT
 * Editable: what the family knows first-hand — allergies, conditions, meds, blood
 * type, physician, insurance, asthma/EpiPen and where the device is kept, plus the
 * physical dates they hold the paperwork for.
 * Not editable here: concussion history, last concussion date and return-to-play.
 * Those are clinical//staff determinations about whether a child may play, and a
 * parent editing them would be overriding a medical clearance, not correcting a
 * fact about their own child. They stay visible, read-only, so the family can see
 * what the club holds.
 */

interface MedicalRecord {
  athlete_id: number;
  exists?: boolean;
  allergies?: string | null;
  allergy_severity?: string | null;
  medical_conditions?: string | null;
  medications?: string | null;
  blood_type?: string | null;
  physician_name?: string | null;
  physician_phone?: string | null;
  insurance_provider?: string | null;
  insurance_policy_number?: string | null;
  insurance_group_number?: string | null;
  has_asthma?: boolean | string | null;
  inhaler_location?: string | null;
  has_epipen?: boolean | string | null;
  epipen_location?: string | null;
  special_instructions?: string | null;
  last_physical_date?: string | null;
  physical_expiry_date?: string | null;
  // Read-only below — clinical determinations, shown but not editable by crew.
  concussion_history?: string | null;
  last_concussion_date?: string | null;
  return_to_play_date?: string | null;
}

/** The keys this page is allowed to submit. Anything else is left untouched. */
const EDITABLE_KEYS = [
  'allergies', 'allergy_severity', 'medical_conditions', 'medications', 'blood_type',
  'physician_name', 'physician_phone',
  'insurance_provider', 'insurance_policy_number', 'insurance_group_number',
  'has_asthma', 'inhaler_location', 'has_epipen', 'epipen_location',
  'special_instructions', 'last_physical_date', 'physical_expiry_date',
] as const;

/** Postgres booleans arrive as `true`/`'t'`/`'true'` depending on the driver path. */
const asBool = (v: boolean | string | null | undefined): boolean =>
  v === true || v === 'true' || v === 't' || v === '1';

/** `<input type="date">` needs bare YYYY-MM-DD; the API may return a timestamp. */
const asDateInput = (v: string | null | undefined): string =>
  typeof v === 'string' && v.length >= 10 ? v.slice(0, 10) : '';

export const MedicalInfoPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { id } = useParams<{ id: string }>();
  const { accessibleAthleteIds, loading: permissionsLoading } = useFinancialPermissions();

  // Defense-in-depth: medical data is the most sensitive record in the system —
  // only fetch/show it for an athlete this parent actually has access to. The
  // backend enforces this too (medicalRequireAccess).
  const accessAllowed =
    permissionsLoading || (!!id && accessibleAthleteIds.includes(Number(id)));
  const accessDenied = !permissionsLoading && !accessAllowed;

  const [medical, setMedical] = useState<MedicalRecord | null>(null);
  const [draft, setDraft] = useState<MedicalRecord | null>(null);
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [justSaved, setJustSaved] = useState(false);

  const fetchMedical = useCallback(async () => {
    if (!id || permissionsLoading) return;
    if (!accessAllowed) {
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(
        `${API_URL}/legacy/medical-gateway.php?athlete_id=${id}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      const data = await response.json();

      if (response.ok && data.success) {
        setMedical(data.medical || { athlete_id: Number(id), exists: false });
      } else {
        setError(data.error || 'Failed to load medical information');
      }
    } catch {
      setError('Failed to load medical information');
    } finally {
      setLoading(false);
    }
  }, [API_URL, id, accessAllowed, permissionsLoading]);

  useEffect(() => {
    fetchMedical();
  }, [fetchMedical]);

  const startEditing = () => {
    setDraft({ ...(medical || { athlete_id: Number(id) }) });
    setSaveError(null);
    setJustSaved(false);
    setEditing(true);
  };

  const setField = (key: keyof MedicalRecord, value: string | boolean) =>
    setDraft((d) => (d ? { ...d, [key]: value } : d));

  const handleSave = async () => {
    if (!draft) return;
    setSaving(true);
    setSaveError(null);

    // Send only the crew-editable keys. The gateway binds on array_key_exists,
    // so omitting a key leaves it alone — which is how the clinical fields above
    // survive a save from this page untouched.
    const payload: Record<string, unknown> = { athlete_id: Number(id) };
    for (const key of EDITABLE_KEYS) {
      const v = draft[key as keyof MedicalRecord];
      payload[key] = typeof v === 'boolean' ? v : v ?? '';
    }

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/medical-gateway.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(payload),
      });
      const data = await response.json();

      if (response.ok && data.success) {
        setEditing(false);
        setJustSaved(true);
        // Re-read rather than trusting the draft: the server decrypts, normalizes
        // empty dates to null and may have rejected a value we still hold.
        await fetchMedical();
      } else {
        setSaveError(data.error || 'Could not save. Please try again.');
      }
    } catch {
      setSaveError('Unable to reach the server. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const Section: React.FC<{ title: string; children: React.ReactNode }> = ({ title, children }) => (
    <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
      <div className="px-4 py-3 bg-gray-50 border-b border-gray-200">
        <h2 className="font-semibold text-brand-primary">{title}</h2>
      </div>
      <div className="p-4 space-y-3">{children}</div>
    </div>
  );

  /** Read view. Renders "Not provided" rather than hiding — a blank page is how
   *  the broken version disguised itself as an empty one. */
  const Row: React.FC<{ label: string; value?: string | null }> = ({ label, value }) => (
    <div className="py-1.5 border-b border-gray-100 last:border-0">
      <p className="text-sm text-gray-500">{label}</p>
      <p className={value ? 'text-gray-900' : 'text-gray-400'}>{value || 'Not provided'}</p>
    </div>
  );

  const Field: React.FC<{
    label: string;
    k: keyof MedicalRecord;
    type?: 'text' | 'date' | 'textarea';
    placeholder?: string;
  }> = ({ label, k, type = 'text', placeholder }) => {
    const raw = draft?.[k];
    const value = type === 'date' ? asDateInput(raw as string) : ((raw as string) ?? '');
    const cls =
      'w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-primary disabled:opacity-50';
    return (
      <div>
        <label className="block text-sm text-gray-600 mb-1" htmlFor={`medical-${k}`}>{label}</label>
        {type === 'textarea' ? (
          <textarea
            id={`medical-${k}`} rows={3} className={cls} disabled={saving}
            placeholder={placeholder} value={value}
            onChange={(e) => setField(k, e.target.value)}
          />
        ) : (
          <input
            id={`medical-${k}`} type={type} className={cls} disabled={saving}
            placeholder={placeholder} value={value}
            onChange={(e) => setField(k, e.target.value)}
          />
        )}
      </div>
    );
  };

  const Toggle: React.FC<{ label: string; k: keyof MedicalRecord }> = ({ label, k }) => (
    <label className="flex items-center gap-2 text-sm text-gray-700">
      <input
        type="checkbox" disabled={saving} checked={asBool(draft?.[k] as boolean)}
        onChange={(e) => setField(k, e.target.checked)}
        className="h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
      />
      {label}
    </label>
  );

  const shell = (children: React.ReactNode) => (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title="Medical Info" showBack />
      <div className="pt-14 px-4 pb-6">{children}</div>
    </div>
  );

  if (accessDenied) {
    return shell(
      <div className="mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
        Access denied. You do not have permission to view this athlete's medical information.
      </div>
    );
  }

  if (loading) {
    return shell(
      <div className="flex items-center justify-center py-12">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
      </div>
    );
  }

  if (error || !medical) {
    return shell(
      <div className="mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
        {error || 'Medical information not found'}
      </div>
    );
  }

  if (editing && draft) {
    return shell(
      <div className="space-y-4 pt-4">
        <Section title="Medical">
          <Field label="Allergies" k="allergies" type="textarea" placeholder="e.g. peanuts, bee stings" />
          <Field label="Allergy severity" k="allergy_severity" placeholder="e.g. severe / anaphylactic" />
          <Field label="Medical conditions" k="medical_conditions" type="textarea" />
          <Field label="Current medications" k="medications" type="textarea" />
          <Field label="Blood type" k="blood_type" placeholder="e.g. O+" />
        </Section>

        <Section title="Asthma & Allergy Devices">
          <Toggle label="Has asthma" k="has_asthma" />
          <Field label="Inhaler location" k="inhaler_location" placeholder="e.g. in their kit bag" />
          <Toggle label="Carries an EpiPen" k="has_epipen" />
          <Field label="EpiPen location" k="epipen_location" placeholder="e.g. side pocket of backpack" />
        </Section>

        <Section title="Physician & Insurance">
          <Field label="Physician name" k="physician_name" />
          <Field label="Physician phone" k="physician_phone" />
          <Field label="Insurance provider" k="insurance_provider" />
          <Field label="Policy number" k="insurance_policy_number" />
          <Field label="Group number" k="insurance_group_number" />
        </Section>

        <Section title="Physical">
          <Field label="Last physical" k="last_physical_date" type="date" />
          <Field label="Physical expires" k="physical_expiry_date" type="date" />
          <Field label="Anything else the club should know" k="special_instructions" type="textarea" />
        </Section>

        {saveError && (
          <div className="bg-red-50 text-red-700 px-4 py-3 rounded-lg text-sm">{saveError}</div>
        )}

        <div className="flex gap-3">
          <button
            onClick={() => { setEditing(false); setSaveError(null); }}
            disabled={saving}
            className="flex-1 px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm font-medium disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={handleSave}
            disabled={saving}
            className="flex-1 px-4 py-2 bg-brand-primary text-white rounded-md hover:opacity-90 text-sm font-medium disabled:opacity-50"
          >
            {saving ? 'Saving...' : 'Save'}
          </button>
        </div>
      </div>
    );
  }

  const hasClinicalNotes =
    medical.concussion_history || medical.last_concussion_date || medical.return_to_play_date;

  return shell(
    <div className="space-y-4 pt-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-gray-600">
          {medical.exists ? 'On file with the club.' : 'Nothing on file yet.'}
        </p>
        <button onClick={startEditing} className="text-sm text-brand-accent hover:underline">
          {medical.exists ? 'Edit' : 'Add details'}
        </button>
      </div>

      {justSaved && <p className="text-sm text-green-700">Saved.</p>}

      <Section title="Medical">
        <Row label="Allergies" value={medical.allergies} />
        <Row label="Allergy severity" value={medical.allergy_severity} />
        <Row label="Medical conditions" value={medical.medical_conditions} />
        <Row label="Current medications" value={medical.medications} />
        <Row label="Blood type" value={medical.blood_type} />
      </Section>

      <Section title="Asthma & Allergy Devices">
        <Row label="Has asthma" value={asBool(medical.has_asthma) ? 'Yes' : 'No'} />
        {asBool(medical.has_asthma) && <Row label="Inhaler location" value={medical.inhaler_location} />}
        <Row label="Carries an EpiPen" value={asBool(medical.has_epipen) ? 'Yes' : 'No'} />
        {asBool(medical.has_epipen) && <Row label="EpiPen location" value={medical.epipen_location} />}
      </Section>

      <Section title="Physician & Insurance">
        <Row label="Physician" value={medical.physician_name} />
        <Row label="Physician phone" value={medical.physician_phone} />
        <Row label="Insurance provider" value={medical.insurance_provider} />
        <Row label="Policy number" value={medical.insurance_policy_number} />
        <Row label="Group number" value={medical.insurance_group_number} />
      </Section>

      <Section title="Physical">
        <Row label="Last physical" value={asDateInput(medical.last_physical_date) || null} />
        <Row label="Physical expires" value={asDateInput(medical.physical_expiry_date) || null} />
        <Row label="Notes for the club" value={medical.special_instructions} />
      </Section>

      {hasClinicalNotes && (
        <Section title="Clinical (club-managed)">
          <p className="text-xs text-gray-500 -mt-1 mb-2">
            Recorded by club staff. Contact your club to correct anything here.
          </p>
          <Row label="Concussion history" value={medical.concussion_history} />
          <Row label="Last concussion" value={asDateInput(medical.last_concussion_date) || null} />
          <Row label="Cleared to return" value={asDateInput(medical.return_to_play_date) || null} />
        </Section>
      )}
    </div>
  );
};

export default MedicalInfoPage;

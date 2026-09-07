import React, { useState, useEffect, useRef } from 'react';
import { useOrg } from '../contexts/OrgContext';
import { GRADE_OPTIONS } from '../utils/grade';
import { JERSEY_SIZE_GROUPS, jerseySizesInGroup } from '../utils/jerseySize';
import Button from './ui/Button';

/**
 * ⚠️ THERE IS NO PRIMARY CREW MEMBER. Crew members are equal (product rule,
 * 2026-09-02). This form deliberately has no control that ranks one guardian
 * above another, sends no `is_primary_contact` on save, and labels the cards
 * "Crew member 1/2/3" by position only — a number for the reader, not a rank.
 */
const emptyGuardian = (): GuardianData => ({
  first_name: '', last_name: '', email: '', mobile_phone: '', relationship_type: 'Parent',
});

interface GuardianData {
  // athlete_guardians.id — the LINK row, not the guardian. Returned by the
  // single-athlete GET and carried through so a save can tell the gateway which
  // crew members are still attached. Absent on rows added in the form.
  id?: number;
  first_name: string;
  last_name: string;
  email: string;
  mobile_phone: string;
  work_phone?: string;
  relationship_type: string;
  address_line1?: string;
  city?: string;
  state?: string;
  zip_code?: string;
}

interface EmergencyContact {
  contact_name: string;
  relationship: string;
  primary_phone: string;
  alternate_phone?: string;
  can_authorize_medical: boolean;
}

interface AthleteFormData {
  first_name: string;
  middle_initial?: string;
  last_name: string;
  preferred_name?: string;
  date_of_birth: string;
  gender: 'Male' | 'Female' | 'Non-binary';
  home_address_line1: string;
  home_address_line2?: string;
  city: string;
  state: string;
  zip_code: string;
  country?: string;
  school_name?: string;
  grade_level?: number;
  // Uniform jersey size, e.g. 'YM' / 'AL'. Athlete-level, not per-team: jersey
  // *number* belongs to a team membership, size belongs to the kid.
  jersey_size?: string;
  dietary_restrictions?: string[];
  guardians?: GuardianData[];
  emergency_contacts?: EmergencyContact[];
  medical?: {
    // Allergies
    allergies?: string;
    allergy_severity?: 'none' | 'mild' | 'moderate' | 'severe' | 'life-threatening';
    // Medical Conditions
    medical_conditions?: string;
    medications?: string;
    // Emergency Equipment
    has_asthma?: boolean;
    inhaler_location?: string;
    has_epipen?: boolean;
    epipen_location?: string;
    // Physician Information
    physician_name?: string;
    physician_phone?: string;
    physician_address?: string;
    // Insurance
    insurance_provider?: string;
    insurance_policy_number?: string;
    insurance_group_number?: string;
    // Physical Information
    last_physical_date?: string;
    physical_expiry_date?: string;
    height_inches?: number;
    weight_lbs?: number;
    blood_type?: string;
    // Consent
    emergency_treatment_consent?: boolean;
    special_instructions?: string;
  };
}

interface AthleteFormProps {
  athlete?: any;
  onSubmit: () => void;
  onClose: () => void;
}

const AthleteForm: React.FC<AthleteFormProps> = ({ athlete, onSubmit, onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const { currentClubId } = useOrg();
  const [currentStep, setCurrentStep] = useState(1);
  const [formData, setFormData] = useState<AthleteFormData>({
    first_name: '',
    middle_initial: '',
    last_name: '',
    preferred_name: '',
    date_of_birth: '',
    gender: 'Male',
    home_address_line1: '',
    home_address_line2: '',
    city: '',
    state: '',
    zip_code: '',
    country: 'USA',
    school_name: '',
    grade_level: undefined,
    jersey_size: '',
    dietary_restrictions: [],
    guardians: [emptyGuardian()],
    emergency_contacts: [{
      contact_name: '',
      relationship: '',
      primary_phone: '',
      can_authorize_medical: false
    }],
    medical: {
      allergies: '',
      allergy_severity: 'none',
      medical_conditions: '',
      medications: '',
      has_asthma: false,
      inhaler_location: '',
      has_epipen: false,
      epipen_location: '',
      physician_name: '',
      physician_phone: '',
      physician_address: '',
      insurance_provider: '',
      insurance_policy_number: '',
      insurance_group_number: '',
      last_physical_date: '',
      physical_expiry_date: '',
      height_inches: undefined,
      weight_lbs: undefined,
      blood_type: '',
      emergency_treatment_consent: true,
      special_instructions: ''
    }
  });

  // When step 3 (Emergency & Medical) was entered — see the guard in handleSubmit.
  const step3EnteredAt = useRef<number>(0);
  useEffect(() => {
    if (currentStep === 3) step3EnteredAt.current = Date.now();
  }, [currentStep]);

  useEffect(() => {
    if (athlete) {
      setFormData({
        ...athlete,
        guardians: (athlete.guardians && athlete.guardians.length > 0)
          ? athlete.guardians
          : [emptyGuardian()],
        emergency_contacts: athlete.emergency_contacts || formData.emergency_contacts,
        medical: athlete.medical || formData.medical
      });

      if (athlete.id) {
        fetchMedicalData(athlete.id);
      }
    }
  }, [athlete]);

  const fetchMedicalData = async (athleteId: number) => {
    try {
      const response = await fetch(`${API_URL}/legacy/medical-gateway.php?athlete_id=${athleteId}`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      const data = await response.json();
      if (data.success && data.medical && data.medical.exists) {
        setFormData(prev => ({
          ...prev,
          medical: {
            allergies: data.medical.allergies || '',
            allergy_severity: data.medical.allergy_severity || 'none',
            medical_conditions: data.medical.medical_conditions || '',
            medications: data.medical.medications || '',
            has_asthma: data.medical.has_asthma || false,
            inhaler_location: data.medical.inhaler_location || '',
            has_epipen: data.medical.has_epipen || false,
            epipen_location: data.medical.epipen_location || '',
            physician_name: data.medical.physician_name || '',
            physician_phone: data.medical.physician_phone || '',
            physician_address: data.medical.physician_address || '',
            insurance_provider: data.medical.insurance_provider || '',
            insurance_policy_number: data.medical.insurance_policy_number || '',
            insurance_group_number: data.medical.insurance_group_number || '',
            last_physical_date: data.medical.last_physical_date || '',
            physical_expiry_date: data.medical.physical_expiry_date || '',
            height_inches: data.medical.height_inches || undefined,
            weight_lbs: data.medical.weight_lbs || undefined,
            blood_type: data.medical.blood_type || '',
            emergency_treatment_consent: data.medical.emergency_treatment_consent ?? true,
            special_instructions: data.medical.special_instructions || ''
          }
        }));
      }
    } catch (error) {
      console.error('Error fetching medical data:', error);
    }
  };

  const handleChange = (field: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      [field]: value
    }));
  };

  const handleGuardianChange = (index: number, field: string, value: any) => {
    setFormData(prev => {
      const guardians = [...(prev.guardians || [])];
      guardians[index] = { ...guardians[index], [field]: value };
      return { ...prev, guardians };
    });
  };

  const addGuardian = () => {
    setFormData(prev => ({
      ...prev,
      guardians: [...(prev.guardians || []), emptyGuardian()],
    }));
  };

  const removeGuardian = (index: number) => {
    setFormData(prev => ({
      ...prev,
      guardians: (prev.guardians || []).filter((_, i) => i !== index),
    }));
  };

  const handleMedicalChange = (field: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      medical: {
        ...prev.medical!,
        [field]: value
      }
    }));
  };

  const handleEmergencyContactChange = (index: number, field: string, value: any) => {
    setFormData(prev => {
      const contacts = [...(prev.emergency_contacts || [])];
      contacts[index] = {
        ...contacts[index],
        [field]: value
      };
      return { ...prev, emergency_contacts: contacts };
    });
  };

  const addEmergencyContact = () => {
    setFormData(prev => ({
      ...prev,
      emergency_contacts: [
        ...(prev.emergency_contacts || []),
        { contact_name: '', relationship: '', primary_phone: '', can_authorize_medical: false }
      ]
    }));
  };

  const removeEmergencyContact = (index: number) => {
    setFormData(prev => ({
      ...prev,
      emergency_contacts: prev.emergency_contacts?.filter((_, i) => i !== index)
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Only the final step saves. Cheap guard against any future path that
    // submits the form from an earlier step.
    if (currentStep !== 3) return;

    // "Next" and "Update Athlete" render into the same slot with the same
    // styling, so the moment step 3 renders the save button sits exactly where
    // the cursor just clicked. A double-click on Next therefore saved the
    // athlete immediately, before the user could touch the emergency fields.
    // Ignore a submit that lands in the instant after arriving on this step;
    // a deliberate click is always well past this window.
    if (Date.now() - step3EnteredAt.current < 500) return;

    // Validate required fields
    if (!formData.first_name || !formData.last_name) {
      alert('First name and last name are required');
      return;
    }

    try {
      // Send all athlete profile fields to the updated API
      const submitData: any = {
        first_name: formData.first_name,
        last_name: formData.last_name,
        middle_initial: formData.middle_initial,
        preferred_name: formData.preferred_name,
        date_of_birth: formData.date_of_birth,
        gender: formData.gender,
        home_address_line1: formData.home_address_line1,
        home_address_line2: formData.home_address_line2,
        city: formData.city,
        state: formData.state,
        zip_code: formData.zip_code,
        school_name: formData.school_name,
        grade_level: formData.grade_level,
        // '' when no size is on file; the gateway maps that to NULL rather than
        // letting it hit the CHECK constraint (lib/jersey_size.php).
        jersey_size: formData.jersey_size ?? '',
        email: formData.guardians?.[0]?.email || `${formData.first_name.toLowerCase()}.${formData.last_name.toLowerCase()}@student.com`,
        // Stamp the active club so the new athlete is visible in this club's
        // Athletes list even before any team assignment (CA-18 write side).
        club_id: currentClubId,
        // Emergency contacts were collected on step 3 and then dropped here —
        // they were never part of this payload, so nothing was ever saved and the
        // tab blanked on every revisit. The gateway replaces the athlete's full
        // set, so send the whole list (blank rows are ignored server-side).
        emergency_contacts: (formData.emergency_contacts || []).filter(
          c => c.contact_name?.trim()
        ),
        // Crew members still on the form, by athlete_guardians.id. Removing one
        // here previously did nothing: we POSTed the survivors and never unlinked
        // the removed row, so they reappeared on reload. The gateway unlinks any
        // guardian attached to this athlete that isn't in this list. Newly added
        // crew have no id yet and are created after this call, so they're safe.
        guardian_link_ids: (formData.guardians || [])
          .map(g => g.id)
          .filter((id): id is number => typeof id === 'number' && id > 0)
      };

      if (athlete) {
        submitData.id = athlete.id;
      }

      const url = athlete
        ? `${API_URL}/legacy/athletes-gateway.php?id=${athlete.id}`
        : `${API_URL}/legacy/athletes-gateway.php`;
      const response = await fetch(url, {
        method: athlete ? 'PUT' : 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        },
        body: JSON.stringify(submitData)
      });

      if (response.ok) {
        const athleteData = await response.json();
        const athleteId = athleteData.athlete_id || athleteData.id || athlete?.id;

        // guardian-gateway POST is idempotent (matches on email+first+last, or on
        // the link id, and updates the athlete link), so re-saving on edit won't
        // duplicate.
        //
        // No `is_primary_contact` is sent. Crew members are equal (2026-09-02),
        // and the gateway no longer reads the key — but the reason to omit it
        // rather than send a false is that a payload which says "nobody is
        // primary" is still a payload making a claim about primaries.
        //
        // A row with NOTHING in it is the blank placeholder the form always
        // renders — skip it. A row with SOME fields is someone the user was
        // actually trying to add, and it used to be dropped here silently: the
        // filter required all four fields, so adding a crew member you had an
        // email but no phone for reported "saved successfully" and saved nobody.
        // That is how a real crew member went missing with no error anywhere.
        const enteredGuardians = (formData.guardians || []).filter(
          g => g.first_name || g.last_name || g.email || g.mobile_phone
        );
        const incomplete = enteredGuardians
          .map((g, idx) => {
            const missing = [
              !g.first_name && 'first name',
              !g.last_name && 'last name',
              !g.email && 'email',
              !g.mobile_phone && 'mobile phone',
            ].filter(Boolean);
            return missing.length
              ? `${g.first_name || g.last_name || `Crew member ${idx + 1}`}: needs ${missing.join(', ')}`
              : null;
          })
          .filter(Boolean);

        if (incomplete.length > 0) {
          // The athlete is already saved at this point, so say so plainly rather
          // than implying nothing happened. All four are required server-side.
          alert(
            `The athlete was saved, but this crew member could not be added:\n\n${incomplete.join(
              '\n'
            )}\n\nAdd the missing details and save again.`
          );
          onSubmit();
          return;
        }

        for (let i = 0; i < enteredGuardians.length; i++) {
          const guardianData = {
            athlete_id: athleteId,
            ...enteredGuardians[i],
            has_legal_custody: 1,
            can_authorize_medical: 1,
            can_pickup: 1,
            receives_communications: 1,
            financial_responsible: 1
          };

          // The response used to be discarded, exactly like the medical save
          // below before it was fixed — so a crew member the server REFUSED still
          // produced "saved successfully". Whatever the server says, say it.
          const guardianResponse = await fetch(`${API_URL}/legacy/guardian-gateway.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
            body: JSON.stringify(guardianData)
          });

          if (!guardianResponse.ok) {
            let detail = '';
            try {
              detail = (await guardianResponse.json())?.error ?? '';
            } catch {
              /* non-JSON error body */
            }
            const who = `${guardianData.first_name || ''} ${guardianData.last_name || ''}`.trim()
              || `crew member ${i + 1}`;
            alert(
              `The athlete was saved, but ${who} could not be added${detail ? `: ${detail}` : '.'}`
            );
            onSubmit();
            return;
          }
        }

        // Save medical information if provided
        if (formData.medical) {
          const medicalData = {
            athlete_id: athleteId,
            ...formData.medical,
            // Convert empty strings to null for numeric fields
            height_inches: formData.medical.height_inches || null,
            weight_lbs: formData.medical.weight_lbs || null
          };

          // Medical data is scoped server-side now; without this header the
          // save 401s. Response IS checked below — this call used to be
          // fire-and-forget, which is how a failing save looked successful.
          const medicalResponse = await fetch(`${API_URL}/legacy/medical-gateway.php`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
            },
            body: JSON.stringify(medicalData)
          });

          if (!medicalResponse.ok) {
            // Previously this response was ignored entirely, so a failing medical
            // save still produced "saved successfully!". Say what actually
            // happened instead of claiming a save that did not occur.
            let detail = '';
            try {
              detail = (await medicalResponse.json())?.error ?? '';
            } catch {
              /* non-JSON error body */
            }
            alert(
              `The athlete was saved, but their medical information could not be saved${
                detail ? `: ${detail}` : '.'
              }`
            );
            onSubmit();
            return;
          }
        }

        alert('Athlete saved successfully with medical information!');
        onSubmit();
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to save athlete');
      }
    } catch (error) {
      console.error('Error saving athlete:', error);
      alert('Failed to save athlete');
    }
  };

  const calculateAge = (dob: string) => {
    if (!dob) return null;
    const birthDate = new Date(dob);
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
      age--;
    }
    return age;
  };

  const age = calculateAge(formData.date_of_birth);

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
            {athlete ? 'Edit Athlete' : 'Create New Athlete'}
          </h3>
          <Button variant="ghost" size="icon" aria-label="Close" className="text-2xl" onClick={onClose}>
            ×
          </Button>
        </div>

        <div className="p-6">
          {/* Step Indicator */}
          <div className="flex justify-between mb-8">
            {['Athlete Info', 'Crew Info', 'Emergency & Medical'].map((step, index) => (
              <div
                key={index}
                className={`flex-1 text-center py-2 border border-brand-secondary rounded-md ${
                  currentStep === index + 1
                    ? 'bg-white text-brand-primary font-bold'
                    : 'bg-gray-100 text-brand-primary'
                }`}
              >
                {step}
              </div>
            ))}
          </div>

          {/* Enter inside a field must not save the athlete. Step 3 is the only
              step that renders a submit button, so from there a stray Enter while
              typing an emergency contact submitted the whole form. Textareas keep
              their newline behaviour. */}
          <form
            onSubmit={handleSubmit}
            onKeyDown={(e) => {
              const tag = (e.target as HTMLElement).tagName;
              // BUTTON is exempt so a keyboard user can still press Enter on a
              // focused Next / Update Athlete; TEXTAREA keeps its newlines.
              if (e.key === 'Enter' && tag !== 'TEXTAREA' && tag !== 'BUTTON') {
                e.preventDefault();
              }
            }}
          >
            {/* Step 1: Athlete Information */}
            {currentStep === 1 && (
              <div className="space-y-6">
                <h4 className="text-lg font-semibold text-brand-primary mb-4 uppercase">Athlete Information</h4>

                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      First Name *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.first_name}
                      onChange={(e) => handleChange('first_name', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Middle Initial
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.middle_initial}
                      onChange={(e) => handleChange('middle_initial', e.target.value)}
                      maxLength={1}
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Last Name *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.last_name}
                      onChange={(e) => handleChange('last_name', e.target.value)}
                      required
                    />
                  </div>
                </div>

                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Preferred Name
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.preferred_name}
                      onChange={(e) => handleChange('preferred_name', e.target.value)}
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Date of Birth *
                    </label>
                    <input
                      type="date"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.date_of_birth}
                      onChange={(e) => handleChange('date_of_birth', e.target.value)}
                      required
                    />
                    {age !== null && (
                      <p className="text-gray-600 text-sm mt-1">Age: {age} years</p>
                    )}
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Gender *
                    </label>
                    <select
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.gender}
                      onChange={(e) => handleChange('gender', e.target.value)}
                      required
                    >
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                      <option value="Non-binary">Non-binary</option>
                    </select>
                  </div>
                </div>

                <div className="space-y-4">
                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Address Line 1 *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.home_address_line1}
                      onChange={(e) => handleChange('home_address_line1', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Address Line 2
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.home_address_line2}
                      onChange={(e) => handleChange('home_address_line2', e.target.value)}
                    />
                  </div>

                  <div className="grid grid-cols-3 gap-4">
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        City *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.city}
                        onChange={(e) => handleChange('city', e.target.value)}
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        State *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.state}
                        onChange={(e) => handleChange('state', e.target.value)}
                        maxLength={2}
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Zip Code *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.zip_code}
                        onChange={(e) => handleChange('zip_code', e.target.value)}
                        required
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        School Name
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.school_name}
                        onChange={(e) => handleChange('school_name', e.target.value)}
                      />
                    </div>

                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Grade Level
                      </label>
                      <select
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.grade_level ?? ''}
                        onChange={(e) => handleChange('grade_level', e.target.value === '' ? null : parseInt(e.target.value, 10))}
                      >
                        <option value="">Select grade</option>
                        {GRADE_OPTIONS.map((o) => (
                          <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                      </select>
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                        Jersey Size
                      </label>
                      <select
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.jersey_size ?? ''}
                        onChange={(e) => handleChange('jersey_size', e.target.value)}
                      >
                        <option value="">Select size</option>
                        {JERSEY_SIZE_GROUPS.map((group) => (
                          <optgroup key={group} label={group}>
                            {jerseySizesInGroup(group).map((o) => (
                              <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                          </optgroup>
                        ))}
                      </select>
                      <p className="text-gray-500 text-xs mt-1">
                        Used for uniform orders. Applies to the athlete across all teams.
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Step 2: Crew Information */}
            {currentStep === 2 && (
              <div className="space-y-6">
                <h4 className="text-lg font-semibold text-brand-primary uppercase">Crew Information</h4>

                {(formData.guardians || []).map((guardian, gi) => (
                  <div key={gi} className="space-y-4 border border-brand-secondary rounded-lg p-4">
                    <div className="flex items-center justify-between">
                      {/* A position, not a rank. Crew members are equal — this
                          heading numbers the cards so the required-field messages
                          below can name one, and nothing more. */}
                      <span className="text-sm font-semibold text-brand-primary uppercase">
                        {`Crew Member ${gi + 1}`}
                      </span>
                      {(formData.guardians?.length || 0) > 1 && (
                        <Button variant="danger-link" onClick={() => removeGuardian(gi)}>
                          Remove
                        </Button>
                      )}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          First Name {gi === 0 ? '*' : ''}
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={guardian.first_name}
                          onChange={(e) => handleGuardianChange(gi, 'first_name', e.target.value)}
                          required={gi === 0}
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Last Name {gi === 0 ? '*' : ''}
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={guardian.last_name}
                          onChange={(e) => handleGuardianChange(gi, 'last_name', e.target.value)}
                          required={gi === 0}
                        />
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Email {gi === 0 ? '*' : ''}
                        </label>
                        <input
                          type="email"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={guardian.email}
                          onChange={(e) => handleGuardianChange(gi, 'email', e.target.value)}
                          required={gi === 0}
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Mobile Phone {gi === 0 ? '*' : ''}
                        </label>
                        <input
                          type="tel"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={guardian.mobile_phone}
                          onChange={(e) => handleGuardianChange(gi, 'mobile_phone', e.target.value)}
                          required={gi === 0}
                        />
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Relationship {gi === 0 ? '*' : ''}
                        </label>
                        <select
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={guardian.relationship_type}
                          onChange={(e) => handleGuardianChange(gi, 'relationship_type', e.target.value)}
                          required={gi === 0}
                        >
                          <option value="Parent">Parent</option>
                          <option value="Guardian">Guardian</option>
                          <option value="Other">Other</option>
                        </select>
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Work Phone
                        </label>
                        <input
                          type="tel"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={guardian.work_phone || ''}
                          onChange={(e) => handleGuardianChange(gi, 'work_phone', e.target.value)}
                        />
                      </div>
                    </div>
                  </div>
                ))}

                <Button variant="secondary" fullWidth onClick={addGuardian}>
                  + Add another crew member
                </Button>
              </div>
            )}

            {/* Step 3: Emergency & Medical Information */}
            {currentStep === 3 && (
              <div className="space-y-6">
                <div>
                  <h4 className="text-lg font-semibold text-brand-primary mb-4 uppercase">Emergency Contacts</h4>
                  {formData.emergency_contacts?.map((contact, index) => (
                    <div key={index} className="bg-white border border-brand-secondary rounded-md p-4 mb-4">
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Contact Name *
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={contact.contact_name}
                            onChange={(e) => handleEmergencyContactChange(index, 'contact_name', e.target.value)}
                            required
                          />
                        </div>

                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Relationship *
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={contact.relationship}
                            onChange={(e) => handleEmergencyContactChange(index, 'relationship', e.target.value)}
                            required
                          />
                        </div>

                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Primary Phone *
                          </label>
                          <input
                            type="tel"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={contact.primary_phone}
                            onChange={(e) => handleEmergencyContactChange(index, 'primary_phone', e.target.value)}
                            required
                          />
                        </div>

                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Can Authorize Medical
                          </label>
                          <select
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={contact.can_authorize_medical ? 'yes' : 'no'}
                            onChange={(e) => handleEmergencyContactChange(index, 'can_authorize_medical', e.target.value === 'yes')}
                          >
                            <option value="no">No</option>
                            <option value="yes">Yes</option>
                          </select>
                        </div>
                      </div>

                      {formData.emergency_contacts!.length > 1 && (
                        <Button variant="danger-link" className="mt-2" onClick={() => removeEmergencyContact(index)}>
                          Remove Contact
                        </Button>
                      )}
                    </div>
                  ))}

                  <Button variant="secondary" onClick={addEmergencyContact}>
                    + Add Emergency Contact
                  </Button>
                </div>

                <div>
                  <h4 className="text-lg font-semibold text-brand-primary mb-4 uppercase">Medical Information</h4>

                  {/* Critical Allergy Information */}
                  <div className="bg-red-50 border-2 border-red-300 p-4 mb-6">
                    <h5 className="font-semibold text-red-800 mb-3 uppercase">Critical Allergy Information</h5>
                    <div className="space-y-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Allergies (food, medication, environmental)
                        </label>
                        <textarea
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          rows={2}
                          placeholder="e.g., Peanuts, Bee stings, Penicillin"
                          value={formData.medical?.allergies}
                          onChange={(e) => handleMedicalChange('allergies', e.target.value)}
                        />
                      </div>

                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Allergy Severity
                          </label>
                          <select
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={formData.medical?.allergy_severity}
                            onChange={(e) => handleMedicalChange('allergy_severity', e.target.value)}
                          >
                            <option value="none">No Allergies</option>
                            <option value="mild">Mild</option>
                            <option value="moderate">Moderate</option>
                            <option value="severe">Severe</option>
                            <option value="life-threatening">Life-Threatening</option>
                          </select>
                        </div>

                        <div>
                          <label className="flex items-center text-brand-primary mt-6">
                            <input
                              type="checkbox"
                              className="mr-2"
                              checked={formData.medical?.has_epipen}
                              onChange={(e) => handleMedicalChange('has_epipen', e.target.checked)}
                            />
                            Carries EpiPen
                          </label>
                          {formData.medical?.has_epipen && (
                            <input
                              type="text"
                              className="w-full mt-2 bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                              placeholder="EpiPen location (e.g., backpack, coach)"
                              value={formData.medical?.epipen_location}
                              onChange={(e) => handleMedicalChange('epipen_location', e.target.value)}
                            />
                          )}
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Medical Conditions */}
                  <div className="bg-yellow-50 border-2 border-yellow-300 p-4 mb-6">
                    <h5 className="font-semibold text-yellow-800 mb-3 uppercase">Medical Conditions & Medications</h5>
                    <div className="space-y-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Medical Conditions
                        </label>
                        <textarea
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          rows={2}
                          placeholder="e.g., Diabetes, ADHD, Epilepsy, Heart condition"
                          value={formData.medical?.medical_conditions}
                          onChange={(e) => handleMedicalChange('medical_conditions', e.target.value)}
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Current Medications
                        </label>
                        <textarea
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          rows={2}
                          placeholder="List all current medications and dosages"
                          value={formData.medical?.medications}
                          onChange={(e) => handleMedicalChange('medications', e.target.value)}
                        />
                      </div>

                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="flex items-center text-brand-primary">
                            <input
                              type="checkbox"
                              className="mr-2"
                              checked={formData.medical?.has_asthma}
                              onChange={(e) => handleMedicalChange('has_asthma', e.target.checked)}
                            />
                            Has Asthma
                          </label>
                          {formData.medical?.has_asthma && (
                            <input
                              type="text"
                              className="w-full mt-2 bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                              placeholder="Inhaler location (e.g., backpack, coach)"
                              value={formData.medical?.inhaler_location}
                              onChange={(e) => handleMedicalChange('inhaler_location', e.target.value)}
                            />
                          )}
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Insurance Information */}
                  <div className="border border-brand-secondary rounded-md p-4 mb-6">
                    <h5 className="font-semibold text-brand-primary mb-3 uppercase">Insurance Information</h5>
                    <div className="grid grid-cols-3 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Insurance Provider
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.medical?.insurance_provider}
                          onChange={(e) => handleMedicalChange('insurance_provider', e.target.value)}
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Policy Number
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.medical?.insurance_policy_number}
                          onChange={(e) => handleMedicalChange('insurance_policy_number', e.target.value)}
                        />
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Group Number
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          value={formData.medical?.insurance_group_number}
                          onChange={(e) => handleMedicalChange('insurance_group_number', e.target.value)}
                        />
                      </div>
                    </div>
                  </div>

                  {/* Physician & Physical Information */}
                  <div className="grid grid-cols-2 gap-6 mb-6">
                    <div className="border border-brand-secondary rounded-md p-4">
                      <h5 className="font-semibold text-brand-primary mb-3 uppercase">Primary Physician</h5>
                      <div className="space-y-3">
                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Physician Name
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={formData.medical?.physician_name}
                            onChange={(e) => handleMedicalChange('physician_name', e.target.value)}
                          />
                        </div>
                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Phone
                          </label>
                          <input
                            type="tel"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={formData.medical?.physician_phone}
                            onChange={(e) => handleMedicalChange('physician_phone', e.target.value)}
                          />
                        </div>
                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Address
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={formData.medical?.physician_address}
                            onChange={(e) => handleMedicalChange('physician_address', e.target.value)}
                          />
                        </div>
                      </div>
                    </div>

                    <div className="border border-brand-secondary rounded-md p-4">
                      <h5 className="font-semibold text-brand-primary mb-3 uppercase">Physical Information</h5>
                      <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                              Last Physical
                            </label>
                            <input
                              type="date"
                              className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                              value={formData.medical?.last_physical_date}
                              onChange={(e) => handleMedicalChange('last_physical_date', e.target.value)}
                            />
                          </div>
                          <div>
                            <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                              Expires
                            </label>
                            <input
                              type="date"
                              className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                              value={formData.medical?.physical_expiry_date}
                              onChange={(e) => handleMedicalChange('physical_expiry_date', e.target.value)}
                            />
                          </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                              Height (inches)
                            </label>
                            <input
                              type="number"
                              className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                              value={formData.medical?.height_inches}
                              onChange={(e) => handleMedicalChange('height_inches', parseInt(e.target.value))}
                            />
                          </div>
                          <div>
                            <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                              Weight (lbs)
                            </label>
                            <input
                              type="number"
                              className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                              value={formData.medical?.weight_lbs}
                              onChange={(e) => handleMedicalChange('weight_lbs', parseInt(e.target.value))}
                            />
                          </div>
                        </div>
                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Blood Type
                          </label>
                          <select
                            className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                            value={formData.medical?.blood_type}
                            onChange={(e) => handleMedicalChange('blood_type', e.target.value)}
                          >
                            <option value="">Select Blood Type</option>
                            <option value="A+">A+</option>
                            <option value="A-">A-</option>
                            <option value="B+">B+</option>
                            <option value="B-">B-</option>
                            <option value="AB+">AB+</option>
                            <option value="AB-">AB-</option>
                            <option value="O+">O+</option>
                            <option value="O-">O-</option>
                          </select>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Emergency Consent & Special Instructions */}
                  <div className="border border-brand-secondary rounded-md p-4">
                    <div className="space-y-4">
                      <div>
                        <label className="flex items-center text-brand-primary">
                          <input
                            type="checkbox"
                            className="mr-2"
                            checked={formData.medical?.emergency_treatment_consent}
                            onChange={(e) => handleMedicalChange('emergency_treatment_consent', e.target.checked)}
                          />
                          <span className="font-semibold">I consent to emergency medical treatment if necessary</span>
                        </label>
                      </div>

                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Special Instructions or Additional Medical Information
                        </label>
                        <textarea
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                          rows={3}
                          placeholder="Any additional medical information, special care instructions, or important notes for coaches and medical staff"
                          value={formData.medical?.special_instructions}
                          onChange={(e) => handleMedicalChange('special_instructions', e.target.value)}
                        />
                      </div>
                    </div>
                  </div>

                  {/* Parental consent is NOT collected here — see ConsentGate in the
                      parent portal. This screen used to carry two "Parental Consent
                      (Required)" checkboxes that were local React state: never sent
                      anywhere, never written to consent_records, and force-set to true
                      whenever anyone edited an existing athlete. They gated this form's
                      submit button and nothing else, so the product claimed COPPA
                      consent capture and stored none. Staff ticking a box on a parent's
                      behalf would not have been parental consent even if it HAD been
                      stored. Removed 2026-07-30; the parent now attests in their own
                      portal and it is recorded via api/consent.php?action=record. */}
                </div>
              </div>
            )}

            {/* Navigation Buttons */}
            <div className="flex justify-between mt-8">
              <div>
                {currentStep > 1 && (
                  <Button variant="secondary" onClick={() => setCurrentStep(currentStep - 1)}>
                    Previous
                  </Button>
                )}
              </div>

              <div className="flex space-x-4">
                <Button variant="secondary" onClick={onClose}>
                  Cancel
                </Button>

                {currentStep < 3 ? (
                  <Button onClick={() => setCurrentStep(currentStep + 1)}>
                    Next
                  </Button>
                ) : (
                  <Button type="submit">
                    {athlete ? 'Update Athlete' : 'Create Athlete'}
                  </Button>
                )}
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
};

export default AthleteForm;
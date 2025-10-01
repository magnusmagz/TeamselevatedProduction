import React, { useState, useEffect } from 'react';

interface GuardianData {
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
  dietary_restrictions?: string[];
  guardian?: GuardianData;
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
    dietary_restrictions: [],
    guardian: {
      first_name: '',
      last_name: '',
      email: '',
      mobile_phone: '',
      relationship_type: 'Mother'
    },
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

  useEffect(() => {
    if (athlete) {
      setFormData({
        ...athlete,
        guardian: athlete.guardians?.[0] || formData.guardian,
        emergency_contacts: athlete.emergency_contacts || formData.emergency_contacts
      });
    }
  }, [athlete]);

  const handleChange = (field: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      [field]: value
    }));
  };

  const handleGuardianChange = (field: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      guardian: {
        ...prev.guardian!,
        [field]: value
      }
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
        email: formData.guardian?.email || `${formData.first_name.toLowerCase()}.${formData.last_name.toLowerCase()}@student.com`
      };

      if (athlete) {
        submitData.id = athlete.id;
      }

      const url = athlete
        ? `http://localhost:8889/athletes-gateway.php?id=${athlete.id}`
        : 'http://localhost:8889/athletes-gateway.php';
      const response = await fetch(url, {
        method: athlete ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(submitData)
      });

      if (response.ok) {
        const athleteData = await response.json();
        const athleteId = athleteData.id || athlete?.id;

        // Save guardian information if provided
        if (formData.guardian?.first_name && formData.guardian?.last_name && formData.guardian?.email && formData.guardian?.mobile_phone) {
          const guardianData = {
            athlete_id: athleteId,
            ...formData.guardian,
            is_primary_contact: 1,
            has_legal_custody: 1,
            can_authorize_medical: 1,
            can_pickup: 1,
            receives_communications: 1,
            financial_responsible: 1
          };

          await fetch('http://localhost:8889/guardian-gateway.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(guardianData)
          });
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

          await fetch('http://localhost:8889/medical-gateway.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(medicalData)
          });
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
      <div className="bg-white border-2 border-forest-800 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
            {athlete ? 'Edit Athlete' : 'Create New Athlete'}
          </h3>
          <button onClick={onClose} className="text-forest-800 hover:bg-gray-100 px-2 text-2xl">
            ×
          </button>
        </div>

        <div className="p-6">
          {/* Step Indicator */}
          <div className="flex justify-between mb-8">
            {['Athlete Info', 'Guardian Info', 'Emergency & Medical'].map((step, index) => (
              <div
                key={index}
                className={`flex-1 text-center py-2 border-2 border-forest-800 ${
                  currentStep === index + 1
                    ? 'bg-white text-forest-800 font-bold'
                    : 'bg-gray-100 text-forest-800'
                }`}
              >
                {step}
              </div>
            ))}
          </div>

          <form onSubmit={handleSubmit}>
            {/* Step 1: Athlete Information */}
            {currentStep === 1 && (
              <div className="space-y-6">
                <h4 className="text-lg font-semibold text-forest-800 mb-4 uppercase">Athlete Information</h4>

                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      First Name *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.first_name}
                      onChange={(e) => handleChange('first_name', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Middle Initial
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.middle_initial}
                      onChange={(e) => handleChange('middle_initial', e.target.value)}
                      maxLength={1}
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Last Name *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.last_name}
                      onChange={(e) => handleChange('last_name', e.target.value)}
                      required
                    />
                  </div>
                </div>

                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Preferred Name
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.preferred_name}
                      onChange={(e) => handleChange('preferred_name', e.target.value)}
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Date of Birth *
                    </label>
                    <input
                      type="date"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.date_of_birth}
                      onChange={(e) => handleChange('date_of_birth', e.target.value)}
                      required
                    />
                    {age !== null && (
                      <p className="text-gray-600 text-sm mt-1">Age: {age} years</p>
                    )}
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Gender *
                    </label>
                    <select
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Address Line 1 *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.home_address_line1}
                      onChange={(e) => handleChange('home_address_line1', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Address Line 2
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.home_address_line2}
                      onChange={(e) => handleChange('home_address_line2', e.target.value)}
                    />
                  </div>

                  <div className="grid grid-cols-3 gap-4">
                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        City *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.city}
                        onChange={(e) => handleChange('city', e.target.value)}
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        State *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.state}
                        onChange={(e) => handleChange('state', e.target.value)}
                        maxLength={2}
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        Zip Code *
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.zip_code}
                        onChange={(e) => handleChange('zip_code', e.target.value)}
                        required
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        School Name
                      </label>
                      <input
                        type="text"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.school_name}
                        onChange={(e) => handleChange('school_name', e.target.value)}
                      />
                    </div>

                    <div>
                      <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                        Grade Level
                      </label>
                      <input
                        type="number"
                        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                        value={formData.grade_level}
                        onChange={(e) => handleChange('grade_level', parseInt(e.target.value))}
                        min="1"
                        max="12"
                      />
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Step 2: Guardian Information */}
            {currentStep === 2 && (
              <div className="space-y-6">
                <h4 className="text-lg font-semibold text-forest-800 mb-4 uppercase">Guardian Information</h4>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Guardian First Name *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.guardian?.first_name}
                      onChange={(e) => handleGuardianChange('first_name', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Guardian Last Name *
                    </label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.guardian?.last_name}
                      onChange={(e) => handleGuardianChange('last_name', e.target.value)}
                      required
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Email *
                    </label>
                    <input
                      type="email"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.guardian?.email}
                      onChange={(e) => handleGuardianChange('email', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Mobile Phone *
                    </label>
                    <input
                      type="tel"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.guardian?.mobile_phone}
                      onChange={(e) => handleGuardianChange('mobile_phone', e.target.value)}
                      required
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Relationship *
                    </label>
                    <select
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.guardian?.relationship_type}
                      onChange={(e) => handleGuardianChange('relationship_type', e.target.value)}
                      required
                    >
                      <option value="Mother">Mother</option>
                      <option value="Father">Father</option>
                      <option value="Stepparent">Stepparent</option>
                      <option value="Grandparent">Grandparent</option>
                      <option value="Guardian">Guardian</option>
                      <option value="Other">Other</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                      Work Phone
                    </label>
                    <input
                      type="tel"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.guardian?.work_phone}
                      onChange={(e) => handleGuardianChange('work_phone', e.target.value)}
                    />
                  </div>
                </div>
              </div>
            )}

            {/* Step 3: Emergency & Medical Information */}
            {currentStep === 3 && (
              <div className="space-y-6">
                <div>
                  <h4 className="text-lg font-semibold text-forest-800 mb-4 uppercase">Emergency Contacts</h4>
                  {formData.emergency_contacts?.map((contact, index) => (
                    <div key={index} className="bg-white border-2 border-forest-800 p-4 mb-4">
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Contact Name *
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                            value={contact.contact_name}
                            onChange={(e) => handleEmergencyContactChange(index, 'contact_name', e.target.value)}
                            required
                          />
                        </div>

                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Relationship *
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                            value={contact.relationship}
                            onChange={(e) => handleEmergencyContactChange(index, 'relationship', e.target.value)}
                            required
                          />
                        </div>

                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Primary Phone *
                          </label>
                          <input
                            type="tel"
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                            value={contact.primary_phone}
                            onChange={(e) => handleEmergencyContactChange(index, 'primary_phone', e.target.value)}
                            required
                          />
                        </div>

                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Can Authorize Medical
                          </label>
                          <select
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                            value={contact.can_authorize_medical ? 'yes' : 'no'}
                            onChange={(e) => handleEmergencyContactChange(index, 'can_authorize_medical', e.target.value === 'yes')}
                          >
                            <option value="no">No</option>
                            <option value="yes">Yes</option>
                          </select>
                        </div>
                      </div>

                      {formData.emergency_contacts!.length > 1 && (
                        <button
                          type="button"
                          onClick={() => removeEmergencyContact(index)}
                          className="mt-2 text-red-400 hover:text-red-300"
                        >
                          Remove Contact
                        </button>
                      )}
                    </div>
                  ))}

                  <button
                    type="button"
                    onClick={addEmergencyContact}
                    className="bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 hover:bg-gray-100"
                  >
                    + Add Emergency Contact
                  </button>
                </div>

                <div>
                  <h4 className="text-lg font-semibold text-forest-800 mb-4 uppercase">Medical Information</h4>

                  {/* Critical Allergy Information */}
                  <div className="bg-red-50 border-2 border-red-300 p-4 mb-6">
                    <h5 className="font-semibold text-red-800 mb-3 uppercase">Critical Allergy Information</h5>
                    <div className="space-y-4">
                      <div>
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Allergies (food, medication, environmental)
                        </label>
                        <textarea
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          rows={2}
                          placeholder="e.g., Peanuts, Bee stings, Penicillin"
                          value={formData.medical?.allergies}
                          onChange={(e) => handleMedicalChange('allergies', e.target.value)}
                        />
                      </div>

                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Allergy Severity
                          </label>
                          <select
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
                          <label className="flex items-center text-forest-800 mt-6">
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
                              className="w-full mt-2 bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Medical Conditions
                        </label>
                        <textarea
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          rows={2}
                          placeholder="e.g., Diabetes, ADHD, Epilepsy, Heart condition"
                          value={formData.medical?.medical_conditions}
                          onChange={(e) => handleMedicalChange('medical_conditions', e.target.value)}
                        />
                      </div>

                      <div>
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Current Medications
                        </label>
                        <textarea
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          rows={2}
                          placeholder="List all current medications and dosages"
                          value={formData.medical?.medications}
                          onChange={(e) => handleMedicalChange('medications', e.target.value)}
                        />
                      </div>

                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="flex items-center text-forest-800">
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
                              className="w-full mt-2 bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
                  <div className="border-2 border-forest-800 p-4 mb-6">
                    <h5 className="font-semibold text-forest-800 mb-3 uppercase">Insurance Information</h5>
                    <div className="grid grid-cols-3 gap-4">
                      <div>
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Insurance Provider
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          value={formData.medical?.insurance_provider}
                          onChange={(e) => handleMedicalChange('insurance_provider', e.target.value)}
                        />
                      </div>

                      <div>
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Policy Number
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          value={formData.medical?.insurance_policy_number}
                          onChange={(e) => handleMedicalChange('insurance_policy_number', e.target.value)}
                        />
                      </div>

                      <div>
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Group Number
                        </label>
                        <input
                          type="text"
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          value={formData.medical?.insurance_group_number}
                          onChange={(e) => handleMedicalChange('insurance_group_number', e.target.value)}
                        />
                      </div>
                    </div>
                  </div>

                  {/* Physician & Physical Information */}
                  <div className="grid grid-cols-2 gap-6 mb-6">
                    <div className="border-2 border-forest-800 p-4">
                      <h5 className="font-semibold text-forest-800 mb-3 uppercase">Primary Physician</h5>
                      <div className="space-y-3">
                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Physician Name
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                            value={formData.medical?.physician_name}
                            onChange={(e) => handleMedicalChange('physician_name', e.target.value)}
                          />
                        </div>
                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Phone
                          </label>
                          <input
                            type="tel"
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                            value={formData.medical?.physician_phone}
                            onChange={(e) => handleMedicalChange('physician_phone', e.target.value)}
                          />
                        </div>
                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Address
                          </label>
                          <input
                            type="text"
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                            value={formData.medical?.physician_address}
                            onChange={(e) => handleMedicalChange('physician_address', e.target.value)}
                          />
                        </div>
                      </div>
                    </div>

                    <div className="border-2 border-forest-800 p-4">
                      <h5 className="font-semibold text-forest-800 mb-3 uppercase">Physical Information</h5>
                      <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                              Last Physical
                            </label>
                            <input
                              type="date"
                              className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                              value={formData.medical?.last_physical_date}
                              onChange={(e) => handleMedicalChange('last_physical_date', e.target.value)}
                            />
                          </div>
                          <div>
                            <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                              Expires
                            </label>
                            <input
                              type="date"
                              className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                              value={formData.medical?.physical_expiry_date}
                              onChange={(e) => handleMedicalChange('physical_expiry_date', e.target.value)}
                            />
                          </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                              Height (inches)
                            </label>
                            <input
                              type="number"
                              className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                              value={formData.medical?.height_inches}
                              onChange={(e) => handleMedicalChange('height_inches', parseInt(e.target.value))}
                            />
                          </div>
                          <div>
                            <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                              Weight (lbs)
                            </label>
                            <input
                              type="number"
                              className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                              value={formData.medical?.weight_lbs}
                              onChange={(e) => handleMedicalChange('weight_lbs', parseInt(e.target.value))}
                            />
                          </div>
                        </div>
                        <div>
                          <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                            Blood Type
                          </label>
                          <select
                            className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
                  <div className="border-2 border-forest-800 p-4">
                    <div className="space-y-4">
                      <div>
                        <label className="flex items-center text-forest-800">
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
                        <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                          Special Instructions or Additional Medical Information
                        </label>
                        <textarea
                          className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                          rows={3}
                          placeholder="Any additional medical information, special care instructions, or important notes for coaches and medical staff"
                          value={formData.medical?.special_instructions}
                          onChange={(e) => handleMedicalChange('special_instructions', e.target.value)}
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Navigation Buttons */}
            <div className="flex justify-between mt-8">
              <div>
                {currentStep > 1 && (
                  <button
                    type="button"
                    onClick={() => setCurrentStep(currentStep - 1)}
                    className="bg-forest-600 text-white px-6 py-2 hover:bg-forest-500"
                  >
                    Previous
                  </button>
                )}
              </div>

              <div className="flex space-x-4">
                <button
                  type="button"
                  onClick={onClose}
                  className="bg-forest-600 text-white px-6 py-2 hover:bg-forest-500"
                >
                  Cancel
                </button>

                {currentStep < 3 ? (
                  <button
                    type="button"
                    onClick={() => setCurrentStep(currentStep + 1)}
                    className="bg-forest-500 text-white px-6 py-2 hover:bg-forest-400 font-semibold"
                  >
                    Next
                  </button>
                ) : (
                  <button
                    type="submit"
                    className="bg-forest-500 text-white px-6 py-2 hover:bg-forest-400 font-semibold"
                  >
                    {athlete ? 'Update Athlete' : 'Create Athlete'}
                  </button>
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
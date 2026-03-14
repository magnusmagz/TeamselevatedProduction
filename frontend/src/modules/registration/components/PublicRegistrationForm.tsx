import React, { useState, useEffect } from 'react';
import { Program, FormField } from '../types';

interface PaymentItem {
  id: number;
  name: string;
  base_price: string;
  item_type: string;
  allow_payment_plan: boolean;
}

interface PublicRegistrationFormProps {
  embedCode: string;
  embedded?: boolean;
}

const PublicRegistrationForm: React.FC<PublicRegistrationFormProps> = ({ embedCode, embedded = false }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [program, setProgram] = useState<Program | null>(null);
  const [formFields, setFormFields] = useState<FormField[]>([]);
  const [formData, setFormData] = useState<Record<string, any>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [consentDataCollection, setConsentDataCollection] = useState(false);
  const [consentMedicalData, setConsentMedicalData] = useState(false);
  const [registrationFee, setRegistrationFee] = useState<number>(0);

  useEffect(() => {
    fetchProgramDetails();
  }, [embedCode]);

  const fetchProgramDetails = async () => {
    try {
      const response = await fetch(
        `${API_URL}/registration/programs-api.php?path=by-embed&code=${embedCode}`
      );
      const data = await response.json();

      if (data && data.id) {
        setProgram(data);

        // Parse options field from JSON string to array
        const parsedFields = (data.form_fields || []).map((field: any) => ({
          ...field,
          options: field.options && typeof field.options === 'string'
            ? JSON.parse(field.options)
            : field.options
        }));
        setFormFields(parsedFields);

        // Initialize form data with empty values
        const initialData: Record<string, any> = {};
        parsedFields.forEach((field: FormField) => {
          initialData[field.field_name] = field.field_type === 'checkbox' ? false : '';
        });
        setFormData(initialData);

        // Fetch payment items for this program
        try {
          const paymentResponse = await fetch(
            `${API_URL}/api/payment-items.php?program_id=${data.id}`
          );
          const paymentData = await paymentResponse.json();
          if (paymentData.success && paymentData.items) {
            // Find registration fee
            const regFee = paymentData.items.find((item: PaymentItem) => item.item_type === 'registration');
            if (regFee) {
              setRegistrationFee(parseFloat(regFee.base_price));
            }
          }
        } catch (err) {
          console.error('Error fetching payment items:', err);
        }
      }
    } catch (error) {
      console.error('Error fetching program:', error);
    } finally {
      setLoading(false);
    }
  };

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};

    formFields.forEach(field => {
      if (field.required) {
        const value = formData[field.field_name];
        if (field.field_type === 'checkbox' && !value) {
          newErrors[field.field_name] = 'This field is required';
        } else if (field.field_type !== 'checkbox' && (!value || value.toString().trim() === '')) {
          newErrors[field.field_name] = 'This field is required';
        }
      }

      // Email validation
      if (field.field_type === 'email' && formData[field.field_name]) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(formData[field.field_name])) {
          newErrors[field.field_name] = 'Please enter a valid email address';
        }
      }
    });

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!validateForm()) {
      return;
    }

    if (!consentDataCollection || !consentMedicalData) {
      return;
    }

    setSubmitting(true);

    const payload = {
      program_id: program?.id,
      form_data: formData,
      consent_data_collection: consentDataCollection,
      consent_medical_data: consentMedicalData,
    };
    console.log('Submitting registration:', payload);

    try {
      const response = await fetch(`${API_URL}/registration/registrations-api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const result = await response.json();

      if (response.ok && result.success) {
        // Always show success/thank you message - registration is pending approval
        setSubmitted(true);
      } else {
        console.error('Registration error:', result);
        alert(`Error: ${result.error || 'An error occurred. Please try again.'}`);
      }
    } catch (error) {
      console.error('Error submitting registration:', error);
      alert('An error occurred. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleFieldChange = (fieldName: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      [fieldName]: value
    }));

    // Clear error when user starts typing
    if (errors[fieldName]) {
      setErrors(prev => {
        const newErrors = { ...prev };
        delete newErrors[fieldName];
        return newErrors;
      });
    }
  };

  const renderField = (field: FormField) => {
    const fieldId = `field-${field.field_name}`;
    const hasError = !!errors[field.field_name];

    const inputClasses = `w-full bg-white text-brand-primary border rounded-md ${
      hasError ? 'border-red-500' : 'border-brand-secondary'
    } px-4 py-2 focus:outline-none focus:border-brand-accent`;

    switch (field.field_type) {
      case 'text':
      case 'email':
      case 'tel':
      case 'number':
      case 'date':
        return (
          <input
            id={fieldId}
            type={field.field_type}
            className={inputClasses}
            value={formData[field.field_name] || ''}
            onChange={(e) => handleFieldChange(field.field_name, e.target.value)}
          />
        );

      case 'textarea':
        return (
          <textarea
            id={fieldId}
            className={inputClasses}
            rows={3}
            value={formData[field.field_name] || ''}
            onChange={(e) => handleFieldChange(field.field_name, e.target.value)}
          />
        );

      case 'select':
        return (
          <select
            id={fieldId}
            className={inputClasses}
            value={formData[field.field_name] || ''}
            onChange={(e) => handleFieldChange(field.field_name, e.target.value)}
          >
            <option value="">Select...</option>
            {field.options?.map((option: any) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        );

      case 'checkbox':
        return (
          <div className="flex items-center">
            <input
              id={fieldId}
              type="checkbox"
              className="mr-2"
              checked={formData[field.field_name] || false}
              onChange={(e) => handleFieldChange(field.field_name, e.target.checked)}
            />
            <label htmlFor={fieldId} className="text-brand-primary">
              {field.field_label}
            </label>
          </div>
        );

      case 'radio':
        return (
          <div className="space-y-2">
            {field.options?.map((option: any) => (
              <div key={option} className="flex items-center">
                <input
                  id={`${fieldId}-${option}`}
                  type="radio"
                  name={field.field_name}
                  value={option}
                  className="mr-2"
                  checked={formData[field.field_name] === option}
                  onChange={(e) => handleFieldChange(field.field_name, e.target.value)}
                />
                <label htmlFor={`${fieldId}-${option}`} className="text-brand-primary">
                  {option}
                </label>
              </div>
            ))}
          </div>
        );

      default:
        return null;
    }
  };

  // Group fields by section
  const fieldsBySection = formFields.reduce((acc, field) => {
    const section = field.section || 'general';
    if (!acc[section]) {
      acc[section] = [];
    }
    acc[section].push(field);
    return acc;
  }, {} as Record<string, FormField[]>);

  const getSectionLabel = (section: string) => {
    return section.split('_').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join(' ');
  };

  if (loading) {
    return (
      <div className="text-center py-12">
        <div className="text-brand-primary">Loading registration form...</div>
      </div>
    );
  }

  if (!program) {
    return (
      <div className="text-center py-12">
        <div className="text-red-600">Registration form not found.</div>
      </div>
    );
  }

  if (submitted) {
    return (
      <div className={embedded ? '' : 'min-h-screen bg-gray-50 py-8'}>
        <div className={embedded ? '' : 'max-w-2xl mx-auto px-4'}>
          <div className="bg-white border border-brand-secondary rounded-md p-8 text-center">
            <div className="w-16 h-16 bg-brand-light rounded-full flex items-center justify-center mx-auto mb-6">
              <svg className="w-8 h-8 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <h2 className="text-2xl font-bold text-brand-primary mb-4">Registration Submitted!</h2>
            <p className="text-gray-600 mb-4">
              Thank you for registering for <span className="font-semibold">{program.name}</span>.
            </p>
            <div className="bg-gray-50 rounded-md p-4 mb-4">
              <p className="text-gray-700 text-sm">
                Your registration is now <span className="font-semibold text-amber-600">pending review</span>.
                Once approved, you will receive an email with payment instructions and a link to complete your registration.
              </p>
            </div>
            <p className="text-gray-500 text-sm">
              If you have any questions, please contact the program administrator.
            </p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className={embedded ? '' : 'min-h-screen bg-gray-50 py-8'}>
      <div className={embedded ? '' : 'max-w-2xl mx-auto px-4'}>
        <div className="bg-white border border-brand-secondary rounded-md">
          {/* Header */}
          <div className="bg-brand-primary text-white p-6">
            <div className="flex justify-between items-start">
              <div>
                <h1 className="text-2xl font-bold uppercase">{program.name}</h1>
                {program.description && (
                  <p className="mt-2 text-white/90">{program.description}</p>
                )}
                {(program.start_date || program.end_date) && (
                  <div className="mt-4 text-sm">
                    {program.start_date && (
                      <span>
                        {new Date(program.start_date).toLocaleDateString()}
                        {program.end_date && ' - '}
                      </span>
                    )}
                    {program.end_date && (
                      <span>{new Date(program.end_date).toLocaleDateString()}</span>
                    )}
                  </div>
                )}
              </div>
              {registrationFee > 0 && (
                <div className="text-right">
                  <div className="text-sm text-white/80">Registration Fee</div>
                  <div className="text-3xl font-bold">${registrationFee.toFixed(2)}</div>
                </div>
              )}
            </div>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="p-6">
            {Object.entries(fieldsBySection).map(([section, fields]) => (
              <div key={section} className="mb-8">
                <h3 className="text-lg font-semibold text-brand-primary uppercase mb-4 border-b border-brand-secondary pb-2">
                  {getSectionLabel(section)}
                </h3>
                <div className="space-y-4">
                  {fields.map(field => (
                    <div key={field.field_name}>
                      {field.field_type !== 'checkbox' && (
                        <label htmlFor={`field-${field.field_name}`} className="block text-brand-primary text-sm font-medium mb-2">
                          {field.field_label}
                          {field.required && <span className="text-red-500 ml-1">*</span>}
                        </label>
                      )}
                      {renderField(field)}
                      {errors[field.field_name] && (
                        <div className="text-red-500 text-sm mt-1">{errors[field.field_name]}</div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}

            {/* COPPA Consent Section */}
            <div className="border-t border-gray-200 pt-6 mt-6">
              <h3 className="text-lg font-semibold text-gray-800 mb-4">Parental Consent (Required)</h3>

              <div className="space-y-4">
                <label className="flex items-start gap-3 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={consentDataCollection}
                    onChange={(e) => setConsentDataCollection(e.target.checked)}
                    className="mt-1 h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
                  />
                  <span className="text-sm text-gray-700">
                    As the parent/legal guardian, I consent to the collection and storage of my child's personal information as described in the{' '}
                    <a href="/privacy-policy" target="_blank" rel="noopener noreferrer" className="text-brand-primary underline hover:text-brand-primary-hover">
                      Privacy Policy
                    </a>.
                  </span>
                </label>

                <label className="flex items-start gap-3 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={consentMedicalData}
                    onChange={(e) => setConsentMedicalData(e.target.checked)}
                    className="mt-1 h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
                  />
                  <span className="text-sm text-gray-700">
                    I consent to the collection and encrypted storage of my child's medical information, accessible only to authorized staff for safety purposes. Examples of this data may include but is not limited to: allergies, medications and medical conditions.
                  </span>
                </label>
              </div>

              {(!consentDataCollection || !consentMedicalData) && (
                <p className="mt-2 text-sm text-red-600">Both consent checkboxes must be checked to proceed.</p>
              )}
            </div>

            <div className="mt-8 flex justify-between items-center">
              {registrationFee > 0 && (
                <div className="text-brand-primary">
                  <span className="text-sm">Total Due: </span>
                  <span className="text-xl font-bold">${registrationFee.toFixed(2)}</span>
                </div>
              )}
              <button
                type="submit"
                disabled={submitting}
                className="bg-brand-primary text-white px-8 py-3 rounded-md hover:bg-brand-primary uppercase font-semibold disabled:opacity-50"
              >
                {submitting ? 'Submitting...' : 'Submit Registration'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
};

export default PublicRegistrationForm;
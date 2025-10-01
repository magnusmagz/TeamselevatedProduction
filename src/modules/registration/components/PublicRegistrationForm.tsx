import React, { useState, useEffect } from 'react';
import { Program, FormField } from '../types';

interface PublicRegistrationFormProps {
  embedCode: string;
  embedded?: boolean;
}

const PublicRegistrationForm: React.FC<PublicRegistrationFormProps> = ({ embedCode, embedded = false }) => {
  const [program, setProgram] = useState<Program | null>(null);
  const [formFields, setFormFields] = useState<FormField[]>([]);
  const [formData, setFormData] = useState<Record<string, any>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  useEffect(() => {
    fetchProgramDetails();
  }, [embedCode]);

  const fetchProgramDetails = async () => {
    try {
      const response = await fetch(
        `http://localhost:8889/registration/programs-api.php?path=by-embed&code=${embedCode}`
      );
      const data = await response.json();

      if (data && data.id) {
        setProgram(data);
        setFormFields(data.form_fields || []);

        // Initialize form data with empty values
        const initialData: Record<string, any> = {};
        data.form_fields?.forEach((field: FormField) => {
          initialData[field.field_name] = field.field_type === 'checkbox' ? false : '';
        });
        setFormData(initialData);
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

    setSubmitting(true);

    try {
      const response = await fetch('http://localhost:8889/registration/registrations-api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          program_id: program?.id,
          form_data: formData
        })
      });

      if (response.ok) {
        setSubmitted(true);
      } else {
        alert('An error occurred. Please try again.');
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

    const inputClasses = `w-full bg-white text-forest-800 border-2 ${
      hasError ? 'border-red-500' : 'border-forest-800'
    } px-4 py-2 focus:outline-none focus:border-forest-600`;

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
            <label htmlFor={fieldId} className="text-forest-800">
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
                <label htmlFor={`${fieldId}-${option}`} className="text-forest-800">
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
        <div className="text-forest-800">Loading registration form...</div>
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
      <div className="bg-white border-2 border-forest-800 p-8 text-center">
        <h2 className="text-2xl font-bold text-forest-800 mb-4">Registration Submitted!</h2>
        <p className="text-gray-600">
          Thank you for registering for {program.name}.
          You will receive a confirmation email shortly.
        </p>
      </div>
    );
  }

  return (
    <div className={embedded ? '' : 'min-h-screen bg-gray-50 py-8'}>
      <div className={embedded ? '' : 'max-w-2xl mx-auto px-4'}>
        <div className="bg-white border-2 border-forest-800">
          {/* Header */}
          <div className="bg-forest-800 text-white p-6">
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

          {/* Form */}
          <form onSubmit={handleSubmit} className="p-6">
            {Object.entries(fieldsBySection).map(([section, fields]) => (
              <div key={section} className="mb-8">
                <h3 className="text-lg font-semibold text-forest-800 uppercase mb-4 border-b-2 border-forest-800 pb-2">
                  {getSectionLabel(section)}
                </h3>
                <div className="space-y-4">
                  {fields.map(field => (
                    <div key={field.field_name}>
                      {field.field_type !== 'checkbox' && (
                        <label htmlFor={`field-${field.field_name}`} className="block text-forest-800 text-sm font-medium mb-2">
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

            <div className="mt-8 flex justify-end">
              <button
                type="submit"
                disabled={submitting}
                className="bg-forest-800 text-white px-8 py-3 hover:bg-forest-700 uppercase font-semibold disabled:opacity-50"
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
import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { Program, FormField, TryoutSession } from '../types';

interface PaymentItem {
  id: number;
  name: string;
  base_price: string;
  item_type: string;
}

const PublicTryoutRegistration: React.FC = () => {
  const { embedCode } = useParams<{ embedCode: string }>();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

  const [program, setProgram] = useState<Program | null>(null);
  const [sessions, setSessions] = useState<TryoutSession[]>([]);
  const [formFields, setFormFields] = useState<FormField[]>([]);
  const [formData, setFormData] = useState<Record<string, any>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [registrationFee, setRegistrationFee] = useState<number>(0);
  const [linkCopied, setLinkCopied] = useState(false);

  useEffect(() => {
    if (embedCode) {
      fetchProgramDetails();
    }
  }, [embedCode]);

  const fetchProgramDetails = async () => {
    try {
      // Fetch program details
      const response = await fetch(
        `${API_URL}/registration/programs-api.php?path=by-embed&code=${embedCode}`
      );
      const data = await response.json();

      if (data && data.id) {
        setProgram(data);

        // Parse form fields
        const parsedFields = (data.form_fields || []).map((field: any) => ({
          ...field,
          options: field.options && typeof field.options === 'string'
            ? JSON.parse(field.options)
            : field.options
        }));
        setFormFields(parsedFields);

        // Initialize form data
        const initialData: Record<string, any> = {};
        parsedFields.forEach((field: FormField) => {
          initialData[field.field_name] = field.field_type === 'checkbox' ? false : '';
        });
        setFormData(initialData);

        // Fetch tryout sessions
        const sessionsRes = await fetch(
          `${API_URL}/registration/tryouts-api.php?path=sessions&program_id=${data.id}`
        );
        const sessionsData = await sessionsRes.json();
        if (Array.isArray(sessionsData)) {
          setSessions(sessionsData);
        }

        // Fetch payment items
        try {
          const paymentRes = await fetch(`${API_URL}/api/payment-items.php?program_id=${data.id}`);
          const paymentData = await paymentRes.json();
          if (paymentData.success && paymentData.items) {
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

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      weekday: 'short',
      month: 'short',
      day: 'numeric'
    });
  };

  const formatTime = (timeString: string | undefined) => {
    if (!timeString) return '';
    const [hours, minutes] = timeString.split(':');
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
  };

  const handleCopyLink = () => {
    navigator.clipboard.writeText(window.location.href);
    setLinkCopied(true);
    setTimeout(() => setLinkCopied(false), 2000);
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
    if (!validateForm()) return;

    setSubmitting(true);

    try {
      const response = await fetch(`${API_URL}/registration/registrations-api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          program_id: program?.id,
          form_data: formData
        })
      });

      const result = await response.json();
      if (response.ok && result.success) {
        setSubmitted(true);
      } else {
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
    setFormData(prev => ({ ...prev, [fieldName]: value }));
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
    const inputClasses = `w-full bg-white text-gray-900 border rounded-md ${
      hasError ? 'border-red-500' : 'border-gray-300'
    } px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent`;

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
              <option key={option} value={option}>{option}</option>
            ))}
          </select>
        );
      case 'checkbox':
        return (
          <div className="flex items-center">
            <input
              id={fieldId}
              type="checkbox"
              className="mr-2 h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
              checked={formData[field.field_name] || false}
              onChange={(e) => handleFieldChange(field.field_name, e.target.checked)}
            />
            <label htmlFor={fieldId} className="text-gray-700">{field.field_label}</label>
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
                  className="mr-2 h-4 w-4 border-gray-300 text-brand-primary focus:ring-brand-primary"
                  checked={formData[field.field_name] === option}
                  onChange={(e) => handleFieldChange(field.field_name, e.target.value)}
                />
                <label htmlFor={`${fieldId}-${option}`} className="text-gray-700">{option}</label>
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
    if (!acc[section]) acc[section] = [];
    acc[section].push(field);
    return acc;
  }, {} as Record<string, FormField[]>);

  const getSectionLabel = (section: string) => {
    return section.split('_').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join(' ');
  };

  // Group sessions: regular vs rain dates
  const regularSessions = sessions.filter(s => !s.is_rain_date);
  const rainDateSessions = sessions.filter(s => s.is_rain_date);

  // Loading state
  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-primary mx-auto mb-4"></div>
          <p className="text-gray-600">Loading tryout details...</p>
        </div>
      </div>
    );
  }

  // Error state
  if (!program) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="bg-white rounded-lg shadow-lg p-8 max-w-md text-center">
          <div className="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg className="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
          <h1 className="text-xl font-bold text-brand-primary mb-2">Tryout Not Found</h1>
          <p className="text-gray-600">The tryout registration you are looking for does not exist or has ended.</p>
        </div>
      </div>
    );
  }

  // Success state
  if (submitted) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
        <div className="bg-white rounded-lg shadow-lg p-8 max-w-lg text-center">
          <div className="w-16 h-16 bg-brand-light rounded-full flex items-center justify-center mx-auto mb-6">
            <svg className="w-8 h-8 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h2 className="text-2xl font-bold text-brand-primary mb-4">Registration Submitted!</h2>
          <p className="text-gray-600 mb-4">
            Thank you for registering for <span className="font-semibold">{program.name}</span>.
          </p>
          <div className="bg-gray-50 rounded-lg p-4 mb-4 text-left">
            <p className="text-gray-700 text-sm">
              Your registration is now <span className="font-semibold text-amber-600">pending review</span>.
              We'll send you an email with next steps once your registration is confirmed.
            </p>
          </div>
          {sessions.length > 0 && (
            <div className="bg-brand-light/50 rounded-lg p-4 text-left">
              <p className="text-sm text-brand-primary-dark font-medium mb-2">What to expect:</p>
              <ul className="text-sm text-brand-primary-dark space-y-1">
                <li>• Arrive 15 minutes before your scheduled session</li>
                <li>• Wear appropriate athletic clothing and cleats</li>
                <li>• Bring water and any required equipment</li>
              </ul>
            </div>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Hero Section */}
      <div className="bg-brand-primary text-white">
        <div className="container mx-auto px-4 py-12">
          <div className="max-w-4xl">
            <p className="text-white/70 text-sm uppercase tracking-wide mb-2">Tryout Registration</p>
            <h1 className="text-3xl md:text-4xl font-bold mb-4">{program.name}</h1>
            {(program.start_date || program.end_date) && (
              <div className="flex items-center gap-2 text-white/90">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span>
                  {program.start_date && formatDate(program.start_date)}
                  {program.start_date && program.end_date && ' - '}
                  {program.end_date && formatDate(program.end_date)}
                </span>
              </div>
            )}
            {program.min_age && program.max_age && (
              <div className="flex items-center gap-2 text-white/90 mt-2">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <span>Ages {program.min_age} - {program.max_age}</span>
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="container mx-auto px-4 py-8">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Main Content */}
          <div className="lg:col-span-2 space-y-6">
            {/* Description */}
            {program.description && (
              <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                <h2 className="text-lg font-semibold text-brand-primary mb-4">About This Tryout</h2>
                <p className="text-gray-600 whitespace-pre-wrap">{program.description}</p>
              </div>
            )}

            {/* Tryout Schedule */}
            {sessions.length > 0 && (
              <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                <h2 className="text-lg font-semibold text-brand-primary mb-4">
                  Tryout Schedule
                  <span className="text-sm font-normal text-gray-500 ml-2">({sessions.length} session{sessions.length !== 1 ? 's' : ''})</span>
                </h2>

                {/* Regular Sessions */}
                {regularSessions.length > 0 && (
                  <div className="space-y-3">
                    {regularSessions.map((session) => (
                      <div
                        key={session.id}
                        className="flex items-start gap-4 p-4 bg-gray-50 rounded-lg"
                      >
                        <div className="flex-shrink-0 w-16 text-center">
                          <div className="text-2xl font-bold text-brand-primary">
                            {new Date(session.session_date).getDate()}
                          </div>
                          <div className="text-xs text-gray-500 uppercase">
                            {new Date(session.session_date).toLocaleDateString('en-US', { month: 'short' })}
                          </div>
                        </div>
                        <div className="flex-1 min-w-0">
                          <div className="font-medium text-gray-900">
                            {session.name || `${session.age_group || ''} ${session.gender || ''}`.trim() || 'Tryout Session'}
                          </div>
                          <div className="text-sm text-gray-600 mt-1">
                            {session.start_time && (
                              <span>
                                {formatTime(session.start_time)}
                                {session.end_time && ` - ${formatTime(session.end_time)}`}
                              </span>
                            )}
                          </div>
                          {session.location && (
                            <div className="text-sm text-gray-500 mt-1 flex items-center gap-1">
                              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                              </svg>
                              {session.location}
                            </div>
                          )}
                        </div>
                        {(session.age_group || session.gender) && (
                          <div className="flex-shrink-0">
                            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-brand-primary/10 text-brand-primary">
                              {session.age_group} {session.gender}
                            </span>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                )}

                {/* Rain Dates */}
                {rainDateSessions.length > 0 && (
                  <div className="mt-6">
                    <h3 className="text-sm font-semibold text-yellow-700 uppercase tracking-wide mb-3 flex items-center gap-2">
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                      </svg>
                      Rain/Backup Dates
                    </h3>
                    <div className="space-y-2">
                      {rainDateSessions.map((session) => (
                        <div
                          key={session.id}
                          className="flex items-center gap-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg"
                        >
                          <div className="flex-shrink-0 w-16 text-center">
                            <div className="text-lg font-bold text-yellow-700">
                              {new Date(session.session_date).getDate()}
                            </div>
                            <div className="text-xs text-yellow-600 uppercase">
                              {new Date(session.session_date).toLocaleDateString('en-US', { month: 'short' })}
                            </div>
                          </div>
                          <div className="flex-1 text-sm text-yellow-800">
                            {session.name || 'Backup Date'} - Same times as scheduled sessions
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* What to Bring */}
            {(() => {
              const items = (program as any).what_to_bring && Array.isArray((program as any).what_to_bring) && (program as any).what_to_bring.length > 0
                ? (program as any).what_to_bring
                : [
                    'Athletic clothing appropriate for the weather',
                    'Cleats and shin guards',
                    'Water bottle',
                    'Soccer ball (optional - we provide balls)'
                  ];
              return (
                <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                  <h2 className="text-lg font-semibold text-brand-primary mb-4">What to Bring</h2>
                  <ul className="space-y-2 text-gray-600">
                    {items.map((item: string, index: number) => (
                      <li key={index} className="flex items-start gap-2">
                        <svg className="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                        {item}
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })()}

            {/* Mobile: Show form here */}
            <div className="lg:hidden">
              {renderRegistrationForm()}
            </div>
          </div>

          {/* Sidebar */}
          <div className="space-y-6">
            {/* Registration Fee Card */}
            {registrationFee > 0 && (
              <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
                <div className="text-center">
                  <p className="text-sm text-gray-500 mb-1">Registration Fee</p>
                  <p className="text-3xl font-bold text-brand-primary">${registrationFee.toFixed(2)}</p>
                </div>
              </div>
            )}

            {/* Share */}
            <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
              <h3 className="font-semibold text-brand-primary mb-3">Share This Tryout</h3>
              <div className="flex gap-2">
                <button
                  onClick={handleCopyLink}
                  className="flex-1 flex items-center justify-center gap-2 py-2 px-4 bg-gray-100 hover:bg-gray-200 rounded-lg text-gray-700 text-sm font-medium transition-colors"
                >
                  {linkCopied ? (
                    <>
                      <svg className="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                      </svg>
                      Copied!
                    </>
                  ) : (
                    <>
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                      </svg>
                      Copy Link
                    </>
                  )}
                </button>
              </div>
            </div>

            {/* Desktop: Registration Form */}
            <div className="hidden lg:block">
              {renderRegistrationForm()}
            </div>
          </div>
        </div>
      </div>
    </div>
  );

  function renderRegistrationForm() {
    return (
      <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
        <div className="bg-brand-primary text-white p-4">
          <h3 className="font-semibold text-lg">Register Now</h3>
          {program?.capacity && (
            <p className="text-white/70 text-sm">Limited spots available</p>
          )}
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          {Object.entries(fieldsBySection).map(([section, fields]) => (
            <div key={section} className="mb-6">
              <h4 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
                {getSectionLabel(section)}
              </h4>
              <div className="space-y-4">
                {fields.map(field => (
                  <div key={field.field_name}>
                    {field.field_type !== 'checkbox' && (
                      <label htmlFor={`field-${field.field_name}`} className="block text-gray-700 text-sm font-medium mb-1">
                        {field.field_label}
                        {field.required && <span className="text-red-500 ml-1">*</span>}
                      </label>
                    )}
                    {renderField(field)}
                    {errors[field.field_name] && (
                      <p className="text-red-500 text-sm mt-1">{errors[field.field_name]}</p>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ))}

          <button
            type="submit"
            disabled={submitting}
            className="w-full bg-brand-primary text-white py-3 px-4 rounded-lg font-semibold hover:bg-brand-primary/90 transition-colors disabled:opacity-50"
          >
            {submitting ? 'Submitting...' : 'Submit Registration'}
          </button>

          {registrationFee > 0 && (
            <p className="text-center text-sm text-gray-500 mt-3">
              Payment of ${registrationFee.toFixed(2)} due after approval
            </p>
          )}
        </form>
      </div>
    );
  }
};

export default PublicTryoutRegistration;

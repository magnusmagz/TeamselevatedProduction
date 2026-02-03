import React, { useState, useEffect } from 'react';
import { EvaluationCriterion, TryoutSession, Program } from '../types';
import EvaluationCriteriaBuilder from './EvaluationCriteriaBuilder';

interface TryoutCreationWizardProps {
  clubId: number;
  existingProgram?: Program | null;
  onComplete: (tryoutId: number) => void;
  onCancel: () => void;
}

interface TryoutFormData {
  name: string;
  description: string;
  start_date: string;
  end_date: string;
  registration_opens: string;
  registration_closes: string;
  min_age: string;
  max_age: string;
  capacity: string;
}

const defaultCriteria: EvaluationCriterion[] = [
  { name: 'Technical Skills', description: 'Ball control, passing, shooting accuracy', max_score: 5, weight: 1.0, display_order: 0, tempId: 'default-1' },
  { name: 'Tactical Awareness', description: 'Game understanding, positioning, decision making', max_score: 5, weight: 1.0, display_order: 1, tempId: 'default-2' },
  { name: 'Physical Fitness', description: 'Speed, endurance, strength, agility', max_score: 5, weight: 1.0, display_order: 2, tempId: 'default-3' },
  { name: 'Attitude/Coachability', description: 'Effort, listening, teamwork, positive attitude', max_score: 5, weight: 1.0, display_order: 3, tempId: 'default-4' }
];

const TryoutCreationWizard: React.FC<TryoutCreationWizardProps> = ({
  clubId,
  existingProgram,
  onComplete,
  onCancel
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [step, setStep] = useState(1);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(!!existingProgram);
  const [error, setError] = useState<string | null>(null);
  const isEditing = !!existingProgram;

  // Step 1: Basic Info
  const [formData, setFormData] = useState<TryoutFormData>({
    name: existingProgram?.name || '',
    description: existingProgram?.description || '',
    start_date: existingProgram?.start_date || '',
    end_date: existingProgram?.end_date || '',
    registration_opens: existingProgram?.registration_opens || '',
    registration_closes: existingProgram?.registration_closes || '',
    min_age: existingProgram?.min_age?.toString() || '',
    max_age: existingProgram?.max_age?.toString() || '',
    capacity: existingProgram?.capacity?.toString() || ''
  });

  // Step 2: Sessions
  const [sessions, setSessions] = useState<TryoutSession[]>([]);

  // Step 3: Evaluation Criteria
  const [criteria, setCriteria] = useState<EvaluationCriterion[]>(defaultCriteria);

  // Locations for dropdown (venues and fields)
  const [locations, setLocations] = useState<{ id: number; name: string; venue_id?: number; type: 'venue' | 'field' }[]>([]);

  // Load locations (venues and fields) on mount
  useEffect(() => {
    loadLocations();
  }, []);

  const loadLocations = async () => {
    try {
      // Fetch both venues and fields
      const [venuesRes, fieldsRes] = await Promise.all([
        fetch(`${API_URL}/api/venues.php`),
        fetch(`${API_URL}/api/fields.php`)
      ]);

      const venuesData = await venuesRes.json();
      const fieldsData = await fieldsRes.json();

      const allLocations: { id: number; name: string; venue_id?: number; type: 'venue' | 'field' }[] = [];

      // Add venues
      if (Array.isArray(venuesData)) {
        venuesData.forEach((venue: any) => {
          allLocations.push({
            id: venue.id,
            name: venue.name,
            type: 'venue'
          });
        });
      }

      // Add fields (they already have "Venue - Field" format from API)
      if (Array.isArray(fieldsData)) {
        fieldsData.forEach((field: any) => {
          allLocations.push({
            id: field.id,
            name: field.name,
            venue_id: field.venue_id,
            type: 'field'
          });
        });
      }

      setLocations(allLocations);
    } catch (err) {
      console.error('Error loading locations:', err);
    }
  };

  // Load existing sessions and criteria when editing
  useEffect(() => {
    if (existingProgram?.id) {
      loadExistingData(existingProgram.id);
    }
  }, [existingProgram?.id]);

  const loadExistingData = async (programId: number) => {
    setLoading(true);
    try {
      const [sessionsRes, criteriaRes] = await Promise.all([
        fetch(`${API_URL}/registration/tryouts-api.php?path=sessions&program_id=${programId}`),
        fetch(`${API_URL}/registration/tryouts-api.php?path=criteria&program_id=${programId}`)
      ]);

      const sessionsData = await sessionsRes.json();
      const criteriaData = await criteriaRes.json();

      if (Array.isArray(sessionsData) && sessionsData.length > 0) {
        setSessions(sessionsData);
      }

      if (Array.isArray(criteriaData) && criteriaData.length > 0) {
        setCriteria(criteriaData);
      }
    } catch (err) {
      console.error('Error loading existing tryout data:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleNext = () => {
    if (step === 1) {
      if (!formData.name.trim()) {
        setError('Please enter a tryout name');
        return;
      }
      setError(null);
    }
    setStep(step + 1);
  };

  const handleBack = () => {
    setStep(step - 1);
  };

  const handleAddSession = () => {
    setSessions([
      ...sessions,
      {
        program_id: 0,
        session_date: '',
        start_time: '',
        end_time: '',
        location: ''
      }
    ]);
  };

  const handleUpdateSession = (index: number, field: string, value: string | number | null) => {
    const newSessions = [...sessions];
    (newSessions[index] as any)[field] = value;
    setSessions(newSessions);
  };

  const handleRemoveSession = (index: number) => {
    setSessions(sessions.filter((_, i) => i !== index));
  };

  const handleSubmit = async () => {
    setSaving(true);
    setError(null);

    try {
      const endpoint = isEditing
        ? `${API_URL}/registration/tryouts-api.php?path=update&id=${existingProgram?.id}`
        : `${API_URL}/registration/tryouts-api.php?path=create`;

      const response = await fetch(endpoint, {
        method: isEditing ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          club_id: clubId,
          name: formData.name,
          description: formData.description || null,
          start_date: formData.start_date || null,
          end_date: formData.end_date || null,
          registration_opens: formData.registration_opens || null,
          registration_closes: formData.registration_closes || null,
          min_age: formData.min_age ? parseInt(formData.min_age) : null,
          max_age: formData.max_age ? parseInt(formData.max_age) : null,
          capacity: formData.capacity ? parseInt(formData.capacity) : null,
          status: 'published',
          sessions: sessions.filter(s => s.session_date),
          criteria: criteria.map(({ tempId, ...c }) => c)
        })
      });

      const data = await response.json();

      if (response.ok && data.success) {
        onComplete(data.id || existingProgram?.id || 0);
      } else {
        setError(data.error || `Failed to ${isEditing ? 'update' : 'create'} tryout`);
      }
    } catch (err) {
      console.error(`Error ${isEditing ? 'updating' : 'creating'} tryout:`, err);
      setError(`An error occurred while ${isEditing ? 'updating' : 'creating'} the tryout`);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md max-w-3xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <h2 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
            {isEditing ? 'Edit Tryout' : 'Create Tryout'}
          </h2>
          <button
            onClick={onCancel}
            className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
          >
            &times;
          </button>
        </div>

        {/* Step Indicator */}
        <div className="px-6 py-4 border-b border-brand-secondary">
          <div className="flex items-center justify-between">
            {['Basic Info', 'Sessions', 'Evaluation Criteria'].map((label, index) => (
              <div key={index} className="flex items-center">
                <div
                  className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold ${
                    step > index + 1
                      ? 'bg-green-600 text-white'
                      : step === index + 1
                      ? 'bg-brand-primary text-white'
                      : 'bg-gray-200 text-gray-500'
                  }`}
                >
                  {step > index + 1 ? '✓' : index + 1}
                </div>
                <span
                  className={`ml-2 text-sm font-medium ${
                    step === index + 1 ? 'text-brand-primary' : 'text-gray-500'
                  }`}
                >
                  {label}
                </span>
                {index < 2 && (
                  <div className="w-16 h-px bg-gray-300 mx-4" />
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {loading ? (
            <div className="flex items-center justify-center h-full">
              <div className="text-brand-primary">Loading tryout data...</div>
            </div>
          ) : (
          <>
          {error && (
            <div className="mb-4 p-3 bg-red-100 border border-red-400 text-red-700 rounded-md">
              {error}
            </div>
          )}

          {/* Step 1: Basic Info */}
          {step === 1 && (
            <div className="space-y-4">
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Tryout Name <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  className="w-full border border-brand-secondary rounded-md px-4 py-2"
                  placeholder="e.g., 2026 Spring Tryouts"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                />
              </div>

              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Description
                </label>
                <textarea
                  className="w-full border border-brand-secondary rounded-md px-4 py-2"
                  rows={3}
                  placeholder="Details about the tryout..."
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Age Range (Min)
                  </label>
                  <input
                    type="number"
                    className="w-full border border-brand-secondary rounded-md px-4 py-2"
                    placeholder="e.g., 8"
                    value={formData.min_age}
                    onChange={(e) => setFormData({ ...formData, min_age: e.target.value })}
                  />
                </div>
                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Age Range (Max)
                  </label>
                  <input
                    type="number"
                    className="w-full border border-brand-secondary rounded-md px-4 py-2"
                    placeholder="e.g., 14"
                    value={formData.max_age}
                    onChange={(e) => setFormData({ ...formData, max_age: e.target.value })}
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Registration Opens
                  </label>
                  <input
                    type="date"
                    className="w-full border border-brand-secondary rounded-md px-4 py-2"
                    value={formData.registration_opens}
                    onChange={(e) => setFormData({ ...formData, registration_opens: e.target.value })}
                  />
                </div>
                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Registration Closes
                  </label>
                  <input
                    type="date"
                    className="w-full border border-brand-secondary rounded-md px-4 py-2"
                    value={formData.registration_closes}
                    onChange={(e) => setFormData({ ...formData, registration_closes: e.target.value })}
                  />
                </div>
              </div>

              <div>
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Capacity
                </label>
                <input
                  type="number"
                  className="w-full border border-brand-secondary rounded-md px-4 py-2"
                  placeholder="Maximum participants"
                  value={formData.capacity}
                  onChange={(e) => setFormData({ ...formData, capacity: e.target.value })}
                />
              </div>
            </div>
          )}

          {/* Step 2: Sessions */}
          {step === 2 && (
            <div className="space-y-4">
              <div className="flex justify-between items-center">
                <div>
                  <h3 className="text-brand-primary font-semibold uppercase">Tryout Sessions</h3>
                  <p className="text-sm text-gray-600">Add dates and times for your tryout sessions</p>
                </div>
                <button
                  type="button"
                  onClick={handleAddSession}
                  className="text-brand-primary hover:text-brand-primary-hover text-sm font-semibold uppercase"
                >
                  + Add Session
                </button>
              </div>

              {sessions.length === 0 && (
                <div className="text-center py-8 border-2 border-dashed border-gray-300 rounded-md text-gray-500">
                  No sessions added yet. Click "Add Session" to schedule tryout dates.
                </div>
              )}

              {sessions.map((session, index) => (
                <div
                  key={index}
                  className="border border-brand-secondary rounded-md p-4 space-y-3"
                >
                  <div className="flex justify-between items-center">
                    <span className="font-medium text-brand-primary">Session {index + 1}</span>
                    <button
                      type="button"
                      onClick={() => handleRemoveSession(index)}
                      className="text-red-600 hover:text-red-500 text-xs font-semibold uppercase"
                    >
                      Remove
                    </button>
                  </div>

                  <div className="grid grid-cols-3 gap-4">
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-1">
                        Date <span className="text-red-500">*</span>
                      </label>
                      <input
                        type="date"
                        className="w-full border border-brand-secondary rounded-md px-3 py-2"
                        value={session.session_date}
                        onChange={(e) => handleUpdateSession(index, 'session_date', e.target.value)}
                      />
                    </div>
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-1">
                        Start Time
                      </label>
                      <input
                        type="time"
                        className="w-full border border-brand-secondary rounded-md px-3 py-2"
                        value={session.start_time || ''}
                        onChange={(e) => handleUpdateSession(index, 'start_time', e.target.value)}
                      />
                    </div>
                    <div>
                      <label className="block text-brand-primary text-sm font-medium mb-1">
                        End Time
                      </label>
                      <input
                        type="time"
                        className="w-full border border-brand-secondary rounded-md px-3 py-2"
                        value={session.end_time || ''}
                        onChange={(e) => handleUpdateSession(index, 'end_time', e.target.value)}
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-1">
                      Location
                    </label>
                    <select
                      className="w-full border border-brand-secondary rounded-md px-3 py-2"
                      value={session.venue_id ? `${session.venue_id}|${session.location || ''}` : ''}
                      onChange={(e) => {
                        const [venueId, locationName] = e.target.value.split('|');
                        handleUpdateSession(index, 'venue_id', venueId ? parseInt(venueId) : null);
                        handleUpdateSession(index, 'location', locationName || '');
                      }}
                    >
                      <option value="">Select a location...</option>
                      <optgroup label="Venues">
                        {locations.filter(l => l.type === 'venue').map(venue => (
                          <option key={`venue-${venue.id}`} value={`${venue.id}|${venue.name}`}>
                            {venue.name}
                          </option>
                        ))}
                      </optgroup>
                      <optgroup label="Fields">
                        {locations.filter(l => l.type === 'field').map(field => (
                          <option key={`field-${field.id}`} value={`${field.venue_id}|${field.name}`}>
                            {field.name}
                          </option>
                        ))}
                      </optgroup>
                    </select>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Step 3: Evaluation Criteria */}
          {step === 3 && (
            <EvaluationCriteriaBuilder
              criteria={criteria}
              onChange={setCriteria}
            />
          )}
          </>
          )}
        </div>

        {/* Footer */}
        <div className="border-t border-brand-secondary px-6 py-4 flex justify-between">
          <button
            type="button"
            onClick={step === 1 ? onCancel : handleBack}
            className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100 font-semibold uppercase"
          >
            {step === 1 ? 'Cancel' : 'Back'}
          </button>

          {step < 3 ? (
            <button
              type="button"
              onClick={handleNext}
              className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary-hover font-semibold uppercase"
            >
              Next
            </button>
          ) : (
            <button
              type="button"
              onClick={handleSubmit}
              disabled={saving}
              className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary-hover font-semibold uppercase disabled:opacity-50"
            >
              {saving ? (isEditing ? 'Saving...' : 'Creating...') : (isEditing ? 'Save Changes' : 'Create Tryout')}
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

export default TryoutCreationWizard;

import React, { useState, useEffect } from 'react';
import { Program, FormField, FieldType, Venue } from '../types';
import FormFieldBuilder from './FormFieldBuilder';
import VenuePicker from './VenuePicker';
import ProgramScheduleBuilder from './ProgramScheduleBuilder';

interface ProgramFormBuilderProps {
  program: Program | null;
  onClose: () => void;
}

const ProgramFormBuilder: React.FC<ProgramFormBuilderProps> = ({ program, onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [activeTab, setActiveTab] = useState<'details' | 'fields' | 'schedule'>('details');
  const [savedProgramId, setSavedProgramId] = useState<number | undefined>(program?.id);
  const [formData, setFormData] = useState<Program>({
    name: '',
    type: 'camp',
    participant_type: 'athlete',
    description: '',
    status: 'draft',
    start_date: '',
    end_date: '',
    registration_opens: '',
    registration_closes: '',
    min_age: undefined,
    max_age: undefined,
    capacity: undefined,
    registration_fee: undefined
  });

  const [formFields, setFormFields] = useState<FormField[]>([]);
  const [saving, setSaving] = useState(false);
  const [venues, setVenues] = useState<Venue[]>([]);

  useEffect(() => {
    if (program) {
      setFormData(program);
      // TODO: Load existing form fields
    }
  }, [program]);

  // Load the venues catalog once for the facility picker (program + per-session).
  useEffect(() => {
    (async () => {
      try {
        const res = await fetch(`${API_URL}/api/venues.php`, {
          headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
        });
        const data = await res.json();
        setVenues(Array.isArray(data) ? data : []);
      } catch (e) {
        console.error('Error loading venues:', e);
      }
    })();
  }, [API_URL]);

  const handleSave = async () => {
    setSaving(true);
    try {
      const url = savedProgramId
        ? `${API_URL}/registration/programs-api.php?id=${savedProgramId}`
        : `${API_URL}/registration/programs-api.php?path=create`;

      const method = savedProgramId ? 'PUT' : 'POST';

      console.log('Saving program:', formData);

      const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${localStorage.getItem('auth_token')}` },
        body: JSON.stringify({ ...formData })
      });

      const data = await response.json();
      console.log('Save response:', data);

      if (response.ok) {
        if (!savedProgramId && data.id) {
          setSavedProgramId(data.id);
          alert('Program created! You can now configure the registration form.');
        } else {
          alert('Program updated successfully!');
          onClose();
        }
      } else {
        alert(`Error saving program: ${data.error || 'Unknown error'}`);
      }
    } catch (error) {
      console.error('Error saving program:', error);
      alert('An error occurred while saving. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      <div className="absolute inset-0 bg-black bg-opacity-30" onClick={onClose} />
      <div className="bg-white border border-brand-secondary rounded-md absolute inset-y-0 right-0 w-full max-w-[75vw] shadow-xl flex flex-col">
        <div className="border-b border-brand-secondary px-6 py-4">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
              {program ? 'Edit Program' : 'Create New Program'}
            </h3>
            <button
              onClick={onClose}
              className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
            >
              ×
            </button>
          </div>

          {/* Tabs */}
          <div className="flex space-x-8 border-b border-brand-secondary -mb-0.5">
            <button
              onClick={() => setActiveTab('details')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'details'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              Program Details
            </button>
            <button
              onClick={() => setActiveTab('fields')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'fields'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              Registration Form
            </button>
            <button
              onClick={() => setActiveTab('schedule')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'schedule'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              Schedule
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-6">
          {activeTab === 'details' && (
            <div className="space-y-6">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Program Name *
                  </label>
                  <input
                    type="text"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="Summer Soccer Camp 2024"
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Program Type *
                  </label>
                  <select
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.type}
                    onChange={(e) => setFormData({ ...formData, type: e.target.value as any })}
                  >
                    <option value="league">League</option>
                    <option value="camp">Camp</option>
                    <option value="clinic">Clinic</option>
                    <option value="tryout">Tryout</option>
                    <option value="tournament">Tournament</option>
                    <option value="drop_in">Drop In</option>
                  </select>
                </div>

                <div className="col-span-2">
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Who Registers?
                  </label>
                  <select
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.participant_type || 'athlete'}
                    onChange={(e) => setFormData({ ...formData, participant_type: e.target.value as any })}
                  >
                    <option value="athlete">Athlete (with crew)</option>
                    <option value="coach">Coach</option>
                    <option value="adult">Adult</option>
                  </select>
                  <p className="text-xs text-gray-500 mt-1">
                    Sets the default registration form. Coach/Adult collects the registrant's own info
                    (name, email, phone) — no guardian or birthday. Choose this before creating the program.
                  </p>
                </div>

                <div className="col-span-2">
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Description
                  </label>
                  <textarea
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.description || ''}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    rows={3}
                    placeholder="Describe your program..."
                  />
                </div>

                <div className="col-span-2">
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Facility / Location
                  </label>
                  <VenuePicker
                    venues={venues}
                    value={formData.venue_id}
                    onChange={(id) => setFormData({ ...formData, venue_id: id })}
                  />
                  <p className="text-xs text-gray-500 mt-1">
                    The main facility for this program. Individual sessions can override it in the Schedule tab.
                  </p>
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Start Date
                  </label>
                  <input
                    type="date"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.start_date || ''}
                    onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    End Date
                  </label>
                  <input
                    type="date"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.end_date || ''}
                    onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Registration Opens
                  </label>
                  <input
                    type="datetime-local"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.registration_opens || ''}
                    onChange={(e) => setFormData({ ...formData, registration_opens: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Registration Closes
                  </label>
                  <input
                    type="datetime-local"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.registration_closes || ''}
                    onChange={(e) => setFormData({ ...formData, registration_closes: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Min Age
                  </label>
                  <input
                    type="number"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.min_age || ''}
                    onChange={(e) => setFormData({ ...formData, min_age: parseInt(e.target.value) || undefined })}
                    min="0"
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Max Age
                  </label>
                  <input
                    type="number"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.max_age || ''}
                    onChange={(e) => setFormData({ ...formData, max_age: parseInt(e.target.value) || undefined })}
                    min="0"
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Capacity
                  </label>
                  <input
                    type="number"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.capacity || ''}
                    onChange={(e) => setFormData({ ...formData, capacity: parseInt(e.target.value) || undefined })}
                    min="1"
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Registration Fee
                  </label>
                  <div className="relative">
                    <span className="absolute left-3 top-2 text-gray-500">$</span>
                    <input
                      type="number"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md pl-7 pr-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.registration_fee || ''}
                      onChange={(e) => setFormData({ ...formData, registration_fee: parseFloat(e.target.value) || undefined })}
                      min="0"
                      step="0.01"
                      placeholder="0.00"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Status
                  </label>
                  <select
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.status}
                    onChange={(e) => setFormData({ ...formData, status: e.target.value as any })}
                  >
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="closed">Closed</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'fields' && (
            savedProgramId ? (
              <FormFieldBuilder
                programId={savedProgramId}
                onSave={(fields) => {
                  console.log('Fields saved:', fields);
                }}
              />
            ) : (
              <div className="bg-yellow-50 border border-yellow-400 rounded-md p-6 text-center">
                <p className="text-yellow-800 font-medium mb-2">Save Program Details First</p>
                <p className="text-yellow-600 text-sm">
                  Please save the program details in the first tab before configuring the registration form.
                </p>
              </div>
            )
          )}

          {activeTab === 'schedule' && (
            savedProgramId ? (
              <ProgramScheduleBuilder programId={savedProgramId} venues={venues} />
            ) : (
              <div className="bg-yellow-50 border border-yellow-400 rounded-md p-6 text-center">
                <p className="text-yellow-800 font-medium mb-2">Save Program Details First</p>
                <p className="text-yellow-600 text-sm">
                  Please save the program details in the first tab before building the schedule.
                </p>
              </div>
            )
          )}
        </div>

        <div className="border-t border-brand-secondary px-6 py-4 flex justify-end space-x-4">
          <button
            onClick={onClose}
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase"
          >
            Cancel
          </button>
          <button
            onClick={handleSave}
            disabled={saving || !formData.name}
            className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase disabled:opacity-50"
          >
            {saving ? 'Saving...' : (savedProgramId ? 'Update Program' : 'Create Program')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default ProgramFormBuilder;
import React, { useState, useEffect } from 'react';
import { Program, FormField, FieldType } from '../types';
import FormFieldBuilder from './FormFieldBuilder';

interface ProgramFormBuilderProps {
  program: Program | null;
  onClose: () => void;
}

const ProgramFormBuilder: React.FC<ProgramFormBuilderProps> = ({ program, onClose }) => {
  const [activeTab, setActiveTab] = useState<'details' | 'fields'>('details');
  const [savedProgramId, setSavedProgramId] = useState<number | undefined>(program?.id);
  const [formData, setFormData] = useState<Program>({
    name: '',
    type: 'camp',
    description: '',
    status: 'draft',
    start_date: '',
    end_date: '',
    registration_opens: '',
    registration_closes: '',
    min_age: undefined,
    max_age: undefined,
    capacity: undefined
  });

  const [formFields, setFormFields] = useState<FormField[]>([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (program) {
      setFormData(program);
      // TODO: Load existing form fields
    }
  }, [program]);

  const handleSave = async () => {
    setSaving(true);
    try {
      const url = savedProgramId
        ? `http://localhost:8889/registration/programs-api.php?id=${savedProgramId}`
        : 'http://localhost:8889/registration/programs-api.php?path=create';

      const method = savedProgramId ? 'PUT' : 'POST';

      const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...formData, club_id: 1 })
      });

      if (response.ok) {
        const data = await response.json();
        if (!savedProgramId && data.id) {
          setSavedProgramId(data.id);
          alert('Program saved! You can now configure the registration form.');
        } else {
          onClose();
        }
      }
    } catch (error) {
      console.error('Error saving program:', error);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
        <div className="border-b-2 border-forest-800 px-6 py-4">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
              {program ? 'Edit Program' : 'Create New Program'}
            </h3>
            <button
              onClick={onClose}
              className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
            >
              ×
            </button>
          </div>

          {/* Tabs */}
          <div className="flex space-x-8 border-b-2 border-forest-800 -mb-0.5">
            <button
              onClick={() => setActiveTab('details')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'details'
                  ? 'border-forest-800 text-forest-800'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              Program Details
            </button>
            <button
              onClick={() => setActiveTab('fields')}
              className={`pb-2 px-1 border-b-2 font-medium text-sm uppercase transition-colors ${
                activeTab === 'fields'
                  ? 'border-forest-800 text-forest-800'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              Registration Form
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-6">
          {activeTab === 'details' && (
            <div className="space-y-6">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Program Name *
                  </label>
                  <input
                    type="text"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="Summer Soccer Camp 2024"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Program Type *
                  </label>
                  <select
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Description
                  </label>
                  <textarea
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.description || ''}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    rows={3}
                    placeholder="Describe your program..."
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Start Date
                  </label>
                  <input
                    type="date"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.start_date || ''}
                    onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    End Date
                  </label>
                  <input
                    type="date"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.end_date || ''}
                    onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Registration Opens
                  </label>
                  <input
                    type="datetime-local"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.registration_opens || ''}
                    onChange={(e) => setFormData({ ...formData, registration_opens: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Registration Closes
                  </label>
                  <input
                    type="datetime-local"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.registration_closes || ''}
                    onChange={(e) => setFormData({ ...formData, registration_closes: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Min Age
                  </label>
                  <input
                    type="number"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.min_age || ''}
                    onChange={(e) => setFormData({ ...formData, min_age: parseInt(e.target.value) || undefined })}
                    min="0"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Max Age
                  </label>
                  <input
                    type="number"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.max_age || ''}
                    onChange={(e) => setFormData({ ...formData, max_age: parseInt(e.target.value) || undefined })}
                    min="0"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Capacity
                  </label>
                  <input
                    type="number"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.capacity || ''}
                    onChange={(e) => setFormData({ ...formData, capacity: parseInt(e.target.value) || undefined })}
                    min="1"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Status
                  </label>
                  <select
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
              <div className="bg-yellow-50 border-2 border-yellow-600 p-6 text-center">
                <p className="text-yellow-800 font-medium mb-2">Save Program Details First</p>
                <p className="text-yellow-600 text-sm">
                  Please save the program details in the first tab before configuring the registration form.
                </p>
              </div>
            )
          )}
        </div>

        <div className="border-t-2 border-forest-800 px-6 py-4 flex justify-end space-x-4">
          <button
            onClick={onClose}
            className="bg-white text-forest-800 border-2 border-forest-800 px-6 py-2 hover:bg-gray-100 uppercase"
          >
            Cancel
          </button>
          <button
            onClick={handleSave}
            disabled={saving || !formData.name}
            className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 font-semibold uppercase disabled:opacity-50"
          >
            {saving ? 'Saving...' : (savedProgramId ? 'Update Program' : 'Create Program')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default ProgramFormBuilder;
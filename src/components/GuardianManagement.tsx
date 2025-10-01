import React, { useState } from 'react';
// Updated layout for guardian actions

interface Guardian {
  id?: number;
  first_name: string;
  last_name: string;
  email: string;
  mobile_phone: string;
  work_phone?: string;
  relationship_type: string;
  is_primary_contact?: boolean;
  has_legal_custody?: boolean;
  can_authorize_medical?: boolean;
  can_pickup?: boolean;
  receives_communications?: boolean;
  financial_responsible?: boolean;
}

interface GuardianManagementProps {
  athleteId: number;
  guardians: Guardian[];
  onUpdate: () => void;
  onClose: () => void;
}

const GuardianManagement: React.FC<GuardianManagementProps> = ({
  athleteId,
  guardians,
  onUpdate,
  onClose
}) => {
  const [showAddForm, setShowAddForm] = useState(false);
  const [editingGuardian, setEditingGuardian] = useState<Guardian | null>(null);
  const [formData, setFormData] = useState<Guardian>({
    first_name: '',
    last_name: '',
    email: '',
    mobile_phone: '',
    work_phone: '',
    relationship_type: 'Mother',
    is_primary_contact: false,
    has_legal_custody: true,
    can_authorize_medical: true,
    can_pickup: true,
    receives_communications: true,
    financial_responsible: false
  });

  const handleChange = (field: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      [field]: value
    }));
  };

  const handleAddGuardian = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      const response = await fetch(
        `http://localhost:8889/guardian-gateway.php`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...formData, athlete_id: athleteId })
        }
      );

      if (response.ok) {
        alert('Guardian added successfully!');
        setShowAddForm(false);
        setFormData({
          first_name: '',
          last_name: '',
          email: '',
          mobile_phone: '',
          work_phone: '',
          relationship_type: 'Mother',
          is_primary_contact: false,
          has_legal_custody: true,
          can_authorize_medical: true,
          can_pickup: true,
          receives_communications: true,
          financial_responsible: false
        });
        onUpdate();
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to add guardian');
      }
    } catch (error) {
      console.error('Error adding guardian:', error);
      alert('Failed to add guardian');
    }
  };

  const handleRemoveGuardian = async (guardianId: number) => {
    if (!window.confirm('Are you sure you want to remove this guardian?')) return;

    try {
      const response = await fetch(
        `http://localhost:8889/guardian-gateway.php?id=${guardianId}`,
        {
          method: 'DELETE'
        }
      );

      if (response.ok) {
        alert('Guardian removed successfully!');
        onUpdate();
      } else {
        alert('Failed to remove guardian');
      }
    } catch (error) {
      console.error('Error removing guardian:', error);
      alert('Failed to remove guardian');
    }
  };

  const handleUpdatePermissions = async (guardian: Guardian) => {
    try {
      const response = await fetch(
        `http://localhost:8889/guardian-gateway.php`,
        {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(guardian)
        }
      );

      if (response.ok) {
        alert('Guardian permissions updated!');
        setEditingGuardian(null);
        onUpdate();
      } else {
        alert('Failed to update guardian');
      }
    } catch (error) {
      console.error('Error updating guardian:', error);
      alert('Failed to update guardian');
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-5xl max-h-[90vh] overflow-y-auto">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">Manage Guardians/Household Members</h3>
          <button onClick={onClose} className="text-forest-800 hover:bg-gray-100 px-2 text-2xl">
            ×
          </button>
        </div>

        <div className="p-6">
          {/* Current Guardians */}
          <div className="mb-6">
            <div className="flex justify-between items-center mb-4">
              <h4 className="text-lg font-semibold text-forest-800">Current Guardians</h4>
              <button
                onClick={() => setShowAddForm(true)}
                className="bg-forest-800 text-white border-2 border-forest-800 px-4 py-2 hover:bg-forest-700 font-semibold uppercase"
              >
                + Add Guardian
              </button>
            </div>

            {guardians.length === 0 ? (
              <p className="text-gray-600">No guardians added yet.</p>
            ) : (
              <div className="space-y-4">
                {/* Group guardians by email */}
                {(() => {
                  const emailGroups = guardians.reduce((groups, guardian) => {
                    const email = guardian.email;
                    if (!groups[email]) {
                      groups[email] = [];
                    }
                    groups[email].push(guardian);
                    return groups;
                  }, {} as Record<string, typeof guardians>);

                  return Object.entries(emailGroups).map(([email, guardiansWithEmail]) => (
                    <div key={email} className="space-y-2">
                      {guardiansWithEmail.length > 1 && (
                        <div className="text-xs text-gray-600 px-4 py-1 bg-gray-100 border border-gray-300">
                          Shared Email: {email} ({guardiansWithEmail.map(g => g.first_name).join(' & ')})
                        </div>
                      )}
                      {guardiansWithEmail.map(guardian => (
                  <div key={guardian.id} className="bg-white border-2 border-forest-800 p-4">
                    <div className="flex justify-between items-start">
                      <div className="flex-1">
                        <div className="mb-2">
                          <div className="flex items-center space-x-4 mb-1">
                            <h5 className="text-forest-800 font-semibold cursor-pointer hover:text-forest-600">
                              {guardian.first_name} {guardian.last_name}
                            </h5>
                            <span className="px-2 py-1 bg-forest-800 text-white text-sm">
                              {guardian.relationship_type}
                            </span>
                            {guardian.is_primary_contact && (
                              <span className="px-2 py-1 bg-green-600 text-white text-sm">
                                Primary Contact
                              </span>
                            )}
                          </div>

                          {editingGuardian?.id !== guardian.id && (
                            <div className="flex space-x-3">
                              <button
                                onClick={() => setEditingGuardian(guardian)}
                                className="text-forest-800 hover:text-forest-600 text-sm font-semibold uppercase"
                              >
                                Edit
                              </button>
                              <button
                                onClick={() => handleRemoveGuardian(guardian.id!)}
                                className="text-red-600 hover:text-red-700 text-sm font-semibold uppercase"
                              >
                                Delete
                              </button>
                            </div>
                          )}
                        </div>

                        <div className="text-gray-600 text-sm space-y-1">
                          <div>Email: {guardian.email}</div>
                          <div>Mobile: {guardian.mobile_phone}</div>
                          {guardian.work_phone && <div>Work: {guardian.work_phone}</div>}
                        </div>

                        {editingGuardian?.id === guardian.id && editingGuardian ? (
                          <div className="mt-4 space-y-2 border-t border-gray-300 pt-4">
                            <h6 className="text-forest-800 font-semibold">Permissions:</h6>
                            <label className="flex items-center text-forest-800">
                              <input
                                type="checkbox"
                                className="mr-2"
                                checked={editingGuardian.is_primary_contact || false}
                                onChange={(e) => {
                                  if (editingGuardian) {
                                    setEditingGuardian({
                                      ...editingGuardian,
                                      is_primary_contact: e.target.checked
                                    });
                                  }
                                }}
                              />
                              Primary Contact
                            </label>
                            <label className="flex items-center text-forest-800">
                              <input
                                type="checkbox"
                                className="mr-2"
                                checked={editingGuardian.has_legal_custody || false}
                                onChange={(e) => {
                                  if (editingGuardian) {
                                    setEditingGuardian({
                                      ...editingGuardian,
                                      has_legal_custody: e.target.checked
                                    });
                                  }
                                }}
                              />
                              Has Legal Custody
                            </label>
                            <label className="flex items-center text-forest-800">
                              <input
                                type="checkbox"
                                className="mr-2"
                                checked={editingGuardian.can_authorize_medical || false}
                                onChange={(e) => {
                                  if (editingGuardian) {
                                    setEditingGuardian({
                                      ...editingGuardian,
                                      can_authorize_medical: e.target.checked
                                    });
                                  }
                                }}
                              />
                              Can Authorize Medical
                            </label>
                            <label className="flex items-center text-forest-800">
                              <input
                                type="checkbox"
                                className="mr-2"
                                checked={editingGuardian.can_pickup || false}
                                onChange={(e) => {
                                  if (editingGuardian) {
                                    setEditingGuardian({
                                      ...editingGuardian,
                                      can_pickup: e.target.checked
                                    });
                                  }
                                }}
                              />
                              Can Pick Up Athlete
                            </label>
                            <label className="flex items-center text-forest-800">
                              <input
                                type="checkbox"
                                className="mr-2"
                                checked={editingGuardian.receives_communications || false}
                                onChange={(e) => {
                                  if (editingGuardian) {
                                    setEditingGuardian({
                                      ...editingGuardian,
                                      receives_communications: e.target.checked
                                    });
                                  }
                                }}
                              />
                              Receives Communications
                            </label>
                            <label className="flex items-center text-forest-800">
                              <input
                                type="checkbox"
                                className="mr-2"
                                checked={editingGuardian.financial_responsible || false}
                                onChange={(e) => {
                                  if (editingGuardian) {
                                    setEditingGuardian({
                                      ...editingGuardian,
                                      financial_responsible: e.target.checked
                                    });
                                  }
                                }}
                              />
                              Financially Responsible
                            </label>
                            <div className="flex space-x-2 mt-4">
                              <button
                                onClick={() => handleUpdatePermissions(editingGuardian)}
                                className="bg-forest-800 text-white border-2 border-forest-800 px-3 py-1 text-sm hover:bg-forest-700 font-semibold uppercase"
                              >
                                Save
                              </button>
                              <button
                                onClick={() => setEditingGuardian(null)}
                                className="bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 text-sm hover:bg-gray-100 font-semibold uppercase"
                              >
                                Cancel
                              </button>
                            </div>
                          </div>
                        ) : (
                          <div className="mt-3 flex items-center space-x-4 text-xs">
                            <span className="text-gray-500">
                              {[
                                guardian.has_legal_custody && 'Legal Custody',
                                guardian.can_authorize_medical && 'Medical Auth',
                                guardian.can_pickup && 'Can Pickup',
                                guardian.receives_communications && 'Gets Comms',
                                guardian.financial_responsible && 'Financial'
                              ]
                                .filter(Boolean)
                                .join(' • ')}
                            </span>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                      ))}
                    </div>
                  ));
                })()}
              </div>
            )}
          </div>

          {/* Add Guardian Form */}
          {showAddForm && (
            <div className="bg-white border-2 border-forest-800 p-6">
              <h4 className="text-lg font-semibold text-forest-800 mb-4">Add New Guardian</h4>
              <form onSubmit={handleAddGuardian}>
                <div className="grid grid-cols-2 gap-4 mb-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2">
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
                    <label className="block text-forest-800 text-sm font-medium mb-2">
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

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2">
                      Email *
                    </label>
                    <input
                      type="email"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.email}
                      onChange={(e) => handleChange('email', e.target.value)}
                      required
                    />
                    <p className="text-gray-500 text-xs mt-1">
                      Multiple guardians can share the same email if they have different first names (e.g., John and Jane at thejonesfamily@email.com)
                    </p>
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2">
                      Mobile Phone *
                    </label>
                    <input
                      type="tel"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.mobile_phone}
                      onChange={(e) => handleChange('mobile_phone', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2">
                      Work Phone
                    </label>
                    <input
                      type="tel"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.work_phone}
                      onChange={(e) => handleChange('work_phone', e.target.value)}
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-2">
                      Relationship *
                    </label>
                    <select
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      value={formData.relationship_type}
                      onChange={(e) => handleChange('relationship_type', e.target.value)}
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
                </div>

                <div className="space-y-2 mb-4 border-t border-gray-300 pt-4">
                  <h5 className="text-forest-800 font-semibold">Permissions:</h5>
                  <div className="grid grid-cols-2 gap-2">
                    <label className="flex items-center text-forest-800">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.is_primary_contact}
                        onChange={(e) => handleChange('is_primary_contact', e.target.checked)}
                      />
                      Primary Contact
                    </label>
                    <label className="flex items-center text-forest-800">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.has_legal_custody}
                        onChange={(e) => handleChange('has_legal_custody', e.target.checked)}
                      />
                      Has Legal Custody
                    </label>
                    <label className="flex items-center text-forest-800">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.can_authorize_medical}
                        onChange={(e) => handleChange('can_authorize_medical', e.target.checked)}
                      />
                      Can Authorize Medical
                    </label>
                    <label className="flex items-center text-forest-800">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.can_pickup}
                        onChange={(e) => handleChange('can_pickup', e.target.checked)}
                      />
                      Can Pick Up Athlete
                    </label>
                    <label className="flex items-center text-forest-800">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.receives_communications}
                        onChange={(e) => handleChange('receives_communications', e.target.checked)}
                      />
                      Receives Communications
                    </label>
                    <label className="flex items-center text-forest-800">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.financial_responsible}
                        onChange={(e) => handleChange('financial_responsible', e.target.checked)}
                      />
                      Financially Responsible
                    </label>
                  </div>
                </div>

                <div className="flex justify-end space-x-4">
                  <button
                    type="button"
                    onClick={() => setShowAddForm(false)}
                    className="bg-white text-forest-800 border-2 border-forest-800 px-6 py-2 hover:bg-gray-100 font-semibold uppercase"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 font-semibold uppercase"
                  >
                    Add Guardian
                  </button>
                </div>
              </form>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default GuardianManagement;
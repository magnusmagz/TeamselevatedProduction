import React, { useState, useEffect } from 'react';
import { useOrg } from '../contexts/OrgContext';
// Updated layout for guardian actions

interface Guardian {
  id?: number;
  first_name: string;
  last_name: string;
  email: string;
  mobile_phone: string;
  work_phone?: string;
  relationship_type: string;
  // ⚠️ No `is_primary_contact`. Crew members are equal (product rule,
  // 2026-09-02): nothing here ranks one guardian above another, and the gateway
  // neither reads nor writes athlete_guardians.is_primary.
  has_legal_custody?: boolean;
  can_authorize_medical?: boolean;
  can_pickup?: boolean;
  receives_communications?: boolean;
  financial_responsible?: boolean;
}

interface GuardianManagementProps {
  athleteId: number;
  athleteName?: string;
  guardians: Guardian[];
  onUpdate: () => void;
  onClose: () => void;
}

const GuardianManagement: React.FC<GuardianManagementProps> = ({
  athleteId,
  athleteName,
  guardians,
  onUpdate,
  onClose
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [showAddForm, setShowAddForm] = useState(false);
  const [editingGuardian, setEditingGuardian] = useState<Guardian | null>(null);

  // Parent-portal invite status per guardian (Option A).
  const { activeContext } = useOrg();
  const clubId = activeContext?.scope_id;
  const [statuses, setStatuses] = useState<Record<number, string>>({});
  const [inviting, setInviting] = useState<number | null>(null);

  const fetchStatuses = async () => {
    if (!athleteId || !clubId) return;
    try {
      const res = await fetch(`${API_URL}/api/auth-gateway.php?action=parent-portal-status`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
        body: JSON.stringify({ athlete_id: athleteId, club_id: clubId }),
      });
      const data = await res.json();
      if (res.ok && data.success) {
        const map: Record<number, string> = {};
        (data.statuses || []).forEach((s: any) => { map[s.guardian_id] = s.status; });
        setStatuses(map);
      }
    } catch { /* status is non-critical */ }
  };

  useEffect(() => { fetchStatuses(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [athleteId, clubId]);

  const handleInvite = async (guardian: Guardian) => {
    if (!guardian.id || !clubId) return;
    setInviting(guardian.id);
    try {
      const res = await fetch(`${API_URL}/api/auth-gateway.php?action=send-parent-invite`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
        body: JSON.stringify({ guardian_id: guardian.id, club_id: clubId, athlete_id: athleteId }),
      });
      const data = await res.json();
      if (res.ok && data.success) {
        alert(data.status === 'already_active' ? 'That parent already has an account.' : `Invite sent to ${data.email}`);
        await fetchStatuses(); // refresh (also updates any shared-email co-parent)
      } else {
        alert(data.error || 'Could not send invite.');
      }
    } catch {
      alert('Could not send invite.');
    } finally {
      setInviting(null);
    }
  };

  const renderStatusChip = (guardian: Guardian) => {
    if (!guardian.id) return null;
    const st = guardian.email?.trim() ? (statuses[guardian.id] || 'not_invited') : 'no_email';
    const map: Record<string, { label: string; cls: string }> = {
      active: { label: 'Portal active', cls: 'bg-green-100 text-green-700' },
      invited: { label: 'Invited', cls: 'bg-amber-100 text-amber-800' },
      not_invited: { label: 'Not invited', cls: 'bg-gray-100 text-gray-600' },
      no_email: { label: 'No email', cls: 'bg-gray-100 text-gray-500' },
    };
    const m = map[st] || map.not_invited;
    return <span className={`px-2 py-1 rounded-full text-xs font-semibold ${m.cls}`}>{m.label}</span>;
  };

  const renderInviteAction = (guardian: Guardian) => {
    if (!guardian.id) return null;
    if (!guardian.email?.trim()) {
      return <span className="text-gray-400 text-sm italic">Add an email to invite</span>;
    }
    const st = statuses[guardian.id] || 'not_invited';
    const busy = inviting === guardian.id;
    if (st === 'active') return null;
    if (st === 'invited') {
      return (
        <button onClick={() => handleInvite(guardian)} disabled={busy}
          className="text-brand-primary hover:text-brand-primary-hover text-sm font-semibold uppercase disabled:opacity-50">
          {busy ? 'Sending…' : 'Resend invite'}
        </button>
      );
    }
    return (
      <button onClick={() => handleInvite(guardian)} disabled={busy}
        className="bg-brand-primary text-white border border-brand-primary rounded-md px-3 py-1.5 text-sm font-bold uppercase hover:bg-brand-primary-hover disabled:opacity-50">
        {busy ? 'Sending…' : 'Invite to portal'}
      </button>
    );
  };
  const [formData, setFormData] = useState<Guardian>({
    first_name: '',
    last_name: '',
    email: '',
    mobile_phone: '',
    work_phone: '',
    relationship_type: 'Parent',
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
        `${API_URL}/legacy/guardian-gateway.php`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
          body: JSON.stringify({ ...formData, athlete_id: athleteId })
        }
      );

      if (response.ok) {
        alert('Crew member added successfully!');
        setShowAddForm(false);
        setFormData({
          first_name: '',
          last_name: '',
          email: '',
          mobile_phone: '',
          work_phone: '',
          relationship_type: 'Parent',
          has_legal_custody: true,
          can_authorize_medical: true,
          can_pickup: true,
          receives_communications: true,
          financial_responsible: false
        });
        onUpdate();
      } else {
        const error = await response.json();
        alert(error.error || 'Failed to add crew member');
      }
    } catch (error) {
      console.error('Error adding guardian:', error);
      alert('Failed to add crew member');
    }
  };

  const handleRemoveGuardian = async (guardianId: number) => {
    if (!window.confirm('Are you sure you want to remove this crew member?')) return;

    try {
      const response = await fetch(
        `${API_URL}/legacy/guardian-gateway.php?id=${guardianId}`,
        {
          method: 'DELETE',
          headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` }
        }
      );

      if (response.ok) {
        alert('Crew member removed successfully!');
        onUpdate();
      } else {
        alert('Failed to remove crew member');
      }
    } catch (error) {
      console.error('Error removing guardian:', error);
      alert('Failed to remove crew member');
    }
  };

  const handleUpdatePermissions = async (guardian: Guardian) => {
    try {
      const response = await fetch(
        `${API_URL}/legacy/guardian-gateway.php`,
        {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
          body: JSON.stringify(guardian)
        }
      );

      if (response.ok) {
        alert('Crew permissions updated!');
        setEditingGuardian(null);
        onUpdate();
      } else {
        alert('Failed to update crew member');
      }
    } catch (error) {
      console.error('Error updating guardian:', error);
      alert('Failed to update crew member');
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md w-full max-w-5xl max-h-[90vh] overflow-y-auto">
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
            {athleteName ? `${athleteName}'s Crew` : 'Crew'}
          </h3>
          <button onClick={onClose} className="text-brand-primary hover:bg-gray-100 px-2 text-2xl">
            ×
          </button>
        </div>

        <div className="p-6">
          {/* Current Guardians */}
          <div className="mb-6">
            <div className="flex justify-between items-center mb-4">
              <div>
                <h4 className="text-lg font-semibold text-brand-primary">Crew</h4>
                <p className="text-xs text-gray-500">Crew &amp; family</p>
              </div>
              <button
                onClick={() => setShowAddForm(true)}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary font-semibold uppercase"
              >
                + Add to Crew
              </button>
            </div>

            {guardians.length === 0 ? (
              <p className="text-gray-600">No crew added yet.</p>
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
                  <div key={guardian.id} className="bg-white border border-brand-secondary rounded-md p-4">
                    <div className="flex justify-between items-start">
                      <div className="flex-1">
                        <div className="mb-2">
                          <div className="flex items-center space-x-4 mb-1">
                            <h5 className="text-brand-primary font-semibold cursor-pointer hover:text-brand-primary-hover">
                              {guardian.first_name} {guardian.last_name}
                            </h5>
                            <span className="px-2 py-1 bg-brand-primary text-white text-sm">
                              {guardian.relationship_type}
                            </span>
                            {renderStatusChip(guardian)}
                          </div>

                          {editingGuardian?.id !== guardian.id && (
                            <div className="flex space-x-3">
                              <button
                                onClick={() => setEditingGuardian(guardian)}
                                className="text-brand-primary hover:text-brand-primary-hover text-sm font-semibold uppercase"
                              >
                                Edit
                              </button>
                              <button
                                onClick={() => handleRemoveGuardian(guardian.id!)}
                                className="text-red-600 hover:text-red-700 text-sm font-semibold uppercase"
                              >
                                Delete
                              </button>
                              {renderInviteAction(guardian)}
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
                            <h6 className="text-brand-primary font-semibold">Permissions:</h6>
                            <label className="flex items-center text-brand-primary">
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
                            <label className="flex items-center text-brand-primary">
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
                            <label className="flex items-center text-brand-primary">
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
                            <label className="flex items-center text-brand-primary">
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
                            <label className="flex items-center text-brand-primary">
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
                                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-3 py-1 text-sm hover:bg-brand-primary font-semibold uppercase"
                              >
                                Save
                              </button>
                              <button
                                onClick={() => setEditingGuardian(null)}
                                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-1 text-sm hover:bg-gray-100 font-semibold uppercase"
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

          {/* Add Crew Member Form */}
          {showAddForm && (
            <div className="bg-white border border-brand-secondary rounded-md p-6">
              <h4 className="text-lg font-semibold text-brand-primary mb-4">Add Crew Member</h4>
              <form onSubmit={handleAddGuardian}>
                <div className="grid grid-cols-2 gap-4 mb-4">
                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2">
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
                    <label className="block text-brand-primary text-sm font-medium mb-2">
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

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2">
                      Email *
                    </label>
                    <input
                      type="email"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.email}
                      onChange={(e) => handleChange('email', e.target.value)}
                      required
                    />
                    <p className="text-gray-500 text-xs mt-1">
                      Multiple guardians can share the same email if they have different first names (e.g., John and Jane at thejonesfamily@email.com)
                    </p>
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2">
                      Mobile Phone *
                    </label>
                    <input
                      type="tel"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.mobile_phone}
                      onChange={(e) => handleChange('mobile_phone', e.target.value)}
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2">
                      Work Phone
                    </label>
                    <input
                      type="tel"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.work_phone}
                      onChange={(e) => handleChange('work_phone', e.target.value)}
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-2">
                      Relationship *
                    </label>
                    <select
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={formData.relationship_type}
                      onChange={(e) => handleChange('relationship_type', e.target.value)}
                      required
                    >
                      <option value="Parent">Parent</option>
                      <option value="Guardian">Guardian</option>
                      <option value="Other">Other</option>
                    </select>
                  </div>
                </div>

                <div className="space-y-2 mb-4 border-t border-gray-300 pt-4">
                  <h5 className="text-brand-primary font-semibold">Permissions:</h5>
                  <div className="grid grid-cols-2 gap-2">
                    <label className="flex items-center text-brand-primary">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.has_legal_custody}
                        onChange={(e) => handleChange('has_legal_custody', e.target.checked)}
                      />
                      Has Legal Custody
                    </label>
                    <label className="flex items-center text-brand-primary">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.can_authorize_medical}
                        onChange={(e) => handleChange('can_authorize_medical', e.target.checked)}
                      />
                      Can Authorize Medical
                    </label>
                    <label className="flex items-center text-brand-primary">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.can_pickup}
                        onChange={(e) => handleChange('can_pickup', e.target.checked)}
                      />
                      Can Pick Up Athlete
                    </label>
                    <label className="flex items-center text-brand-primary">
                      <input
                        type="checkbox"
                        className="mr-2"
                        checked={formData.receives_communications}
                        onChange={(e) => handleChange('receives_communications', e.target.checked)}
                      />
                      Receives Communications
                    </label>
                    <label className="flex items-center text-brand-primary">
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
                    className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 font-semibold uppercase"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase"
                  >
                    Add Crew Member
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
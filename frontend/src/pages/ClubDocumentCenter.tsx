import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../contexts/OrgContext';
import { pageQuery } from '../utils/pagination';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface DocumentAssignment {
  id: number;
  document_id: number;
  target_type: 'club' | 'team' | 'athlete' | 'user';
  target_id: number;
  target_name: string | null;
  assigned_at: string;
}

interface ClubDocument {
  id: number;
  club_profile_id: number;
  title: string;
  description: string | null;
  file_path: string | null;
  file_name: string | null;
  mime_type: string | null;
  link_url: string | null;
  slot: string | null;
  notes: string | null;
  is_required: boolean;
  expires_at: string | null;
  uploaded_by: number | null;
  uploaded_by_name?: string;
  created_at: string;
  updated_at: string | null;
  assignments?: DocumentAssignment[];
}

interface AssignableTeam { id: number; name: string }
interface AssignableAthlete { id: number; first_name: string; last_name: string }
interface AssignableUser { id: number; first_name: string; last_name: string; role_label?: string }

type AssignmentTarget = { target_type: 'club' | 'team' | 'athlete' | 'user'; target_id: number };

interface Slot {
  key: string;
  label: string;
  documents: ClubDocument[];
}

const DEFAULT_SLOTS: { key: string; label: string }[] = [
  { key: 'background_check', label: 'Background Check' },
  { key: 'abuse_prevention', label: 'Abuse Prevention Training' },
  { key: 'concussion', label: 'Concussion Protocol' },
  { key: 'code_of_conduct', label: 'Code of Conduct' },
  { key: 'coaches_certificate', label: 'Coaches Certificate' },
  { key: 'attestation', label: 'Attestation' },
  { key: 'first_aid', label: 'First Aid' },
  { key: 'cpr', label: 'CPR' },
  { key: 'trainings', label: 'Trainings' },
];

const ClubDocumentCenter: React.FC = () => {
  const { activeContext } = useOrg();
  const clubId = activeContext?.scope_id;

  const [slots, setSlots] = useState<Slot[]>([]);
  const [customDocuments, setCustomDocuments] = useState<ClubDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Modal state
  const [showModal, setShowModal] = useState(false);
  const [editingDoc, setEditingDoc] = useState<ClubDocument | null>(null);
  const [modalSlot, setModalSlot] = useState<string>('');
  const [modalName, setModalName] = useState('');
  const [modalTab, setModalTab] = useState<'upload' | 'link'>('link');
  const [modalLinkUrl, setModalLinkUrl] = useState('');
  const [modalFileUrl, setModalFileUrl] = useState('');
  const [modalFileName, setModalFileName] = useState<string | null>(null);
  const [modalMimeType, setModalMimeType] = useState<string | null>(null);
  const [modalNotes, setModalNotes] = useState('');
  const [modalIsRequired, setModalIsRequired] = useState(false);
  const [modalExpiresAt, setModalExpiresAt] = useState('');
  const [modalAssignments, setModalAssignments] = useState<AssignmentTarget[]>([]);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);

  // Assignment picker data
  const [availableTeams, setAvailableTeams] = useState<AssignableTeam[]>([]);
  const [availableAthletes, setAvailableAthletes] = useState<AssignableAthlete[]>([]);
  const [availableCoaches, setAvailableCoaches] = useState<AssignableUser[]>([]);
  const [coachesError, setCoachesError] = useState<string | null>(null);
  const [showAssignmentPicker, setShowAssignmentPicker] = useState(false);

  const token = localStorage.getItem('auth_token');

  const fetchDocuments = useCallback(async () => {
    if (!clubId) return;
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(
        `${API_URL}/api/documents-gateway.php?club_profile_id=${clubId}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      const data = await response.json();
      if (data.success) {
        setSlots(data.slots || []);
        setCustomDocuments(data.custom_documents || []);
      } else {
        setError(data.error || 'Failed to load documents');
      }
    } catch {
      setError('Failed to load documents');
    } finally {
      setLoading(false);
    }
  }, [clubId, token]);

  // Load teams/athletes/coaches for the assignment picker (lazy on first modal open)
  const loadAssignablePeople = useCallback(async () => {
    if (!clubId) return;
    setCoachesError(null);
    // ⚠️ The coaches action is `available`, not `list`. `legacy/coaches-gateway.php`
    // has no `list` case and no `default:`, so that request returned 200 with an
    // EMPTY BODY, `.json()` threw, the `.catch(() => null)` swallowed it, and the
    // Coaches / Volunteers section of the picker simply never rendered — no error,
    // no empty state, just a missing assignment target.
    //
    // ⚠️ `available` used to return a BARE ARRAY and now returns
    // `{success, coaches, page}` (GOTR G2 pagination — a truncated array cannot
    // say it is truncated). Both branches below stay: the frontend deploys
    // before the backend, so the array shape is live for the deploy window.
    // Both lists ask for limit=1000 because these are PICKERS — an assignable
    // person missing from the list is invisible, with no "load more" to press.
    const [teamsRes, athletesRes, coachesRes] = await Promise.all([
      fetch(`${API_URL}/api/teams.php?club_profile_id=${clubId}`, { headers: { Authorization: `Bearer ${token}` } }).then(r => r.json()).catch(() => null),
      fetch(`${API_URL}/legacy/athletes-gateway.php?club_id=${clubId}${pageQuery(null, 1000)}`, { headers: { Authorization: `Bearer ${token}` } }).then(r => r.json()).catch(() => null),
      fetch(`${API_URL}/legacy/coaches-gateway.php?action=available&club_id=${clubId}${pageQuery(null, 1000)}`, { headers: { Authorization: `Bearer ${token}` } })
        .then(r => r.json())
        .catch(() => ({ __failed: true })),
    ]);
    if (teamsRes?.teams) setAvailableTeams(teamsRes.teams);
    if (athletesRes?.athletes) setAvailableAthletes(athletesRes.athletes);
    else if (Array.isArray(athletesRes)) setAvailableAthletes(athletesRes);

    if (Array.isArray(coachesRes)) setAvailableCoaches(coachesRes);
    else if (Array.isArray(coachesRes?.coaches)) setAvailableCoaches(coachesRes.coaches);
    else {
      // A real error state on the section, not a silent absence. An empty
      // Coaches list and an unreachable one look identical otherwise, and the
      // admin's only symptom is that they cannot assign a document to a coach.
      setAvailableCoaches([]);
      setCoachesError(coachesRes?.error || 'Could not load coaches and volunteers.');
    }
  }, [clubId, token]);

  useEffect(() => {
    fetchDocuments();
  }, [fetchDocuments]);

  const resetModal = () => {
    setShowModal(false);
    setEditingDoc(null);
    setModalSlot('');
    setModalName('');
    setModalTab('link');
    setModalLinkUrl('');
    setModalFileUrl('');
    setModalFileName(null);
    setModalMimeType(null);
    setModalNotes('');
    setModalIsRequired(false);
    setModalExpiresAt('');
    setModalAssignments([]);
    setShowAssignmentPicker(false);
  };

  const openAddModal = (slotKey?: string) => {
    resetModal();
    setModalSlot(slotKey || '');
    if (slotKey) {
      const slotDef = DEFAULT_SLOTS.find((s) => s.key === slotKey);
      if (slotDef) setModalName(slotDef.label);
    }
    // Default new docs to club-wide assignment so they're at least visible
    if (clubId) setModalAssignments([{ target_type: 'club', target_id: clubId }]);
    loadAssignablePeople();
    setShowModal(true);
  };

  const openEditModal = (doc: ClubDocument) => {
    resetModal();
    setEditingDoc(doc);
    setModalSlot(doc.slot || '');
    setModalName(doc.title);
    setModalNotes(doc.notes || '');
    setModalIsRequired(!!doc.is_required);
    setModalExpiresAt(doc.expires_at ? doc.expires_at.slice(0, 10) : '');
    if (doc.file_path) {
      setModalTab('upload');
      setModalFileUrl(doc.file_path);
      setModalFileName(doc.file_name);
      setModalMimeType(doc.mime_type);
    } else {
      setModalTab('link');
      setModalLinkUrl(doc.link_url || '');
    }
    if (doc.assignments) {
      setModalAssignments(doc.assignments.map(a => ({ target_type: a.target_type, target_id: a.target_id })));
    }
    loadAssignablePeople();
    setShowModal(true);
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('type', 'club-documents');

      const response = await fetch(`${API_URL}/api/upload.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: formData,
      });
      const data = await response.json();
      if (data.success) {
        setModalFileUrl(data.url);
        setModalFileName(file.name);
        setModalMimeType(file.type || null);
        if (!modalName) {
          setModalName(file.name.replace(/\.[^/.]+$/, ''));
        }
      } else {
        alert(data.error || 'Upload failed');
      }
    } catch {
      alert('Upload failed');
    } finally {
      setUploading(false);
    }
  };

  const handleSave = async () => {
    if (!modalName.trim()) {
      alert('Document name is required');
      return;
    }

    const resolvedFilePath = modalTab === 'upload' ? modalFileUrl : null;
    const resolvedLinkUrl = modalTab === 'link' ? modalLinkUrl : null;

    if (!resolvedFilePath && !resolvedLinkUrl) {
      alert('Please upload a file or paste a link');
      return;
    }

    setSaving(true);
    try {
      const slotForApi = modalSlot && modalSlot !== '__custom__' ? modalSlot : null;
      const expiresForApi = modalExpiresAt ? modalExpiresAt : null;

      if (editingDoc) {
        // Update metadata
        const updateRes = await fetch(`${API_URL}/api/documents-gateway.php?id=${editingDoc.id}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({
            title: modalName.trim(),
            file_path: resolvedFilePath,
            file_name: modalFileName,
            mime_type: modalMimeType,
            link_url: resolvedLinkUrl,
            notes: modalNotes.trim() || null,
            slot: slotForApi,
            is_required: modalIsRequired,
            expires_at: expiresForApi,
          }),
        });
        const updateData = await updateRes.json();
        if (!updateData.success) {
          alert(updateData.error || 'Failed to update document');
          return;
        }

        // Sync assignments: remove ones no longer present, add new ones
        const before = (editingDoc.assignments || []).map(a => `${a.target_type}:${a.target_id}`);
        const after = modalAssignments.map(a => `${a.target_type}:${a.target_id}`);
        const toRemove = (editingDoc.assignments || []).filter(a => !after.includes(`${a.target_type}:${a.target_id}`));
        const toAdd = modalAssignments.filter(a => !before.includes(`${a.target_type}:${a.target_id}`));
        for (const r of toRemove) {
          await fetch(`${API_URL}/api/documents-gateway.php?action=unassign&document_id=${editingDoc.id}&target_type=${r.target_type}&target_id=${r.target_id}`, {
            method: 'DELETE',
            headers: { Authorization: `Bearer ${token}` },
          });
        }
        if (toAdd.length > 0) {
          await fetch(`${API_URL}/api/documents-gateway.php?action=assign`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
            body: JSON.stringify({ document_id: editingDoc.id, targets: toAdd }),
          });
        }
      } else {
        // Create with initial assignments
        const response = await fetch(`${API_URL}/api/documents-gateway.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({
            club_profile_id: clubId,
            title: modalName.trim(),
            file_path: resolvedFilePath,
            file_name: modalFileName,
            mime_type: modalMimeType,
            link_url: resolvedLinkUrl,
            slot: slotForApi,
            notes: modalNotes.trim() || null,
            is_required: modalIsRequired,
            expires_at: expiresForApi,
            assignments: modalAssignments,
          }),
        });
        const data = await response.json();
        if (!data.success) {
          alert(data.error || 'Failed to add document');
          return;
        }
      }

      resetModal();
      fetchDocuments();
    } catch {
      alert('Failed to save document');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (docId: number) => {
    if (!window.confirm('Are you sure you want to delete this document?')) return;

    try {
      const response = await fetch(
        `${API_URL}/api/documents-gateway.php?id=${docId}`,
        {
          method: 'DELETE',
          headers: { Authorization: `Bearer ${token}` },
        }
      );
      const data = await response.json();
      if (data.success) {
        fetchDocuments();
      } else {
        alert(data.error || 'Failed to delete document');
      }
    } catch {
      alert('Failed to delete document');
    }
  };

  const getDocLink = (doc: ClubDocument): string => {
    return doc.file_path || doc.link_url || '#';
  };

  const summarizeAssignments = (doc: ClubDocument): string => {
    const a = doc.assignments || [];
    if (a.length === 0) return 'Unassigned';
    const counts: Record<string, number> = {};
    for (const x of a) counts[x.target_type] = (counts[x.target_type] || 0) + 1;
    if (counts.club && a.length === counts.club) return 'Club-wide';
    const parts: string[] = [];
    if (counts.club) parts.push('Club-wide');
    if (counts.team) parts.push(`${counts.team} team${counts.team > 1 ? 's' : ''}`);
    if (counts.athlete) parts.push(`${counts.athlete} athlete${counts.athlete > 1 ? 's' : ''}`);
    if (counts.user) parts.push(`${counts.user} ${counts.user > 1 ? 'people' : 'person'}`);
    return parts.join(' · ');
  };

  const toggleAssignment = (target: AssignmentTarget) => {
    const key = `${target.target_type}:${target.target_id}`;
    const exists = modalAssignments.some(a => `${a.target_type}:${a.target_id}` === key);
    if (exists) {
      setModalAssignments(prev => prev.filter(a => `${a.target_type}:${a.target_id}` !== key));
    } else {
      setModalAssignments(prev => [...prev, target]);
    }
  };

  const isAssigned = (type: AssignmentTarget['target_type'], id: number): boolean => {
    return modalAssignments.some(a => a.target_type === type && a.target_id === id);
  };

  if (!clubId) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-gray-500 py-12">
          Please select a club to view the document center.
        </div>
      </main>
    );
  }

  if (loading) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-brand-primary py-12">Loading documents...</div>
      </main>
    );
  }

  if (error) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-red-600 py-12">{error}</div>
      </main>
    );
  }

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title="Document Center"
        subtitle="Manage required compliance documents and custom files for your club"
        actions={
          <Button onClick={() => openAddModal()}>Upload Document</Button>
        }
      />

      {/* Slot Cards Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
        {slots.map((slot) => {
          const hasDocuments = slot.documents.length > 0;
          return (
            <div
              key={slot.key}
              className="border border-brand-secondary rounded-md p-4 bg-white"
            >
              {/* Slot header */}
              <div className="flex items-center justify-between mb-3">
                <span className="text-sm font-bold text-brand-primary uppercase tracking-wide">
                  {slot.label}
                </span>
                {hasDocuments ? (
                  <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                    <svg
                      className="w-3.5 h-3.5 mr-1"
                      fill="currentColor"
                      viewBox="0 0 20 20"
                    >
                      <path
                        fillRule="evenodd"
                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                        clipRule="evenodd"
                      />
                    </svg>
                    Complete
                  </span>
                ) : (
                  <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                    Empty
                  </span>
                )}
              </div>

              {/* Documents list */}
              {hasDocuments ? (
                <div className="space-y-2 mb-3">
                  {slot.documents.map((doc) => (
                    <div
                      key={doc.id}
                      className="flex items-center justify-between text-sm"
                    >
                      <a
                        href={getDocLink(doc)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-brand-primary hover:underline truncate flex-1 mr-2"
                      >
                        <svg
                          className="w-4 h-4 inline mr-1"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                          />
                        </svg>
                        {doc.title}
                      </a>
                      <div className="flex items-center gap-1 mr-2">
                        {doc.is_required && (
                          <span className="px-1.5 py-0.5 text-[10px] font-semibold uppercase rounded bg-amber-100 text-amber-700">Req'd</span>
                        )}
                        <span className="px-1.5 py-0.5 text-[10px] uppercase rounded bg-gray-100 text-gray-600" title={`Assigned to: ${summarizeAssignments(doc)}`}>
                          {summarizeAssignments(doc)}
                        </span>
                      </div>
                      <div className="flex items-center space-x-1 flex-shrink-0">
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => openEditModal(doc)}
                          title="Edit"
                          aria-label="Edit"
                        >
                          <svg
                            className="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                          >
                            <path
                              strokeLinecap="round"
                              strokeLinejoin="round"
                              strokeWidth={2}
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                            />
                          </svg>
                        </Button>
                        <Button
                          variant="danger-link"
                          size="icon"
                          onClick={() => handleDelete(doc.id)}
                          title="Delete"
                          aria-label="Delete"
                        >
                          <svg
                            className="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                          >
                            <path
                              strokeLinecap="round"
                              strokeLinejoin="round"
                              strokeWidth={2}
                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            />
                          </svg>
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-xs text-gray-400 mb-3">
                  No document uploaded yet
                </p>
              )}

              {/* Add button */}
              <Button variant="link" size="sm" onClick={() => openAddModal(slot.key)}>
                + Add
              </Button>
            </div>
          );
        })}
      </div>

      {/* Custom Documents Section */}
      <div className="border-t border-brand-secondary pt-8">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide">
            Custom Documents
          </h2>
          <Button
            onClick={() => {
              resetModal();
              setModalSlot('__custom__');
              setShowModal(true);
            }}
          >
            Add Custom Document
          </Button>
        </div>

        {customDocuments.length === 0 ? (
          <div className="border border-brand-secondary rounded-md p-6 text-center text-gray-500">
            No custom documents yet. Click "Add Custom Document" to get started.
          </div>
        ) : (
          <div className="border border-brand-secondary rounded-md bg-white divide-y divide-brand-secondary">
            {customDocuments.map((doc) => (
              <div
                key={doc.id}
                className="p-4 flex items-center justify-between hover:bg-gray-50"
              >
                <div className="flex-1 min-w-0">
                  <a
                    href={getDocLink(doc)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-brand-primary hover:underline font-semibold flex items-center"
                  >
                    <svg
                      className="w-5 h-5 mr-2 flex-shrink-0"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                      />
                    </svg>
                    <span className="truncate">{doc.title}</span>
                    <svg
                      className="w-3.5 h-3.5 ml-1 flex-shrink-0"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                      />
                    </svg>
                  </a>
                  <div className="flex items-center text-xs text-gray-500 mt-1 space-x-3">
                    <span>
                      Added{' '}
                      {new Date(doc.created_at).toLocaleDateString()}
                    </span>
                    {doc.uploaded_by_name && <span>by {doc.uploaded_by_name}</span>}
                    <span className="px-1.5 py-0.5 text-[10px] uppercase rounded bg-gray-100 text-gray-600">{summarizeAssignments(doc)}</span>
                    {doc.is_required && (
                      <span className="px-1.5 py-0.5 text-[10px] font-semibold uppercase rounded bg-amber-100 text-amber-700">Required</span>
                    )}
                    {doc.expires_at && (
                      <span className="px-1.5 py-0.5 text-[10px] uppercase rounded bg-blue-100 text-blue-700" title={`Expires ${new Date(doc.expires_at).toLocaleDateString()}`}>
                        Expires {new Date(doc.expires_at).toLocaleDateString()}
                      </span>
                    )}
                    {doc.notes && (
                      <span className="italic truncate max-w-xs">
                        {doc.notes}
                      </span>
                    )}
                  </div>
                </div>
                <div className="flex items-center space-x-2 ml-4 flex-shrink-0">
                  <Button variant="link" onClick={() => openEditModal(doc)}>
                    Edit
                  </Button>
                  <Button variant="danger-link" onClick={() => handleDelete(doc.id)}>
                    Delete
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Add/Edit Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
            {/* Modal header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-brand-secondary">
              <h3 className="text-lg font-bold text-brand-primary uppercase">
                {editingDoc ? 'Edit Document' : 'Add Document'}
              </h3>
              <Button variant="ghost" size="icon" onClick={resetModal} aria-label="Close">
                <svg
                  className="w-5 h-5"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </Button>
            </div>

            {/* Modal body */}
            <div className="px-6 py-4 space-y-4">
              {/* Slot selector */}
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-1 uppercase">
                  Category
                </label>
                <select
                  value={modalSlot}
                  onChange={(e) => setModalSlot(e.target.value)}
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                >
                  <option value="">-- Select a category --</option>
                  {DEFAULT_SLOTS.map((s) => (
                    <option key={s.key} value={s.key}>
                      {s.label}
                    </option>
                  ))}
                  <option value="__custom__">Custom</option>
                </select>
              </div>

              {/* Document name */}
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-1 uppercase">
                  Document Name *
                </label>
                <input
                  type="text"
                  value={modalName}
                  onChange={(e) => setModalName(e.target.value)}
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  placeholder="e.g., Background Check Certificate"
                />
              </div>

              {/* Tabs: Upload File | Paste Link */}
              <div>
                <div className="flex border-b border-brand-secondary">
                  {/* Upload is offered only while editing a document that already has a
                      file. New uploads land on the dyno's local disk, which is wiped on
                      restart, and are served with no login — decision 14 (2026-09-02):
                      links only until durable, authorised storage ships (roadmap Phase 5). */}
                  {editingDoc?.file_path && (
                  <button
                      onClick={() => setModalTab('upload')}
                      className={`px-4 py-2 text-sm font-semibold uppercase ${
                        modalTab === 'upload'
                          ? 'text-brand-primary border-b-2 border-brand-primary'
                          : 'text-gray-500 hover:text-brand-primary'
                      }`}
                    >
                      Upload File
                    </button>
                  )}
                  <button
                    onClick={() => setModalTab('link')}
                    className={`px-4 py-2 text-sm font-semibold uppercase ${
                      modalTab === 'link'
                        ? 'text-brand-primary border-b-2 border-brand-primary'
                        : 'text-gray-500 hover:text-brand-primary'
                    }`}
                  >
                    Paste Link
                  </button>
                </div>

                <div className="mt-3">
                  {modalTab === 'upload' ? (
                    <div>
                      {modalFileUrl ? (
                        <div className="flex items-center justify-between bg-green-50 border border-green-200 rounded-md p-3">
                          <span className="text-sm text-green-700 truncate flex-1">
                            File uploaded successfully
                          </span>
                          <Button variant="danger-link" className="ml-2" onClick={() => setModalFileUrl('')}>
                            Remove
                          </Button>
                        </div>
                      ) : (
                        <div className="relative">
                          <input
                            type="file"
                            onChange={handleFileUpload}
                            disabled={uploading}
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.jpg,.jpeg,.png,.gif,.webp"
                            className="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border file:border-brand-secondary file:text-sm file:font-semibold file:bg-brand-primary file:text-white hover:file:bg-brand-primary-hover"
                          />
                          {uploading && (
                            <div className="mt-2 text-sm text-brand-primary">
                              Uploading...
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  ) : (
                    <input
                      type="url"
                      value={modalLinkUrl}
                      onChange={(e) => setModalLinkUrl(e.target.value)}
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      placeholder="https://drive.google.com/..."
                    />
                  )}
                </div>
              </div>

              {/* Notes */}
              <div>
                <label className="block text-brand-primary text-sm font-medium mb-1 uppercase">
                  Notes (optional)
                </label>
                <textarea
                  value={modalNotes}
                  onChange={(e) => setModalNotes(e.target.value)}
                  rows={2}
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  placeholder="Any additional notes..."
                />
              </div>

              {/* Required + Expires */}
              <div className="grid grid-cols-2 gap-3">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={modalIsRequired}
                    onChange={(e) => setModalIsRequired(e.target.checked)}
                    className="w-4 h-4"
                  />
                  <span className="text-sm font-medium text-brand-primary uppercase">Required</span>
                </label>
                <div>
                  <label className="block text-xs font-medium text-brand-primary uppercase mb-1">Expires (optional)</label>
                  <input
                    type="date"
                    value={modalExpiresAt}
                    onChange={(e) => setModalExpiresAt(e.target.value)}
                    className="w-full bg-white text-sm text-brand-primary border border-brand-secondary rounded-md px-3 py-1.5 focus:outline-none focus:border-brand-accent"
                  />
                </div>
              </div>

              {/* Assignment Picker */}
              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="block text-brand-primary text-sm font-medium uppercase">
                    Assigned To ({modalAssignments.length})
                  </label>
                  <Button variant="link" size="sm" onClick={() => setShowAssignmentPicker(!showAssignmentPicker)}>
                    {showAssignmentPicker ? 'Hide' : 'Edit assignments'}
                  </Button>
                </div>

                {/* Current chips */}
                {modalAssignments.length === 0 ? (
                  <p className="text-xs text-gray-400 italic">Not assigned to anyone — only club admins will see this doc.</p>
                ) : (
                  <div className="flex flex-wrap gap-1">
                    {modalAssignments.map((a) => {
                      let label = '';
                      if (a.target_type === 'club') label = 'Club-wide';
                      else if (a.target_type === 'team') label = `Team: ${availableTeams.find(t => t.id === a.target_id)?.name || a.target_id}`;
                      else if (a.target_type === 'athlete') {
                        const ath = availableAthletes.find(x => x.id === a.target_id);
                        label = `Athlete: ${ath ? `${ath.first_name} ${ath.last_name}` : a.target_id}`;
                      } else if (a.target_type === 'user') {
                        const u = availableCoaches.find(x => x.id === a.target_id);
                        label = `Coach: ${u ? `${u.first_name} ${u.last_name}` : a.target_id}`;
                      }
                      return (
                        <span key={`${a.target_type}-${a.target_id}`} className="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded bg-blue-100 text-blue-800">
                          {label}
                          <button
                            type="button"
                            onClick={() => toggleAssignment(a)}
                            className="hover:text-red-600"
                            title="Remove"
                          >
                            ×
                          </button>
                        </span>
                      );
                    })}
                  </div>
                )}

                {showAssignmentPicker && (
                  <div className="mt-3 border border-brand-secondary rounded-md p-3 max-h-72 overflow-y-auto bg-gray-50 space-y-3">
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={!!clubId && isAssigned('club', clubId)}
                        onChange={() => clubId && toggleAssignment({ target_type: 'club', target_id: clubId })}
                      />
                      <span className="font-semibold uppercase text-xs">Club-wide</span>
                    </label>

                    {availableTeams.length > 0 && (
                      <details>
                        <summary className="cursor-pointer text-xs font-semibold uppercase text-brand-primary">Teams ({availableTeams.length})</summary>
                        <div className="mt-2 grid grid-cols-2 gap-1">
                          {availableTeams.map(t => (
                            <label key={t.id} className="flex items-center gap-1 text-sm">
                              <input
                                type="checkbox"
                                checked={isAssigned('team', t.id)}
                                onChange={() => toggleAssignment({ target_type: 'team', target_id: t.id })}
                              />
                              <span className="truncate">{t.name}</span>
                            </label>
                          ))}
                        </div>
                      </details>
                    )}

                    {coachesError && (
                      <div className="text-xs text-red-700 bg-red-50 border border-red-200 rounded px-2 py-1.5">
                        {coachesError} You can still assign this document to the club, teams or athletes.
                      </div>
                    )}

                    {availableCoaches.length > 0 && (
                      <details>
                        <summary className="cursor-pointer text-xs font-semibold uppercase text-brand-primary">Coaches / Volunteers ({availableCoaches.length})</summary>
                        <div className="mt-2 grid grid-cols-2 gap-1">
                          {availableCoaches.map(u => (
                            <label key={u.id} className="flex items-center gap-1 text-sm">
                              <input
                                type="checkbox"
                                checked={isAssigned('user', u.id)}
                                onChange={() => toggleAssignment({ target_type: 'user', target_id: u.id })}
                              />
                              <span className="truncate">{u.first_name} {u.last_name}</span>
                            </label>
                          ))}
                        </div>
                      </details>
                    )}

                    {availableAthletes.length > 0 && (
                      <details>
                        <summary className="cursor-pointer text-xs font-semibold uppercase text-brand-primary">Athletes ({availableAthletes.length})</summary>
                        <div className="mt-2 grid grid-cols-2 gap-1 max-h-40 overflow-y-auto">
                          {availableAthletes.map(a => (
                            <label key={a.id} className="flex items-center gap-1 text-sm">
                              <input
                                type="checkbox"
                                checked={isAssigned('athlete', a.id)}
                                onChange={() => toggleAssignment({ target_type: 'athlete', target_id: a.id })}
                              />
                              <span className="truncate">{a.first_name} {a.last_name}</span>
                            </label>
                          ))}
                        </div>
                      </details>
                    )}
                  </div>
                )}
              </div>
            </div>

            {/* Modal footer */}
            <div className="flex justify-end space-x-3 px-6 py-4 border-t border-brand-secondary">
              <Button variant="secondary" onClick={resetModal}>
                Cancel
              </Button>
              <Button onClick={handleSave} loading={saving} disabled={uploading}>
                {editingDoc ? 'Update Document' : 'Save Document'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
};

export default ClubDocumentCenter;

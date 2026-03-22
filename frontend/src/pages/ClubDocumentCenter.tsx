import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface ClubDocument {
  id: number;
  club_profile_id: number;
  name: string;
  url: string | null;
  slot: string | null;
  link_url: string | null;
  file_type: string | null;
  notes: string | null;
  created_by: number;
  creator_name?: string;
  created_at: string;
  updated_at: string | null;
}

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
  const { user } = useAuth();
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
  const [modalNotes, setModalNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);

  const token = localStorage.getItem('auth_token');

  const fetchDocuments = useCallback(async () => {
    if (!clubId) return;
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(
        `${API_URL}/api/club-document-center.php?club_profile_id=${clubId}`,
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
    setModalNotes('');
  };

  const openAddModal = (slotKey?: string) => {
    resetModal();
    setModalSlot(slotKey || '');
    if (slotKey) {
      const slotDef = DEFAULT_SLOTS.find((s) => s.key === slotKey);
      if (slotDef) setModalName(slotDef.label);
    }
    setShowModal(true);
  };

  const openEditModal = (doc: ClubDocument) => {
    resetModal();
    setEditingDoc(doc);
    setModalSlot(doc.slot || '');
    setModalName(doc.name);
    setModalNotes(doc.notes || '');
    if (doc.url) {
      setModalTab('upload');
      setModalFileUrl(doc.url);
    } else {
      setModalTab('link');
      setModalLinkUrl(doc.link_url || '');
    }
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

    const resolvedUrl = modalTab === 'upload' ? modalFileUrl : null;
    const resolvedLinkUrl = modalTab === 'link' ? modalLinkUrl : null;

    if (!resolvedUrl && !resolvedLinkUrl) {
      alert('Please upload a file or paste a link');
      return;
    }

    setSaving(true);
    try {
      if (editingDoc) {
        // Update
        const response = await fetch(`${API_URL}/api/club-document-center.php`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({
            id: editingDoc.id,
            name: modalName.trim(),
            url: resolvedUrl,
            link_url: resolvedLinkUrl,
            notes: modalNotes.trim() || null,
            slot: modalSlot || null,
          }),
        });
        const data = await response.json();
        if (!data.success) {
          alert(data.error || 'Failed to update document');
          return;
        }
      } else {
        // Create
        const response = await fetch(`${API_URL}/api/club-document-center.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({
            club_profile_id: clubId,
            slot: modalSlot || null,
            name: modalName.trim(),
            url: resolvedUrl,
            link_url: resolvedLinkUrl,
            notes: modalNotes.trim() || null,
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
        `${API_URL}/api/club-document-center.php?id=${docId}`,
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
    return doc.url || doc.link_url || '#';
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
      {/* Header */}
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">
            Document Center
          </h1>
          <p className="text-sm text-gray-600 mt-1">
            Manage required compliance documents and custom files for your club
          </p>
        </div>
        <button
          onClick={() => openAddModal()}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
        >
          Upload Document
        </button>
      </div>

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
                        {doc.name}
                      </a>
                      <div className="flex items-center space-x-1 flex-shrink-0">
                        <button
                          onClick={() => openEditModal(doc)}
                          className="text-gray-400 hover:text-brand-primary p-1"
                          title="Edit"
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
                        </button>
                        <button
                          onClick={() => handleDelete(doc.id)}
                          className="text-gray-400 hover:text-red-600 p-1"
                          title="Delete"
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
                        </button>
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
              <button
                onClick={() => openAddModal(slot.key)}
                className="text-xs text-brand-primary hover:underline font-semibold uppercase"
              >
                + Add
              </button>
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
          <button
            onClick={() => {
              resetModal();
              setModalSlot('__custom__');
              setShowModal(true);
            }}
            className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
          >
            Add Custom Document
          </button>
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
                    <span className="truncate">{doc.name}</span>
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
                    {doc.creator_name && <span>by {doc.creator_name}</span>}
                    {doc.notes && (
                      <span className="italic truncate max-w-xs">
                        {doc.notes}
                      </span>
                    )}
                  </div>
                </div>
                <div className="flex items-center space-x-2 ml-4 flex-shrink-0">
                  <button
                    onClick={() => openEditModal(doc)}
                    className="text-brand-primary hover:text-brand-primary-hover font-semibold text-sm uppercase"
                  >
                    Edit
                  </button>
                  <button
                    onClick={() => handleDelete(doc.id)}
                    className="text-red-600 hover:text-red-700 font-semibold text-sm uppercase"
                  >
                    Delete
                  </button>
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
              <button
                onClick={resetModal}
                className="text-gray-400 hover:text-gray-600"
              >
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
              </button>
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
                          <button
                            onClick={() => setModalFileUrl('')}
                            className="text-red-500 hover:text-red-700 text-sm font-medium ml-2"
                          >
                            Remove
                          </button>
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
            </div>

            {/* Modal footer */}
            <div className="flex justify-end space-x-3 px-6 py-4 border-t border-brand-secondary">
              <button
                onClick={resetModal}
                className="bg-gray-200 text-gray-700 px-4 py-2 rounded-md font-semibold uppercase hover:bg-gray-300 text-sm"
              >
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={saving || uploading}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50"
              >
                {saving
                  ? 'Saving...'
                  : editingDoc
                  ? 'Update Document'
                  : 'Save Document'}
              </button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
};

export default ClubDocumentCenter;

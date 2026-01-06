import React, { useState, useEffect } from 'react';
import { useOrg } from '../contexts/OrgContext';

interface ClubDocument {
  id: number;
  name: string;
  url: string;
  created_by: number;
  creator_name?: string;
  created_at: string;
}

const ClubDocuments: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { activeContext } = useOrg();
  const clubId = activeContext?.scope_id;

  const [documents, setDocuments] = useState<ClubDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editingDoc, setEditingDoc] = useState<ClubDocument | null>(null);
  const [formData, setFormData] = useState({ name: '', url: '' });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (clubId) {
      fetchDocuments();
    }
  }, [clubId]);

  const fetchDocuments = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/club-documents-gateway.php?clubId=${clubId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      if (data.success) {
        setDocuments(data.documents || []);
      }
    } catch (error) {
      console.error('Error fetching documents:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);

    try {
      const token = localStorage.getItem('auth_token');
      const method = editingDoc ? 'PUT' : 'POST';
      const body = editingDoc
        ? { id: editingDoc.id, ...formData }
        : { clubId, ...formData };

      const response = await fetch(`${API_URL}/api/club-documents-gateway.php`, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(body)
      });

      if (response.ok) {
        fetchDocuments();
        setShowForm(false);
        setEditingDoc(null);
        setFormData({ name: '', url: '' });
      } else {
        alert('Failed to save document');
      }
    } catch (error) {
      alert('Failed to save document');
    } finally {
      setSaving(false);
    }
  };

  const handleEdit = (doc: ClubDocument) => {
    setEditingDoc(doc);
    setFormData({ name: doc.name, url: doc.url });
    setShowForm(true);
  };

  const handleDelete = async (docId: number) => {
    if (!window.confirm('Are you sure you want to delete this document?')) return;

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/club-documents-gateway.php?id=${docId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      if (response.ok) {
        fetchDocuments();
      } else {
        alert('Failed to delete document');
      }
    } catch (error) {
      alert('Failed to delete document');
    }
  };

  const handleCancel = () => {
    setShowForm(false);
    setEditingDoc(null);
    setFormData({ name: '', url: '' });
  };

  if (loading) {
    return <div className="text-center py-8 text-brand-primary">Loading documents...</div>;
  }

  return (
    <div className="space-y-6">
      {/* Header with Add button */}
      <div className="flex justify-between items-center">
        <div>
          <h2 className="text-xl font-semibold text-brand-primary uppercase">Club Documents</h2>
          <p className="text-gray-600 text-sm mt-1">
            Share important documents and links with your club members
          </p>
        </div>
        {!showForm && (
          <button
            onClick={() => setShowForm(true)}
            className="bg-brand-primary text-white px-4 py-2 rounded-md font-semibold uppercase hover:bg-brand-primary-hover"
          >
            Add Document
          </button>
        )}
      </div>

      {/* Add/Edit Form */}
      {showForm && (
        <div className="bg-brand-secondary border border-brand-secondary rounded-md p-6">
          <h3 className="text-lg font-semibold text-brand-primary mb-4 uppercase">
            {editingDoc ? 'Edit Document' : 'Add New Document'}
          </h3>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Document Name *
              </label>
              <input
                type="text"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                placeholder="e.g., Code of Conduct"
                required
              />
            </div>
            <div>
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Document URL *
              </label>
              <input
                type="url"
                value={formData.url}
                onChange={(e) => setFormData({ ...formData, url: e.target.value })}
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                placeholder="https://docs.google.com/..."
                required
              />
            </div>
            <div className="flex space-x-3">
              <button
                type="submit"
                disabled={saving}
                className="bg-brand-primary text-white px-4 py-2 rounded-md font-semibold uppercase hover:bg-brand-primary-hover disabled:opacity-50"
              >
                {saving ? 'Saving...' : editingDoc ? 'Update' : 'Add Document'}
              </button>
              <button
                type="button"
                onClick={handleCancel}
                className="bg-gray-200 text-gray-700 px-4 py-2 rounded-md font-semibold uppercase hover:bg-gray-300"
              >
                Cancel
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Documents List */}
      <div className="bg-white rounded-md border border-brand-secondary overflow-hidden">
        {documents.length === 0 ? (
          <div className="text-center py-8 text-gray-600">
            No documents added yet. Click "Add Document" to get started.
          </div>
        ) : (
          <div className="divide-y divide-brand-secondary">
            {documents.map((doc) => (
              <div key={doc.id} className="p-4 hover:bg-gray-50 flex items-center justify-between">
                <div className="flex-1">
                  <a
                    href={doc.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-brand-primary font-semibold hover:text-brand-primary-hover flex items-center"
                  >
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    {doc.name}
                    <svg className="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                  </a>
                  <p className="text-xs text-gray-500 mt-1">
                    Added {new Date(doc.created_at).toLocaleDateString()}
                    {doc.creator_name && ` by ${doc.creator_name}`}
                  </p>
                </div>
                <div className="flex space-x-2">
                  <button
                    onClick={() => handleEdit(doc)}
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
    </div>
  );
};

export default ClubDocuments;

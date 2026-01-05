import React, { useState, useEffect } from 'react';

interface LeagueDocumentsProps {
  leagueId: number;
}

interface Document {
  id: number;
  name: string;
  url: string;
  created_at: string;
  updated_at: string;
  created_by_name: string;
}

const LeagueDocuments: React.FC<LeagueDocumentsProps> = ({ leagueId }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [documents, setDocuments] = useState<Document[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [editingDocument, setEditingDocument] = useState<Document | null>(null);
  const [formData, setFormData] = useState({
    name: '',
    url: ''
  });

  useEffect(() => {
    fetchDocuments();
  }, [leagueId]);

  const fetchDocuments = async () => {
    try {
      setLoading(true);
      setError(null);

      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/league-documents-gateway.php?leagueId=${leagueId}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      if (!response.ok) {
        throw new Error('Failed to fetch documents');
      }

      const data = await response.json();
      setDocuments(data.documents || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load documents');
    } finally {
      setLoading(false);
    }
  };

  const handleAddDocument = () => {
    setEditingDocument(null);
    setFormData({ name: '', url: '' });
    setShowForm(true);
  };

  const handleEditDocument = (doc: Document) => {
    setEditingDocument(doc);
    setFormData({ name: doc.name, url: doc.url });
    setShowForm(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!formData.name.trim() || !formData.url.trim()) {
      alert('Please fill in all fields');
      return;
    }

    try {
      const token = localStorage.getItem('auth_token');
      const isEditing = editingDocument !== null;

      const url = isEditing
        ? `${API_URL}/api/league-documents-gateway.php?action=update`
        : `${API_URL}/api/league-documents-gateway.php?action=create`;

      const response = await fetch(url, {
        method: isEditing ? 'PUT' : 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          ...(isEditing && { id: editingDocument.id }),
          leagueId,
          name: formData.name,
          url: formData.url
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to save document');
      }

      setShowForm(false);
      setFormData({ name: '', url: '' });
      setEditingDocument(null);
      fetchDocuments();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to save document');
    }
  };

  const handleDeleteDocument = async (doc: Document) => {
    if (!window.confirm(`Are you sure you want to delete "${doc.name}"?`)) {
      return;
    }

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/league-documents-gateway.php?action=delete`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          id: doc.id,
          leagueId
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to delete document');
      }

      fetchDocuments();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to delete document');
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center py-12">
        <div className="text-brand-primary">Loading documents...</div>
      </div>
    );
  }

  return (
    <div className="bg-white shadow-sm rounded-lg">
      {error && (
        <div className="bg-red-50 border-l-4 border-red-400 p-4 mb-4">
          <p className="text-red-700">{error}</p>
        </div>
      )}

      {/* Header */}
      <div className="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
        <div>
          <h2 className="text-lg font-semibold text-brand-primary">Document Hub</h2>
          <p className="text-sm text-gray-600 mt-1">
            Manage important documents and resources for your league
          </p>
        </div>
        <button
          onClick={handleAddDocument}
          className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary uppercase font-medium text-sm"
        >
          Add Document
        </button>
      </div>

      {/* Documents List */}
      <div className="p-6">
        {documents.length === 0 ? (
          <div className="text-center py-12">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
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
            <h3 className="mt-2 text-sm font-medium text-gray-900">No documents</h3>
            <p className="mt-1 text-sm text-gray-500">
              Get started by adding your first document
            </p>
            <div className="mt-6">
              <button
                onClick={handleAddDocument}
                className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary uppercase font-medium text-sm"
              >
                Add Document
              </button>
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {documents.map((doc) => (
              <div
                key={doc.id}
                className="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow"
              >
                <div className="flex items-start justify-between">
                  <div className="flex-1 min-w-0">
                    <h3 className="text-sm font-medium text-brand-primary truncate">
                      {doc.name}
                    </h3>
                    <a
                      href={doc.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-xs text-blue-600 hover:text-blue-800 truncate block mt-1"
                    >
                      {doc.url}
                    </a>
                    <p className="text-xs text-gray-500 mt-2">
                      Added by {doc.created_by_name}
                    </p>
                  </div>
                  <div className="ml-2 flex space-x-2">
                    <button
                      onClick={() => handleEditDocument(doc)}
                      className="text-gray-400 hover:text-brand-primary-hover"
                      title="Edit"
                    >
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          strokeWidth={2}
                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                        />
                      </svg>
                    </button>
                    <button
                      onClick={() => handleDeleteDocument(doc)}
                      className="text-gray-400 hover:text-red-600"
                      title="Delete"
                    >
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Add/Edit Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div className="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
              <h2 className="text-xl font-semibold text-brand-primary">
                {editingDocument ? 'Edit Document' : 'Add Document'}
              </h2>
              <button
                onClick={() => {
                  setShowForm(false);
                  setEditingDocument(null);
                  setFormData({ name: '', url: '' });
                }}
                className="text-gray-400 hover:text-gray-600"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <form onSubmit={handleSubmit} className="px-6 py-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Document Name *
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g., League Rules, Registration Form"
                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Document URL *
                </label>
                <input
                  type="url"
                  value={formData.url}
                  onChange={(e) => setFormData({ ...formData, url: e.target.value })}
                  placeholder="https://example.com/document.pdf"
                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-brand-accent"
                  required
                />
                <p className="mt-1 text-xs text-gray-500">
                  Enter the full URL to the document (Google Drive, Dropbox, etc.)
                </p>
              </div>

              <div className="flex justify-end space-x-3 pt-4 border-t">
                <button
                  type="button"
                  onClick={() => {
                    setShowForm(false);
                    setEditingDocument(null);
                    setFormData({ name: '', url: '' });
                  }}
                  className="px-4 py-2 text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50 uppercase font-medium text-sm"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-brand-primary text-white rounded-md hover:bg-brand-primary uppercase font-medium text-sm"
                >
                  {editingDocument ? 'Update' : 'Add'} Document
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default LeagueDocuments;

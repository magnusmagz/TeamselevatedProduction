import React, { useState, useEffect, useCallback } from 'react';
import { deriveDocumentStatus, formatDocumentDate } from '../utils/documentStatus';

interface Document {
  id: number;
  title: string;
  description?: string | null;
  file_path?: string | null;
  link_url?: string | null;
  file_name?: string | null;
  mime_type?: string | null;
  slot?: string | null;
  is_required: boolean;
  expires_at?: string | null;
  notes?: string | null;
  uploaded_by_name?: string;
  created_at: string;
}

interface DocumentManagerProps {
  athleteId: string;
  athleteName?: string;
}

const DocumentManager: React.FC<DocumentManagerProps> = ({ athleteId, athleteName }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const [documents, setDocuments] = useState<Document[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchDocuments = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(
        `${API_URL}/api/documents-gateway.php?action=for-athlete&athlete_id=${athleteId}`,
        { headers: token ? { Authorization: `Bearer ${token}` } : {} }
      );
      const data = await response.json();
      if (data.success && Array.isArray(data.documents)) {
        setDocuments(data.documents);
      } else {
        setDocuments([]);
        if (!data.success) setError(data.error || 'Failed to load documents');
      }
    } catch {
      setError('Failed to load documents');
    } finally {
      setLoading(false);
    }
  }, [API_URL, athleteId]);

  useEffect(() => {
    fetchDocuments();
  }, [fetchDocuments]);

  if (loading) {
    return <div className="text-center text-brand-primary py-8">Loading documents...</div>;
  }

  if (error) {
    return <div className="text-center text-red-600 py-8">{error}</div>;
  }

  return (
    <div className="bg-white border border-brand-secondary rounded-md p-4">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-bold text-brand-primary uppercase">
          Documents{athleteName ? ` for ${athleteName}` : ''}
        </h3>
        <span className="text-xs text-gray-500">
          {documents.length} document{documents.length === 1 ? '' : 's'}
        </span>
      </div>

      {documents.length === 0 ? (
        <p className="text-sm text-gray-500 italic py-6 text-center">
          No documents assigned to this athlete yet. Documents are managed from the Document Center.
        </p>
      ) : (
        <div className="divide-y divide-brand-secondary">
          {documents.map((doc) => {
            const status = deriveDocumentStatus(doc.expires_at);
            const href = doc.file_path || doc.link_url || '#';
            return (
              <a
                key={doc.id}
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                className="block py-3 hover:bg-gray-50 -mx-4 px-4"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="font-semibold text-brand-primary truncate">{doc.title}</p>
                      {doc.is_required && (
                        <span className="px-1.5 py-0.5 text-[10px] font-semibold uppercase rounded bg-amber-100 text-amber-700">
                          Required
                        </span>
                      )}
                      {status === 'expired' && (
                        <span className="px-1.5 py-0.5 text-[10px] font-semibold uppercase rounded bg-red-100 text-red-700">
                          Expired
                        </span>
                      )}
                      {status === 'expiring_soon' && (
                        <span className="px-1.5 py-0.5 text-[10px] font-semibold uppercase rounded bg-yellow-100 text-yellow-800">
                          Expiring soon
                        </span>
                      )}
                    </div>
                    {doc.slot && (
                      <p className="text-xs text-gray-500 mt-0.5 capitalize">
                        {doc.slot.replace(/_/g, ' ')}
                      </p>
                    )}
                    <div className="flex items-center gap-3 mt-1 text-xs text-gray-500">
                      <span>Added {formatDocumentDate(doc.created_at)}</span>
                      {doc.uploaded_by_name && <span>by {doc.uploaded_by_name}</span>}
                      {doc.expires_at && (
                        <span>Expires {formatDocumentDate(doc.expires_at)}</span>
                      )}
                    </div>
                    {doc.notes && (
                      <p className="text-xs text-gray-600 italic mt-1">{doc.notes}</p>
                    )}
                  </div>
                  <svg
                    className="w-4 h-4 text-gray-400 flex-shrink-0 mt-1"
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
                </div>
              </a>
            );
          })}
        </div>
      )}
    </div>
  );
};

export default DocumentManager;

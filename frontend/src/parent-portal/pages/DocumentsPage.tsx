import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { useParentAthletes } from '../hooks/useParentAthletes';
import { ParentHeader } from '../components/ParentHeader';
import { AthleteSelector } from '../components/AthleteSelector';

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

function deriveStatus(expiresAt?: string | null): 'valid' | 'expiring_soon' | 'expired' {
  if (!expiresAt) return 'valid';
  const now = Date.now();
  const exp = new Date(expiresAt).getTime();
  if (exp < now) return 'expired';
  if (exp - now < 30 * 24 * 60 * 60 * 1000) return 'expiring_soon';
  return 'valid';
}

export const DocumentsPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { id } = useParams<{ id: string }>();
  const { athletes, selectedAthleteId, selectAthlete } = useParentAthletes();

  const athleteIdFromUrl = id ? parseInt(id) : null;
  const activeAthleteId = athleteIdFromUrl || selectedAthleteId;

  const [documents, setDocuments] = useState<Document[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<'all' | 'expiring' | 'expired'>('all');

  useEffect(() => {
    const fetchDocuments = async () => {
      if (!activeAthleteId) {
        setDocuments([]);
        setLoading(false);
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(
          `${API_URL}/api/documents-gateway.php?action=for-athlete&athlete_id=${activeAthleteId}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await response.json();

        if (data.success && data.documents) {
          setDocuments(data.documents);
        } else {
          setDocuments([]);
        }
      } catch (err) {
        setError('Failed to load documents');
      } finally {
        setLoading(false);
      }
    };

    fetchDocuments();
  }, [API_URL, activeAthleteId]);

  const handleAthleteSelect = (id: number | null) => {
    selectAthlete(id);
  };

  const filteredDocuments = documents.filter((doc) => {
    const status = deriveStatus(doc.expires_at);
    if (filter === 'expiring') return status === 'expiring_soon';
    if (filter === 'expired') return status === 'expired';
    return true;
  });

  const getStatusBadge = (status: 'valid' | 'expiring_soon' | 'expired') => {
    const styles = {
      valid: 'bg-green-100 text-green-800',
      expiring_soon: 'bg-yellow-100 text-yellow-800',
      expired: 'bg-red-100 text-red-800',
    };
    const labels = {
      valid: 'Valid',
      expiring_soon: 'Expiring Soon',
      expired: 'Expired',
    };
    return (
      <span className={`px-2 py-0.5 text-xs font-medium rounded ${styles[status]}`}>
        {labels[status]}
      </span>
    );
  };

  const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  const formatFileSize = (bytes?: number) => {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  };

  const getDocumentIcon = (type: string) => {
    const iconClass = 'w-8 h-8';
    if (type.includes('pdf')) {
      return (
        <svg className={`${iconClass} text-red-500`} fill="currentColor" viewBox="0 0 24 24">
          <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1 2l5 5h-5V4zm-3 9v5h-1v-5h1zm2 0v5h1l2-2.5L13 13h-1zm5 1v4h-1v-3l-1 1.5V15l1-1z" />
        </svg>
      );
    }
    if (type.includes('image')) {
      return (
        <svg className={`${iconClass} text-blue-500`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      );
    }
    return (
      <svg className={`${iconClass} text-gray-500`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
      </svg>
    );
  };

  const activeAthlete = athletes.find((a) => a.id === activeAthleteId);

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader
        title={activeAthlete ? `${activeAthlete.first_name}'s Documents` : 'Documents'}
        showBack
        rightElement={
          !athleteIdFromUrl && athletes.length > 1 ? (
            <AthleteSelector
              athletes={athletes}
              selectedAthleteId={activeAthleteId}
              onSelect={handleAthleteSelect}
              showAllOption={false}
            />
          ) : undefined
        }
      />

      <div className="pt-14 pb-4">
        {/* Filter Tabs */}
        <div className="flex bg-white border-b border-gray-200">
          {(['all', 'expiring', 'expired'] as const).map((tab) => (
            <button
              key={tab}
              onClick={() => setFilter(tab)}
              className={`flex-1 py-3 text-sm font-medium border-b-2 transition-colors ${
                filter === tab
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-gray-500'
              }`}
            >
              {tab.charAt(0).toUpperCase() + tab.slice(1).replace('_', ' ')}
            </button>
          ))}
        </div>

        {/* Loading State */}
        {loading && (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
          </div>
        )}

        {/* Error State */}
        {error && (
          <div className="mx-4 mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
            {error}
          </div>
        )}

        {/* No Athlete Selected */}
        {!loading && !activeAthleteId && (
          <div className="text-center py-12 px-4">
            <p className="text-gray-500">Please select an athlete to view documents.</p>
          </div>
        )}

        {/* Empty State */}
        {!loading && !error && activeAthleteId && filteredDocuments.length === 0 && (
          <div className="text-center py-12 px-4">
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
                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">
              {filter === 'all' ? 'No Documents' : `No ${filter.replace('_', ' ')} Documents`}
            </h3>
            <p className="mt-1 text-sm text-gray-500">
              {filter === 'all'
                ? 'No documents have been uploaded yet.'
                : 'No documents match this filter.'}
            </p>
          </div>
        )}

        {/* Documents List */}
        {!loading && !error && filteredDocuments.length > 0 && (
          <div className="px-4 py-4 space-y-3">
            {filteredDocuments.map((doc) => {
              const status = deriveStatus(doc.expires_at);
              const href = doc.file_path || doc.link_url || '#';
              return (
              <a
                key={doc.id}
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                className="block bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:bg-gray-50 transition-colors"
              >
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0">{getDocumentIcon(doc.mime_type || '')}</div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-2">
                      <p className="font-medium text-gray-900 truncate">{doc.title}</p>
                      {getStatusBadge(status)}
                    </div>
                    {doc.slot && (
                      <p className="text-sm text-brand-primary mt-0.5">{doc.slot.replace(/_/g, ' ')}</p>
                    )}
                    <div className="flex items-center gap-3 mt-2 text-xs text-gray-500">
                      <span>Uploaded {formatDate(doc.created_at)}</span>
                      {doc.is_required && (
                        <span className="text-amber-700 font-medium">Required</span>
                      )}
                    </div>
                    {doc.expires_at && (
                      <p
                        className={`text-xs mt-1 ${
                          status === 'expired'
                            ? 'text-red-600'
                            : status === 'expiring_soon'
                            ? 'text-yellow-600'
                            : 'text-gray-500'
                        }`}
                      >
                        {status === 'expired' ? 'Expired' : 'Expires'}{' '}
                        {formatDate(doc.expires_at)}
                      </p>
                    )}
                  </div>
                  <svg
                    className="w-5 h-5 text-gray-400 flex-shrink-0"
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
    </div>
  );
};

export default DocumentsPage;

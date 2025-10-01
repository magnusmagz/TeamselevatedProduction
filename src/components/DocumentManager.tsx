import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';

interface DocumentType {
  type_id: number;
  type_code: string;
  display_name: string;
  description: string;
  is_required: boolean;
  document_id?: number;
  document_name?: string;
  file_path?: string;
  upload_date?: string;
  expires_date?: string;
  is_verified?: boolean;
  notes?: string;
  status: 'missing' | 'expired' | 'expiring_soon' | 'pending_verification' | 'valid';
}

interface DocumentManagerProps {
  athleteId: string;
  athleteName?: string;
}

const DocumentManager: React.FC<DocumentManagerProps> = ({ athleteId, athleteName }) => {
  const [documents, setDocuments] = useState<DocumentType[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [selectedType, setSelectedType] = useState<number | null>(null);
  const [expiresDate, setExpiresDate] = useState('');
  const [notes, setNotes] = useState('');
  const [uploading, setUploading] = useState(false);

  useEffect(() => {
    fetchDocuments();
  }, [athleteId]);

  const fetchDocuments = async () => {
    try {
      const response = await fetch(`http://localhost:3003/api/athletes/${athleteId}/documents`);
      const data = await response.json();
      if (data.success) {
        setDocuments(data.documents);
      }
    } catch (error) {
      console.error('Failed to fetch documents:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    if (event.target.files && event.target.files[0]) {
      setSelectedFile(event.target.files[0]);
    }
  };

  const handleUpload = async (typeId: number) => {
    if (!selectedFile) {
      alert('Please select a file');
      return;
    }

    setUploading(true);
    const formData = new FormData();
    formData.append('document', selectedFile);
    formData.append('document_type_id', typeId.toString());
    if (expiresDate) {
      formData.append('expires_date', expiresDate);
    }
    if (notes) {
      formData.append('notes', notes);
    }

    try {
      const response = await fetch(`http://localhost:3003/api/athletes/${athleteId}/documents`, {
        method: 'POST',
        body: formData
      });

      const data = await response.json();
      if (data.success) {
        alert('Document uploaded successfully');
        fetchDocuments();
        setSelectedFile(null);
        setSelectedType(null);
        setExpiresDate('');
        setNotes('');
      } else {
        alert(data.error || 'Upload failed');
      }
    } catch (error) {
      alert('Upload failed');
    } finally {
      setUploading(false);
    }
  };

  const handleDelete = async (documentId: number) => {
    if (!window.confirm('Are you sure you want to delete this document?')) {
      return;
    }

    try {
      const response = await fetch(`http://localhost:3003/api/documents/${documentId}`, {
        method: 'DELETE'
      });

      const data = await response.json();
      if (data.success) {
        alert('Document deleted');
        fetchDocuments();
      }
    } catch (error) {
      alert('Delete failed');
    }
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'valid': return 'bg-green-100 text-green-800';
      case 'expired': return 'bg-red-100 text-red-800';
      case 'expiring_soon': return 'bg-yellow-100 text-yellow-800';
      case 'pending_verification': return 'bg-blue-100 text-blue-800';
      case 'missing': return 'bg-gray-100 text-gray-800';
      default: return 'bg-gray-100 text-gray-800';
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case 'valid': return '✓ Valid';
      case 'expired': return '⚠ Expired';
      case 'expiring_soon': return '⏰ Expiring Soon';
      case 'pending_verification': return '🔍 Pending Review';
      case 'missing': return '❌ Missing';
      default: return status;
    }
  };

  if (loading) {
    return <div className="p-5">Loading documents...</div>;
  }

  // Separate required and optional documents
  const requiredDocs = documents.filter(d => d.is_required);
  const optionalDocs = documents.filter(d => !d.is_required);

  // Calculate compliance
  const requiredComplete = requiredDocs.filter(d => d.status === 'valid').length;
  const compliancePercent = Math.round((requiredComplete / requiredDocs.length) * 100);

  return (
    <div className="p-5">
      <div className="mb-5">
        <h2 className="text-2xl font-bold mb-2">Document Center</h2>
        {athleteName && <p className="text-gray-600">Managing documents for {athleteName}</p>}
      </div>

      {/* Compliance Status */}
      <div className="mb-5 p-4 border-2 border-black bg-gray-50">
        <div className="flex justify-between items-center mb-2">
          <span className="font-bold">Compliance Status</span>
          <span className={`text-lg font-bold ${compliancePercent === 100 ? 'text-green-600' : 'text-yellow-600'}`}>
            {compliancePercent}% Complete
          </span>
        </div>
        <div className="w-full bg-gray-200 rounded-full h-4">
          <div
            className={`h-4 rounded-full ${compliancePercent === 100 ? 'bg-green-600' : 'bg-yellow-600'}`}
            style={{ width: `${compliancePercent}%` }}
          />
        </div>
        <p className="text-sm mt-2">
          {requiredComplete} of {requiredDocs.length} required documents valid
        </p>
      </div>

      {/* Upload Section */}
      <div className="mb-5 p-4 border border-gray-300">
        <h3 className="font-bold mb-3">Upload New Document</h3>
        <div className="space-y-3">
          <input
            type="file"
            onChange={handleFileChange}
            accept=".pdf,.jpg,.jpeg,.png,.gif"
            className="block w-full text-sm"
          />
          {selectedFile && (
            <div>
              <p className="text-sm text-gray-600">Selected: {selectedFile.name}</p>
              <input
                type="date"
                placeholder="Expiration date (if applicable)"
                value={expiresDate}
                onChange={(e) => setExpiresDate(e.target.value)}
                className="mt-2 p-2 border border-gray-300"
              />
              <textarea
                placeholder="Notes (optional)"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="mt-2 w-full p-2 border border-gray-300"
                rows={2}
              />
            </div>
          )}
        </div>
      </div>

      {/* Required Documents */}
      <div className="mb-5">
        <h3 className="font-bold mb-3 text-lg">Required Documents</h3>
        <div className="space-y-2">
          {requiredDocs.map((doc) => (
            <div key={doc.type_id} className="border border-gray-300 p-3">
              <div className="flex justify-between items-start">
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <h4 className="font-bold">{doc.display_name}</h4>
                    <span className={`px-2 py-1 text-xs rounded ${getStatusColor(doc.status)}`}>
                      {getStatusText(doc.status)}
                    </span>
                  </div>
                  <p className="text-sm text-gray-600 mt-1">{doc.description}</p>
                  {doc.document_id && (
                    <div className="mt-2 text-sm">
                      <p>📄 {doc.document_name}</p>
                      <p className="text-gray-500">
                        Uploaded: {new Date(doc.upload_date!).toLocaleDateString()}
                        {doc.expires_date && (
                          <span> | Expires: {new Date(doc.expires_date).toLocaleDateString()}</span>
                        )}
                      </p>
                      {doc.notes && <p className="text-gray-600 italic">Note: {doc.notes}</p>}
                    </div>
                  )}
                </div>
                <div className="flex gap-2">
                  {selectedFile && selectedType === doc.type_id ? (
                    <>
                      <button
                        onClick={() => handleUpload(doc.type_id)}
                        disabled={uploading}
                        className="px-3 py-1 bg-green-600 text-white text-sm hover:bg-green-700 disabled:bg-gray-400"
                      >
                        {uploading ? 'Uploading...' : 'Confirm'}
                      </button>
                      <button
                        onClick={() => setSelectedType(null)}
                        className="px-3 py-1 bg-gray-600 text-white text-sm hover:bg-gray-700"
                      >
                        Cancel
                      </button>
                    </>
                  ) : (
                    <>
                      <button
                        onClick={() => setSelectedType(doc.type_id)}
                        disabled={!selectedFile}
                        className="px-3 py-1 bg-blue-600 text-white text-sm hover:bg-blue-700 disabled:bg-gray-400"
                      >
                        {doc.document_id ? 'Replace' : 'Upload'}
                      </button>
                      {doc.document_id && (
                        <button
                          onClick={() => handleDelete(doc.document_id!)}
                          className="px-3 py-1 bg-red-600 text-white text-sm hover:bg-red-700"
                        >
                          Delete
                        </button>
                      )}
                    </>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Optional Documents */}
      <div>
        <h3 className="font-bold mb-3 text-lg">Optional Documents</h3>
        <div className="space-y-2">
          {optionalDocs.map((doc) => (
            <div key={doc.type_id} className="border border-gray-200 p-3 bg-gray-50">
              <div className="flex justify-between items-start">
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <h4 className="font-medium">{doc.display_name}</h4>
                    {doc.document_id && (
                      <span className={`px-2 py-1 text-xs rounded ${getStatusColor(doc.status)}`}>
                        {getStatusText(doc.status)}
                      </span>
                    )}
                  </div>
                  <p className="text-sm text-gray-600 mt-1">{doc.description}</p>
                  {doc.document_id && (
                    <div className="mt-2 text-sm">
                      <p>📄 {doc.document_name}</p>
                      <p className="text-gray-500">
                        Uploaded: {new Date(doc.upload_date!).toLocaleDateString()}
                        {doc.expires_date && (
                          <span> | Expires: {new Date(doc.expires_date).toLocaleDateString()}</span>
                        )}
                      </p>
                    </div>
                  )}
                </div>
                <div className="flex gap-2">
                  {selectedFile && selectedType === doc.type_id ? (
                    <>
                      <button
                        onClick={() => handleUpload(doc.type_id)}
                        disabled={uploading}
                        className="px-3 py-1 bg-green-600 text-white text-sm hover:bg-green-700 disabled:bg-gray-400"
                      >
                        {uploading ? 'Uploading...' : 'Confirm'}
                      </button>
                      <button
                        onClick={() => setSelectedType(null)}
                        className="px-3 py-1 bg-gray-600 text-white text-sm hover:bg-gray-700"
                      >
                        Cancel
                      </button>
                    </>
                  ) : (
                    <>
                      <button
                        onClick={() => setSelectedType(doc.type_id)}
                        disabled={!selectedFile}
                        className="px-3 py-1 bg-blue-600 text-white text-sm hover:bg-blue-700 disabled:bg-gray-400"
                      >
                        {doc.document_id ? 'Replace' : 'Upload'}
                      </button>
                      {doc.document_id && (
                        <button
                          onClick={() => handleDelete(doc.document_id!)}
                          className="px-3 py-1 bg-red-600 text-white text-sm hover:bg-red-700"
                        >
                          Delete
                        </button>
                      )}
                    </>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default DocumentManager;
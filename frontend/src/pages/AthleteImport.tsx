import React, { useState, useEffect, useRef } from 'react';
import { useOrg } from '../contexts/OrgContext';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface ImportJob {
  id: number;
  status: 'queued' | 'processing' | 'completed' | 'failed';
  original_filename: string | null;
  total_rows: number;
  processed_rows: number;
  created_count: number;
  updated_count: number;
  skipped_count: number;
  error_count: number;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
}

interface ImportError {
  row_number: number;
  error_message: string;
  row_json: Record<string, string> | null;
}

const AthleteImport: React.FC = () => {
  const token = localStorage.getItem('auth_token');
  const { currentClubId, isClubAdmin } = useOrg();

  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [jobId, setJobId] = useState<number | null>(null);
  const [job, setJob] = useState<ImportJob | null>(null);
  const [errors, setErrors] = useState<ImportError[]>([]);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const pollRef = useRef<number | null>(null);

  const isAdmin = isClubAdmin;

  useEffect(() => {
    return () => {
      if (pollRef.current !== null) window.clearInterval(pollRef.current);
    };
  }, []);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0] ?? null;
    setFile(f);
    setErrorMessage(null);
  };

  const handleUpload = async () => {
    if (!file || !currentClubId) return;
    setUploading(true);
    setErrorMessage(null);
    setJob(null);
    setErrors([]);
    setJobId(null);

    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('club_profile_id', String(currentClubId));

      const res = await fetch(`${API_URL}/api/imports-gateway.php?action=upload-athletes`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Upload failed');
      }
      setJobId(data.job_id);
      startPolling(data.job_id);
    } catch (err) {
      setErrorMessage(err instanceof Error ? err.message : 'Upload failed');
    } finally {
      setUploading(false);
    }
  };

  const startPolling = (id: number) => {
    if (pollRef.current !== null) window.clearInterval(pollRef.current);
    const poll = async () => {
      try {
        const res = await fetch(
          `${API_URL}/api/imports-gateway.php?action=status&job_id=${id}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await res.json();
        if (data.success) {
          setJob(data.job);
          setErrors(data.errors || []);
          if (data.job.status === 'completed' || data.job.status === 'failed') {
            if (pollRef.current !== null) window.clearInterval(pollRef.current);
            pollRef.current = null;
          }
        }
      } catch {
        // swallow transient poll errors
      }
    };
    poll();
    pollRef.current = window.setInterval(poll, 1000);
  };

  const handleReset = () => {
    if (pollRef.current !== null) window.clearInterval(pollRef.current);
    pollRef.current = null;
    setFile(null);
    setJobId(null);
    setJob(null);
    setErrors([]);
    setErrorMessage(null);
  };

  if (!isAdmin) {
    return (
      <main className="max-w-3xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-brand-primary mb-4">Import Athletes</h1>
        <p className="text-gray-600">
          The bulk importer is currently available to club admins only.
          Coach-scoped imports are coming in the next iteration.
        </p>
      </main>
    );
  }

  const progressPct = job && job.total_rows > 0
    ? Math.round((job.processed_rows / job.total_rows) * 100)
    : 0;

  return (
    <main className="max-w-3xl mx-auto px-4 py-8">
      <h1 className="text-2xl font-bold text-brand-primary mb-2">Import Athletes</h1>
      <p className="text-sm text-gray-600 mb-6">
        Upload a CSV with one row per athlete. Required columns: athlete_first_name,
        athlete_last_name, athlete_dob (YYYY-MM-DD), athlete_gender, guardian1_first_name,
        guardian1_last_name, guardian1_email, guardian1_mobile. Optional: a second guardian
        as guardian2_*.
      </p>

      {!jobId && (
        <div className="bg-white border border-gray-200 rounded-lg p-6">
          <label className="block text-sm font-medium text-gray-700 mb-2">CSV file</label>
          <input
            type="file"
            accept=".csv,text/csv"
            onChange={handleFileChange}
            className="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:bg-brand-primary file:text-white file:cursor-pointer"
          />
          {file && (
            <p className="mt-2 text-sm text-gray-600">
              Selected: <span className="font-medium">{file.name}</span> ({Math.round(file.size / 1024)} KB)
            </p>
          )}
          {errorMessage && (
            <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
              {errorMessage}
            </div>
          )}
          <button
            onClick={handleUpload}
            disabled={!file || uploading}
            className="mt-4 px-4 py-2 bg-brand-primary text-white rounded disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {uploading ? 'Uploading…' : 'Start Import'}
          </button>
        </div>
      )}

      {jobId && job && (
        <div className="bg-white border border-gray-200 rounded-lg p-6">
          <div className="flex justify-between items-start mb-4">
            <div>
              <h2 className="text-lg font-semibold">Job #{job.id}</h2>
              <p className="text-sm text-gray-600">{job.original_filename}</p>
            </div>
            <span className={`px-3 py-1 text-xs font-semibold rounded-full ${
              job.status === 'completed' ? 'bg-green-100 text-green-700' :
              job.status === 'failed' ? 'bg-red-100 text-red-700' :
              job.status === 'processing' ? 'bg-blue-100 text-blue-700' :
              'bg-gray-100 text-gray-700'
            }`}>
              {job.status}
            </span>
          </div>

          <div className="mb-4">
            <div className="flex justify-between text-xs text-gray-600 mb-1">
              <span>{job.processed_rows} of {job.total_rows} rows</span>
              <span>{progressPct}%</span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div
                className="bg-brand-primary h-2 rounded-full transition-all"
                style={{ width: `${progressPct}%` }}
              />
            </div>
          </div>

          <div className="grid grid-cols-4 gap-3 text-center mb-4">
            <div className="bg-green-50 rounded p-3">
              <div className="text-2xl font-bold text-green-700">{job.created_count}</div>
              <div className="text-xs text-green-700">Created</div>
            </div>
            <div className="bg-blue-50 rounded p-3">
              <div className="text-2xl font-bold text-blue-700">{job.updated_count}</div>
              <div className="text-xs text-blue-700">Updated</div>
            </div>
            <div className="bg-gray-50 rounded p-3">
              <div className="text-2xl font-bold text-gray-700">{job.skipped_count}</div>
              <div className="text-xs text-gray-700">Skipped</div>
            </div>
            <div className="bg-red-50 rounded p-3">
              <div className="text-2xl font-bold text-red-700">{job.error_count}</div>
              <div className="text-xs text-red-700">Errors</div>
            </div>
          </div>

          {errors.length > 0 && (
            <div className="mb-4">
              <h3 className="text-sm font-semibold mb-2">Errors ({errors.length} shown)</h3>
              <div className="max-h-64 overflow-y-auto border border-gray-200 rounded">
                <table className="w-full text-xs">
                  <thead className="bg-gray-50 sticky top-0">
                    <tr>
                      <th className="text-left p-2">Row</th>
                      <th className="text-left p-2">Error</th>
                    </tr>
                  </thead>
                  <tbody>
                    {errors.map((err, i) => (
                      <tr key={i} className="border-t border-gray-200">
                        <td className="p-2 font-mono">{err.row_number}</td>
                        <td className="p-2 text-red-700">{err.error_message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {(job.status === 'completed' || job.status === 'failed') && (
            <button
              onClick={handleReset}
              className="px-4 py-2 bg-brand-primary text-white rounded"
            >
              Import another file
            </button>
          )}
        </div>
      )}
    </main>
  );
};

export default AthleteImport;

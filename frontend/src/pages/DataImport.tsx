import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useOrg } from '../contexts/OrgContext';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Team {
  id: number;
  name: string;
}

interface PreviewResponse {
  success: boolean;
  entity: string;
  headers: string[];
  suggested_mapping: Record<string, string>;
  required_fields: string[];
  optional_fields: string[];
  field_labels: Record<string, string>;
  preview_rows: Record<string, string>[];
  total_rows: number;
}

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
}

interface ImportError {
  row_number: number;
  error_message: string;
  row_json: Record<string, string> | null;
}

type WizardStep = 'upload' | 'map' | 'status';

// Per-entity display config. Adding a new entity = one entry here + one
// backend ImportStrategy class. The page title, sample CSV template, and
// helper copy all read from these tables.
export type ImportEntity = 'athletes' | 'facilities' | 'volunteers' | 'coaches' | 'teams';

const ENTITY_DISPLAY_NAMES: Record<ImportEntity, string> = {
  athletes: 'Athletes',
  facilities: 'Facilities',
  volunteers: 'Volunteers',
  coaches: 'Coaches',
  teams: 'Teams',
};

const ENTITY_DESCRIPTIONS: Partial<Record<ImportEntity, string>> = {
  athletes: 'Upload a CSV with one row per athlete. Each row can include up to two crew members.',
  facilities: 'Upload a CSV with one row per field or court. Each row can include inline venue info — venues are auto-created by name if they don\'t already exist.',
  volunteers: 'Upload a CSV with one row per volunteer assignment. Each row references a team by name, so one file can span multiple teams in your club. Users without accounts will be created (login disabled until they claim it).',
  coaches: 'Upload a CSV with one row per coach. Creates a user account if needed and grants the coach role on your club. Team assignments are not handled here — assign coaches to specific teams from the team management UI after they\'re imported.',
  teams: 'Upload a CSV with one row per team. Required: name and age_group. A team is identified by the combination (e.g. "Tigers U12" and "Tigers U14" are distinct teams). Optional foreign keys — season, primary coach, home field — are looked up by name/email if provided.',
};

// Entity types that support optional team assignment at upload time.
// Athletes can be added to a team roster; facilities belong to venues, not teams.
const ENTITIES_WITH_TEAM_ASSIGNMENT: Partial<Record<ImportEntity, boolean>> = {
  athletes: true,
};

const SAMPLE_CSVS: Partial<Record<ImportEntity, string>> = {
  athletes: [
    'athlete_first_name,athlete_last_name,athlete_dob,athlete_gender,athlete_grade_level,athlete_school,guardian1_first_name,guardian1_last_name,guardian1_email,guardian1_mobile,guardian1_relationship,guardian2_first_name,guardian2_last_name,guardian2_email,guardian2_mobile,guardian2_relationship',
    'Ashley,Adams,2018-03-24,Female,3,Lincoln Elementary,Ava,Adams,ava.adams@example.com,5551001000,Parent,true,,,,,',
    'Marcus,Jones,2014-06-15,Male,5,Roosevelt Middle,John,Jones,thejones@example.com,5551002000,Parent,true,Jane,Jones,thejones@example.com,5551002001,Parent',
  ].join('\n'),
  facilities: [
    'venue_name,venue_address,venue_city,venue_state,venue_zip_code,venue_type,venue_has_lights,field_name,field_type,surface_type,dimensions,capacity,field_has_lights,location_notes',
    'Greenlake Park,1234 Park Way,Seattle,WA,98103,Park,true,Field A,Soccer,Grass,U12 Full Size,200,true,Near the parking lot',
    'Greenlake Park,1234 Park Way,Seattle,WA,98103,Park,true,Field B,Soccer,Turf,U8 Small Sided,80,true,Back corner',
    'Magnuson Sports Complex,7400 Sand Point Way NE,Seattle,WA,98115,Complex,true,Court 1,Basketball,Court,Regulation,150,true,',
  ].join('\n'),
  volunteers: [
    'team_name,first_name,last_name,email,phone,volunteer_role,background_check_status,start_date,notes',
    'Mustangs,Sarah,Chen,sarah.chen@example.com,5551001000,Team Manager,cleared,2026-03-01,Experienced',
    'Mustangs,Raj,Patel,raj.patel@example.com,5551001001,Team Parent,pending,,',
    'Thunder,Sarah,Chen,sarah.chen@example.com,5551001000,Team Manager,cleared,2026-03-01,Same person also helps Thunder',
  ].join('\n'),
  coaches: [
    'first_name,last_name,email,phone',
    'Pat,Henderson,pat.henderson@example.com,5552001000',
    'Riley,Nakamura,riley.nakamura@example.com,5552001001',
    'Jordan,Goldberg,jordan.goldberg@example.com,',
  ].join('\n'),
  teams: [
    'name,age_group,division,skill_level,gender,season_name,primary_coach_email,home_venue_name,home_field_name',
    'Tigers,U12,Competitive,Intermediate,Mixed,Spring 2026,pat.henderson@example.com,Greenlake Park,Field A',
    'Tigers,U14,Competitive,Advanced,Mixed,Spring 2026,riley.nakamura@example.com,Greenlake Park,Field B',
    'Lightning,U10,Recreational,Beginner,Mixed,,,,',
  ].join('\n'),
};

const UNMAPPED = '__unmapped__';

interface DataImportProps {
  entity: ImportEntity;
}

const DataImport: React.FC<DataImportProps> = ({ entity }) => {
  const token = localStorage.getItem('auth_token');
  const { currentClubId } = useOrg();

  const [step, setStep] = useState<WizardStep>('upload');
  const [file, setFile] = useState<File | null>(null);
  const [dragActive, setDragActive] = useState(false);

  const [teams, setTeams] = useState<Team[]>([]);
  const [selectedTeamId, setSelectedTeamId] = useState<string>('');

  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [jobId, setJobId] = useState<number | null>(null);
  const [job, setJob] = useState<ImportJob | null>(null);
  const [importErrors, setImportErrors] = useState<ImportError[]>([]);
  const pollRef = useRef<number | null>(null);

  const headers = { Authorization: `Bearer ${token}` };

  useEffect(() => {
    return () => {
      if (pollRef.current !== null) window.clearInterval(pollRef.current);
    };
  }, []);

  // Fetch teams for the picker, but only if this entity supports team assignment.
  useEffect(() => {
    if (!currentClubId) return;
    if (!(ENTITIES_WITH_TEAM_ASSIGNMENT[entity] ?? false)) return;
    const fetchTeams = async () => {
      try {
        const res = await fetch(
          `${API_URL}/api/imports-gateway.php?action=teams&club_profile_id=${currentClubId}`,
          { headers }
        );
        const data = await res.json();
        if (data.success && Array.isArray(data.teams)) {
          setTeams(data.teams);
        }
      } catch {
        // non-fatal; team picker just shows empty
      }
    };
    fetchTeams();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentClubId, entity]);

  const sampleCsv = SAMPLE_CSVS[entity];
  const displayName = ENTITY_DISPLAY_NAMES[entity];
  const description = ENTITY_DESCRIPTIONS[entity] ?? `Upload a CSV with one row per ${displayName.toLowerCase().replace(/s$/, '')}.`;
  const supportsTeamAssignment = ENTITIES_WITH_TEAM_ASSIGNMENT[entity] ?? false;

  const handleDownloadSample = () => {
    if (!sampleCsv) return;
    const blob = new Blob([sampleCsv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${entity}-import-template.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const handleFileSelected = async (f: File) => {
    setErrorMessage(null);
    setFile(f);
    setLoading(true);
    try {
      const formData = new FormData();
      formData.append('file', f);
      const res = await fetch(
        `${API_URL}/api/imports-gateway.php?action=preview&entity=${entity}`,
        { method: 'POST', headers, body: formData }
      );
      const data: PreviewResponse & { error?: string } = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Preview failed');
      }
      setPreview(data);
      const initialMapping: Record<string, string> = {};
      [...data.required_fields, ...data.optional_fields].forEach((field) => {
        initialMapping[field] = data.suggested_mapping[field] || UNMAPPED;
      });
      setMapping(initialMapping);
      setStep('map');
    } catch (err) {
      setErrorMessage(err instanceof Error ? err.message : 'Preview failed');
      setFile(null);
    } finally {
      setLoading(false);
    }
  };

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    const f = e.dataTransfer.files?.[0];
    if (f) handleFileSelected(f);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleFileInput = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    if (f) handleFileSelected(f);
  };

  const handleMappingChange = (dest: string, source: string) => {
    setMapping((prev) => ({ ...prev, [dest]: source }));
  };

  const missingRequired = preview
    ? preview.required_fields.filter((f) => !mapping[f] || mapping[f] === UNMAPPED)
    : [];

  const handleStartImport = async () => {
    if (!file || !currentClubId || !preview) return;
    if (missingRequired.length > 0) {
      setErrorMessage('Please map all required fields before starting the import.');
      return;
    }

    setLoading(true);
    setErrorMessage(null);
    try {
      const cleanMapping: Record<string, string> = {};
      Object.entries(mapping).forEach(([k, v]) => {
        if (v && v !== UNMAPPED) cleanMapping[k] = v;
      });

      const formData = new FormData();
      formData.append('file', file);
      formData.append('club_profile_id', String(currentClubId));
      if (selectedTeamId) formData.append('team_id', selectedTeamId);
      formData.append('column_mapping', JSON.stringify(cleanMapping));

      const res = await fetch(
        `${API_URL}/api/imports-gateway.php?action=upload&entity=${entity}`,
        { method: 'POST', headers, body: formData }
      );
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || (data.details && data.details.join(', ')) || 'Upload failed');
      }
      setJobId(data.job_id);
      setStep('status');
      startPolling(data.job_id);
    } catch (err) {
      setErrorMessage(err instanceof Error ? err.message : 'Upload failed');
    } finally {
      setLoading(false);
    }
  };

  const startPolling = (id: number) => {
    if (pollRef.current !== null) window.clearInterval(pollRef.current);
    const poll = async () => {
      try {
        const res = await fetch(
          `${API_URL}/api/imports-gateway.php?action=status&job_id=${id}`,
          { headers }
        );
        const data = await res.json();
        if (data.success) {
          setJob(data.job);
          setImportErrors(data.errors || []);
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
    setStep('upload');
    setFile(null);
    setPreview(null);
    setMapping({});
    setSelectedTeamId('');
    setJobId(null);
    setJob(null);
    setImportErrors([]);
    setErrorMessage(null);
  };

  if (!currentClubId) {
    return (
      <main className="max-w-3xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-brand-primary mb-4">Import Athletes</h1>
        <p className="text-gray-600">Select a club to continue.</p>
      </main>
    );
  }

  const progressPct = job && job.total_rows > 0
    ? Math.round((job.processed_rows / job.total_rows) * 100)
    : 0;

  return (
    <main className="max-w-4xl mx-auto px-4 py-8">
      <h1 className="text-2xl font-bold text-brand-primary mb-2">Import {displayName}</h1>

      {/* Step indicator */}
      <div className="flex items-center gap-2 mb-6 text-sm">
        {(['upload', 'map', 'status'] as WizardStep[]).map((s, i) => (
          <React.Fragment key={s}>
            {i > 0 && <span className="text-gray-300">→</span>}
            <span className={`px-3 py-1 rounded-full ${
              step === s ? 'bg-brand-primary text-white font-semibold' : 'bg-gray-100 text-gray-600'
            }`}>
              {i + 1}. {s === 'upload' ? 'Upload' : s === 'map' ? 'Map columns' : 'Import'}
            </span>
          </React.Fragment>
        ))}
      </div>

      {errorMessage && (
        <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
          {errorMessage}
        </div>
      )}

      {/* ── Step 1: Upload ─────────────────────────────────────── */}
      {step === 'upload' && (
        <div className="bg-white border border-gray-200 rounded-lg p-6">
          <p className="text-sm text-gray-600 mb-4">
            {description}
            {sampleCsv && (
              <>
                {' '}
                <button
                  type="button"
                  onClick={handleDownloadSample}
                  className="text-brand-primary underline"
                >
                  Download sample template
                </button>
              </>
            )}
          </p>

          {supportsTeamAssignment && (
            <>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Assign to team (optional)
              </label>
              <select
                value={selectedTeamId}
                onChange={(e) => setSelectedTeamId(e.target.value)}
                className="w-full mb-4 p-2 border border-gray-300 rounded"
              >
                <option value="">— None (import without team assignment) —</option>
                {teams.map((t) => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </>
          )}

          <div
            onDragEnter={(e) => { e.preventDefault(); setDragActive(true); }}
            onDragLeave={(e) => { e.preventDefault(); setDragActive(false); }}
            onDragOver={(e) => { e.preventDefault(); setDragActive(true); }}
            onDrop={handleDrop}
            className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
              dragActive ? 'border-brand-primary bg-brand-primary/5' : 'border-gray-300'
            }`}
          >
            <p className="text-sm text-gray-600 mb-3">
              Drag and drop a CSV file here, or click to browse
            </p>
            <input
              type="file"
              accept=".csv,text/csv"
              onChange={handleFileInput}
              className="hidden"
              id="athlete-csv-input"
            />
            <label
              htmlFor="athlete-csv-input"
              className="inline-block px-4 py-2 bg-brand-primary text-white rounded cursor-pointer"
            >
              {loading ? 'Parsing…' : 'Choose file'}
            </label>
          </div>
        </div>
      )}

      {/* ── Step 2: Map columns ───────────────────────────────── */}
      {step === 'map' && preview && (
        <div className="bg-white border border-gray-200 rounded-lg p-6">
          <div className="flex justify-between items-start mb-4">
            <div>
              <h2 className="text-lg font-semibold">Map columns</h2>
              <p className="text-sm text-gray-600">
                {preview.total_rows} rows detected in {file?.name}.
                We auto-matched {Object.values(preview.suggested_mapping).length} columns — review and adjust below.
              </p>
            </div>
            <button
              onClick={handleReset}
              className="text-sm text-gray-500 underline"
            >
              Start over
            </button>
          </div>

          {missingRequired.length > 0 && (
            <div className="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
              {missingRequired.length} required field(s) not yet mapped: {missingRequired.map((f) => preview.field_labels[f] || f).join(', ')}
            </div>
          )}

          <div className="space-y-3 mb-6">
            {preview.required_fields.map((field) => (
              <MappingRow
                key={field}
                destField={field}
                label={preview.field_labels[field] || field}
                required
                value={mapping[field] || UNMAPPED}
                headers={preview.headers}
                onChange={(v) => handleMappingChange(field, v)}
              />
            ))}
            <div className="pt-2 border-t border-gray-200">
              <h3 className="text-xs font-semibold text-gray-500 uppercase mb-2">Optional fields</h3>
              {preview.optional_fields.map((field) => (
                <MappingRow
                  key={field}
                  destField={field}
                  label={preview.field_labels[field] || field}
                  required={false}
                  value={mapping[field] || UNMAPPED}
                  headers={preview.headers}
                  onChange={(v) => handleMappingChange(field, v)}
                />
              ))}
            </div>
          </div>

          {preview.preview_rows.length > 0 && (
            <div className="mb-6">
              <h3 className="text-sm font-semibold mb-2">Preview (first {preview.preview_rows.length} rows)</h3>
              <div className="overflow-x-auto border border-gray-200 rounded">
                <table className="text-xs">
                  <thead className="bg-gray-50">
                    <tr>
                      {preview.headers.map((h) => (
                        <th key={h} className="text-left p-2 whitespace-nowrap">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {preview.preview_rows.map((row, i) => (
                      <tr key={i} className="border-t border-gray-200">
                        {preview.headers.map((h) => (
                          <td key={h} className="p-2 whitespace-nowrap">{row[h]}</td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          <button
            onClick={handleStartImport}
            disabled={loading || missingRequired.length > 0}
            className="px-4 py-2 bg-brand-primary text-white rounded disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {loading ? 'Starting…' : `Start import (${preview.total_rows} rows)`}
          </button>
        </div>
      )}

      {/* ── Step 3: Status ────────────────────────────────────── */}
      {step === 'status' && jobId && (
        <div className="bg-white border border-gray-200 rounded-lg p-6">
          <div className="flex justify-between items-start mb-4">
            <div>
              <h2 className="text-lg font-semibold">Job #{jobId}</h2>
              <p className="text-sm text-gray-600">{job?.original_filename}</p>
            </div>
            {job && (
              <span className={`px-3 py-1 text-xs font-semibold rounded-full ${
                job.status === 'completed' ? 'bg-green-100 text-green-700' :
                job.status === 'failed' ? 'bg-red-100 text-red-700' :
                job.status === 'processing' ? 'bg-blue-100 text-blue-700' :
                'bg-gray-100 text-gray-700'
              }`}>
                {job.status}
              </span>
            )}
          </div>

          {job && (
            <>
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

              {importErrors.length > 0 && (
                <div className="mb-4">
                  <h3 className="text-sm font-semibold mb-2">Errors ({importErrors.length} shown)</h3>
                  <div className="max-h-64 overflow-y-auto border border-gray-200 rounded">
                    <table className="w-full text-xs">
                      <thead className="bg-gray-50 sticky top-0">
                        <tr>
                          <th className="text-left p-2">Row</th>
                          <th className="text-left p-2">Error</th>
                        </tr>
                      </thead>
                      <tbody>
                        {importErrors.map((err, i) => (
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
            </>
          )}
        </div>
      )}
    </main>
  );
};

interface MappingRowProps {
  destField: string;
  label: string;
  required: boolean;
  value: string;
  headers: string[];
  onChange: (value: string) => void;
}

const MappingRow: React.FC<MappingRowProps> = ({ label, required, value, headers, onChange }) => (
  <div className="flex items-center gap-3">
    <div className="flex-1 text-sm">
      {label}
      {required && <span className="text-red-500 ml-1">*</span>}
    </div>
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={`flex-1 p-2 border rounded text-sm ${
        required && value === UNMAPPED ? 'border-red-300 bg-red-50' : 'border-gray-300'
      }`}
    >
      <option value={UNMAPPED}>— Not mapped —</option>
      {headers.map((h) => (
        <option key={h} value={h}>{h}</option>
      ))}
    </select>
  </div>
);

export default DataImport;

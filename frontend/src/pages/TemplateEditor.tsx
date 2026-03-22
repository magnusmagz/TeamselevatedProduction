import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';
import EmailEditor, { EditorRef, EmailEditorProps } from 'react-email-editor';

interface EmailTemplate {
  id: number;
  name: string;
  subject: string;
  category: string;
  scope: 'club' | 'personal';
  team_visibility: number[];
  is_active: boolean;
  created_by: number;
  design_json: object;
  html_output: string;
  updated_at: string;
  created_at: string;
}

interface MergeField {
  key: string;
  name: string;
  value: string;
  sample?: string;
}

interface Team {
  id: number;
  name: string;
}

const CATEGORIES = ['General', 'Marketing', 'Transactional', 'Newsletter'] as const;

const TemplateEditor: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const token = localStorage.getItem('auth_token');
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { activeContext } = useOrg();

  const editorRef = useRef<any>(null);
  const editorReady = useRef(false);
  const hasUnsavedChanges = useRef(false);

  const isNew = !id || id === 'new';
  const clubProfileId = activeContext?.scope_id;
  const isAdmin = user?.activeRole?.role === 'club_admin' || user?.system_role === 'super_admin';

  const [templateName, setTemplateName] = useState('');
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState<string>('General');
  const [scope, setScope] = useState<'club' | 'personal'>('club');
  const [teamVisibility, setTeamVisibility] = useState<number[]>([]);
  const [isActive, setIsActive] = useState(true);

  const [teams, setTeams] = useState<Team[]>([]);
  const [mergeFields, setMergeFields] = useState<MergeField[]>([]);
  const [existingDesign, setExistingDesign] = useState<object | null>(null);

  const [loading, setLoading] = useState(!isNew);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [showPreview, setShowPreview] = useState(false);
  const [previewHtml, setPreviewHtml] = useState('');
  const [showTeamVisibility, setShowTeamVisibility] = useState(false);

  // Fetch template data if editing
  useEffect(() => {
    if (!isNew && id) {
      fetchTemplate(parseInt(id, 10));
    }
  }, [id]);

  // Fetch teams and merge fields
  useEffect(() => {
    if (clubProfileId) {
      fetchTeams();
      fetchMergeFields();
    }
  }, [clubProfileId]);

  // Warn on unsaved changes
  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (hasUnsavedChanges.current) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, []);

  // Clear success message after delay
  useEffect(() => {
    if (successMessage) {
      const timer = setTimeout(() => setSuccessMessage(null), 3000);
      return () => clearTimeout(timer);
    }
  }, [successMessage]);

  const fetchTemplate = async (templateId: number) => {
    setLoading(true);
    try {
      const response = await fetch(
        `${API_URL}/api/email-templates.php?action=get&id=${templateId}`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) throw new Error('Failed to fetch template');
      const data = await response.json();
      const template: EmailTemplate = data.template || data;

      setTemplateName(template.name);
      setSubject(template.subject);
      setCategory(template.category);
      setScope(template.scope);
      setTeamVisibility(template.team_visibility || []);
      setIsActive(template.is_active);

      if (template.design_json) {
        setExistingDesign(template.design_json);
        // If editor is already ready, load the design immediately
        const editor = getEditor();
        if (editorReady.current && editor) {
          editor.loadDesign(template.design_json as any);
        }
      }
    } catch (err) {
      console.error('Error fetching template:', err);
      setError('Failed to load template.');
    } finally {
      setLoading(false);
    }
  };

  const fetchTeams = async () => {
    try {
      const response = await fetch(
        `${API_URL}/api/teams.php?club_profile_id=${clubProfileId}`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) return;
      const data = await response.json();
      setTeams(Array.isArray(data) ? data : data.teams || []);
    } catch (err) {
      console.error('Error fetching teams:', err);
    }
  };

  const fetchMergeFields = async () => {
    try {
      const response = await fetch(
        `${API_URL}/api/email-templates.php?action=merge-fields`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) return;
      const data = await response.json();
      setMergeFields(Array.isArray(data) ? data : data.merge_fields || []);
    } catch (err) {
      console.error('Error fetching merge fields:', err);
      // Set default merge fields as fallback
      setMergeFields([
        { key: 'athlete_first_name', name: 'Athlete First Name', value: '{{athlete_first_name}}' },
        { key: 'athlete_last_name', name: 'Athlete Last Name', value: '{{athlete_last_name}}' },
        { key: 'parent_first_name', name: 'Parent First Name', value: '{{parent_first_name}}' },
        { key: 'parent_last_name', name: 'Parent Last Name', value: '{{parent_last_name}}' },
        { key: 'team_name', name: 'Team Name', value: '{{team_name}}' },
        { key: 'club_name', name: 'Club Name', value: '{{club_name}}' },
        { key: 'event_name', name: 'Event Name', value: '{{event_name}}' },
        { key: 'event_date', name: 'Event Date', value: '{{event_date}}' },
        { key: 'event_time', name: 'Event Time', value: '{{event_time}}' },
        { key: 'event_location', name: 'Event Location', value: '{{event_location}}' },
        { key: 'unsubscribe_url', name: 'Unsubscribe URL', value: '{{unsubscribe_url}}' },
      ]);
    }
  };

  const buildMergeTags = useCallback(() => {
    const tags: Record<string, { name: string; value: string; sample?: string }> = {};
    mergeFields.forEach((field) => {
      tags[field.key] = {
        name: field.name,
        value: field.value,
        sample: field.sample || field.value,
      };
    });

    // Always include defaults if not provided by API
    if (!tags['athlete_first_name']) {
      tags['athlete_first_name'] = { name: 'Athlete First Name', value: '{{athlete_first_name}}', sample: 'John' };
    }
    if (!tags['athlete_last_name']) {
      tags['athlete_last_name'] = { name: 'Athlete Last Name', value: '{{athlete_last_name}}', sample: 'Doe' };
    }
    if (!tags['parent_first_name']) {
      tags['parent_first_name'] = { name: 'Parent First Name', value: '{{parent_first_name}}', sample: 'Jane' };
    }
    if (!tags['parent_last_name']) {
      tags['parent_last_name'] = { name: 'Parent Last Name', value: '{{parent_last_name}}', sample: 'Doe' };
    }
    if (!tags['team_name']) {
      tags['team_name'] = { name: 'Team Name', value: '{{team_name}}', sample: 'Eagles U14' };
    }
    if (!tags['club_name']) {
      tags['club_name'] = { name: 'Club Name', value: '{{club_name}}', sample: 'Metro FC' };
    }
    if (!tags['event_name']) {
      tags['event_name'] = { name: 'Event Name', value: '{{event_name}}', sample: 'Team Dinner' };
    }
    if (!tags['event_date']) {
      tags['event_date'] = { name: 'Event Date', value: '{{event_date}}', sample: 'March 25, 2026' };
    }
    if (!tags['event_time']) {
      tags['event_time'] = { name: 'Event Time', value: '{{event_time}}', sample: '6:00 PM' };
    }
    if (!tags['event_location']) {
      tags['event_location'] = { name: 'Event Location', value: '{{event_location}}', sample: '123 Main St' };
    }
    if (!tags['unsubscribe_url']) {
      tags['unsubscribe_url'] = { name: 'Unsubscribe URL', value: '{{unsubscribe_url}}', sample: '#' };
    }

    return tags;
  }, [mergeFields]);

  const getEditor = () => {
    // react-email-editor v1.x: actual Unlayer instance is on .editor
    return editorRef.current?.editor || editorRef.current;
  };

  const onEditorReady: EmailEditorProps['onReady'] = () => {
    editorReady.current = true;

    const editor = getEditor();
    // Load existing design if available
    if (existingDesign && editor) {
      editor.loadDesign(existingDesign as any);
    }

    // Track changes
    if (editor) {
      editor.addEventListener('design:updated', () => {
        hasUnsavedChanges.current = true;
      });
    }
  };

  const handleSave = () => {
    if (!templateName.trim()) {
      setError('Please enter a template name.');
      return;
    }
    const editor = getEditor();
    if (!editor || !editorReady.current) {
      setError('Editor is not ready. Please wait and try again.');
      return;
    }

    setSaving(true);
    setError(null);

    editor.saveDesign((design: object) => {
      editor.exportHtml((data: { html: string }) => {
        saveTemplate(design, data.html);
      });
    });
  };

  const saveTemplate = async (designJson: object, htmlOutput: string) => {
    try {
      const payload = {
        name: templateName.trim(),
        subject: subject.trim(),
        category,
        scope,
        team_visibility: teamVisibility,
        is_active: isActive,
        design_json: designJson,
        html_output: htmlOutput,
        club_profile_id: clubProfileId,
      };

      const url = isNew
        ? `${API_URL}/api/email-templates.php?action=create`
        : `${API_URL}/api/email-templates.php?action=update&id=${id}`;

      const method = isNew ? 'POST' : 'PUT';

      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`,
        },
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const errData = await response.json().catch(() => ({}));
        throw new Error(errData.message || 'Failed to save template');
      }

      const result = await response.json();
      hasUnsavedChanges.current = false;
      setSuccessMessage('Template saved successfully!');

      // If this was a new template, navigate to the edit URL
      if (isNew && result.id) {
        navigate(`/email-templates/${result.id}`, { replace: true });
      }
    } catch (err: any) {
      console.error('Error saving template:', err);
      setError(err.message || 'Failed to save template.');
    } finally {
      setSaving(false);
    }
  };

  const handlePreview = () => {
    const editor = getEditor();
    if (!editor || !editorReady.current) {
      setError('Editor is not ready.');
      return;
    }

    editor.exportHtml((data: { html: string }) => {
      setPreviewHtml(data.html);
      setShowPreview(true);
    });
  };

  const handleBack = () => {
    if (hasUnsavedChanges.current) {
      const confirmed = window.confirm('You have unsaved changes. Are you sure you want to leave?');
      if (!confirmed) return;
    }
    navigate('/email-templates');
  };

  const toggleTeamVisibility = (teamId: number) => {
    setTeamVisibility((prev) =>
      prev.includes(teamId) ? prev.filter((id) => id !== teamId) : [...prev, teamId]
    );
    hasUnsavedChanges.current = true;
  };

  // Loading state
  if (loading) {
    return (
      <div className="flex flex-col bg-gray-50" style={{ height: 'calc(100vh - 132px)' }}>
        <div className="flex items-center justify-between px-4 py-3 bg-white border-b border-gray-200">
          <div className="flex items-center gap-4">
            <div className="h-8 w-24 bg-gray-200 rounded animate-pulse" />
            <div className="h-8 w-64 bg-gray-200 rounded animate-pulse" />
          </div>
          <div className="flex items-center gap-3">
            <div className="h-8 w-24 bg-gray-200 rounded animate-pulse" />
            <div className="h-8 w-20 bg-gray-200 rounded animate-pulse" />
          </div>
        </div>
        <div className="flex-1 flex items-center justify-center">
          <div className="text-center">
            <div className="w-10 h-10 border-4 border-brand-primary border-t-transparent rounded-full animate-spin mx-auto mb-3" />
            <p className="text-gray-500 text-sm">Loading template...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col bg-gray-50" style={{ height: 'calc(100vh - 132px)' }}>
      {/* Top bar */}
      <div className="flex-shrink-0 flex flex-col sm:flex-row items-start sm:items-center justify-between px-4 py-3 bg-white border-b border-gray-200 gap-3">
        <div className="flex items-center gap-3 flex-1 min-w-0">
          <button
            onClick={handleBack}
            className="flex items-center text-sm text-brand-primary hover:text-brand-primary/80 whitespace-nowrap uppercase font-semibold"
          >
            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Templates
          </button>
          <div className="h-6 w-px bg-gray-300" />
          <input
            type="text"
            placeholder="Template name..."
            value={templateName}
            onChange={(e) => {
              setTemplateName(e.target.value);
              hasUnsavedChanges.current = true;
            }}
            className="flex-1 min-w-0 px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
          />
          <input
            type="text"
            placeholder="Subject line..."
            value={subject}
            onChange={(e) => {
              setSubject(e.target.value);
              hasUnsavedChanges.current = true;
            }}
            className="flex-1 min-w-0 px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none hidden lg:block"
          />
        </div>
        <div className="flex items-center gap-2 flex-shrink-0">
          <select
            value={category}
            onChange={(e) => {
              setCategory(e.target.value);
              hasUnsavedChanges.current = true;
            }}
            className="px-2 py-1.5 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
          >
            {CATEGORIES.map((cat) => (
              <option key={cat} value={cat}>
                {cat}
              </option>
            ))}
          </select>
          <button
            onClick={() => setShowTeamVisibility(!showTeamVisibility)}
            className={`px-4 py-2 border rounded-md text-sm uppercase font-semibold transition-colors ${
              showTeamVisibility
                ? 'border-brand-secondary text-brand-primary bg-brand-primary bg-opacity-5'
                : 'border-brand-secondary text-brand-primary hover:bg-gray-50'
            }`}
            title="Team visibility"
          >
            <svg className="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Teams
            {teamVisibility.length > 0 && (
              <span className="ml-1 px-1.5 py-0.5 bg-brand-primary text-white text-xs rounded-full">
                {teamVisibility.length}
              </span>
            )}
          </button>
          <button
            onClick={handlePreview}
            className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm"
          >
            Preview
          </button>
          <button
            onClick={handleSave}
            disabled={saving}
            className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {saving ? 'Saving...' : 'Save'}
          </button>
        </div>
      </div>

      {/* Subject line on small screens */}
      <div className="lg:hidden flex-shrink-0 px-4 py-2 bg-white border-b border-gray-200">
        <input
          type="text"
          placeholder="Subject line..."
          value={subject}
          onChange={(e) => {
            setSubject(e.target.value);
            hasUnsavedChanges.current = true;
          }}
          className="w-full px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
        />
      </div>

      {/* Messages */}
      {error && (
        <div className="flex-shrink-0 mx-4 mt-2 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg flex justify-between items-center text-sm">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="text-red-500 hover:text-red-700 ml-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      )}
      {successMessage && (
        <div className="flex-shrink-0 mx-4 mt-2 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">
          {successMessage}
        </div>
      )}

      {/* Team Visibility Panel */}
      {showTeamVisibility && (
        <div className="flex-shrink-0 mx-4 mt-2 p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
          <div className="flex justify-between items-center mb-3">
            <h3 className="text-sm font-semibold text-gray-900">Team Visibility</h3>
            <p className="text-xs text-gray-500">
              {teamVisibility.length === 0
                ? 'Visible to all teams'
                : `Visible to ${teamVisibility.length} team${teamVisibility.length === 1 ? '' : 's'}`}
            </p>
          </div>
          <p className="text-xs text-gray-400 mb-3">
            Leave all unchecked to make this template visible to all teams.
          </p>
          <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
            {teams.length === 0 ? (
              <p className="text-xs text-gray-400">No teams found.</p>
            ) : (
              teams.map((team) => (
                <label
                  key={team.id}
                  className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer text-sm transition-colors ${
                    teamVisibility.includes(team.id)
                      ? 'border-brand-primary bg-brand-primary bg-opacity-5 text-brand-primary'
                      : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  <input
                    type="checkbox"
                    checked={teamVisibility.includes(team.id)}
                    onChange={() => toggleTeamVisibility(team.id)}
                    className="sr-only"
                  />
                  <span
                    className={`w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 ${
                      teamVisibility.includes(team.id)
                        ? 'bg-brand-primary border-brand-primary'
                        : 'border-gray-300 bg-white'
                    }`}
                  >
                    {teamVisibility.includes(team.id) && (
                      <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                      </svg>
                    )}
                  </span>
                  {team.name}
                </label>
              ))
            )}
          </div>

          {/* Scope toggle for admins */}
          {isAdmin && (
            <div className="mt-4 pt-3 border-t border-gray-100 flex items-center gap-4">
              <span className="text-xs font-medium text-gray-600">Template Scope:</span>
              <label className="inline-flex items-center gap-1.5 cursor-pointer">
                <input
                  type="radio"
                  name="scope"
                  value="club"
                  checked={scope === 'club'}
                  onChange={() => {
                    setScope('club');
                    hasUnsavedChanges.current = true;
                  }}
                  className="text-brand-primary focus:ring-brand-primary"
                />
                <span className="text-xs text-gray-700">Club Template</span>
              </label>
              <label className="inline-flex items-center gap-1.5 cursor-pointer">
                <input
                  type="radio"
                  name="scope"
                  value="personal"
                  checked={scope === 'personal'}
                  onChange={() => {
                    setScope('personal');
                    hasUnsavedChanges.current = true;
                  }}
                  className="text-brand-primary focus:ring-brand-primary"
                />
                <span className="text-xs text-gray-700">Personal Template</span>
              </label>
              <label className="inline-flex items-center gap-1.5 cursor-pointer ml-4">
                <input
                  type="checkbox"
                  checked={isActive}
                  onChange={(e) => {
                    setIsActive(e.target.checked);
                    hasUnsavedChanges.current = true;
                  }}
                  className="text-brand-primary focus:ring-brand-primary rounded"
                />
                <span className="text-xs text-gray-700">Active</span>
              </label>
            </div>
          )}
        </div>
      )}

      {/* Unlayer Editor */}
      <div className="flex-1 min-h-0">
        <EmailEditor
          ref={editorRef}
          onReady={onEditorReady}
          options={{
            mergeTags: buildMergeTags(),
            features: {
              textEditor: {
                spellChecker: true,
              },
            },
            appearance: {
              theme: 'light',
              panels: {
                tools: {
                  dock: 'left',
                },
              },
            },
          }}
          style={{ height: '100%' }}
        />
      </div>

      {/* Preview Modal */}
      {showPreview && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4"
          onClick={() => setShowPreview(false)}
        >
          <div
            className="bg-white rounded-lg shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between px-4 py-3 border-b border-gray-200">
              <div>
                <h3 className="text-sm font-semibold text-gray-900">Email Preview</h3>
                {subject && (
                  <p className="text-xs text-gray-500 mt-0.5">Subject: {subject}</p>
                )}
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setShowPreview(false)}
                  className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm"
                >
                  Close
                </button>
              </div>
            </div>
            <div className="flex-1 overflow-auto bg-gray-100 p-4">
              <div className="bg-white mx-auto max-w-2xl shadow-sm rounded">
                <iframe
                  srcDoc={previewHtml}
                  title="Email Preview"
                  className="w-full border-0 rounded"
                  style={{ minHeight: '600px', height: '100%' }}
                  sandbox="allow-same-origin"
                />
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default TemplateEditor;

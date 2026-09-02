import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import {
  CATEGORY_META,
  OTHER_META,
  categoryColor,
  categoryLabel,
  categorySlug,
  countByCategory,
  groupByCategory,
} from '../constants/templateCategories';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

// Lazy, like the email editor loads EmailCompose — the compose modal pulls in
// recipient search and is dead weight for anyone only browsing templates.
const SmsCompose = React.lazy(() =>
  import('../components/communications/SmsCompose').then((m) => ({ default: m.SmsCompose }))
);

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

const SMS_SEGMENT_LENGTH = 160;
const SMS_CONCAT_SEGMENT_LENGTH = 153;


interface MergeField {
  key: string;
  label: string;
  group?: string;
}

/**
 * Fallback only. The real list comes from MergeFieldService::getAvailableFields()
 * via the API — a second hardcoded copy is how this page ended up offering
 * {{guardian_first_name}} but not {{recipient_first_name}}, the one tag that
 * resolves for every recipient type. Same drift that gave SMS its own stale
 * category list.
 */
const FALLBACK_MERGE_FIELDS: MergeField[] = [
  { key: 'recipient_first_name', label: 'Recipient First Name', group: 'Recipient' },
  { key: 'athlete_first_name', label: 'Athlete First Name', group: 'Athlete' },
  { key: 'team_name', label: 'Team Name', group: 'Team' },
  { key: 'club_name', label: 'Club Name', group: 'Club' },
];

interface SmsTemplate {
  id: number;
  club_profile_id: number;
  name: string;
  body_text: string;
  category: string;
  scope: string;
  channel: string;
  is_active: boolean;
  cloned_from: number | null;
  created_by: number;
  updated_by: number;
  created_at: string;
  updated_at: string;
}

function getSegmentCount(len: number): number {
  if (len === 0) return 0;
  if (len <= SMS_SEGMENT_LENGTH) return 1;
  return Math.ceil(len / SMS_CONCAT_SEGMENT_LENGTH);
}

function getScopeBadge(scope: string) {
  switch (scope) {
    case 'platform':
      return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">Platform</span>;
    case 'club':
      return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">Club</span>;
    case 'personal':
      return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">Personal</span>;
    default:
      return null;
  }
}

const SmsTemplates: React.FC = () => {
  const { user } = useAuth();
  const { activeContext } = useOrg();
  const token = localStorage.getItem('auth_token');

  const clubProfileId = activeContext?.scope_id;
  const isAdmin = user?.activeRole?.role === 'club_admin' || user?.system_role === 'super_admin';
  const isSuperAdmin = user?.system_role === 'super_admin';

  const [templates, setTemplates] = useState<SmsTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [activeTab, setActiveTab] = useState<'club' | 'personal'>('club');
  const [mergeFields, setMergeFields] = useState<MergeField[]>(FALLBACK_MERGE_FIELDS);
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');

  // Modal state
  const [modalOpen, setModalOpen] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<SmsTemplate | null>(null);
  const [formName, setFormName] = useState('');
  const [formBody, setFormBody] = useState('');
  const [formCategory, setFormCategory] = useState(CATEGORY_META[0].slug);
  const [formScope, setFormScope] = useState('club');
  const [saving, setSaving] = useState(false);
  const [mergePickerOpen, setMergePickerOpen] = useState(false);

  // Send-from-library: open SmsCompose preloaded with this template, matching
  // the email template editor's Send button.
  const [composeTemplate, setComposeTemplate] = useState<{ id: number; name: string; body_text: string } | null>(null);

  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // Stable across renders so the fetch effects/callbacks below can depend on
  // it without re-firing on every render.
  const headers: Record<string, string> = useMemo(() => ({
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  }), [token]);

  const fetchTemplates = useCallback(async () => {
    if (!clubProfileId) return;
    setLoading(true);
    try {
      const res = await fetch(
        `${API_URL}/api/email-templates.php?action=list&club_profile_id=${clubProfileId}&channel=sms`,
        { headers }
      );
      const data = await res.json();
      if (data.success) {
        setTemplates(data.templates || []);
      } else {
        setError(data.error || 'Failed to load templates');
      }
    } catch {
      setError('Failed to load templates');
    } finally {
      setLoading(false);
    }
  }, [clubProfileId, headers]);

  useEffect(() => {
    fetchTemplates();
  }, [fetchTemplates]);

  // One source of merge tags, shared with the email editor.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(`${API_URL}/api/email-templates.php?action=merge-fields`, { headers });
        if (!res.ok) return;
        const data = await res.json();
        const fields: MergeField[] = Array.isArray(data) ? data : data.merge_fields || [];
        if (!cancelled && fields.length) setMergeFields(fields);
      } catch {
        // keep the fallback list
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const openCreateModal = () => {
    setEditingTemplate(null);
    setFormName('');
    setFormBody('');
    setFormCategory(CATEGORY_META[0].slug);
    setFormScope('club');
    setModalOpen(true);
  };

  const openEditModal = (t: SmsTemplate) => {
    setEditingTemplate(t);
    setFormName(t.name);
    setFormBody(t.body_text || '');
    setFormCategory(categorySlug(t.category));
    setFormScope(t.scope);
    setModalOpen(true);
  };

  const closeModal = () => {
    setModalOpen(false);
    setEditingTemplate(null);
    setMergePickerOpen(false);
  };

  /**
   * Send this template: open the SMS compose modal with the body preloaded, so
   * the user picks recipients and sends from there. Mirrors the email template
   * editor's Send button (TemplateEditor.handleSendClick).
   *
   * Deliberately NOT gated on isAdmin, unlike Edit/Duplicate/Delete: coaches can
   * send SMS to their own team and can use templates — they just can't create or
   * modify them. Scope is enforced server-side on the send either way.
   */
  const handleSendClick = (t: SmsTemplate) => {
    if (!clubProfileId) {
      setError('No active club — cannot send.');
      return;
    }
    if (!(t.body_text || '').trim()) {
      setError('This template has no message body to send.');
      return;
    }
    setComposeTemplate({ id: t.id, name: t.name, body_text: t.body_text });
  };

  const handleSave = async () => {
    if (!formName.trim() || !formBody.trim()) return;
    setSaving(true);

    try {
      const isEdit = !!editingTemplate;
      const url = isEdit
        ? `${API_URL}/api/email-templates.php?action=update&id=${editingTemplate!.id}`
        : `${API_URL}/api/email-templates.php?action=create`;

      const res = await fetch(url, {
        method: isEdit ? 'PUT' : 'POST',
        headers,
        body: JSON.stringify({
          club_profile_id: clubProfileId,
          name: formName,
          channel: 'sms',
          body_text: formBody,
          category: formCategory,
          scope: formScope,
        }),
      });

      const data = await res.json();
      if (data.success) {
        closeModal();
        fetchTemplates();
      } else {
        setError(data.error || 'Failed to save template');
      }
    } catch {
      setError('Failed to save template');
    } finally {
      setSaving(false);
    }
  };

  const handleDuplicate = async (t: SmsTemplate) => {
    try {
      const res = await fetch(`${API_URL}/api/email-templates.php?action=duplicate`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ id: t.id }),
      });
      const data = await res.json();
      if (data.success) {
        fetchTemplates();
      }
    } catch {
      setError('Failed to duplicate template');
    }
  };

  const handleDelete = async (t: SmsTemplate) => {
    if (!window.confirm(`Delete template "${t.name}"?`)) return;
    try {
      const res = await fetch(`${API_URL}/api/email-templates.php?action=delete&id=${t.id}`, {
        method: 'DELETE',
        headers,
      });
      const data = await res.json();
      if (data.success) {
        fetchTemplates();
      }
    } catch {
      setError('Failed to delete template');
    }
  };

  const insertMergeField = (key: string) => {
    const tag = `{{${key}}}`;
    const textarea = textareaRef.current;
    if (textarea) {
      const start = textarea.selectionStart;
      const end = textarea.selectionEnd;
      const newBody = formBody.substring(0, start) + tag + formBody.substring(end);
      setFormBody(newBody);
      // Restore cursor position after the inserted tag
      setTimeout(() => {
        textarea.focus();
        textarea.setSelectionRange(start + tag.length, start + tag.length);
      }, 0);
    } else {
      setFormBody(formBody + tag);
    }
    setMergePickerOpen(false);
  };

  const charCount = formBody.length;
  const segmentCount = getSegmentCount(charCount);

  const getCharCountColor = () => {
    if (charCount === 0) return 'text-gray-400';
    if (charCount <= SMS_SEGMENT_LENGTH) return 'text-gray-500';
    if (charCount <= SMS_SEGMENT_LENGTH * 2) return 'text-amber-600';
    return 'text-red-600';
  };

  // Tab, then search, then category — the same order as the email library
  // (TemplateLibrary.tsx), so the chip counts reflect what the tab and search
  // left. Both pages share one taxonomy so they cannot drift apart again.
  const bySearchAndTab = templates.filter((t) => {
    const matchesTab =
      activeTab === 'club'
        ? t.scope === 'club' || t.scope === 'platform'
        : t.scope === 'personal' && t.created_by === user?.id;
    const q = searchQuery.trim().toLowerCase();
    const matchesSearch =
      q === '' ||
      t.name.toLowerCase().includes(q) ||
      (t.body_text || '').toLowerCase().includes(q);
    return matchesTab && matchesSearch;
  });

  const categoryCounts = countByCategory(bySearchAndTab);

  const filteredTemplates = bySearchAndTab.filter(
    (t) => categoryFilter === 'All' || categorySlug(t.category) === categoryFilter
  );

  const groupedTemplates = groupByCategory(filteredTemplates);
  const listTemplates = groupedTemplates.flatMap((g) => g.items);

  const formatDate = (dateString: string) =>
    new Date(dateString).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });

  if (!clubProfileId) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <p className="text-gray-500">Select a club to manage SMS templates.</p>
      </div>
    );
  }

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">SMS Templates</h1>
        {isAdmin && (
          <button
            onClick={openCreateModal}
            className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
          >
            Create Template
          </button>
        )}
      </div>

      {error && (
        <div className="mb-4 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
          {error}
          <button onClick={() => setError('')} className="ml-2 text-red-500 hover:text-red-700 font-medium">Dismiss</button>
        </div>
      )}

      {/* Tabs */}
      <div className="flex border-b border-gray-200 mb-6">
        <button
          onClick={() => setActiveTab('club')}
          className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            activeTab === 'club'
              ? 'border-brand-primary text-brand-primary'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          }`}
        >
          Club Templates
        </button>
        <button
          onClick={() => setActiveTab('personal')}
          className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            activeTab === 'personal'
              ? 'border-brand-primary text-brand-primary'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          }`}
        >
          My Templates
        </button>
      </div>

      {/* Tag cluster — click a tag to view that group, or All to view everything */}
      {!loading && templates.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 mb-5">
          <button
            onClick={() => setCategoryFilter('All')}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition-all ${
              categoryFilter === 'All'
                ? 'bg-brand-primary text-white shadow-sm'
                : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'
            }`}
          >
            All Templates
            <span className={categoryFilter === 'All' ? 'text-white/70' : 'text-gray-400'}>
              {bySearchAndTab.length}
            </span>
          </button>
          {[...CATEGORY_META, OTHER_META].map((c) => {
            const count = categoryCounts[c.slug] || 0;
            if (!count) return null;
            const active = categoryFilter === c.slug;
            return (
              <button
                key={c.slug}
                onClick={() => setCategoryFilter(active ? 'All' : c.slug)}
                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition-all ${c.color} ${
                  active ? 'ring-2 ring-offset-1 ring-gray-500' : 'opacity-80 hover:opacity-100'
                }`}
              >
                {c.label}
                <span className="opacity-60">{count}</span>
              </button>
            );
          })}
        </div>
      )}

      {/* Filters row (matches the email Template Library) */}
      {!loading && templates.length > 0 && (
        <div className="flex flex-col sm:flex-row gap-3 mb-6">
          <div className="relative flex-1">
            <svg
              className="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
              />
            </svg>
            <input
              type="text"
              placeholder="Search by name or message..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
            />
          </div>

          {/* View toggle */}
          <div className="flex border border-brand-secondary rounded-md overflow-hidden">
            <button
              onClick={() => setViewMode('grid')}
              className={`px-3 py-2 text-sm uppercase font-semibold ${
                viewMode === 'grid' ? 'bg-brand-primary text-white' : 'bg-white text-brand-primary hover:bg-gray-50'
              }`}
              title="Grid view"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"
                />
              </svg>
            </button>
            <button
              onClick={() => setViewMode('list')}
              className={`px-3 py-2 text-sm uppercase font-semibold ${
                viewMode === 'list' ? 'bg-brand-primary text-white' : 'bg-white text-brand-primary hover:bg-gray-50'
              }`}
              title="List view"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>
          </div>
        </div>
      )}

      {/* Template list */}
      {loading ? (
        <div className="flex justify-center py-12">
          <svg className="animate-spin h-8 w-8 text-brand-primary" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
        </div>
      ) : templates.length === 0 ? (
        <div className="text-center py-12 text-gray-500">
          <svg className="mx-auto h-12 w-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
          <p className="text-lg font-medium mb-1">No SMS templates yet</p>
          <p className="text-sm">
            {isAdmin ? 'Create your first SMS template to get started.' : 'No templates have been created for your club yet.'}
          </p>
          {isAdmin && (
            <button
              onClick={openCreateModal}
              className="mt-4 bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
            >
              Create Template
            </button>
          )}
        </div>
      ) : filteredTemplates.length === 0 ? (
        <div className="text-center py-12 text-gray-500">
          <p className="text-lg font-medium mb-1">No matching templates</p>
          <p className="text-sm">Try adjusting your search or filter criteria.</p>
          <button
            onClick={() => { setSearchQuery(''); setCategoryFilter('All'); }}
            className="mt-4 text-sm font-medium text-brand-primary hover:underline"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="space-y-8">
          {viewMode === 'grid' && groupedTemplates.map((group) => (
            <section key={group.slug}>
              <div className="flex items-center gap-2 mb-3">
                <span className={`text-xs px-2.5 py-0.5 rounded-full font-semibold ${group.color}`}>
                  {group.label}
                </span>
                <span className="text-xs text-gray-400">{group.items.length}</span>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {group.items.map((t) => {
                  const bodyLen = (t.body_text || '').length;
                  const segs = getSegmentCount(bodyLen);
                  return (
                    <div key={t.id} className="bg-white border border-gray-200 rounded-xl p-5 shadow-sm hover:shadow-md transition-shadow">
                      <div className="flex items-start justify-between mb-2">
                        <h3 className="font-semibold text-gray-900 text-sm leading-tight line-clamp-1">{t.name}</h3>
                        {getScopeBadge(t.scope)}
                      </div>
                      <p className="text-sm text-gray-500 mb-3 line-clamp-2 min-h-[2.5rem]">
                        {t.body_text ? (t.body_text.length > 80 ? t.body_text.substring(0, 80) + '...' : t.body_text) : 'No body text'}
                      </p>
                      <div className="flex flex-wrap gap-2 mb-3">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${categoryColor(t.category)}`}>
                          {categoryLabel(t.category)}
                        </span>
                        <span className="text-xs px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-600">
                          {bodyLen} chars &middot; {segs} segment{segs !== 1 ? 's' : ''}
                        </span>
                      </div>
                      <p className="text-xs text-gray-400 mb-3">Updated {formatDate(t.updated_at)}</p>
                      <div className="flex items-center gap-2 pt-3 border-t border-gray-100">
                        <button
                          onClick={() => handleSendClick(t)}
                          disabled={!clubProfileId}
                          title="Send this template to recipients"
                          className="text-xs font-semibold text-brand-primary hover:underline disabled:opacity-50 disabled:cursor-not-allowed disabled:no-underline"
                        >
                          <svg className="w-3.5 h-3.5 inline-block mr-1 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                          </svg>
                          Send
                        </button>
                        {isAdmin && (
                          <button
                            onClick={() => openEditModal(t)}
                            className="text-xs font-medium text-brand-primary hover:underline"
                          >
                            Edit
                          </button>
                        )}
                        {isAdmin && (
                          <button
                            onClick={() => handleDuplicate(t)}
                            className="text-xs font-medium text-gray-500 hover:text-gray-700 hover:underline"
                          >
                            Duplicate
                          </button>
                        )}
                        {isAdmin && t.scope !== 'platform' && (
                          <button
                            onClick={() => handleDelete(t)}
                            className="text-xs font-medium text-red-500 hover:text-red-700 hover:underline ml-auto"
                          >
                            Delete
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </section>
          ))}

          {/* List view — same columns as the email library, with the SMS length
              column standing in for Subject. */}
          {viewMode === 'list' && (
            <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Message</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Scope</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Length</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Updated</th>
                      <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200">
                    {listTemplates.map((t) => {
                      const bodyLen = (t.body_text || '').length;
                      const segs = getSegmentCount(bodyLen);
                      return (
                        <tr key={t.id} className="hover:bg-gray-50">
                          <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">{t.name}</td>
                          <td className="px-4 py-3 text-sm text-gray-500 max-w-xs truncate">{t.body_text}</td>
                          <td className="px-4 py-3 whitespace-nowrap">
                            <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${categoryColor(t.category)}`}>
                              {categoryLabel(t.category)}
                            </span>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap">{getScopeBadge(t.scope)}</td>
                          <td className="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                            {bodyLen} chars &middot; {segs} seg{segs !== 1 ? 's' : ''}
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{formatDate(t.updated_at)}</td>
                          <td className="px-4 py-3 text-right whitespace-nowrap">
                            <div className="flex justify-end gap-3">
                              <button
                                onClick={() => handleSendClick(t)}
                                disabled={!clubProfileId}
                                title="Send this template to recipients"
                                className="text-xs text-brand-primary hover:underline font-semibold disabled:opacity-50 disabled:cursor-not-allowed disabled:no-underline"
                              >
                                Send
                              </button>
                              {isAdmin && (
                                <button onClick={() => openEditModal(t)} className="text-xs text-brand-primary hover:underline font-medium">
                                  Edit
                                </button>
                              )}
                              {isAdmin && (
                                <button onClick={() => handleDuplicate(t)} className="text-xs text-gray-600 hover:underline font-medium">
                                  Duplicate
                                </button>
                              )}
                              {isAdmin && t.scope !== 'platform' && (
                                <button onClick={() => handleDelete(t)} className="text-xs text-red-600 hover:underline font-medium">
                                  Delete
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Create/Edit Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={closeModal} />
          <div className="relative w-full max-w-lg mx-4 bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden max-h-[90vh]">
            {/* Modal Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
              <h2 className="text-lg font-semibold text-gray-900">
                {editingTemplate ? 'Edit SMS Template' : 'Create SMS Template'}
              </h2>
              <button
                onClick={closeModal}
                className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Modal Body */}
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
              {/* Name */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Template Name</label>
                <input
                  type="text"
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  placeholder="e.g. Practice Reminder"
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none"
                />
              </div>

              {/* Category */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select
                  value={formCategory}
                  onChange={(e) => setFormCategory(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none bg-white"
                >
                  {CATEGORY_META.map((c) => (
                    <option key={c.slug} value={c.slug}>{c.label}</option>
                  ))}
                </select>
              </div>

              {/* Scope */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Scope</label>
                <select
                  value={formScope}
                  onChange={(e) => setFormScope(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none bg-white"
                >
                  <option value="club">Club</option>
                  <option value="personal">Personal</option>
                  {isSuperAdmin && <option value="platform">Platform</option>}
                </select>
              </div>

              {/* Body */}
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="block text-sm font-medium text-gray-700">Message Body</label>
                  <div className="relative">
                    <button
                      type="button"
                      onClick={() => setMergePickerOpen(!mergePickerOpen)}
                      className="text-xs font-medium text-brand-primary hover:underline flex items-center gap-1"
                    >
                      <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z" />
                      </svg>
                      Insert Merge Field
                    </button>
                    {mergePickerOpen && (
                      <div className="absolute right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg py-1 min-w-[220px] z-50 max-h-60 overflow-y-auto">
                        {Object.entries(
                          mergeFields.reduce((acc, f) => {
                            const g = f.group || 'Other';
                            (acc[g] = acc[g] || []).push(f);
                            return acc;
                          }, {} as Record<string, MergeField[]>)
                        ).map(([group, fields]) => (
                          <div key={group}>
                            <div className="px-3 pt-2 pb-1 text-[10px] uppercase tracking-wider font-semibold text-gray-400">
                              {group}
                            </div>
                            {fields.map((f) => (
                              <button
                                key={f.key}
                                onClick={() => insertMergeField(f.key)}
                                className="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 transition-colors"
                              >
                                <span className="text-gray-900">{f.label}</span>
                                <span className="text-gray-400 ml-2 text-xs">{`{{${f.key}}}`}</span>
                              </button>
                            ))}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
                <textarea
                  ref={textareaRef}
                  value={formBody}
                  onChange={(e) => setFormBody(e.target.value)}
                  placeholder="Type your SMS template message..."
                  rows={6}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none resize-none font-mono"
                />

                {/* Character count and segment info */}
                <div className="flex items-center justify-between mt-1.5">
                  <div className="flex items-center gap-3">
                    <span className={`text-xs font-medium ${getCharCountColor()}`}>
                      {charCount} / {SMS_SEGMENT_LENGTH} characters
                    </span>
                    {segmentCount > 1 && (
                      <span className="text-xs text-amber-600 flex items-center gap-1">
                        <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {segmentCount} SMS segment{segmentCount > 1 ? 's' : ''} per recipient
                      </span>
                    )}
                  </div>
                  {charCount > 0 && (
                    <span className="text-xs text-gray-400">
                      {segmentCount <= 1
                        ? `${SMS_SEGMENT_LENGTH - charCount} remaining`
                        : `${segmentCount * SMS_CONCAT_SEGMENT_LENGTH - charCount} remaining in segment`}
                    </span>
                  )}
                </div>

                {segmentCount > 2 && (
                  <div className="mt-2 bg-red-50 border border-red-100 rounded-lg px-3 py-2 flex items-center gap-2">
                    <svg className="w-4 h-4 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p className="text-xs text-red-700">
                      Long messages cost more. This template will send as {segmentCount} segments per recipient.
                    </p>
                  </div>
                )}
              </div>
            </div>

            {/* Modal Footer */}
            <div className="px-6 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={closeModal}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-3 hover:bg-gray-50 uppercase font-semibold text-sm"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleSave}
                disabled={saving || !formName.trim() || !formBody.trim()}
                className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
              >
                {saving ? (
                  <>
                    <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Saving...
                  </>
                ) : (
                  editingTemplate ? 'Update Template' : 'Create Template'
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Send: SMS compose modal preloaded with this template (lazy-loaded) */}
      {composeTemplate && clubProfileId && (
        <React.Suspense fallback={null}>
          <SmsCompose
            isOpen={!!composeTemplate}
            onClose={() => setComposeTemplate(null)}
            clubProfileId={clubProfileId}
            preselectedTemplate={composeTemplate}
          />
        </React.Suspense>
      )}
    </div>
  );
};

export default SmsTemplates;

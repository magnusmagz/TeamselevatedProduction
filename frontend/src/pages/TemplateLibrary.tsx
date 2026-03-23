import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

interface EmailTemplate {
  id: number;
  name: string;
  subject: string;
  category: string;
  scope: 'club' | 'personal' | 'platform';
  team_visibility: number[];
  is_active: boolean;
  created_by: number;
  updated_at: string;
  created_at: string;
}

const CATEGORIES = ['All', 'General', 'Marketing', 'Transactional', 'Newsletter'] as const;

const categoryColors: Record<string, string> = {
  general: 'bg-gray-100 text-gray-700',
  marketing: 'bg-blue-100 text-blue-700',
  transactional: 'bg-green-100 text-green-700',
  newsletter: 'bg-purple-100 text-purple-700',
};

const TemplateLibrary: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const token = localStorage.getItem('auth_token');
  const navigate = useNavigate();
  const { user } = useAuth();
  const { activeContext } = useOrg();

  const [templates, setTemplates] = useState<EmailTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'club' | 'personal'>('club');
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  const [searchQuery, setSearchQuery] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [deleteConfirmId, setDeleteConfirmId] = useState<number | null>(null);
  const [duplicating, setDuplicating] = useState<number | null>(null);

  const clubProfileId = activeContext?.scope_id;
  const isAdmin = user?.activeRole?.role === 'club_admin' || user?.system_role === 'super_admin';

  useEffect(() => {
    if (clubProfileId) {
      fetchTemplates();
    }
  }, [clubProfileId]);

  const fetchTemplates = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(
        `${API_URL}/api/email-templates.php?action=list&club_profile_id=${clubProfileId}&channel=email`,
        {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) throw new Error('Failed to fetch templates');
      const data = await response.json();
      setTemplates(Array.isArray(data) ? data : data.templates || []);
    } catch (err) {
      console.error('Error fetching templates:', err);
      setError('Failed to load templates. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async (id: number) => {
    try {
      const response = await fetch(
        `${API_URL}/api/email-templates.php?action=delete&id=${id}`,
        {
          method: 'DELETE',
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        }
      );
      if (!response.ok) throw new Error('Failed to delete template');
      setTemplates((prev) => prev.filter((t) => t.id !== id));
      setDeleteConfirmId(null);
    } catch (err) {
      console.error('Error deleting template:', err);
      setError('Failed to delete template.');
    }
  };

  const handleDuplicate = async (id: number) => {
    setDuplicating(id);
    try {
      const response = await fetch(
        `${API_URL}/api/email-templates.php?action=duplicate`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
          body: JSON.stringify({ id }),
        }
      );
      if (!response.ok) throw new Error('Failed to duplicate template');
      await fetchTemplates();
    } catch (err) {
      console.error('Error duplicating template:', err);
      setError('Failed to duplicate template.');
    } finally {
      setDuplicating(null);
    }
  };

  const filteredTemplates = templates.filter((t) => {
    const matchesTab =
      activeTab === 'club'
        ? t.scope === 'club' || t.scope === 'platform'
        : t.scope === 'personal' && t.created_by === user?.id;
    const matchesSearch =
      searchQuery === '' ||
      t.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      t.subject.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesCategory =
      categoryFilter === 'All' || t.category.toLowerCase() === categoryFilter.toLowerCase();
    return matchesTab && matchesSearch && matchesCategory;
  });

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  const canEditTemplate = (template: EmailTemplate) => {
    if (template.scope === 'platform') return true; // Anyone can "edit" (clone-on-edit)
    if (isAdmin) return true;
    return template.scope === 'personal' && template.created_by === user?.id;
  };

  const handleEditTemplate = async (template: EmailTemplate) => {
    // Platform templates: clone first, then edit the clone
    if (template.scope === 'platform' && user?.system_role !== 'super_admin') {
      try {
        const res = await fetch(`${API_URL}/api/email-templates.php?action=clone-for-club`, {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
          body: JSON.stringify({ template_id: template.id, club_profile_id: clubProfileId }),
        });
        const data = await res.json();
        if (data.success) {
          navigate(`/email-templates/${data.clone_id}`);
          return;
        }
      } catch (err) {
        console.error('Failed to clone template:', err);
      }
    }
    navigate(`/email-templates/${template.id}`);
  };

  const canDeleteTemplate = (template: EmailTemplate) => {
    if (template.scope === 'club') return isAdmin;
    return template.created_by === user?.id;
  };

  // Loading skeleton
  if (loading) {
    return (
      <div className="p-6 max-w-7xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <div className="h-8 w-48 bg-gray-200 rounded animate-pulse" />
          <div className="h-10 w-36 bg-gray-200 rounded animate-pulse" />
        </div>
        <div className="flex gap-4 mb-6">
          <div className="h-10 w-32 bg-gray-200 rounded animate-pulse" />
          <div className="h-10 w-32 bg-gray-200 rounded animate-pulse" />
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {[1, 2, 3, 4, 5, 6].map((i) => (
            <div key={i} className="border rounded-lg p-4 space-y-3">
              <div className="h-5 w-3/4 bg-gray-200 rounded animate-pulse" />
              <div className="h-4 w-full bg-gray-200 rounded animate-pulse" />
              <div className="flex gap-2">
                <div className="h-6 w-20 bg-gray-200 rounded-full animate-pulse" />
                <div className="h-6 w-16 bg-gray-200 rounded-full animate-pulse" />
              </div>
              <div className="h-4 w-1/2 bg-gray-200 rounded animate-pulse" />
            </div>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-7xl mx-auto">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">Email Templates</h1>
        {isAdmin && (
          <button
            onClick={() => navigate('/email-templates/new')}
            className="inline-flex items-center bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Create Template
          </button>
        )}
      </div>

      {/* Error banner */}
      {error && (
        <div className="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg flex justify-between items-center">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="text-red-500 hover:text-red-700">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
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

      {/* Filters row */}
      <div className="flex flex-col sm:flex-row gap-3 mb-6">
        {/* Search */}
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
            placeholder="Search by name or subject..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none"
          />
        </div>

        {/* Category filter */}
        <select
          value={categoryFilter}
          onChange={(e) => setCategoryFilter(e.target.value)}
          className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none bg-white"
        >
          {CATEGORIES.map((cat) => (
            <option key={cat} value={cat}>
              {cat === 'All' ? 'All Categories' : cat}
            </option>
          ))}
        </select>

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

      {/* Empty state */}
      {filteredTemplates.length === 0 && !loading && (
        <div className="text-center py-16 px-4">
          <svg
            className="mx-auto h-16 w-16 text-gray-300 mb-4"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={1.5}
              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
            />
          </svg>
          <h3 className="text-lg font-medium text-gray-900 mb-1">No templates found</h3>
          <p className="text-gray-500 mb-6">
            {searchQuery || categoryFilter !== 'All'
              ? 'Try adjusting your search or filter criteria.'
              : activeTab === 'club'
              ? 'No club templates have been created yet. Get started by creating your first template.'
              : 'You have not created any personal templates yet.'}
          </p>
          {isAdmin && activeTab === 'club' && !searchQuery && categoryFilter === 'All' && (
            <button
              onClick={() => navigate('/email-templates/new')}
              className="inline-flex items-center bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Create Your First Template
            </button>
          )}
        </div>
      )}

      {/* Grid view */}
      {viewMode === 'grid' && filteredTemplates.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {filteredTemplates.map((template) => (
            <div
              key={template.id}
              className="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow bg-white"
            >
              <div className="flex justify-between items-start mb-2">
                <h3 className="text-sm font-semibold text-gray-900 line-clamp-1">{template.name}</h3>
                {!template.is_active && (
                  <span className="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full ml-2 whitespace-nowrap">
                    Inactive
                  </span>
                )}
              </div>
              <p className="text-sm text-gray-500 mb-3 line-clamp-1">{template.subject}</p>
              <div className="flex flex-wrap gap-2 mb-3">
                <span
                  className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                    categoryColors[template.category.toLowerCase()] || categoryColors.general
                  }`}
                >
                  {template.category}
                </span>
                <span
                  className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                    template.scope === 'club'
                      ? 'bg-brand-primary bg-opacity-10 text-brand-primary'
                      : 'bg-orange-100 text-orange-700'
                  }`}
                >
                  {template.scope === 'platform' ? 'Platform' : template.scope === 'club' ? 'Club' : 'Personal'}
                </span>
              </div>
              <p className="text-xs text-gray-400 mb-3">Updated {formatDate(template.updated_at)}</p>
              <div className="flex gap-2 border-t border-gray-100 pt-3">
                {canEditTemplate(template) && (
                  <button
                    onClick={() => handleEditTemplate(template)}
                    className="text-xs text-brand-primary hover:underline font-medium"
                  >
                    Edit
                  </button>
                )}
                <button
                  onClick={() => handleDuplicate(template.id)}
                  disabled={duplicating === template.id}
                  className="text-xs text-gray-600 hover:underline font-medium disabled:opacity-50"
                >
                  {duplicating === template.id ? 'Duplicating...' : 'Duplicate'}
                </button>
                {canDeleteTemplate(template) && (
                  <>
                    {deleteConfirmId === template.id ? (
                      <div className="flex gap-1 ml-auto">
                        <button
                          onClick={() => handleDelete(template.id)}
                          className="text-xs text-red-600 hover:underline font-medium"
                        >
                          Confirm
                        </button>
                        <button
                          onClick={() => setDeleteConfirmId(null)}
                          className="text-xs text-gray-400 hover:underline font-medium"
                        >
                          Cancel
                        </button>
                      </div>
                    ) : (
                      <button
                        onClick={() => setDeleteConfirmId(template.id)}
                        className="text-xs text-red-500 hover:underline font-medium ml-auto"
                      >
                        Delete
                      </button>
                    )}
                  </>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* List view */}
      {viewMode === 'list' && filteredTemplates.length > 0 && (
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Name
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Subject
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Category
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Scope
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Last Updated
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {filteredTemplates.map((template) => (
                  <tr key={template.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        {template.name}
                        {!template.is_active && (
                          <span className="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">
                            Inactive
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500 max-w-xs truncate">
                      {template.subject}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span
                        className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                          categoryColors[template.category.toLowerCase()] || categoryColors.general
                        }`}
                      >
                        {template.category}
                      </span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span
                        className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                          template.scope === 'club'
                            ? 'bg-brand-primary bg-opacity-10 text-brand-primary'
                            : 'bg-orange-100 text-orange-700'
                        }`}
                      >
                        {template.scope === 'platform' ? 'Platform' : template.scope === 'club' ? 'Club' : 'Personal'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                      {formatDate(template.updated_at)}
                    </td>
                    <td className="px-4 py-3 text-right whitespace-nowrap">
                      <div className="flex justify-end gap-3">
                        {canEditTemplate(template) && (
                          <button
                            onClick={() => handleEditTemplate(template)}
                            className="text-xs text-brand-primary hover:underline font-medium"
                          >
                            Edit
                          </button>
                        )}
                        <button
                          onClick={() => handleDuplicate(template.id)}
                          disabled={duplicating === template.id}
                          className="text-xs text-gray-600 hover:underline font-medium disabled:opacity-50"
                        >
                          {duplicating === template.id ? '...' : 'Duplicate'}
                        </button>
                        {canDeleteTemplate(template) && (
                          <>
                            {deleteConfirmId === template.id ? (
                              <div className="flex gap-2">
                                <button
                                  onClick={() => handleDelete(template.id)}
                                  className="text-xs text-red-600 hover:underline font-medium"
                                >
                                  Confirm
                                </button>
                                <button
                                  onClick={() => setDeleteConfirmId(null)}
                                  className="text-xs text-gray-400 hover:underline font-medium"
                                >
                                  Cancel
                                </button>
                              </div>
                            ) : (
                              <button
                                onClick={() => setDeleteConfirmId(template.id)}
                                className="text-xs text-red-500 hover:underline font-medium"
                              >
                                Delete
                              </button>
                            )}
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Delete confirmation modal */}
      {deleteConfirmId !== null && (
        <div
          className="fixed inset-0 bg-black bg-opacity-30 z-50 flex items-center justify-center p-4"
          onClick={() => setDeleteConfirmId(null)}
        >
          <div
            className="bg-white rounded-lg shadow-xl max-w-sm w-full p-6"
            onClick={(e) => e.stopPropagation()}
          >
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Delete Template</h3>
            <p className="text-sm text-gray-500 mb-4">
              Are you sure you want to delete this template? This action cannot be undone.
            </p>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => setDeleteConfirmId(null)}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-3 hover:bg-gray-50 uppercase font-semibold text-sm"
              >
                Cancel
              </button>
              <button
                onClick={() => handleDelete(deleteConfirmId)}
                className="bg-red-600 text-white border border-red-700 rounded-md px-6 py-3 hover:bg-red-700 uppercase font-semibold text-sm"
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default TemplateLibrary;

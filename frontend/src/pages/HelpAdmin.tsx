import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { useAuth } from '../contexts/AuthContext';
import {
  fetchAdminList,
  createArticle,
  updateArticle,
  deleteArticle,
  createCategory,
  updateCategory,
  deleteCategory,
  createReleaseNote,
  updateReleaseNote,
  deleteReleaseNote,
} from '../services/helpApi';
import { HelpArticle, HelpCategory, HelpReleaseNote } from '../types/help';
import HelpRoleBadge from '../components/help/HelpRoleBadge';
import HelpBreadcrumb from '../components/help/HelpBreadcrumb';
import PageHeader from '../components/ui/PageHeader';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';
import Button from '../components/ui/Button';

type Tab = 'articles' | 'categories' | 'release-notes';
type EditorMode = null | 'article' | 'category' | 'release-note';

const HelpAdmin: React.FC = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const isSuperAdmin = user?.system_role === 'super_admin';
  const [tab, setTab] = useState<Tab>('articles');
  const [articles, setArticles] = useState<HelpArticle[]>([]);
  const [categories, setCategories] = useState<HelpCategory[]>([]);
  const [releaseNotes, setReleaseNotes] = useState<HelpReleaseNote[]>([]);
  const [loading, setLoading] = useState(true);

  // Editor state
  const [editorMode, setEditorMode] = useState<EditorMode>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [showPreview, setShowPreview] = useState(false);
  const [saving, setSaving] = useState(false);

  // Article form
  const [formTitle, setFormTitle] = useState('');
  const [formSummary, setFormSummary] = useState('');
  const [formBody, setFormBody] = useState('');
  const [formCategoryId, setFormCategoryId] = useState(0);
  const [formRoleTags, setFormRoleTags] = useState<string[]>([]);
  const [formRelatedFeature, setFormRelatedFeature] = useState('');
  const [formPublished, setFormPublished] = useState(true);
  const [formSortOrder, setFormSortOrder] = useState(0);

  // Category form
  const [catName, setCatName] = useState('');
  const [catDescription, setCatDescription] = useState('');
  const [catRoleTag, setCatRoleTag] = useState('');
  const [catSortOrder, setCatSortOrder] = useState(0);

  // Release note form
  const [rnTitle, setRnTitle] = useState('');
  const [rnBody, setRnBody] = useState('');
  const [rnVersion, setRnVersion] = useState('');
  const [rnTags, setRnTags] = useState<string[]>([]);
  const [rnDate, setRnDate] = useState(new Date().toISOString().split('T')[0]);
  const [rnPublished, setRnPublished] = useState(true);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const data = await fetchAdminList();
      setArticles(data.articles);
      setCategories(data.categories);
      setReleaseNotes(data.release_notes);
    } catch {
      // error
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!isSuperAdmin) {
      navigate('/help');
      return;
    }
    loadData();
  }, [isSuperAdmin, navigate, loadData]);

  const resetForm = () => {
    setEditorMode(null);
    setEditingId(null);
    setFormTitle(''); setFormSummary(''); setFormBody(''); setFormCategoryId(0);
    setFormRoleTags([]); setFormRelatedFeature(''); setFormPublished(true); setFormSortOrder(0);
    setCatName(''); setCatDescription(''); setCatRoleTag(''); setCatSortOrder(0);
    setRnTitle(''); setRnBody(''); setRnVersion(''); setRnTags([]);
    setRnDate(new Date().toISOString().split('T')[0]); setRnPublished(true);
    setShowPreview(false);
  };

  // Article CRUD
  const editArticle = (a: HelpArticle) => {
    setEditorMode('article');
    setEditingId(a.id);
    setFormTitle(a.title);
    setFormSummary(a.summary || '');
    setFormBody(a.body_markdown);
    setFormCategoryId(a.category_id);
    setFormRoleTags(a.role_tags);
    setFormRelatedFeature(a.related_feature || '');
    setFormPublished(a.is_published);
    setFormSortOrder(a.sort_order);
  };

  const saveArticle = async () => {
    setSaving(true);
    try {
      const data = {
        title: formTitle, summary: formSummary, body_markdown: formBody,
        category_id: formCategoryId, role_tags: formRoleTags,
        related_feature: formRelatedFeature || null, is_published: formPublished,
        sort_order: formSortOrder, search_keywords: [],
      };
      if (editingId) {
        await updateArticle(editingId, data);
      } else {
        await createArticle(data);
      }
      resetForm();
      loadData();
    } catch { /* error */ } finally { setSaving(false); }
  };

  const removeArticle = async (id: number) => {
    if (!window.confirm('Delete this article?')) return;
    await deleteArticle(id);
    loadData();
  };

  // Category CRUD
  const editCategory = (c: HelpCategory) => {
    setEditorMode('category');
    setEditingId(c.id);
    setCatName(c.name);
    setCatDescription(c.description || '');
    setCatRoleTag(c.role_tag || '');
    setCatSortOrder(c.sort_order);
  };

  const saveCategory = async () => {
    setSaving(true);
    try {
      const data = { name: catName, description: catDescription || null, role_tag: catRoleTag || null, sort_order: catSortOrder };
      if (editingId) {
        await updateCategory(editingId, data);
      } else {
        await createCategory(data);
      }
      resetForm();
      loadData();
    } catch { /* error */ } finally { setSaving(false); }
  };

  const removeCategory = async (id: number) => {
    if (!window.confirm('Delete this category? It must have no articles.')) return;
    try {
      await deleteCategory(id);
      loadData();
    } catch (e: any) {
      alert(e.message);
    }
  };

  // Release note CRUD
  const editReleaseNote = (rn: HelpReleaseNote) => {
    setEditorMode('release-note');
    setEditingId(rn.id);
    setRnTitle(rn.title);
    setRnBody(rn.body_markdown);
    setRnVersion(rn.version || '');
    setRnTags(rn.tags);
    setRnDate(rn.release_date);
    setRnPublished(rn.is_published);
  };

  const saveReleaseNote = async () => {
    setSaving(true);
    try {
      const data = {
        title: rnTitle, body_markdown: rnBody, version: rnVersion || null,
        tags: rnTags, release_date: rnDate, is_published: rnPublished,
      };
      if (editingId) {
        await updateReleaseNote(editingId, data);
      } else {
        await createReleaseNote(data);
      }
      resetForm();
      loadData();
    } catch { /* error */ } finally { setSaving(false); }
  };

  const removeReleaseNote = async (id: number) => {
    if (!window.confirm('Delete this release note?')) return;
    await deleteReleaseNote(id);
    loadData();
  };

  const toggleRoleTag = (tag: string) => {
    setFormRoleTags((prev) => prev.includes(tag) ? prev.filter((t) => t !== tag) : [...prev, tag]);
  };

  const toggleRnTag = (tag: string) => {
    setRnTags((prev) => prev.includes(tag) ? prev.filter((t) => t !== tag) : [...prev, tag]);
  };

  if (loading) {
    return <div className="text-center text-brand-primary py-12">Loading...</div>;
  }

  // Editor views
  if (editorMode === 'article') {
    return (
      <div className="max-w-4xl mx-auto">
        <HelpBreadcrumb items={[{ label: 'Admin', to: '/help/admin' }, { label: editingId ? 'Edit Article' : 'New Article' }]} />
        <h2 className="text-xl font-bold text-brand-primary mb-4">{editingId ? 'Edit Article' : 'New Article'}</h2>

        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" value={formTitle} onChange={(e) => setFormTitle(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent" />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Category</label>
            <select value={formCategoryId} onChange={(e) => setFormCategoryId(Number(e.target.value))}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent">
              <option value={0}>Select category...</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Summary</label>
            <input type="text" value={formSummary} onChange={(e) => setFormSummary(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent"
              placeholder="One-line description for search results" />
          </div>

          <div>
            <div className="flex items-center justify-between mb-1">
              <label className="block text-sm font-medium text-gray-700">Body (Markdown)</label>
              <Button onClick={() => setShowPreview(!showPreview)} variant="link" size="sm">
                {showPreview ? 'Edit' : 'Preview'}
              </Button>
            </div>
            {showPreview ? (
              <div className="border border-gray-300 rounded-md p-4 prose prose-sm max-w-none min-h-[300px] bg-white">
                <ReactMarkdown remarkPlugins={[remarkGfm]}>{formBody}</ReactMarkdown>
              </div>
            ) : (
              <textarea value={formBody} onChange={(e) => setFormBody(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:border-brand-accent min-h-[300px]" />
            )}
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Role Tags</label>
              <div className="flex gap-2">
                {['admin', 'coach', 'parent'].map((tag) => (
                  <label key={tag} className="flex items-center gap-1 text-sm">
                    <input type="checkbox" checked={formRoleTags.includes(tag)} onChange={() => toggleRoleTag(tag)} />
                    {tag.charAt(0).toUpperCase() + tag.slice(1)}
                  </label>
                ))}
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Related Feature Key</label>
              <input type="text" value={formRelatedFeature} onChange={(e) => setFormRelatedFeature(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent"
                placeholder="e.g. email-compose" />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
              <input type="number" value={formSortOrder} onChange={(e) => setFormSortOrder(Number(e.target.value))}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent" />
            </div>
            <div className="flex items-end">
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={formPublished} onChange={(e) => setFormPublished(e.target.checked)} />
                Published
              </label>
            </div>
          </div>

          <div className="flex gap-3 pt-4">
            <Button onClick={saveArticle} loading={saving} disabled={!formTitle || !formCategoryId || !formBody}>
              {editingId ? 'Update Article' : 'Create Article'}
            </Button>
            <Button onClick={resetForm} variant="secondary">
              Cancel
            </Button>
          </div>
        </div>
      </div>
    );
  }

  if (editorMode === 'category') {
    return (
      <div className="max-w-2xl mx-auto">
        <HelpBreadcrumb items={[{ label: 'Admin', to: '/help/admin' }, { label: editingId ? 'Edit Category' : 'New Category' }]} />
        <h2 className="text-xl font-bold text-brand-primary mb-4">{editingId ? 'Edit Category' : 'New Category'}</h2>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <input type="text" value={catName} onChange={(e) => setCatName(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <input type="text" value={catDescription} onChange={(e) => setCatDescription(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent" />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Role Tag</label>
              <select value={catRoleTag} onChange={(e) => setCatRoleTag(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent">
                <option value="">General (all roles)</option>
                <option value="admin">Admin</option>
                <option value="coach">Coach</option>
                <option value="parent">Parent</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
              <input type="number" value={catSortOrder} onChange={(e) => setCatSortOrder(Number(e.target.value))}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent" />
            </div>
          </div>
          <div className="flex gap-3 pt-4">
            <Button onClick={saveCategory} loading={saving} disabled={!catName}>
              {editingId ? 'Update Category' : 'Create Category'}
            </Button>
            <Button onClick={resetForm} variant="secondary">
              Cancel
            </Button>
          </div>
        </div>
      </div>
    );
  }

  if (editorMode === 'release-note') {
    return (
      <div className="max-w-4xl mx-auto">
        <HelpBreadcrumb items={[{ label: 'Admin', to: '/help/admin' }, { label: editingId ? 'Edit Release Note' : 'New Release Note' }]} />
        <h2 className="text-xl font-bold text-brand-primary mb-4">{editingId ? 'Edit Release Note' : 'New Release Note'}</h2>
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Title</label>
              <input type="text" value={rnTitle} onChange={(e) => setRnTitle(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Version (optional)</label>
              <input type="text" value={rnVersion} onChange={(e) => setRnVersion(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent"
                placeholder="e.g. 2.4.0" />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Release Date</label>
              <input type="date" value={rnDate} onChange={(e) => setRnDate(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Tags</label>
              <div className="flex gap-2 pt-1">
                {['feature', 'improvement', 'bugfix', 'breaking'].map((tag) => (
                  <label key={tag} className="flex items-center gap-1 text-sm">
                    <input type="checkbox" checked={rnTags.includes(tag)} onChange={() => toggleRnTag(tag)} />
                    {tag.charAt(0).toUpperCase() + tag.slice(1)}
                  </label>
                ))}
              </div>
            </div>
          </div>
          <div>
            <div className="flex items-center justify-between mb-1">
              <label className="block text-sm font-medium text-gray-700">Body (Markdown)</label>
              <Button onClick={() => setShowPreview(!showPreview)} variant="link" size="sm">
                {showPreview ? 'Edit' : 'Preview'}
              </Button>
            </div>
            {showPreview ? (
              <div className="border border-gray-300 rounded-md p-4 prose prose-sm max-w-none min-h-[250px] bg-white">
                <ReactMarkdown remarkPlugins={[remarkGfm]}>{rnBody}</ReactMarkdown>
              </div>
            ) : (
              <textarea value={rnBody} onChange={(e) => setRnBody(e.target.value)}
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:border-brand-accent min-h-[250px]" />
            )}
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={rnPublished} onChange={(e) => setRnPublished(e.target.checked)} />
            Published
          </label>
          <div className="flex gap-3 pt-4">
            <Button onClick={saveReleaseNote} loading={saving} disabled={!rnTitle || !rnBody}>
              {editingId ? 'Update Release Note' : 'Create Release Note'}
            </Button>
            <Button onClick={resetForm} variant="secondary">
              Cancel
            </Button>
          </div>
        </div>
      </div>
    );
  }

  const articleColumns: DataTableColumn<HelpArticle>[] = [
    { key: 'title', header: 'Title', render: (a) => <span className="text-gray-900">{a.title}</span> },
    { key: 'category', header: 'Category', render: (a) => <span className="text-gray-500">{a.category_name}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (a) => (
        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${a.is_published ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
          {a.is_published ? 'Published' : 'Draft'}
        </span>
      ),
    },
    {
      key: 'roles',
      header: 'Roles',
      render: (a) => <div className="flex gap-1">{a.role_tags.map((t) => <HelpRoleBadge key={t} role={t} />)}</div>,
    },
    {
      key: 'actions',
      header: 'Actions',
      actions: true,
      render: (a) => (
        <>
          <Button onClick={() => editArticle(a)} variant="link" size="sm" className="mr-3">Edit</Button>
          <Button onClick={() => removeArticle(a.id)} variant="danger-link" size="sm">Delete</Button>
        </>
      ),
    },
  ];

  const categoryColumns: DataTableColumn<HelpCategory>[] = [
    { key: 'name', header: 'Name', render: (c) => <span className="text-gray-900">{c.name}</span> },
    { key: 'slug', header: 'Slug', render: (c) => <span className="text-gray-500 font-mono">{c.slug}</span> },
    {
      key: 'role',
      header: 'Role',
      render: (c) => (c.role_tag ? <HelpRoleBadge role={c.role_tag} /> : <span className="text-xs text-gray-400">All</span>),
    },
    { key: 'order', header: 'Order', render: (c) => <span className="text-gray-500">{c.sort_order}</span> },
    {
      key: 'actions',
      header: 'Actions',
      actions: true,
      render: (c) => (
        <>
          <Button onClick={() => editCategory(c)} variant="link" size="sm" className="mr-3">Edit</Button>
          <Button onClick={() => removeCategory(c.id)} variant="danger-link" size="sm">Delete</Button>
        </>
      ),
    },
  ];

  const releaseNoteColumns: DataTableColumn<HelpReleaseNote>[] = [
    { key: 'title', header: 'Title', render: (rn) => <span className="text-gray-900">{rn.title}</span> },
    { key: 'version', header: 'Version', render: (rn) => <span className="text-gray-500 font-mono">{rn.version || '-'}</span> },
    { key: 'date', header: 'Date', render: (rn) => <span className="text-gray-500">{rn.release_date}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (rn) => (
        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${rn.is_published ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
          {rn.is_published ? 'Published' : 'Draft'}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      actions: true,
      render: (rn) => (
        <>
          <Button onClick={() => editReleaseNote(rn)} variant="link" size="sm" className="mr-3">Edit</Button>
          <Button onClick={() => removeReleaseNote(rn.id)} variant="danger-link" size="sm">Delete</Button>
        </>
      ),
    },
  ];

  // Main admin list view
  return (
    <div className="max-w-5xl mx-auto">
      <HelpBreadcrumb items={[{ label: 'Admin' }]} />
      <PageHeader title="Help Portal Admin" />

      {/* Tabs */}
      <div className="flex border-b border-gray-200 mb-6">
        {(['articles', 'categories', 'release-notes'] as Tab[]).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              tab === t
                ? 'text-brand-primary border-brand-primary'
                : 'text-gray-500 border-transparent hover:text-gray-700'
            }`}
          >
            {t === 'articles' ? `Articles (${articles.length})` : t === 'categories' ? `Categories (${categories.length})` : `Release Notes (${releaseNotes.length})`}
          </button>
        ))}
      </div>

      {/* Articles tab */}
      {tab === 'articles' && (
        <>
          <Button onClick={() => setEditorMode('article')}
            className="mb-4">
            + New Article
          </Button>
          <DataTable<HelpArticle>
            columns={articleColumns}
            rows={articles}
            rowKey={(a) => a.id}
            emptyState="No articles yet"
          />
        </>
      )}

      {/* Categories tab */}
      {tab === 'categories' && (
        <>
          <Button onClick={() => setEditorMode('category')}
            className="mb-4">
            + New Category
          </Button>
          <DataTable<HelpCategory>
            columns={categoryColumns}
            rows={categories}
            rowKey={(c) => c.id}
            emptyState="No categories yet"
          />
        </>
      )}

      {/* Release Notes tab */}
      {tab === 'release-notes' && (
        <>
          <Button onClick={() => setEditorMode('release-note')}
            className="mb-4">
            + New Release Note
          </Button>
          <DataTable<HelpReleaseNote>
            columns={releaseNoteColumns}
            rows={releaseNotes}
            rowKey={(rn) => rn.id}
            emptyState="No release notes yet"
          />
        </>
      )}
    </div>
  );
};

export default HelpAdmin;

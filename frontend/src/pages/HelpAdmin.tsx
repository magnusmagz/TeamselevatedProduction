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
              <button onClick={() => setShowPreview(!showPreview)} className="text-xs text-brand-primary hover:underline">
                {showPreview ? 'Edit' : 'Preview'}
              </button>
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
            <button onClick={saveArticle} disabled={saving || !formTitle || !formCategoryId || !formBody}
              className="bg-brand-primary text-white px-4 py-2 rounded-md text-sm font-medium hover:opacity-90 disabled:opacity-50">
              {saving ? 'Saving...' : editingId ? 'Update Article' : 'Create Article'}
            </button>
            <button onClick={resetForm} className="px-4 py-2 rounded-md text-sm font-medium border border-gray-300 text-gray-600 hover:bg-gray-50">
              Cancel
            </button>
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
            <button onClick={saveCategory} disabled={saving || !catName}
              className="bg-brand-primary text-white px-4 py-2 rounded-md text-sm font-medium hover:opacity-90 disabled:opacity-50">
              {saving ? 'Saving...' : editingId ? 'Update Category' : 'Create Category'}
            </button>
            <button onClick={resetForm} className="px-4 py-2 rounded-md text-sm font-medium border border-gray-300 text-gray-600 hover:bg-gray-50">
              Cancel
            </button>
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
              <button onClick={() => setShowPreview(!showPreview)} className="text-xs text-brand-primary hover:underline">
                {showPreview ? 'Edit' : 'Preview'}
              </button>
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
            <button onClick={saveReleaseNote} disabled={saving || !rnTitle || !rnBody}
              className="bg-brand-primary text-white px-4 py-2 rounded-md text-sm font-medium hover:opacity-90 disabled:opacity-50">
              {saving ? 'Saving...' : editingId ? 'Update Release Note' : 'Create Release Note'}
            </button>
            <button onClick={resetForm} className="px-4 py-2 rounded-md text-sm font-medium border border-gray-300 text-gray-600 hover:bg-gray-50">
              Cancel
            </button>
          </div>
        </div>
      </div>
    );
  }

  // Main admin list view
  return (
    <div className="max-w-5xl mx-auto">
      <HelpBreadcrumb items={[{ label: 'Admin' }]} />
      <h1 className="text-2xl font-bold text-brand-primary mb-6">Help Portal Admin</h1>

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
          <button onClick={() => setEditorMode('article')}
            className="mb-4 bg-brand-primary text-white px-4 py-2 rounded-md text-sm font-medium hover:opacity-90">
            + New Article
          </button>
          <table className="min-w-full bg-white border border-gray-200 rounded-lg overflow-hidden">
            <thead>
              <tr className="bg-gray-50">
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Title</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Category</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Status</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Roles</th>
                <th className="px-4 py-2.5 text-right text-xs font-bold text-gray-500 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {articles.map((a) => (
                <tr key={a.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5 text-sm text-gray-900">{a.title}</td>
                  <td className="px-4 py-2.5 text-sm text-gray-500">{a.category_name}</td>
                  <td className="px-4 py-2.5">
                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${a.is_published ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                      {a.is_published ? 'Published' : 'Draft'}
                    </span>
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex gap-1">{a.role_tags.map((t) => <HelpRoleBadge key={t} role={t} />)}</div>
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    <button onClick={() => editArticle(a)} className="text-xs text-brand-primary hover:underline mr-3">Edit</button>
                    <button onClick={() => removeArticle(a.id)} className="text-xs text-red-600 hover:underline">Delete</button>
                  </td>
                </tr>
              ))}
              {articles.length === 0 && (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-sm text-gray-500">No articles yet</td></tr>
              )}
            </tbody>
          </table>
        </>
      )}

      {/* Categories tab */}
      {tab === 'categories' && (
        <>
          <button onClick={() => setEditorMode('category')}
            className="mb-4 bg-brand-primary text-white px-4 py-2 rounded-md text-sm font-medium hover:opacity-90">
            + New Category
          </button>
          <table className="min-w-full bg-white border border-gray-200 rounded-lg overflow-hidden">
            <thead>
              <tr className="bg-gray-50">
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Name</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Slug</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Role</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Order</th>
                <th className="px-4 py-2.5 text-right text-xs font-bold text-gray-500 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {categories.map((c) => (
                <tr key={c.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5 text-sm text-gray-900">{c.name}</td>
                  <td className="px-4 py-2.5 text-sm text-gray-500 font-mono">{c.slug}</td>
                  <td className="px-4 py-2.5">{c.role_tag ? <HelpRoleBadge role={c.role_tag} /> : <span className="text-xs text-gray-400">All</span>}</td>
                  <td className="px-4 py-2.5 text-sm text-gray-500">{c.sort_order}</td>
                  <td className="px-4 py-2.5 text-right">
                    <button onClick={() => editCategory(c)} className="text-xs text-brand-primary hover:underline mr-3">Edit</button>
                    <button onClick={() => removeCategory(c.id)} className="text-xs text-red-600 hover:underline">Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      {/* Release Notes tab */}
      {tab === 'release-notes' && (
        <>
          <button onClick={() => setEditorMode('release-note')}
            className="mb-4 bg-brand-primary text-white px-4 py-2 rounded-md text-sm font-medium hover:opacity-90">
            + New Release Note
          </button>
          <table className="min-w-full bg-white border border-gray-200 rounded-lg overflow-hidden">
            <thead>
              <tr className="bg-gray-50">
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Title</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Version</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Date</th>
                <th className="px-4 py-2.5 text-left text-xs font-bold text-gray-500 uppercase">Status</th>
                <th className="px-4 py-2.5 text-right text-xs font-bold text-gray-500 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {releaseNotes.map((rn) => (
                <tr key={rn.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5 text-sm text-gray-900">{rn.title}</td>
                  <td className="px-4 py-2.5 text-sm text-gray-500 font-mono">{rn.version || '-'}</td>
                  <td className="px-4 py-2.5 text-sm text-gray-500">{rn.release_date}</td>
                  <td className="px-4 py-2.5">
                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${rn.is_published ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                      {rn.is_published ? 'Published' : 'Draft'}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    <button onClick={() => editReleaseNote(rn)} className="text-xs text-brand-primary hover:underline mr-3">Edit</button>
                    <button onClick={() => removeReleaseNote(rn.id)} className="text-xs text-red-600 hover:underline">Delete</button>
                  </td>
                </tr>
              ))}
              {releaseNotes.length === 0 && (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-sm text-gray-500">No release notes yet</td></tr>
              )}
            </tbody>
          </table>
        </>
      )}
    </div>
  );
};

export default HelpAdmin;

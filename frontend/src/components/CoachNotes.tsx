import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';
import Button from './ui/Button';

interface CoachNotesProps {
  athleteId: number;
  clubProfileId: number;
}

interface CoachNote {
  id: number;
  athlete_id: number;
  coach_user_id: number;
  team_id: number | null;
  category: string;
  content: string;
  visibility: string;
  created_at: string;
  updated_at: string;
  coach_first_name: string;
  coach_last_name: string;
  team_name: string | null;
}

const CATEGORIES = [
  { value: 'performance', label: 'Performance' },
  { value: 'attitude', label: 'Attitude' },
  { value: 'improvement', label: 'Improvement' },
  { value: 'standout', label: 'Standout Moment' },
  { value: 'general', label: 'General' },
];

const CATEGORY_COLORS: Record<string, string> = {
  performance: '#12443e',
  attitude: '#3b82f6',
  improvement: '#f59e0b',
  standout: '#22c55e',
  general: '#6b7280',
};

const CoachNotes: React.FC<CoachNotesProps> = ({ athleteId, clubProfileId }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  const [notes, setNotes] = useState<CoachNote[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editingNote, setEditingNote] = useState<CoachNote | null>(null);

  // Form state
  const [category, setCategory] = useState('general');
  const [content, setContent] = useState('');
  const [visibility, setVisibility] = useState('coaches_only');

  const getToken = () => localStorage.getItem('auth_token');

  const fetchNotes = useCallback(async () => {
    try {
      const token = getToken();
      const res = await fetch(
        `${API_URL}/api/coach-notes.php?action=list&athlete_id=${athleteId}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      const data = await res.json();
      if (data.success) {
        setNotes(data.data);
      }
    } catch (err) {
      console.error('Failed to fetch coach notes:', err);
    } finally {
      setLoading(false);
    }
  }, [API_URL, athleteId]);

  useEffect(() => {
    fetchNotes();
  }, [fetchNotes]);

  const resetForm = () => {
    setCategory('general');
    setContent('');
    setVisibility('coaches_only');
    setEditingNote(null);
    setShowForm(false);
  };

  const handleSave = async () => {
    if (!content.trim()) return;
    setSaving(true);

    try {
      const token = getToken();

      if (editingNote) {
        // Update
        const res = await fetch(`${API_URL}/api/coach-notes.php?action=update`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({ id: editingNote.id, content, category, visibility }),
        });
        const data = await res.json();
        if (data.success) {
          setNotes((prev) =>
            prev.map((n) => (n.id === editingNote.id ? data.data : n))
          );
          resetForm();
        }
      } else {
        // Create
        const res = await fetch(`${API_URL}/api/coach-notes.php?action=create`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({ athlete_id: athleteId, category, content, visibility }),
        });
        const data = await res.json();
        if (data.success) {
          setNotes((prev) => [data.data, ...prev]);
          resetForm();
        }
      }
    } catch (err) {
      console.error('Failed to save note:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (noteId: number) => {
    if (!window.confirm('Are you sure you want to delete this note?')) return;

    try {
      const token = getToken();
      const res = await fetch(
        `${API_URL}/api/coach-notes.php?action=delete&id=${noteId}`,
        { method: 'DELETE', headers: { Authorization: `Bearer ${token}` } }
      );
      const data = await res.json();
      if (data.success) {
        setNotes((prev) => prev.filter((n) => n.id !== noteId));
      }
    } catch (err) {
      console.error('Failed to delete note:', err);
    }
  };

  const startEdit = (note: CoachNote) => {
    setEditingNote(note);
    setCategory(note.category);
    setContent(note.content);
    setVisibility(note.visibility);
    setShowForm(true);
  };

  const formatDate = (dateStr: string) => {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    });
  };

  if (loading) {
    return (
      <div className="flex justify-center py-8">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-800">Coach Notes</h3>
        {!showForm && (
          <Button onClick={() => { resetForm(); setShowForm(true); }}>
            + Add Note
          </Button>
        )}
      </div>

      {/* Add / Edit Form */}
      {showForm && (
        <div className="bg-white border border-gray-200 rounded-md p-4 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <h4 className="font-medium text-gray-700">
              {editingNote ? 'Edit Note' : 'New Note'}
            </h4>
            <Button variant="ghost" size="icon" aria-label="Close" className="text-lg" onClick={resetForm}>
              &times;
            </Button>
          </div>

          {/* Category */}
          <div>
            <label className="block text-sm font-medium text-gray-600 mb-1">Category</label>
            <select
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-primary"
            >
              {CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </select>
          </div>

          {/* Content */}
          <div>
            <label className="block text-sm font-medium text-gray-600 mb-1">Note</label>
            <textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              rows={4}
              placeholder="Write your coaching observation..."
              className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-primary resize-none"
            />
          </div>

          {/* Visibility */}
          <div>
            <label className="block text-sm font-medium text-gray-600 mb-1">Visibility</label>
            <div className="flex gap-4">
              <label className="flex items-center gap-2 text-sm cursor-pointer">
                <input
                  type="radio"
                  name="visibility"
                  value="coaches_only"
                  checked={visibility === 'coaches_only'}
                  onChange={(e) => setVisibility(e.target.value)}
                  className="text-brand-primary"
                />
                <span className="text-gray-700">Coaches Only</span>
              </label>
              <label className="flex items-center gap-2 text-sm cursor-pointer">
                <input
                  type="radio"
                  name="visibility"
                  value="share_with_parents"
                  checked={visibility === 'share_with_parents'}
                  onChange={(e) => setVisibility(e.target.value)}
                  className="text-brand-primary"
                />
                <span className="text-gray-700">Share with Crew</span>
              </label>
            </div>
          </div>

          {/* Actions */}
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={resetForm}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={!content.trim()} loading={saving}>
              {editingNote ? 'Update Note' : 'Save Note'}
            </Button>
          </div>
        </div>
      )}

      {/* Notes List */}
      {notes.length === 0 ? (
        <div className="text-center py-12 bg-white border border-brand-secondary rounded-md">
          <div className="text-gray-600 font-medium">No notes yet.</div>
          <div className="text-sm text-gray-500 mt-1">
            Add your first coaching observation.
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          {notes.map((note) => (
            <div
              key={note.id}
              className="bg-white border border-gray-200 rounded-md p-4 shadow-sm"
            >
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-2 flex-wrap">
                  {/* Category badge */}
                  <span
                    className="inline-block px-2 py-0.5 rounded-full text-xs font-semibold text-white"
                    style={{ backgroundColor: CATEGORY_COLORS[note.category] || '#6b7280' }}
                  >
                    {CATEGORIES.find((c) => c.value === note.category)?.label || note.category}
                  </span>
                  {/* Visibility indicator */}
                  {note.visibility === 'share_with_parents' && (
                    <span className="text-xs text-purple-600 bg-purple-50 px-2 py-0.5 rounded-full font-medium">
                      Shared with Parents
                    </span>
                  )}
                </div>

                {/* Edit / Delete (only for author) */}
                {user && user.id === note.coach_user_id && (
                  <div className="flex gap-1 ml-2 flex-shrink-0">
                    <Button variant="link" size="sm" onClick={() => startEdit(note)} title="Edit">
                      Edit
                    </Button>
                    <Button variant="danger-link" size="sm" onClick={() => handleDelete(note.id)} title="Delete">
                      Delete
                    </Button>
                  </div>
                )}
              </div>

              {/* Content */}
              <p className="text-gray-800 text-sm mt-2 whitespace-pre-wrap">{note.content}</p>

              {/* Meta */}
              <div className="flex items-center gap-3 mt-3 text-xs text-gray-500">
                <span>
                  {note.coach_first_name} {note.coach_last_name}
                </span>
                {note.team_name && (
                  <>
                    <span className="text-gray-300">|</span>
                    <span>{note.team_name}</span>
                  </>
                )}
                <span className="text-gray-300">|</span>
                <span>{formatDate(note.created_at)}</span>
                {note.updated_at !== note.created_at && (
                  <span className="italic">(edited)</span>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default CoachNotes;

import React, { useState, useEffect, useCallback } from 'react';

interface Subscription {
  id: number;
  club_id: number;
  team_id: number | null;
  name: string;
  feed_url: string | null;
  source_type: 'feed' | 'file_upload';
  is_active: boolean;
  sync_interval_minutes: number;
  last_synced_at: string | null;
  last_sync_status: string | null;
  last_sync_error: string | null;
  event_count: number;
  team_name: string | null;
  created_at: string;
}

interface Team {
  id: number;
  name: string;
}

interface CalendarSubscriptionManagerProps {
  clubId: number;
  teamId?: number;
  teams: Team[];
  onClose: () => void;
  onSync?: () => void;
}

const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

const CalendarSubscriptionManager: React.FC<CalendarSubscriptionManagerProps> = ({
  clubId,
  teamId,
  teams,
  onClose,
  onSync,
}) => {
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAddForm, setShowAddForm] = useState(false);
  const [showUploadForm, setShowUploadForm] = useState(false);
  const [syncing, setSyncing] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const [formName, setFormName] = useState('');
  const [formUrl, setFormUrl] = useState('');
  const [formTeamId, setFormTeamId] = useState<string>(teamId ? String(teamId) : '');
  const [formInterval, setFormInterval] = useState('60');
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadName, setUploadName] = useState('');
  const [uploadTeamId, setUploadTeamId] = useState<string>(teamId ? String(teamId) : '');
  const [submitting, setSubmitting] = useState(false);

  const token = localStorage.getItem('auth_token');
  const headers = {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  };

  const fetchSubscriptions = useCallback(async () => {
    try {
      setLoading(true);
      const params = new URLSearchParams({ action: 'list', club_id: String(clubId) });
      if (teamId) params.set('team_id', String(teamId));

      const res = await fetch(`${API_URL}/api/calendar-subscriptions-gateway.php?${params}`, { headers });
      const data = await res.json();

      if (!res.ok) throw new Error(data.error || 'Failed to load subscriptions');
      setSubscriptions(data.subscriptions || []);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }, [clubId, teamId]);

  useEffect(() => {
    fetchSubscriptions();
  }, [fetchSubscriptions]);

  const handleCreateFeed = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      const res = await fetch(`${API_URL}/api/calendar-subscriptions-gateway.php?action=create`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          club_id: clubId,
          name: formName,
          feed_url: formUrl,
          team_id: formTeamId ? Number(formTeamId) : null,
          sync_interval_minutes: Number(formInterval),
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to create subscription');

      setSuccess('Feed subscription created');
      setShowAddForm(false);
      setFormName('');
      setFormUrl('');
      setFormInterval('60');
      fetchSubscriptions();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleUpload = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!uploadFile) return;

    setSubmitting(true);
    setError(null);

    try {
      const formData = new FormData();
      formData.append('ics_file', uploadFile);
      formData.append('club_id', String(clubId));
      formData.append('name', uploadName);
      if (uploadTeamId) formData.append('team_id', uploadTeamId);

      const res = await fetch(`${API_URL}/api/calendar-subscriptions-gateway.php?action=upload`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` },
        body: formData,
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to upload file');

      setSuccess(`Imported ${data.result?.imported || 0} events`);
      setShowUploadForm(false);
      setUploadFile(null);
      setUploadName('');
      fetchSubscriptions();
      onSync?.();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleSyncNow = async (id: number) => {
    setSyncing(id);
    setError(null);

    try {
      const res = await fetch(`${API_URL}/api/calendar-subscriptions-gateway.php?action=sync&id=${id}`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to queue sync');

      setSuccess('Sync job queued — events will update shortly');
      setTimeout(() => {
        fetchSubscriptions();
        onSync?.();
      }, 3000);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSyncing(null);
    }
  };

  const handleDelete = async (id: number, name: string) => {
    const removeEvents = window.confirm(
      `Delete "${name}"?\n\nClick OK to also remove all imported events, or Cancel to keep the events.`
    );

    const confirmed = window.confirm(`Are you sure you want to delete "${name}"?`);
    if (!confirmed) return;

    try {
      const res = await fetch(
        `${API_URL}/api/calendar-subscriptions-gateway.php?action=delete&id=${id}&remove_events=${removeEvents}`,
        { method: 'DELETE', headers }
      );
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to delete');

      setSuccess(data.message);
      fetchSubscriptions();
      if (removeEvents) onSync?.();
    } catch (err: any) {
      setError(err.message);
    }
  };

  const handleToggleActive = async (sub: Subscription) => {
    try {
      const res = await fetch(`${API_URL}/api/calendar-subscriptions-gateway.php?action=update&id=${sub.id}`, {
        method: 'PUT',
        headers,
        body: JSON.stringify({ is_active: !sub.is_active }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to update');
      fetchSubscriptions();
    } catch (err: any) {
      setError(err.message);
    }
  };

  const formatDate = (dateStr: string | null) => {
    if (!dateStr) return 'Never';
    return new Date(dateStr).toLocaleString();
  };

  const intervalOptions = [
    { value: '15', label: 'Every 15 min' },
    { value: '30', label: 'Every 30 min' },
    { value: '60', label: 'Every hour' },
    { value: '240', label: 'Every 4 hours' },
    { value: '720', label: 'Every 12 hours' },
    { value: '1440', label: 'Every 24 hours' },
  ];

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b border-gray-200">
          <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide">
            Calendar Subscriptions
          </h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>

        {/* Alerts */}
        <div className="px-4 pt-2">
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded mb-2 text-sm flex justify-between">
              <span>{error}</span>
              <button onClick={() => setError(null)} className="ml-2 font-bold">&times;</button>
            </div>
          )}
          {success && (
            <div className="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded mb-2 text-sm flex justify-between">
              <span>{success}</span>
              <button onClick={() => setSuccess(null)} className="ml-2 font-bold">&times;</button>
            </div>
          )}
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-4">
          {/* Action Buttons */}
          {!showAddForm && !showUploadForm && (
            <div className="flex gap-2 mb-4">
              <button
                onClick={() => { setShowAddForm(true); setShowUploadForm(false); }}
                className="bg-brand-primary text-white px-3 py-2 rounded text-sm font-semibold uppercase hover:opacity-90"
              >
                + Add Feed
              </button>
              <button
                onClick={() => { setShowUploadForm(true); setShowAddForm(false); }}
                className="bg-white text-brand-primary border border-brand-primary px-3 py-2 rounded text-sm font-semibold uppercase hover:bg-gray-50"
              >
                Upload .ics File
              </button>
            </div>
          )}

          {/* Add Feed Form */}
          {showAddForm && (
            <form onSubmit={handleCreateFeed} className="bg-gray-50 border rounded-lg p-4 mb-4 space-y-3">
              <h3 className="font-semibold text-sm uppercase text-gray-700">Subscribe to Calendar Feed</h3>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input
                  type="text"
                  value={formName}
                  onChange={e => setFormName(e.target.value)}
                  placeholder="e.g. League Schedule"
                  className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Feed URL</label>
                <input
                  type="url"
                  value={formUrl}
                  onChange={e => setFormUrl(e.target.value)}
                  placeholder="https:// or webcal://"
                  className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                  required
                />
                <p className="text-xs text-gray-500 mt-1">Paste the ICS/iCal feed URL from your league, tournament, or field scheduling site</p>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Team</label>
                  <select
                    value={formTeamId}
                    onChange={e => setFormTeamId(e.target.value)}
                    className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                  >
                    <option value="">Club-wide</option>
                    {teams.map(t => (
                      <option key={t.id} value={t.id}>{t.name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Sync Interval</label>
                  <select
                    value={formInterval}
                    onChange={e => setFormInterval(e.target.value)}
                    className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                  >
                    {intervalOptions.map(o => (
                      <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="flex gap-2 pt-1">
                <button
                  type="submit"
                  disabled={submitting}
                  className="bg-brand-primary text-white px-4 py-2 rounded text-sm font-semibold uppercase disabled:opacity-50"
                >
                  {submitting ? 'Creating...' : 'Subscribe'}
                </button>
                <button
                  type="button"
                  onClick={() => setShowAddForm(false)}
                  className="text-gray-600 px-4 py-2 rounded text-sm"
                >
                  Cancel
                </button>
              </div>
            </form>
          )}

          {/* Upload Form */}
          {showUploadForm && (
            <form onSubmit={handleUpload} className="bg-gray-50 border rounded-lg p-4 mb-4 space-y-3">
              <h3 className="font-semibold text-sm uppercase text-gray-700">Upload .ics File</h3>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input
                  type="text"
                  value={uploadName}
                  onChange={e => setUploadName(e.target.value)}
                  placeholder="e.g. Tournament Schedule"
                  className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">File</label>
                <input
                  type="file"
                  accept=".ics,.ical"
                  onChange={e => setUploadFile(e.target.files?.[0] || null)}
                  className="w-full text-sm"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Team</label>
                <select
                  value={uploadTeamId}
                  onChange={e => setUploadTeamId(e.target.value)}
                  className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                >
                  <option value="">Club-wide</option>
                  {teams.map(t => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                  ))}
                </select>
              </div>
              <div className="flex gap-2 pt-1">
                <button
                  type="submit"
                  disabled={submitting || !uploadFile}
                  className="bg-brand-primary text-white px-4 py-2 rounded text-sm font-semibold uppercase disabled:opacity-50"
                >
                  {submitting ? 'Importing...' : 'Import'}
                </button>
                <button
                  type="button"
                  onClick={() => setShowUploadForm(false)}
                  className="text-gray-600 px-4 py-2 rounded text-sm"
                >
                  Cancel
                </button>
              </div>
            </form>
          )}

          {/* Subscriptions List */}
          {loading ? (
            <div className="text-center text-gray-500 py-8">Loading...</div>
          ) : subscriptions.length === 0 ? (
            <div className="text-center text-gray-500 py-8">
              <p className="text-lg mb-2">No calendar subscriptions yet</p>
              <p className="text-sm">Subscribe to an external calendar feed or upload an .ics file to import events</p>
            </div>
          ) : (
            <div className="space-y-3">
              {subscriptions.map(sub => (
                <div key={sub.id} className="border border-gray-200 rounded-lg p-3 hover:border-gray-300 transition-colors">
                  <div className="flex items-start justify-between">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <h4 className="font-semibold text-sm text-gray-900 truncate">{sub.name}</h4>
                        <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
                          sub.source_type === 'feed'
                            ? 'bg-blue-100 text-blue-700'
                            : 'bg-gray-100 text-gray-600'
                        }`}>
                          {sub.source_type === 'feed' ? 'Feed' : 'Upload'}
                        </span>
                        {sub.source_type === 'feed' && (
                          <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
                            sub.is_active ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'
                          }`}>
                            {sub.is_active ? 'Active' : 'Paused'}
                          </span>
                        )}
                        {sub.last_sync_status === 'error' && (
                          <span className="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                            Error
                          </span>
                        )}
                      </div>
                      <div className="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-xs text-gray-500">
                        {sub.team_name && <span>Team: {sub.team_name}</span>}
                        <span>{sub.event_count} events</span>
                        <span>Last synced: {formatDate(sub.last_synced_at)}</span>
                      </div>
                      {sub.last_sync_status === 'error' && sub.last_sync_error && (
                        <p className="text-xs text-red-600 mt-1 truncate" title={sub.last_sync_error}>
                          {sub.last_sync_error}
                        </p>
                      )}
                    </div>
                  </div>

                  {/* Row Actions */}
                  <div className="flex gap-2 mt-2 pt-2 border-t border-gray-100">
                    {sub.source_type === 'feed' && (
                      <>
                        <button
                          onClick={() => handleSyncNow(sub.id)}
                          disabled={syncing === sub.id}
                          className="text-xs text-blue-600 hover:text-blue-800 font-medium disabled:opacity-50"
                        >
                          {syncing === sub.id ? 'Syncing...' : 'Sync Now'}
                        </button>
                        <button
                          onClick={() => handleToggleActive(sub)}
                          className="text-xs text-gray-600 hover:text-gray-800 font-medium"
                        >
                          {sub.is_active ? 'Pause' : 'Resume'}
                        </button>
                      </>
                    )}
                    <button
                      onClick={() => handleDelete(sub.id, sub.name)}
                      className="text-xs text-red-600 hover:text-red-800 font-medium ml-auto"
                    >
                      Delete
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default CalendarSubscriptionManager;

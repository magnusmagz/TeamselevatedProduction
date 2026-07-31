import React, { useCallback, useEffect, useState } from 'react';
import { useOrg } from '../contexts/OrgContext';

const API_URL = process.env.REACT_APP_API_URL || '';

/**
 * Chat moderation review queue.
 *
 * Human reports and automated flags arrive in the same list — one admin inbox,
 * not two. The admin reads the reported message here, then clicks through to the
 * conversation in chat to remove it if warranted. Removal deliberately does not
 * live on this page: it is a chat-server socket action so the tombstone reaches
 * everyone in the room live.
 *
 * The header surfaces queue HEALTH, not just a count. An unactioned flag sitting
 * for months is discoverable evidence that a club was told and did nothing, so
 * the age of the oldest open item is the number that matters.
 */

interface Report {
  id: number;
  message_id: number;
  conversation_id: number | null;
  club_id: number | null;
  source: 'user' | 'auto';
  rule: string | null;
  severity: 'low' | 'medium' | 'high';
  note: string | null;
  status: 'open' | 'actioned' | 'dismissed';
  created_at: string;
  reviewed_at: string | null;
  message_text: string | null;
  message_removed: boolean;
  sender_name: string | null;
  message_created_at: string | null;
  conversation_type: string | null;
  team_name: string | null;
  reporter_first_name: string | null;
  reporter_last_name: string | null;
  reviewer_first_name: string | null;
  reviewer_last_name: string | null;
}

const REASON_LABELS: Record<string, string> = {
  safety_concern: 'Safety concern',
  harassment: 'Harassment or bullying',
  inappropriate: 'Inappropriate content',
  personal_information: 'Shares personal information',
  spam: 'Spam',
  other: 'Something else',
};

function daysSince(iso: string | null): number | null {
  if (!iso) return null;
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return null;
  return Math.floor((Date.now() - then) / 86_400_000);
}

function SeverityBadge({ severity }: { severity: Report['severity'] }) {
  const styles: Record<string, string> = {
    high: 'bg-red-100 text-red-800 border-red-200',
    medium: 'bg-amber-100 text-amber-800 border-amber-200',
    low: 'bg-gray-100 text-gray-700 border-gray-200',
  };
  return (
    <span className={`text-xs font-medium px-2 py-0.5 rounded border ${styles[severity] || styles.low}`}>
      {severity}
    </span>
  );
}

export default function ChatModeration() {
  const { activeContext } = useOrg();
  const clubId = (activeContext as any)?.scope_id ?? null;

  const [reports, setReports] = useState<Report[]>([]);
  const [openCount, setOpenCount] = useState(0);
  const [oldestOpenAt, setOldestOpenAt] = useState<string | null>(null);
  const [status, setStatus] = useState<'open' | 'all'>('open');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const authHeaders = useCallback((): Record<string, string> => {
    const token = localStorage.getItem('auth_token');
    return token
      ? { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }
      : { 'Content-Type': 'application/json' };
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ action: 'queue', status });
      if (clubId) params.set('club_id', String(clubId));
      const res = await fetch(`${API_URL}/api/chat-moderation.php?${params}`, {
        headers: authHeaders(),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.error || 'Could not load the queue');
      setReports(data.reports || []);
      setOpenCount(data.open_count || 0);
      setOldestOpenAt(data.oldest_open_at || null);
    } catch (e: any) {
      setError(e.message || 'Could not load the queue');
    } finally {
      setLoading(false);
    }
  }, [status, clubId, authHeaders]);

  useEffect(() => {
    load();
  }, [load]);

  const close = async (reportId: number, action: 'dismiss' | 'actioned') => {
    setBusyId(reportId);
    try {
      const res = await fetch(`${API_URL}/api/chat-moderation.php?action=${action}`, {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({ report_id: reportId }),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.error || 'Could not update the report');
      await load();
    } catch (e: any) {
      setError(e.message || 'Could not update the report');
    } finally {
      setBusyId(null);
    }
  };

  const oldestDays = daysSince(oldestOpenAt);

  return (
    <div className="p-6 max-w-5xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-brand-primary">Reported Messages</h1>
        <p className="text-sm text-gray-500 mt-1">
          Messages reported by members or flagged automatically. Open the conversation in
          chat to remove a message — removing it leaves a visible note in the thread and is
          recorded in the audit log.
        </p>
      </div>

      {/* Queue health. The age of the oldest open item is the number that matters. */}
      <div className="flex flex-wrap gap-4 mb-6">
        <div className="px-4 py-3 bg-white border border-gray-200 rounded-lg min-w-[9rem]">
          <div className="text-2xl font-semibold text-brand-primary">{openCount}</div>
          <div className="text-xs text-gray-500">Awaiting review</div>
        </div>
        {oldestDays !== null && (
          <div
            className={`px-4 py-3 rounded-lg border min-w-[9rem] ${
              oldestDays >= 7
                ? 'bg-red-50 border-red-200'
                : oldestDays >= 3
                ? 'bg-amber-50 border-amber-200'
                : 'bg-white border-gray-200'
            }`}
          >
            <div className="text-2xl font-semibold text-brand-primary">
              {oldestDays}
              <span className="text-sm font-normal text-gray-500 ml-1">
                {oldestDays === 1 ? 'day' : 'days'}
              </span>
            </div>
            <div className="text-xs text-gray-500">Oldest unreviewed</div>
          </div>
        )}
      </div>

      <div className="flex gap-2 mb-4">
        {(['open', 'all'] as const).map(s => (
          <button
            key={s}
            onClick={() => setStatus(s)}
            className={`px-3 py-1.5 text-sm rounded-md border ${
              status === s
                ? 'bg-brand-primary text-white border-brand-primary'
                : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
            }`}
          >
            {s === 'open' ? 'Awaiting review' : 'All'}
          </button>
        ))}
      </div>

      {error && (
        <div className="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
          {error}
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary" />
        </div>
      ) : reports.length === 0 ? (
        <div className="text-center py-12 border border-gray-200 rounded-lg bg-white">
          <p className="text-gray-600">
            {status === 'open' ? 'Nothing awaiting review.' : 'No reports yet.'}
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {reports.map(r => (
            <div
              key={r.id}
              className={`border rounded-lg bg-white p-4 ${
                r.status === 'open' ? 'border-gray-200' : 'border-gray-100 opacity-70'
              }`}
            >
              <div className="flex items-start justify-between gap-4 mb-2">
                <div className="flex items-center gap-2 flex-wrap">
                  <SeverityBadge severity={r.severity} />
                  <span className="text-xs text-gray-500">
                    {r.source === 'auto'
                      ? `Auto-flagged${r.rule ? `: ${r.rule}` : ''}`
                      : REASON_LABELS[r.rule || ''] || 'Reported'}
                  </span>
                  {r.team_name && (
                    <span className="text-xs bg-brand-secondary text-brand-primary px-1.5 py-0.5 rounded">
                      {r.team_name}
                    </span>
                  )}
                  {r.status !== 'open' && (
                    <span className="text-xs text-gray-400 capitalize">{r.status}</span>
                  )}
                </div>
                <span className="text-xs text-gray-400 flex-shrink-0">
                  {new Date(r.created_at).toLocaleDateString()}
                </span>
              </div>

              {/* The reported message. Already-removed messages carry no text —
                  the server nulls it, so the queue can never echo it back. */}
              <div className="bg-gray-50 border border-gray-100 rounded p-3 mb-3">
                <div className="text-xs text-gray-500 mb-1">
                  {r.sender_name || 'Unknown sender'}
                  {r.message_created_at && ` · ${new Date(r.message_created_at).toLocaleString()}`}
                </div>
                {r.message_removed ? (
                  <p className="text-sm italic text-gray-500">
                    This message has already been removed.
                  </p>
                ) : (
                  <p className="text-sm text-gray-800 whitespace-pre-wrap break-words">
                    {r.message_text}
                  </p>
                )}
              </div>

              {r.note && (
                <p className="text-sm text-gray-600 mb-3">
                  <span className="text-xs text-gray-400">Reporter’s note: </span>
                  {r.note}
                </p>
              )}

              <div className="flex items-center justify-between gap-3">
                <span className="text-xs text-gray-400">
                  {r.source === 'user' && (r.reporter_first_name || r.reporter_last_name)
                    ? `Reported by ${r.reporter_first_name || ''} ${r.reporter_last_name || ''}`.trim()
                    : r.source === 'auto'
                    ? 'Flagged automatically'
                    : 'Reported'}
                  {r.status !== 'open' && r.reviewer_first_name
                    ? ` · reviewed by ${r.reviewer_first_name} ${r.reviewer_last_name || ''}`.trim()
                    : ''}
                </span>

                {r.status === 'open' && (
                  <div className="flex gap-2">
                    <button
                      onClick={() => close(r.id, 'dismiss')}
                      disabled={busyId === r.id}
                      className="px-3 py-1.5 text-sm border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                    >
                      No action needed
                    </button>
                    <button
                      onClick={() => close(r.id, 'actioned')}
                      disabled={busyId === r.id}
                      className="px-3 py-1.5 text-sm bg-brand-primary text-white rounded-md hover:bg-brand-primary/90 disabled:opacity-50"
                    >
                      Mark handled
                    </button>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

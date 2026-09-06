import React, { useCallback, useEffect, useState } from 'react';
import { useOrg } from '../contexts/OrgContext';
import PageHeader from '../components/ui/PageHeader';

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

/** Auto-rule names, for the queue. Keep in step with RULES in chat-server/lib/flags.js. */
const RULE_LABELS: Record<string, string> = {
  hate_speech: 'Racial or hate slur',
  secrecy: 'Asking to keep it secret',
  off_platform_contact: 'Contact details / off-platform',
  profanity: 'Profanity',
  external_app: 'Another messaging app',
};

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
  const [summary, setSummary] = useState<Record<string, number> | null>(null);

  const authHeaders = useCallback((): Record<string, string> => {
    const token = localStorage.getItem('auth_token');
    return token
      ? { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }
      : { 'Content-Type': 'application/json' };
  }, []);

  // Loaded lazily, on first expand — a club admin opening the queue to act on a
  // report should not pay for a 90-day aggregate they did not ask for.
  const loadSummary = useCallback(async () => {
    try {
      const params = new URLSearchParams({ action: 'summary', days: '90' });
      if (clubId) params.set('club_id', String(clubId));
      const res = await fetch(`${API_URL}/api/chat-moderation.php?${params}`, {
        headers: authHeaders(),
      });
      const data = await res.json();
      if (res.ok && data.success) setSummary(data.summary);
    } catch {
      /* The summary is informational; a failure must not disturb the queue. */
    }
  }, [clubId, authHeaders]);

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
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title="Reported Messages"
        subtitle="Messages reported by members or flagged automatically. Open the conversation in chat to remove a message — removing it leaves a visible note in the thread and is recorded in the audit log."
      />

      {/* Queue health. The age of the oldest open item is the number that matters. */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="bg-white rounded-lg border border-brand-secondary p-5">
          <p className="text-sm font-medium text-brand-primary">Awaiting review</p>
          <p className="text-3xl font-bold text-brand-primary mt-1">{openCount}</p>
        </div>
        {oldestDays !== null && (
          <div
            className={`bg-white rounded-lg border p-5 ${
              oldestDays >= 7
                ? 'border-red-200'
                : oldestDays >= 3
                ? 'border-amber-200'
                : 'border-brand-secondary'
            }`}
          >
            <p
              className={`text-sm font-medium ${
                oldestDays >= 7 ? 'text-red-600' : oldestDays >= 3 ? 'text-amber-600' : 'text-brand-primary'
              }`}
            >
              Oldest unreviewed
            </p>
            <p
              className={`text-3xl font-bold mt-1 ${
                oldestDays >= 7 ? 'text-red-700' : oldestDays >= 3 ? 'text-amber-700' : 'text-brand-primary'
              }`}
            >
              {oldestDays}
              <span className="text-sm font-normal text-gray-500 ml-1">
                {oldestDays === 1 ? 'day' : 'days'}
              </span>
            </p>
          </div>
        )}
      </div>

      {/* Compliance summary — the artifact a club hands to a board or an insurer.
          Counts actions, never content, so it can be shared without carrying the
          thing that was reported. */}
      <details className="mb-6 border border-brand-secondary rounded-lg bg-white">
        <summary
          className="px-4 py-3 cursor-pointer text-sm font-semibold text-brand-primary uppercase tracking-wide select-none"
          onClick={() => { if (!summary) loadSummary(); }}
        >
          Oversight summary (last 90 days)
        </summary>
        <div className="px-4 pb-4 pt-1">
          {!summary ? (
            <p className="text-sm text-gray-400">Loading…</p>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              {[
                ['Reports received', summary.reports_total],
                ['From members', summary.reports_from_members],
                ['Auto-flagged', summary.reports_auto_flagged],
                ['High severity', summary.reports_high_severity],
                ['Reviewed', summary.reviewed],
                ['Still open', summary.still_open],
                ['Messages removed', summary.messages_removed],
                ['Admin conversation reads', summary.admin_reads],
              ].map(([label, value]) => (
                <div key={String(label)}>
                  <div className="text-xl font-semibold text-brand-primary">{value as number}</div>
                  <div className="text-xs text-gray-500">{label as string}</div>
                </div>
              ))}
            </div>
          )}
          <p className="text-xs text-gray-400 mt-4">
            Counts actions only — no message content is included. Every admin read of a
            conversation they are not part of is recorded and counted here.
          </p>
        </div>
      </details>

      {error && (
        <div className="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
          {error}
        </div>
      )}

      <div className="bg-white rounded-lg border border-brand-secondary overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
          <h2 className="text-sm font-semibold text-brand-primary uppercase tracking-wide">
            {status === 'open' ? 'Awaiting review' : 'All reports'}
          </h2>
          <div className="flex gap-2">
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
        </div>

        {loading ? (
        <div className="flex justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary" />
        </div>
      ) : reports.length === 0 ? (
        <div className="text-center py-12">
          <p className="text-gray-600">
            {status === 'open' ? 'Nothing awaiting review.' : 'No reports yet.'}
          </p>
        </div>
      ) : (
        <div className="divide-y divide-gray-200">
          {reports.map(r => (
            <div
              key={r.id}
              className={`p-4 ${r.status === 'open' ? '' : 'opacity-70'}`}
            >
              <div className="flex items-start justify-between gap-4 mb-2">
                <div className="flex items-center gap-2 flex-wrap">
                  <SeverityBadge severity={r.severity} />
                  <span className="text-xs text-gray-500">
                    {r.source === 'auto'
                      ? `Auto-flagged${r.rule ? `: ${RULE_LABELS[r.rule] || r.rule}` : ''}`
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
    </div>
  );
}

import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useOrg } from '../contexts/OrgContext';
import { SMS_SEGMENT_LENGTH, countSmsSegments } from '../utils/smsSegments';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

/**
 * SMS Inbox (M3 + M4 of docs/sms-inbox-scope.md).
 *
 * Replies to the club's number, threaded, and answerable — the answer goes out as
 * a text from that same number.
 *
 * Design decisions live in docs/mockups/sms-inbox.html; three that matter:
 *  - "Needs reply" is the default view, because the job is clearing a queue.
 *  - The auto-reply is SHOWN and marked automated. Hiding it would let an admin
 *    write an answer contradicting what the family already received.
 *  - A failed send keeps the typed text in the box. Retyping a reply you already
 *    wrote is worse than the failure.
 *
 * The auto-reply still fires on every inbound and still says the number is not
 * monitored. That is deliberate and Maggie's call: a club should be genuinely
 * ready to engage before we promise families someone will answer. The copy changes
 * when a club says so, not when the button shipped.
 */

type Filter = 'needs_reply' | 'unread' | 'all';

interface Thread {
  conversation_id: string;
  contact_name: string | null;
  contact_phone: string | null;
  contact_type: string | null;
  contact_id: number | null;
  athlete_name: string | null;
  last_body: string | null;
  last_at: string;
  unread: number;
  needs_reply: boolean;
}

interface Message {
  id: number;
  direction: 'inbound' | 'outbound';
  body: string;
  status: string;
  created_at: string;
  automated: boolean;
  sent_by: string | null;
}

interface ThreadDetail {
  conversation_id: string;
  contact: {
    name: string | null; phone: string | null; type: string | null;
    id: number | null; athlete_id: number | null; athlete_name: string | null;
  };
  sending_number: string | null;
  messages: Message[];
}

const FILTERS: { key: Filter; label: string }[] = [
  { key: 'needs_reply', label: 'Needs reply' },
  { key: 'unread', label: 'Unread' },
  { key: 'all', label: 'All' },
];

const when = (iso: string) => {
  const d = new Date(iso.replace(' ', 'T') + (iso.endsWith('Z') ? '' : 'Z'));
  const now = new Date();
  const sameDay = d.toDateString() === now.toDateString();
  return sameDay
    ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
    : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
};

const formatPhone = (p: string | null) => {
  if (!p) return '';
  const m = p.match(/^\+1(\d{3})(\d{3})(\d{4})$/);
  return m ? `(${m[1]}) ${m[2]}-${m[3]}` : p;
};

export const SmsInbox: React.FC = () => {
  const { currentClubId, isClubAdmin } = useOrg();

  const [filter, setFilter] = useState<Filter>('needs_reply');
  const [threads, setThreads] = useState<Thread[]>([]);
  const [counts, setCounts] = useState({ all: 0, needs_reply: 0, unread: 0 });
  const [selected, setSelected] = useState<string | null>(null);
  const [detail, setDetail] = useState<ThreadDetail | null>(null);
  const [loadingList, setLoadingList] = useState(true);
  const [loadingThread, setLoadingThread] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [disabled, setDisabled] = useState(false);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers = React.useMemo(
    () => ({ Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }),
    [token]
  );
  const selectedRef = useRef<string | null>(null);
  selectedRef.current = selected;

  const loadThreads = useCallback(async () => {
    if (!currentClubId) return;
    setError(null);
    try {
      const res = await fetch(
        `${API_URL}/api/inbox.php?action=threads&club_profile_id=${currentClubId}&filter=${filter}`,
        { headers }
      );
      const data = await res.json();
      if (res.status === 404) {
        // The club has not switched the inbox on. Not an error — a state.
        setDisabled(true);
        return;
      }
      if (!res.ok) throw new Error(data.error || 'Could not load the inbox');
      setDisabled(false);
      setThreads(data.data.threads || []);
      setCounts(data.data.counts || { all: 0, needs_reply: 0, unread: 0 });
    } catch (e: any) {
      setError(e.message);
    } finally {
      setLoadingList(false);
    }
  }, [currentClubId, filter, headers]);

  useEffect(() => { loadThreads(); }, [loadThreads]);

  // Replies arrive while the page is open. Polling rather than sockets is a
  // deliberate M3 choice — see "Deliberately not built" in the scope.
  useEffect(() => {
    if (disabled) return;
    const t = setInterval(loadThreads, 30000);
    return () => clearInterval(t);
  }, [loadThreads, disabled]);

  const openThread = useCallback(async (conversationId: string) => {
    if (!currentClubId) return;
    setSelected(conversationId);
    setLoadingThread(true);
    // Never carry a half-written reply into someone else's conversation.
    setDraft('');
    setSendError(null);
    try {
      const res = await fetch(
        `${API_URL}/api/inbox.php?action=thread&club_profile_id=${currentClubId}&conversation_id=${encodeURIComponent(conversationId)}`,
        { headers }
      );
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Could not open this conversation');
      setDetail(data.data);

      // Opening IS reading. Fire-and-forget would be wrong here — the unread
      // badge has to agree with what the admin has actually seen.
      await fetch(`${API_URL}/api/inbox.php?action=read`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ club_profile_id: currentClubId, conversation_id: conversationId }),
      });
      setThreads(prev => prev.map(t =>
        t.conversation_id === conversationId ? { ...t, unread: 0 } : t
      ));
      setCounts(c => ({ ...c, unread: Math.max(0, c.unread - 1) }));
    } catch (e: any) {
      setError(e.message);
    } finally {
      setLoadingThread(false);
    }
  }, [currentClubId, headers]);

  const sendReply = useCallback(async () => {
    if (!currentClubId || !selected || draft.trim() === '') return;
    setSending(true);
    setSendError(null);
    try {
      const res = await fetch(`${API_URL}/api/inbox.php?action=reply`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          club_profile_id: currentClubId,
          conversation_id: selected,
          body: draft,
        }),
      });
      const data = await res.json();
      // A 409 means queueSms skipped them — opted out, suppressed, bad number.
      // Keep the text in the box; retyping a reply you already wrote is worse
      // than the failure itself.
      if (!res.ok) throw new Error(data.error || 'Could not send this reply');
      setDraft('');
      await openThread(selected);
      loadThreads();
    } catch (e: any) {
      setSendError(e.message);
    } finally {
      setSending(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentClubId, selected, draft, headers]);

  if (!isClubAdmin) {
    return (
      <div className="p-6 max-w-7xl mx-auto text-sm text-gray-600">
        The SMS inbox is available to club admins.
      </div>
    );
  }

  if (disabled) {
    return (
      <div className="p-6 max-w-7xl mx-auto">
        <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">SMS Inbox</h1>
        <div className="mt-4 p-4 border border-amber-200 bg-amber-50 rounded-lg text-sm text-amber-900">
          The inbox is not switched on for this club yet. Replies to your number are
          being recorded in the meantime, so nothing is being lost — they will all be
          here when it is enabled.
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <header className="mb-4">
        <h1 className="text-2xl font-bold text-brand-primary uppercase tracking-wide">SMS Inbox</h1>
        <p className="text-sm text-gray-500 mt-1">Replies to your club's number</p>
      </header>

      {error && (
        <div className="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">{error}</div>
      )}

      <div className="flex flex-wrap items-center gap-2 mb-4">
        {FILTERS.map(f => {
          const n = f.key === 'all' ? counts.all : counts[f.key];
          const active = filter === f.key;
          return (
            <button
              key={f.key}
              onClick={() => setFilter(f.key)}
              className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition-all ${
                active ? 'bg-brand-primary text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'
              }`}
            >
              {f.label}
              <span className={active ? 'text-white/70' : 'text-gray-400'}>{n}</span>
            </button>
          );
        })}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-4 border border-gray-200 rounded-lg bg-white overflow-hidden min-h-[520px]">
        {/* Conversations */}
        <div className="border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto max-h-[560px]">
          {loadingList ? (
            <div className="p-4 space-y-2">
              {[1, 2, 3, 4].map(i => <div key={i} className="h-14 bg-gray-100 rounded animate-pulse" />)}
            </div>
          ) : threads.length === 0 ? (
            <div className="p-8 text-center text-sm text-gray-500">
              {/* An empty filter and an empty inbox mean different things. */}
              {counts.all === 0
                ? 'No replies yet.'
                : filter === 'needs_reply'
                  ? 'Nothing waiting on you.'
                  : 'Nothing here.'}
            </div>
          ) : (
            threads.map(t => (
              <button
                key={t.conversation_id}
                onClick={() => openThread(t.conversation_id)}
                className={`w-full text-left px-4 py-3 border-b border-gray-100 flex gap-2 hover:bg-gray-50 ${
                  selected === t.conversation_id ? 'bg-brand-light' : ''
                }`}
              >
                <span className={`mt-1.5 w-2 h-2 rounded-full flex-shrink-0 ${
                  t.unread > 0 ? 'bg-brand-accent' : 'bg-transparent'
                }`} />
                <span className="min-w-0 flex-1">
                  <span className={`block text-sm truncate ${t.unread > 0 ? 'font-semibold text-gray-900' : 'text-gray-700'}`}>
                    {t.contact_name || formatPhone(t.contact_phone) || 'Unknown sender'}
                  </span>
                  <span className="block text-xs text-gray-400 truncate">
                    {t.athlete_name ? `Crew · ${t.athlete_name}` : t.contact_name ? 'Crew' : formatPhone(t.contact_phone)}
                  </span>
                  <span className="block text-xs text-gray-500 mt-1 line-clamp-2">{t.last_body}</span>
                  {t.needs_reply && (
                    <span className="inline-block mt-1.5 text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">
                      Needs reply
                    </span>
                  )}
                </span>
                <span className="text-xs text-gray-400 whitespace-nowrap tabular-nums">{when(t.last_at)}</span>
              </button>
            ))
          )}
        </div>

        {/* Thread */}
        <div className="flex flex-col min-w-0">
          {!detail ? (
            <div className="flex-1 grid place-items-center p-8 text-sm text-gray-400">
              {threads.length === 0 ? '' : 'Select a conversation'}
            </div>
          ) : (
            <>
              <div className="px-5 py-3 border-b border-gray-200">
                <h2 className="text-base font-semibold text-gray-900">
                  {detail.contact.name || formatPhone(detail.contact.phone) || 'Unknown sender'}
                </h2>
                <p className="text-xs text-gray-500 mt-0.5">
                  {detail.contact.athlete_name && <>Crew for {detail.contact.athlete_name} · </>}
                  <span className="tabular-nums">{formatPhone(detail.contact.phone)}</span>
                </p>
              </div>

              <div className="flex-1 overflow-y-auto px-5 py-4 space-y-3 max-h-[400px]">
                {loadingThread ? (
                  <div className="text-sm text-gray-400">Loading…</div>
                ) : detail.messages.map(m => (
                  <div
                    key={m.id}
                    className={`max-w-[80%] px-3 py-2 rounded-lg text-sm ${
                      m.direction === 'inbound'
                        ? 'bg-gray-100 text-gray-900 rounded-bl-sm'
                        : m.automated
                          ? 'bg-white text-gray-600 border border-dashed border-gray-300 rounded-bl-sm'
                          : 'bg-brand-primary text-white ml-auto rounded-br-sm'
                    }`}
                  >
                    {m.automated && (
                      <span className="block text-[10px] uppercase tracking-wide font-bold text-gray-400 mb-1">
                        Auto-reply sent
                      </span>
                    )}
                    {m.body}
                    <span className={`block text-[10px] mt-1 ${
                      m.direction === 'outbound' && !m.automated ? 'text-white/70' : 'text-gray-400'
                    }`}>
                      {when(m.created_at)}
                      {m.sent_by && ` · ${m.sent_by}`}
                      {m.direction === 'outbound' && !m.automated && ` · ${m.status}`}
                    </span>
                  </div>
                ))}
              </div>

              <div className="border-t border-gray-200 px-5 py-3 bg-gray-50">
                {sendError && (
                  <div className="mb-2 p-2 bg-red-50 border border-red-200 text-red-700 rounded text-xs">
                    {sendError}
                  </div>
                )}
                <textarea
                  value={draft}
                  onChange={e => setDraft(e.target.value)}
                  rows={2}
                  placeholder={`Reply to ${detail.contact.name || 'this contact'}…`}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none resize-y"
                />
                <div className="flex flex-wrap items-center gap-3 mt-2">
                  <span className="text-xs text-gray-400">
                    Sends as a text from{' '}
                    <span className="font-mono tabular-nums text-gray-600">
                      {formatPhone(detail.sending_number)}
                    </span>
                  </span>
                  <span className="text-xs text-gray-400 ml-auto tabular-nums">
                    {draft.length} / {SMS_SEGMENT_LENGTH}
                    {countSmsSegments(draft) > 1 && ` · ${countSmsSegments(draft)} segments`}
                  </span>
                  <button
                    type="button"
                    onClick={sendReply}
                    disabled={sending || draft.trim() === ''}
                    className="px-4 py-2 rounded-md bg-brand-primary text-white text-xs font-bold uppercase disabled:opacity-40 disabled:cursor-not-allowed hover:bg-brand-primary-hover"
                  >
                    {sending ? 'Sending…' : 'Send text'}
                  </button>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default SmsInbox;

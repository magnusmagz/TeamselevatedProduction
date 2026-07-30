import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { useOrg } from '../contexts/OrgContext';
import { SMS_SEGMENT_LENGTH, countSmsSegments } from '../utils/smsSegments';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

/**
 * Broadcast compose — group-first sending.
 *
 * This is the only caller of `action=send-broadcast`. SmsCompose/EmailCompose
 * deliberately route individually-resolved recipients through send-sms/send-email
 * instead (see the CA-49 comments there); this page is the other shape, where you
 * pick teams — or the whole club — and the backend resolves who that means.
 *
 * The practical difference: a broadcast writes a `broadcast_campaigns` row, so the
 * send shows up in reporting as ONE campaign rather than N unrelated log entries.
 */

type Channel = 'sms' | 'email';
type Scope = 'teams' | 'club';
type SendStatus = 'idle' | 'sending' | 'success' | 'error';

/**
 * ⚠️ SINGULAR. resolveBroadcastRecipients tests in_array('athlete', …) — the
 * plural forms are what recipient-search's resolve-group takes, and sending those
 * here resolves nobody with a cheerful HTTP 200. Locked backend-side by
 * BroadcastRecipientResolutionTest::testPluralRecipientTypesResolveNobody.
 */
type RecipientType = 'athlete' | 'guardian' | 'coach';

interface TeamGroup {
  id: number;
  name: string;
  age_group?: string;
  athlete_count: number;
  guardian_count: number;
}

interface PreviewCounts {
  total: number;
  suppressed: number;
  final_count: number;
}

const RECIPIENT_TYPE_LABELS: { value: RecipientType; label: string; hint: string }[] = [
  { value: 'athlete', label: 'Athletes', hint: 'Players with a contact on file' },
  // "Crew", never "Parents" — the guardian-facing term everywhere in this UI.
  { value: 'guardian', label: 'Crew', hint: 'Parents and guardians' },
  { value: 'coach', label: 'Coaches', hint: 'Staff on the selected teams' },
];

export const BroadcastCompose: React.FC = () => {
  const { currentClubId, isClubAdmin } = useOrg();

  const [channel] = useState<Channel>('sms');
  const [scope, setScope] = useState<Scope>('teams');
  const [teams, setTeams] = useState<TeamGroup[]>([]);
  const [teamsLoading, setTeamsLoading] = useState(false);
  const [selectedTeamIds, setSelectedTeamIds] = useState<number[]>([]);
  // Coaches off by default: a message written for families reads oddly to staff.
  const [recipientTypes, setRecipientTypes] = useState<RecipientType[]>(['athlete', 'guardian']);
  const [message, setMessage] = useState('');

  const [preview, setPreview] = useState<PreviewCounts | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  const [sendStatus, setSendStatus] = useState<SendStatus>('idle');
  const [resultMessage, setResultMessage] = useState<string | null>(null);
  const [resultType, setResultType] = useState<'success' | 'error'>('success');

  const token = localStorage.getItem('auth_token');
  const headers = useMemo(
    () => ({ Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }),
    [token]
  );

  const clubId = currentClubId;

  // A coach's team picker IS their permission boundary, so club-wide is admin-only.
  // The backend refuses it independently (broadcastAuthError) — this only hides a
  // control that would always 403.
  useEffect(() => {
    if (!isClubAdmin && scope === 'club') setScope('teams');
  }, [isClubAdmin, scope]);

  // ─── Teams ────────────────────────────────────────────────────────────────
  // The groups endpoint is already coach-scoped server-side (getTeamFilterClause),
  // so a coach only ever sees their own teams here.
  useEffect(() => {
    if (!clubId) return;
    setTeamsLoading(true);
    fetch(`${API_URL}/api/recipient-search?action=groups&club_profile_id=${clubId}`, { headers })
      .then((res) => res.json())
      .then((data) => setTeams(data.groups || data.data || []))
      .catch(() => setTeams([]))
      .finally(() => setTeamsLoading(false));
  }, [clubId, headers]);

  const toggleTeam = (id: number) => {
    setSelectedTeamIds((prev) =>
      prev.includes(id) ? prev.filter((t) => t !== id) : [...prev, id]
    );
  };

  const toggleRecipientType = (value: RecipientType) => {
    setRecipientTypes((prev) =>
      prev.includes(value) ? prev.filter((t) => t !== value) : [...prev, value]
    );
  };

  // ─── Preview ──────────────────────────────────────────────────────────────
  const isClubWide = scope === 'club';
  const hasAudience =
    recipientTypes.length > 0 && (isClubWide || selectedTeamIds.length > 0);

  const buildPayload = useCallback(
    () => ({
      club_profile_id: clubId,
      channel,
      scope,
      team_ids: isClubWide ? [] : selectedTeamIds,
      recipient_types: recipientTypes,
    }),
    [clubId, channel, scope, isClubWide, selectedTeamIds, recipientTypes]
  );

  // Debounced so ticking three boxes is one request, not three.
  const previewTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => {
    if (!clubId || !hasAudience) {
      setPreview(null);
      setPreviewError(null);
      return;
    }

    if (previewTimer.current) clearTimeout(previewTimer.current);
    previewTimer.current = setTimeout(() => {
      setPreviewLoading(true);
      setPreviewError(null);
      fetch(`${API_URL}/api/communications?action=preview-broadcast`, {
        method: 'POST',
        headers,
        body: JSON.stringify(buildPayload()),
      })
        .then(async (res) => {
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data.error || 'Could not count recipients');
          return data;
        })
        .then((data) => setPreview(data.data || null))
        .catch((err) => {
          setPreview(null);
          setPreviewError(err.message);
        })
        .finally(() => setPreviewLoading(false));
    }, 350);

    return () => {
      if (previewTimer.current) clearTimeout(previewTimer.current);
    };
  }, [clubId, hasAudience, buildPayload, headers]);

  // ─── Message sizing ───────────────────────────────────────────────────────
  const charCount = message.length;
  const segmentCount = useMemo(() => countSmsSegments(message), [message]);
  const totalSegments = (preview?.final_count ?? 0) * segmentCount;

  const canSend =
    hasAudience &&
    message.trim().length > 0 &&
    (preview?.final_count ?? 0) > 0 &&
    sendStatus !== 'sending';

  // ─── Send ─────────────────────────────────────────────────────────────────
  const handleSend = async () => {
    if (!canSend) return;

    setSendStatus('sending');
    setResultMessage(null);

    try {
      const res = await fetch(`${API_URL}/api/communications?action=send-broadcast`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ ...buildPayload(), body: message }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || 'Failed to send broadcast');

      const queued = data?.data?.queued ?? 0;
      const skipped = data?.data?.skipped ?? 0;

      setSendStatus('success');
      setResultType('success');
      setResultMessage(
        `Broadcast queued for ${queued} recipient${queued !== 1 ? 's' : ''}` +
          (skipped > 0 ? ` · ${skipped} skipped` : '') +
          '.'
      );
      setMessage('');
    } catch (err: any) {
      setSendStatus('error');
      setResultType('error');
      setResultMessage(err.message || 'Failed to send broadcast.');
    }
  };

  const charCountColor =
    charCount === 0
      ? 'text-gray-400'
      : charCount <= SMS_SEGMENT_LENGTH
      ? 'text-gray-500'
      : charCount <= SMS_SEGMENT_LENGTH * 2
      ? 'text-amber-600'
      : 'text-red-600';

  if (!clubId) {
    return (
      <div className="p-6">
        <p className="text-sm text-gray-500">Select a club to send a broadcast.</p>
      </div>
    );
  }

  return (
    <div className="max-w-3xl mx-auto p-6 space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Broadcast SMS</h1>
        <p className="mt-1 text-sm text-gray-500">
          Send one message to whole teams or the entire club. Sends appear in Reporting as a
          single campaign.
        </p>
      </header>

      {/* ── Audience ─────────────────────────────────────────────────── */}
      <section className="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Send to</h2>

        {isClubAdmin && (
          <div className="flex flex-col gap-2" role="radiogroup" aria-label="Audience scope">
            <label className="flex items-start gap-2 cursor-pointer">
              <input
                type="radio"
                name="scope"
                value="teams"
                checked={scope === 'teams'}
                onChange={() => setScope('teams')}
                className="mt-1"
              />
              <span>
                <span className="text-sm text-gray-900">Selected teams</span>
              </span>
            </label>
            <label className="flex items-start gap-2 cursor-pointer">
              <input
                type="radio"
                name="scope"
                value="club"
                checked={scope === 'club'}
                onChange={() => setScope('club')}
                className="mt-1"
              />
              <span>
                <span className="text-sm text-gray-900">Everyone in the club</span>
                <span className="block text-xs text-gray-500">
                  Includes athletes who have registered but are not yet on a roster
                </span>
              </span>
            </label>
          </div>
        )}

        {!isClubWide && (
          <div>
            {teamsLoading ? (
              <p className="text-xs text-gray-400">Loading teams…</p>
            ) : teams.length === 0 ? (
              <p className="text-xs text-gray-400">No teams available.</p>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-56 overflow-y-auto">
                {teams.map((team) => (
                  <label
                    key={team.id}
                    className="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer"
                  >
                    <input
                      type="checkbox"
                      checked={selectedTeamIds.includes(team.id)}
                      onChange={() => toggleTeam(team.id)}
                    />
                    <span className="text-sm text-gray-900 flex-1">{team.name}</span>
                    <span className="text-xs text-gray-400">
                      {team.athlete_count + team.guardian_count}
                    </span>
                  </label>
                ))}
              </div>
            )}
          </div>
        )}

        <fieldset className="pt-2 border-t border-gray-100">
          <legend className="sr-only">Recipient types</legend>
          <div className="flex flex-wrap gap-4">
            {RECIPIENT_TYPE_LABELS.map((rt) => (
              <label key={rt.value} className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={recipientTypes.includes(rt.value)}
                  onChange={() => toggleRecipientType(rt.value)}
                />
                <span className="text-sm text-gray-900" title={rt.hint}>
                  {rt.label}
                </span>
              </label>
            ))}
          </div>
        </fieldset>
      </section>

      {/* ── Live count ───────────────────────────────────────────────── */}
      <section
        className="bg-gray-50 rounded-xl border border-gray-200 px-5 py-4"
        aria-live="polite"
      >
        {!hasAudience ? (
          <p className="text-sm text-gray-500">
            Pick {isClubWide ? 'at least one recipient type' : 'at least one team and recipient type'} to
            see who this reaches.
          </p>
        ) : previewLoading ? (
          <p className="text-sm text-gray-500">Counting recipients…</p>
        ) : previewError ? (
          <p className="text-sm text-red-600">{previewError}</p>
        ) : preview ? (
          <div className="flex flex-wrap items-baseline gap-x-2 text-sm">
            <span className="font-semibold text-gray-900">{preview.total} recipients</span>
            {preview.suppressed > 0 && (
              <>
                <span className="text-gray-400">·</span>
                {/* Never a silent drop — opt-outs are shown, not quietly removed. */}
                <span className="text-amber-700">{preview.suppressed} opted out</span>
              </>
            )}
            <span className="text-gray-400">·</span>
            <span className="text-gray-700">
              {preview.final_count} will receive
            </span>
          </div>
        ) : null}
      </section>

      {/* ── Message ──────────────────────────────────────────────────── */}
      <section className="bg-white rounded-xl border border-gray-200 p-5 space-y-2">
        <label htmlFor="broadcast-body" className="block text-sm font-semibold text-gray-900">
          Message
        </label>
        <textarea
          id="broadcast-body"
          rows={5}
          value={message}
          onChange={(e) => setMessage(e.target.value)}
          placeholder="Practice is cancelled tonight — fields are flooded. See you Thursday."
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-primary focus:border-transparent"
        />
        <div className="flex items-center justify-between text-xs">
          <span className={charCountColor}>
            {charCount} / {SMS_SEGMENT_LENGTH} characters
          </span>
          {segmentCount > 1 && (
            <span className="text-amber-600">
              {segmentCount} segments per recipient
              {totalSegments > 0 && ` · ${totalSegments} total`}
            </span>
          )}
        </div>
      </section>

      {resultMessage && (
        <div
          role="status"
          className={`rounded-lg px-4 py-3 text-sm ${
            resultType === 'success'
              ? 'bg-green-50 text-green-800 border border-green-200'
              : 'bg-red-50 text-red-800 border border-red-200'
          }`}
        >
          {resultMessage}
        </div>
      )}

      <div className="flex justify-end">
        <button
          type="button"
          onClick={handleSend}
          disabled={!canSend}
          className="px-5 py-2.5 rounded-lg bg-brand-primary text-white text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed hover:opacity-90 transition"
        >
          {sendStatus === 'sending' ? 'Sending…' : 'Send broadcast'}
        </button>
      </div>
    </div>
  );
};

export default BroadcastCompose;

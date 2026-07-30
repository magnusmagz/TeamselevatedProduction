import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../contexts/OrgContext';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

/**
 * Club Profile → Messaging. The club's SMS sending number.
 *
 * Numbers are not bought here — an admin buys in the Twilio console and pastes it,
 * and the backend verifies ownership against Twilio before storing. So the two
 * states that matter are "verified and sending" and "nothing configured, SMS is
 * blocked", and this screen has to make the second one unmistakable: with no number
 * the club cannot text anyone at all.
 */

interface SmsNumberState {
  configured: boolean;
  phone_number: string | null;
  messaging_service_sid: string | null;
  twilio_phone_sid: string | null;
  provisioned_at: string | null;
  blocked_reason: string | null;
}

const formatForDisplay = (e164: string | null): string => {
  if (!e164) return '';
  const m = e164.match(/^\+1(\d{3})(\d{3})(\d{4})$/);
  return m ? `+1 (${m[1]}) ${m[2]}-${m[3]}` : e164;
};

export const ClubSmsSettings: React.FC = () => {
  const { currentClubId, isClubAdmin } = useOrg();

  const [state, setState] = useState<SmsNumberState | null>(null);
  const [loading, setLoading] = useState(true);
  const [input, setInput] = useState('');
  const [serviceSid, setServiceSid] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers = React.useMemo(
    () => ({ Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }),
    [token]
  );

  const load = useCallback(async () => {
    if (!currentClubId) return;
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(
        `${API_URL}/api/sms-numbers.php?action=get&club_profile_id=${currentClubId}`,
        { headers }
      );
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Could not load the SMS number');
      setState(data.data);
      setServiceSid(data.data?.messaging_service_sid || '');
    } catch (e: any) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [currentClubId, headers]);

  useEffect(() => {
    load();
  }, [load]);

  const save = async () => {
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetch(`${API_URL}/api/sms-numbers.php?action=set`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          club_profile_id: currentClubId,
          phone_number: input.trim(),
          messaging_service_sid: serviceSid.trim() || null,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Could not save the number');
      setState({ ...data.data, provisioned_at: new Date().toISOString(), blocked_reason: null });
      setInput('');
      // Saving also points the number's inbound webhook at the auto-reply. If that
      // failed the number still sends, but replies vanish — say so rather than
      // report a clean success.
      if (data.data?.inbound_warning) {
        setError(data.data.inbound_warning);
      } else {
        setNotice(
          'Verified against your Twilio account. This club now sends from that number, ' +
            'and replies get an automatic pointer to the parent portal.'
        );
      }
    } catch (e: any) {
      setError(e.message);
    } finally {
      setSaving(false);
    }
  };

  const clear = async () => {
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetch(`${API_URL}/api/sms-numbers.php?action=clear`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ club_profile_id: currentClubId }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Could not clear the number');
      await load();
      setNotice('Number removed. This club can no longer send SMS until a new one is set.');
    } catch (e: any) {
      setError(e.message);
    } finally {
      setSaving(false);
    }
  };

  if (!isClubAdmin) {
    return (
      <p className="text-sm text-gray-600">
        Only club admins can manage the SMS sending number.
      </p>
    );
  }

  if (loading) {
    return <div className="text-center text-brand-primary py-12">Loading messaging settings...</div>;
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-semibold text-brand-primary uppercase">SMS Sending Number</h2>
        <p className="text-sm text-gray-600 mt-2">
          The number families see when this club texts them. Buy the number in your Twilio
          console, then paste it here — we check that your account owns it before saving.
        </p>
      </div>

      {/* Current state. The unconfigured case is a blocker, not a hint. */}
      {state?.configured ? (
        <div className="bg-white border border-brand-secondary rounded-md p-6">
          <p className="text-xs uppercase tracking-wide text-gray-500 mb-1">Currently sending as</p>
          <p className="text-2xl font-semibold text-brand-primary">
            {state.messaging_service_sid
              ? state.messaging_service_sid
              : formatForDisplay(state.phone_number)}
          </p>
          {state.messaging_service_sid && state.phone_number && (
            <p className="text-sm text-gray-600 mt-1">
              via Messaging Service, number {formatForDisplay(state.phone_number)}
            </p>
          )}
          {state.twilio_phone_sid && (
            <p className="text-xs text-gray-400 mt-2">Twilio SID {state.twilio_phone_sid}</p>
          )}
          <button
            type="button"
            onClick={clear}
            disabled={saving}
            className="mt-4 text-sm text-red-600 hover:text-red-700 underline disabled:opacity-40"
          >
            Remove this number
          </button>
        </div>
      ) : (
        <div className="border border-red-200 bg-red-50 rounded-md p-4" role="alert">
          <p className="text-sm font-semibold text-red-800">SMS is blocked for this club</p>
          <p className="text-sm text-red-700 mt-1">
            {state?.blocked_reason ||
              'This club has no SMS number configured, so no texts can be sent.'}
          </p>
        </div>
      )}

      {/* Set / replace */}
      <div className="bg-white border border-brand-secondary rounded-md p-6 space-y-4">
        <h3 className="text-sm font-bold text-brand-primary uppercase tracking-wide">
          {state?.configured ? 'Replace number' : 'Set number'}
        </h3>

        <div>
          <label
            htmlFor="sms-number"
            className="block text-brand-primary text-sm font-medium mb-2 uppercase"
          >
            Twilio phone number
          </label>
          <input
            id="sms-number"
            type="tel"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            placeholder="+1 360 555 0199"
            className="w-full md:w-80 rounded-md border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-primary focus:border-transparent"
          />
          <p className="text-xs text-gray-500 mt-1">
            Must already be on your Twilio account and SMS-capable.
          </p>
        </div>

        <div>
          <label
            htmlFor="sms-service-sid"
            className="block text-brand-primary text-sm font-medium mb-2 uppercase"
          >
            Messaging Service SID <span className="normal-case text-gray-400">(optional)</span>
          </label>
          <input
            id="sms-service-sid"
            type="text"
            value={serviceSid}
            onChange={(e) => setServiceSid(e.target.value)}
            placeholder="MG…"
            className="w-full md:w-80 rounded-md border border-gray-300 px-3 py-2 font-mono text-sm focus:ring-2 focus:ring-brand-primary focus:border-transparent"
          />
          <p className="text-xs text-gray-500 mt-1">
            If your A2P 10DLC campaign is registered to a Messaging Service, put its SID here —
            it will be used instead of the bare number, which is what carriers expect for
            high-volume club messaging.
          </p>
        </div>

        {error && (
          <div className="border border-red-200 bg-red-50 rounded-md px-4 py-3" role="alert">
            <p className="text-sm text-red-800">{error}</p>
          </div>
        )}
        {notice && (
          <div className="border border-green-200 bg-green-50 rounded-md px-4 py-3" role="status">
            <p className="text-sm text-green-800">{notice}</p>
          </div>
        )}

        <button
          type="button"
          onClick={save}
          disabled={saving || (!input.trim() && !serviceSid.trim())}
          className="px-5 py-2.5 rounded-md bg-brand-primary text-white text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed hover:opacity-90 transition"
        >
          {saving ? 'Verifying…' : 'Verify & Save'}
        </button>
      </div>

      <div className="border border-amber-200 bg-amber-50 rounded-md p-4">
        <p className="text-sm font-semibold text-amber-900">Why each club needs its own number</p>
        <p className="text-sm text-amber-800 mt-1">
          When someone replies STOP, the carrier blocks that number for them — not just your
          club's messages. While clubs share a number, one family opting out of one club stops
          hearing from every club on the platform. Your own number keeps your opt-outs yours.
        </p>
      </div>
    </div>
  );
};

export default ClubSmsSettings;

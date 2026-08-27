import React from 'react';

const API_URL = process.env.REACT_APP_API_URL || '';

interface ChannelRow { channel: string; sent: number; clicked: number }
interface ClubRow { club: string; sent: number; clicked: number; people: number }
interface AlertRow { kind: string; n: number; admins: number }

interface Health {
  days: number;
  totals: { sent: number; people: number; clicked: number };
  by_channel: ChannelRow[];
  by_club: ClubRow[];
  reach: {
    push_devices: number;
    people_with_push: number;
    emailable_users: number;
    opted_out_email: number;
    opted_out_push: number;
    muted_conversations: number;
  };
  last_notification_at: string | null;
  last_message_at: string | null;
  moderation: { alerts: AlertRow[]; open_reports: number; open_high_severity: number };
}

const rate = (clicked: number, sent: number) => (sent ? `${Math.round((clicked / sent) * 1000) / 10}%` : '—');

/** "3m ago", "2h ago", "5d ago" — enough to see whether something has stalled. */
function ago(iso: string | null): string {
  if (!iso) return 'never';
  const then = new Date(iso.replace(' ', 'T') + (/[Z+]/.test(iso.slice(-6)) ? '' : 'Z')).getTime();
  if (Number.isNaN(then)) return iso;
  const mins = Math.max(0, Math.floor((Date.now() - then) / 60000));
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  if (mins < 1440) return `${Math.floor(mins / 60)}h ago`;
  return `${Math.floor(mins / 1440)}d ago`;
}

/**
 * Chat notification health, for the internal team.
 *
 * Chat notifications bypass the bulk send path, which is where open and click
 * tracking live, so none of this appears in Email Reporting — and there is no
 * analytics on the site. Without this screen the only way to answer "are
 * notifications reaching anyone" is a database query, which is not something a
 * support conversation can wait for.
 *
 * Read-only and platform-wide. The question it answers — does the feature work —
 * is ours rather than any one club's.
 */
export const NotificationHealth: React.FC = () => {
  const [health, setHealth] = React.useState<Health | null>(null);
  const [days, setDays] = React.useState(30);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);

  const load = React.useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${API_URL}/api/super-admin-gateway.php?action=notification-health&days=${days}`,
        { headers: token ? { Authorization: `Bearer ${token}` } : {} }
      );
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.error || 'Could not load notification health');
      setHealth(data.health);
    } catch (e: any) {
      // Deliberately an error state, never an empty one. Zeros here would read
      // as "the feature is not working" when the truth is "we could not ask".
      setError(e.message || 'Could not load notification health');
      setHealth(null);
    } finally {
      setLoading(false);
    }
  }, [days]);

  React.useEffect(() => { load(); }, [load]);

  if (loading) return <div className="p-6 text-brand-primary">Loading notification health…</div>;

  if (error) {
    return (
      <div className="p-6">
        <p className="text-red-700">{error}</p>
        <button onClick={load} className="mt-3 px-4 py-2 rounded-md bg-brand-primary text-white text-sm">
          Try again
        </button>
      </div>
    );
  }

  if (!health) return null;

  const { totals, reach, moderation } = health;

  // A dispatcher that has gone quiet while messages keep arriving is the failure
  // that went unnoticed for weeks before the worker reconnect fix, so it is
  // called out rather than left for someone to spot in two timestamps.
  const stalled =
    !!health.last_message_at &&
    (!health.last_notification_at || new Date(health.last_notification_at) < new Date(health.last_message_at)) &&
    Date.now() - new Date((health.last_message_at || '').replace(' ', 'T') + 'Z').getTime() > 30 * 60000;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold text-brand-primary">Chat notification health</h2>
        <select
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
          className="border border-brand-secondary rounded-md px-3 py-1.5 text-sm"
        >
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
        </select>
      </div>

      {stalled && (
        <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800" role="alert">
          Messages are arriving but nothing has been notified since{' '}
          {ago(health.last_notification_at)}. The queue worker may be stuck.
        </div>
      )}

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {[
          { label: 'Notifications sent', value: totals.sent },
          { label: 'People reached', value: totals.people },
          { label: 'Clicked through', value: totals.clicked },
          { label: 'Click rate', value: rate(totals.clicked, totals.sent) },
        ].map((t) => (
          <div key={t.label} className="border border-brand-secondary rounded-lg p-4">
            <div className="text-2xl font-bold text-brand-primary">{t.value}</div>
            <div className="mt-1 text-xs uppercase tracking-wide text-gray-500">{t.label}</div>
          </div>
        ))}
      </div>

      <div className="grid md:grid-cols-2 gap-6">
        <section>
          <h3 className="text-sm font-semibold text-brand-primary mb-2">By channel</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase text-gray-500 border-b border-brand-secondary">
                <th className="py-2">Channel</th><th>Sent</th><th>Clicked</th><th>Rate</th>
              </tr>
            </thead>
            <tbody>
              {health.by_channel.map((c) => (
                <tr key={c.channel} className="border-b border-brand-secondary/40">
                  <td className="py-2 font-medium">{c.channel}</td>
                  <td>{c.sent}</td><td>{c.clicked}</td><td>{rate(c.clicked, c.sent)}</td>
                </tr>
              ))}
              {health.by_channel.length === 0 && (
                <tr><td colSpan={4} className="py-3 text-gray-500">Nothing sent in this window.</td></tr>
              )}
            </tbody>
          </table>
        </section>

        <section>
          <h3 className="text-sm font-semibold text-brand-primary mb-2">Reach</h3>
          <dl className="text-sm space-y-1">
            {[
              ['Push devices registered', reach.push_devices],
              ['People with push on', reach.people_with_push],
              ['Users reachable by email', reach.emailable_users],
              ['Opted out of email', reach.opted_out_email],
              ['Opted out of push', reach.opted_out_push],
              ['Conversations muted', reach.muted_conversations],
            ].map(([label, value]) => (
              <div key={String(label)} className="flex justify-between border-b border-brand-secondary/40 py-1">
                <dt className="text-gray-600">{label}</dt>
                <dd className="font-medium text-brand-primary">{value}</dd>
              </div>
            ))}
          </dl>
          <p className="mt-3 text-xs text-gray-500">
            Last message {ago(health.last_message_at)} · last notification {ago(health.last_notification_at)}
          </p>
        </section>
      </div>

      <section>
        <h3 className="text-sm font-semibold text-brand-primary mb-2">By club</h3>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase text-gray-500 border-b border-brand-secondary">
              <th className="py-2">Club</th><th>People</th><th>Sent</th><th>Clicked</th><th>Rate</th>
            </tr>
          </thead>
          <tbody>
            {health.by_club.map((c) => (
              <tr key={c.club} className="border-b border-brand-secondary/40">
                <td className="py-2 font-medium">{c.club}</td>
                <td>{c.people}</td><td>{c.sent}</td><td>{c.clicked}</td><td>{rate(c.clicked, c.sent)}</td>
              </tr>
            ))}
            {health.by_club.length === 0 && (
              <tr><td colSpan={5} className="py-3 text-gray-500">Nothing sent in this window.</td></tr>
            )}
          </tbody>
        </table>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-brand-primary mb-2">Moderation</h3>
        <p className="text-sm text-gray-700">
          <span className="font-semibold text-brand-primary">{moderation.open_reports}</span> reports still open
          {moderation.open_high_severity > 0 && (
            <>, <span className="font-semibold text-red-700">{moderation.open_high_severity} high severity</span></>
          )}
          .
        </p>
        <ul className="mt-1 text-sm text-gray-600">
          {moderation.alerts.map((a) => (
            <li key={a.kind}>{a.kind.replace('_', ' ')}: {a.n} alerts to {a.admins} admins</li>
          ))}
        </ul>
      </section>

      <p className="text-xs text-gray-500 border-t border-brand-secondary pt-3">
        Click-through only. These emails carry no open tracking by design, and there is no analytics on
        the site, so this screen is the whole picture.
      </p>
    </div>
  );
};

export default NotificationHealth;

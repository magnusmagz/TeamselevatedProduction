import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { generateColorPalette } from '../utils/colorExtractor';
import { eventWhere } from '../utils/eventWhere';
import { parseDateOnly } from '../utils/dateFormat';

/**
 * /club/:slug — the club's public link page (2026-09-09).
 *
 * Public, no token, mobile-first: a phone-width card stack that is also the
 * desktop layout (centred). Everything on it comes from ONE call to
 * api/club-public-gateway.php?action=page, whose allowlists decide what a
 * stranger may see — this file renders what it is handed and adds nothing.
 * There is no athlete data on this page, by design.
 *
 * Brand colours are scoped to the page WRAPPER as CSS variables, never set on
 * :root — a signed-in admin previewing their page must not have the staff app
 * re-skinned underneath it.
 */

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
export const COACHES_PER_PAGE = 9;

export interface PublicClub {
  id: number;
  name: string;
  slug: string;
  tagline: string | null;
  phone: string | null;
  website: string | null;
  city: string | null;
  state: string | null;
  socials: Partial<Record<'facebook' | 'instagram' | 'twitter' | 'tiktok' | 'youtube' | 'linkedin', string>>;
  logo_url: string | null;
  primary_color: string;
  secondary_color: string;
}
export interface PublicSponsor { id: number; name: string; website: string | null; logo_data: string | null }
export interface PublicCoach { name: string; role: string; photo: string | null; teams: string[] }
export interface PublicEvent {
  id: number;
  name: string;
  type: string;
  event_date: string;
  start_time: string | null;
  end_time: string | null;
  opponent_name: string | null;
  venue_name: string | null;
  field_name: string | null;
  teams: string[];
}
export interface PublicPagePayload {
  club: PublicClub;
  sponsors: PublicSponsor[];
  coaches: PublicCoach[];
  events: PublicEvent[];
}

export function publicPageUrl(slug: string, action = 'page', extra = ''): string {
  return `${API_URL}/api/club-public-gateway.php?action=${action}&slug=${encodeURIComponent(slug)}${extra}`;
}

/** Home / Away / Tournament — the pill and the date square share one treatment. */
export function eventKind(e: Pick<PublicEvent, 'type' | 'name' | 'opponent_name'>): 'Home' | 'Away' | 'Tournament' {
  if (e.type === 'tournament') return 'Tournament';
  const text = `${e.name} ${e.opponent_name ?? ''}`.toLowerCase();
  if (/\bat\b|\baway\b|^@/.test(text)) return 'Away';
  return 'Home';
}

const KIND_STYLE: Record<ReturnType<typeof eventKind>, { bg: string; fg: string }> = {
  Home: { bg: 'var(--color-primary)', fg: '#ffffff' },
  Away: { bg: 'var(--color-secondary)', fg: 'var(--color-primary)' },
  Tournament: { bg: '#f5b400', fg: '#1f2937' },
};

export function formatTime(t: string | null): string | null {
  if (!t) return null;
  const [h, m] = t.split(':').map(Number);
  if (Number.isNaN(h) || Number.isNaN(m)) return null;
  const suffix = h >= 12 ? 'PM' : 'AM';
  const hh = h % 12 === 0 ? 12 : h % 12;
  return `${hh}:${String(m).padStart(2, '0')} ${suffix}`;
}

export function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
}

const Icon: React.FC<{ name: string; size?: number; className?: string }> = ({ name, size = 20, className }) => {
  const paths: Record<string, string> = {
    phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z',
    globe: 'M3 12a9 9 0 1 0 18 0 9 9 0 1 0-18 0M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
    share: 'M18 5m-2.5 0a2.5 2.5 0 1 0 5 0 2.5 2.5 0 1 0-5 0M6 12m-2.5 0a2.5 2.5 0 1 0 5 0 2.5 2.5 0 1 0-5 0M18 19m-2.5 0a2.5 2.5 0 1 0 5 0 2.5 2.5 0 1 0-5 0M8.2 10.8l7.6-4.4M8.2 13.2l7.6 4.4',
    chevL: 'M15 6l-6 6 6 6',
    chevR: 'M9 6l6 6-6 6',
    cal: 'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18M8 3v4M16 3v4',
    send: 'M21 3L10 14M21 3l-7 18-4-7-7-4z',
    facebook: 'M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v6h4v-6h3l1-4h-4V8z',
    instagram: 'M3 8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5zM12 12m-4 0a4 4 0 1 0 8 0 4 4 0 1 0-8 0M17.5 6.5h.01',
    twitter: 'M4 4l16 16M20 4L4 20',
    tiktok: 'M14 4v10a4 4 0 1 1-4-4M14 4a5 5 0 0 0 5 5',
    youtube: 'M2 9a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3zM10 9.5v5l4.5-2.5z',
    linkedin: 'M4 9h4v11H4zM6 4m-2 0a2 2 0 1 0 4 0 2 2 0 1 0-4 0M10 9h4v2a4 4 0 0 1 6 3v6h-4v-6a1 1 0 0 0-2 0v6h-4z',
  };
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}
      strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
      <path d={paths[name] ?? ''} />
    </svg>
  );
};

const SOCIAL_LABELS: Record<string, string> = {
  facebook: 'Facebook', instagram: 'Instagram', twitter: 'X', tiktok: 'TikTok', youtube: 'YouTube', linkedin: 'LinkedIn',
};

const pillBase =
  'flex items-center justify-center gap-2.5 min-h-[48px] px-5 py-3 rounded-md font-semibold text-sm uppercase tracking-wide transition-colors';
const pillPrimary = `${pillBase} bg-brand-primary text-white hover:bg-brand-primary-hover`;
const pillSecondary = `${pillBase} bg-white text-brand-primary border border-brand-secondary hover:bg-brand-light/40`;

const Card: React.FC<{ children: React.ReactNode; className?: string; id?: string }> = ({ children, className = '', id }) => (
  <section id={id} className={`bg-white border border-gray-200 rounded-lg p-4 flex flex-col gap-3 ${className}`}>{children}</section>
);

const SectionTitle: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <h2 className="text-[15px] font-bold uppercase tracking-wider text-brand-primary m-0" style={{ fontFamily: "'Orbitron', sans-serif" }}>
    {children}
  </h2>
);

// ---------------------------------------------------------------- sections

const Hero: React.FC<{ club: PublicClub; onShare: () => void; shared: boolean }> = ({ club, onShare, shared }) => {
  const place = [club.city, club.state].filter(Boolean).join(', ');
  return (
    <div className="relative flex flex-col items-center gap-3 px-5 pt-7 pb-6 bg-brand-primary">
      <button
        type="button"
        onClick={onShare}
        aria-label={shared ? 'Link copied' : 'Share this page'}
        className="absolute top-3.5 right-3.5 w-10 h-10 rounded-full flex items-center justify-center text-white bg-white/15 hover:bg-white/25"
      >
        <Icon name="share" size={18} />
      </button>
      <div className="w-24 h-24 rounded-full bg-white flex items-center justify-center shadow-lg overflow-hidden">
        {club.logo_url ? (
          <img src={club.logo_url} alt="" className="w-[68px] h-[68px] object-contain" />
        ) : (
          <span className="text-2xl font-bold text-brand-primary">{initials(club.name)}</span>
        )}
      </div>
      <h1
        className="m-0 text-white text-[22px] font-extrabold uppercase tracking-wide text-center leading-tight"
        style={{ fontFamily: "'Orbitron', sans-serif" }}
      >
        {club.name}
      </h1>
      {place && <div className="text-white/85 text-sm text-center">{place}</div>}
      {club.tagline && <p className="m-0 text-white/75 text-[13px] text-center max-w-[300px]">{club.tagline}</p>}
      {shared && <span role="status" className="text-white/90 text-xs">Link copied</span>}
    </div>
  );
};

const ActionPills: React.FC<{ club: PublicClub }> = ({ club }) => {
  const items: React.ReactNode[] = [];
  if (club.website) {
    items.push(
      <a key="web" href={club.website} target="_blank" rel="noopener noreferrer" className={pillPrimary}>
        <Icon name="globe" size={18} /><span>Visit our website</span>
      </a>
    );
  }
  if (club.phone) {
    items.push(
      <a key="tel" href={`tel:${club.phone.replace(/[^\d+]/g, '')}`} className={pillSecondary}>
        <Icon name="phone" size={18} /><span>Call the club</span>
      </a>
    );
  }
  items.push(
    <a key="contact" href="#contact" className={pillSecondary}>
      <Icon name="send" size={18} /><span>Contact us</span>
    </a>
  );
  return <div className="flex flex-col gap-2.5">{items}</div>;
};

const SocialRow: React.FC<{ club: PublicClub }> = ({ club }) => {
  const entries = Object.entries(club.socials ?? {}).filter(([, url]) => !!url);
  if (entries.length === 0) return null;
  return (
    <div className="flex justify-center gap-3.5">
      {entries.map(([key, url]) => (
        <a
          key={key}
          href={url as string}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={SOCIAL_LABELS[key] ?? key}
          className="w-12 h-12 rounded-full bg-white border border-brand-secondary flex items-center justify-center text-brand-primary hover:bg-brand-light/40"
        >
          <Icon name={key} size={22} />
        </a>
      ))}
    </div>
  );
};

const SponsorBanner: React.FC<{ sponsors: PublicSponsor[] }> = ({ sponsors }) => {
  if (sponsors.length === 0) return null;
  return (
    <Card className="py-3.5">
      <div className="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400 text-center">Thank you to our sponsors</div>
      <div className="flex gap-3 overflow-x-auto pb-0.5" data-testid="sponsor-strip">
        {sponsors.map((s) => {
          const content = s.logo_data ? (
            <img src={s.logo_data} alt={s.name} className="h-10 w-auto object-contain" />
          ) : (
            <span className="text-xs font-semibold text-gray-600 whitespace-nowrap">{s.name}</span>
          );
          const cls = 'flex items-center justify-center min-w-[88px] h-10 px-3 rounded';
          return s.website ? (
            <a key={s.id} href={s.website} target="_blank" rel="noopener noreferrer" className={`${cls} hover:opacity-80`} title={s.name}>{content}</a>
          ) : (
            <div key={s.id} className={cls} title={s.name}>{content}</div>
          );
        })}
      </div>
    </Card>
  );
};

const GameRow: React.FC<{ e: PublicEvent }> = ({ e }) => {
  const kind = eventKind(e);
  const style = KIND_STYLE[kind];
  const d = parseDateOnly(e.event_date);
  const day = d ? String(d.getDate()) : '';
  const mon = d ? d.toLocaleDateString('en-US', { month: 'short' }) : '';
  const start = formatTime(e.start_time);
  const end = formatTime(e.end_time);
  const when = start ? (end ? `${start} – ${end}` : start) : 'All day';
  const where = eventWhere({ venue_name: e.venue_name, field_name: e.field_name });
  const title = e.opponent_name ? `${e.name} vs ${e.opponent_name}` : e.name;
  return (
    <li className="flex gap-3.5 py-3 border-t border-gray-100 list-none">
      <div className="flex flex-col items-center justify-center min-w-[52px] rounded-md px-1 py-1.5" style={{ background: style.bg, color: style.fg }}>
        <div className="text-xl font-bold leading-none" style={{ fontFamily: "'Orbitron', sans-serif" }}>{day}</div>
        <div className="text-[11px] uppercase tracking-widest mt-1">{mon}</div>
      </div>
      <div className="flex flex-col gap-0.5 flex-grow min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-[15px] font-semibold text-gray-900">{title}</span>
          <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full" style={{ background: style.bg, color: style.fg }}>{kind}</span>
        </div>
        <div className="text-[13px] text-gray-600">{when}</div>
        {where && <div className="text-[13px] text-gray-500">{where}</div>}
        {e.teams.length > 0 && <div className="text-[12px] text-gray-500">{e.teams.join(', ')}</div>}
      </div>
    </li>
  );
};

const UpcomingGames: React.FC<{ events: PublicEvent[]; slug: string; showingAll: boolean; onSeeAll: () => void }> = ({ events, slug, showingAll, onSeeAll }) => (
  <Card>
    <div className="flex items-center justify-between">
      <SectionTitle>Upcoming games</SectionTitle>
      {!showingAll && events.length >= 10 && (
        <button type="button" onClick={onSeeAll} className="text-[13px] font-semibold text-brand-primary hover:underline">See all</button>
      )}
    </div>
    {events.length === 0 ? (
      <p className="m-0 text-sm text-gray-500">No games on the schedule yet. Check back soon.</p>
    ) : (
      <ul className="m-0 p-0 flex flex-col">{events.map((e) => <GameRow key={e.id} e={e} />)}</ul>
    )}
    <a href={publicPageUrl(slug, 'ics')} className={pillSecondary}>
      <Icon name="cal" size={18} /><span>Add to my calendar</span>
    </a>
  </Card>
);

const CoachesGrid: React.FC<{ coaches: PublicCoach[] }> = ({ coaches }) => {
  const [page, setPage] = useState(0);
  const pages = Math.max(1, Math.ceil(coaches.length / COACHES_PER_PAGE));
  const slice = coaches.slice(page * COACHES_PER_PAGE, (page + 1) * COACHES_PER_PAGE);
  if (coaches.length === 0) return null;
  return (
    <Card>
      <SectionTitle>Our coaches</SectionTitle>
      <ul className="m-0 p-0 grid grid-cols-3 gap-2" data-testid="coaches-grid">
        {slice.map((c, i) => (
          <li key={`${c.name}-${i}`} className="list-none flex flex-col items-center gap-1.5 px-1.5 py-3 border border-gray-100 rounded-lg bg-gray-50 text-center">
            {c.photo ? (
              <img src={c.photo} alt="" className="w-[52px] h-[52px] rounded-full object-cover" />
            ) : (
              <div className="w-[52px] h-[52px] rounded-full bg-brand-secondary text-brand-primary flex items-center justify-center font-bold text-[15px]">{initials(c.name)}</div>
            )}
            <span className="text-[13px] font-semibold text-gray-900 leading-tight">{c.name}</span>
            <span className="text-[11px] text-gray-500 leading-tight">{c.role}</span>
            {c.teams[0] && (
              <span className="text-[10px] font-bold uppercase tracking-wider text-brand-primary bg-white border border-brand-secondary rounded-full px-2 py-0.5 max-w-full truncate" title={c.teams.join(', ')}>
                {c.teams.length > 1 ? `${c.teams[0]} +${c.teams.length - 1}` : c.teams[0]}
              </span>
            )}
          </li>
        ))}
      </ul>
      {pages > 1 && (
        <div className="flex items-center justify-center gap-3.5 pt-1">
          <button type="button" onClick={() => setPage((p) => Math.max(0, p - 1))} disabled={page === 0} aria-label="Previous coaches"
            className="w-11 h-11 rounded-full border border-brand-secondary flex items-center justify-center text-brand-primary disabled:opacity-40">
            <Icon name="chevL" size={18} />
          </button>
          <span className="text-[13px] text-gray-500">{page + 1} of {pages}</span>
          <button type="button" onClick={() => setPage((p) => Math.min(pages - 1, p + 1))} disabled={page >= pages - 1} aria-label="Next coaches"
            className="w-11 h-11 rounded-full border border-brand-secondary flex items-center justify-center text-brand-primary disabled:opacity-40">
            <Icon name="chevR" size={18} />
          </button>
        </div>
      )}
    </Card>
  );
};

type ContactState = { kind: 'idle' } | { kind: 'sending' } | { kind: 'sent' } | { kind: 'error'; message: string; field?: string };

const ContactForm: React.FC<{ slug: string }> = ({ slug }) => {
  const [form, setForm] = useState({ name: '', email: '', phone: '', message: '', website_url: '' });
  const [state, setState] = useState<ContactState>({ kind: 'idle' });
  const field = 'min-h-[44px] px-3 py-2.5 border border-brand-secondary rounded-md text-[15px] text-gray-900 bg-white w-full focus:outline-none focus:border-brand-accent';
  const label = 'flex flex-col gap-1.5 text-xs font-semibold text-gray-700 uppercase tracking-wide';

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setState({ kind: 'sending' });
    try {
      const res = await fetch(publicPageUrl(slug, 'contact'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.success) {
        setState({ kind: 'sent' });
        return;
      }
      if (res.status === 429) {
        setState({ kind: 'error', message: data.error || 'Please try again in an hour.' });
        return;
      }
      if (res.status === 503 && data.feature_disabled) {
        setState({ kind: 'error', message: 'The contact form is not available right now. Please call or email the club.' });
        return;
      }
      setState({ kind: 'error', message: data.error || 'Something went wrong. Please try again.', field: data.field });
    } catch {
      setState({ kind: 'error', message: 'Could not reach the server. Please check your connection and try again.' });
    }
  };

  if (state.kind === 'sent') {
    return (
      <Card className="scroll-mt-4" id="contact">
        <SectionTitle>Contact us</SectionTitle>
        <p role="status" className="m-0 text-sm text-gray-700">Thanks, your message is on its way to the club. They will reply by email.</p>
      </Card>
    );
  }

  return (
    <Card className="scroll-mt-4" id="contact">
      <SectionTitle>Contact us</SectionTitle>
      <p className="m-0 text-[13px] text-gray-500">Your message goes straight to the club administrators. We reply by email.</p>
      <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
        <label className={label}>Your name
          <input className={field} value={form.name} maxLength={100} required autoComplete="name"
            onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="First and last name" />
        </label>
        <label className={label}>Email
          <input className={field} type="email" value={form.email} maxLength={254} required autoComplete="email"
            onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="you@example.com" />
        </label>
        <label className={label}>Phone (optional)
          <input className={field} type="tel" value={form.phone} maxLength={40} autoComplete="tel"
            onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="(555) 555-0100" />
        </label>
        <label className={label}>Message
          <textarea className={field} rows={4} value={form.message} maxLength={2000} required
            onChange={(e) => setForm({ ...form, message: e.target.value })} placeholder="How can we help?" />
        </label>
        {/* Honeypot: never shown; a bot that fills it is answered silently. */}
        <div className="absolute -left-[9999px] top-auto w-px h-px overflow-hidden" aria-hidden="true">
          <label>Website
            <input tabIndex={-1} autoComplete="off" value={form.website_url} onChange={(e) => setForm({ ...form, website_url: e.target.value })} />
          </label>
        </div>
        {state.kind === 'error' && <p role="alert" className="m-0 text-sm text-red-700">{state.message}</p>}
        <button type="submit" disabled={state.kind === 'sending'} className={`${pillPrimary} disabled:opacity-60`}>
          <Icon name="send" size={18} /><span>{state.kind === 'sending' ? 'Sending…' : 'Send message'}</span>
        </button>
      </form>
    </Card>
  );
};

// -------------------------------------------------------------------- page

const ClubLinkPage: React.FC = () => {
  const { slug = '' } = useParams<{ slug: string }>();
  const [data, setData] = useState<PublicPagePayload | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'not_found' | 'error'>('loading');
  const [showingAll, setShowingAll] = useState(false);
  const [shared, setShared] = useState(false);

  const load = useCallback(async (all: boolean) => {
    try {
      const res = await fetch(publicPageUrl(slug, 'page', all ? '&events=all' : ''));
      if (res.status === 404) { setStatus('not_found'); return; }
      const json = await res.json();
      if (!res.ok || !json.club) { setStatus('error'); return; }
      setData(json as PublicPagePayload);
      setStatus('ready');
    } catch {
      setStatus('error');
    }
  }, [slug]);

  useEffect(() => { load(false); }, [load]);

  useEffect(() => {
    if (!data) return;
    const prev = document.title;
    document.title = `${data.club.name} — Teams Elevated`;
    return () => { document.title = prev; };
  }, [data]);

  const brandVars = useMemo(() => {
    if (!data) return {};
    const p = generateColorPalette(data.club.primary_color, data.club.secondary_color);
    return {
      '--color-primary': p.primary,
      '--color-primary-hover': p.primaryHover,
      '--color-primary-dark': p.primaryDark,
      '--color-secondary': p.secondary,
      '--color-secondary-hover': p.secondaryHover,
      '--color-accent': p.accent,
      '--color-light': p.light,
      '--color-muted': p.muted,
    } as React.CSSProperties;
  }, [data]);

  const share = async () => {
    const url = window.location.href;
    try {
      if (navigator.share) {
        await navigator.share({ title: data?.club.name, url });
        return;
      }
      await navigator.clipboard.writeText(url);
      setShared(true);
      window.setTimeout(() => setShared(false), 2000);
    } catch {
      /* user dismissed the share sheet */
    }
  };

  if (status === 'loading') {
    return <div className="min-h-screen bg-gray-50 flex items-center justify-center text-gray-500 text-sm">Loading…</div>;
  }
  if (status === 'not_found' || status === 'error' || !data) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
        <div className="max-w-md w-full bg-white border border-gray-200 rounded-lg p-6 text-center">
          <h1 className="m-0 text-lg font-bold text-gray-900">{status === 'not_found' ? 'This club page is not available.' : 'Something went wrong loading this page.'}</h1>
          <p className="mt-2 mb-0 text-sm text-gray-500">
            {status === 'not_found' ? 'The link may be wrong, or the club has turned its page off.' : 'Please try again in a moment.'}
          </p>
        </div>
      </div>
    );
  }

  const { club, sponsors, coaches, events } = data;
  return (
    <div className="min-h-screen bg-gray-100" style={brandVars} data-testid="club-link-page">
      <div className="max-w-md mx-auto min-h-screen bg-[#f5f6f8] flex flex-col sm:my-6 sm:min-h-0 sm:rounded-2xl sm:overflow-hidden sm:shadow-xl">
        <Hero club={club} onShare={share} shared={shared} />
        <div className="px-4 pt-4 pb-5 flex flex-col gap-4 relative">
          <ActionPills club={club} />
          <SocialRow club={club} />
          <SponsorBanner sponsors={sponsors} />
          <UpcomingGames events={events} slug={club.slug} showingAll={showingAll} onSeeAll={() => { setShowingAll(true); load(true); }} />
          <CoachesGrid coaches={coaches} />
          <ContactForm slug={club.slug} />
          <footer className="flex flex-col items-center gap-2 pt-3 pb-2 text-gray-400 text-[11px] uppercase tracking-[0.12em]">
            <span>Powered by</span>
            <a href="https://teamselevated.com" target="_blank" rel="noopener noreferrer" className="font-bold text-gray-500 hover:text-gray-700" style={{ fontFamily: "'Orbitron', sans-serif" }}>Teams Elevated</a>
          </footer>
        </div>
      </div>
    </div>
  );
};

export default ClubLinkPage;

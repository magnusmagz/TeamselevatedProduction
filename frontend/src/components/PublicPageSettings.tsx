import React, { useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import Button, { LinkButton } from './ui/Button';

/**
 * Club Profile → Public Page tab (2026-09-09).
 *
 * The one place a club controls /club/<slug>: on/off, the link itself, a
 * tagline, the public calendar feed URL and a QR code. Saves go through the
 * same club-profile PUT as everything else on the page; the parent passes the
 * current profile in and a save function out, so this component never fetches.
 */

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

export interface PublicPageFields {
  slug?: string | null;
  public_page_enabled?: boolean;
  public_page_tagline?: string | null;
  public_page_migration_pending?: boolean;
}

interface Props {
  profile: PublicPageFields;
  clubName: string;
  saving: boolean;
  /** Resolves with the server's error sentence on a refused save, or null when it saved. */
  onSave: (fields: { slug: string; public_page_enabled: boolean; public_page_tagline: string }) => Promise<string | null>;
}

export function publicPagePath(slug: string): string {
  return `/club/${slug}`;
}

export function publicPageIcsUrl(slug: string): string {
  return `${API_URL}/api/club-public-gateway.php?action=ics&slug=${encodeURIComponent(slug)}`;
}

export const SLUG_HELP = 'Lowercase letters, numbers and dashes, 3 to 60 characters. Changing it breaks links you have already shared.';

const PublicPageSettings: React.FC<Props> = ({ profile, clubName, saving, onSave }) => {
  const [enabled, setEnabled] = useState<boolean>(profile.public_page_enabled ?? true);
  const [slug, setSlug] = useState<string>(profile.slug ?? '');
  const [tagline, setTagline] = useState<string>(profile.public_page_tagline ?? '');
  const [error, setError] = useState<string | null>(null);
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const [copied, setCopied] = useState<'link' | 'feed' | null>(null);

  const migrationPending = !!profile.public_page_migration_pending;
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const fullUrl = slug ? `${origin}${publicPagePath(slug)}` : '';
  const slugLooksValid = /^[a-z0-9](?:[a-z0-9-]{1,58})[a-z0-9]$/.test(slug);

  const copy = async (text: string, which: 'link' | 'feed') => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(which);
      window.setTimeout(() => setCopied(null), 2000);
    } catch {
      /* clipboard unavailable: the field is selectable */
    }
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    if (slug !== '' && !slugLooksValid) {
      setError(SLUG_HELP);
      return;
    }
    const err = await onSave({ slug, public_page_enabled: enabled, public_page_tagline: tagline });
    if (err) {
      setError(err);
    } else {
      setSavedAt(Date.now());
    }
  };

  const inputCls = 'w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent';
  const labelCls = 'block text-brand-primary text-sm font-medium mb-2 uppercase';

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start" data-testid="public-page-settings">
      <form onSubmit={save} className="bg-white border border-brand-secondary rounded-md p-6 flex flex-col gap-5">
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="text-[15px] font-semibold text-gray-900">Public page is {enabled ? 'live' : 'off'}</div>
            <div className="text-[13px] text-gray-500">Anyone with the link can see it. Turn it off to hide it.</div>
            {migrationPending && (
              <div className="text-[13px] text-amber-700 mt-1">The on/off switch and tagline will work once the next database update is applied.</div>
            )}
          </div>
          <label className="inline-flex items-center cursor-pointer">
            <input
              type="checkbox"
              role="switch"
              aria-checked={enabled}
              aria-label="Public page is live"
              className="sr-only peer"
              checked={enabled}
              disabled={migrationPending}
              onChange={(e) => setEnabled(e.target.checked)}
            />
            <span className="w-11 h-6 rounded-full bg-gray-300 peer-checked:bg-brand-primary relative transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-5" />
          </label>
        </div>

        <div>
          <label className={labelCls} htmlFor="public-page-slug">Your link</label>
          <div className="flex items-stretch">
            <span className="px-3 py-2 border border-r-0 border-brand-secondary rounded-l-md bg-gray-100 text-sm text-gray-500 whitespace-nowrap flex items-center">
              {origin.replace(/^https?:\/\//, '')}/club/
            </span>
            <input
              id="public-page-slug"
              className={`${inputCls} rounded-l-none`}
              value={slug}
              onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
              placeholder="your-club-name"
              maxLength={60}
            />
          </div>
          <p className="text-xs text-gray-500 mt-1">{SLUG_HELP} Leave it blank to generate one from the club name.</p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button type="button" onClick={() => copy(fullUrl, 'link')} disabled={!slugLooksValid}>
            {copied === 'link' ? 'Copied' : 'Copy link'}
          </Button>
          <LinkButton variant="secondary" href={fullUrl || undefined} target="_blank" rel="noopener noreferrer">
            Open page
          </LinkButton>
        </div>

        <div>
          <label className={labelCls} htmlFor="public-page-tagline">Tagline (optional)</label>
          <input
            id="public-page-tagline"
            className={inputCls}
            value={tagline}
            maxLength={160}
            disabled={migrationPending}
            onChange={(e) => setTagline(e.target.value)}
            placeholder={`Youth soccer for ${clubName || 'your community'}`}
          />
          <p className="text-xs text-gray-500 mt-1">Shows under the club name. {tagline.length}/160.</p>
        </div>

        <div>
          <label className={labelCls} htmlFor="public-page-feed">Public calendar feed</label>
          <div className="flex gap-2 items-center">
            <input id="public-page-feed" className={`${inputCls} text-xs`} readOnly value={slug ? publicPageIcsUrl(slug) : ''} />
            <Button type="button" variant="secondary" size="sm" onClick={() => copy(publicPageIcsUrl(slug), 'feed')} disabled={!slugLooksValid}>
              {copied === 'feed' ? 'Copied' : 'Copy'}
            </Button>
          </div>
          <p className="text-xs text-gray-500 mt-1">Families can subscribe in Google or Apple Calendar. Games and tournaments only.</p>
        </div>

        {error && <p role="alert" className="m-0 text-sm text-red-700">{error}</p>}
        {savedAt && !error && <p role="status" className="m-0 text-sm text-brand-primary">Saved.</p>}

        <div className="flex justify-end pt-2 border-t border-gray-100">
          <Button type="submit" loading={saving}>Save changes</Button>
        </div>
      </form>

      <div className="flex flex-col gap-4">
        <div className="bg-white border border-brand-secondary rounded-md p-6">
          <div className="text-[15px] font-semibold text-gray-900 mb-3">What visitors see</div>
          <ul className="m-0 p-0 list-none flex flex-col gap-2 text-[13px] text-gray-700">
            <li>Club name, logo, colours and tagline</li>
            <li>Phone, website and social links</li>
            <li>Sponsor banner</li>
            <li>Upcoming games and tournaments (no practices, no descriptions)</li>
            <li>Our coaches: name, role and team, nine to a page</li>
            <li>A contact form that emails every club administrator</li>
            <li className="text-red-800 font-semibold">Never athletes, families, rosters or coaches&rsquo; contact details</li>
          </ul>
        </div>
        <div className="bg-white border border-brand-secondary rounded-md p-6 flex gap-5 items-center">
          <div className="border border-gray-200 rounded p-2 bg-white" data-testid="public-page-qr">
            {slugLooksValid ? <QRCodeSVG value={fullUrl} size={112} /> : <div className="w-28 h-28" />}
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-[15px] font-semibold text-gray-900">QR code</span>
            <span className="text-[13px] text-gray-500">Print it on flyers, banners and sign-up tables. It opens your public page.</span>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PublicPageSettings;

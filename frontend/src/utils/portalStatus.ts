/**
 * Platform-access status, labelled once for both the Crew page and the Coaches page.
 *
 * The backend half is lib/portal_status.php. Keep the union below in step with
 * te_portal_status() there — an unrecognised status renders as "Unknown", never as
 * a blank cell, because blank reads as "fine" and this column exists to show what
 * is NOT fine.
 *
 * Why "Portal active" is gone: it meant "a users row on this email has a password",
 * which is not a login. It reported two coaches as active on 2026-07-31 who had
 * never signed in. The badge now states the thing it can actually prove — the date
 * they first signed in.
 */

export type PortalStatus =
  | 'active'
  | 'account_never_used'
  | 'invited'
  | 'invite_expired'
  | 'not_invited'
  | 'no_email';

export interface PortalStatusFields {
  // Optional because a response cached from before this shipped has no `status`,
  // and because CoachManagement's row type predates it. An absent status must
  // render as Unknown, not crash the table.
  status?: PortalStatus | string;
  first_login_at?: string | null;
  invited_at?: string | null;
  shared_account?: boolean;
  shared_reason?: string | null;
}

export const PORTAL_STATUS_META: Record<
  PortalStatus,
  { label: string; cls: string; dot: string; help: string }
> = {
  active: {
    label: 'On the platform',
    cls: 'bg-green-100 text-green-800',
    dot: 'bg-green-600',
    help: 'Signed in at least once.',
  },
  account_never_used: {
    label: 'Account never used',
    cls: 'bg-sky-100 text-sky-800',
    dot: 'bg-sky-500',
    help: 'An account exists but nobody has ever signed into it.',
  },
  invited: {
    label: 'Invited',
    cls: 'bg-amber-100 text-amber-800',
    dot: 'bg-amber-500',
    help: 'Invite sent and still valid. They have not set a password yet.',
  },
  invite_expired: {
    label: 'Invite expired',
    cls: 'bg-orange-100 text-orange-800',
    dot: 'bg-orange-500',
    help: 'They were invited but the link lapsed before they used it. Resend.',
  },
  not_invited: {
    label: 'Not invited',
    cls: 'bg-gray-100 text-gray-600',
    dot: 'bg-gray-400',
    help: 'No invite has been sent.',
  },
  no_email: {
    label: 'No email',
    cls: 'bg-red-100 text-red-700',
    dot: 'bg-red-500',
    help: 'No address on file, so they cannot be invited at all.',
  },
};

const UNKNOWN = {
  label: 'Unknown',
  cls: 'bg-gray-100 text-gray-600',
  dot: 'bg-gray-400',
  help: 'Status could not be determined.',
};

export function portalStatusMeta(status?: string | null) {
  return PORTAL_STATUS_META[status as PortalStatus] ?? UNKNOWN;
}

/** Filter chips, in funnel order — what you chase first is what reads first. */
export const PORTAL_STATUS_ORDER: PortalStatus[] = [
  'not_invited',
  'invite_expired',
  'invited',
  'account_never_used',
  'active',
  'no_email',
];

/** "2 Aug 2026" — short, unambiguous across locales, no time-of-day noise. */
export function formatLoginDate(iso?: string | null): string | null {
  if (!iso) return null;
  const d = new Date(iso.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * The line under the badge. Each status gets the ONE date that matters for it —
 * showing "invited 31 Jul" next to someone who is already in is noise.
 */
export function portalStatusDetail(m: PortalStatusFields): string | null {
  const first = formatLoginDate(m.first_login_at);
  const invited = formatLoginDate(m.invited_at);
  switch (m.status) {
    case 'active':
      return first ? `Since ${first}` : null;
    case 'invited':
    case 'invite_expired':
      return invited ? `Invited ${invited}` : null;
    case 'account_never_used':
      return invited ? `Invited ${invited}` : 'Created by an admin';
    default:
      return null;
  }
}

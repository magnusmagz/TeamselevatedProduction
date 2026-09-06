/**
 * Which access control the Coaches page draws for a coach's portal status.
 *
 * Mirrors te_coach_access_action_for_status() in lib/coach_access.php. The
 * backend re-derives the state from the database on every request — this only
 * decides the label; an invite sent to an account that already has a password
 * comes back 409 and the page says so.
 */
export type CoachAccessAction = 'invite' | 'resend' | 'login_link';

export function coachAccessAction(status?: string | null): CoachAccessAction | null {
  switch (status) {
    case 'not_invited':
      return 'invite';
    case 'invited':
    case 'invite_expired':
      return 'resend';
    case 'active':
    case 'account_never_used':
      return 'login_link';
    default:
      return null;
  }
}

export const COACH_ACCESS_LABEL: Record<CoachAccessAction, string> = {
  invite: 'Invite',
  resend: 'Resend invite',
  login_link: 'Send login link',
};

/** The endpoint action behind each control. */
export const COACH_ACCESS_ENDPOINT: Record<CoachAccessAction, string> = {
  invite: 'invite',
  resend: 'invite',
  login_link: 'send-login-link',
};

export const TEMP_PASSWORD_MIN_LENGTH = 10;

/**
 * A 12-character temporary password from an unambiguous alphabet (no 0/O, 1/l/I),
 * drawn from crypto.getRandomValues. An admin reads this aloud or pastes it into
 * a text — ambiguous glyphs are how that goes wrong.
 */
export function generateTemporaryPassword(length = 12): string {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
  const bytes = new Uint32Array(length);
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < length; i++) bytes[i] = Math.floor(Math.random() * 0xffffffff);
  }
  let out = '';
  for (let i = 0; i < length; i++) out += alphabet[bytes[i] % alphabet.length];
  return out;
}

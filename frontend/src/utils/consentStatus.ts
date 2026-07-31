// Staff-facing parental-consent status. Mirrors te_consent_rollup_status() in
// lib/consent_capture.php — the backend computes the status, this module owns how
// it is LABELLED and coloured, so the two never disagree about what the rungs are.
//
// The ladder is not cosmetic. The gap between "the parent agreed" and "the parent
// confirmed by email" is what COPPA's verifiable-consent standard turns on, and the
// gap between portal and registration consent is what tells a club whether the
// agreement is tied to an account or only to a sign-up form.

export type ConsentStatus =
  | 'verified'
  | 'confirmed'
  | 'signup_only'
  | 'partial'
  | 'none';

export interface ConsentStatusMeta {
  label: string;
  /** One line a club admin can act on, shown on the athlete profile. */
  detail: string;
  cls: string;
  /** Does the club still have something to chase? */
  outstanding: boolean;
}

export const CONSENT_STATUS_META: Record<ConsentStatus, ConsentStatusMeta> = {
  verified: {
    label: 'Verified',
    detail: 'Agreed in the portal and confirmed by email.',
    cls: 'bg-green-100 text-green-700',
    outstanding: false,
  },
  confirmed: {
    label: 'Agreed',
    detail: 'Agreed in the portal. Waiting on the emailed confirmation link.',
    cls: 'bg-emerald-50 text-emerald-700',
    outstanding: false,
  },
  signup_only: {
    label: 'Sign-up only',
    detail:
      'Agreed on the registration form, but not yet confirmed from a portal account.',
    cls: 'bg-amber-100 text-amber-800',
    outstanding: true,
  },
  partial: {
    label: 'Incomplete',
    detail: 'Some consents are on file, others are missing.',
    cls: 'bg-orange-100 text-orange-800',
    outstanding: true,
  },
  none: {
    label: 'Not on file',
    detail: 'No parental consent has been recorded for this athlete.',
    cls: 'bg-red-100 text-red-700',
    outstanding: true,
  },
};

/** Unknown values render rather than disappear — a blank cell reads as "fine". */
export function consentStatusMeta(status: string | null | undefined): ConsentStatusMeta {
  if (status && status in CONSENT_STATUS_META) {
    return CONSENT_STATUS_META[status as ConsentStatus];
  }
  return {
    label: status ? String(status) : 'Unknown',
    detail: 'Consent state could not be determined.',
    cls: 'bg-gray-100 text-gray-600',
    outstanding: true,
  };
}

/** Order for a "worst first" sort — what a club needs to chase comes first. */
export const CONSENT_STATUS_ORDER: ConsentStatus[] = [
  'none',
  'partial',
  'signup_only',
  'confirmed',
  'verified',
];

export function consentStatusRank(status: string | null | undefined): number {
  const i = CONSENT_STATUS_ORDER.indexOf(status as ConsentStatus);
  return i === -1 ? -1 : i;
}

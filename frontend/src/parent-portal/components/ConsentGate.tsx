import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';

/**
 * Parental consent, captured from the parent — which is the only place it can
 * honestly be captured.
 *
 * WHAT THIS REPLACES
 * `AthleteForm` (a STAFF screen) carried a "Parental Consent (Required)" block
 * with two checkboxes. They were local React state: never sent anywhere, never
 * written to `consent_records`, and force-set to `true` whenever anyone edited an
 * existing athlete. They gated the submit button and nothing else. So the product
 * asserted COPPA consent capture and stored nothing — and a club admin ticking
 * "As the parent/legal guardian, I consent" is not parental consent in any case.
 * `api/consent.php` has had a complete, working implementation the whole time;
 * nothing ever called `action=record`.
 *
 * ONE PARENT, SEVERAL CHILDREN
 * `consent_records` is keyed (guardian_id, athlete_id, consent_type) and
 * `athlete_id` is NOT NULL, which is correct: consent is about a specific child,
 * so consenting for Rachel says nothing about Sam. But a parent should not read
 * the same wall of text three times. So the statements appear ONCE, the children
 * they cover are listed explicitly, and submitting writes a separate row per
 * child. Adding a fourth child later re-raises this gate for that child alone.
 *
 * BLOCKING, BUT NOT TRAPPING
 * The gate cannot be dismissed — no close control, and it renders instead of the
 * portal rather than over it, so there is no route around it. It does have a
 * decline path, deliberately: consent that cannot be refused is not consent
 * (GDPR Art.4(11) "freely given"), and a parent with no way out would simply be
 * locked out of a product they are already inside. Declining signs them out and
 * points them at the club.
 *
 * PROCEEDING vs. VERIFIED
 * `action=status` reports `has_active_consent` only once the parent has clicked
 * the emailed confirmation link — COPPA verifiable consent is double opt-in. The
 * gate deliberately clears on the RECORDED row, not the confirmed one: blocking a
 * parent inside the portal until they leave, find an email and come back would
 * strand anyone whose mail is slow or filtered. Confirmation is chased by the
 * banner below instead, and staff-side reporting can still distinguish recorded
 * from verified.
 */

const REQUIRED_CONSENT_TYPES = ['data_collection', 'medical_data'] as const;

interface ConsentRow {
  consent_type: string;
  consent_given: boolean | string;
  revoked_at: string | null;
  email_confirmed_at: string | null;
  /** 'registration' | 'portal' | 'staff'. See migration 063. */
  source?: string | null;
  consented_at?: string | null;
}

interface AthleteConsentState {
  athleteId: number;
  name: string;
  /** Recorded in the PORTAL and not withdrawn — this is what clears the gate. */
  portalTypes: Set<string>;
  /** Recorded at public registration — shown as context, does not clear the gate. */
  registrationTypes: Set<string>;
  /** Portal consent confirmed by email — what COPPA counts as verified. */
  confirmedTypes: Set<string>;
  /** When they agreed at sign-up, for the re-affirmation copy. */
  registeredAt: string | null;
}

const truthy = (v: boolean | string): boolean =>
  v === true || v === 'true' || v === 't' || v === '1';

export const ConsentGate: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user, logout } = useAuth();
  const { accessibleAthletes, loading: permissionsLoading } = useFinancialPermissions();

  const [states, setStates] = useState<AthleteConsentState[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [agreedData, setAgreedData] = useState(false);
  const [agreedMedical, setAgreedMedical] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [declining, setDeclining] = useState(false);

  const loadStatus = useCallback(async () => {
    if (permissionsLoading) return;
    if (!accessibleAthletes || accessibleAthletes.length === 0) {
      setStates([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    const token = localStorage.getItem('auth_token');

    const results = await Promise.all(
      accessibleAthletes.map(async (a) => {
        const base: AthleteConsentState = {
          athleteId: a.id,
          name: `${a.first_name} ${a.last_name}`.trim(),
          portalTypes: new Set<string>(),
          registrationTypes: new Set<string>(),
          confirmedTypes: new Set<string>(),
          registeredAt: null,
        };
        try {
          const res = await fetch(
            `${API_URL}/api/consent.php?action=status&athlete_id=${a.id}`,
            { headers: { Authorization: `Bearer ${token}` } }
          );
          const data = await res.json();
          if (res.ok && data.success && Array.isArray(data.consents)) {
            for (const row of data.consents as ConsentRow[]) {
              if (!truthy(row.consent_given) || row.revoked_at) continue;
              // Rows predating migration 063 carry no source, and the migration
              // backfilled them to 'portal' because ConsentGate was the only
              // writer that had ever existed. Defaulting the same way here means
              // a backend that has not been migrated yet cannot re-prompt a
              // family who already confirmed.
              const source = row.source ?? 'portal';
              if (source === 'registration') {
                base.registrationTypes.add(row.consent_type);
                if (!base.registeredAt) base.registeredAt = row.consented_at ?? null;
              } else {
                base.portalTypes.add(row.consent_type);
                if (row.email_confirmed_at) base.confirmedTypes.add(row.consent_type);
              }
            }
          }
        } catch {
          // A status read that fails must not lock a parent out of the portal.
          // Treat it as "already consented" — the gate is a prompt, not a
          // security control, and the real enforcement is server-side.
          REQUIRED_CONSENT_TYPES.forEach((t) => base.portalTypes.add(t));
        }
        return base;
      })
    );

    setStates(results);
    setLoading(false);
  }, [API_URL, accessibleAthletes, permissionsLoading]);

  useEffect(() => {
    loadStatus();
  }, [loadStatus]);

  // Keyed on the PORTAL record, not on consent generally — that is what makes
  // this a re-affirmation rather than a first ask. A family who agreed at sign-up
  // is still asked here, deliberately (see the header).
  const needsConsent = (states || []).filter((s) =>
    REQUIRED_CONSENT_TYPES.some((t) => !s.portalTypes.has(t))
  );

  const awaitingConfirmation = (states || []).filter(
    (s) =>
      REQUIRED_CONSENT_TYPES.every((t) => s.portalTypes.has(t)) &&
      REQUIRED_CONSENT_TYPES.some((t) => !s.confirmedTypes.has(t))
  );

  /** Everyone being asked already agreed at sign-up — soften the ask. */
  const allPreviouslyAgreed =
    needsConsent.length > 0 &&
    needsConsent.every((s) =>
      REQUIRED_CONSENT_TYPES.every((t) => s.registrationTypes.has(t))
    );

  const earliestSignup = needsConsent
    .map((s) => s.registeredAt)
    .filter(Boolean)
    .sort()[0];

  const handleSubmit = async () => {
    if (!user?.id) return;
    setSubmitting(true);
    setError(null);
    const token = localStorage.getItem('auth_token');

    try {
      // One row per (child × consent type) — the record has to be per-child even
      // though the parent agreed once. Sequential rather than parallel: each
      // record call also sends a confirmation email, and firing a dozen at once
      // for a three-child family is how a provider starts rate-limiting.
      for (const s of needsConsent) {
        for (const type of REQUIRED_CONSENT_TYPES) {
          if (s.portalTypes.has(type)) continue;
          const res = await fetch(`${API_URL}/api/consent.php?action=record`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({
              athlete_id: s.athleteId,
              guardian_id: user.id,
              consent_type: type,
              consent_given: true,
            }),
          });
          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Could not record consent.');
          }
        }
      }
      await loadStatus();
    } catch (e) {
      setError(
        e instanceof Error ? e.message : 'Could not record consent. Please try again.'
      );
    } finally {
      setSubmitting(false);
    }
  };

  // Never hold the portal hostage to a slow status read.
  if (loading || permissionsLoading || states === null) return <>{children}</>;

  if (declining) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
        <div className="max-w-md w-full bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h1 className="text-xl font-bold text-brand-primary mb-3">
            We need a parent's consent first
          </h1>
          <p className="text-sm text-gray-700 mb-3">
            Without it your club can't keep your child's information in this system,
            so there's nothing for the portal to show you.
          </p>
          <p className="text-sm text-gray-700 mb-4">
            If you have questions about what's collected or why, contact your club
            directly — they can talk it through, and you can come back and agree at
            any time.
          </p>
          <div className="flex gap-3">
            <button
              onClick={() => setDeclining(false)}
              className="flex-1 px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm font-medium"
            >
              Back
            </button>
            <button
              onClick={() => logout()}
              className="flex-1 px-4 py-2 bg-brand-primary text-white rounded-md hover:opacity-90 text-sm font-medium"
            >
              Sign out
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (needsConsent.length > 0) {
    const bothAgreed = agreedData && agreedMedical;
    return (
      <div className="min-h-screen bg-gray-50 px-4 py-8">
        <div className="max-w-lg mx-auto bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h1 className="text-xl font-bold text-brand-primary mb-2">
            {allPreviouslyAgreed ? 'Confirm your consent' : 'Parental consent'}
          </h1>
          <p className="text-sm text-gray-600 mb-5">
            {allPreviouslyAgreed ? (
              <>
                You agreed to this when you signed up
                {earliestSignup
                  ? ` on ${new Date(earliestSignup).toLocaleDateString('en-US', {
                      month: 'long',
                      day: 'numeric',
                      year: 'numeric',
                    })}`
                  : ''}
                . Please confirm it here now that you have an account, so it's
                recorded against you rather than just the sign-up form.
              </>
            ) : (
              <>
                Before you use the portal, your club needs your consent as the parent
                or legal guardian. This is asked once per child.
              </>
            )}
          </p>

          <div className="mb-5 rounded-md border border-gray-200 bg-gray-50 p-3">
            <p className="text-xs font-medium text-gray-500 uppercase mb-2">
              {needsConsent.length === 1 ? 'This applies to' : 'This applies to'}
            </p>
            <ul className="space-y-1">
              {needsConsent.map((s) => (
                <li key={s.athleteId} className="text-sm text-gray-900">
                  {s.name}
                </li>
              ))}
            </ul>
          </div>

          <div className="space-y-4">
            <label className="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={agreedData}
                onChange={(e) => setAgreedData(e.target.checked)}
                className="mt-1 h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
              />
              <span className="text-sm text-gray-700">
                As the parent or legal guardian, I consent to the collection and
                storage of my child's personal information as described in the{' '}
                <a
                  href="/privacy-policy"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-brand-primary underline"
                >
                  Privacy Policy
                </a>
                .
              </span>
            </label>

            <label className="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={agreedMedical}
                onChange={(e) => setAgreedMedical(e.target.checked)}
                className="mt-1 h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
              />
              <span className="text-sm text-gray-700">
                I consent to the collection and encrypted storage of my child's
                medical information, accessible only to authorized staff for safety
                purposes — for example allergies, medications and medical conditions.
              </span>
            </label>
          </div>

          {error && (
            <div className="mt-4 bg-red-50 text-red-700 px-3 py-2 rounded text-sm">
              {error}
            </div>
          )}

          <button
            onClick={handleSubmit}
            disabled={!bothAgreed || submitting}
            className="mt-6 w-full px-4 py-2 bg-brand-primary text-white rounded-md hover:opacity-90 text-sm font-medium disabled:opacity-50"
          >
            {submitting ? 'Recording...' : 'I agree'}
          </button>

          <button
            onClick={() => setDeclining(true)}
            disabled={submitting}
            className="mt-3 w-full px-4 py-2 text-sm text-gray-500 hover:text-gray-700 disabled:opacity-50"
          >
            I don't agree
          </button>

          <p className="mt-4 text-xs text-gray-500">
            We'll email you a confirmation link for your records. Your consent is
            recorded now — you can withdraw it at any time from the portal.
          </p>
        </div>
      </div>
    );
  }

  return (
    <>
      {awaitingConfirmation.length > 0 && (
        <div className="bg-amber-50 border-b border-amber-200 px-4 py-2">
          <p className="text-xs text-amber-900 max-w-lg mx-auto">
            Check your email to confirm your consent
            {awaitingConfirmation.length === 1 ? ` for ${awaitingConfirmation[0].name}` : ''}.
            Everything works in the meantime.
          </p>
        </div>
      )}
      {children}
    </>
  );
};

export default ConsentGate;

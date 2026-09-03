/**
 * The shapes api/compliance-gateway.php actually returns (GOTR G4).
 *
 * ⚠️ A type that asserts something false is worse than no type — that is what
 * hid the senderId string/number bug for months. These mirror lib/compliance.php
 * exactly: `required`/`active` arrive as real booleans because
 * te_compliance_decorate_requirements() normalises Postgres' 't'/'f' before they
 * leave the server, and every date is the stored 'YYYY-MM-DD' string, never a
 * timestamp.
 */

export type ProofType = 'document' | 'attested_date' | 'external_link';

export type CredentialStatus =
  | 'missing'
  | 'submitted'
  | 'verified'
  | 'rejected'
  | 'expired';

/** Where an inherited requirement comes from. `editable` is a LABEL, not a permission. */
export interface RequirementOrigin {
  scope: string;
  name: string | null;
  label: string;
  editable: boolean;
}

export interface ComplianceRequirement {
  id: number;
  org_unit_id: number | null;
  club_profile_id: number | null;
  kind: string;
  name: string;
  description: string | null;
  proof: ProofType;
  proof_url: string | null;
  /** null means it never expires. */
  validity_days: number | null;
  required: boolean;
  active: boolean;
  sort_order: number;
  /** An EMPTY list means "every staff role", not "nobody". */
  roles: string[];
  origin?: RequirementOrigin;
}

export interface ComplianceRow {
  requirement: ComplianceRequirement;
  status: CredentialStatus;
  /** 'YYYY-MM-DD' — format with formatDateOnly, never new Date(). */
  completed_at: string | null;
  expires_at: string | null;
  /** Negative once it has passed. Null when there is no expiry at all. */
  days_to_expiry: number | null;
  credential_id: number | null;
  document_id: number | null;
  rejection_reason: string | null;
  source: string | null;
}

export interface ComplianceRollup {
  compliant: boolean;
  missing: number;
  expiring_30: number;
  expired: number;
  required_total: number;
  total: number;
}

export interface CompliancePerson {
  user_id: number;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  rollup: ComplianceRollup;
  requirements: ComplianceRow[];
}

export interface ComplianceSummary {
  total: number;
  compliant: number;
  expiring_30: number;
  expired: number;
  missing: number;
}

export interface ComplianceVocabulary {
  kinds: string[];
  proofs: ProofType[];
  roles: string[];
}

/** One club's worth of "what do I owe", from action=my-requirements. */
export interface MyComplianceClub {
  club_id: number;
  requirements: ComplianceRow[];
  rollup: ComplianceRollup;
}

export type ComplianceFilter = '' | 'compliant' | 'expiring' | 'expired' | 'missing';

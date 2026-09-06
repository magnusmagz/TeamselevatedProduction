/**
 * The org-tier compliance rollup endpoint, api/compliance-rollup.php (GOTR G5).
 *
 * Same conventions as ./api.ts: every call checks `response.ok` and throws the
 * server's sentence, because every page here renders a list and a parsed 403
 * body would go straight into `.map`.
 *
 * Nothing in this file writes. The endpoint has no write action, and the test
 * suite on the PHP side asserts that; this module simply has nothing to offer
 * a caller who wants one.
 */

import { API_URL, authHeaders } from './api';
import type { ComplianceRow, ComplianceRollup, ComplianceSummary } from './types';

const ENDPOINT = `${API_URL}/api/compliance-rollup.php`;

async function readError(response: Response): Promise<string> {
  try {
    const body = await response.json();
    if (body?.error) return String(body.error);
  } catch {
    // Non-JSON error body — fall through to the status message.
  }
  return `Request failed (${response.status})`;
}

async function getJson<T>(url: string): Promise<T> {
  const response = await fetch(url, { headers: authHeaders() });
  if (!response.ok) {
    throw new Error(await readError(response));
  }
  return response.json();
}

export type OrgRole = 'org_admin' | 'org_viewer';

/** One org unit the caller has standing at, from ?view=units. */
export interface OrgStandingUnit {
  org_unit_id: number;
  role: OrgRole;
  name: string;
  type: 'national' | 'division' | 'council' | string;
  path: string;
  depth: number;
}

export interface OrgUnitsResponse {
  success: boolean;
  available: boolean;
  units: OrgStandingUnit[];
}

export function fetchMyOrgUnits(): Promise<OrgUnitsResponse> {
  return getJson<OrgUnitsResponse>(`${ENDPOINT}?view=units`);
}

export interface OrgUnit {
  id: number;
  parent_id: number | null;
  type: string;
  name: string;
  external_code: string | null;
  path: string;
  depth: number;
  club_count?: number;
}

/** One council's counts. Every count is PEOPLE, not credentials. */
export interface CouncilRollup {
  club_id: number;
  club_name: string;
  org_unit_id: number;
  org_unit_name: string;
  org_unit_type: string;
  org_unit_path: string;
  staff_total: number;
  compliant: number;
  expiring_30: number;
  expired: number;
  missing: number;
  non_compliant: number;
  /** 0..1, or null when there is nobody on staff — which is not 0. */
  risk_share: number | null;
}

export interface RollupRequirement {
  id: number;
  name: string;
  kind: string;
  required: boolean;
  org_unit_id: number | null;
  club_profile_id: number | null;
}

export interface RollupSummaryResponse {
  success: boolean;
  available: boolean;
  standing: OrgRole;
  unit: OrgUnit | null;
  units: OrgUnit[];
  /** 'YYYY-MM-DD' */
  as_of: string;
  requirement_id: number | null;
  requirements: RollupRequirement[];
  total: ComplianceSummary & { staff_total: number };
  councils: CouncilRollup[];
}

export function fetchRollupSummary(orgUnitId: number, requirementId: number | null): Promise<RollupSummaryResponse> {
  const suffix = requirementId ? `&requirement_id=${requirementId}` : '';
  return getJson<RollupSummaryResponse>(`${ENDPOINT}?view=summary&org_unit_id=${orgUnitId}${suffix}`);
}

export interface CouncilTrend {
  club_id: number;
  club_name: string;
  /** One integer per entry of `months`, in the same order. */
  by_month: number[];
}

export interface RollupTrendResponse {
  success: boolean;
  available: boolean;
  /** 'YYYY-MM', six of them, this month first. */
  months: string[];
  councils: CouncilTrend[];
}

export function fetchRollupTrend(orgUnitId: number): Promise<RollupTrendResponse> {
  return getJson<RollupTrendResponse>(`${ENDPOINT}?view=trend&org_unit_id=${orgUnitId}`);
}

export interface RollupPerson {
  user_id: number;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  staff_roles: string[];
  rollup: ComplianceRollup;
  requirements: ComplianceRow[];
}

export interface RollupClubResponse {
  success: boolean;
  available: boolean;
  club: { id: number; name: string };
  summary: { total: number; compliant: number; expiring_30: number; expired: number; missing: number };
  people: RollupPerson[];
}

export function fetchRollupClub(orgUnitId: number, clubId: number): Promise<RollupClubResponse> {
  return getJson<RollupClubResponse>(`${ENDPOINT}?view=club&org_unit_id=${orgUnitId}&club_id=${clubId}`);
}

/** "2026-09" → "Sep 2026". Read off the string; never new Date() on a date-only value. */
export function monthLabel(yyyyMm: string): string {
  const m = /^(\d{4})-(\d{2})$/.exec(yyyyMm);
  if (!m) return yyyyMm;
  const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const index = Number(m[2]) - 1;
  return `${names[index] ?? m[2]} ${m[1]}`;
}

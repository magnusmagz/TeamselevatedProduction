/**
 * One place the compliance screens talk to the backend (GOTR G4).
 *
 * Three pages read the same two endpoints; re-declaring the URL and the bearer
 * header in each is how one of them ends up on a stale action name. These are
 * plain functions rather than hooks, so a caller can put them in a useCallback
 * with an honest dependency list instead of suppressing the lint rule.
 *
 * ⚠️ Every call checks `response.ok` and throws, because these pages render a
 * LIST. fetch() does not reject on 4xx/5xx, so without the check a 403 body
 * would be parsed and `.map`ped, and the page would go through the
 * ErrorBoundary with "Something went wrong" instead of saying you do not have
 * access. (The rule in CLAUDE.md is to read what the component does with the
 * body first — here it maps, so the check belongs.)
 */

import type {
  ComplianceFilter,
  CompliancePerson,
  ComplianceRequirement,
  ComplianceSummary,
  ComplianceVocabulary,
  MyComplianceClub,
} from './types';

export const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

const GATEWAY = `${API_URL}/api/compliance-gateway.php`;

export function authHeaders(): Record<string, string> {
  return { Authorization: `Bearer ${localStorage.getItem('auth_token')}` };
}

/** The one sentence the server gave us, or a status-code fallback. */
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

async function postJson<T>(action: string, body: unknown): Promise<T> {
  const response = await fetch(`${GATEWAY}?action=${action}`, {
    method: 'POST',
    headers: { ...authHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!response.ok) {
    throw new Error(await readError(response));
  }
  return response.json();
}

export interface RequirementsResponse {
  success: boolean;
  /**
   * FALSE means migration 091 has not been applied yet. `main` is shared and
   * migrations are applied by hand, so this is an expected state for a few
   * hours — the page says so rather than rendering an empty list, which would
   * read as "this club has no requirements".
   */
  available: boolean;
  requirements: ComplianceRequirement[];
  vocabulary: ComplianceVocabulary;
}

export function fetchRequirements(clubId: number): Promise<RequirementsResponse> {
  return getJson<RequirementsResponse>(`${GATEWAY}?action=requirements&club_id=${clubId}`);
}

export interface ClubStatusResponse {
  success: boolean;
  available: boolean;
  filter: string | null;
  summary: ComplianceSummary | null;
  people: CompliancePerson[];
}

export function fetchClubStatus(clubId: number, filter: ComplianceFilter): Promise<ClubStatusResponse> {
  const suffix = filter ? `&filter=${filter}` : '';
  return getJson<ClubStatusResponse>(`${GATEWAY}?action=club-status&club_id=${clubId}${suffix}`);
}

export interface MyRequirementsResponse {
  success: boolean;
  available: boolean;
  clubs: MyComplianceClub[];
}

export function fetchMyRequirements(): Promise<MyRequirementsResponse> {
  return getJson<MyRequirementsResponse>(`${GATEWAY}?action=my-requirements`);
}

/** Create or update. Omit `id` to create. The owner is never taken from here on an update. */
export interface SaveRequirementBody {
  id?: number;
  club_profile_id?: number;
  name: string;
  description: string | null;
  kind: string;
  proof: string;
  proof_url: string | null;
  validity_days: number | null;
  roles: string[];
  required: boolean;
  active: boolean;
}

export function saveRequirement(body: SaveRequirementBody): Promise<{ success: boolean; id: number }> {
  return postJson('requirement-save', body);
}

export function deleteRequirement(id: number): Promise<{ success: boolean; deactivated: boolean }> {
  return postJson('requirement-delete', { id });
}

/** An admin entering somebody else's completion. `expires_at` overrides the computed date. */
export function recordCompletion(body: {
  club_id: number;
  user_id: number;
  requirement_id: number;
  completed_at: string;
  expires_at?: string | null;
}): Promise<{ success: boolean }> {
  return postJson('record', body);
}

export function reviewCredential(body: {
  club_id: number;
  user_id: number;
  requirement_id: number;
  decision: 'verify' | 'reject';
  rejection_reason?: string;
}): Promise<{ success: boolean }> {
  return postJson('review', body);
}

/** The person attesting their own completion. No user_id — the person is the token. */
export function submitOwnCompletion(body: {
  requirement_id: number;
  completed_at: string;
}): Promise<{ success: boolean }> {
  return postJson('submit', body);
}

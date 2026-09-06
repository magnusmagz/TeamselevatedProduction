/**
 * The credential-intake admin endpoints (GOTR G7) — api/compliance-intake.php.
 *
 * Only the admin half lives here (keys, unmatched arrivals, matching). The
 * feed itself (`action=lms`) is called by an LMS with an intake key, never by
 * this app.
 */

import { API_URL, authHeaders } from './api';

const ENDPOINT = `${API_URL}/api/compliance-intake.php`;

export interface IntakeKey {
  id: number;
  org_unit_id: number;
  name: string;
  key_prefix: string;
  created_at: string | null;
  last_used_at: string | null;
  revoked_at: string | null;
  active: boolean;
}

export interface UnmatchedArrival {
  id: number;
  org_unit_id: number;
  key_id: number | null;
  email: string;
  requirement_key: string;
  /** 'YYYY-MM-DD' — format with formatDateOnly. */
  completed_on: string | null;
  external_id: string | null;
  reason: 'no_person' | 'no_requirement' | string;
  received_at: string | null;
}

export interface IntakePerson {
  user_id: number;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  club_id: number;
}

async function readError(response: Response): Promise<string> {
  try {
    const body = await response.json();
    if (body?.error) return String(body.error);
  } catch {
    // Non-JSON error body.
  }
  return `Request failed (${response.status})`;
}

async function get<T>(query: string): Promise<T> {
  const response = await fetch(`${ENDPOINT}?${query}`, { headers: authHeaders() });
  if (!response.ok) throw new Error(await readError(response));
  return response.json();
}

async function post<T>(action: string, body: unknown): Promise<T> {
  const response = await fetch(`${ENDPOINT}?action=${action}`, {
    method: 'POST',
    headers: { ...authHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!response.ok) throw new Error(await readError(response));
  return response.json();
}

export function fetchIntakeKeys(orgUnitId: number): Promise<{ success: boolean; available: boolean; keys: IntakeKey[] }> {
  return get(`action=keys&org_unit_id=${orgUnitId}`);
}

/** The response carries the plaintext key ONCE. It is not stored and cannot be fetched again. */
export function createIntakeKey(orgUnitId: number, name: string): Promise<{ success: boolean; id: number; key: string; prefix: string }> {
  return post('key-create', { org_unit_id: orgUnitId, name });
}

export function revokeIntakeKey(orgUnitId: number, id: number): Promise<{ success: boolean }> {
  return post('key-revoke', { org_unit_id: orgUnitId, id });
}

export function fetchUnmatched(orgUnitId: number): Promise<{ success: boolean; available: boolean; arrivals: UnmatchedArrival[] }> {
  return get(`action=unmatched&org_unit_id=${orgUnitId}`);
}

export function searchIntakePeople(orgUnitId: number, q: string): Promise<{ success: boolean; people: IntakePerson[] }> {
  return get(`action=people&org_unit_id=${orgUnitId}&q=${encodeURIComponent(q)}`);
}

export function matchArrival(body: {
  org_unit_id: number;
  id: number;
  user_id: number;
  requirement_id?: number;
}): Promise<{ success: boolean; credential_id: number }> {
  return post('match', body);
}

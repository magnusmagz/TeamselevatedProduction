/**
 * The reminder-stream endpoints (GOTR G7) — api/compliance-streams.php.
 *
 * Same shape as ./api.ts: plain functions, every call checks `response.ok`
 * and throws the server's one sentence. A 422 from `save` carries
 * `unknown_tags`, which the panel shows next to the field — so the thrown
 * error keeps the parsed body on it.
 */

import { API_URL, authHeaders } from './api';

const ENDPOINT = `${API_URL}/api/compliance-streams.php`;

export type StreamApplies = 'own' | 'inherited' | 'default';

export interface StreamStep {
  /** Days BEFORE expiry. Negative means days after it (-7 = a week past). */
  days_before: number;
  subject: string;
  body: string;
  channel: 'email';
}

export interface ReminderStream {
  id: number;
  requirement_id: number;
  org_unit_id: number | null;
  club_profile_id: number | null;
  active: boolean;
  steps: StreamStep[];
  tier?: 'club' | 'org_unit';
  tier_unit?: { id: number; type: string; name: string } | null;
}

export interface StreamDescription {
  success: boolean;
  /** FALSE means migration 091 has not been applied yet. */
  available: boolean;
  applies: StreamApplies;
  stream: ReminderStream | null;
  /** This tier's own row, active or not, so it can be edited or switched back on. */
  own: ReminderStream | null;
  inherited_from: { id: number; type: string; name: string } | null;
  default_thresholds: number[];
  tags: string[];
}

export class StreamApiError extends Error {
  status: number;
  reason: string | null;
  unknownTags: string[];

  constructor(message: string, status: number, body: any) {
    super(message);
    this.status = status;
    this.reason = body?.reason ?? null;
    this.unknownTags = Array.isArray(body?.unknown_tags) ? body.unknown_tags : [];
  }
}

async function throwFor(response: Response): Promise<never> {
  let body: any = null;
  try {
    body = await response.json();
  } catch {
    // Non-JSON error body.
  }
  throw new StreamApiError(body?.error ? String(body.error) : `Request failed (${response.status})`, response.status, body);
}

export async function fetchStreamForRequirement(
  requirementId: number,
  tier: { club_id: number } | { org_unit_id: number }
): Promise<StreamDescription> {
  const scope = 'club_id' in tier ? `club_id=${tier.club_id}` : `org_unit_id=${tier.org_unit_id}`;
  const response = await fetch(`${ENDPOINT}?action=for-requirement&requirement_id=${requirementId}&${scope}`, {
    headers: authHeaders(),
  });
  if (!response.ok) return throwFor(response);
  return response.json();
}

async function post<T>(action: string, body: unknown): Promise<T> {
  const response = await fetch(`${ENDPOINT}?action=${action}`, {
    method: 'POST',
    headers: { ...authHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!response.ok) return throwFor(response);
  return response.json();
}

export interface SaveStreamBody {
  id?: number;
  requirement_id: number;
  club_profile_id?: number;
  org_unit_id?: number;
  steps: Array<{ days_before: number; subject: string; body: string; channel: 'email' }>;
  active?: boolean;
}

export function saveStream(body: SaveStreamBody): Promise<{ success: boolean; id: number; stream: ReminderStream }> {
  return post('save', body);
}

export function setStreamActive(id: number, active: boolean): Promise<{ success: boolean; active: boolean; stream: ReminderStream }> {
  return post('set-active', { id, active });
}

export interface PreviewResponse {
  success: boolean;
  subject: string;
  body: string;
  values: Record<string, string>;
}

export function previewStep(body: {
  days_before: number;
  subject: string;
  body: string;
  club_id?: number;
  requirement_name?: string;
}): Promise<PreviewResponse> {
  return post('preview', body);
}

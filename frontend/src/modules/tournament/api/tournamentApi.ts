import { Tournament, TournamentFormData, TournamentStatus, SportPreset } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

function getHeaders(): HeadersInit {
  const token = localStorage.getItem('auth_token');
  return {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
  };
}

export async function listTournaments(
  clubId: number,
  filters?: { status?: TournamentStatus; season_id?: number }
): Promise<{ tournaments: Tournament[] }> {
  let url = `${API_URL}/api/tournament-gateway.php?action=list&club_id=${clubId}`;
  if (filters?.status) url += `&status=${filters.status}`;
  if (filters?.season_id) url += `&season_id=${filters.season_id}`;

  const res = await fetch(url, { headers: getHeaders() });
  if (!res.ok) throw new Error(`Failed to list tournaments: ${res.status}`);
  return res.json();
}

export async function getTournament(id: number): Promise<Tournament> {
  const res = await fetch(
    `${API_URL}/api/tournament-gateway.php?action=get&id=${id}`,
    { headers: getHeaders() }
  );
  if (!res.ok) throw new Error(`Failed to get tournament: ${res.status}`);
  return res.json();
}

export async function createTournament(
  clubId: number,
  data: TournamentFormData
): Promise<{ id: number; message: string }> {
  const res = await fetch(
    `${API_URL}/api/tournament-gateway.php?action=create`,
    {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ club_id: clubId, ...data }),
    }
  );
  if (!res.ok) {
    const err = await res.json();
    throw new Error(err.error || `Failed to create tournament: ${res.status}`);
  }
  return res.json();
}

export async function updateTournament(
  id: number,
  data: Partial<TournamentFormData>
): Promise<{ success: boolean; message: string }> {
  const res = await fetch(
    `${API_URL}/api/tournament-gateway.php?action=update&id=${id}`,
    {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify(data),
    }
  );
  if (!res.ok) {
    const err = await res.json();
    throw new Error(err.error || `Failed to update tournament: ${res.status}`);
  }
  return res.json();
}

export async function updateTournamentStatus(
  id: number,
  status: TournamentStatus
): Promise<{ success: boolean; message: string }> {
  const res = await fetch(
    `${API_URL}/api/tournament-gateway.php?action=update-status&id=${id}`,
    {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ status }),
    }
  );
  if (!res.ok) {
    const err = await res.json();
    throw new Error(err.error || `Failed to update status: ${res.status}`);
  }
  return res.json();
}

export async function deleteTournament(
  id: number
): Promise<{ success: boolean; message: string }> {
  const res = await fetch(
    `${API_URL}/api/tournament-gateway.php?action=delete&id=${id}`,
    {
      method: 'DELETE',
      headers: getHeaders(),
    }
  );
  if (!res.ok) {
    const err = await res.json();
    throw new Error(err.error || `Failed to delete tournament: ${res.status}`);
  }
  return res.json();
}

export async function getSportPresets(sport: string = 'soccer'): Promise<{ presets: SportPreset[] }> {
  const res = await fetch(
    `${API_URL}/api/tournament-gateway.php?action=sport-presets&sport=${sport}`,
    { headers: getHeaders() }
  );
  if (!res.ok) throw new Error(`Failed to get sport presets: ${res.status}`);
  return res.json();
}

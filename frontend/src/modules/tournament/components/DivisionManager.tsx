import React, { useState, useEffect, useCallback } from 'react';
import { TournamentDivision } from '../types';
import DivisionForm from './DivisionForm';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  tournamentId: number;
  sport: string;
  isAdmin: boolean;
}

const FORMAT_LABELS: Record<string, string> = {
  round_robin: 'Round Robin',
  group_knockout: 'Group + Knockout',
  single_elimination: 'Single Elimination',
  double_elimination: 'Double Elimination',
};

const DivisionManager: React.FC<Props> = ({ tournamentId, sport, isAdmin }) => {
  const [divisions, setDivisions] = useState<TournamentDivision[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editingDivision, setEditingDivision] = useState<TournamentDivision | null>(null);
  const [deleting, setDeleting] = useState<number | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
  };

  const fetchDivisions = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=divisions-list&tournament_id=${tournamentId}`,
        { headers }
      );
      const data = await res.json();
      setDivisions(data.divisions || []);
    } catch (err) {
      console.error('Failed to fetch divisions:', err);
    } finally {
      setLoading(false);
    }
  }, [tournamentId]);

  useEffect(() => {
    fetchDivisions();
  }, [fetchDivisions]);

  const handleDelete = async (divId: number) => {
    if (!window.confirm('Delete this division? This cannot be undone.')) return;

    setDeleting(divId);
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=division-delete&id=${divId}`,
        { method: 'DELETE', headers }
      );
      if (res.status === 409) {
        const err = await res.json();
        alert(err.error);
        return;
      }
      if (!res.ok) throw new Error('Failed to delete');
      fetchDivisions();
    } catch (err: any) {
      alert(err.message || 'Failed to delete division');
    } finally {
      setDeleting(null);
    }
  };

  const handleFormSave = () => {
    setShowForm(false);
    setEditingDivision(null);
    fetchDivisions();
  };

  if (showForm || editingDivision) {
    return (
      <DivisionForm
        tournamentId={tournamentId}
        sport={sport}
        division={editingDivision}
        onSave={handleFormSave}
        onCancel={() => { setShowForm(false); setEditingDivision(null); }}
      />
    );
  }

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h3 className="text-lg font-semibold text-gray-900">
          Divisions ({divisions.length})
        </h3>
        {isAdmin && (
          <button
            onClick={() => setShowForm(true)}
            className="inline-flex items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover"
          >
            <svg className="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Division
          </button>
        )}
      </div>

      {loading ? (
        <div className="text-center py-8 text-gray-500">Loading divisions...</div>
      ) : divisions.length === 0 ? (
        <div className="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
          <p className="text-gray-500">No divisions yet. Add your first division to get started.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {divisions.map((div) => (
            <div
              key={div.id}
              className="bg-white border border-gray-200 rounded-lg p-4 flex justify-between items-start"
            >
              <div className="flex-1">
                <div className="flex items-center space-x-3">
                  <h4 className="font-semibold text-gray-900">{div.name}</h4>
                  <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
                    {FORMAT_LABELS[div.format] || div.format}
                  </span>
                </div>
                <div className="mt-1 text-sm text-gray-500 space-x-4">
                  <span>{div.age_group} {div.gender}</span>
                  <span>{div.game_duration_minutes} min ({div.half_duration_minutes}×2)</span>
                  {div.max_players_on_field && <span>{div.max_players_on_field}v{div.max_players_on_field}</span>}
                  <span>Roster: {div.min_roster_size}–{div.max_roster_size}</span>
                  <span>{div.registration_count || 0} teams</span>
                  <span>{div.group_count || 0} groups</span>
                </div>
                {div.sport_rule_notes && div.sport_rule_notes.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1">
                    {div.sport_rule_notes.map((note, i) => (
                      <span key={i} className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800">
                        {note}
                      </span>
                    ))}
                  </div>
                )}
              </div>

              {isAdmin && (
                <div className="flex items-center space-x-2 ml-4">
                  <button
                    onClick={() => setEditingDivision(div)}
                    className="text-sm text-brand-primary hover:underline"
                  >
                    Edit
                  </button>
                  <button
                    onClick={() => handleDelete(div.id)}
                    disabled={deleting === div.id}
                    className="text-sm text-red-600 hover:underline disabled:opacity-50"
                  >
                    {deleting === div.id ? 'Deleting...' : 'Delete'}
                  </button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default DivisionManager;

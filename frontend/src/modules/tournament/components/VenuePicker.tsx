import React, { useState, useEffect, useCallback } from 'react';
import VenueManagement from '../../../components/VenueManagement';
import { VenueSummary } from '../types';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  value: number | null;
  onChange: (venueId: number | null, venue: VenueSummary | null) => void;
  className?: string;
  disabled?: boolean;
}

/**
 * Reusable venue picker.
 *
 * - Lists existing venues from /legacy/venues-gateway.php (same endpoint
 *   VenueManagement.tsx uses, so we share the source of truth).
 * - "+ Manage / create venues" opens the existing VenueManagement modal
 *   (already designed to be opened with `onClose`). On close, refetch the
 *   list and auto-select the most recently-created venue if one was added.
 *
 * The dropdown displays "Venue Name — City, ST · N fields" so directors can
 * eyeball which venue has enough fields before selecting it. Venues with 0
 * fields are still selectable but flagged in the UI.
 */
const VenuePicker: React.FC<Props> = ({ value, onChange, className, disabled }) => {
  const [venues, setVenues] = useState<VenueSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showManage, setShowManage] = useState(false);

  const fetchVenues = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/legacy/venues-gateway.php`, { headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` } });
      if (!res.ok) throw new Error('Failed to load venues');
      const data = await res.json();
      // The endpoint returns a bare array of venue rows.
      const list: VenueSummary[] = Array.isArray(data) ? data : [];
      setVenues(list);
    } catch (e: any) {
      setError(e.message || 'Failed to load venues');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchVenues();
  }, [fetchVenues]);

  // When the management modal closes, refetch venues. If a new venue was
  // created (max id > previous max id), auto-select it.
  const handleManageClose = async () => {
    setShowManage(false);
    const prevMaxId = venues.reduce((m, v) => Math.max(m, v.id), 0);
    try {
      const res = await fetch(`${API_URL}/legacy/venues-gateway.php`, { headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` } });
      const data = await res.json();
      const list: VenueSummary[] = Array.isArray(data) ? data : [];
      setVenues(list);
      const newest = list.reduce<VenueSummary | null>(
        (best, v) => (v.id > prevMaxId && (!best || v.id > best.id) ? v : best),
        null
      );
      if (newest) {
        onChange(newest.id, newest);
      }
    } catch {
      // non-fatal — list will refresh on next interaction
    }
  };

  const handleSelectChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const id = e.target.value === '' ? null : Number(e.target.value);
    const venue = id == null ? null : venues.find((v) => v.id === id) || null;
    onChange(id, venue);
  };

  const selected = value == null ? null : venues.find((v) => v.id === value) || null;

  return (
    <div className={className}>
      <div className="flex items-center justify-between mb-1">
        <label className="block text-sm font-medium text-gray-700">Venue</label>
        <button
          type="button"
          onClick={() => setShowManage(true)}
          disabled={disabled}
          className="text-xs font-medium text-brand-primary hover:underline disabled:opacity-50"
        >
          + Manage / create venues
        </button>
      </div>

      <select
        value={value ?? ''}
        onChange={handleSelectChange}
        disabled={disabled || loading}
        className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm bg-white"
      >
        <option value="">{loading ? 'Loading venues…' : '— Select a venue —'}</option>
        {venues.map((v) => {
          const where = [v.city, v.state].filter(Boolean).join(', ');
          const fields = typeof v.field_count === 'number'
            ? ` · ${v.field_count} field${v.field_count === 1 ? '' : 's'}`
            : '';
          const flag = (v.field_count ?? 0) === 0 ? ' ⚠ no fields' : '';
          return (
            <option key={v.id} value={v.id}>
              {v.name}
              {where ? ` — ${where}` : ''}
              {fields}
              {flag}
            </option>
          );
        })}
      </select>

      {selected && (selected.field_count ?? 0) === 0 && (
        <p className="text-xs text-amber-600 mt-1">
          This venue has no fields configured. Schedule generation will fail until fields are added — open Manage venues to add some.
        </p>
      )}

      {error && (
        <p className="text-xs text-red-600 mt-1">{error}</p>
      )}

      {showManage && (
        <div className="fixed inset-0 z-50 bg-black bg-opacity-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] overflow-y-auto">
            <VenueManagement onClose={handleManageClose} />
          </div>
        </div>
      )}
    </div>
  );
};

export default VenuePicker;

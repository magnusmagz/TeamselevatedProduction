import React from 'react';
import { Venue } from '../types';

interface VenuePickerProps {
  venues: Venue[];
  value?: number | null;
  onChange: (venueId: number | undefined) => void;
  allowNone?: boolean;
  className?: string;
  id?: string;
}

/**
 * A dropdown over the club's venues catalog. Reused for a program's headline
 * facility and for per-session facility overrides. Pure (no fetch) — the parent
 * loads the venues list once and passes it in.
 */
const VenuePicker: React.FC<VenuePickerProps> = ({
  venues,
  value,
  onChange,
  allowNone = true,
  className,
  id,
}) => (
  <select
    id={id}
    className={
      className ||
      'w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent'
    }
    value={value ?? ''}
    onChange={(e) => onChange(e.target.value ? parseInt(e.target.value, 10) : undefined)}
  >
    {allowNone && <option value="">— No facility —</option>}
    {venues.map((v) => (
      <option key={v.id} value={v.id}>
        {v.name}
        {v.city ? ` — ${v.city}${v.state ? ', ' + v.state : ''}` : ''}
      </option>
    ))}
  </select>
);

export default VenuePicker;

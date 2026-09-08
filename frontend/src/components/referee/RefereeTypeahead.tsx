import React, { useEffect, useRef, useState } from 'react';
import { RefereeSearchHit } from './refereeTypes';

/**
 * Typeahead over the club's referee directory (`api/referees.php?action=search`).
 *
 * Controlled text input: the parent owns the typed value, so a free-typed name
 * still works where one is allowed (the feedback modal). Picking a hit calls
 * onPick with the row; the parent decides whether to keep the text.
 *
 * The listbox rows are <li role="option">, not buttons — the button scan in
 * uiConsistency.test.ts covers components/, and a listbox row is a selection,
 * not a command.
 */
interface Props {
  apiUrl: string;
  clubId: number | null;
  value: string;
  onChange: (text: string) => void;
  onPick: (hit: RefereeSearchHit) => void;
  id?: string;
  placeholder?: string;
  /** Hits to leave out (already assigned). */
  excludeIds?: number[];
  disabled?: boolean;
  className?: string;
}

const RefereeTypeahead: React.FC<Props> = ({
  apiUrl, clubId, value, onChange, onPick, id = 'referee-typeahead', placeholder = 'Start typing a name…',
  excludeIds = [], disabled = false, className = '',
}) => {
  const [hits, setHits] = useState<RefereeSearchHit[]>([]);
  const [open, setOpen] = useState(false);
  const [unavailable, setUnavailable] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);
  const token = localStorage.getItem('auth_token');

  useEffect(() => {
    if (!open || clubId == null) return;
    let cancelled = false;
    const handle = setTimeout(async () => {
      try {
        const res = await fetch(
          `${apiUrl}/api/referees.php?action=search&club_id=${clubId}&q=${encodeURIComponent(value.trim())}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await res.json();
        if (cancelled) return;
        if (!res.ok || !data?.success) {
          setHits([]);
          return;
        }
        setUnavailable(data.available === false);
        setHits(Array.isArray(data.referees) ? data.referees : []);
      } catch {
        if (!cancelled) setHits([]);
      }
    }, 150);
    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [apiUrl, clubId, value, open, token]);

  useEffect(() => {
    const onDown = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  const visible = hits.filter((h) => !excludeIds.includes(h.id));

  return (
    <div ref={wrapRef} className={`relative ${className}`}>
      <input
        id={id}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${id}-listbox`}
        aria-autocomplete="list"
        value={value}
        disabled={disabled}
        placeholder={placeholder}
        autoComplete="off"
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          onChange(e.target.value);
          setOpen(true);
        }}
        onKeyDown={(e) => {
          if (e.key === 'Escape') setOpen(false);
        }}
        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-accent"
      />
      {open && clubId != null && (visible.length > 0 || unavailable) && (
        <ul
          id={`${id}-listbox`}
          role="listbox"
          className="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto bg-white border border-brand-secondary rounded-md shadow-lg"
        >
          {unavailable && (
            <li className="px-3 py-2 text-xs text-gray-500">The referee directory is not switched on for this club yet.</li>
          )}
          {visible.map((h) => (
            <li
              key={h.id}
              role="option"
              aria-selected={false}
              tabIndex={0}
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => {
                onPick(h);
                setOpen(false);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  onPick(h);
                  setOpen(false);
                }
              }}
              className="px-3 py-2 text-sm cursor-pointer hover:bg-brand-light/40 focus:bg-brand-light/40 focus:outline-none"
            >
              <span className="font-medium text-gray-900">{h.name}</span>
              {h.grade && <span className="ml-2 text-xs text-gray-500">{h.grade}</span>}
              {h.email && <span className="block text-xs text-gray-500">{h.email}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default RefereeTypeahead;

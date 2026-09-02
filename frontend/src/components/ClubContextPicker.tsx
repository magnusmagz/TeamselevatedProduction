import React, { useState, useRef, useEffect, useMemo } from 'react';
import { useOrg, contextLabel } from '../contexts/OrgContext';

/**
 * Switch the active club, for people who have more than one.
 *
 * Renders NOTHING for the overwhelming majority of users — one club, one
 * context, no control. It exists for the GOTR shape (a division or national
 * admin over many councils) and for the handful of staff who already sit in two
 * clubs today.
 *
 * One entry per CLUB, not per role. `switch-context` takes a scope_id, so two
 * roles in the same club are one switch target; listing them twice would offer
 * a choice that does nothing.
 *
 * ⚠️ The backend's access check for a switch reads `user_club_access` directly,
 * so a club a user reaches only through a DERIVED coach role (team membership,
 * no club-access row) answers 403. That path is in api/auth-gateway.php, which
 * is on the do-not-modify list, so the failure is surfaced here rather than
 * hidden: a switch that cannot happen says so instead of silently doing nothing.
 */
const ClubContextPicker: React.FC = () => {
  const { activeContext, availableContexts, switchToContext } = useOrg();
  const [isOpen, setIsOpen] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const ref = useRef<HTMLDivElement>(null);

  const clubs = useMemo(() => {
    const seen = new Map<number, { id: number; label: string }>();
    availableContexts
      .filter((c) => c.scope_type === 'club')
      .forEach((c) => {
        if (!seen.has(c.scope_id)) {
          seen.set(c.scope_id, { id: c.scope_id, label: contextLabel(c) });
        }
      });
    return Array.from(seen.values()).sort((a, b) => a.label.localeCompare(b.label));
  }, [availableContexts]);

  useEffect(() => {
    const onClickOutside = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };
    if (isOpen) document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, [isOpen]);

  // One club (or none) is not a choice.
  if (clubs.length < 2) {
    return null;
  }

  const handlePick = async (scopeId: number) => {
    if (scopeId === activeContext?.scope_id) {
      setIsOpen(false);
      return;
    }
    setBusyId(scopeId);
    setError(null);
    try {
      await switchToContext(scopeId, 'club');
      setIsOpen(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not switch club');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setIsOpen(!isOpen)}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-label="Switch club"
        className="max-w-[12rem] px-3 py-2 text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm flex items-center gap-1"
      >
        <span className="truncate">{contextLabel(activeContext) || 'Select club'}</span>
        <svg
          className={`w-4 h-4 shrink-0 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {isOpen && (
        <div
          role="listbox"
          aria-label="Clubs"
          className="absolute right-0 mt-2 w-64 max-h-80 overflow-y-auto bg-white border border-brand-secondary rounded-md shadow-lg z-50"
        >
          <div className="py-1">
            {clubs.map((club) => {
              const isActive = club.id === activeContext?.scope_id;
              return (
                <button
                  key={club.id}
                  type="button"
                  role="option"
                  aria-selected={isActive}
                  disabled={busyId !== null}
                  onClick={() => handlePick(club.id)}
                  className={`w-full text-left px-4 py-3 text-sm hover:bg-brand-secondary disabled:opacity-60 ${
                    isActive ? 'font-semibold text-brand-primary' : 'text-brand-primary'
                  }`}
                >
                  {club.label}
                  {busyId === club.id && <span className="ml-2 text-xs">Switching…</span>}
                </button>
              );
            })}
            {error && (
              <p role="alert" className="px-4 py-2 text-xs text-red-600">
                {error}
              </p>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default ClubContextPicker;

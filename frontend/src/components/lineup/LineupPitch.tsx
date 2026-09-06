import React from 'react';
import { FieldSize, FormationSlot, slotsFor } from '../../utils/lineupFormations';
import { PitchPlayer } from './types';

/**
 * The one pitch drawing. The coach screen, the print view and the crew view all
 * render this, so what a family sees is what the coach set — same SVG, same
 * coordinates (lib/lineup_formations.php ↔ utils/lineupFormations.ts).
 *
 * Interaction is the caller's: `onSlotTap` makes each slot a button; without it
 * the pitch is inert (print, crew).
 */

interface Props {
  fieldSize: FieldSize;
  formation: string;
  players: Record<string, PitchPlayer | undefined>;
  selectedSlot?: string | null;
  highlightAthleteIds?: number[];
  onSlotTap?: (slot: FormationSlot) => void;
  className?: string;
}

const H = 130; // viewBox height; y 0–100 from the preset maps to 8–122

const py = (y: number) => 8 + (y * 114) / 100;

const shortName = (p: PitchPlayer): string => {
  const last = p.last_name ?? p.name.split(' ').slice(-1)[0] ?? '';
  return last.length > 11 ? last.slice(0, 10) + '…' : last;
};

const LineupPitch: React.FC<Props> = ({
  fieldSize, formation, players, selectedSlot, highlightAthleteIds = [], onSlotTap, className,
}) => {
  const slots = slotsFor(fieldSize, formation) ?? [];
  const interactive = Boolean(onSlotTap);

  return (
    <svg
      viewBox={`0 0 100 ${H}`}
      className={className}
      role="img"
      aria-label={`${formation} on a ${fieldSize} pitch`}
      style={{ width: '100%', height: 'auto', display: 'block' }}
    >
      {/* grass */}
      <rect x="0" y="0" width="100" height={H} rx="2" fill="#2f8f4e" />
      {[0, 1, 2, 3, 4, 5].map((i) => (
        <rect key={i} x="0" y={i * (H / 6)} width="100" height={H / 12} fill="#2a844a" opacity="0.6" />
      ))}
      {/* lines */}
      <g fill="none" stroke="#ffffff" strokeWidth="0.8" opacity="0.9">
        <rect x="3" y="3" width="94" height={H - 6} />
        <line x1="3" y1={H / 2} x2="97" y2={H / 2} />
        <circle cx="50" cy={H / 2} r="9" />
        <rect x="22" y="3" width="56" height="18" />
        <rect x="36" y="3" width="28" height="7" />
        <rect x="22" y={H - 21} width="56" height="18" />
        <rect x="36" y={H - 10} width="28" height="7" />
      </g>

      {slots.map((s) => {
        const p = players[s.slot];
        const selected = selectedSlot === s.slot;
        const highlighted = p ? highlightAthleteIds.includes(p.athlete_id) : false;
        const unavailable = p && (p.attendance === 'absent' || p.attendance === 'excused');
        const flagged = p && (p.status === 'injured' || p.status === 'suspended');
        const fill = !p ? 'rgba(255,255,255,0.25)' : highlighted ? '#f59e0b' : unavailable ? '#9ca3af' : '#ffffff';
        const stroke = selected ? '#facc15' : flagged ? '#ef4444' : '#0f3d2a';
        const label = p
          ? `${s.label}: ${p.name}${p.jersey_number != null ? ` #${p.jersey_number}` : ''}`
          : `${s.label} slot, empty`;
        return (
          <g
            key={s.slot}
            transform={`translate(${s.x} ${py(s.y)})`}
            onClick={interactive ? () => onSlotTap?.(s) : undefined}
            role={interactive ? 'button' : undefined}
            tabIndex={interactive ? 0 : undefined}
            aria-label={label}
            data-slot={s.slot}
            data-selected={selected ? 'true' : undefined}
            onKeyDown={interactive ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSlotTap?.(s); } } : undefined}
            style={interactive ? { cursor: 'pointer' } : undefined}
          >
            <circle r="6.2" fill={fill} stroke={stroke} strokeWidth={selected ? 1.6 : 0.9} strokeDasharray={p ? undefined : '1.5 1'} />
            {p ? (
              <text y="1.8" textAnchor="middle" fontSize="5" fontWeight="700" fill="#0f3d2a">
                {p.jersey_number ?? '·'}
              </text>
            ) : (
              <text y="1.6" textAnchor="middle" fontSize="3.6" fontWeight="700" fill="#ffffff">
                {s.label}
              </text>
            )}
            {p && p.captain && (
              <text x="5.2" y="-4" textAnchor="middle" fontSize="3.2" fontWeight="700" fill="#facc15">C</text>
            )}
            <text
              y="10.5"
              textAnchor="middle"
              fontSize="3.8"
              fontWeight="600"
              fill="#ffffff"
              stroke="#0f3d2a"
              strokeWidth="0.35"
              paintOrder="stroke"
            >
              {p ? shortName(p) : ''}
            </text>
          </g>
        );
      })}
    </svg>
  );
};

export default LineupPitch;

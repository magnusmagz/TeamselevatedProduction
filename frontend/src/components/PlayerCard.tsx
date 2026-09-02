import React from 'react';
import { ageGroup, ageQuarter } from '../utils/ageGroup';

interface PlayerCardProps {
  player: {
    id: number;
    first_name: string;
    last_name: string;
    date_of_birth?: string;
    photo_url?: string;
    gender?: string;
  };
  team: {
    name: string;
    jersey_number?: number;
    primary_position?: string;
  };
  club: {
    name: string;
    logo_url?: string;
  };
  season?: string;
  size?: 'normal' | 'large';
}

function getUGroup(dob: string): string {
  return ageGroup(dob) ?? '';
}

function getAgeQuarter(dob: string): string {
  return ageQuarter(dob) ?? '';
}

function formatDOB(dob: string): string {
  const d = new Date(dob);
  return d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
}

function getCurrentSeason(): string {
  const now = new Date();
  const year = now.getFullYear();
  const month = now.getMonth(); // 0-indexed
  if (month >= 7) {
    return `${year}-${String(year + 1).slice(-2)}`;
  }
  return `${year - 1}-${String(year).slice(-2)}`;
}

function formatPlayerId(id: number): string {
  return `TE-${String(id).padStart(6, '0')}`;
}

const PlayerCard: React.FC<PlayerCardProps> = ({ player, team, club, season, size = 'normal' }) => {
  const initials = `${player.first_name[0] || ''}${player.last_name[0] || ''}`.toUpperCase();
  const displaySeason = season || getCurrentSeason();
  const isLarge = size === 'large';

  const cardWidth = isLarge ? '4.05in' : '3.375in';
  const cardHeight = isLarge ? '2.55in' : '2.125in';

  return (
    <div
      className="player-card bg-white border border-gray-300 rounded-lg overflow-hidden flex flex-col"
      style={{
        width: cardWidth,
        height: cardHeight,
        boxShadow: '0 1px 3px rgba(0,0,0,0.12)',
        fontSize: isLarge ? '0.8rem' : '0.65rem',
        pageBreakInside: 'avoid',
      }}
    >
      {/* Header Bar */}
      <div
        className="bg-brand-primary flex items-center gap-2 px-3"
        style={{ minHeight: isLarge ? '2.2rem' : '1.8rem' }}
      >
        {club.logo_url ? (
          <img
            src={club.logo_url}
            alt={club.name}
            className="rounded-full bg-white object-cover flex-shrink-0"
            style={{ width: isLarge ? '1.5rem' : '1.2rem', height: isLarge ? '1.5rem' : '1.2rem' }}
          />
        ) : (
          <div
            className="rounded-full bg-brand-secondary flex items-center justify-center text-brand-primary font-bold flex-shrink-0"
            style={{ width: isLarge ? '1.5rem' : '1.2rem', height: isLarge ? '1.5rem' : '1.2rem', fontSize: isLarge ? '0.55rem' : '0.45rem' }}
          >
            {club.name.charAt(0)}
          </div>
        )}
        {team.jersey_number && (
          <span className="text-white font-bold" style={{ fontSize: isLarge ? '0.9rem' : '0.75rem' }}>
            #{team.jersey_number}
          </span>
        )}
        <div className="flex flex-col leading-tight overflow-hidden">
          <span className="text-white font-bold uppercase tracking-wide truncate" style={{ fontSize: isLarge ? '0.8rem' : '0.65rem' }}>
            {player.first_name} {player.last_name}
          </span>
          <span className="text-brand-secondary truncate" style={{ fontSize: isLarge ? '0.6rem' : '0.5rem' }}>
            {team.name}
          </span>
        </div>
      </div>

      {/* Body */}
      <div className="flex flex-1 px-3 py-2 gap-3">
        {/* Photo */}
        <div className="flex-shrink-0">
          {player.photo_url ? (
            <img
              src={player.photo_url}
              alt={`${player.first_name} ${player.last_name}`}
              className="rounded border border-gray-200 object-cover"
              style={{ width: isLarge ? '5rem' : '4.2rem', height: isLarge ? '5rem' : '4.2rem' }}
            />
          ) : (
            <div
              className="rounded border border-gray-200 bg-brand-secondary flex items-center justify-center text-brand-primary font-bold"
              style={{
                width: isLarge ? '5rem' : '4.2rem',
                height: isLarge ? '5rem' : '4.2rem',
                fontSize: isLarge ? '1.2rem' : '1rem',
              }}
            >
              {initials}
            </div>
          )}
        </div>

        {/* Info */}
        <div className="flex flex-col justify-between flex-1 min-w-0 leading-snug">
          <div className="space-y-0.5">
            <div className="truncate">
              <span className="text-gray-500 font-medium">Name: </span>
              <span className="text-brand-primary font-semibold">{player.first_name} {player.last_name}</span>
            </div>
            {player.date_of_birth && (
              <div>
                <span className="text-gray-500 font-medium">DOB: </span>
                <span className="text-brand-primary">{formatDOB(player.date_of_birth)}</span>
              </div>
            )}
            {player.date_of_birth && (
              <div>
                <span className="text-gray-500 font-medium">Age Group: </span>
                <span className="text-brand-primary font-semibold">{getUGroup(player.date_of_birth)}</span>
                <span className="text-gray-400 ml-1">({getAgeQuarter(player.date_of_birth)})</span>
              </div>
            )}
            <div className="truncate">
              <span className="text-gray-500 font-medium">Club: </span>
              <span className="text-brand-primary">{club.name}</span>
            </div>
            <div className="truncate">
              <span className="text-gray-500 font-medium">Team: </span>
              <span className="text-brand-primary">{team.name}</span>
            </div>
            {team.primary_position && (
              <div>
                <span className="text-gray-500 font-medium">Position: </span>
                <span className="text-brand-primary">{team.primary_position}</span>
              </div>
            )}
          </div>

          {/* Footer area */}
          <div className="flex justify-between items-end mt-1">
            <div>
              <div className="uppercase font-bold text-brand-primary tracking-wider" style={{ fontSize: isLarge ? '0.55rem' : '0.45rem' }}>
                Member Pass
              </div>
              <div className="text-gray-400" style={{ fontSize: isLarge ? '0.5rem' : '0.4rem' }}>
                Season {displaySeason}
              </div>
            </div>
            <div className="text-brand-primary font-mono font-semibold" style={{ fontSize: isLarge ? '0.6rem' : '0.5rem' }}>
              {formatPlayerId(player.id)}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PlayerCard;

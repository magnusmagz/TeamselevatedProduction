import React from 'react';

interface Props {
  firstName: string;
  lastName: string;
  dateOfBirth: string | null;
  age: number | null;
  jerseyNumber: string | null;
  position: string | null;
  photoUrl: string | null;
  teamName?: string;
  checkedIn?: boolean;
}

const PlayerCard: React.FC<Props> = ({
  firstName, lastName, dateOfBirth, age, jerseyNumber, position, photoUrl, teamName, checkedIn,
}) => {
  return (
    <div className={`flex items-center space-x-3 p-3 rounded-lg border ${
      checkedIn ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-white'
    }`}>
      {/* Photo */}
      <div className="w-12 h-12 rounded-full bg-gray-200 flex-shrink-0 overflow-hidden">
        {photoUrl ? (
          <img src={photoUrl} alt={`${firstName} ${lastName}`} className="w-full h-full object-cover" />
        ) : (
          <svg className="w-full h-full text-gray-400 p-2" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
          </svg>
        )}
      </div>

      {/* Info */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center space-x-2">
          {jerseyNumber && (
            <span className="text-xs font-bold text-gray-400">#{jerseyNumber}</span>
          )}
          <span className="font-medium text-gray-900 truncate">
            {firstName} {lastName}
          </span>
        </div>
        <div className="text-xs text-gray-500 space-x-2">
          {position && <span>{position}</span>}
          {age !== null && <span>Age {age}</span>}
          {dateOfBirth && <span>DOB: {new Date(dateOfBirth).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>}
        </div>
        {teamName && <div className="text-xs text-gray-400">{teamName}</div>}
      </div>

      {/* Check-in indicator */}
      {checkedIn !== undefined && (
        <div className="flex-shrink-0">
          {checkedIn ? (
            <svg className="w-6 h-6 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
            </svg>
          ) : (
            <svg className="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10" strokeWidth="2" />
            </svg>
          )}
        </div>
      )}
    </div>
  );
};

export default PlayerCard;

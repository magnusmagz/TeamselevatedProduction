import React, { useState, useRef, useEffect } from 'react';

interface Athlete {
  id: number;
  first_name: string;
  last_name: string;
  profile_image_url?: string;
}

interface AthleteSelectorProps {
  athletes: Athlete[];
  selectedAthleteId: number | null;
  onSelect: (id: number | null) => void;
  showAllOption?: boolean;
}

export const AthleteSelector: React.FC<AthleteSelectorProps> = ({
  athletes,
  selectedAthleteId,
  onSelect,
  showAllOption = true,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const selectedAthlete = athletes.find((a) => a.id === selectedAthleteId);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleSelect = (id: number | null) => {
    onSelect(id);
    setIsOpen(false);
  };

  const getInitials = (firstName: string, lastName: string) => {
    return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
  };

  if (athletes.length === 0) {
    return null;
  }

  // If only one athlete, show as static (no dropdown)
  if (athletes.length === 1 && !showAllOption) {
    const athlete = athletes[0];
    return (
      <div className="flex items-center gap-2 px-3 py-2 bg-gray-100 rounded-lg">
        {athlete.profile_image_url ? (
          <img
            src={athlete.profile_image_url}
            alt={`${athlete.first_name} ${athlete.last_name}`}
            className="w-8 h-8 rounded-full object-cover"
          />
        ) : (
          <div className="w-8 h-8 rounded-full bg-brand-primary text-white flex items-center justify-center text-sm font-medium">
            {getInitials(athlete.first_name, athlete.last_name)}
          </div>
        )}
        <span className="font-medium text-gray-900">
          {athlete.first_name} {athlete.last_name}
        </span>
      </div>
    );
  }

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="flex items-center gap-2 px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors min-w-[160px]"
      >
        {selectedAthlete ? (
          <>
            {selectedAthlete.profile_image_url ? (
              <img
                src={selectedAthlete.profile_image_url}
                alt={`${selectedAthlete.first_name} ${selectedAthlete.last_name}`}
                className="w-6 h-6 rounded-full object-cover"
              />
            ) : (
              <div className="w-6 h-6 rounded-full bg-brand-primary text-white flex items-center justify-center text-xs font-medium">
                {getInitials(selectedAthlete.first_name, selectedAthlete.last_name)}
              </div>
            )}
            <span className="font-medium text-gray-900 truncate">
              {selectedAthlete.first_name}
            </span>
          </>
        ) : (
          <span className="text-gray-700">All Athletes</span>
        )}
        <svg
          className={`w-4 h-4 ml-auto text-gray-500 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-50 max-h-60 overflow-auto">
          {showAllOption && (
            <button
              onClick={() => handleSelect(null)}
              className={`w-full px-3 py-2 text-left hover:bg-gray-50 flex items-center gap-2 ${
                selectedAthleteId === null ? 'bg-brand-secondary' : ''
              }`}
            >
              <div className="w-6 h-6 rounded-full bg-gray-300 flex items-center justify-center">
                <svg className="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              </div>
              <span className="text-gray-700">All Athletes</span>
            </button>
          )}
          {athletes.map((athlete) => (
            <button
              key={athlete.id}
              onClick={() => handleSelect(athlete.id)}
              className={`w-full px-3 py-2 text-left hover:bg-gray-50 flex items-center gap-2 ${
                selectedAthleteId === athlete.id ? 'bg-brand-secondary' : ''
              }`}
            >
              {athlete.profile_image_url ? (
                <img
                  src={athlete.profile_image_url}
                  alt={`${athlete.first_name} ${athlete.last_name}`}
                  className="w-6 h-6 rounded-full object-cover"
                />
              ) : (
                <div className="w-6 h-6 rounded-full bg-brand-primary text-white flex items-center justify-center text-xs font-medium">
                  {getInitials(athlete.first_name, athlete.last_name)}
                </div>
              )}
              <span className="text-gray-900">
                {athlete.first_name} {athlete.last_name}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

export default AthleteSelector;

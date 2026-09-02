import React from 'react';
import { Link } from 'react-router-dom';
import { useParentAthletes } from '../hooks/useParentAthletes';
import { ParentHeader } from '../components/ParentHeader';
import { NoAthletesLinked } from '../components/NoAthletesLinked';

export const MyAthletesPage: React.FC = () => {
  const { athletes, loading, error } = useParentAthletes();

  const getInitials = (firstName: string, lastName: string) => {
    return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title="My Athletes" showBack />

      <div className="pt-14 px-4 pb-4">
        {loading && (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
          </div>
        )}

        {error && (
          <div className="bg-red-50 text-red-700 px-4 py-3 rounded-lg mt-4">
            {error}
          </div>
        )}

        {!loading && !error && athletes.length === 0 && <NoAthletesLinked />}

        {!loading && !error && athletes.length > 0 && (
          <div className="space-y-3 mt-4">
            {athletes.map((athlete) => (
              <Link
                key={athlete.id}
                to={`/parent/athlete/${athlete.id}`}
                className="block bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:bg-gray-50 transition-colors"
              >
                <div className="flex items-center gap-4">
                  {athlete.profile_image_url ? (
                    <img
                      src={athlete.profile_image_url}
                      alt={`${athlete.first_name} ${athlete.last_name}`}
                      className="w-16 h-16 rounded-full object-cover"
                    />
                  ) : (
                    <div className="w-16 h-16 rounded-full bg-brand-primary text-white flex items-center justify-center text-xl font-medium">
                      {getInitials(athlete.first_name, athlete.last_name)}
                    </div>
                  )}
                  <div className="flex-1 min-w-0">
                    <h3 className="font-semibold text-brand-primary text-lg">
                      {athlete.first_name} {athlete.last_name}
                    </h3>
                    {athlete.teams && athlete.teams.length > 0 && (
                      <div className="flex flex-wrap gap-1 mt-1">
                        {athlete.teams.map((team) => (
                          <span
                            key={team.id}
                            className="inline-block px-2 py-0.5 bg-brand-secondary text-brand-primary text-xs rounded"
                          >
                            {team.name}
                          </span>
                        ))}
                      </div>
                    )}
                    {athlete.date_of_birth && (
                      <p className="text-sm text-gray-500 mt-1">
                        DOB: {new Date(athlete.date_of_birth).toLocaleDateString()}
                      </p>
                    )}
                  </div>
                  <svg
                    className="w-5 h-5 text-gray-400 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M9 5l7 7-7 7"
                    />
                  </svg>
                </div>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

export default MyAthletesPage;

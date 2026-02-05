import React from 'react';
import { Link } from 'react-router-dom';
import { useParentAthletes } from '../hooks/useParentAthletes';
import { ParentHeader } from '../components/ParentHeader';

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

        {!loading && !error && athletes.length === 0 && (
          <div className="text-center py-12">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-gray-900">No Athletes</h3>
            <p className="mt-1 text-sm text-gray-500">
              No athletes are linked to your account.
            </p>
          </div>
        )}

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
                    <h3 className="font-semibold text-gray-900 text-lg">
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

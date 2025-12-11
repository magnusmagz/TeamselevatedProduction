import React, { useState } from 'react';
import { useOrg } from '../contexts/OrgContext';
import LeagueInfoForm from '../components/LeagueInfoForm';
import LeagueUserManagement from '../components/LeagueUserManagement';
import LeagueDocuments from '../components/LeagueDocuments';

const LeagueSettings: React.FC = () => {
  const { activeContext, isLeagueAdmin, currentLeagueId } = useOrg();
  const [activeTab, setActiveTab] = useState<'info' | 'users' | 'documents'>('info');

  // Only league admins can access this page
  if (!isLeagueAdmin || !currentLeagueId) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4">
          <div className="flex">
            <div className="flex-shrink-0">
              <svg className="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
              </svg>
            </div>
            <div className="ml-3">
              <p className="text-sm text-yellow-700">
                You must be a league administrator to access this page.
              </p>
            </div>
          </div>
        </div>
      </main>
    );
  }

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-forest-800">League Settings</h1>
        <p className="text-gray-600 mt-1">
          Manage your league information and user access
        </p>
      </div>

      {/* Tab Navigation */}
      <div className="border-b border-gray-200 mb-6">
        <nav className="-mb-px flex space-x-8">
          <button
            onClick={() => setActiveTab('info')}
            className={`
              py-4 px-1 border-b-2 font-medium text-sm uppercase
              ${activeTab === 'info'
                ? 'border-forest-600 text-forest-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }
            `}
          >
            League Information
          </button>
          <button
            onClick={() => setActiveTab('users')}
            className={`
              py-4 px-1 border-b-2 font-medium text-sm uppercase
              ${activeTab === 'users'
                ? 'border-forest-600 text-forest-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }
            `}
          >
            User Management
          </button>
          <button
            onClick={() => setActiveTab('documents')}
            className={`
              py-4 px-1 border-b-2 font-medium text-sm uppercase
              ${activeTab === 'documents'
                ? 'border-forest-600 text-forest-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }
            `}
          >
            Document Hub
          </button>
        </nav>
      </div>

      {/* Tab Content */}
      <div>
        {activeTab === 'info' && (
          <LeagueInfoForm leagueId={currentLeagueId} />
        )}
        {activeTab === 'users' && (
          <LeagueUserManagement leagueId={currentLeagueId} />
        )}
        {activeTab === 'documents' && (
          <LeagueDocuments leagueId={currentLeagueId} />
        )}
      </div>
    </main>
  );
};

export default LeagueSettings;

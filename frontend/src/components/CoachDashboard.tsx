import React, { useState, useEffect } from 'react';
import RosterManagement from './RosterManagement';
import AttendanceTracker from './AttendanceTracker';
import PracticeScheduler from './PracticeScheduler';

interface CoachTeam {
  id: number;
  name: string;
  age_group: string;
  division: string;
  season_name: string;
  player_count: number;
  guest_count: number;
  coach_role: string;
  next_event: string | null;
}

const CoachDashboard: React.FC = () => {
  const [teams, setTeams] = useState<CoachTeam[]>([]);
  const [selectedTeam, setSelectedTeam] = useState<CoachTeam | null>(null);
  const [activeTab, setActiveTab] = useState<'teams' | 'roster' | 'attendance' | 'schedule'>('teams');
  const [showPracticeScheduler, setShowPracticeScheduler] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchCoachTeams();
  }, []);

  const fetchCoachTeams = async () => {
    try {
      const response = await fetch('http://localhost:8888/teamselevated-backend/api/coach/teams');
      const data = await response.json();
      setTeams(data);
    } catch (error) {
      console.error('Error fetching coach teams:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleTeamSelect = (team: CoachTeam) => {
    setSelectedTeam(team);
    setActiveTab('roster');
  };

  return (
    <div className="min-h-screen bg-white">
      <nav className="bg-white border-b-2 border-forest-800">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between h-16">
            <div className="flex items-center space-x-8">
              <h1 className="text-2xl font-bold text-forest-800 uppercase tracking-wide">COACH DASHBOARD</h1>
              <div className="flex space-x-4">
                <button
                  onClick={() => setActiveTab('teams')}
                  className={`px-4 py-2 border-2 uppercase ${
                    activeTab === 'teams'
                      ? 'bg-forest-800 text-white border-forest-800'
                      : 'text-forest-800 hover:bg-gray-100 border-forest-800'
                  }`}
                >
                  My Teams
                </button>
                {selectedTeam && (
                  <>
                    <button
                      onClick={() => setActiveTab('roster')}
                      className={`px-4 py-2 border-2 uppercase ${
                        activeTab === 'roster'
                          ? 'bg-forest-800 text-white border-forest-800'
                          : 'text-forest-800 hover:bg-gray-100 border-forest-800'
                      }`}
                    >
                      Roster
                    </button>
                    <button
                      onClick={() => setActiveTab('attendance')}
                      className={`px-4 py-2 border-2 uppercase ${
                        activeTab === 'attendance'
                          ? 'bg-forest-800 text-white border-forest-800'
                          : 'text-forest-800 hover:bg-gray-100 border-forest-800'
                      }`}
                    >
                      Attendance
                    </button>
                    <button
                      onClick={() => setActiveTab('schedule')}
                      className={`px-4 py-2 border-2 uppercase ${
                        activeTab === 'schedule'
                          ? 'bg-forest-800 text-white border-forest-800'
                          : 'text-forest-800 hover:bg-gray-100 border-forest-800'
                      }`}
                    >
                      Schedule
                    </button>
                  </>
                )}
              </div>
            </div>
            <div className="flex items-center">
              <span className="text-forest-800 uppercase">Coach View</span>
            </div>
          </div>
        </div>
      </nav>

      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {activeTab === 'teams' && (
          <div>
            <h2 className="text-3xl font-bold text-forest-800 mb-6 uppercase tracking-wide">My Teams</h2>

            {loading ? (
              <div className="text-center text-forest-800 py-12">Loading teams...</div>
            ) : teams.length === 0 ? (
              <div className="border-2 border-forest-800 p-12 text-center bg-white">
                <p className="text-gray-600 text-lg">No teams assigned yet.</p>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {teams.map((team) => (
                  <div
                    key={team.id}
                    className="bg-white border-2 border-forest-800 p-6 hover:bg-gray-50 cursor-pointer transition-colors"
                    onClick={() => handleTeamSelect(team)}
                  >
                    <div className="flex justify-between items-start mb-4">
                      <h3 className="text-xl font-bold text-forest-800">{team.name}</h3>
                      <span className="px-2 py-1 border border-forest-800 text-forest-800 text-xs uppercase">
                        {team.coach_role}
                      </span>
                    </div>
                    <div className="space-y-2">
                      <div className="flex justify-between text-gray-600">
                        <span>Age Group:</span>
                        <span className="text-forest-800 font-medium">{team.age_group}</span>
                      </div>
                      <div className="flex justify-between text-gray-600">
                        <span>Division:</span>
                        <span className="text-forest-800 font-medium">{team.division}</span>
                      </div>
                      <div className="flex justify-between text-gray-600">
                        <span>Players:</span>
                        <span className="text-forest-800 font-medium">
                          {team.player_count}
                          {team.guest_count > 0 && (
                            <span className="text-gray-500 ml-1">(+{team.guest_count} guests)</span>
                          )}
                        </span>
                      </div>
                      {team.next_event && (
                        <div className="mt-4 pt-4 border-t border-gray-300">
                          <p className="text-gray-600 text-sm">Next Event:</p>
                          <p className="text-forest-800 text-sm font-medium">
                            {new Date(team.next_event).toLocaleString()}
                          </p>
                        </div>
                      )}
                    </div>
                    <button className="w-full mt-4 bg-forest-800 text-white border-2 border-forest-800 py-2 hover:bg-forest-700 uppercase">
                      Manage Roster →
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {activeTab === 'roster' && selectedTeam && (
          <RosterManagement team={selectedTeam} />
        )}

        {activeTab === 'attendance' && selectedTeam && (
          <AttendanceTracker team={selectedTeam} />
        )}

        {activeTab === 'schedule' && selectedTeam && (
          <div>
            <div className="mb-6 flex justify-between items-center">
              <div>
                <h2 className="text-3xl font-bold text-forest-800 uppercase tracking-wide">
                  {selectedTeam.name} Schedule
                </h2>
                <p className="text-gray-600 mt-2">Manage practices, games, and events</p>
              </div>
              <button
                onClick={() => setShowPracticeScheduler(true)}
                className="bg-forest-800 text-white border-2 border-forest-800 px-4 py-2 hover:bg-forest-700 uppercase"
              >
                + Schedule Practices
              </button>
            </div>
            <div className="border-2 border-forest-800 p-8 text-center">
              <p className="text-gray-600">Calendar view coming soon...</p>
              <p className="text-gray-500 mt-2">Use "Schedule Practices" to bulk create practice sessions</p>
            </div>
          </div>
        )}

        {showPracticeScheduler && selectedTeam && (
          <PracticeScheduler
            team={selectedTeam}
            onClose={() => setShowPracticeScheduler(false)}
          />
        )}
      </main>
    </div>
  );
};

export default CoachDashboard;
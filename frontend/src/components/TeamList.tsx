import React, { useState } from 'react';
import PracticeScheduler from './PracticeScheduler';
import SmartScheduler from './SmartScheduler';

interface Team {
  id: number;
  name: string;
  age_group: string;
  division: string;
  season_name: string;
  coach_name: string;
  player_count: number;
  home_field_name: string;
}

interface TeamListProps {
  teams: Team[];
  onEdit: (team: Team) => void;
  onDelete: (teamId: number) => void;
}

const TeamList: React.FC<TeamListProps> = ({ teams, onEdit, onDelete }) => {
  const [showScheduler, setShowScheduler] = useState(false);
  const [showSmartScheduler, setShowSmartScheduler] = useState(false);
  const [selectedTeamForSchedule, setSelectedTeamForSchedule] = useState<Team | null>(null);

  const handleSchedulePractice = (team: Team) => {
    setSelectedTeamForSchedule(team);
    setShowScheduler(true);
  };

  const handleSmartSchedule = (team: Team) => {
    setSelectedTeamForSchedule(team);
    setShowSmartScheduler(true);
  };
  if (teams.length === 0) {
    return (
      <div className="border-2 border-forest-800 p-12 text-center bg-white">
        <p className="text-gray-600 text-lg">No teams found. Create your first team to get started.</p>
      </div>
    );
  }

  return (
    <div className="border-2 border-forest-800 overflow-hidden bg-white">
      <table className="min-w-full border-collapse">
        <thead>
          <tr className="border-b-2 border-forest-800 bg-white">
            <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase tracking-wider border-r border-gray-300">
              Team Name
            </th>
            <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase tracking-wider border-r border-gray-300">
              Age Group
            </th>
            <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase tracking-wider border-r border-gray-300">
              Division
            </th>
            <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase tracking-wider border-r border-gray-300">
              Head Coach
            </th>
            <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase tracking-wider border-r border-gray-300">
              Players
            </th>
            <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase tracking-wider border-r border-gray-300">
              Home Field
            </th>
            <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase tracking-wider">
              Actions
            </th>
          </tr>
        </thead>
        <tbody>
          {teams.map((team, index) => (
            <tr
              key={team.id}
              className="border-b border-gray-300 hover:bg-gray-50"
            >
              <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                <button
                  onClick={() => onEdit(team)}
                  className="text-sm font-medium text-forest-800 hover:underline text-left"
                >
                  {team.name}
                </button>
              </td>
              <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                <span className="px-2 py-1 text-xs text-forest-800 border border-forest-800">
                  {team.age_group}
                </span>
              </td>
              <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                <span className="px-2 py-1 text-xs text-forest-800 border border-forest-800">
                  {team.division}
                </span>
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-forest-800 border-r border-gray-300">
                {team.coach_name || 'Unassigned'}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-forest-800 border-r border-gray-300">
                {team.player_count}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-forest-800 border-r border-gray-300">
                {team.home_field_name || 'Not set'}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                <button
                  onClick={() => onEdit(team)}
                  className="text-forest-800 hover:underline mr-3 uppercase text-xs">
                  Edit
                </button>
                <button
                  onClick={() => window.location.href = `/teams/${team.id}/roster`}
                  className="text-forest-800 hover:underline mr-3 uppercase text-xs">
                  Roster
                </button>
                <button
                  onClick={() => handleSchedulePractice(team)}
                  className="text-forest-800 hover:underline mr-3 uppercase text-xs">
                  Schedule
                </button>
                <button
                  onClick={() => handleSmartSchedule(team)}
                  className="text-blue-600 hover:underline mr-3 uppercase text-xs font-bold">
                  Smart 🆕
                </button>
                <button
                  onClick={() => onDelete(team.id)}
                  className="text-forest-800 hover:underline uppercase text-xs"
                >
                  Archive
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {showScheduler && selectedTeamForSchedule && (
        <PracticeScheduler
          team={selectedTeamForSchedule}
          onClose={() => {
            setShowScheduler(false);
            setSelectedTeamForSchedule(null);
          }}
        />
      )}

      {showSmartScheduler && selectedTeamForSchedule && (
        <SmartScheduler
          team={selectedTeamForSchedule}
          onClose={() => {
            setShowSmartScheduler(false);
            setSelectedTeamForSchedule(null);
          }}
        />
      )}
    </div>
  );
};

export default TeamList;
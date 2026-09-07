import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import PracticeScheduler from './PracticeScheduler';
import { teamGenderLabel } from '../utils/teamGender';
import SmartScheduler from './SmartScheduler';
import DataTable, { DataTableColumn } from './ui/DataTable';
import Button from './ui/Button';

interface Team {
  id: number;
  name: string;
  age_group: string;
  gender?: string | null;
  division: string;
  season_name: string;
  coach_name: string;
  primary_coach_id: number | null;
  player_count: number;
  home_field_name: string;
}

interface TeamListProps {
  teams: Team[];
  onEdit: (team: Team) => void;
  onDelete: (teamId: number) => void;
  // Hide the archive/delete button when the viewer can't perform that
  // action — keeps the coach experience clean (backend rejects anyway).
  canDelete?: boolean;
}

const TeamList: React.FC<TeamListProps> = ({ teams, onEdit, onDelete, canDelete = true }) => {
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
  const columns: DataTableColumn<Team>[] = [
    {
      key: 'name',
      header: 'Team Name',
      className: 'whitespace-nowrap',
      render: (team) => (
        <Link
          to={`/team/${team.id}`}
          className="text-sm font-medium text-brand-primary hover:underline text-left"
        >
          {team.name}
        </Link>
      ),
    },
    {
      key: 'age_group',
      header: 'Age Group',
      className: 'whitespace-nowrap',
      render: (team) => (
        <>
          <span className="px-2 py-1 text-xs text-brand-primary border border-brand-secondary rounded-md">
            {team.age_group}
          </span>
          {team.gender && (
            <span className="ml-1 px-2 py-1 text-xs text-gray-600 bg-gray-100 rounded-md">
              {teamGenderLabel(team.gender)}
            </span>
          )}
        </>
      ),
    },
    {
      key: 'division',
      header: 'Division',
      className: 'whitespace-nowrap',
      render: (team) => (
        <span className="px-2 py-1 text-xs text-brand-primary border border-brand-secondary rounded-md">
          {team.division}
        </span>
      ),
    },
    {
      key: 'coach',
      header: 'Head Coach',
      className: 'whitespace-nowrap text-brand-primary',
      render: (team) => (
        <div>
          {team.primary_coach_id ? (
            <Link
              to={`/coach/${team.primary_coach_id}`}
              className="text-sm font-medium text-brand-primary hover:text-brand-primary-hover hover:underline"
            >
              {team.coach_name}
            </Link>
          ) : (
            <span className="text-gray-500">Unassigned</span>
          )}
        </div>
      ),
    },
    {
      key: 'player_count',
      header: 'Players',
      className: 'whitespace-nowrap text-brand-primary',
      render: (team) => team.player_count,
    },
    {
      key: 'home_field_name',
      header: 'Home Field',
      className: 'whitespace-nowrap text-brand-primary',
      render: (team) => team.home_field_name || 'Not set',
    },
    {
      key: 'actions',
      header: 'Actions',
      actions: true,
      align: 'left',
      className: 'font-medium',
      render: (team) => (
        <>
          <Button variant="link" size="sm" onClick={() => onEdit(team)} className="mr-3">
            Edit
          </Button>
          <Button variant="link" size="sm" onClick={() => window.location.href = `/teams/${team.id}/roster`} className="mr-3">
            Roster
          </Button>
          <Button variant="link" size="sm" onClick={() => handleSchedulePractice(team)} className="mr-3">
            Schedule
          </Button>
          <Button variant="link" size="sm" onClick={() => handleSmartSchedule(team)} className="mr-3">
            Smart
          </Button>
          {canDelete && (
            <Button variant="link" size="sm" onClick={() => onDelete(team.id)}>
              Archive
            </Button>
          )}
        </>
      ),
    },
  ];

  return (
    <div>
      <DataTable<Team>
        columns={columns}
        rows={teams}
        rowKey={(team) => team.id}
        emptyState="No teams found. Create your first team to get started."
      />

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
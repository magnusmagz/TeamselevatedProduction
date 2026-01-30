import React from 'react';
import TeamCalendarView from './TeamCalendarView';

/**
 * Club Calendar - Shows all teams' events and practices
 * This is a wrapper around TeamCalendarView with default settings for club-wide view
 */
const TeamCalendar: React.FC = () => {
  return (
    <TeamCalendarView
      title="Club Calendar"
      showTeamFilter={true}
      showAddEvent={true}
      readOnly={false}
    />
  );
};

export default TeamCalendar;

import React from 'react';

interface Stats {
  total_clubs: number;
  total_users: number;
  total_teams: number;
  total_athletes: number;
  super_admins: number;
  club_admins: number;
  coaches: number;
}

interface PlatformStatsProps {
  stats: Stats | null;
  loading: boolean;
}

const PlatformStats: React.FC<PlatformStatsProps> = ({ stats, loading }) => {
  if (loading) {
    return (
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[...Array(8)].map((_, i) => (
          <div key={i} className="bg-white border border-brand-secondary rounded-lg p-4 animate-pulse">
            <div className="h-4 bg-gray-200 rounded w-20 mb-2"></div>
            <div className="h-8 bg-gray-200 rounded w-16"></div>
          </div>
        ))}
      </div>
    );
  }

  if (!stats) {
    return <div className="text-gray-500">Unable to load statistics</div>;
  }

  const statCards = [
    { label: 'Total Clubs', value: stats.total_clubs, color: 'text-blue-600' },
    { label: 'Total Users', value: stats.total_users, color: 'text-green-600' },
    { label: 'Total Teams', value: stats.total_teams, color: 'text-purple-600' },
    { label: 'Total Athletes', value: stats.total_athletes, color: 'text-orange-600' },
    { label: 'Super Admins', value: stats.super_admins, color: 'text-red-600' },
    { label: 'Club Admins', value: stats.club_admins, color: 'text-indigo-600' },
    { label: 'Coaches', value: stats.coaches, color: 'text-teal-600' },
  ];

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      {statCards.map((stat, index) => (
        <div
          key={index}
          className="bg-white border border-brand-secondary rounded-lg p-4 hover:shadow-md transition-shadow"
        >
          <div className="text-sm text-gray-500 uppercase tracking-wide">{stat.label}</div>
          <div className={`text-3xl font-bold ${stat.color}`}>
            {stat.value.toLocaleString()}
          </div>
        </div>
      ))}
    </div>
  );
};

export default PlatformStats;

import React from 'react';

const roleColors: Record<string, string> = {
  admin: 'bg-purple-100 text-purple-700 border-purple-200',
  coach: 'bg-blue-100 text-blue-700 border-blue-200',
  parent: 'bg-green-100 text-green-700 border-green-200',
};

const roleLabels: Record<string, string> = {
  admin: 'Admin',
  coach: 'Coach',
  parent: 'Parent',
};

interface Props {
  role: string;
  size?: 'sm' | 'md';
}

const HelpRoleBadge: React.FC<Props> = ({ role, size = 'sm' }) => {
  const colors = roleColors[role] || 'bg-gray-100 text-gray-700 border-gray-200';
  const label = roleLabels[role] || role;
  const sizeClasses = size === 'sm' ? 'text-xs px-1.5 py-0.5' : 'text-sm px-2 py-0.5';

  return (
    <span className={`inline-flex items-center font-medium rounded-full border ${colors} ${sizeClasses}`}>
      {label}
    </span>
  );
};

export default HelpRoleBadge;

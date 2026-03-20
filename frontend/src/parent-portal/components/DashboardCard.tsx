import React from 'react';
import { Link } from 'react-router-dom';

interface DashboardCardProps {
  title: string;
  icon?: React.ReactNode;
  to?: string;
  onClick?: () => void;
  children: React.ReactNode;
  className?: string;
  headerAction?: React.ReactNode;
}

export const DashboardCard: React.FC<DashboardCardProps> = ({
  title,
  icon,
  to,
  onClick,
  children,
  className = '',
  headerAction,
}) => {
  const CardWrapper = to ? Link : onClick ? 'button' : 'div';
  const wrapperProps = to
    ? { to }
    : onClick
    ? { onClick, type: 'button' as const }
    : {};

  return (
    <div className={`bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden ${className}`}>
      <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <div className="flex items-center gap-2">
          {icon && <span className="text-brand-primary">{icon}</span>}
          <h3 className="font-semibold text-brand-primary">{title}</h3>
        </div>
        {headerAction}
      </div>
      <div className="p-4">{children}</div>
    </div>
  );
};

interface QuickActionProps {
  icon: React.ReactNode;
  label: string;
  to: string;
  variant?: 'primary' | 'secondary';
}

export const QuickAction: React.FC<QuickActionProps> = ({
  icon,
  label,
  to,
  variant = 'secondary',
}) => {
  return (
    <Link
      to={to}
      className={`flex flex-col items-center justify-center p-4 rounded-lg transition-colors min-h-[80px] ${
        variant === 'primary'
          ? 'bg-brand-primary text-white hover:bg-brand-primary-hover'
          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
      }`}
    >
      <span className="mb-1">{icon}</span>
      <span className="text-sm font-medium text-center">{label}</span>
    </Link>
  );
};

interface AthleteCardProps {
  athlete: {
    id: number;
    first_name: string;
    last_name: string;
    profile_image_url?: string;
    teams?: Array<{ id: number; name: string }>;
  };
  to?: string;
  onClick?: () => void;
}

export const AthleteCard: React.FC<AthleteCardProps> = ({ athlete, to, onClick }) => {
  const getInitials = (firstName: string, lastName: string) => {
    return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
  };

  const content = (
    <div className="flex items-center gap-3 p-3 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
      {athlete.profile_image_url ? (
        <img
          src={athlete.profile_image_url}
          alt={`${athlete.first_name} ${athlete.last_name}`}
          className="w-12 h-12 rounded-full object-cover"
        />
      ) : (
        <div className="w-12 h-12 rounded-full bg-brand-primary text-white flex items-center justify-center text-lg font-medium">
          {getInitials(athlete.first_name, athlete.last_name)}
        </div>
      )}
      <div className="flex-1 min-w-0">
        <p className="font-medium text-gray-900 truncate">
          {athlete.first_name} {athlete.last_name}
        </p>
        {athlete.teams && athlete.teams.length > 0 && (
          <p className="text-sm text-gray-500 truncate">
            {athlete.teams.map((t) => t.name).join(', ')}
          </p>
        )}
      </div>
      <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
      </svg>
    </div>
  );

  if (to) {
    return <Link to={to}>{content}</Link>;
  }

  if (onClick) {
    return (
      <button onClick={onClick} className="w-full text-left">
        {content}
      </button>
    );
  }

  return content;
};

interface PaymentAlertProps {
  amount: number;
  dueDate?: string;
  athleteName?: string;
}

export const PaymentAlert: React.FC<PaymentAlertProps> = ({ amount, dueDate, athleteName }) => {
  return (
    <Link
      to="/parent/payments"
      className="flex items-center gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors"
    >
      <div className="flex-shrink-0 w-10 h-10 bg-amber-500 rounded-full flex items-center justify-center">
        <svg className="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <div className="flex-1">
        <p className="font-semibold text-amber-800">
          ${amount.toFixed(2)} Outstanding
        </p>
        {dueDate && (
          <p className="text-sm text-amber-700">Due {dueDate}</p>
        )}
        {athleteName && (
          <p className="text-sm text-amber-600">{athleteName}</p>
        )}
      </div>
      <svg className="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
      </svg>
    </Link>
  );
};

interface EventItemProps {
  event: {
    id: number;
    title: string;
    date: string;
    time?: string;
    type?: string;
    location?: string;
  };
}

export const EventItem: React.FC<EventItemProps> = ({ event }) => {
  const formatDate = (dateStr: string) => {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
    });
  };

  return (
    <Link
      to={`/parent/schedule/rsvp/${event.id}`}
      className="flex items-center gap-3 py-3 border-b border-gray-100 last:border-0 hover:bg-gray-50 -mx-4 px-4 transition-colors"
    >
      <div className="flex-shrink-0 w-12 text-center">
        <p className="text-xs text-gray-500 uppercase">
          {new Date(event.date).toLocaleDateString('en-US', { weekday: 'short' })}
        </p>
        <p className="text-lg font-bold text-brand-primary">
          {new Date(event.date).getDate()}
        </p>
      </div>
      <div className="flex-1 min-w-0">
        <p className="font-medium text-gray-900 truncate">{event.title}</p>
        <div className="flex items-center gap-2 text-sm text-gray-500">
          {event.time && <span>{event.time}</span>}
          {event.location && (
            <>
              <span>·</span>
              <span className="truncate">{event.location}</span>
            </>
          )}
        </div>
      </div>
      <svg className="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
      </svg>
    </Link>
  );
};

interface AnnouncementItemProps {
  announcement: {
    id: number;
    title: string;
    message: string;
    created_at: string;
    team_name?: string;
  };
}

export const AnnouncementItem: React.FC<AnnouncementItemProps> = ({ announcement }) => {
  const timeAgo = (dateStr: string) => {
    const date = new Date(dateStr);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor(diff / (1000 * 60));

    if (days > 0) return `${days}d ago`;
    if (hours > 0) return `${hours}h ago`;
    if (minutes > 0) return `${minutes}m ago`;
    return 'Just now';
  };

  return (
    <Link
      to={`/parent/announcements`}
      className="block py-3 border-b border-gray-100 last:border-0 hover:bg-gray-50 -mx-4 px-4 transition-colors"
    >
      <div className="flex items-start justify-between gap-2 mb-1">
        <p className="font-medium text-gray-900 line-clamp-1">{announcement.title}</p>
        <span className="text-xs text-gray-500 flex-shrink-0">{timeAgo(announcement.created_at)}</span>
      </div>
      <p className="text-sm text-gray-600 line-clamp-2">{announcement.message}</p>
      {announcement.team_name && (
        <p className="text-xs text-brand-primary mt-1">{announcement.team_name}</p>
      )}
    </Link>
  );
};

export default DashboardCard;

import React, { useState, useEffect } from 'react';
import { useParentAthletes } from '../hooks/useParentAthletes';
import { ParentHeader } from '../components/ParentHeader';
import { AthleteSelector } from '../components/AthleteSelector';

interface Announcement {
  id: number;
  title: string;
  message: string;
  created_at: string;
  author_name?: string;
  team_id?: number;
  team_name?: string;
  priority?: 'normal' | 'high' | 'urgent';
  read?: boolean;
}

export const AnnouncementsPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { athletes, selectedAthleteId, selectAthlete } = useParentAthletes();
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [expandedId, setExpandedId] = useState<number | null>(null);

  useEffect(() => {
    const fetchAnnouncements = async () => {
      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const athleteParam = selectedAthleteId ? `&athlete_id=${selectedAthleteId}` : '';
        const response = await fetch(
          `${API_URL}/api/announcements.php?action=list${athleteParam}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await response.json();

        if (data.success && data.announcements) {
          setAnnouncements(data.announcements);
        } else {
          setAnnouncements([]);
        }
      } catch (err) {
        setError('Failed to load announcements');
      } finally {
        setLoading(false);
      }
    };

    fetchAnnouncements();
  }, [API_URL, selectedAthleteId]);

  const formatDate = (dateStr: string) => {
    const date = new Date(dateStr);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (days === 0) {
      return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
      });
    }
    if (days === 1) {
      return 'Yesterday';
    }
    if (days < 7) {
      return date.toLocaleDateString('en-US', { weekday: 'long' });
    }
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
    });
  };

  const getPriorityStyles = (priority?: Announcement['priority']) => {
    if (priority === 'urgent') {
      return {
        border: 'border-l-4 border-l-red-500',
        badge: 'bg-red-100 text-red-800',
        label: 'Urgent',
      };
    }
    if (priority === 'high') {
      return {
        border: 'border-l-4 border-l-orange-500',
        badge: 'bg-orange-100 text-orange-800',
        label: 'Important',
      };
    }
    return {
      border: '',
      badge: '',
      label: '',
    };
  };

  const markAsRead = async (id: number) => {
    try {
      const token = localStorage.getItem('auth_token');
      await fetch(`${API_URL}/api/announcements.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          action: 'mark_read',
          announcement_id: id,
        }),
      });

      setAnnouncements((prev) =>
        prev.map((a) => (a.id === id ? { ...a, read: true } : a))
      );
    } catch (err) {
      console.error('Error marking as read:', err);
    }
  };

  const toggleExpand = (id: number) => {
    setExpandedId((prev) => (prev === id ? null : id));
    // Mark as read when expanded
    const announcement = announcements.find((a) => a.id === id);
    if (announcement && !announcement.read) {
      markAsRead(id);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader
        title="Announcements"
        showBack
        rightElement={
          athletes.length > 1 ? (
            <AthleteSelector
              athletes={athletes}
              selectedAthleteId={selectedAthleteId}
              onSelect={selectAthlete}
              showAllOption={true}
            />
          ) : undefined
        }
      />

      <div className="pt-14 pb-4">
        {/* Loading State */}
        {loading && (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
          </div>
        )}

        {/* Error State */}
        {error && (
          <div className="mx-4 mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
            {error}
          </div>
        )}

        {/* Empty State */}
        {!loading && !error && announcements.length === 0 && (
          <div className="text-center py-12 px-4">
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
                d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-gray-900">No Announcements</h3>
            <p className="mt-1 text-sm text-gray-500">
              There are no announcements at this time.
            </p>
          </div>
        )}

        {/* Announcements List */}
        {!loading && !error && announcements.length > 0 && (
          <div className="px-4 py-4 space-y-3">
            {announcements.map((announcement) => {
              const priorityStyles = getPriorityStyles(announcement.priority);
              const isExpanded = expandedId === announcement.id;

              return (
                <button
                  key={announcement.id}
                  onClick={() => toggleExpand(announcement.id)}
                  className={`w-full text-left bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden transition-all ${
                    priorityStyles.border
                  } ${!announcement.read ? 'ring-2 ring-brand-primary/20' : ''}`}
                >
                  <div className="p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                          {priorityStyles.label && (
                            <span
                              className={`px-2 py-0.5 text-xs font-medium rounded ${priorityStyles.badge}`}
                            >
                              {priorityStyles.label}
                            </span>
                          )}
                          {!announcement.read && (
                            <span className="w-2 h-2 rounded-full bg-brand-primary"></span>
                          )}
                        </div>
                        <h3 className="font-semibold text-gray-900">{announcement.title}</h3>
                        {announcement.team_name && (
                          <p className="text-sm text-brand-primary">{announcement.team_name}</p>
                        )}
                      </div>
                      <div className="flex items-center gap-2 flex-shrink-0">
                        <span className="text-xs text-gray-500">
                          {formatDate(announcement.created_at)}
                        </span>
                        <svg
                          className={`w-5 h-5 text-gray-400 transition-transform ${
                            isExpanded ? 'rotate-180' : ''
                          }`}
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M19 9l-7 7-7-7"
                          />
                        </svg>
                      </div>
                    </div>

                    {!isExpanded && (
                      <p className="text-gray-600 text-sm mt-2 line-clamp-2">
                        {announcement.message}
                      </p>
                    )}

                    {isExpanded && (
                      <div className="mt-3 pt-3 border-t border-gray-100">
                        <p className="text-gray-700 whitespace-pre-wrap">
                          {announcement.message}
                        </p>
                        {announcement.author_name && (
                          <p className="text-sm text-gray-500 mt-3">
                            Posted by {announcement.author_name}
                          </p>
                        )}
                      </div>
                    )}
                  </div>
                </button>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
};

export default AnnouncementsPage;

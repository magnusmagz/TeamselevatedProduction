import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useParentAthletes } from '../hooks/useParentAthletes';
import { ParentHeader } from '../components/ParentHeader';

interface EventDetails {
  id: number;
  title: string;
  description?: string;
  date: string;
  start_time?: string;
  end_time?: string;
  location?: string;
  address?: string;
  type: 'practice' | 'game' | 'meeting' | 'tournament' | 'other';
  team_id?: number;
  team_name?: string;
  notes?: string;
  athletes_rsvp?: Array<{
    athlete_id: number;
    athlete_name: string;
    status: 'attending' | 'not_attending' | 'maybe' | null;
  }>;
}

export const ScheduleRSVPPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { athletes } = useParentAthletes();

  const [event, setEvent] = useState<EventDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [rsvpStatus, setRsvpStatus] = useState<{ [athleteId: number]: string }>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    const fetchEvent = async () => {
      if (!id) return;

      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${API_URL}/api/calendar-events-gateway.php?action=get&id=${id}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const data = await response.json();

        if (data.success && data.event) {
          setEvent(data.event);

          // Initialize RSVP status from event data
          const initialRsvp: { [key: number]: string } = {};
          data.event.athletes_rsvp?.forEach(
            (a: { athlete_id: number; status: string | null }) => {
              if (a.status) {
                initialRsvp[a.athlete_id] = a.status;
              }
            }
          );
          setRsvpStatus(initialRsvp);
        } else {
          setError('Event not found');
        }
      } catch (err) {
        setError('Failed to load event details');
      } finally {
        setLoading(false);
      }
    };

    fetchEvent();
  }, [API_URL, id]);

  const formatDate = (dateStr: string) => {
    // Parse "YYYY-MM-DD" as local time so PDT users don't see the previous day
    // (new Date("2026-05-05") parses as UTC midnight = May 4 5pm PDT).
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-US', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  const formatTime = (time?: string) => {
    if (!time) return '';
    const [hours, minutes] = time.split(':');
    const h = parseInt(hours);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const hour12 = h % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
  };

  const getEventTypeColor = (type: EventDetails['type']) => {
    const colors = {
      practice: 'bg-blue-100 text-blue-800',
      game: 'bg-green-100 text-green-800',
      meeting: 'bg-purple-100 text-purple-800',
      tournament: 'bg-orange-100 text-orange-800',
      other: 'bg-gray-100 text-gray-800',
    };
    return colors[type] || colors.other;
  };

  const handleRsvpChange = (athleteId: number, status: string) => {
    setRsvpStatus((prev) => ({
      ...prev,
      [athleteId]: status,
    }));
  };

  const handleSubmit = async () => {
    if (!event) return;

    setSubmitting(true);
    setError(null);

    try {
      const token = localStorage.getItem('auth_token');
      const promises = Object.entries(rsvpStatus).map(([athleteId, status]) =>
        fetch(`${API_URL}/api/calendar-events-gateway.php?action=rsvp`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({
            event_id: event.id,
            athlete_id: parseInt(athleteId),
            status,
          }),
        })
      );

      await Promise.all(promises);
      navigate('/parent/schedule', { state: { message: 'RSVP updated successfully' } });
    } catch (err) {
      setError('Failed to update RSVP');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Event Details" showBack />
        <div className="pt-14 flex items-center justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
        </div>
      </div>
    );
  }

  if (error || !event) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Event Details" showBack />
        <div className="pt-14 px-4">
          <div className="mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
            {error || 'Event not found'}
          </div>
        </div>
      </div>
    );
  }

  // Filter athletes that are part of this event's team
  const relevantAthletes = event.team_id
    ? athletes.filter((a) => a.teams?.some((t) => t.id === event.team_id))
    : athletes;

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title="Event Details" showBack />

      <div className="pt-14 pb-40">
        {/* Event Header */}
        <div className="bg-white border-b border-gray-200 px-4 py-6">
          <span
            className={`inline-block px-2 py-0.5 text-xs font-medium rounded mb-2 ${getEventTypeColor(
              event.type
            )}`}
          >
            {event.type.charAt(0).toUpperCase() + event.type.slice(1)}
          </span>
          <h1 className="text-xl font-bold text-brand-primary">{event.title}</h1>
          {event.team_name && (
            <p className="text-brand-primary mt-1">{event.team_name}</p>
          )}
        </div>

        {/* Event Details */}
        <div className="px-4 py-4 space-y-4">
          {/* Date & Time */}
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div className="flex items-start gap-3">
              <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center">
                <svg
                  className="w-5 h-5 text-brand-primary"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                  />
                </svg>
              </div>
              <div>
                <p className="font-medium text-gray-900">{formatDate(event.date)}</p>
                {(event.start_time || event.end_time) && (
                  <p className="text-gray-600">
                    {formatTime(event.start_time)}
                    {event.end_time && ` - ${formatTime(event.end_time)}`}
                  </p>
                )}
              </div>
            </div>
          </div>

          {/* Location */}
          {(event.location || event.address) && (
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <div className="flex items-start gap-3">
                <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center">
                  <svg
                    className="w-5 h-5 text-brand-primary"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                    />
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                    />
                  </svg>
                </div>
                <div>
                  <p className="font-medium text-gray-900">{event.location}</p>
                  {event.address && <p className="text-gray-600">{event.address}</p>}
                </div>
              </div>
            </div>
          )}

          {/* Description */}
          {event.description && (
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="font-semibold text-brand-primary mb-2">Details</h2>
              <p className="text-gray-700 whitespace-pre-wrap">{event.description}</p>
            </div>
          )}

          {/* Notes */}
          {event.notes && (
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="font-semibold text-brand-primary mb-2">Notes</h2>
              <p className="text-gray-700 whitespace-pre-wrap">{event.notes}</p>
            </div>
          )}

          {/* RSVP Section */}
          {relevantAthletes.length > 0 && (
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="font-semibold text-brand-primary mb-4">RSVP</h2>
              <div className="space-y-4">
                {relevantAthletes.map((athlete) => (
                  <div key={athlete.id}>
                    <p className="font-medium text-gray-900 mb-2">
                      {athlete.first_name} {athlete.last_name}
                    </p>
                    <div className="flex gap-2">
                      {(['attending', 'maybe', 'not_attending'] as const).map((status) => (
                        <button
                          key={status}
                          onClick={() => handleRsvpChange(athlete.id, status)}
                          className={`flex-1 py-2 px-3 rounded-lg text-sm font-medium transition-colors ${
                            rsvpStatus[athlete.id] === status
                              ? status === 'attending'
                                ? 'bg-green-500 text-white'
                                : status === 'not_attending'
                                ? 'bg-red-500 text-white'
                                : 'bg-yellow-500 text-white'
                              : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                          }`}
                        >
                          {status === 'attending'
                            ? 'Going'
                            : status === 'not_attending'
                            ? 'Not Going'
                            : 'Maybe'}
                        </button>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Fixed Bottom Button - positioned above bottom nav */}
      {relevantAthletes.length > 0 && (
        <div
          className="fixed left-0 right-0 bg-white border-t border-gray-200 p-4 z-40"
          style={{ bottom: 'calc(4rem + var(--safe-area-inset-bottom, 0px))' }}
        >
          <div className="max-w-lg mx-auto">
            <button
              onClick={handleSubmit}
              disabled={submitting || Object.keys(rsvpStatus).length === 0}
              className="w-full py-3 bg-brand-primary text-white font-semibold rounded-lg hover:bg-brand-primary-hover transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {submitting ? 'Saving...' : 'Save RSVP'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default ScheduleRSVPPage;

import React, { useState, useEffect, useRef, useMemo } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useParentAthletes } from '../hooks/useParentAthletes';
import { ParentHeader } from '../components/ParentHeader';
import { AthleteSelector } from '../components/AthleteSelector';

interface Event {
  id: number;
  title: string;
  description?: string;
  date: string;
  start_time?: string;
  end_time?: string;
  location?: string;
  type: 'practice' | 'game' | 'meeting' | 'tournament' | 'other';
  team_id?: number;
  team_name?: string;
  rsvp_status?: 'attending' | 'not_attending' | 'maybe' | null;
}

export const UpcomingEventsPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { athletes, selectedAthleteId, selectAthlete } = useParentAthletes();
  const [searchParams] = useSearchParams();
  const [events, setEvents] = useState<Event[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<'list' | 'calendar'>('list');
  const [calendarMonth, setCalendarMonth] = useState<Date>(() => {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  });

  // Map team_id → first names of the parent's athletes on that team. Used to label
  // each event tile in the "All Athletes" list view so parents can tell which kid
  // each event applies to when multiple of their athletes are on different teams.
  const teamIdToAthleteFirstNames = useMemo(() => {
    const map: Record<number, string[]> = {};
    athletes.forEach((a) => {
      (a.teams || []).forEach((t) => {
        if (!map[t.id]) map[t.id] = [];
        if (!map[t.id].includes(a.first_name)) map[t.id].push(a.first_name);
      });
    });
    return map;
  }, [athletes]);

  // Pre-select athlete from ?athlete=N URL param when arriving from athlete-detail.
  // Apply once per URL value (not on every render) so the dropdown stays free to
  // change after the initial pre-select — otherwise switching to Madison snaps back
  // to the URL-param athlete.
  const appliedUrlAthleteRef = useRef<string | null>(null);
  useEffect(() => {
    const athleteParam = searchParams.get('athlete');
    if (!athleteParam) {
      appliedUrlAthleteRef.current = null;
      return;
    }
    if (athleteParam === appliedUrlAthleteRef.current) return;
    if (athletes.length === 0) return;
    const id = Number(athleteParam);
    if (Number.isFinite(id) && athletes.some((a) => a.id === id)) {
      selectAthlete(id);
      appliedUrlAthleteRef.current = athleteParam;
    }
  }, [searchParams, athletes, selectAthlete]);

  useEffect(() => {
    const fetchEvents = async () => {
      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const athleteParam = selectedAthleteId ? `&athlete_id=${selectedAthleteId}` : '';
        const response = await fetch(
          `${API_URL}/api/calendar-events-gateway.php?action=upcoming${athleteParam}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await response.json();

        if (data.success && data.events) {
          setEvents(data.events);
        } else {
          setEvents([]);
        }
      } catch (err) {
        setError('Failed to load events');
      } finally {
        setLoading(false);
      }
    };

    fetchEvents();
  }, [API_URL, selectedAthleteId]);

  const getEventTypeColor = (type: Event['type']) => {
    const colors = {
      practice: 'bg-blue-100 text-blue-800',
      game: 'bg-green-100 text-green-800',
      meeting: 'bg-purple-100 text-purple-800',
      tournament: 'bg-orange-100 text-orange-800',
      other: 'bg-gray-100 text-gray-800',
    };
    return colors[type] || colors.other;
  };

  const formatTime = (time?: string) => {
    if (!time) return '';
    const [hours, minutes] = time.split(':');
    const h = parseInt(hours);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const hour12 = h % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
  };

  const groupEventsByDate = (events: Event[]) => {
    const groups: { [key: string]: Event[] } = {};
    events.forEach((event) => {
      const date = event.date;
      if (!groups[date]) {
        groups[date] = [];
      }
      groups[date].push(event);
    });
    return groups;
  };

  const formatDateHeader = (dateStr: string) => {
    // Parse "YYYY-MM-DD" as local time. Otherwise PDT users see "May 4" for
    // a server date of "2026-05-05" (new Date("YYYY-MM-DD") = UTC midnight).
    const [y, m, d] = dateStr.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);

    if (date.toDateString() === today.toDateString()) {
      return 'Today';
    }
    if (date.toDateString() === tomorrow.toDateString()) {
      return 'Tomorrow';
    }
    return date.toLocaleDateString('en-US', {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
    });
  };

  const groupedEvents = groupEventsByDate(events);

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader
        title="Schedule"
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
        {/* View Mode Toggle */}
        <div className="flex bg-white border-b border-gray-200">
          <button
            onClick={() => setViewMode('list')}
            className={`flex-1 py-3 text-sm font-medium border-b-2 transition-colors ${
              viewMode === 'list'
                ? 'border-brand-primary text-brand-primary'
                : 'border-transparent text-gray-500'
            }`}
          >
            List View
          </button>
          <button
            onClick={() => setViewMode('calendar')}
            className={`flex-1 py-3 text-sm font-medium border-b-2 transition-colors ${
              viewMode === 'calendar'
                ? 'border-brand-primary text-brand-primary'
                : 'border-transparent text-gray-500'
            }`}
          >
            Calendar View
          </button>
        </div>

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
        {!loading && !error && events.length === 0 && (
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
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">No Upcoming Events</h3>
            <p className="mt-1 text-sm text-gray-500">
              There are no scheduled events at this time.
            </p>
          </div>
        )}

        {/* List View */}
        {!loading && !error && events.length > 0 && viewMode === 'list' && (
          <div className="px-4 py-4">
            {Object.entries(groupedEvents).map(([date, dateEvents]) => (
              <div key={date} className="mb-6">
                <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
                  {formatDateHeader(date)}
                </h2>
                <div className="space-y-3">
                  {dateEvents.map((event) => (
                    <Link
                      key={event.id}
                      to={`/parent/schedule/rsvp/${event.id}`}
                      className="block bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:bg-gray-50 transition-colors"
                    >
                      <div className="flex items-start gap-3">
                        <div className="flex-shrink-0 w-12 text-center">
                          {event.start_time && (
                            <p className="text-sm font-medium text-gray-900">
                              {formatTime(event.start_time)}
                            </p>
                          )}
                        </div>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 mb-1">
                            <span
                              className={`px-2 py-0.5 text-xs font-medium rounded ${getEventTypeColor(
                                event.type
                              )}`}
                            >
                              {event.type.charAt(0).toUpperCase() + event.type.slice(1)}
                            </span>
                            {event.rsvp_status && (
                              <span
                                className={`px-2 py-0.5 text-xs font-medium rounded ${
                                  event.rsvp_status === 'attending'
                                    ? 'bg-green-100 text-green-800'
                                    : event.rsvp_status === 'not_attending'
                                    ? 'bg-red-100 text-red-800'
                                    : 'bg-yellow-100 text-yellow-800'
                                }`}
                              >
                                {event.rsvp_status === 'attending'
                                  ? 'Going'
                                  : event.rsvp_status === 'not_attending'
                                  ? 'Not Going'
                                  : 'Maybe'}
                              </span>
                            )}
                          </div>
                          <p className="font-medium text-gray-900">{event.title}</p>
                          {event.team_name && (
                            <p className="text-sm text-brand-primary">{event.team_name}</p>
                          )}
                          {selectedAthleteId === null && event.team_id && teamIdToAthleteFirstNames[event.team_id]?.length > 0 && (
                            <p className="text-xs text-gray-500 mt-0.5">
                              For {teamIdToAthleteFirstNames[event.team_id].join(' & ')}
                            </p>
                          )}
                          {event.location && (
                            <div className="flex items-center gap-1 mt-1 text-sm text-gray-500">
                              <svg
                                className="w-4 h-4"
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
                              <span className="truncate">{event.location}</span>
                            </div>
                          )}
                        </div>
                        <svg
                          className="w-5 h-5 text-gray-400 flex-shrink-0"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M9 5l7 7-7 7"
                          />
                        </svg>
                      </div>
                    </Link>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Calendar View - Month grid */}
        {!loading && !error && viewMode === 'calendar' && (
          <CalendarMonthGrid
            month={calendarMonth}
            events={events}
            onPrev={() => setCalendarMonth(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1))}
            onNext={() => setCalendarMonth(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1))}
            onToday={() => {
              const d = new Date();
              setCalendarMonth(new Date(d.getFullYear(), d.getMonth(), 1));
            }}
          />
        )}
      </div>
    </div>
  );
};

interface CalendarMonthGridProps {
  month: Date;
  events: Event[];
  onPrev: () => void;
  onNext: () => void;
  onToday: () => void;
}

const CalendarMonthGrid: React.FC<CalendarMonthGridProps> = ({ month, events, onPrev, onNext, onToday }) => {
  const year = month.getFullYear();
  const monthIndex = month.getMonth();
  const monthLabel = month.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

  // Build a 6-week (42 cell) grid starting from the Sunday on or before day 1 of the month.
  // Use gridStart's own year/month for each cell so out-of-range days resolve correctly when
  // the grid spans into the previous or next month (and across year boundaries).
  const firstOfMonth = new Date(year, monthIndex, 1);
  const gridStart = new Date(year, monthIndex, 1 - firstOfMonth.getDay());
  const days: Date[] = [];
  for (let i = 0; i < 42; i++) {
    days.push(new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i));
  }

  const eventsByDay = events.reduce<Record<string, Event[]>>((acc, e) => {
    (acc[e.date] = acc[e.date] || []).push(e);
    return acc;
  }, {});

  const todayKey = new Date().toISOString().split('T')[0];
  const dayKey = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

  return (
    <div className="px-4 py-4">
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-3">
        <div className="flex items-center justify-between mb-3">
          <button
            onClick={onPrev}
            aria-label="Previous month"
            className="p-2 hover:bg-gray-100 rounded"
          >
            <svg className="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <div className="flex items-center gap-2">
            <h3 className="font-semibold text-brand-primary">{monthLabel}</h3>
            <button
              onClick={onToday}
              className="text-xs text-brand-accent hover:underline"
            >
              Today
            </button>
          </div>
          <button
            onClick={onNext}
            aria-label="Next month"
            className="p-2 hover:bg-gray-100 rounded"
          >
            <svg className="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>

        <div className="grid grid-cols-7 gap-px bg-gray-200 rounded overflow-hidden">
          {['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((d) => (
            <div key={d} className="bg-gray-50 text-xs font-semibold text-gray-500 text-center py-1">{d}</div>
          ))}
          {days.map((d, idx) => {
            const key = dayKey(d);
            const inMonth = d.getMonth() === monthIndex;
            const isToday = key === todayKey;
            const dayEvents = eventsByDay[key] || [];
            return (
              <div
                key={idx}
                className={`bg-white min-h-[64px] p-1 ${inMonth ? '' : 'opacity-40'}`}
              >
                <div className={`text-xs text-right pr-0.5 ${isToday ? 'font-bold text-brand-primary' : 'text-gray-500'}`}>
                  {d.getDate()}
                </div>
                <div className="flex flex-col gap-0.5 mt-0.5">
                  {dayEvents.slice(0, 3).map((evt) => {
                    // RSVP indicator: dot color encodes the response.
                    // Green = attending, red = not attending, amber = maybe,
                    // no dot = not yet responded. Tooltip surfaces the
                    // status verbatim for screen readers / hover.
                    const rsvp = evt.rsvp_status;
                    const dotClass =
                      rsvp === 'attending' ? 'bg-green-500'
                      : rsvp === 'not_attending' ? 'bg-red-500'
                      : rsvp === 'maybe' ? 'bg-amber-500'
                      : null;
                    const tooltip = rsvp
                      ? `${evt.title} — ${rsvp.replace('_', ' ')}`
                      : evt.title;
                    return (
                      <Link
                        key={evt.id}
                        to={`/parent/schedule/rsvp/${evt.id}`}
                        className="flex items-center gap-1 truncate text-[10px] leading-tight px-1 py-0.5 rounded bg-brand-primary/10 text-brand-primary hover:bg-brand-primary/20"
                        title={tooltip}
                      >
                        {dotClass && (
                          <span
                            className={`flex-shrink-0 w-1.5 h-1.5 rounded-full ${dotClass}`}
                            aria-hidden
                          />
                        )}
                        <span className="truncate">{evt.title}</span>
                      </Link>
                    );
                  })}
                  {dayEvents.length > 3 && (
                    <span className="text-[10px] text-gray-400 px-1">+{dayEvents.length - 3} more</span>
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {/* RSVP legend — surfaces what the dots on each event chip mean */}
        <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-gray-500">
          <span className="font-medium uppercase tracking-wide text-gray-400">RSVP</span>
          <span className="inline-flex items-center gap-1"><span className="w-1.5 h-1.5 rounded-full bg-green-500" /> Attending</span>
          <span className="inline-flex items-center gap-1"><span className="w-1.5 h-1.5 rounded-full bg-amber-500" /> Maybe</span>
          <span className="inline-flex items-center gap-1"><span className="w-1.5 h-1.5 rounded-full bg-red-500" /> Not attending</span>
          <span className="text-gray-400">No dot = not yet responded</span>
        </div>
      </div>
    </div>
  );
};

export default UpcomingEventsPage;

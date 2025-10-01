import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

interface Practice {
  id: number;
  team_id: number;
  team_name: string;
  date: string;
  start_time: string;
  end_time: string;
  venue_id: number;
  field_id: number;
  day: string;
}

interface Event {
  id?: number;
  name: string;
  type: 'game' | 'practice' | 'meeting' | 'tournament' | 'event' | 'other';
  event_date: string;
  start_time?: string;
  end_time?: string;
  team_ids?: number[];
  teams?: { id: number; name: string; primary_color?: string }[];
  team_name?: string;
  team_color?: string;
  program_id?: number;
  venue_id?: number;
  venue_name?: string;
  location?: string;
  description?: string;
  status: 'scheduled' | 'cancelled' | 'postponed' | 'completed';
}

interface CalendarDay {
  date: Date;
  dateStr: string;
  isCurrentMonth: boolean;
  isToday: boolean;
  practices: Practice[];
  events: Event[];
}

type ViewMode = 'month' | 'week' | 'schedule';

const TeamCalendar: React.FC = () => {
  const navigate = useNavigate();
  const [currentDate, setCurrentDate] = useState(new Date());
  const [practices, setPractices] = useState<Practice[]>([]);
  const [events, setEvents] = useState<Event[]>([]);
  const [calendarDays, setCalendarDays] = useState<CalendarDay[]>([]);
  const [selectedTeamFilter, setSelectedTeamFilter] = useState<string>('all');
  const [teams, setTeams] = useState<string[]>([]);
  const [viewMode, setViewMode] = useState<ViewMode>('month');
  const [weekDays, setWeekDays] = useState<CalendarDay[]>([]);
  const [showEventForm, setShowEventForm] = useState(false);
  const [selectedDate, setSelectedDate] = useState<string>('');
  const [selectedEvent, setSelectedEvent] = useState<Event | null>(null);
  const [venues, setVenues] = useState<any[]>([]);
  const [allTeams, setAllTeams] = useState<any[]>([]);
  const [programs, setPrograms] = useState<any[]>([]);
  const [sendInvites, setSendInvites] = useState(true);
  const [sendUpdates, setSendUpdates] = useState(true);
  const [changesSummary, setChangesSummary] = useState<string>('');
  const [eventFormData, setEventFormData] = useState<Event>({
    name: '',
    type: 'event',
    event_date: '',
    team_ids: [],
    status: 'scheduled'
  });

  // Detect changes when editing an event
  useEffect(() => {
    if (selectedEvent && eventFormData.name) {
      const changes = [];
      if (eventFormData.event_date !== selectedEvent.event_date) {
        changes.push('Date changed');
      }
      if (eventFormData.start_time !== selectedEvent.start_time) {
        changes.push('Start time changed');
      }
      if (eventFormData.end_time !== selectedEvent.end_time) {
        changes.push('End time changed');
      }
      if (eventFormData.venue_id !== selectedEvent.venue_id) {
        changes.push('Location changed');
      }
      if (eventFormData.status !== selectedEvent.status) {
        changes.push(`Status changed to ${eventFormData.status}`);
      }
      setChangesSummary(changes.join(', '));
    }
  }, [eventFormData, selectedEvent]);

  useEffect(() => {
    // Load practices from localStorage
    const loadPractices = () => {
      const storedPractices = JSON.parse(localStorage.getItem('teamPractices') || '[]');
      setPractices(storedPractices);

      // Extract unique team names
      const uniqueTeams = Array.from(new Set(storedPractices.map((p: Practice) => p.team_name))) as string[];
      setTeams(uniqueTeams);
    };

    // Load initially
    loadPractices();
    fetchEvents();
    fetchVenues();
    fetchTeams();
    fetchPrograms();

    // Reload when window gets focus (in case practices were added in another tab/component)
    const handleFocus = () => {
      loadPractices();
      fetchEvents();
    };
    window.addEventListener('focus', handleFocus);

    // Also reload when localStorage changes
    const handleStorageChange = (e: StorageEvent) => {
      if (e.key === 'teamPractices') {
        loadPractices();
      }
    };
    window.addEventListener('storage', handleStorageChange);

    return () => {
      window.removeEventListener('focus', handleFocus);
      window.removeEventListener('storage', handleStorageChange);
    };
  }, []);

  const fetchEvents = async () => {
    try {
      const response = await fetch('http://localhost:8889/events-gateway.php');
      const data = await response.json();
      if (data.events) {
        setEvents(data.events);
      }
    } catch (error) {
      console.error('Error fetching events:', error);
    }
  };

  const fetchVenues = async () => {
    try {
      const response = await fetch('http://localhost:8889/venues-gateway.php');
      const data = await response.json();
      if (data.venues) {
        setVenues(data.venues);
      }
    } catch (error) {
      console.error('Error fetching venues:', error);
    }
  };

  const fetchTeams = async () => {
    try {
      const response = await fetch('http://localhost:8889/teams-gateway.php');
      const data = await response.json();
      if (data.teams) {
        setAllTeams(data.teams);
      }
    } catch (error) {
      console.error('Error fetching teams:', error);
    }
  };

  const fetchPrograms = async () => {
    try {
      const response = await fetch('http://localhost:8889/programs-gateway.php');
      const data = await response.json();
      if (data.programs) {
        setPrograms(data.programs);
      }
    } catch (error) {
      console.error('Error fetching programs:', error);
    }
  };

  useEffect(() => {
    if (viewMode === 'month') {
      generateCalendarDays();
    } else if (viewMode === 'week') {
      generateWeekDays();
    }
  }, [currentDate, practices, events, selectedTeamFilter, viewMode]);

  const generateCalendarDays = () => {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDate = new Date(firstDay);
    const endDate = new Date(lastDay);

    // Adjust to start on Sunday
    startDate.setDate(startDate.getDate() - startDate.getDay());
    // Adjust to end on Saturday
    if (endDate.getDay() !== 6) {
      endDate.setDate(endDate.getDate() + (6 - endDate.getDay()));
    }

    const days: CalendarDay[] = [];
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let date = new Date(startDate); date <= endDate; date.setDate(date.getDate() + 1)) {
      const currentDateCopy = new Date(date);
      const dateStr = currentDateCopy.toISOString().split('T')[0];

      // Filter practices for this day
      let dayPractices = practices.filter(p => p.date === dateStr);
      let dayEvents = events.filter(e => e.event_date === dateStr);

      // Apply team filter
      if (selectedTeamFilter !== 'all') {
        dayPractices = dayPractices.filter(p => p.team_name === selectedTeamFilter);
        dayEvents = dayEvents.filter(e => e.team_name === selectedTeamFilter);
      }

      days.push({
        date: currentDateCopy,
        dateStr,
        isCurrentMonth: currentDateCopy.getMonth() === month,
        isToday: currentDateCopy.getTime() === today.getTime(),
        practices: dayPractices,
        events: dayEvents
      });
    }

    setCalendarDays(days);
  };

  const generateWeekDays = () => {
    const startOfWeek = new Date(currentDate);
    const day = startOfWeek.getDay();
    const diff = startOfWeek.getDate() - day;
    startOfWeek.setDate(diff);
    startOfWeek.setHours(0, 0, 0, 0);

    const days: CalendarDay[] = [];
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let i = 0; i < 7; i++) {
      const date = new Date(startOfWeek);
      date.setDate(startOfWeek.getDate() + i);
      const dateStr = date.toISOString().split('T')[0];

      let dayPractices = practices.filter(p => p.date === dateStr);
      let dayEvents = events.filter(e => e.event_date === dateStr);

      if (selectedTeamFilter !== 'all') {
        dayPractices = dayPractices.filter(p => p.team_name === selectedTeamFilter);
        dayEvents = dayEvents.filter(e => e.team_name === selectedTeamFilter);
      }

      days.push({
        date,
        dateStr,
        isCurrentMonth: true,
        isToday: date.getTime() === today.getTime(),
        practices: dayPractices.sort((a, b) => a.start_time.localeCompare(b.start_time)),
        events: dayEvents.sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''))
      });
    }

    setWeekDays(days);
  };

  const handlePreviousPeriod = () => {
    if (viewMode === 'month') {
      setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1));
    } else if (viewMode === 'week') {
      const newDate = new Date(currentDate);
      newDate.setDate(currentDate.getDate() - 7);
      setCurrentDate(newDate);
    }
  };

  const handleNextPeriod = () => {
    if (viewMode === 'month') {
      setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1));
    } else if (viewMode === 'week') {
      const newDate = new Date(currentDate);
      newDate.setDate(currentDate.getDate() + 7);
      setCurrentDate(newDate);
    }
  };

  const handleToday = () => {
    setCurrentDate(new Date());
  };

  const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];

  const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  // Define team colors
  const getTeamColor = (teamName: string) => {
    const colors = [
      'bg-blue-100 text-blue-800 border-blue-300',
      'bg-green-100 text-green-800 border-green-300',
      'bg-purple-100 text-purple-800 border-purple-300',
      'bg-orange-100 text-orange-800 border-orange-300',
      'bg-pink-100 text-pink-800 border-pink-300',
    ];
    const index = teams.indexOf(teamName) % colors.length;
    return colors[index];
  };

  const handleDateClick = (dateStr: string) => {
    setSelectedDate(dateStr);
    setEventFormData({
      name: '',
      type: 'event',
      event_date: dateStr,
      team_ids: [],
      status: 'scheduled'
    });
    setSelectedEvent(null);
    setShowEventForm(true);
  };

  const handleEventClick = (event: Event) => {
    setSelectedEvent(event);
    // Convert teams array to team_ids for the form
    const formData = {
      ...event,
      team_ids: event.teams ? event.teams.map(t => t.id) : []
    };
    setEventFormData(formData);
    setChangesSummary('');
    setSendUpdates(true);
    setShowEventForm(true);
  };

  const handleEventSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    console.log('Form submitted with data:', eventFormData);

    try {
      const url = selectedEvent
        ? `http://localhost:8889/events-gateway.php?id=${selectedEvent.id}`
        : 'http://localhost:8889/events-gateway.php';

      console.log('Sending request to:', url);
      console.log('Request method:', selectedEvent ? 'PUT' : 'POST');

      // Include invite/update flags in the request
      const requestData = {
        ...eventFormData,
        send_invites: !selectedEvent && sendInvites,  // Send invites only for new events
        send_updates: selectedEvent && sendUpdates     // Send updates only when editing
      };

      const response = await fetch(url, {
        method: selectedEvent ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(requestData)
      });

      console.log('Response status:', response.status);
      const responseData = await response.json();
      console.log('Response data:', responseData);

      if (response.ok) {
        console.log('Event saved successfully');

        // Show invite results if available
        if (responseData.invites) {
          if (responseData.invites.sent > 0) {
            alert(`Event saved! Calendar invites sent to ${responseData.invites.sent} recipient(s).`);
          } else if (responseData.invites.message) {
            alert(`Event saved! ${responseData.invites.message}`);
          }
        } else if (responseData.updates) {
          if (responseData.updates.updated > 0) {
            alert(`Event updated! Updates sent to ${responseData.updates.updated} recipient(s).`);
          } else if (responseData.updates.message) {
            alert(`Event updated! ${responseData.updates.message}`);
          }
        }

        setShowEventForm(false);
        fetchEvents();
        setEventFormData({
          name: '',
          type: 'event',
          event_date: '',
          team_ids: [],
          status: 'scheduled'
        });
        setChangesSummary('');
      } else {
        console.error('Failed to save event:', responseData);
        alert('Failed to save event: ' + (responseData.error || 'Unknown error'));
      }
    } catch (error) {
      console.error('Error saving event:', error);
      alert('Error saving event: ' + error);
    }
  };

  const handleDeleteEvent = async (eventId: number) => {
    if (!window.confirm('Are you sure you want to delete this event?')) return;

    try {
      const response = await fetch(`http://localhost:8889/events-gateway.php?id=${eventId}`, {
        method: 'DELETE'
      });

      if (response.ok) {
        setShowEventForm(false);
        fetchEvents();
      }
    } catch (error) {
      console.error('Error deleting event:', error);
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="mb-8 flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold text-forest-800 uppercase tracking-wide">Team Calendar</h1>
          <p className="text-gray-600 mt-2">View all team practices and events</p>
        </div>
        <button
          onClick={() => handleDateClick(new Date().toISOString().split('T')[0])}
          className="bg-forest-800 text-white border-2 border-forest-800 px-4 py-2 hover:bg-forest-700 font-semibold uppercase"
        >
          + Add Event
        </button>
      </div>

      {/* Controls */}
      <div className="bg-white border-2 border-forest-800 p-6 mb-6">
        {/* View Mode Selector */}
        <div className="flex justify-center mb-4">
          <div className="inline-flex border-2 border-forest-800">
            <button
              onClick={() => setViewMode('month')}
              className={`px-6 py-2 uppercase font-medium ${
                viewMode === 'month' ? 'bg-forest-800 text-white' : 'bg-white text-forest-800'
              }`}
            >
              Month
            </button>
            <button
              onClick={() => setViewMode('week')}
              className={`px-6 py-2 uppercase font-medium border-l-2 border-forest-800 ${
                viewMode === 'week' ? 'bg-forest-800 text-white' : 'bg-white text-forest-800'
              }`}
            >
              Week
            </button>
            <button
              onClick={() => setViewMode('schedule')}
              className={`px-6 py-2 uppercase font-medium border-l-2 border-forest-800 ${
                viewMode === 'schedule' ? 'bg-forest-800 text-white' : 'bg-white text-forest-800'
              }`}
            >
              Schedule
            </button>
          </div>
        </div>

        <div className="flex justify-between items-center mb-4">
          <div className="flex items-center space-x-4">
            {viewMode !== 'schedule' && (
              <>
                <button
                  onClick={handlePreviousPeriod}
                  className="text-forest-800 hover:bg-gray-100 p-2"
                >
                  ←
                </button>
                <h2 className="text-xl font-bold text-forest-800">
                  {viewMode === 'week'
                    ? `Week of ${weekDays[0]?.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${weekDays[6]?.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
                    : `${monthNames[currentDate.getMonth()]} ${currentDate.getFullYear()}`
                  }
                </h2>
                <button
                  onClick={handleNextPeriod}
                  className="text-forest-800 hover:bg-gray-100 p-2"
                >
                  →
                </button>
              </>
            )}
            {viewMode === 'schedule' && (
              <h2 className="text-xl font-bold text-forest-800 uppercase">All Scheduled Practices</h2>
            )}
            <button
              onClick={handleToday}
              className="bg-white text-forest-800 border-2 border-forest-800 px-4 py-1 hover:bg-gray-100 uppercase text-sm"
            >
              Today
            </button>
          </div>

          <div className="flex items-center space-x-4">
            <label className="text-forest-800 font-medium">Filter by team:</label>
            <select
              value={selectedTeamFilter}
              onChange={(e) => setSelectedTeamFilter(e.target.value)}
              className="bg-white text-forest-800 border-2 border-forest-800 px-4 py-1 focus:outline-none focus:border-forest-600"
            >
              <option value="all">All Teams</option>
              {teams.map(team => (
                <option key={team} value={team}>{team}</option>
              ))}
            </select>
          </div>
        </div>

        {/* Calendar Views */}
        {viewMode === 'month' && (
          <div className="border-2 border-forest-800">
            {/* Day Headers */}
            <div className="grid grid-cols-7 bg-forest-800 text-white">
              {dayNames.map(day => (
                <div key={day} className="p-2 text-center font-bold uppercase text-sm border-r border-forest-600 last:border-r-0">
                  {day}
                </div>
              ))}
            </div>

            {/* Calendar Days */}
            <div className="grid grid-cols-7">
              {calendarDays.map((day, index) => (
                <div
                  key={index}
                  className={`min-h-[100px] border-r border-b border-gray-300 p-2 cursor-pointer hover:bg-gray-50 ${
                    day.isCurrentMonth ? 'bg-white' : 'bg-gray-50'
                  } ${day.isToday ? 'bg-yellow-50' : ''} last:border-r-0`}
                  onClick={() => handleDateClick(day.dateStr)}
                >
                  <div className={`font-medium mb-1 ${
                    day.isToday ? 'text-forest-800 font-bold' :
                    day.isCurrentMonth ? 'text-gray-700' : 'text-gray-400'
                  }`}>
                    {day.date.getDate()}
                  </div>
                  <div className="space-y-1">
                    {day.events.slice(0, 2).map((event, eIndex) => (
                      <div
                        key={`e-${eIndex}`}
                        className="text-xs p-1 border bg-forest-100 border-forest-300 text-forest-800"
                        title={event.name}
                        onClick={(e) => {
                          e.stopPropagation();
                          handleEventClick(event);
                        }}
                      >
                        <div className="font-medium truncate">{event.name}</div>
                        {event.start_time && <div className="text-xs">{event.start_time}</div>}
                      </div>
                    ))}
                    {day.practices.slice(0, 2).map((practice, pIndex) => (
                      <div
                        key={`p-${pIndex}`}
                        className={`text-xs p-1 border rounded-none ${getTeamColor(practice.team_name)}`}
                        title={`${practice.team_name}: ${practice.start_time} - ${practice.end_time}`}
                      >
                        <div className="font-medium truncate">{practice.team_name}</div>
                        <div className="text-xs">{practice.start_time}</div>
                      </div>
                    ))}
                    {(day.events.length + day.practices.length) > 4 && (
                      <div className="text-xs text-gray-500 italic">
                        +{(day.events.length + day.practices.length) - 4} more
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {viewMode === 'week' && (
          <div className="border-2 border-forest-800">
            {/* Day Headers */}
            <div className="grid grid-cols-7 bg-forest-800 text-white">
              {weekDays.map((day, index) => (
                <div key={index} className="p-2 text-center border-r border-forest-600 last:border-r-0">
                  <div className="font-bold uppercase text-sm">{dayNames[index]}</div>
                  <div className="text-xs">{day?.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</div>
                </div>
              ))}
            </div>

            {/* Week Days with Time Slots */}
            <div className="grid grid-cols-7">
              {weekDays.map((day, index) => (
                <div
                  key={index}
                  className={`min-h-[400px] border-r border-gray-300 ${
                    day.isToday ? 'bg-yellow-50' : 'bg-white'
                  } last:border-r-0`}
                >
                  <div className="border-b border-gray-200 p-2">
                    {(day.events.length > 0 || day.practices.length > 0) ? (
                      <div className="space-y-2">
                        {day.events.map((event, eIndex) => (
                          <div
                            key={`e-${eIndex}`}
                            className="p-2 border bg-forest-100 border-forest-300 text-forest-800 cursor-pointer hover:bg-forest-200"
                            onClick={() => handleEventClick(event)}
                          >
                            <div className="font-bold text-sm">
                              {event.start_time && event.end_time ? `${event.start_time} - ${event.end_time}` : 'All Day'}
                            </div>
                            <div className="font-medium">{event.name}</div>
                            {event.venue_name && <div className="text-xs opacity-75">{event.venue_name}</div>}
                          </div>
                        ))}
                        {day.practices.map((practice, pIndex) => (
                          <div
                            key={`p-${pIndex}`}
                            className={`p-2 border ${getTeamColor(practice.team_name)}`}
                          >
                            <div className="font-bold text-sm">{practice.start_time} - {practice.end_time}</div>
                            <div className="font-medium">{practice.team_name}</div>
                            <div className="text-xs opacity-75">Field {practice.field_id}</div>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <div
                        className="text-gray-400 text-center py-4 text-sm cursor-pointer hover:bg-gray-100"
                        onClick={() => handleDateClick(day.dateStr)}
                      >
                        No events - Click to add
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {viewMode === 'schedule' && (
          <div className="border-2 border-forest-800 bg-white">
            <div className="p-4">
              <div className="max-h-[600px] overflow-y-auto">
                {(() => {
                  const filteredPractices = selectedTeamFilter === 'all'
                    ? practices
                    : practices.filter(p => p.team_name === selectedTeamFilter);

                  const sortedPractices = [...filteredPractices].sort((a, b) => {
                    const dateCompare = a.date.localeCompare(b.date);
                    if (dateCompare !== 0) return dateCompare;
                    return a.start_time.localeCompare(b.start_time);
                  });

                  const futurePractices = sortedPractices.filter(p => {
                    const practiceDate = new Date(p.date);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    return practiceDate >= today;
                  });

                  if (futurePractices.length === 0) {
                    return (
                      <div className="text-center py-12 text-gray-500">
                        No upcoming practices scheduled
                      </div>
                    );
                  }

                  let currentMonth = '';
                  return futurePractices.map((practice, index) => {
                    const practiceDate = new Date(practice.date);
                    const monthYear = practiceDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                    const showMonthHeader = monthYear !== currentMonth;
                    currentMonth = monthYear;

                    return (
                      <div key={index}>
                        {showMonthHeader && (
                          <div className="bg-forest-800 text-white px-4 py-2 font-bold uppercase mt-4 first:mt-0">
                            {monthYear}
                          </div>
                        )}
                        <div className="border-b border-gray-200 p-4 hover:bg-gray-50 grid grid-cols-12 gap-4">
                          <div className="col-span-2">
                            <div className="font-bold text-forest-800">
                              {practiceDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })}
                            </div>
                          </div>
                          <div className="col-span-2">
                            <div className="text-forest-800">
                              {practice.start_time} - {practice.end_time}
                            </div>
                          </div>
                          <div className="col-span-3">
                            <div className={`inline-block px-2 py-1 text-sm border ${getTeamColor(practice.team_name)}`}>
                              {practice.team_name}
                            </div>
                          </div>
                          <div className="col-span-2">
                            <div className="text-gray-600">
                              Venue {practice.venue_id}
                            </div>
                          </div>
                          <div className="col-span-2">
                            <div className="text-gray-600">
                              Field {practice.field_id}
                            </div>
                          </div>
                          <div className="col-span-1">
                            <div className="text-gray-600 capitalize">
                              {practice.day}
                            </div>
                          </div>
                        </div>
                      </div>
                    );
                  });
                })()}
              </div>
            </div>
          </div>
        )}

        {/* Legend */}
        {teams.length > 0 && (
          <div className="mt-6">
            <h3 className="text-forest-800 font-bold uppercase mb-2">Teams</h3>
            <div className="flex flex-wrap gap-2">
              {teams.map(team => (
                <div
                  key={team}
                  className={`px-3 py-1 text-sm border ${getTeamColor(team)}`}
                >
                  {team}
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Stats */}
        <div className="mt-6 grid grid-cols-4 gap-4">
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-forest-800">
              {events.filter(e => selectedTeamFilter === 'all' || e.team_name === selectedTeamFilter).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">Total Events</div>
          </div>
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-forest-800">
              {practices.filter(p => selectedTeamFilter === 'all' || p.team_name === selectedTeamFilter).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">Total Practices</div>
          </div>
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-forest-800">
              {[...events, ...practices].filter(item => {
                const itemDate = new Date('event_date' in item ? item.event_date : item.date);
                return itemDate.getMonth() === currentDate.getMonth() &&
                       itemDate.getFullYear() === currentDate.getFullYear() &&
                       (selectedTeamFilter === 'all' || item.team_name === selectedTeamFilter);
              }).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">This Month</div>
          </div>
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-forest-800">
              {[...events, ...practices].filter(item => {
                const itemDate = new Date('event_date' in item ? item.event_date : item.date);
                const todayDate = new Date();
                todayDate.setHours(0, 0, 0, 0);
                return itemDate >= todayDate &&
                       (selectedTeamFilter === 'all' || item.team_name === selectedTeamFilter);
              }).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">Upcoming</div>
          </div>
        </div>
      </div>

      {/* Event Form Modal */}
      {showEventForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white border-2 border-forest-800 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div className="border-b-2 border-forest-800 px-6 py-4">
              <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
                {selectedEvent ? 'Edit Event' : 'Add New Event'}
              </h3>
            </div>

            <form onSubmit={handleEventSubmit} className="p-6">
              <div className="grid grid-cols-2 gap-4 mb-4">
                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Event Name *
                  </label>
                  <input
                    type="text"
                    value={eventFormData.name}
                    onChange={(e) => setEventFormData({ ...eventFormData, name: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    required
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Type *
                  </label>
                  <select
                    value={eventFormData.type}
                    onChange={(e) => setEventFormData({ ...eventFormData, type: e.target.value as any })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    required
                  >
                    <option value="event">Event</option>
                    <option value="game">Game</option>
                    <option value="practice">Practice</option>
                    <option value="meeting">Meeting</option>
                    <option value="tournament">Tournament</option>
                    <option value="other">Other</option>
                  </select>
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Date *
                  </label>
                  <input
                    type="date"
                    value={eventFormData.event_date}
                    onChange={(e) => setEventFormData({ ...eventFormData, event_date: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    required
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Status
                  </label>
                  <select
                    value={eventFormData.status}
                    onChange={(e) => setEventFormData({ ...eventFormData, status: e.target.value as any })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  >
                    <option value="scheduled">Scheduled</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="postponed">Postponed</option>
                    <option value="completed">Completed</option>
                  </select>
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Start Time
                  </label>
                  <input
                    type="time"
                    value={eventFormData.start_time || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, start_time: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    End Time
                  </label>
                  <input
                    type="time"
                    value={eventFormData.end_time || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, end_time: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  />
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Teams (Hold Ctrl/Cmd to select multiple)
                  </label>
                  <div className="mb-2 flex gap-2">
                    <button
                      type="button"
                      onClick={() => {
                        const allTeamIds = allTeams.map(team => team.id);
                        setEventFormData({ ...eventFormData, team_ids: allTeamIds });
                      }}
                      className="px-3 py-1 text-xs bg-forest-800 text-white hover:bg-forest-700 uppercase font-medium"
                    >
                      Choose All
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setEventFormData({ ...eventFormData, team_ids: [] });
                      }}
                      className="px-3 py-1 text-xs border-2 border-forest-800 text-forest-800 hover:bg-gray-100 uppercase font-medium"
                    >
                      Clear All
                    </button>
                  </div>
                  <select
                    multiple
                    value={eventFormData.team_ids?.map(id => id.toString()) || []}
                    onChange={(e) => {
                      const selectedIds = Array.from(e.target.selectedOptions, option => Number(option.value));
                      setEventFormData({ ...eventFormData, team_ids: selectedIds });
                    }}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600 min-h-[120px]"
                  >
                    {allTeams.map(team => (
                      <option key={team.id} value={team.id}>{team.name}</option>
                    ))}
                  </select>
                  <div className="text-sm text-gray-600 mt-1">
                    {(eventFormData.team_ids?.length || 0) > 0
                      ? `${eventFormData.team_ids?.length} team(s) selected`
                      : 'No teams selected (optional)'
                    }
                  </div>
                </div>

                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Venue
                  </label>
                  <select
                    value={eventFormData.venue_id || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, venue_id: e.target.value ? Number(e.target.value) : undefined })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  >
                    <option value="">No Venue</option>
                    {venues.map(venue => (
                      <option key={venue.id} value={venue.id}>{venue.name}</option>
                    ))}
                  </select>
                </div>

                <div className="col-span-2">
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Location (if not a venue)
                  </label>
                  <input
                    type="text"
                    value={eventFormData.location || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, location: e.target.value })}
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    placeholder="e.g., Away game at opponent's field"
                  />
                </div>
              </div>

              <div className="mb-4">
                <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                  Description
                </label>
                <textarea
                  value={eventFormData.description || ''}
                  onChange={(e) => setEventFormData({ ...eventFormData, description: e.target.value })}
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                  rows={3}
                />
              </div>

              {/* Calendar Invite Options */}
              <div className="mb-4 border-t-2 border-gray-200 pt-4">
                <div className="text-forest-800 text-sm font-medium mb-3 uppercase">Calendar Invites</div>

                {/* Show changes summary when editing */}
                {selectedEvent && changesSummary && (
                  <div className="bg-yellow-50 border border-yellow-300 p-3 mb-3 rounded">
                    <p className="text-sm font-medium text-yellow-800">Changes detected: {changesSummary}</p>
                  </div>
                )}

                {/* Invite checkbox for new events */}
                {!selectedEvent && (
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={sendInvites}
                      onChange={(e) => setSendInvites(e.target.checked)}
                      className="w-4 h-4 text-forest-800 border-2 border-forest-800 focus:ring-forest-600"
                    />
                    <span className="text-forest-800">Send calendar invites to all team members</span>
                  </label>
                )}

                {/* Update checkbox for existing events */}
                {selectedEvent && (
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={sendUpdates}
                      onChange={(e) => setSendUpdates(e.target.checked)}
                      className="w-4 h-4 text-forest-800 border-2 border-forest-800 focus:ring-forest-600"
                    />
                    <span className="text-forest-800">Send update notifications to all invitees</span>
                  </label>
                )}

                <p className="text-sm text-gray-600 mt-2">
                  {!selectedEvent
                    ? 'Calendar invites will be sent to all athletes, guardians, and coaches associated with the selected teams.'
                    : 'Update notifications will be sent to everyone who received the original invite.'}
                </p>
              </div>

              <div className="flex justify-between">
                <div>
                  {selectedEvent && (
                    <button
                      type="button"
                      onClick={() => handleDeleteEvent(selectedEvent.id!)}
                      className="px-4 py-2 border-2 border-red-600 text-red-600 hover:bg-red-50 font-semibold uppercase"
                    >
                      Delete
                    </button>
                  )}
                </div>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => {
                      setShowEventForm(false);
                      setEventFormData({
                        name: '',
                        type: 'event',
                        event_date: '',
                        team_ids: [],
                        status: 'scheduled'
                      });
                    }}
                    className="px-4 py-2 border-2 border-forest-800 text-forest-800 hover:bg-gray-100 font-semibold uppercase"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 bg-forest-800 text-white border-2 border-forest-800 hover:bg-forest-700 font-semibold uppercase"
                  >
                    {selectedEvent ? 'Update' : 'Create'} Event
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default TeamCalendar;
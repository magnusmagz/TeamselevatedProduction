import React, { useState, useEffect } from 'react';
import AttendanceModal from './AttendanceModal';
import CalendarSubscriptionManager from './CalendarSubscriptionManager';
import PracticeScheduler from './PracticeScheduler';
import { useAuth } from '../contexts/AuthContext';

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
  opponent_name?: string;
  description?: string;
  status: 'scheduled' | 'cancelled' | 'postponed' | 'completed';
  subscription_id?: number | null;
  recurrence_group_id?: string | null;
  recurrence_rule?: string | null;
}

// Add Event "Repeat" choices → backend recurrence config (frequency/interval).
const REPEAT_OPTIONS: { value: string; label: string; frequency?: string; interval?: number }[] = [
  { value: 'none', label: 'Does not repeat' },
  { value: 'daily', label: 'Daily', frequency: 'daily' },
  { value: 'weekday', label: 'Every weekday (Mon–Fri)', frequency: 'weekday' },
  { value: 'weekly', label: 'Weekly', frequency: 'weekly', interval: 1 },
  { value: 'biweekly', label: 'Every other week', frequency: 'weekly', interval: 2 },
  { value: 'every3weeks', label: 'Every 3 weeks', frequency: 'weekly', interval: 3 },
  { value: 'every4weeks', label: 'Every 4 weeks', frequency: 'weekly', interval: 4 },
  { value: 'monthly_date', label: 'Monthly (same date)', frequency: 'monthly_date' },
  { value: 'monthly_weekday', label: 'Monthly (e.g. 2nd Friday)', frequency: 'monthly_weekday' },
];

const WEEKDAY_LABELS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

interface CalendarDay {
  date: Date;
  dateStr: string;
  isCurrentMonth: boolean;
  isToday: boolean;
  practices: Practice[];
  events: Event[];
}

type ViewMode = 'month' | 'week' | 'schedule';

interface TeamCalendarViewProps {
  teamId?: number;           // Optional: locks to specific team
  teamName?: string;         // Team name for display (when teamId is set)
  showTeamFilter?: boolean;  // Show team dropdown (default: true)
  showAddEvent?: boolean;    // Show add event button (default: true)
  readOnly?: boolean;        // For parent/player view (default: false)
  title?: string;            // Custom title (default: "Club Calendar")
}

const TeamCalendarView: React.FC<TeamCalendarViewProps> = ({
  teamId,
  teamName,
  showTeamFilter = true,
  showAddEvent = true,
  readOnly = false,
  title = "Club Calendar"
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const { user } = useAuth();
  const [showSubscriptionManager, setShowSubscriptionManager] = useState(false);
  const [currentDate, setCurrentDate] = useState(new Date());
  const [practices, setPractices] = useState<Practice[]>([]);
  const [events, setEvents] = useState<Event[]>([]);
  const [calendarDays, setCalendarDays] = useState<CalendarDay[]>([]);
  const [selectedTeamFilter, setSelectedTeamFilter] = useState<string>(teamId ? String(teamId) : 'all');
  const [viewMode, setViewMode] = useState<ViewMode>('month');
  const [weekDays, setWeekDays] = useState<CalendarDay[]>([]);
  const [showEventForm, setShowEventForm] = useState(false);
  const [selectedEvent, setSelectedEvent] = useState<Event | null>(null);
  const [venues, setVenues] = useState<any[]>([]);
  const [allTeams, setAllTeams] = useState<any[]>([]);
  // Teams the current user coaches (or has any team-scoped role on). Powers
  // the "My Teams" dropdown option and the coach default-view behavior.
  // Empty array for users who don't coach any teams (admin-only, parents).
  const [myTeamIds, setMyTeamIds] = useState<number[]>([]);
  const [myTeamsDefaultApplied, setMyTeamsDefaultApplied] = useState(false);
  const [sendInvites, setSendInvites] = useState(true);
  const [sendUpdates, setSendUpdates] = useState(true);
  const [changesSummary, setChangesSummary] = useState<string>('');
  const [showAttendanceModal, setShowAttendanceModal] = useState(false);
  const [attendanceEventId, setAttendanceEventId] = useState<number | null>(null);
  // Recurring-practice scheduler: reuses the same PracticeScheduler component
  // surfaced from the teams page. `scheduleTeam` holds the {id, name} the
  // scheduler is opened for; `showSchedulePicker` shows a team chooser first
  // when the calendar isn't already scoped to a single team.
  const [scheduleTeam, setScheduleTeam] = useState<{ id: number; name: string } | null>(null);
  const [showSchedulePicker, setShowSchedulePicker] = useState(false);
  const [rsvpSummary, setRsvpSummary] = useState<{ total: number; responded: number } | null>(null);
  const [rsvpList, setRsvpList] = useState<{ athlete_id: number; athlete_name: string; status: string | null }[]>([]);
  const [showRsvpBreakdown, setShowRsvpBreakdown] = useState(false);
  const [sendingReminders, setSendingReminders] = useState(false);
  const [eventFormData, setEventFormData] = useState<Event>({
    name: '',
    type: 'event',
    event_date: '',
    team_ids: teamId ? [teamId] : [],
    status: 'scheduled',
    opponent_name: ''
  });
  // Recurrence controls for the Add Event form (new events only — occurrences
  // are materialized server-side, so edits always target one occurrence).
  const [repeatOption, setRepeatOption] = useState('none');
  const [repeatWeekdays, setRepeatWeekdays] = useState<number[]>([]);
  const [repeatEndType, setRepeatEndType] = useState<'date' | 'count'>('count');
  const [repeatEndDate, setRepeatEndDate] = useState('');
  const [repeatCount, setRepeatCount] = useState(10);

  const resetRecurrence = () => {
    setRepeatOption('none');
    setRepeatWeekdays([]);
    setRepeatEndType('count');
    setRepeatEndDate('');
    setRepeatCount(10);
  };

  const isWeeklyRepeat = REPEAT_OPTIONS.find((o) => o.value === repeatOption)?.frequency === 'weekly';

  // Weekday index (0=Sun..6=Sat) of the form's date, parsed as a local date.
  const formDateWeekday = (): number | null => {
    if (!eventFormData.event_date) return null;
    const [y, m, d] = eventFormData.event_date.split('-').map(Number);
    if (!y || !m || !d) return null;
    return new Date(y, m - 1, d).getDay();
  };

  const buildRecurrencePayload = () => {
    const option = REPEAT_OPTIONS.find((o) => o.value === repeatOption);
    if (!option || !option.frequency) return undefined;
    return {
      frequency: option.frequency,
      interval: option.interval ?? 1,
      weekdays: option.frequency === 'weekly' ? repeatWeekdays : [],
      end_type: repeatEndType,
      end_date: repeatEndType === 'date' ? repeatEndDate : undefined,
      count: repeatEndType === 'count' ? repeatCount : undefined,
    };
  };

  // Fetch the list of teams this user coaches/manages so we can offer a
  // "My Teams" filter option and default coaches to that view. Skipped when
  // the calendar is locked to a specific team.
  useEffect(() => {
    if (teamId) return;
    const token = localStorage.getItem('auth_token');
    if (!token) return;
    fetch(`${API_URL}/api/coach/teams`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => r.ok ? r.json() : null)
      .then((data) => {
        if (!data) return;
        const ids = Array.isArray(data)
          ? data.map((t: any) => t.id).filter(Boolean)
          : Array.isArray(data?.teams)
            ? data.teams.map((t: any) => t.id).filter(Boolean)
            : [];
        setMyTeamIds(ids);
      })
      .catch(() => { /* non-fatal — just no My Teams option */ });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [teamId]);

  // Default coaches to "My Teams" once we know which teams they coach.
  // Runs once per mount (gated by myTeamsDefaultApplied) so a coach who
  // manually picks a different team doesn't get bounced back.
  useEffect(() => {
    if (teamId || myTeamsDefaultApplied) return;
    if (myTeamIds.length === 0) return;
    if (user?.activeRole?.role === 'coach') {
      setSelectedTeamFilter('my_teams');
    }
    setMyTeamsDefaultApplied(true);
  }, [myTeamIds, teamId, user, myTeamsDefaultApplied]);

  // Lock filter to teamId if provided
  useEffect(() => {
    if (teamId) {
      setSelectedTeamFilter(String(teamId));
    }
  }, [teamId]);

  // Load RSVP summary when opening an existing event
  useEffect(() => {
    if (!selectedEvent?.id || !showEventForm) {
      setRsvpSummary(null);
      setRsvpList([]);
      setShowRsvpBreakdown(false);
      return;
    }
    const RSVP_TRACKED_TYPES = ['practice', 'game', 'tournament', 'meeting', 'event'];
    if (!RSVP_TRACKED_TYPES.includes(selectedEvent.type)) {
      setRsvpSummary(null);
      setRsvpList([]);
      setShowRsvpBreakdown(false);
      return;
    }
    setShowRsvpBreakdown(false);
    const token = localStorage.getItem('auth_token');
    fetch(`${API_URL}/api/calendar-events-gateway.php?action=get&id=${selectedEvent.id}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.success && Array.isArray(data.event?.athletes_rsvp)) {
          const list = data.event.athletes_rsvp as {
            athlete_id: number;
            athlete_name: string;
            status: string | null;
          }[];
          const total = list.length;
          const responded = list.filter(
            (a) => a.status && a.status !== 'pending'
          ).length;
          setRsvpSummary({ total, responded });
          setRsvpList(list);
        } else {
          setRsvpSummary(null);
          setRsvpList([]);
        }
      })
      .catch(() => {
        setRsvpSummary(null);
        setRsvpList([]);
      });
  }, [API_URL, selectedEvent, showEventForm]);

  const handleSendReminders = async () => {
    if (!selectedEvent?.id || !rsvpSummary) return;
    const missing = rsvpSummary.total - rsvpSummary.responded;
    if (missing <= 0) return;
    if (!window.confirm(`Send RSVP reminder email to ${missing} non-respondent${missing === 1 ? '' : 's'}?`)) {
      return;
    }
    setSendingReminders(true);
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(
        `${API_URL}/api/calendar-events-gateway.php?action=send-rsvp-reminders`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
          },
          body: JSON.stringify({ event_id: selectedEvent.id }),
        }
      );
      const data = await response.json();
      if (response.ok && data.success) {
        const sent = data.reminders_sent ?? 0;
        const skipped = data.skipped ?? 0;
        alert(
          sent === 0
            ? data.message || 'No reminders sent.'
            : `Sent ${sent} reminder${sent === 1 ? '' : 's'}.${skipped > 0 ? ` Skipped ${skipped} (suppressed or invalid).` : ''}`
        );
      } else {
        alert(`Failed to send reminders: ${data.error || 'Unknown error'}`);
      }
    } catch (err) {
      alert('Failed to send reminders');
    } finally {
      setSendingReminders(false);
    }
  };

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
    fetchEvents();
    fetchVenues();
    fetchTeams();

    // Reload when window gets focus (in case practices were added in another tab/component)
    const handleFocus = () => {
      fetchEvents();
    };
    window.addEventListener('focus', handleFocus);

    return () => {
      window.removeEventListener('focus', handleFocus);
    };
  }, []);

  const fetchEvents = async () => {
    try {
      const response = await fetch(`${API_URL}/legacy/events-gateway.php`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` }
      });
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
      const response = await fetch(`${API_URL}/legacy/venues-gateway.php`, { headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` } });
      const data = await response.json();
      // Endpoint returns a bare JSON array of venues (not {venues:[...]}).
      // Parse defensively in case the shape ever changes.
      const list = Array.isArray(data) ? data : (data.venues || []);
      setVenues(list);
    } catch (error) {
      console.error('Error fetching venues:', error);
    }
  };

  const fetchTeams = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/legacy/teams-gateway.php`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await response.json();
      if (data.teams) {
        setAllTeams(data.teams);
      }
    } catch (error) {
      console.error('Error fetching teams:', error);
    }
  };


  useEffect(() => {
    if (viewMode === 'month') {
      generateCalendarDays();
    } else if (viewMode === 'week') {
      generateWeekDays();
    }
  }, [currentDate, practices, events, selectedTeamFilter, viewMode, allTeams, myTeamIds]);

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
      if (selectedTeamFilter === 'my_teams') {
        // Multi-team filter — keep events/practices for any team the user coaches.
        const myIdSet = new Set(myTeamIds);
        const myNameSet = new Set(
          allTeams.filter(t => myIdSet.has(t.id)).map(t => t.name)
        );
        dayPractices = dayPractices.filter(p =>
          (p.team_id != null && myIdSet.has(p.team_id)) || (p.team_name && myNameSet.has(p.team_name))
        );
        dayEvents = dayEvents.filter(e => {
          if (e.teams && e.teams.length > 0) {
            return e.teams.some(t => myIdSet.has(t.id));
          }
          return !!e.team_name && myNameSet.has(e.team_name);
        });
      } else if (selectedTeamFilter !== 'all') {
        const filterTeamId = parseInt(selectedTeamFilter);
        const filterTeam = allTeams.find(t => t.id === filterTeamId);
        const filterTeamName = filterTeam?.name;

        dayPractices = dayPractices.filter(p => p.team_name === filterTeamName || p.team_id === filterTeamId);
        dayEvents = dayEvents.filter(e => {
          // Check if event has this team in its teams array
          if (e.teams && e.teams.length > 0) {
            return e.teams.some(t => t.id === filterTeamId);
          }
          // Fallback to team_name match
          return e.team_name === filterTeamName;
        });
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

      if (selectedTeamFilter === 'my_teams') {
        const myIdSet = new Set(myTeamIds);
        const myNameSet = new Set(
          allTeams.filter(t => myIdSet.has(t.id)).map(t => t.name)
        );
        dayPractices = dayPractices.filter(p =>
          (p.team_id != null && myIdSet.has(p.team_id)) || (p.team_name && myNameSet.has(p.team_name))
        );
        dayEvents = dayEvents.filter(e => {
          if (e.teams && e.teams.length > 0) {
            return e.teams.some(t => myIdSet.has(t.id));
          }
          return !!e.team_name && myNameSet.has(e.team_name);
        });
      } else if (selectedTeamFilter !== 'all') {
        const filterTeamId = parseInt(selectedTeamFilter);
        const filterTeam = allTeams.find(t => t.id === filterTeamId);
        const filterTeamName = filterTeam?.name;

        dayPractices = dayPractices.filter(p => p.team_name === filterTeamName || p.team_id === filterTeamId);
        dayEvents = dayEvents.filter(e => {
          if (e.teams && e.teams.length > 0) {
            return e.teams.some(t => t.id === filterTeamId);
          }
          return e.team_name === filterTeamName;
        });
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

  // Color-code events by their type so the calendar reads at a glance.
  // Each event_type gets a distinct background / border / text treatment.
  // Falls back to a neutral style for unknown/legacy types.
  const getEventTypeStyle = (type?: Event['type']) => {
    switch (type) {
      case 'game':
        return 'bg-red-100 text-red-800 border-red-300';
      case 'practice':
        return 'bg-blue-100 text-blue-800 border-blue-300';
      case 'tournament':
        return 'bg-purple-100 text-purple-800 border-purple-300';
      case 'meeting':
        return 'bg-amber-100 text-amber-800 border-amber-300';
      case 'event':
        return 'bg-green-100 text-green-800 border-green-300';
      case 'other':
      default:
        return 'bg-gray-100 text-gray-800 border-gray-300';
    }
  };

  // Human-readable label for an event type (used in the legend).
  const eventTypeLabel = (type: Event['type']) => {
    switch (type) {
      case 'game': return 'Game';
      case 'practice': return 'Practice';
      case 'tournament': return 'Tournament';
      case 'meeting': return 'Meeting';
      case 'event': return 'Event';
      case 'other':
      default: return 'Other';
    }
  };

  // Define team colors
  const getTeamColor = (teamName: string) => {
    const colors = [
      'bg-blue-100 text-blue-800 border-blue-300',
      'bg-green-100 text-green-800 border-green-300',
      'bg-purple-100 text-purple-800 border-purple-300',
      'bg-orange-100 text-orange-800 border-orange-300',
      'bg-pink-100 text-pink-800 border-pink-300',
    ];
    const teamIndex = allTeams.findIndex(t => t.name === teamName);
    const index = (teamIndex >= 0 ? teamIndex : 0) % colors.length;
    return colors[index];
  };

  const handleDateClick = (dateStr: string) => {
    if (readOnly) return;
    setEventFormData({
      name: '',
      type: 'event',
      event_date: dateStr,
      team_ids: teamId ? [teamId] : [],
      status: 'scheduled',
      opponent_name: ''
    });
    setSelectedEvent(null);
    resetRecurrence();
    setShowEventForm(true);
  };

  const handleEventClick = (event: Event) => {
    if (readOnly) return;
    setSelectedEvent(event);
    // Convert teams array to team_ids for the form
    const formData = {
      ...event,
      team_ids: event.teams ? event.teams.map(t => t.id) : [],
      opponent_name: event.opponent_name || ''
    };
    setEventFormData(formData);
    setChangesSummary('');
    setSendUpdates(true);
    resetRecurrence();
    setShowEventForm(true);
  };

  const handleEventSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    console.log('Form submitted with data:', eventFormData);

    try {
      const url = selectedEvent
        ? `${API_URL}/legacy/events-gateway.php?id=${selectedEvent.id}`
        : `${API_URL}/legacy/events-gateway.php`;

      console.log('Sending request to:', url);
      console.log('Request method:', selectedEvent ? 'PUT' : 'POST');

      // Include invite/update flags in the request
      const requestData = {
        ...eventFormData,
        send_invites: !selectedEvent && sendInvites,  // Send invites only for new events
        send_updates: selectedEvent && sendUpdates,    // Send updates only when editing
        // Recurrence applies to new events only; the backend expands it into
        // one calendar_events row per occurrence.
        recurrence: !selectedEvent ? buildRecurrencePayload() : undefined
      };

      const response = await fetch(url, {
        method: selectedEvent ? 'PUT' : 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`
        },
        body: JSON.stringify(requestData)
      });

      console.log('Response status:', response.status);
      const responseData = await response.json();
      console.log('Response data:', responseData);

      if (response.ok) {
        console.log('Event saved successfully');

        if (responseData.count && responseData.count > 1) {
          const inviteNote = responseData.invites?.sent > 0
            ? `\n\nSeries invite emailed to ${responseData.invites.sent} recipient(s) — one email covering all dates.`
            : responseData.invites?.message
              ? `\n\n${responseData.invites.message}`
              : '';
          alert(`${responseData.message || `Created ${responseData.count} events.`}${inviteNote}`);
        } else
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
          team_ids: teamId ? [teamId] : [],
          status: 'scheduled',
          opponent_name: ''
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

    // For a recurring event, offer to remove the rest of its series too
    // (this occurrence and everything after it in the group).
    let series = false;
    if (selectedEvent?.recurrence_group_id) {
      series = window.confirm(
        `This event repeats (${selectedEvent.recurrence_rule || 'recurring series'}).\n\n` +
        'OK = delete this event AND all later events in the series\n' +
        'Cancel = delete only this event'
      );
    }

    try {
      const response = await fetch(
        `${API_URL}/legacy/events-gateway.php?id=${eventId}${series ? '&series=1' : ''}`,
        {
          method: 'DELETE',
          headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` }
        }
      );

      if (response.ok) {
        setShowEventForm(false);
        fetchEvents();
      }
    } catch (error) {
      console.error('Error deleting event:', error);
    }
  };

  // Open the recurring-practice scheduler. If the calendar is already scoped
  // to a single team (locked teamId prop, or the filter is set to a specific
  // team), open the scheduler directly for that team. Otherwise show a small
  // picker so the coach can pick which team the practices are for. Reuses the
  // teams already loaded in allTeams — no extra fetch.
  const handleSchedulePractices = () => {
    // Locked to a single team via prop.
    if (teamId) {
      const t = allTeams.find(team => team.id === teamId);
      setScheduleTeam({ id: teamId, name: t?.name || teamName || `Team ${teamId}` });
      return;
    }
    // Filter narrowed to one specific team.
    if (selectedTeamFilter !== 'all' && selectedTeamFilter !== 'my_teams') {
      const filterTeamId = parseInt(selectedTeamFilter);
      const t = allTeams.find(team => team.id === filterTeamId);
      if (t) {
        setScheduleTeam({ id: t.id, name: t.name });
        return;
      }
    }
    // Multiple teams in play — let the coach choose.
    setShowSchedulePicker(true);
  };

  // Get filtered teams for display
  const displayTeams = teamId ? allTeams.filter(t => t.id === teamId) : allTeams;

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold text-brand-primary uppercase tracking-wide">{title}</h1>
          <p className="text-gray-600 mt-2">
            {teamId && teamName ? `View ${teamName} practices and events` : 'View all club practices and events'}
          </p>
        </div>
        <div className="flex gap-2 w-full sm:w-auto">
          {!readOnly && (
            <button
              onClick={() => setShowSubscriptionManager(true)}
              className="bg-white text-brand-primary border border-brand-primary rounded-md px-4 py-2 hover:bg-gray-50 font-semibold uppercase w-full sm:w-auto text-sm"
            >
              Subscriptions
            </button>
          )}
          {showAddEvent && !readOnly && (
            <button
              onClick={handleSchedulePractices}
              className="bg-white text-brand-primary border border-brand-primary rounded-md px-4 py-2 hover:bg-gray-50 font-semibold uppercase w-full sm:w-auto text-sm"
            >
              Schedule Practices
            </button>
          )}
          {showAddEvent && !readOnly && (
            <button
              onClick={() => handleDateClick(new Date().toISOString().split('T')[0])}
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary font-semibold uppercase w-full sm:w-auto"
            >
              + Add Event
            </button>
          )}
        </div>
      </div>

      {/* Controls */}
      <div className="bg-white border border-brand-secondary rounded-md p-4 sm:p-6 mb-6">
        {/* View Mode Selector */}
        <div className="flex justify-center mb-4">
          <div className="inline-flex border border-brand-secondary rounded-md w-full sm:w-auto">
            <button
              onClick={() => setViewMode('month')}
              className={`flex-1 sm:flex-none px-4 sm:px-6 py-2 uppercase font-medium text-sm ${
                viewMode === 'month' ? 'bg-brand-primary text-white' : 'bg-white text-brand-primary'
              }`}
            >
              Month
            </button>
            <button
              onClick={() => setViewMode('week')}
              className={`flex-1 sm:flex-none px-4 sm:px-6 py-2 uppercase font-medium text-sm border-l border-brand-secondary ${
                viewMode === 'week' ? 'bg-brand-primary text-white' : 'bg-white text-brand-primary'
              }`}
            >
              Week
            </button>
            <button
              onClick={() => setViewMode('schedule')}
              className={`flex-1 sm:flex-none px-4 sm:px-6 py-2 uppercase font-medium text-sm border-l border-brand-secondary ${
                viewMode === 'schedule' ? 'bg-brand-primary text-white' : 'bg-white text-brand-primary'
              }`}
            >
              Schedule
            </button>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4">
          <div className="flex items-center space-x-2 sm:space-x-4 overflow-x-auto">
            {viewMode !== 'schedule' && (
              <>
                <button
                  onClick={handlePreviousPeriod}
                  className="text-brand-primary hover:bg-gray-100 p-2 flex-shrink-0"
                >
                  &larr;
                </button>
                <h2 className="text-base sm:text-xl font-bold text-brand-primary whitespace-nowrap">
                  {viewMode === 'week'
                    ? `Week of ${weekDays[0]?.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${weekDays[6]?.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
                    : `${monthNames[currentDate.getMonth()]} ${currentDate.getFullYear()}`
                  }
                </h2>
                <button
                  onClick={handleNextPeriod}
                  className="text-brand-primary hover:bg-gray-100 p-2 flex-shrink-0"
                >
                  &rarr;
                </button>
              </>
            )}
            {viewMode === 'schedule' && (
              <h2 className="text-base sm:text-xl font-bold text-brand-primary uppercase">All Scheduled Practices</h2>
            )}
            <button
              onClick={handleToday}
              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-1 hover:bg-gray-100 uppercase text-sm flex-shrink-0"
            >
              Today
            </button>
          </div>

          {showTeamFilter && (
            <div className="flex items-center gap-2 sm:space-x-4">
              <label className="text-brand-primary font-medium text-sm whitespace-nowrap">Filter by team:</label>
              <select
                value={selectedTeamFilter}
                onChange={(e) => setSelectedTeamFilter(e.target.value)}
                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-1 focus:outline-none focus:border-brand-accent min-w-0 flex-1 sm:flex-none"
              >
                <option value="all">All Teams</option>
                {myTeamIds.length > 0 && (
                  <option value="my_teams">My Teams</option>
                )}
                {allTeams.map(team => (
                  <option key={team.id} value={team.id}>{team.name}</option>
                ))}
              </select>
            </div>
          )}
        </div>

        {/* Calendar Views */}
        {viewMode === 'month' && (
          <div className="border border-brand-secondary rounded-md overflow-x-auto">
            <div className="min-w-[640px]">
            {/* Day Headers */}
            <div className="grid grid-cols-7 bg-brand-primary text-white">
              {dayNames.map(day => (
                <div key={day} className="p-2 text-center font-bold uppercase text-sm border-r border-brand-primary last:border-r-0">
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
                    day.isToday ? 'text-brand-primary font-bold' :
                    day.isCurrentMonth ? 'text-gray-700' : 'text-gray-400'
                  }`}>
                    {day.date.getDate()}
                  </div>
                  <div className="space-y-1">
                    {day.events.slice(0, 2).map((event, eIndex) => (
                      <div
                        key={`e-${eIndex}`}
                        className={`text-xs p-1 border ${getEventTypeStyle(event.type)} ${event.subscription_id ? 'border-l-4 border-l-teal-400' : ''}`}
                        title={`${eventTypeLabel(event.type)}: ${event.name}${event.opponent_name ? ` vs ${event.opponent_name}` : ''}`}
                        onClick={(e) => {
                          e.stopPropagation();
                          handleEventClick(event);
                        }}
                      >
                        <div className="font-medium truncate">{event.name}</div>
                        {event.opponent_name && <div className="text-xs truncate">vs {event.opponent_name}</div>}
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
          </div>
        )}

        {viewMode === 'week' && (
          <div className="border border-brand-secondary rounded-md overflow-x-auto">
            <div className="min-w-[640px]">
            {/* Day Headers */}
            <div className="grid grid-cols-7 bg-brand-primary text-white">
              {weekDays.map((day, index) => (
                <div key={index} className="p-2 text-center border-r border-brand-primary last:border-r-0">
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
                            className={`p-2 border cursor-pointer ${getEventTypeStyle(event.type)} ${event.subscription_id ? 'border-l-4 border-l-teal-400' : ''}`}
                            onClick={() => handleEventClick(event)}
                          >
                            <div className="font-bold text-sm">
                              {event.start_time && event.end_time ? `${event.start_time} - ${event.end_time}` : 'All Day'}
                            </div>
                            <div className="font-medium">
                              <span className="uppercase text-xs font-semibold opacity-70 mr-1">{eventTypeLabel(event.type)}</span>
                              {event.name}
                              {event.subscription_id && <span className="ml-1 text-xs text-teal-600 font-normal">(imported)</span>}
                            </div>
                            {event.opponent_name && <div className="text-xs opacity-75">vs {event.opponent_name}</div>}
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
          </div>
        )}

        {viewMode === 'schedule' && (
          <div className="border border-brand-secondary rounded-md bg-white">
            <div className="p-4">
              <div className="max-h-[min(600px,70vh)] overflow-y-auto">
                {(() => {
                  let filteredPractices = practices;
                  if (selectedTeamFilter !== 'all') {
                    const filterTeamId = parseInt(selectedTeamFilter);
                    const filterTeam = allTeams.find(t => t.id === filterTeamId);
                    const filterTeamName = filterTeam?.name;
                    filteredPractices = practices.filter(p => p.team_name === filterTeamName || p.team_id === filterTeamId);
                  }

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
                          <div className="bg-brand-primary text-white px-4 py-2 font-bold uppercase mt-4 first:mt-0">
                            {monthYear}
                          </div>
                        )}
                        <div className="border-b border-gray-200 p-4 hover:bg-gray-50 grid grid-cols-12 gap-4">
                          <div className="col-span-2">
                            <div className="font-bold text-brand-primary">
                              {practiceDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })}
                            </div>
                          </div>
                          <div className="col-span-2">
                            <div className="text-brand-primary">
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
                              Facility {practice.venue_id}
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

        {/* Event Type Legend */}
        <div className="mt-6">
          <h3 className="text-brand-primary font-bold uppercase mb-2">Event Types</h3>
          <div className="flex flex-wrap gap-2">
            {(['game', 'practice', 'tournament', 'meeting', 'event', 'other'] as Event['type'][]).map(t => (
              <div
                key={t}
                className={`px-3 py-1 text-sm border ${getEventTypeStyle(t)}`}
              >
                {eventTypeLabel(t)}
              </div>
            ))}
          </div>
        </div>

        {/* Legend */}
        {displayTeams.length > 0 && !teamId && (
          <div className="mt-6">
            <h3 className="text-brand-primary font-bold uppercase mb-2">Teams</h3>
            <div className="flex flex-wrap gap-2">
              {displayTeams.map(team => (
                <div
                  key={team.id}
                  className={`px-3 py-1 text-sm border ${getTeamColor(team.name)}`}
                >
                  {team.name}
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Stats */}
        <div className="mt-6 grid grid-cols-4 gap-4">
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-brand-primary">
              {events.filter(e => {
                if (selectedTeamFilter === 'all') return true;
                const filterTeamId = parseInt(selectedTeamFilter);
                if (e.teams && e.teams.length > 0) {
                  return e.teams.some(t => t.id === filterTeamId);
                }
                return false;
              }).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">Total Events</div>
          </div>
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-brand-primary">
              {practices.filter(p => {
                if (selectedTeamFilter === 'all') return true;
                const filterTeamId = parseInt(selectedTeamFilter);
                const filterTeam = allTeams.find(t => t.id === filterTeamId);
                return p.team_name === filterTeam?.name || p.team_id === filterTeamId;
              }).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">Total Practices</div>
          </div>
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-brand-primary">
              {[...events, ...practices].filter(item => {
                const itemDate = new Date('event_date' in item ? item.event_date : item.date);
                const inMonth = itemDate.getMonth() === currentDate.getMonth() &&
                       itemDate.getFullYear() === currentDate.getFullYear();
                if (!inMonth) return false;
                if (selectedTeamFilter === 'all') return true;
                const filterTeamId = parseInt(selectedTeamFilter);
                const filterTeam = allTeams.find(t => t.id === filterTeamId);
                if ('teams' in item && item.teams) {
                  return item.teams.some(t => t.id === filterTeamId);
                }
                return item.team_name === filterTeam?.name;
              }).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">This Month</div>
          </div>
          <div className="bg-gray-50 border border-gray-300 p-4">
            <div className="text-2xl font-bold text-brand-primary">
              {[...events, ...practices].filter(item => {
                const itemDate = new Date('event_date' in item ? item.event_date : item.date);
                const todayDate = new Date();
                todayDate.setHours(0, 0, 0, 0);
                if (itemDate < todayDate) return false;
                if (selectedTeamFilter === 'all') return true;
                const filterTeamId = parseInt(selectedTeamFilter);
                const filterTeam = allTeams.find(t => t.id === filterTeamId);
                if ('teams' in item && item.teams) {
                  return item.teams.some(t => t.id === filterTeamId);
                }
                return item.team_name === filterTeam?.name;
              }).length}
            </div>
            <div className="text-sm text-gray-600 uppercase">Upcoming</div>
          </div>
        </div>
      </div>

      {/* Event Form Modal */}
      {showEventForm && !readOnly && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center p-4 z-50 overflow-y-auto">
          <div className="bg-white border border-brand-secondary rounded-md max-w-2xl w-full my-8">
            <div className="border-b border-brand-secondary px-6 py-4">
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                {selectedEvent ? 'Edit Event' : 'Add New Event'}
              </h3>
            </div>

            <form onSubmit={handleEventSubmit} className="p-6">
              <div className="grid grid-cols-2 gap-4 mb-4">
                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Event Name *
                  </label>
                  <input
                    type="text"
                    value={eventFormData.name}
                    onChange={(e) => setEventFormData({ ...eventFormData, name: e.target.value })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    required
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Type *
                  </label>
                  <select
                    value={eventFormData.type}
                    onChange={(e) => setEventFormData({ ...eventFormData, type: e.target.value as any })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
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
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Date *
                  </label>
                  <input
                    type="date"
                    value={eventFormData.event_date}
                    onChange={(e) => setEventFormData({ ...eventFormData, event_date: e.target.value })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    required
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Status
                  </label>
                  <select
                    value={eventFormData.status}
                    onChange={(e) => setEventFormData({ ...eventFormData, status: e.target.value as any })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  >
                    <option value="scheduled">Scheduled</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="postponed">Postponed</option>
                    <option value="completed">Completed</option>
                  </select>
                </div>

                {/* Repeat — new events only; occurrences are created as individual events */}
                {!selectedEvent && (
                  <div className="col-span-2 border border-gray-200 rounded-md p-3 bg-gray-50">
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                          Repeat
                        </label>
                        <select
                          value={repeatOption}
                          onChange={(e) => {
                            const value = e.target.value;
                            setRepeatOption(value);
                            // Weekly variants: default the day picker to the event date's weekday.
                            const freq = REPEAT_OPTIONS.find((o) => o.value === value)?.frequency;
                            if (freq === 'weekly' && repeatWeekdays.length === 0) {
                              const dow = formDateWeekday();
                              if (dow !== null) setRepeatWeekdays([dow]);
                            }
                          }}
                          className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        >
                          {REPEAT_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                          ))}
                        </select>
                      </div>

                      {repeatOption !== 'none' && (
                        <div>
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            Ends
                          </label>
                          <div className="flex items-center gap-2">
                            <select
                              value={repeatEndType}
                              onChange={(e) => setRepeatEndType(e.target.value as 'date' | 'count')}
                              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-2 py-2 focus:outline-none focus:border-brand-accent"
                            >
                              <option value="count">After</option>
                              <option value="date">On date</option>
                            </select>
                            {repeatEndType === 'count' ? (
                              <>
                                <input
                                  type="number"
                                  min={1}
                                  max={52}
                                  value={repeatCount}
                                  onChange={(e) => setRepeatCount(Math.max(1, Math.min(52, Number(e.target.value) || 1)))}
                                  className="w-20 bg-white text-brand-primary border border-brand-secondary rounded-md px-2 py-2 focus:outline-none focus:border-brand-accent"
                                />
                                <span className="text-sm text-gray-600">events</span>
                              </>
                            ) : (
                              <input
                                type="date"
                                value={repeatEndDate}
                                min={eventFormData.event_date || undefined}
                                onChange={(e) => setRepeatEndDate(e.target.value)}
                                required
                                className="bg-white text-brand-primary border border-brand-secondary rounded-md px-2 py-2 focus:outline-none focus:border-brand-accent"
                              />
                            )}
                          </div>
                        </div>
                      )}

                      {isWeeklyRepeat && (
                        <div className="col-span-2">
                          <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                            On days
                          </label>
                          <div className="flex gap-1.5">
                            {WEEKDAY_LABELS.map((label, dow) => {
                              const active = repeatWeekdays.includes(dow);
                              return (
                                <button
                                  key={dow}
                                  type="button"
                                  onClick={() =>
                                    setRepeatWeekdays((prev) =>
                                      prev.includes(dow) ? prev.filter((d) => d !== dow) : [...prev, dow].sort()
                                    )
                                  }
                                  className={`w-9 h-9 rounded-full text-xs font-semibold border transition-colors ${
                                    active
                                      ? 'bg-brand-primary text-white border-brand-primary'
                                      : 'bg-white text-brand-primary border-brand-secondary hover:bg-gray-100'
                                  }`}
                                >
                                  {label}
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      )}

                      {repeatOption !== 'none' && (
                        <p className="col-span-2 text-xs text-gray-500">
                          Each occurrence is created as its own event (max 52, within one year). With
                          invites on, each person gets one email that adds the whole repeating series
                          to their calendar.
                        </p>
                      )}
                    </div>
                  </div>
                )}

                {/* Series note when editing one occurrence of a recurring event */}
                {selectedEvent?.recurrence_group_id && (
                  <div className="col-span-2 text-xs text-gray-600 bg-blue-50 border border-blue-200 rounded-md px-3 py-2">
                    Part of a recurring series{selectedEvent.recurrence_rule ? ` — ${selectedEvent.recurrence_rule}` : ''}.
                    Changes here affect only this occurrence; deleting offers to remove the rest of the series.
                  </div>
                )}

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Start Time
                  </label>
                  <input
                    type="time"
                    value={eventFormData.start_time || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, start_time: e.target.value })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    End Time
                  </label>
                  <input
                    type="time"
                    value={eventFormData.end_time || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, end_time: e.target.value })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  />
                </div>

                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Teams {teamId ? '(Pre-selected)' : '(Hold Ctrl/Cmd to select multiple)'}
                  </label>
                  {!teamId && (
                    <div className="mb-2 flex gap-2">
                      <button
                        type="button"
                        onClick={() => {
                          const allTeamIds = allTeams.map(team => team.id);
                          setEventFormData({ ...eventFormData, team_ids: allTeamIds });
                        }}
                        className="px-3 py-1 text-xs bg-brand-primary text-white hover:bg-brand-primary uppercase font-medium"
                      >
                        Choose All
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setEventFormData({ ...eventFormData, team_ids: [] });
                        }}
                        className="px-3 py-1 text-xs border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100 uppercase font-medium"
                      >
                        Clear All
                      </button>
                    </div>
                  )}
                  <select
                    multiple
                    disabled={!!teamId}
                    value={eventFormData.team_ids?.map(id => id.toString()) || []}
                    onChange={(e) => {
                      const selectedIds = Array.from(e.target.selectedOptions, option => Number(option.value));
                      setEventFormData({ ...eventFormData, team_ids: selectedIds });
                    }}
                    className={`w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent min-h-[120px] ${teamId ? 'bg-gray-100' : ''}`}
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
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Facility
                  </label>
                  <select
                    value={eventFormData.venue_id || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, venue_id: e.target.value ? Number(e.target.value) : undefined })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  >
                    <option value="">No Facility</option>
                    {venues.map(venue => (
                      <option key={venue.id} value={venue.id}>{venue.name}</option>
                    ))}
                  </select>
                </div>

                <div className="col-span-2">
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Location (if not a facility)
                  </label>
                  <input
                    type="text"
                    value={eventFormData.location || ''}
                    onChange={(e) => setEventFormData({ ...eventFormData, location: e.target.value })}
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    placeholder="e.g., Away game at opponent's field"
                  />
                </div>

                {eventFormData.type === 'game' && (
                  <div className="col-span-2">
                    <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                      Opponent
                    </label>
                    <input
                      type="text"
                      value={eventFormData.opponent_name || ''}
                      onChange={(e) => setEventFormData({ ...eventFormData, opponent_name: e.target.value })}
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      placeholder="e.g., Springfield FC"
                    />
                  </div>
                )}
              </div>

              <div className="mb-4">
                <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                  Description
                </label>
                <textarea
                  value={eventFormData.description || ''}
                  onChange={(e) => setEventFormData({ ...eventFormData, description: e.target.value })}
                  className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                  rows={3}
                />
              </div>

              {/* Calendar Invite Options */}
              <div className="mb-4 border-t-2 border-gray-200 pt-4">
                <div className="text-brand-primary text-sm font-medium mb-3 uppercase">Calendar Invites</div>

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
                      className="w-4 h-4 text-brand-primary border border-brand-secondary rounded-md focus:ring-brand-accent"
                    />
                    <span className="text-brand-primary">Send calendar invites to all team members</span>
                  </label>
                )}

                {/* Update checkbox for existing events */}
                {selectedEvent && (
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={sendUpdates}
                      onChange={(e) => setSendUpdates(e.target.checked)}
                      className="w-4 h-4 text-brand-primary border border-brand-secondary rounded-md focus:ring-brand-accent"
                    />
                    <span className="text-brand-primary">Send update notifications to all invitees</span>
                  </label>
                )}

                <p className="text-sm text-gray-600 mt-2">
                  {!selectedEvent
                    ? 'Calendar invites will be sent to all athletes, crew, and coaches associated with the selected teams.'
                    : 'Update notifications will be sent to everyone who received the original invite.'}
                </p>
              </div>

              <div className="flex flex-col gap-3">
                {selectedEvent && (
                  <div className="flex flex-wrap gap-2">
                    <button
                      type="button"
                      onClick={() => {
                        setAttendanceEventId(selectedEvent.id!);
                        setShowAttendanceModal(true);
                        setShowEventForm(false);
                      }}
                      className="px-4 py-2 border border-green-200 rounded-md text-green-600 hover:bg-green-50 font-semibold uppercase"
                    >
                      Take Attendance
                    </button>
                    {rsvpSummary && (() => {
                      const missing = rsvpSummary.total - rsvpSummary.responded;
                      const allResponded = missing <= 0 || rsvpSummary.total === 0;
                      return (
                        <button
                          type="button"
                          onClick={handleSendReminders}
                          disabled={allResponded || sendingReminders}
                          className={`px-4 py-2 border rounded-md font-semibold uppercase ${
                            allResponded
                              ? 'border-gray-200 text-gray-400 cursor-not-allowed'
                              : 'border-blue-200 text-blue-600 hover:bg-blue-50'
                          } ${sendingReminders ? 'opacity-60 cursor-wait' : ''}`}
                          title={
                            rsvpSummary.total === 0
                              ? 'No athletes on this event'
                              : allResponded
                              ? 'All athletes have responded'
                              : `${missing} of ${rsvpSummary.total} have not RSVPed`
                          }
                        >
                          {sendingReminders
                            ? 'Sending…'
                            : allResponded
                            ? `RSVP: ${rsvpSummary.responded}/${rsvpSummary.total} ✓`
                            : `Remind ${missing} (${rsvpSummary.responded}/${rsvpSummary.total} responded)`}
                        </button>
                      );
                    })()}
                    {rsvpSummary && rsvpSummary.total > 0 && (
                      <button
                        type="button"
                        onClick={() => setShowRsvpBreakdown((v) => !v)}
                        className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100 font-semibold uppercase"
                        title="Show who has and hasn't responded"
                      >
                        {showRsvpBreakdown ? 'Hide' : 'View'} RSVPs ({rsvpSummary.responded}/{rsvpSummary.total})
                      </button>
                    )}
                  </div>
                )}
                <div className="flex justify-between items-center pt-3 border-t border-gray-200">
                  <div>
                    {selectedEvent && (
                      <button
                        type="button"
                        onClick={() => handleDeleteEvent(selectedEvent.id!)}
                        className="px-4 py-2 border border-red-200 rounded-md text-red-600 hover:bg-red-50 font-semibold uppercase"
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
                          team_ids: teamId ? [teamId] : [],
                          status: 'scheduled'
                        });
                      }}
                      className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100 font-semibold uppercase"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      className="px-4 py-2 bg-brand-primary text-white border border-brand-secondary rounded-md hover:bg-brand-primary font-semibold uppercase"
                    >
                      {selectedEvent ? 'Update' : 'Create'} Event
                    </button>
                  </div>
                </div>
              </div>

              {selectedEvent && showRsvpBreakdown && rsvpList.length > 0 && (
                <div className="mt-4 border border-brand-secondary rounded-md p-4 bg-gray-50">
                  {(() => {
                    const groups: { key: string; label: string }[] = [
                      { key: 'attending', label: 'Attending' },
                      { key: 'not_attending', label: 'Not attending' },
                      { key: 'maybe', label: 'Maybe' },
                      { key: 'no_response', label: 'No response' },
                    ];
                    const bucket = (status: string | null) => {
                      if (status === 'attending' || status === 'not_attending' || status === 'maybe') {
                        return status;
                      }
                      // null and 'pending' (and any unknown value) count as no response
                      return 'no_response';
                    };
                    return (
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {groups.map((g) => {
                          const members = rsvpList.filter((a) => bucket(a.status) === g.key);
                          return (
                            <div key={g.key}>
                              <h4 className="text-brand-primary text-sm font-semibold uppercase mb-1">
                                {g.label} ({members.length})
                              </h4>
                              {members.length === 0 ? (
                                <p className="text-sm text-gray-400">—</p>
                              ) : (
                                <ul className="text-sm text-brand-primary space-y-0.5">
                                  {members.map((a) => (
                                    <li key={a.athlete_id}>{a.athlete_name}</li>
                                  ))}
                                </ul>
                              )}
                            </div>
                          );
                        })}
                      </div>
                    );
                  })()}
                </div>
              )}
            </form>
          </div>
        </div>
      )}

      {/* Attendance Modal */}
      {showSubscriptionManager && user?.activeRole?.scope_id && (
        <CalendarSubscriptionManager
          clubId={user.activeRole.scope_id}
          teamId={teamId}
          teams={allTeams.map(t => ({ id: t.id, name: t.name }))}
          onClose={() => setShowSubscriptionManager(false)}
          onSync={() => fetchEvents()}
        />
      )}

      {showAttendanceModal && attendanceEventId && selectedEvent && (
        <AttendanceModal
          eventId={attendanceEventId}
          eventName={selectedEvent.name}
          eventDate={selectedEvent.event_date}
          onClose={() => {
            setShowAttendanceModal(false);
            setAttendanceEventId(null);
          }}
          onSave={() => {
            fetchEvents();
          }}
        />
      )}

      {/* Team picker for scheduling recurring practices (shown when the
          calendar isn't already scoped to a single team) */}
      {showSchedulePicker && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center p-4 z-50 overflow-y-auto">
          <div className="bg-white border border-brand-secondary rounded-md max-w-md w-full my-8">
            <div className="border-b border-brand-secondary px-6 py-4">
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                Schedule Practices
              </h3>
            </div>
            <div className="p-6">
              <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                Select a team
              </label>
              <select
                defaultValue=""
                onChange={(e) => {
                  const id = parseInt(e.target.value);
                  const t = allTeams.find(team => team.id === id);
                  if (t) {
                    setScheduleTeam({ id: t.id, name: t.name });
                    setShowSchedulePicker(false);
                  }
                }}
                className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
              >
                <option value="" disabled>Choose a team…</option>
                {allTeams.map(team => (
                  <option key={team.id} value={team.id}>{team.name}</option>
                ))}
              </select>
              <div className="flex justify-end mt-6">
                <button
                  type="button"
                  onClick={() => setShowSchedulePicker(false)}
                  className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100 font-semibold uppercase"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Recurring-practice scheduler (same component used on the teams page) */}
      {scheduleTeam && (
        <PracticeScheduler
          team={scheduleTeam}
          onClose={() => {
            setScheduleTeam(null);
            // Refresh so newly created recurring practices appear immediately.
            fetchEvents();
          }}
        />
      )}
    </div>
  );
};

export default TeamCalendarView;

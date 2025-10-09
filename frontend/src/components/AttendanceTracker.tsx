import React, { useState, useEffect } from 'react';

interface AttendanceTrackerProps {
  team: { id: number; name: string };
}

interface AttendanceRecord {
  team_member_id: number;
  user_id: number;
  first_name: string;
  last_name: string;
  status: 'present' | 'absent' | 'late' | 'excused';
  notes?: string;
}

interface Event {
  id: number;
  title: string;
  start_datetime: string;
  present_count?: number;
  absent_count?: number;
  late_count?: number;
}

const AttendanceTracker: React.FC<AttendanceTrackerProps> = ({ team }) => {
  const [events, setEvents] = useState<Event[]>([]);
  const [selectedEvent, setSelectedEvent] = useState<Event | null>(null);
  const [roster, setRoster] = useState<any[]>([]);
  const [attendance, setAttendance] = useState<Record<number, AttendanceRecord>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    fetchEvents();
    fetchRoster();
  }, [team.id]);

  useEffect(() => {
    if (selectedEvent) {
      fetchEventAttendance(selectedEvent.id);
    }
  }, [selectedEvent]);

  const fetchEvents = async () => {
    try {
      const response = await fetch(`http://localhost:8888/teamselevated-backend/api/coach/teams/${team.id}/attendance`);
      const data = await response.json();
      setEvents(data);
    } catch (error) {
      console.error('Error fetching events:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchRoster = async () => {
    try {
      const response = await fetch(`http://localhost:8888/teamselevated-backend/api/coach/teams/${team.id}/roster`);
      const data = await response.json();
      setRoster(data);
    } catch (error) {
      console.error('Error fetching roster:', error);
    }
  };

  const fetchEventAttendance = async (eventId: number) => {
    try {
      const response = await fetch(
        `http://localhost:8888/teamselevated-backend/api/coach/teams/${team.id}/attendance?event_id=${eventId}`
      );
      const data = await response.json();

      const attendanceMap: Record<number, AttendanceRecord> = {};
      data.forEach((record: any) => {
        attendanceMap[record.team_member_id] = record;
      });
      setAttendance(attendanceMap);
    } catch (error) {
      console.error('Error fetching attendance:', error);
    }
  };

  const updateAttendance = (memberId: number, status: string) => {
    setAttendance(prev => ({
      ...prev,
      [memberId]: {
        ...prev[memberId],
        team_member_id: memberId,
        status: status as any
      }
    }));
  };

  const updateNotes = (memberId: number, notes: string) => {
    setAttendance(prev => ({
      ...prev,
      [memberId]: {
        ...prev[memberId],
        notes
      }
    }));
  };

  const saveAttendance = async () => {
    if (!selectedEvent) return;

    setSaving(true);
    try {
      const attendanceData = roster.map(player => ({
        team_member_id: player.id,
        status: attendance[player.id]?.status || 'absent',
        notes: attendance[player.id]?.notes || ''
      }));

      const response = await fetch(
        `http://localhost:8888/teamselevated-backend/api/coach/teams/${team.id}/attendance`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            event_id: selectedEvent.id,
            attendance: attendanceData
          })
        }
      );

      if (response.ok) {
        alert('Attendance saved successfully');
        fetchEvents();
      }
    } catch (error) {
      console.error('Error saving attendance:', error);
      alert('Failed to save attendance');
    } finally {
      setSaving(false);
    }
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'present': return 'bg-green-800 text-green-200';
      case 'absent': return 'bg-red-800 text-red-200';
      case 'late': return 'bg-yellow-800 text-yellow-200';
      case 'excused': return 'bg-blue-800 text-blue-200';
      default: return 'bg-forest-700 text-forest-200';
    }
  };

  const calculateAttendanceStats = () => {
    const stats = {
      present: 0,
      absent: 0,
      late: 0,
      excused: 0
    };

    roster.forEach(player => {
      const status = attendance[player.id]?.status || 'absent';
      stats[status]++;
    });

    return stats;
  };

  if (loading) {
    return <div className="text-center text-white py-12">Loading attendance data...</div>;
  }

  return (
    <div>
      <div className="mb-6">
        <h2 className="text-3xl font-bold text-white">Attendance Tracking</h2>
        <p className="text-forest-200 mt-2">{team.name}</p>
      </div>

      <div className="bg-forest-800 p-6 mb-6">
        <h3 className="text-xl font-bold text-white mb-4">Select Event</h3>
        <select
          className="w-full bg-forest-700 text-white px-4 py-2"
          onChange={(e) => {
            const event = events.find(ev => ev.id === parseInt(e.target.value));
            setSelectedEvent(event || null);
          }}
          value={selectedEvent?.id || ''}
        >
          <option value="">Select an event...</option>
          {events.map(event => (
            <option key={event.id} value={event.id}>
              {event.title} - {new Date(event.start_datetime).toLocaleDateString()}
              {event.present_count !== undefined && (
                ` (P:${event.present_count} A:${event.absent_count} L:${event.late_count})`
              )}
            </option>
          ))}
        </select>
      </div>

      {selectedEvent && (
        <>
          <div className="bg-forest-800 p-6 mb-6">
            <h3 className="text-xl font-bold text-white mb-4">Attendance Summary</h3>
            <div className="grid grid-cols-4 gap-4">
              {Object.entries(calculateAttendanceStats()).map(([status, count]) => (
                <div key={status} className={`p-4 ${getStatusColor(status)}`}>
                  <div className="text-2xl font-bold">{count}</div>
                  <div className="capitalize">{status}</div>
                </div>
              ))}
            </div>
          </div>

          <div className="bg-forest-800 overflow-hidden">
            <table className="min-w-full">
              <thead>
                <tr className="bg-forest-950">
                  <th className="px-6 py-3 text-left text-xs font-medium text-forest-200 uppercase">
                    Jersey
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-forest-200 uppercase">
                    Name
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-forest-200 uppercase">
                    Position
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-forest-200 uppercase">
                    Status
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-forest-200 uppercase">
                    Notes
                  </th>
                </tr>
              </thead>
              <tbody>
                {roster.map((player, index) => (
                  <tr
                    key={player.id}
                    className={index % 2 === 0 ? 'bg-forest-800' : 'bg-forest-700'}
                  >
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="text-2xl font-bold text-white">
                        {player.jersey_number || '-'}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-white">{player.name}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="text-forest-200">{player.primary_position}</span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <select
                        className={`px-3 py-1 ${getStatusColor(attendance[player.id]?.status || 'absent')}`}
                        value={attendance[player.id]?.status || 'absent'}
                        onChange={(e) => updateAttendance(player.id, e.target.value)}
                      >
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="late">Late</option>
                        <option value="excused">Excused</option>
                      </select>
                    </td>
                    <td className="px-6 py-4">
                      <input
                        type="text"
                        className="bg-forest-700 text-white px-2 py-1 w-full"
                        value={attendance[player.id]?.notes || ''}
                        onChange={(e) => updateNotes(player.id, e.target.value)}
                        placeholder="Notes..."
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-6 flex justify-end">
            <button
              onClick={saveAttendance}
              disabled={saving}
              className="bg-forest-500 text-white px-6 py-2 hover:bg-forest-400 disabled:bg-forest-700 font-semibold"
            >
              {saving ? 'Saving...' : 'Save Attendance'}
            </button>
          </div>
        </>
      )}
    </div>
  );
};

export default AttendanceTracker;
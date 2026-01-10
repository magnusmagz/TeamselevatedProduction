import React, { useState, useEffect, useCallback } from 'react';

interface Athlete {
  athlete_id: number;
  first_name: string;
  last_name: string;
  jersey_number?: number;
  primary_position?: string;
  team_id: number;
  team_name: string;
}

interface AttendanceRecord {
  athlete_id: number;
  status: 'present' | 'absent';
  notes?: string;
}

interface AttendanceSummary {
  present: number;
  absent: number;
  total: number;
  not_marked?: number;
}

interface EventInfo {
  id: number;
  name: string;
  type: string;
  event_date: string;
  start_time?: string;
  end_time?: string;
  status: string;
}

interface AttendanceModalProps {
  eventId: number;
  eventName: string;
  eventDate: string;
  onClose: () => void;
  onSave?: () => void;
}

const AttendanceModal: React.FC<AttendanceModalProps> = ({
  eventId,
  eventName,
  eventDate,
  onClose,
  onSave
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [athletes, setAthletes] = useState<Athlete[]>([]);
  const [attendance, setAttendance] = useState<Record<number, AttendanceRecord>>({});
  const [summary, setSummary] = useState<AttendanceSummary>({ present: 0, absent: 0, total: 0 });
  const [eventInfo, setEventInfo] = useState<EventInfo | null>(null);
  const [hasChanges, setHasChanges] = useState(false);
  const [initialAttendance, setInitialAttendance] = useState<Record<number, string>>({});

  // Fetch attendance data
  const fetchAttendance = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(
        `${API_URL}/api/event-attendance.php?action=get&event_id=${eventId}`
      );
      const data = await response.json();

      if (!data.success) {
        throw new Error(data.error || 'Failed to fetch attendance');
      }

      setEventInfo(data.event);
      setAthletes(data.athletes || []);
      setSummary(data.summary || { present: 0, absent: 0, total: 0 });

      // Initialize attendance - default all to present, then apply saved records
      const initialRecords: Record<number, AttendanceRecord> = {};
      const savedStatuses: Record<number, string> = {};

      // First, default everyone to present
      (data.athletes || []).forEach((athlete: Athlete) => {
        initialRecords[athlete.athlete_id] = {
          athlete_id: athlete.athlete_id,
          status: 'present'
        };
      });

      // Then apply any saved attendance records
      if (data.attendance) {
        Object.entries(data.attendance).forEach(([athleteId, record]: [string, any]) => {
          const id = parseInt(athleteId);
          initialRecords[id] = {
            athlete_id: id,
            status: record.status,
            notes: record.notes
          };
          savedStatuses[id] = record.status;
        });
      }

      setAttendance(initialRecords);
      setInitialAttendance(savedStatuses);
      setHasChanges(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load attendance');
      console.error('Attendance fetch error:', err);
    } finally {
      setLoading(false);
    }
  }, [API_URL, eventId]);

  useEffect(() => {
    fetchAttendance();
  }, [fetchAttendance]);

  // Toggle attendance status
  const toggleAttendance = (athleteId: number) => {
    setAttendance(prev => {
      const current = prev[athleteId]?.status || 'present';
      const newStatus = current === 'present' ? 'absent' : 'present';

      return {
        ...prev,
        [athleteId]: {
          ...prev[athleteId],
          athlete_id: athleteId,
          status: newStatus
        }
      };
    });
    setHasChanges(true);
  };

  // Mark all present
  const markAllPresent = () => {
    setAttendance(prev => {
      const updated: Record<number, AttendanceRecord> = {};
      athletes.forEach(athlete => {
        updated[athlete.athlete_id] = {
          ...prev[athlete.athlete_id],
          athlete_id: athlete.athlete_id,
          status: 'present'
        };
      });
      return updated;
    });
    setHasChanges(true);
  };

  // Mark all absent
  const markAllAbsent = () => {
    setAttendance(prev => {
      const updated: Record<number, AttendanceRecord> = {};
      athletes.forEach(athlete => {
        updated[athlete.athlete_id] = {
          ...prev[athlete.athlete_id],
          athlete_id: athlete.athlete_id,
          status: 'absent'
        };
      });
      return updated;
    });
    setHasChanges(true);
  };

  // Save attendance
  const handleSave = async () => {
    setSaving(true);
    setError(null);

    try {
      const attendanceRecords = Object.values(attendance);

      const response = await fetch(`${API_URL}/api/event-attendance.php?action=save`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          event_id: eventId,
          attendance: attendanceRecords,
          marked_by: null // Could get from auth context if needed
        })
      });

      const data = await response.json();

      if (!data.success) {
        throw new Error(data.error || 'Failed to save attendance');
      }

      setSummary(data.summary);
      setHasChanges(false);

      if (onSave) {
        onSave();
      }

      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save attendance');
      console.error('Attendance save error:', err);
    } finally {
      setSaving(false);
    }
  };

  // Calculate current summary
  const currentSummary = {
    present: Object.values(attendance).filter(a => a.status === 'present').length,
    absent: Object.values(attendance).filter(a => a.status === 'absent').length,
    total: athletes.length
  };

  // Format date for display
  const formatDate = (dateStr: string) => {
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center p-4 z-50 overflow-y-auto">
      <div className="bg-white border border-brand-secondary rounded-md max-w-3xl w-full my-8">
        {/* Header */}
        <div className="border-b border-brand-secondary px-6 py-4">
          <div className="flex justify-between items-start">
            <div>
              <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
                Take Attendance
              </h3>
              <p className="text-gray-600 mt-1">{eventName}</p>
              <p className="text-sm text-gray-500">{formatDate(eventDate)}</p>
            </div>
            <button
              onClick={onClose}
              className="text-gray-400 hover:text-gray-600 text-2xl leading-none"
            >
              &times;
            </button>
          </div>
        </div>

        {/* Content */}
        <div className="p-6">
          {loading ? (
            <div className="text-center py-12">
              <div className="text-brand-primary">Loading athletes...</div>
            </div>
          ) : error ? (
            <div className="bg-red-50 border border-red-200 text-red-700 p-4 rounded">
              {error}
              <button
                onClick={fetchAttendance}
                className="ml-4 text-red-600 underline hover:text-red-800"
              >
                Retry
              </button>
            </div>
          ) : athletes.length === 0 ? (
            <div className="text-center py-12 text-gray-500">
              No athletes found for this event. Make sure teams are assigned to the event.
            </div>
          ) : (
            <>
              {/* Summary Bar */}
              <div className="bg-gray-50 border border-gray-200 rounded-md p-4 mb-6">
                <div className="flex justify-between items-center">
                  <div className="flex gap-6">
                    <div>
                      <span className="text-2xl font-bold text-green-600">{currentSummary.present}</span>
                      <span className="text-sm text-gray-600 ml-2">Present</span>
                    </div>
                    <div>
                      <span className="text-2xl font-bold text-red-600">{currentSummary.absent}</span>
                      <span className="text-sm text-gray-600 ml-2">Absent</span>
                    </div>
                    <div>
                      <span className="text-2xl font-bold text-gray-600">{currentSummary.total}</span>
                      <span className="text-sm text-gray-600 ml-2">Total</span>
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <button
                      onClick={markAllPresent}
                      className="px-3 py-1 text-xs bg-green-100 text-green-700 border border-green-300 rounded hover:bg-green-200 uppercase font-medium"
                    >
                      All Present
                    </button>
                    <button
                      onClick={markAllAbsent}
                      className="px-3 py-1 text-xs bg-red-100 text-red-700 border border-red-300 rounded hover:bg-red-200 uppercase font-medium"
                    >
                      All Absent
                    </button>
                  </div>
                </div>
              </div>

              {/* Athletes List */}
              <div className="border border-gray-200 rounded-md overflow-hidden">
                <table className="min-w-full">
                  <thead>
                    <tr className="bg-brand-primary text-white">
                      <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">#</th>
                      <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Athlete</th>
                      <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Position</th>
                      <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Team</th>
                      <th className="px-4 py-3 text-center text-xs font-bold uppercase tracking-wider">Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200">
                    {athletes.map((athlete, index) => {
                      const record = attendance[athlete.athlete_id];
                      const status = record?.status || 'present';
                      const isPresent = status === 'present';

                      return (
                        <tr
                          key={athlete.athlete_id}
                          className={`hover:bg-gray-50 cursor-pointer ${
                            isPresent ? 'bg-white' : 'bg-red-50'
                          }`}
                          onClick={() => toggleAttendance(athlete.athlete_id)}
                        >
                          <td className="px-4 py-3 whitespace-nowrap">
                            <span className="text-sm font-medium text-gray-900">
                              {athlete.jersey_number || '-'}
                            </span>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap">
                            <span className="text-sm font-medium text-brand-primary">
                              {athlete.first_name} {athlete.last_name}
                            </span>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap">
                            <span className="text-sm text-gray-600">
                              {athlete.primary_position || '-'}
                            </span>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap">
                            <span className="text-sm text-gray-600">
                              {athlete.team_name}
                            </span>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-center">
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                toggleAttendance(athlete.athlete_id);
                              }}
                              className={`px-4 py-2 text-sm font-bold uppercase rounded transition-colors ${
                                isPresent
                                  ? 'bg-green-100 text-green-700 border border-green-300 hover:bg-green-200'
                                  : 'bg-red-100 text-red-700 border border-red-300 hover:bg-red-200'
                              }`}
                            >
                              {isPresent ? 'Present' : 'Absent'}
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Info text */}
              <p className="text-sm text-gray-500 mt-4">
                Click on a row or the status button to toggle attendance. All athletes default to Present.
              </p>
            </>
          )}
        </div>

        {/* Footer */}
        <div className="border-t border-brand-secondary px-6 py-4 bg-gray-50">
          <div className="flex justify-between items-center">
            <div className="text-sm text-gray-600">
              {hasChanges && <span className="text-amber-600 font-medium">Unsaved changes</span>}
            </div>
            <div className="flex gap-2">
              <button
                onClick={onClose}
                className="px-4 py-2 border border-brand-secondary rounded-md text-brand-primary hover:bg-gray-100 font-semibold uppercase"
              >
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={saving || athletes.length === 0}
                className="px-4 py-2 bg-brand-primary text-white border border-brand-secondary rounded-md hover:bg-brand-primary-hover font-semibold uppercase disabled:opacity-50"
              >
                {saving ? 'Saving...' : 'Save Attendance'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AttendanceModal;

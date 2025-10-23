import React, { useState, useEffect } from 'react';

interface Team {
  id: number;
  name: string;
}

interface Field {
  id: number;
  name: string;
  venue_id: number;
}

interface Venue {
  id: number;
  name: string;
  fields?: Field[];
}

interface TimeSlot {
  date: string;
  dayName: string;
  time: string;
  hour: number;
  fieldId: number;
  isAvailable: boolean;
  bookedBy?: string;
  isSelected: boolean;
}

interface SmartSchedulerProps {
  team: Team;
  onClose: () => void;
}

const SmartScheduler: React.FC<SmartSchedulerProps> = ({ team, onClose }) => {
  // State
  const [venues, setVenues] = useState<Venue[]>([]);
  const [selectedVenue, setSelectedVenue] = useState<number | null>(null);
  const [fields, setFields] = useState<Field[]>([]);
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [availability, setAvailability] = useState<{ [key: string]: TimeSlot[] }>({});
  const [selectedSlots, setSelectedSlots] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(false);
  const [showAvailability, setShowAvailability] = useState(false);

  // Time range for the grid (3pm to 8pm)
  const timeSlots = ['15:00', '16:00', '17:00', '18:00', '19:00'];
  const timeLabels = ['3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM', '7:00 PM'];

  useEffect(() => {
    fetchVenues();
    // Set default dates (this week)
    const today = new Date();
    const sunday = new Date(today);
    sunday.setDate(today.getDate() - today.getDay());
    const saturday = new Date(sunday);
    saturday.setDate(sunday.getDate() + 6);

    setStartDate(sunday.toISOString().split('T')[0]);
    setEndDate(saturday.toISOString().split('T')[0]);
  }, []);

  useEffect(() => {
    if (selectedVenue) {
      const venue = venues.find(v => v.id === selectedVenue);
      setFields(venue?.fields || []);
      if (startDate && endDate && venue?.fields) {
        loadAvailability();
      }
    }
  }, [selectedVenue, venues, startDate, endDate]);

  const fetchVenues = async () => {
    try {
      // Mock venues for demo
      const mockVenues = [
        {
          id: 1,
          name: 'Main Sports Complex',
          fields: [
            { id: 1, name: 'Field 1', venue_id: 1 },
            { id: 2, name: 'Field 2', venue_id: 1 },
            { id: 3, name: 'Field 3', venue_id: 1 }
          ]
        },
        {
          id: 2,
          name: 'Community Park',
          fields: [
            { id: 4, name: 'North Field', venue_id: 2 },
            { id: 5, name: 'South Field', venue_id: 2 }
          ]
        }
      ];
      setVenues(mockVenues);
    } catch (error) {
      console.error('Error fetching venues:', error);
    }
  };

  const loadAvailability = () => {
    setLoading(true);

    // Load existing practices from localStorage
    const existingPractices = JSON.parse(localStorage.getItem('teamPractices') || '[]');

    // Generate availability grid
    const availabilityGrid: { [key: string]: TimeSlot[] } = {};
    const start = new Date(startDate);
    const end = new Date(endDate);

    for (let date = new Date(start); date <= end; date.setDate(date.getDate() + 1)) {
      const dateStr = date.toISOString().split('T')[0];
      const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });

      availabilityGrid[dateStr] = [];

      // For each time slot
      timeSlots.forEach((time, timeIndex) => {
        // For each field
        fields.forEach(field => {
          // Check if this slot is already booked
          const isBooked = existingPractices.find((p: any) =>
            p.date === dateStr &&
            p.field_id === field.id &&
            p.venue_id === selectedVenue &&
            p.start_time <= time &&
            p.end_time > time
          );

          availabilityGrid[dateStr].push({
            date: dateStr,
            dayName,
            time,
            hour: parseInt(time.split(':')[0]),
            fieldId: field.id,
            isAvailable: !isBooked,
            bookedBy: isBooked ? isBooked.team_name : undefined,
            isSelected: false
          });
        });
      });
    }

    setAvailability(availabilityGrid);
    setShowAvailability(true);
    setLoading(false);
  };

  const toggleSlotSelection = (dateStr: string, fieldId: number, time: string) => {
    const slotKey = `${dateStr}-${fieldId}-${time}`;
    const newSelected = new Set(selectedSlots);

    if (newSelected.has(slotKey)) {
      newSelected.delete(slotKey);
    } else {
      newSelected.add(slotKey);
    }

    setSelectedSlots(newSelected);
  };

  const selectPattern = (dayOfWeek: number, time: string, fieldId: number) => {
    const newSelected = new Set(selectedSlots);

    Object.entries(availability).forEach(([dateStr, slots]) => {
      const date = new Date(dateStr);
      if (date.getDay() === dayOfWeek) {
        const slot = slots.find(s => s.time === time && s.fieldId === fieldId);
        if (slot && slot.isAvailable) {
          newSelected.add(`${dateStr}-${fieldId}-${time}`);
        }
      }
    });

    setSelectedSlots(newSelected);
  };

  const publishSchedule = () => {
    if (selectedSlots.size === 0) {
      alert('Please select at least one time slot');
      return;
    }

    // Convert selected slots to practices
    const existingPractices = JSON.parse(localStorage.getItem('teamPractices') || '[]');
    const newPractices: any[] = [];

    selectedSlots.forEach(slotKey => {
      const [dateStr, fieldId, time] = slotKey.split('-');
      const slot = availability[dateStr]?.find(s =>
        s.fieldId === parseInt(fieldId) && s.time === time
      );

      if (slot) {
        // Calculate end time (assuming 1.5 hour practices)
        const startHour = parseInt(time.split(':')[0]);
        const endTime = `${startHour + 1}:30`;

        newPractices.push({
          id: Date.now() + Math.random(),
          team_id: team.id,
          team_name: team.name,
          date: dateStr,
          day: slot.dayName,
          start_time: time,
          end_time: endTime,
          venue_id: selectedVenue,
          field_id: parseInt(fieldId),
          created_at: new Date().toISOString()
        });
      }
    });

    // Save to localStorage
    localStorage.setItem('teamPractices', JSON.stringify([...existingPractices, ...newPractices]));

    alert(`Successfully scheduled ${newPractices.length} practices for ${team.name}!`);
    onClose();
  };

  const getSlotStatus = (dateStr: string, fieldId: number, time: string) => {
    const slotKey = `${dateStr}-${fieldId}-${time}`;
    const slot = availability[dateStr]?.find(s => s.fieldId === fieldId && s.time === time);

    if (!slot) return 'loading';
    if (selectedSlots.has(slotKey)) return 'selected';
    if (!slot.isAvailable) return 'booked';
    return 'available';
  };

  const getSlotClass = (status: string) => {
    switch (status) {
      case 'selected':
        return 'bg-blue-500 text-white cursor-pointer border-2 border-blue-600';
      case 'booked':
        return 'bg-red-100 text-red-800 cursor-not-allowed border-2 border-red-300';
      case 'available':
        return 'bg-green-50 text-green-800 cursor-pointer hover:bg-green-100 border-2 border-green-300';
      default:
        return 'bg-gray-100 border-2 border-gray-300';
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 overflow-y-auto">
      <div className="bg-white border-2 border-forest-800 w-full max-w-7xl my-8">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center bg-forest-50">
          <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
            Smart Scheduler (Beta) - {team.name}
          </h3>
          <button
            onClick={onClose}
            className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
          >
            ×
          </button>
        </div>

        <div className="p-6">
          {/* Step 1: Setup */}
          <div className="bg-gray-50 border-2 border-forest-800 p-4 mb-6">
            <h4 className="text-forest-800 font-bold mb-4 uppercase">Step 1: Select Venue & Date Range</h4>

            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                  Venue
                </label>
                <select
                  value={selectedVenue || ''}
                  onChange={(e) => setSelectedVenue(Number(e.target.value))}
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none"
                >
                  <option value="">Select venue...</option>
                  {venues.map(venue => (
                    <option key={venue.id} value={venue.id}>
                      {venue.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                  Start Date
                </label>
                <input
                  type="date"
                  value={startDate}
                  onChange={(e) => setStartDate(e.target.value)}
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none"
                />
              </div>

              <div>
                <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                  End Date
                </label>
                <input
                  type="date"
                  value={endDate}
                  onChange={(e) => setEndDate(e.target.value)}
                  className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Step 2: Availability Grid */}
          {showAvailability && (
            <div className="border-2 border-forest-800 mb-6">
              <div className="bg-forest-800 text-white p-4">
                <h4 className="font-bold uppercase">Step 2: Select Available Time Slots</h4>
                <p className="text-sm mt-1">Click individual slots or use pattern selection</p>
              </div>

              <div className="p-4 bg-white">
                {/* Legend */}
                <div className="flex gap-4 mb-4 text-sm">
                  <span className="flex items-center gap-2">
                    <div className="w-4 h-4 bg-green-50 border-2 border-green-300"></div>
                    Available
                  </span>
                  <span className="flex items-center gap-2">
                    <div className="w-4 h-4 bg-blue-500 border-2 border-blue-600"></div>
                    Selected
                  </span>
                  <span className="flex items-center gap-2">
                    <div className="w-4 h-4 bg-red-100 border-2 border-red-300"></div>
                    Booked
                  </span>
                </div>

                {/* Grid */}
                <div className="overflow-x-auto">
                  <table className="min-w-full border-2 border-forest-800">
                    <thead>
                      <tr className="bg-forest-100">
                        <th className="border border-gray-300 px-2 py-2 text-forest-800 text-sm font-bold uppercase">
                          Date/Time
                        </th>
                        {fields.map(field => (
                          <th key={field.id} className="border border-gray-300 px-2 py-2 text-forest-800 text-sm font-bold uppercase" colSpan={timeSlots.length}>
                            {field.name}
                          </th>
                        ))}
                      </tr>
                      <tr className="bg-forest-50">
                        <th className="border border-gray-300 px-2 py-1"></th>
                        {fields.map(field =>
                          timeLabels.map((label, idx) => (
                            <th key={`${field.id}-${idx}`} className="border border-gray-300 px-2 py-1 text-xs text-forest-800">
                              {label}
                            </th>
                          ))
                        )}
                      </tr>
                    </thead>
                    <tbody>
                      {Object.entries(availability).map(([dateStr, slots]) => {
                        const date = new Date(dateStr);
                        const dayLabel = date.toLocaleDateString('en-US', {
                          weekday: 'short',
                          month: 'short',
                          day: 'numeric'
                        });

                        return (
                          <tr key={dateStr}>
                            <td className="border border-gray-300 px-2 py-2 font-medium text-forest-800 bg-gray-50">
                              {dayLabel}
                            </td>
                            {fields.map(field =>
                              timeSlots.map(time => {
                                const status = getSlotStatus(dateStr, field.id, time);
                                const slot = slots.find(s => s.fieldId === field.id && s.time === time);

                                return (
                                  <td
                                    key={`${field.id}-${time}`}
                                    className="border border-gray-300 p-1"
                                  >
                                    <button
                                      onClick={() => status === 'available' || status === 'selected'
                                        ? toggleSlotSelection(dateStr, field.id, time)
                                        : null
                                      }
                                      disabled={status === 'booked'}
                                      className={`w-full h-8 text-xs font-medium ${getSlotClass(status)}`}
                                      title={slot?.bookedBy ? `Booked by ${slot.bookedBy}` : 'Click to select'}
                                    >
                                      {status === 'booked' ? '✗' : status === 'selected' ? '✓' : ''}
                                    </button>
                                  </td>
                                );
                              })
                            )}
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {/* Quick Actions */}
                <div className="mt-4 p-4 bg-gray-50 border border-gray-300">
                  <h5 className="font-bold text-forest-800 mb-2 uppercase text-sm">Quick Pattern Selection</h5>
                  <div className="flex flex-wrap gap-2">
                    {fields.map(field => (
                      <div key={field.id} className="flex flex-wrap gap-1">
                        <span className="text-sm font-medium text-forest-800 mr-2">{field.name}:</span>
                        {[1, 2, 3, 4, 5].map(day => (
                          <button
                            key={day}
                            onClick={() => selectPattern(day, '17:00', field.id)}
                            className="px-2 py-1 text-xs bg-white border border-forest-800 hover:bg-forest-100"
                          >
                            {['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'][day]} 5pm
                          </button>
                        ))}
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Actions */}
          <div className="flex justify-between items-center">
            <div className="text-forest-800">
              <span className="font-bold">{selectedSlots.size}</span> time slots selected
            </div>
            <div className="flex gap-4">
              <button
                onClick={onClose}
                className="bg-white text-forest-800 border-2 border-forest-800 px-6 py-2 hover:bg-gray-100 uppercase"
              >
                Cancel
              </button>
              <button
                onClick={publishSchedule}
                disabled={selectedSlots.size === 0}
                className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 uppercase font-bold disabled:opacity-50"
              >
                Publish Schedule ({selectedSlots.size} Practices)
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default SmartScheduler;
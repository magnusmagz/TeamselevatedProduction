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

interface Practice {
  date: string;
  day: string;
  start_time: string;
  end_time: string;
  start_datetime: string;
  end_datetime: string;
  venue_id: number;
  field_id: number;
  has_conflict: boolean;
  team_name?: string;
  conflict_details?: {
    team: string;
    type: string;
    time: string;
  };
  skip?: boolean;
  notes?: string;
}

interface PracticeSchedulerProps {
  team: Team;
  onClose: () => void;
}

const PracticeScheduler: React.FC<PracticeSchedulerProps> = ({ team, onClose }) => {
  const [step, setStep] = useState<'pattern' | 'review' | 'complete'>('pattern');

  // Pattern settings
  const [selectedDays, setSelectedDays] = useState<string[]>([]);
  const [startTime, setStartTime] = useState('17:00');
  const [endTime, setEndTime] = useState('18:30');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [selectedVenue, setSelectedVenue] = useState<number | null>(null);
  const [selectedField, setSelectedField] = useState<number | null>(null);

  // Generated practices
  const [generatedPractices, setGeneratedPractices] = useState<Practice[]>([]);
  const [conflictCount, setConflictCount] = useState(0);

  // Data
  const [venues, setVenues] = useState<Venue[]>([]);
  const [fields, setFields] = useState<Field[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  const daysOfWeek = [
    { value: 'sunday', label: 'Sunday', abbr: 'Sun' },
    { value: 'monday', label: 'Monday', abbr: 'Mon' },
    { value: 'tuesday', label: 'Tuesday', abbr: 'Tue' },
    { value: 'wednesday', label: 'Wednesday', abbr: 'Wed' },
    { value: 'thursday', label: 'Thursday', abbr: 'Thu' },
    { value: 'friday', label: 'Friday', abbr: 'Fri' },
    { value: 'saturday', label: 'Saturday', abbr: 'Sat' }
  ];

  useEffect(() => {
    fetchVenues();
    // Set default dates (today to 3 months from now)
    const today = new Date();
    const threeMonths = new Date();
    threeMonths.setMonth(threeMonths.getMonth() + 3);

    setStartDate(today.toISOString().split('T')[0]);
    setEndDate(threeMonths.toISOString().split('T')[0]);
  }, []);

  useEffect(() => {
    if (selectedVenue) {
      const venue = venues.find(v => v.id === selectedVenue);
      setFields(venue?.fields || []);
      setSelectedField(null); // Reset field selection when venue changes
    }
  }, [selectedVenue, venues]);

  const fetchVenues = async () => {
    try {
      const response = await fetch('http://localhost:8889/venues-gateway.php');
      const venueData = await response.json();

      // Fetch fields for each venue
      const venuesWithFields = await Promise.all(
        venueData.map(async (venue: Venue) => {
          const fieldResponse = await fetch(`http://localhost:8889/venues-gateway.php?id=${venue.id}`);
          const venueDetails = await fieldResponse.json();
          return venueDetails;
        })
      );

      setVenues(venuesWithFields);
    } catch (error) {
      console.error('Error fetching venues:', error);
    }
  };

  const handleDayToggle = (day: string) => {
    if (selectedDays.includes(day)) {
      setSelectedDays(selectedDays.filter(d => d !== day));
    } else {
      setSelectedDays([...selectedDays, day]);
    }
  };

  const checkForConflicts = (newPractice: Practice, existingPractices: Practice[]): { hasConflict: boolean; conflictDetails?: any } => {
    // Check for overlapping practices on the same field
    for (const existing of existingPractices) {
      // Skip if different field or venue
      if (existing.field_id !== newPractice.field_id || existing.venue_id !== newPractice.venue_id) {
        continue;
      }

      // Skip if different date
      if (existing.date !== newPractice.date) {
        continue;
      }

      // Check for time overlap
      const newStart = newPractice.start_time;
      const newEnd = newPractice.end_time;
      const existingStart = existing.start_time;
      const existingEnd = existing.end_time;

      // Check if times overlap
      const overlaps = (
        (newStart >= existingStart && newStart < existingEnd) ||
        (newEnd > existingStart && newEnd <= existingEnd) ||
        (newStart <= existingStart && newEnd >= existingEnd)
      );

      if (overlaps) {
        return {
          hasConflict: true,
          conflictDetails: {
            team: existing.team_name || 'Another team',
            type: 'Field conflict',
            time: `${existingStart} - ${existingEnd}`
          }
        };
      }
    }

    return { hasConflict: false };
  };

  const handleGenerateSchedule = async () => {
    if (!selectedField || selectedDays.length === 0) {
      alert('Please select days and a field');
      return;
    }

    setLoading(true);
    try {
      // Load existing practices to check for conflicts
      const existingPractices = JSON.parse(localStorage.getItem('teamPractices') || '[]');

      // Generate practices on the client side for now
      const practices: Practice[] = [];
      const start = new Date(startDate);
      const end = new Date(endDate);
      let conflictDetected = 0;

      // Map day names to JavaScript day numbers (0 = Sunday, 6 = Saturday)
      const dayMap: { [key: string]: number } = {
        'sunday': 0,
        'monday': 1,
        'tuesday': 2,
        'wednesday': 3,
        'thursday': 4,
        'friday': 5,
        'saturday': 6
      };

      // Iterate through each day from start to end
      for (let date = new Date(start); date <= end; date.setDate(date.getDate() + 1)) {
        const dayOfWeek = date.getDay();
        const dayName = Object.keys(dayMap).find(key => dayMap[key] === dayOfWeek);

        if (dayName && selectedDays.includes(dayName)) {
          // Create a practice for this day
          const practiceDate = new Date(date);
          const dateStr = practiceDate.toISOString().split('T')[0];

          // Create datetime strings for start and end
          const startDateTime = `${dateStr}T${startTime}:00`;
          const endDateTime = `${dateStr}T${endTime}:00`;

          const newPractice: Practice = {
            date: dateStr,
            day: dayName.charAt(0).toUpperCase() + dayName.slice(1),
            start_time: startTime,
            end_time: endTime,
            start_datetime: startDateTime,
            end_datetime: endDateTime,
            venue_id: selectedVenue!,
            field_id: selectedField,
            has_conflict: false,
            skip: false
          };

          // Check for conflicts with existing practices
          const conflictCheck = checkForConflicts(newPractice, existingPractices);
          if (conflictCheck.hasConflict) {
            newPractice.has_conflict = true;
            newPractice.conflict_details = conflictCheck.conflictDetails;
            conflictDetected++;
          }

          practices.push(newPractice);
        }
      }

      setGeneratedPractices(practices);
      setConflictCount(conflictDetected);
      setStep('review');
    } catch (error) {
      console.error('Error generating schedule:', error);
      alert('Failed to generate schedule');
    } finally {
      setLoading(false);
    }
  };

  const handleTogglePractice = (index: number) => {
    const updated = [...generatedPractices];
    updated[index].skip = !updated[index].skip;
    setGeneratedPractices(updated);
  };

  const handleUpdatePractice = (index: number, field: string, value: string) => {
    const updated = [...generatedPractices];
    (updated[index] as any)[field] = value;
    setGeneratedPractices(updated);
  };

  const handlePublishSchedule = async () => {
    const practicesToCreate = generatedPractices.filter(p => !p.skip);

    if (practicesToCreate.length === 0) {
      alert('No practices selected to create');
      return;
    }

    // Check if there are any conflicts in the practices to be created
    const conflictsToPublish = practicesToCreate.filter(p => p.has_conflict);
    if (conflictsToPublish.length > 0) {
      const confirmPublish = window.confirm(
        `⚠️ Warning: ${conflictsToPublish.length} practice${conflictsToPublish.length > 1 ? 's have' : ' has'} field conflicts.\n\n` +
        `Publishing will create double-bookings on the same field.\n\n` +
        `Do you want to continue anyway?`
      );

      if (!confirmPublish) {
        return;
      }
    }

    setSaving(true);
    try {
      // Simulate saving for demo purposes
      await new Promise(resolve => setTimeout(resolve, 1000));

      // Save to localStorage for demo
      const existingPractices = JSON.parse(localStorage.getItem('teamPractices') || '[]');
      const newPractices = practicesToCreate.map(practice => ({
        ...practice,
        id: Date.now() + Math.random(), // Generate unique ID
        team_id: team.id,
        team_name: team.name,
        created_at: new Date().toISOString()
      }));

      localStorage.setItem('teamPractices', JSON.stringify([...existingPractices, ...newPractices]));

      console.log('Saved practices to localStorage:', newPractices);
      console.log('Schedule pattern:', {
        team_id: team.id,
        pattern_name: `${team.name} Regular Practice`,
        days_of_week: selectedDays,
        start_time: startTime,
        end_time: endTime,
        venue_id: selectedVenue,
        field_id: selectedField,
        start_date: startDate,
        end_date: endDate
      });

      // Show success
      setStep('complete');
    } catch (error) {
      console.error('Error creating practices:', error);
      alert('Failed to create practices');
    } finally {
      setSaving(false);
    }
  };

  if (step === 'complete') {
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
        <div className="bg-white border-2 border-forest-800 w-full max-w-2xl p-8 text-center">
          <h2 className="text-2xl font-bold text-forest-800 mb-4 uppercase">Schedule Published!</h2>
          <p className="text-gray-600 mb-6">
            {generatedPractices.filter(p => !p.skip).length} practices have been added to your calendar.
          </p>
          <button
            onClick={onClose}
            className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 uppercase"
          >
            Done
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-6xl max-h-[90vh] overflow-y-auto">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
            Practice Schedule Builder - {team.name}
          </h3>
          <button
            onClick={onClose}
            className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
          >
            ×
          </button>
        </div>

        <div className="p-6">
          {step === 'pattern' && (
            <div className="space-y-6">
              <div className="border-2 border-forest-800 p-6">
                <h4 className="text-forest-800 font-bold mb-4 uppercase">Quick Pattern Setup</h4>

                {/* Days Selection */}
                <div className="mb-6">
                  <label className="block text-forest-800 font-medium mb-3 uppercase text-sm">
                    Select Practice Days
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {daysOfWeek.map(day => (
                      <button
                        key={day.value}
                        onClick={() => handleDayToggle(day.value)}
                        className={`px-4 py-2 border-2 uppercase ${
                          selectedDays.includes(day.value)
                            ? 'bg-forest-800 text-white border-forest-800'
                            : 'bg-white text-forest-800 border-forest-800 hover:bg-gray-100'
                        }`}
                      >
                        {day.label}
                      </button>
                    ))}
                  </div>
                </div>

                {/* Time Selection */}
                <div className="grid grid-cols-2 gap-4 mb-6">
                  <div>
                    <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                      Start Time
                    </label>
                    <input
                      type="time"
                      value={startTime}
                      onChange={(e) => setStartTime(e.target.value)}
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    />
                  </div>
                  <div>
                    <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                      End Time
                    </label>
                    <input
                      type="time"
                      value={endTime}
                      onChange={(e) => setEndTime(e.target.value)}
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    />
                  </div>
                </div>

                {/* Venue and Field Selection */}
                <div className="grid grid-cols-2 gap-4 mb-6">
                  <div>
                    <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                      Venue
                    </label>
                    <select
                      value={selectedVenue || ''}
                      onChange={(e) => setSelectedVenue(Number(e.target.value))}
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    >
                      <option value="">Select a venue...</option>
                      {venues.map(venue => (
                        <option key={venue.id} value={venue.id}>
                          {venue.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                      Field
                    </label>
                    <select
                      value={selectedField || ''}
                      onChange={(e) => setSelectedField(Number(e.target.value))}
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                      disabled={!selectedVenue}
                    >
                      <option value="">Select a field...</option>
                      {fields.map(field => (
                        <option key={field.id} value={field.id}>
                          {field.name}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                {/* Date Range */}
                <div className="grid grid-cols-2 gap-4 mb-6">
                  <div>
                    <label className="block text-forest-800 font-medium mb-2 uppercase text-sm">
                      Start Date
                    </label>
                    <input
                      type="date"
                      value={startDate}
                      onChange={(e) => setStartDate(e.target.value)}
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
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
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    />
                  </div>
                </div>

                <div className="flex justify-end">
                  <button
                    onClick={handleGenerateSchedule}
                    disabled={loading || selectedDays.length === 0 || !selectedField}
                    className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 uppercase disabled:opacity-50"
                  >
                    {loading ? 'Generating...' : 'Generate Schedule →'}
                  </button>
                </div>
              </div>
            </div>
          )}

          {step === 'review' && (
            <div className="space-y-6">
              <div className="bg-white p-4 border-2 border-forest-800">
                <div className="flex justify-between items-center mb-4">
                  <h4 className="text-forest-800 font-bold uppercase">
                    Generated Schedule ({generatedPractices.length} practices)
                  </h4>
                  {conflictCount > 0 && (
                    <div className="bg-red-100 border-2 border-red-600 text-red-800 px-4 py-2 font-medium">
                      ⚠️ {conflictCount} field conflict{conflictCount > 1 ? 's' : ''} detected - review below
                    </div>
                  )}
                </div>

                <div className="text-sm text-gray-600 mb-4">
                  Review and edit as needed. Uncheck practices you don't want to create.
                </div>

                <div className="overflow-x-auto">
                  <table className="min-w-full border-2 border-forest-800">
                    <thead>
                      <tr className="border-b-2 border-forest-800">
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">✓</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Date</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Day</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Time</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Field</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Status</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Notes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {generatedPractices.map((practice, index) => (
                        <tr
                          key={index}
                          className={`border-b border-gray-300 ${
                            practice.skip ? 'opacity-50 bg-gray-100' : ''
                          } ${practice.has_conflict ? 'bg-red-50' : ''}`}
                        >
                          <td className="px-4 py-2">
                            <input
                              type="checkbox"
                              checked={!practice.skip}
                              onChange={() => handleTogglePractice(index)}
                              className="border-2 border-forest-800"
                            />
                          </td>
                          <td className="px-4 py-2 text-forest-800">
                            {new Date(practice.date).toLocaleDateString()}
                          </td>
                          <td className="px-4 py-2 text-forest-800">{practice.day}</td>
                          <td className="px-4 py-2 text-forest-800">
                            {practice.start_time.slice(0, 5)} - {practice.end_time.slice(0, 5)}
                          </td>
                          <td className="px-4 py-2 text-forest-800">
                            {fields.find(f => f.id === practice.field_id)?.name}
                          </td>
                          <td className="px-4 py-2">
                            {practice.has_conflict ? (
                              <div className="text-red-600">
                                <div className="font-bold">⚠️ Conflict</div>
                                <div className="text-xs mt-1">
                                  <div>{practice.conflict_details?.type}</div>
                                  <div className="font-medium">{practice.conflict_details?.team}</div>
                                  <div>{practice.conflict_details?.time}</div>
                                </div>
                              </div>
                            ) : (
                              <span className="text-green-600 font-medium">✓ Available</span>
                            )}
                          </td>
                          <td className="px-4 py-2">
                            <input
                              type="text"
                              value={practice.notes || ''}
                              onChange={(e) => handleUpdatePractice(index, 'notes', e.target.value)}
                              className="w-full bg-white text-forest-800 border border-gray-300 px-2 py-1 text-sm"
                              placeholder="Optional notes..."
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="flex justify-between items-center mt-6">
                  <button
                    onClick={() => setStep('pattern')}
                    className="bg-white text-forest-800 border-2 border-forest-800 px-6 py-2 hover:bg-gray-100 uppercase"
                  >
                    Edit Pattern
                  </button>

                  <div className="flex space-x-4">
                    <div className="text-forest-800">
                      {generatedPractices.filter(p => !p.skip).length} practices selected
                    </div>
                    <button
                      onClick={handlePublishSchedule}
                      disabled={saving}
                      className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 uppercase font-semibold"
                    >
                      {saving ? 'Publishing...' : 'Publish Schedule'}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default PracticeScheduler;
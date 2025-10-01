import React, { useState, useEffect } from 'react';

interface JerseyReportProps {
  teamId: number;
}

interface JerseyConflict {
  number: number;
  position: string;
  players: { player: string; position: string; priority: string }[];
}

const JerseyReport: React.FC<JerseyReportProps> = ({ teamId }) => {
  const [jerseyMap, setJerseyMap] = useState<Record<string, any[]>>({});
  const [availableNumbers, setAvailableNumbers] = useState<number[]>([]);
  const [conflicts, setConflicts] = useState<JerseyConflict[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchJerseyReport();
  }, [teamId]);

  const fetchJerseyReport = async () => {
    try {
      const response = await fetch(`http://localhost:8888/teamselevated-backend/api/coach/teams/${teamId}/jersey-report`);
      const data = await response.json();
      setJerseyMap(data.jersey_map || {});
      setAvailableNumbers(data.available_numbers || []);
      setConflicts(data.conflicts || []);
    } catch (error) {
      console.error('Error fetching jersey report:', error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return <div className="text-center text-white py-12">Loading jersey report...</div>;
  }

  return (
    <div>
      {conflicts.length > 0 && (
        <div className="bg-red-900 p-4 mb-6">
          <h3 className="text-white font-bold mb-2">⚠️ Jersey Conflicts</h3>
          <div className="space-y-2">
            {conflicts.map((conflict, i) => (
              <div key={i} className="text-red-200">
                <strong>#{conflict.number} - {conflict.position}:</strong>
                {' '}Assigned to multiple players:
                {' '}{conflict.players.map(p => p.player).join(', ')}
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="grid grid-cols-2 gap-6 mb-6">
        <div className="bg-forest-800 p-6">
          <h3 className="text-xl font-bold text-white mb-4">Jersey Assignments</h3>
          <div className="max-h-96 overflow-y-auto">
            <table className="w-full">
              <thead className="sticky top-0 bg-forest-950">
                <tr>
                  <th className="text-left px-3 py-2 text-forest-200">#</th>
                  <th className="text-left px-3 py-2 text-forest-200">Player</th>
                  <th className="text-left px-3 py-2 text-forest-200">Position</th>
                  <th className="text-left px-3 py-2 text-forest-200">Priority</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(jerseyMap)
                  .sort(([a], [b]) => parseInt(a) - parseInt(b))
                  .map(([number, assignments]) => (
                    assignments.map((assignment, i) => (
                      <tr key={`${number}-${i}`} className="border-t border-forest-700">
                        <td className="px-3 py-2 text-2xl font-bold text-white">
                          {i === 0 ? number : ''}
                        </td>
                        <td className="px-3 py-2 text-white">{assignment.player}</td>
                        <td className="px-3 py-2 text-forest-200">{assignment.position}</td>
                        <td className="px-3 py-2">
                          <span className={`px-2 py-1 text-xs ${
                            assignment.priority === 'primary' ? 'bg-forest-600' :
                            assignment.priority === 'secondary' ? 'bg-forest-700' :
                            'bg-orange-800'
                          } text-white`}>
                            {assignment.priority}
                          </span>
                        </td>
                      </tr>
                    ))
                  ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className="bg-forest-800 p-6">
          <h3 className="text-xl font-bold text-white mb-4">Available Jersey Numbers</h3>
          <div className="text-forest-200 mb-4">
            {availableNumbers.length} numbers available
          </div>
          <div className="grid grid-cols-10 gap-2 max-h-96 overflow-y-auto">
            {availableNumbers.slice(0, 100).map(number => (
              <div
                key={number}
                className="bg-forest-700 text-white text-center py-2 font-bold"
              >
                {number}
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="bg-forest-800 p-6">
        <h3 className="text-xl font-bold text-white mb-4">Jersey Usage by Position</h3>
        <div className="grid grid-cols-5 gap-4">
          {['Goalkeeper', 'Defender', 'Midfielder', 'Forward', 'Striker'].map(position => {
            const positionJerseys = Object.entries(jerseyMap)
              .filter(([_, assignments]) => assignments.some(a => a.position === position))
              .map(([number]) => parseInt(number))
              .sort((a, b) => a - b);

            return (
              <div key={position} className="bg-forest-700 p-3">
                <h4 className="text-forest-300 font-semibold mb-2">{position}</h4>
                {positionJerseys.length === 0 ? (
                  <p className="text-forest-500 text-sm">No jerseys assigned</p>
                ) : (
                  <div className="flex flex-wrap gap-1">
                    {positionJerseys.map(number => (
                      <span key={number} className="bg-forest-600 text-white px-2 py-1 text-sm">
                        #{number}
                      </span>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};

export default JerseyReport;
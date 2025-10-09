import React, { useState, useEffect } from 'react';

interface PositionReportProps {
  teamId: number;
}

interface PositionData {
  position: string;
  primary_players: { id: number; name: string; is_primary: boolean; status: string }[];
  secondary_players: { id: number; name: string; is_primary: boolean; status: string }[];
  guest_players: { id: number; name: string; is_primary: boolean; status: string }[];
}

const PositionReport: React.FC<PositionReportProps> = ({ teamId }) => {
  const [positionMap, setPositionMap] = useState<Record<string, PositionData>>({});
  const [needsCoverage, setNeedsCoverage] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchPositionReport();
  }, [teamId]);

  const fetchPositionReport = async () => {
    try {
      const response = await fetch(`http://localhost:8888/teamselevated-backend/api/coach/teams/${teamId}/position-report`);
      const data = await response.json();
      setPositionMap(data.position_map || {});
      setNeedsCoverage(data.positions_needing_coverage || []);
    } catch (error) {
      console.error('Error fetching position report:', error);
    } finally {
      setLoading(false);
    }
  };

  const getTotalPlayers = (pos: PositionData) => {
    return pos.primary_players.length + pos.secondary_players.length + pos.guest_players.length;
  };

  if (loading) {
    return <div className="text-center text-white py-12">Loading position report...</div>;
  }

  return (
    <div>
      {needsCoverage.length > 0 && (
        <div className="bg-red-900 p-4 mb-6">
          <h3 className="text-white font-bold mb-2">⚠️ Positions Needing Coverage</h3>
          <div className="space-y-1">
            {needsCoverage.map((pos, i) => (
              <div key={i} className="text-red-200">
                <strong>{pos.position}:</strong> Only {pos.current} player(s), need {pos.needed} more
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="space-y-6">
        {Object.entries(positionMap).map(([position, data]) => (
          <div key={position} className="bg-forest-800 p-6">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-xl font-bold text-white">{position}</h3>
              <div className="flex space-x-4">
                <span className="text-forest-200">
                  Total: {getTotalPlayers(data)} players
                </span>
                {getTotalPlayers(data) < 2 && (
                  <span className="text-red-400">⚠️ Low coverage</span>
                )}
              </div>
            </div>

            <div className="grid grid-cols-3 gap-4">
              <div>
                <h4 className="text-forest-300 font-semibold mb-2">
                  Primary ({data.primary_players.length})
                </h4>
                <div className="space-y-2">
                  {data.primary_players.length === 0 ? (
                    <p className="text-forest-500 text-sm">No primary players</p>
                  ) : (
                    data.primary_players.map(player => (
                      <div key={player.id} className="bg-forest-700 px-3 py-2">
                        <div className="text-white">{player.name}</div>
                        {player.status !== 'active' && (
                          <span className="text-xs text-red-400">{player.status}</span>
                        )}
                      </div>
                    ))
                  )}
                </div>
              </div>

              <div>
                <h4 className="text-forest-300 font-semibold mb-2">
                  Secondary ({data.secondary_players.length})
                </h4>
                <div className="space-y-2">
                  {data.secondary_players.length === 0 ? (
                    <p className="text-forest-500 text-sm">No secondary players</p>
                  ) : (
                    data.secondary_players.map(player => (
                      <div key={player.id} className="bg-forest-700 px-3 py-2">
                        <div className="text-white">{player.name}</div>
                        {player.status !== 'active' && (
                          <span className="text-xs text-red-400">{player.status}</span>
                        )}
                      </div>
                    ))
                  )}
                </div>
              </div>

              <div>
                <h4 className="text-forest-300 font-semibold mb-2">
                  Guest ({data.guest_players.length})
                </h4>
                <div className="space-y-2">
                  {data.guest_players.length === 0 ? (
                    <p className="text-forest-500 text-sm">No guest players</p>
                  ) : (
                    data.guest_players.map(player => (
                      <div key={player.id} className="bg-orange-900 px-3 py-2">
                        <div className="text-white">{player.name}</div>
                        <span className="text-xs text-orange-400">Guest</span>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

export default PositionReport;
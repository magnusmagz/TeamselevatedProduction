import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import AthletePhotoUpload from './AthletePhotoUpload';

interface Team {
  id: number;
  name: string;
  age_group?: string;
}

interface Athlete {
  id: number;
  team_member_id?: number;
  first_name: string;
  last_name: string;
  email: string;
  created_at: string;
  date_of_birth?: string;
  gender?: string;
  school_name?: string;
  grade_level?: number;
  photo_url?: string;
  primary_position?: string;
  positions?: string[];
  jersey_number?: number;
}

interface GuardianInfo {
  first_name: string;
  last_name: string;
  mobile_phone?: string;
  relationship_type?: string;
}

interface RosterManagementProps {
  team: Team;
}

function calcAge(dob: string): number {
  const birth = new Date(dob);
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const m = today.getMonth() - birth.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
  return age;
}

function getAgeQuarter(dob: string): string {
  const month = new Date(dob).getMonth();
  if (month <= 2) return 'Q1';
  if (month <= 5) return 'Q2';
  if (month <= 8) return 'Q3';
  return 'Q4';
}

function getUGroup(dob: string): string {
  const birth = new Date(dob);
  const today = new Date();
  const seasonYear = today.getMonth() >= 7 ? today.getFullYear() + 1 : today.getFullYear();
  return `U${seasonYear - birth.getFullYear()}`;
}

function formatDOB(dob: string): string {
  const d = new Date(dob);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function getUNumber(dob: string): number {
  const birth = new Date(dob);
  const today = new Date();
  const seasonYear = today.getMonth() >= 7 ? today.getFullYear() + 1 : today.getFullYear();
  return seasonYear - birth.getFullYear();
}

function sortAthletesByU(athletes: Athlete[], teamAgeGroup?: string): Athlete[] {
  const targetU = teamAgeGroup ? parseInt(teamAgeGroup.replace(/\D/g, '')) : null;

  return [...athletes].sort((a, b) => {
    // Athletes without DOB go to the bottom
    if (!a.date_of_birth && !b.date_of_birth) return 0;
    if (!a.date_of_birth) return 1;
    if (!b.date_of_birth) return -1;

    if (targetU) {
      const aU = getUNumber(a.date_of_birth);
      const bU = getUNumber(b.date_of_birth);
      const aDist = Math.abs(aU - targetU);
      const bDist = Math.abs(bU - targetU);
      if (aDist !== bDist) return aDist - bDist;
    }

    // Secondary sort: name
    return a.last_name.localeCompare(b.last_name);
  });
}

const SOCCER_POSITIONS = [
  'Goalkeeper', 'Center Back', 'Left Back', 'Right Back',
  'Defensive Midfielder', 'Central Midfielder', 'Attacking Midfielder',
  'Left Wing', 'Right Wing', 'Striker', 'Forward'
];

const AthleteDrawer: React.FC<{ athlete: Athlete; onClose: () => void; apiUrl: string; teamId: number; onUpdate: () => void }> = ({ athlete, onClose, apiUrl, teamId, onUpdate }) => {
  const [guardian, setGuardian] = useState<GuardianInfo | null>(null);
  const [jerseyNumber, setJerseyNumber] = useState<string>(athlete.jersey_number?.toString() || '');
  const [position, setPosition] = useState<string>(athlete.primary_position || '');

  useEffect(() => {
    setJerseyNumber(athlete.jersey_number?.toString() || '');
    setPosition(athlete.primary_position || '');
  }, [athlete.id, athlete.jersey_number, athlete.primary_position]);

  useEffect(() => {
    fetch(`${apiUrl}/legacy/athletes-gateway.php?id=${athlete.id}`)
      .then(r => r.json())
      .then(data => {
        const g = data.athlete?.guardians?.[0] || data.guardians?.[0];
        if (g) setGuardian(g);
      })
      .catch(() => {});
  }, [athlete.id, apiUrl]);

  const age = athlete.date_of_birth ? calcAge(athlete.date_of_birth) : null;
  const initials = `${athlete.first_name[0]}${athlete.last_name[0]}`.toUpperCase();

  return (
    <>
      {/* Backdrop */}
      <div className="fixed inset-0 bg-black bg-opacity-30 z-40" onClick={onClose} />

      {/* Drawer */}
      <div className="fixed right-0 top-0 h-full w-80 bg-white shadow-xl z-50 flex flex-col">
        {/* Header */}
        <div className="bg-brand-primary p-4 flex justify-between items-center">
          <h3 className="text-white font-bold uppercase tracking-wide">Athlete Info</h3>
          <button onClick={onClose} className="text-white hover:text-brand-secondary text-xl font-bold">✕</button>
        </div>

        {/* Content */}
        <div className="p-5 flex-1 overflow-y-auto">
          {/* Avatar + Name */}
          <div className="flex items-center gap-4 mb-5">
            <AthletePhotoUpload
              athleteId={athlete.id}
              currentPhotoUrl={athlete.photo_url || undefined}
              firstName={athlete.first_name}
              lastName={athlete.last_name}
              onPhotoUpdated={() => onUpdate()}
              size="small"
            />
            <div>
              <div className="font-bold text-brand-primary text-lg">{athlete.first_name} {athlete.last_name}</div>
              {age !== null && (
                <div className="text-gray-500 text-sm">
                  Age {age}
                  {athlete.date_of_birth && (
                    <>
                      <span className="ml-2 text-xs font-semibold bg-brand-secondary text-brand-primary px-1.5 py-0.5 rounded-full">
                        {getAgeQuarter(athlete.date_of_birth)}
                      </span>
                      <span className="ml-1 text-xs font-semibold bg-brand-primary text-white px-1.5 py-0.5 rounded-full">
                        {getUGroup(athlete.date_of_birth)}
                      </span>
                    </>
                  )}
                </div>
              )}
            </div>
          </div>

          {/* Details */}
          <div className="space-y-3 text-sm">
            {athlete.date_of_birth && (
              <div className="flex justify-between border-b pb-2">
                <span className="text-gray-500 font-medium uppercase text-xs">Date of Birth</span>
                <span className="text-brand-primary font-medium">{formatDOB(athlete.date_of_birth)}</span>
              </div>
            )}
            {athlete.gender && (
              <div className="flex justify-between border-b pb-2">
                <span className="text-gray-500 font-medium uppercase text-xs">Gender</span>
                <span className="text-brand-primary capitalize">{athlete.gender}</span>
              </div>
            )}
            {athlete.school_name && (
              <div className="flex justify-between border-b pb-2">
                <span className="text-gray-500 font-medium uppercase text-xs">School</span>
                <span className="text-brand-primary">{athlete.school_name}</span>
              </div>
            )}
            {athlete.grade_level && (
              <div className="flex justify-between border-b pb-2">
                <span className="text-gray-500 font-medium uppercase text-xs">Grade</span>
                <span className="text-brand-primary">{athlete.grade_level}</span>
              </div>
            )}
            {athlete.email && (
              <div className="flex justify-between border-b pb-2">
                <span className="text-gray-500 font-medium uppercase text-xs">Email</span>
                <span className="text-brand-primary truncate ml-2">{athlete.email}</span>
              </div>
            )}

            {/* Position & Jersey */}
            <div className="mt-4">
              <div className="text-gray-500 font-medium uppercase text-xs mb-2">Position & Jersey</div>
              <div className="space-y-2">
                <div>
                  <label className="text-xs text-gray-500 block mb-1">Primary Position</label>
                  <select
                    value={position}
                    onChange={async (e) => {
                      const pos = e.target.value;
                      setPosition(pos);
                      const token = localStorage.getItem('auth_token');
                      try {
                        await fetch(`${apiUrl}/legacy/team-players-gateway.php`, {
                          method: 'PUT',
                          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
                          body: JSON.stringify({
                            team_member_id: athlete.team_member_id,
                            primary_position: pos,
                            positions: pos ? [pos] : [],
                          }),
                        });
                        onUpdate();
                      } catch (err) {
                        console.error('Failed to update position:', err);
                      }
                    }}
                    className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
                  >
                    <option value="">Select position...</option>
                    {SOCCER_POSITIONS.map(p => (
                      <option key={p} value={p}>{p}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="text-xs text-gray-500 block mb-1">Jersey Number</label>
                  <input
                    type="number"
                    value={jerseyNumber}
                    onChange={(e) => setJerseyNumber(e.target.value)}
                    onBlur={async () => {
                      const num = jerseyNumber ? parseInt(jerseyNumber) : null;
                      if (num === (athlete.jersey_number ?? null)) return;
                      const token = localStorage.getItem('auth_token');
                      try {
                        await fetch(`${apiUrl}/legacy/team-players-gateway.php`, {
                          method: 'PUT',
                          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
                          body: JSON.stringify({
                            team_member_id: athlete.team_member_id,
                            jersey_number: num,
                          }),
                        });
                        onUpdate();
                      } catch (err) {
                        console.error('Failed to update jersey:', err);
                      }
                    }}
                    placeholder="#"
                    min="0"
                    max="99"
                    className="w-full border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
                  />
                </div>
              </div>
            </div>

            {/* Guardian */}
            {guardian && (
              <div className="mt-4">
                <div className="text-gray-500 font-medium uppercase text-xs mb-2">Parent / Guardian</div>
                <div className="bg-brand-secondary p-3">
                  <div className="font-medium text-brand-primary">{guardian.first_name} {guardian.last_name}</div>
                  {guardian.relationship_type && <div className="text-gray-600 text-xs capitalize">{guardian.relationship_type}</div>}
                  {guardian.mobile_phone && (
                    <a href={`tel:${guardian.mobile_phone}`} className="text-brand-primary text-sm hover:underline">{guardian.mobile_phone}</a>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="p-4 border-t">
          <Link
            to={`/athlete/${athlete.id}`}
            className="block w-full bg-brand-primary text-white text-center py-2 font-semibold uppercase text-sm hover:opacity-90"
          >
            View Full Profile →
          </Link>
        </div>
      </div>
    </>
  );
};

const RosterManagement: React.FC<RosterManagementProps> = ({ team }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [roster, setRoster] = useState<Athlete[]>([]);
  const [loading, setLoading] = useState(true);
  const [availableAthletes, setAvailableAthletes] = useState<Athlete[]>([]);
  const [allAthletes, setAllAthletes] = useState<Athlete[]>([]);
  const [addingAthleteId, setAddingAthleteId] = useState<number | null>(null);
  const [isDragOver, setIsDragOver] = useState(false);
  const [selectedAthlete, setSelectedAthlete] = useState<Athlete | null>(null);

  const fetchRoster = async () => {
    try {
      const response = await fetch(`${API_URL}/legacy/team-players-gateway.php?team_id=${team.id}`);
      const data = await response.json();
      if (data.success && data.team_members) {
        const athletes = data.team_members.map((tm: any) => ({
          id: tm.athlete_id,
          team_member_id: tm.id,
          first_name: tm.first_name,
          last_name: tm.last_name,
          email: tm.email || '',
          created_at: tm.created_at || '',
          date_of_birth: tm.date_of_birth || null,
          gender: tm.gender || null,
          school_name: tm.school_name || null,
          grade_level: tm.grade_level || null,
          photo_url: tm.photo_url || null,
          primary_position: tm.primary_position || null,
          positions: tm.positions ? (typeof tm.positions === 'string' ? JSON.parse(tm.positions) : tm.positions) : [],
          jersey_number: tm.jersey_number || null,
        }));
        setRoster(athletes);
      }
    } catch (error) {
      console.error('Error fetching roster:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchAllAthletes = async () => {
    try {
      const response = await fetch(`${API_URL}/legacy/athletes-gateway.php`);
      const data = await response.json();
      const athletes = data.athletes || [];
      setAllAthletes(athletes);
    } catch (error) {
      console.error('Error fetching all athletes:', error);
      setAllAthletes([]);
    }
  };

  const filterAvailableAthletes = () => {
    const teamAthleteIds = roster.map(athlete => athlete.id);
    const available = allAthletes.filter(athlete => !teamAthleteIds.includes(athlete.id));
    setAvailableAthletes(available);
  };

  useEffect(() => {
    fetchRoster();
    fetchAllAthletes();
  }, [team.id]);

  useEffect(() => {
    filterAvailableAthletes();
  }, [allAthletes, roster]);

  const handleRemoveAthlete = async (athleteId: number) => {
    if (!window.confirm('Are you sure you want to remove this athlete from the team?')) return;

    try {
      const response = await fetch(`${API_URL}/legacy/team-players-gateway.php?team_id=${team.id}&player_id=${athleteId}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' }
      });

      if (response.ok) {
        fetchRoster();
      }
    } catch (error) {
      console.error('Error removing athlete:', error);
    }
  };

  const handleAddAthlete = async (athlete: Athlete) => {
    setAddingAthleteId(athlete.id);
    try {
      const response = await fetch(`${API_URL}/legacy/team-players-gateway.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          team_id: team.id,
          player_id: athlete.id,
          status: 'active'
        })
      });

      const data = await response.json();

      if (data.success) {
        await fetchRoster();
      } else {
        console.error('Failed to add athlete:', data.error || data.message);
        alert('Failed to add athlete: ' + (data.error || data.message || 'Unknown error'));
      }
    } catch (error) {
      console.error('Error adding athlete to team:', error);
      alert('Error adding athlete to team');
    } finally {
      setAddingAthleteId(null);
    }
  };

  const handleDragStart = (e: React.DragEvent, athlete: Athlete) => {
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', athlete.id.toString());
  };

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    setIsDragOver(true);
  };

  const handleDragLeave = () => {
    setIsDragOver(false);
  };

  const handleDrop = async (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(false);
    const athleteId = e.dataTransfer.getData('text/plain');
    if (athleteId) {
      const athlete = availableAthletes.find(a => a.id.toString() === athleteId);
      if (athlete) {
        await handleAddAthlete(athlete);
      }
    }
  };

  return (
    <div>
      {selectedAthlete && (
        <AthleteDrawer
          athlete={selectedAthlete}
          onClose={() => setSelectedAthlete(null)}
          apiUrl={API_URL}
          teamId={team.id}
          onUpdate={fetchRoster}
        />
      )}

      <div className="mb-6 flex justify-between items-center">
        <div>
          <h2 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">
            {team.name} Roster{team.age_group ? ` ${team.age_group}` : ''}
          </h2>
          <p className="text-gray-600 mt-2">{roster.length} players total</p>
        </div>
        <Link
          to={`/teams/${team.id}/player-cards`}
          className="bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 hover:bg-gray-50 uppercase font-semibold text-sm"
        >
          Player Cards
        </Link>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading roster...</div>
      ) : (
        <div className="grid grid-cols-2 gap-6">
          {/* Available Athletes */}
          <div className="bg-white border border-brand-secondary rounded-md p-4">
            <h3 className="text-xl font-bold text-brand-primary mb-4 uppercase tracking-wide">
              Available Athletes
            </h3>
            <div className="space-y-2 max-h-96 overflow-y-auto">
              {sortAthletesByU(availableAthletes, team.age_group).map((athlete) => (
                <div
                  key={athlete.id}
                  draggable
                  onDragStart={(e) => handleDragStart(e, athlete)}
                  className="bg-gray-50 border border-gray-300 p-3 hover:bg-gray-100 transition-colors flex justify-between items-center cursor-grab active:cursor-grabbing"
                >
                  <div>
                    <button
                      onClick={() => setSelectedAthlete(athlete)}
                      className="font-medium text-brand-primary hover:underline text-left"
                    >
                      {athlete.first_name} {athlete.last_name}
                    </button>
                    {athlete.date_of_birth && (
                      <div className="text-xs text-gray-500">
                        Age {calcAge(athlete.date_of_birth)}
                        <span className="ml-1 text-xs font-semibold bg-brand-secondary text-brand-primary px-1.5 py-0.5 rounded-full">
                          {getAgeQuarter(athlete.date_of_birth)}
                        </span>
                        <span className="ml-1 text-xs font-semibold bg-brand-primary text-white px-1.5 py-0.5 rounded-full">
                          {getUGroup(athlete.date_of_birth)}
                        </span>
                        <span className="ml-1">· {formatDOB(athlete.date_of_birth)}</span>
                      </div>
                    )}
                    <div className="text-sm text-gray-600">{athlete.email}</div>
                  </div>
                  <button
                    onClick={() => handleAddAthlete(athlete)}
                    disabled={addingAthleteId === athlete.id}
                    className="bg-brand-primary text-white px-3 py-1 rounded text-sm font-medium hover:opacity-90 disabled:opacity-50 flex items-center gap-1 ml-2 flex-shrink-0"
                  >
                    {addingAthleteId === athlete.id ? (
                      'Adding...'
                    ) : (
                      <>Add <span aria-hidden="true">→</span></>
                    )}
                  </button>
                </div>
              ))}
              {availableAthletes.length === 0 && (
                <div className="text-center text-gray-500 py-8">
                  No available athletes to add
                </div>
              )}
            </div>
          </div>

          {/* Team Roster */}
          <div
            className={`bg-white border-2 p-4 transition-colors ${
              isDragOver ? 'border-green-500 bg-green-50' : 'border-brand-primary'
            }`}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
          >
            <h3 className="text-xl font-bold text-brand-primary mb-4 uppercase tracking-wide">
              Team Roster ({roster.length} players)
            </h3>
            <div className="space-y-2 max-h-96 overflow-y-auto">
              {sortAthletesByU(roster, team.age_group).map((athlete) => (
                <div
                  key={athlete.id}
                  className="bg-brand-secondary border border-brand-secondary p-3"
                >
                  <div className="flex justify-between items-start">
                    <div>
                      <button
                        onClick={() => setSelectedAthlete(athlete)}
                        className="font-medium text-brand-primary hover:underline text-left"
                      >
                        {athlete.first_name} {athlete.last_name}
                      </button>
                      {athlete.date_of_birth && (
                        <div className="text-xs text-gray-600">
                          Age {calcAge(athlete.date_of_birth)}
                          <span className="ml-1 text-xs font-semibold bg-white text-brand-primary px-1.5 py-0.5 rounded-full">
                            {getAgeQuarter(athlete.date_of_birth)}
                          </span>
                          <span className="ml-1 text-xs font-semibold bg-brand-primary text-white px-1.5 py-0.5 rounded-full">
                            {getUGroup(athlete.date_of_birth)}
                          </span>
                          <span className="ml-1">· {formatDOB(athlete.date_of_birth)}</span>
                        </div>
                      )}
                      {athlete.primary_position && (
                        <div className="text-xs text-gray-600">
                          {athlete.primary_position}
                          {athlete.jersey_number && <span className="ml-1">· #{athlete.jersey_number}</span>}
                        </div>
                      )}
                      <div className="text-sm text-brand-primary">{athlete.email}</div>
                    </div>
                    <button
                      onClick={() => handleRemoveAthlete(athlete.id)}
                      className="text-red-600 hover:text-red-800 text-sm uppercase ml-2 flex-shrink-0"
                    >
                      Remove
                    </button>
                  </div>
                </div>
              ))}
              {roster.length === 0 && (
                <div className={`text-center py-8 border-2 border-dashed transition-colors ${
                  isDragOver ? 'border-green-500 text-green-600' : 'border-gray-300 text-gray-500'
                }`}>
                  Drag athletes here or click "Add →"
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default RosterManagement;

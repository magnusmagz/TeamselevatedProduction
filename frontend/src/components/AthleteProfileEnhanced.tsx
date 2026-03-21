import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import CommunicationHistory from './communications/CommunicationHistory';
import EmailCompose from './communications/EmailCompose';
import SmsCompose from './communications/SmsCompose';
import AthleteForm from './AthleteForm';
import PlayerCard from './PlayerCard';
import AthletePhotoUpload from './AthletePhotoUpload';

interface Guardian {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  mobile_phone: string;
  work_phone?: string;
  relationship_type: string;
  is_primary_contact: number;
  has_legal_custody: number;
  can_authorize_medical: number;
  can_pickup: number;
  receives_communications: number;
  financial_responsible: number;
}

interface Team {
  id: number;
  team_id: number;
  user_id: number;
  athlete_id: number;
  jersey_number?: string;
  position?: string;
  primary_position?: string;
  team_name: string;
  created_at: string;
}

interface MedicalAlert {
  type: 'critical' | 'warning' | 'info';
  message: string;
}

interface MedicalInfo {
  athlete_id: number;
  exists: boolean;
  allergies?: string;
  allergy_severity?: string;
  medical_conditions?: string;
  medications?: string;
  physician_name?: string;
  physician_phone?: string;
  physician_address?: string;
  insurance_provider?: string;
  insurance_policy_number?: string;
  insurance_group_number?: string;
  last_physical_date?: string;
  physical_expiry_date?: string;
  height_inches?: number;
  weight_lbs?: number;
  blood_type?: string;
  emergency_treatment_consent?: number;
  special_instructions?: string;
  concussion_history?: string;
  has_asthma?: number;
  inhaler_location?: string;
  has_epipen?: number;
  epipen_location?: string;
  alerts?: MedicalAlert[];
}

interface Athlete {
  id: number;
  first_name: string;
  last_name: string;
  preferred_name?: string;
  date_of_birth: string;
  gender: string;
  email: string;
  school_name?: string;
  grade_level?: string;
  home_address_line1: string;
  home_address_line2?: string;
  city: string;
  state: string;
  zip_code: string;
  country: string;
  photo_url?: string;
  active_status: number;
  guardians: Guardian[];
}

const AthleteProfileEnhanced: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { athleteId } = useParams<{ athleteId: string }>();
  const { currentClubId, activeContext } = useOrg();
  const [athlete, setAthlete] = useState<Athlete | null>(null);
  const [teams, setTeams] = useState<Team[]>([]);
  const [medical, setMedical] = useState<MedicalInfo | null>(null);
  const [selectedTeamIndex, setSelectedTeamIndex] = useState(0);
  const [activeTab, setActiveTab] = useState('profile');
  const [loading, setLoading] = useState(true);
  const [showEmailCompose, setShowEmailCompose] = useState(false);
  const [showSmsCompose, setShowSmsCompose] = useState(false);
  const [composeRecipient, setComposeRecipient] = useState<any>(null);
  const [editingField, setEditingField] = useState<string | null>(null);
  const [showEditForm, setShowEditForm] = useState(false);
  const [showPlayerCard, setShowPlayerCard] = useState(false);

  const SOCCER_POSITIONS = [
    'Goalkeeper', 'Center Back', 'Left Back', 'Right Back',
    'Defensive Midfielder', 'Central Midfielder', 'Attacking Midfielder',
    'Left Wing', 'Right Wing', 'Striker', 'Forward'
  ];

  const updateTeamMember = async (teamMemberId: number, updates: Record<string, any>) => {
    try {
      const token = localStorage.getItem('auth_token');
      await fetch(`${API_URL}/legacy/team-players-gateway.php`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ team_member_id: teamMemberId, ...updates }),
      });
      // Refresh teams data
      const teamsResponse = await fetch(`${API_URL}/legacy/team-players-gateway.php`);
      const teamsData = await teamsResponse.json();
      if (teamsData.success) {
        const athleteTeams = teamsData.team_players
          .filter((tp: Team) => tp.athlete_id === parseInt(athleteId!))
          .map((tp: Team) => ({ ...tp, position: tp.primary_position }));
        setTeams(athleteTeams);
      }
      setEditingField(null);
    } catch (err) {
      console.error('Failed to update:', err);
    }
  };

  useEffect(() => {
    const fetchAthleteData = async () => {
      if (!athleteId) return;

      try {
        // Fetch athlete with guardian data
        const athleteResponse = await fetch(`${API_URL}/legacy/athletes-gateway.php?id=${athleteId}`);
        const athleteData = await athleteResponse.json();

        if (athleteData.id) {
          setAthlete(athleteData);
        }

        // Fetch team assignments
        const teamsResponse = await fetch(`${API_URL}/legacy/team-players-gateway.php`);
        const teamsData = await teamsResponse.json();

        if (teamsData.success) {
          const athleteTeams = teamsData.team_players
            .filter((tp: Team) => tp.athlete_id === parseInt(athleteId))
            .map((tp: Team) => ({ ...tp, position: tp.primary_position }));
          setTeams(athleteTeams);
        }

        // Fetch medical information
        const medicalResponse = await fetch(`${API_URL}/legacy/medical-gateway.php?athlete_id=${athleteId}`);
        const medicalData = await medicalResponse.json();

        if (medicalData.success && medicalData.medical) {
          setMedical(medicalData.medical);
        }
      } catch (error) {
        console.error('Error fetching athlete data:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchAthleteData();
  }, [athleteId]);

  const calculateAge = (birthDate: string) => {
    const today = new Date();
    const birth = new Date(birthDate);
    let age = today.getFullYear() - birth.getFullYear();
    const monthDiff = today.getMonth() - birth.getMonth();

    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
      age--;
    }

    return age;
  };

  const getAgeQuarter = (dob: string): string | null => {
    if (!dob) return null;
    const month = new Date(dob).getMonth();
    if (month <= 2) return 'Q1';
    if (month <= 5) return 'Q2';
    if (month <= 8) return 'Q3';
    return 'Q4';
  };

  const getUGroup = (dob: string): string | null => {
    if (!dob) return null;
    const birth = new Date(dob);
    if (isNaN(birth.getTime())) return null;
    const today = new Date();
    const seasonYear = today.getMonth() >= 7 ? today.getFullYear() + 1 : today.getFullYear();
    return `U${seasonYear - birth.getFullYear()}`;
  };

  const formatPhoneForDisplay = (phone: string) => {
    return phone.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
  };

  const getPrimaryGuardian = () => {
    return athlete?.guardians.find(g => g.is_primary_contact === 1) || athlete?.guardians[0];
  };

  const getEmergencyContact = () => {
    return athlete?.guardians.find(g => g.is_primary_contact === 0 && g.can_pickup === 1);
  };

  if (loading) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-brand-primary py-12">Loading athlete profile...</div>
      </div>
    );
  }

  if (!athlete) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-brand-primary py-12">Athlete not found</div>
      </div>
    );
  }

  const primaryGuardian = getPrimaryGuardian();
  const emergencyContact = getEmergencyContact();

  return (
    <>
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="border-b border-brand-secondary pb-6 mb-6">
        <div className="mb-4">
          <button onClick={() => window.history.back()} className="text-sm text-brand-primary hover:underline">
            ← Back to Athletes
          </button>
        </div>
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-5">
            <AthletePhotoUpload
              athleteId={athlete.id}
              currentPhotoUrl={athlete.photo_url}
              firstName={athlete.first_name}
              lastName={athlete.last_name}
              onPhotoUpdated={(newUrl) => setAthlete(prev => prev ? { ...prev, photo_url: newUrl } : prev)}
              size="large"
            />
            <h1 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">
              {athlete.preferred_name || athlete.first_name} {athlete.last_name}
            </h1>
          </div>
          <div className="flex gap-3">
            <button
              onClick={() => setShowPlayerCard(true)}
              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-3 hover:bg-gray-50 uppercase font-semibold text-sm"
            >
              Player Card
            </button>
            <button
              onClick={() => setShowEditForm(true)}
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-3 hover:bg-brand-primary uppercase font-semibold text-sm"
            >
              Edit Profile
            </button>
          </div>
        </div>
      </div>

      {/* Team Selector */}
      {teams.length > 0 && (
        <div className="border border-brand-secondary rounded-md p-4 mb-6">
          <div className="flex gap-3 flex-wrap">
            {teams.map((team, index) => (
              <button
                key={team.id}
                onClick={() => setSelectedTeamIndex(index)}
                className={`flex-1 min-w-60 p-4 border border-brand-secondary rounded-md text-center ${
                  selectedTeamIndex === index
                    ? 'bg-brand-primary text-white'
                    : 'bg-white text-brand-primary hover:bg-gray-50'
                }`}
              >
                <div className="font-bold uppercase">{team.team_name}</div>
                <div className="text-sm">
                  {team.jersey_number && `#${team.jersey_number} • `}
                  {team.position || 'Position TBD'}
                </div>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Quick Info Bar */}
      <div className="border border-brand-secondary rounded-md p-6 mb-6">
        <div className="grid grid-cols-2 md:grid-cols-5 gap-6">
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-600 mb-1">Jersey</div>
            {editingField === 'jersey' && teams[selectedTeamIndex] ? (
              <input
                type="number"
                defaultValue={teams[selectedTeamIndex]?.jersey_number || ''}
                min="0"
                max="99"
                autoFocus
                onBlur={(e) => {
                  const val = e.target.value ? parseInt(e.target.value) : null;
                  updateTeamMember(teams[selectedTeamIndex].id, { jersey_number: val });
                }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    const val = (e.target as HTMLInputElement).value ? parseInt((e.target as HTMLInputElement).value) : null;
                    updateTeamMember(teams[selectedTeamIndex].id, { jersey_number: val });
                  }
                  if (e.key === 'Escape') setEditingField(null);
                }}
                className="w-20 text-2xl font-bold border border-brand-secondary rounded-md px-2 py-1 text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
              />
            ) : (
              <div
                className="text-2xl font-bold cursor-pointer hover:text-brand-accent flex items-center gap-2"
                onClick={() => setEditingField('jersey')}
              >
                {teams[selectedTeamIndex]?.jersey_number ? `#${teams[selectedTeamIndex].jersey_number}` : 'TBD'}
                <svg className="w-4 h-4 text-gray-400 hover:text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
              </div>
            )}
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-600 mb-1">Position</div>
            {editingField === 'position' && teams[selectedTeamIndex] ? (
              <select
                defaultValue={teams[selectedTeamIndex]?.position || ''}
                autoFocus
                onChange={(e) => {
                  const pos = e.target.value;
                  updateTeamMember(teams[selectedTeamIndex].id, {
                    primary_position: pos,
                    positions: pos ? [pos] : [],
                  });
                }}
                onBlur={() => setEditingField(null)}
                className="text-sm font-bold border border-brand-secondary rounded-md px-2 py-1 text-brand-primary focus:ring-brand-primary focus:border-brand-primary"
              >
                <option value="">Select position...</option>
                {SOCCER_POSITIONS.map(p => (
                  <option key={p} value={p}>{p}</option>
                ))}
              </select>
            ) : (
              <div
                className="text-2xl font-bold cursor-pointer hover:text-brand-accent flex items-center gap-2"
                onClick={() => setEditingField('position')}
              >
                {teams[selectedTeamIndex]?.position || 'TBD'}
                <svg className="w-4 h-4 text-gray-400 hover:text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
              </div>
            )}
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-600 mb-1">Status</div>
            <div className="text-2xl font-bold">
              {athlete.active_status ? 'Active' : 'Inactive'}
            </div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-600 mb-1">Age</div>
            <div className="text-2xl font-bold">
              {calculateAge(athlete.date_of_birth)}
              {athlete.date_of_birth && (
                <>
                  <span className="ml-2 text-xs font-semibold bg-brand-secondary text-brand-primary px-2 py-0.5 rounded-full align-middle">
                    {getAgeQuarter(athlete.date_of_birth)}
                  </span>
                  <span className="ml-1 text-xs font-semibold bg-brand-primary text-white px-2 py-0.5 rounded-full align-middle">
                    {getUGroup(athlete.date_of_birth)}
                  </span>
                </>
              )}
            </div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-600 mb-1">Total Teams</div>
            <div className="text-2xl font-bold">
              {teams.length}
            </div>
          </div>
        </div>
      </div>

      {/* All Teams Overview */}
      {teams.length > 1 && (
        <div className="border border-brand-secondary rounded-md p-6 mb-6">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-3 border-b border-brand-primary">
            ALL TEAM ASSIGNMENTS
          </div>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {teams.map((team, index) => (
              <div key={team.id} className="border border-brand-primary p-4">
                <div className="font-bold mb-3 flex items-center justify-between">
                  <span>{team.team_name}</span>
                  {index === 0 && <span className="px-2 py-1 text-xs bg-brand-primary text-white">PRIMARY</span>}
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span>Jersey Number:</span>
                    <span>{team.jersey_number || 'TBD'}</span>
                  </div>
                  <div className="flex justify-between">
                    <span>Position:</span>
                    <span>{team.position || 'TBD'}</span>
                  </div>
                  <div className="flex justify-between">
                    <span>Joined:</span>
                    <span>{new Date(team.created_at).toLocaleDateString()}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Emergency Information */}
      <div className="border-3 border-brand-primary p-6 mb-6 bg-gray-50">
        <div className="text-sm font-bold uppercase tracking-wide mb-4">
          EMERGENCY INFORMATION
        </div>
        <div className="grid md:grid-cols-2 gap-6">
          <div>
            {primaryGuardian && (
              <div className="mb-4">
                <div className="font-bold mb-2">Primary Contact</div>
                <div>{primaryGuardian.first_name} {primaryGuardian.last_name} ({primaryGuardian.relationship_type})</div>
                <button
                  onClick={() => {
                    setComposeRecipient({ id: primaryGuardian.id, type: 'guardian', first_name: primaryGuardian.first_name, last_name: primaryGuardian.last_name, phone: primaryGuardian.mobile_phone, email: primaryGuardian.email });
                    setShowSmsCompose(true);
                  }}
                  className="text-lg font-bold text-brand-primary hover:underline"
                >
                  {formatPhoneForDisplay(primaryGuardian.mobile_phone)}
                </button>
              </div>
            )}
            {emergencyContact && emergencyContact.id !== primaryGuardian?.id && (
              <div>
                <div className="font-bold mb-2">Emergency Contact</div>
                <div>{emergencyContact.first_name} {emergencyContact.last_name} ({emergencyContact.relationship_type})</div>
                <button
                  onClick={() => {
                    setComposeRecipient({ id: emergencyContact.id, type: 'guardian', first_name: emergencyContact.first_name, last_name: emergencyContact.last_name, phone: emergencyContact.mobile_phone, email: emergencyContact.email });
                    setShowSmsCompose(true);
                  }}
                  className="text-lg font-bold text-brand-primary hover:underline"
                >
                  {formatPhoneForDisplay(emergencyContact.mobile_phone)}
                </button>
              </div>
            )}
          </div>
          <div>
            {medical?.alerts && medical.alerts.length > 0 ? (
              <div className="space-y-2">
                {medical.alerts.map((alert, index) => (
                  <div
                    key={index}
                    className={`p-3 border-2 ${
                      alert.type === 'critical'
                        ? 'border-red-600 bg-red-50'
                        : alert.type === 'warning'
                        ? 'border-yellow-500 bg-yellow-50'
                        : 'border-blue-500 bg-blue-50'
                    }`}
                  >
                    <div className={`font-bold ${
                      alert.type === 'critical'
                        ? 'text-red-800'
                        : alert.type === 'warning'
                        ? 'text-yellow-800'
                        : 'text-blue-800'
                    }`}>
                      {alert.type === 'critical' && '⚠️ CRITICAL: '}
                      {alert.type === 'warning' && '⚠ WARNING: '}
                      {alert.type === 'info' && 'ℹ INFO: '}
                      {alert.message}
                    </div>
                  </div>
                ))}
              </div>
            ) : medical && !medical.exists ? (
              <div className="border-2 border-yellow-500 bg-yellow-50 p-4">
                <div className="font-bold text-yellow-800 mb-2">⚠ NO MEDICAL INFO</div>
                <div className="text-yellow-700">
                  • No medical information on file
                </div>
                <div className="text-yellow-700">
                  • Please update medical records
                </div>
              </div>
            ) : (
              <div className="border-2 border-green-600 bg-green-50 p-4">
                <div className="font-bold text-green-800">✓ Medical Info on File</div>
                <div className="text-green-700 text-sm mt-1">
                  No current alerts
                </div>
              </div>
            )}

            {medical?.insurance_provider && (
              <div className="mt-3 p-3 border border-gray-300">
                <div className="font-bold text-sm">Insurance</div>
                <div className="text-sm">{medical.insurance_provider}</div>
                {medical.insurance_policy_number && (
                  <div className="text-xs text-gray-600">Policy: {medical.insurance_policy_number}</div>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Main Content Grid */}
      <div className="grid md:grid-cols-2 gap-6 mb-6">
        {/* Player Details */}
        <div className="border border-brand-secondary rounded-md p-6">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-3 border-b border-brand-primary">
            Player Details
          </div>
          <div className="space-y-3">
            <div className="flex justify-between py-2 border-b border-gray-200">
              <span className="font-medium">Birth Date</span>
              <span>{new Date(athlete.date_of_birth).toLocaleDateString()}</span>
            </div>
            <div className="flex justify-between py-2 border-b border-gray-200">
              <span className="font-medium">Gender</span>
              <span>{athlete.gender}</span>
            </div>
            <div className="flex justify-between py-2 border-b border-gray-200">
              <span className="font-medium">Total Teams</span>
              <span>{teams.length} Active</span>
            </div>
            <div className="flex justify-between py-2 border-b border-gray-200">
              <span className="font-medium">School</span>
              <span>{athlete.school_name || 'Not specified'}</span>
            </div>
            <div className="flex justify-between py-2 border-b border-gray-200">
              <span className="font-medium">Grade</span>
              <span>{athlete.grade_level || 'Not specified'}</span>
            </div>
            <div className="flex justify-between py-2">
              <span className="font-medium">Email</span>
              <span className="text-sm">{athlete.email}</span>
            </div>
          </div>
        </div>

        {/* Family Information */}
        <div className="border border-brand-secondary rounded-md p-6">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-3 border-b border-brand-primary">
            Family & Contacts
          </div>
          <div className="space-y-4">
            <div>
              <div className="font-bold mb-3">Address</div>
              <div className="text-sm leading-relaxed">
                {athlete.home_address_line1}<br/>
                {athlete.home_address_line2 && <>{athlete.home_address_line2}<br/></>}
                {athlete.city}, {athlete.state} {athlete.zip_code}
              </div>
            </div>

            <div>
              <div className="font-bold mb-3">Guardians</div>
              <div className="space-y-3">
                {athlete.guardians.map((guardian) => (
                  <div key={guardian.id} className="border border-gray-300 p-3">
                    <div className="flex justify-between items-start mb-2">
                      <span className="font-medium">
                        {guardian.first_name} {guardian.last_name}
                      </span>
                      <span className="text-xs px-2 py-1 bg-gray-100">
                        {guardian.is_primary_contact ? 'PRIMARY' : (guardian.relationship_type || 'GUARDIAN').toUpperCase()}
                      </span>
                    </div>
                    <div className="text-sm text-gray-600 space-y-1">
                      <button
                        onClick={() => {
                          setComposeRecipient({ id: guardian.id, type: 'guardian', first_name: guardian.first_name, last_name: guardian.last_name, phone: guardian.mobile_phone, email: guardian.email });
                          setShowSmsCompose(true);
                        }}
                        className="text-brand-primary hover:underline cursor-pointer block"
                      >
                        {formatPhoneForDisplay(guardian.mobile_phone)}
                      </button>
                      <button
                        onClick={() => {
                          setComposeRecipient({ id: guardian.id, type: 'guardian', first_name: guardian.first_name, last_name: guardian.last_name, phone: guardian.mobile_phone, email: guardian.email });
                          setShowEmailCompose(true);
                        }}
                        className="text-brand-primary hover:underline cursor-pointer block"
                      >
                        {guardian.email}
                      </button>
                    </div>
                    <div className="text-xs text-gray-500 mt-2">
                      {guardian.can_authorize_medical ? '✓ Medical' : '✗ Medical'} |
                      {guardian.can_pickup ? ' ✓ Pickup' : ' ✗ Pickup'} |
                      {guardian.financial_responsible ? ' ✓ Financial' : ' ✗ Financial'}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs Section */}
      <div className="border border-brand-secondary rounded-md mb-6">
        <div className="flex border-b border-brand-secondary">
          {[
            { id: 'profile', label: 'Extended Profile' },
            { id: 'medical', label: 'Medical Info' },
            { id: 'documents', label: 'Documents' },
            { id: 'communications', label: 'Communications' },
            { id: 'performance', label: 'Performance' }
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex-1 px-4 py-3 text-sm font-medium uppercase tracking-wide border-r border-brand-primary last:border-r-0 ${
                activeTab === tab.id
                  ? 'bg-brand-primary text-white'
                  : 'bg-white text-brand-primary hover:bg-gray-50'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="p-6">
          {activeTab === 'profile' && (
            <div className="text-center py-8">
              <div className="text-gray-600">Extended profile information</div>
              <div className="text-sm text-gray-500 mt-2">
                Additional fields like dominant foot, years experience, etc. can be added here
              </div>
            </div>
          )}

          {activeTab === 'medical' && medical?.exists && (
            <div className="space-y-6">
              {/* Critical Information */}
              {(medical.allergies || medical.medical_conditions) && (
                <div className="border-2 border-red-600 p-4">
                  <div className="font-bold text-red-800 mb-3">Critical Medical Information</div>
                  {medical.allergies && (
                    <div className="mb-3">
                      <div className="font-semibold">Allergies ({medical.allergy_severity})</div>
                      <div className="text-red-700">{medical.allergies}</div>
                      {medical.has_epipen && (
                        <div className="mt-1 text-sm">
                          <strong>EpiPen Location:</strong> {medical.epipen_location}
                        </div>
                      )}
                    </div>
                  )}
                  {medical.medical_conditions && (
                    <div className="mb-3">
                      <div className="font-semibold">Medical Conditions</div>
                      <div>{medical.medical_conditions}</div>
                      {medical.has_asthma && (
                        <div className="mt-1 text-sm">
                          <strong>Inhaler Location:</strong> {medical.inhaler_location}
                        </div>
                      )}
                    </div>
                  )}
                  {medical.special_instructions && (
                    <div>
                      <div className="font-semibold">Special Instructions</div>
                      <div className="text-sm">{medical.special_instructions}</div>
                    </div>
                  )}
                </div>
              )}

              {/* Physician & Insurance */}
              <div className="grid md:grid-cols-2 gap-4">
                {medical.physician_name && (
                  <div className="border border-gray-300 p-4">
                    <div className="font-bold mb-2">Physician Information</div>
                    <div className="space-y-1 text-sm">
                      <div><strong>Name:</strong> {medical.physician_name}</div>
                      {medical.physician_phone && <div><strong>Phone:</strong> {medical.physician_phone}</div>}
                      {medical.physician_address && <div><strong>Address:</strong> {medical.physician_address}</div>}
                    </div>
                  </div>
                )}
                {medical.insurance_provider && (
                  <div className="border border-gray-300 p-4">
                    <div className="font-bold mb-2">Insurance Information</div>
                    <div className="space-y-1 text-sm">
                      <div><strong>Provider:</strong> {medical.insurance_provider}</div>
                      {medical.insurance_policy_number && <div><strong>Policy #:</strong> {medical.insurance_policy_number}</div>}
                      {medical.insurance_group_number && <div><strong>Group #:</strong> {medical.insurance_group_number}</div>}
                    </div>
                  </div>
                )}
              </div>

              {/* Physical Stats & Exam */}
              <div className="grid md:grid-cols-3 gap-4">
                <div className="border border-gray-300 p-4">
                  <div className="font-bold mb-2">Physical Stats</div>
                  <div className="space-y-1 text-sm">
                    {medical.height_inches && <div><strong>Height:</strong> {Math.floor(medical.height_inches / 12)}' {medical.height_inches % 12}"</div>}
                    {medical.weight_lbs && <div><strong>Weight:</strong> {medical.weight_lbs} lbs</div>}
                    {medical.blood_type && <div><strong>Blood Type:</strong> {medical.blood_type}</div>}
                  </div>
                </div>
                <div className="border border-gray-300 p-4">
                  <div className="font-bold mb-2">Physical Exam</div>
                  <div className="space-y-1 text-sm">
                    {medical.last_physical_date && <div><strong>Date:</strong> {new Date(medical.last_physical_date).toLocaleDateString()}</div>}
                    {medical.physical_expiry_date && <div><strong>Expires:</strong> {new Date(medical.physical_expiry_date).toLocaleDateString()}</div>}
                  </div>
                </div>
                {medical.medications && (
                  <div className="border border-gray-300 p-4">
                    <div className="font-bold mb-2">Current Medications</div>
                    <div className="text-sm">{medical.medications}</div>
                  </div>
                )}
              </div>

              {/* Concussion History */}
              {medical.concussion_history && (
                <div className="border border-yellow-500 bg-yellow-50 p-4">
                  <div className="font-bold text-yellow-800 mb-2">Concussion History</div>
                  <div className="text-sm">{medical.concussion_history}</div>
                </div>
              )}
            </div>
          )}

          {activeTab === 'medical' && (!medical || !medical.exists) && (
            <div className="text-center py-8">
              <div className="text-gray-600">No medical information on file</div>
              <button className="mt-4 px-6 py-2 bg-brand-primary text-white hover:bg-brand-primary">
                Add Medical Information
              </button>
            </div>
          )}

          {activeTab === 'documents' && (
            <div className="text-center py-8">
              <div className="text-gray-600">Document management</div>
              <div className="text-sm text-gray-500 mt-2">
                Birth certificate, medical release, registration forms, etc.
              </div>
            </div>
          )}

          {activeTab === 'communications' && athlete && (
            <CommunicationHistory
              contactType="athlete"
              contactId={athlete.id}
              clubProfileId={currentClubId || 0}
            />
          )}

          {activeTab === 'performance' && (
            <div className="text-center py-8">
              <div className="text-gray-600">Performance tracking</div>
              <div className="text-sm text-gray-500 mt-2">
                Attendance, games played, statistics by team
              </div>
            </div>
          )}
        </div>
      </div>

      </div>

      {/* Edit Profile Modal */}
      {showEditForm && (
        <div className="fixed inset-0 z-50 overflow-y-auto">
          <div className="fixed inset-0 bg-black bg-opacity-30" onClick={() => setShowEditForm(false)} />
          <div className="relative min-h-screen flex items-start justify-center py-8 px-4">
            <div className="relative bg-white rounded-md shadow-xl w-full max-w-3xl">
              <div className="flex items-center justify-between px-6 py-4 border-b border-brand-secondary">
                <h2 className="text-lg font-bold text-brand-primary uppercase tracking-wide">Edit Athlete</h2>
                <button
                  onClick={() => setShowEditForm(false)}
                  className="text-gray-400 hover:text-gray-600 text-xl font-bold"
                >
                  ✕
                </button>
              </div>
              <div className="p-6">
                <AthleteForm
                  athlete={{
                    ...athlete,
                    guardians: athlete.guardians,
                  }}
                  onSubmit={() => {
                    setShowEditForm(false);
                    window.location.reload();
                  }}
                  onClose={() => setShowEditForm(false)}
                />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Compose Modals */}
      {showEmailCompose && (
        <EmailCompose
          isOpen={showEmailCompose}
          onClose={() => { setShowEmailCompose(false); setComposeRecipient(null); }}
          clubProfileId={currentClubId || 0}
          preselectedRecipients={composeRecipient ? [{ ...composeRecipient, suppressed: false }] : []}
        />
      )}
      {showSmsCompose && (
        <SmsCompose
          isOpen={showSmsCompose}
          onClose={() => { setShowSmsCompose(false); setComposeRecipient(null); }}
          clubProfileId={currentClubId || 0}
          preselectedRecipients={composeRecipient ? [{ ...composeRecipient, suppressed: false }] : []}
        />
      )}
      {/* Player Card Modal */}
      {showPlayerCard && athlete && (
        <>
          <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" onClick={() => setShowPlayerCard(false)}>
            <div className="relative" onClick={(e) => e.stopPropagation()}>
              <button
                onClick={() => setShowPlayerCard(false)}
                className="absolute -top-3 -right-3 bg-white rounded-full w-8 h-8 flex items-center justify-center shadow-md text-brand-primary font-bold hover:bg-gray-100 z-10"
              >
                X
              </button>
              <PlayerCard
                player={{
                  id: athlete.id,
                  first_name: athlete.first_name,
                  last_name: athlete.last_name,
                  date_of_birth: athlete.date_of_birth,
                  photo_url: undefined,
                  gender: athlete.gender,
                }}
                team={{
                  name: teams[selectedTeamIndex]?.team_name || '',
                  jersey_number: teams[selectedTeamIndex]?.jersey_number ? parseInt(teams[selectedTeamIndex].jersey_number!) : undefined,
                  primary_position: teams[selectedTeamIndex]?.primary_position || teams[selectedTeamIndex]?.position || undefined,
                }}
                club={{
                  name: activeContext?.scope_name || '',
                }}
                size="large"
              />
            </div>
          </div>
        </>
      )}
    </>
  );

};

export default AthleteProfileEnhanced;
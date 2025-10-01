import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';

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
  jersey_number?: string;
  position?: string;
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
  active_status: number;
  guardians: Guardian[];
}

const AthleteProfileEnhanced: React.FC = () => {
  const { athleteId } = useParams<{ athleteId: string }>();
  const [athlete, setAthlete] = useState<Athlete | null>(null);
  const [teams, setTeams] = useState<Team[]>([]);
  const [medical, setMedical] = useState<MedicalInfo | null>(null);
  const [selectedTeamIndex, setSelectedTeamIndex] = useState(0);
  const [activeTab, setActiveTab] = useState('profile');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchAthleteData = async () => {
      if (!athleteId) return;

      try {
        // Fetch athlete with guardian data
        const athleteResponse = await fetch(`http://localhost:8889/athletes-gateway.php?id=${athleteId}`);
        const athleteData = await athleteResponse.json();

        if (athleteData.id) {
          setAthlete(athleteData);
        }

        // Fetch team assignments
        const teamsResponse = await fetch(`http://localhost:8889/team-players-gateway.php`);
        const teamsData = await teamsResponse.json();

        if (teamsData.success) {
          const athleteTeams = teamsData.team_players.filter((tp: Team) => tp.user_id === parseInt(athleteId));
          setTeams(athleteTeams);
        }

        // Fetch medical information
        const medicalResponse = await fetch(`http://localhost:8889/medical-gateway.php?athlete_id=${athleteId}`);
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
        <div className="text-center text-forest-800 py-12">Loading athlete profile...</div>
      </div>
    );
  }

  if (!athlete) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-forest-800 py-12">Athlete not found</div>
      </div>
    );
  }

  const primaryGuardian = getPrimaryGuardian();
  const emergencyContact = getEmergencyContact();

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="border-b-2 border-forest-800 pb-6 mb-6">
        <div className="mb-4">
          <button onClick={() => window.history.back()} className="text-sm text-forest-800 hover:underline">
            ← Back to Athletes
          </button>
        </div>
        <h1 className="text-3xl font-bold text-forest-800 uppercase tracking-wide">
          {athlete.preferred_name || athlete.first_name} {athlete.last_name}
        </h1>
      </div>

      {/* Team Selector */}
      {teams.length > 0 && (
        <div className="border-2 border-forest-800 p-4 mb-6">
          <div className="flex gap-3 flex-wrap">
            {teams.map((team, index) => (
              <button
                key={team.id}
                onClick={() => setSelectedTeamIndex(index)}
                className={`flex-1 min-w-60 p-4 border-2 border-forest-800 text-center ${
                  selectedTeamIndex === index
                    ? 'bg-forest-800 text-white'
                    : 'bg-white text-forest-800 hover:bg-gray-50'
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
      <div className="border-2 border-forest-800 p-6 mb-6">
        <div className="grid grid-cols-2 md:grid-cols-5 gap-6">
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-600 mb-1">Jersey</div>
            <div className="text-2xl font-bold">
              {teams[selectedTeamIndex]?.jersey_number ? `#${teams[selectedTeamIndex].jersey_number}` : 'TBD'}
            </div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-600 mb-1">Position</div>
            <div className="text-2xl font-bold">
              {teams[selectedTeamIndex]?.position || 'TBD'}
            </div>
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
        <div className="border-2 border-forest-800 p-6 mb-6">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-3 border-b border-forest-800">
            ALL TEAM ASSIGNMENTS
          </div>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {teams.map((team, index) => (
              <div key={team.id} className="border border-forest-800 p-4">
                <div className="font-bold mb-3 flex items-center justify-between">
                  <span>{team.team_name}</span>
                  {index === 0 && <span className="px-2 py-1 text-xs bg-forest-800 text-white">PRIMARY</span>}
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
      <div className="border-3 border-forest-800 p-6 mb-6 bg-gray-50">
        <div className="text-sm font-bold uppercase tracking-wide mb-4">
          EMERGENCY INFORMATION
        </div>
        <div className="grid md:grid-cols-2 gap-6">
          <div>
            {primaryGuardian && (
              <div className="mb-4">
                <div className="font-bold mb-2">Primary Contact</div>
                <div>{primaryGuardian.first_name} {primaryGuardian.last_name} ({primaryGuardian.relationship_type})</div>
                <a href={`tel:${primaryGuardian.mobile_phone}`} className="text-lg font-bold text-forest-800 hover:underline">
                  📞 {formatPhoneForDisplay(primaryGuardian.mobile_phone)}
                </a>
              </div>
            )}
            {emergencyContact && emergencyContact.id !== primaryGuardian?.id && (
              <div>
                <div className="font-bold mb-2">Emergency Contact</div>
                <div>{emergencyContact.first_name} {emergencyContact.last_name} ({emergencyContact.relationship_type})</div>
                <a href={`tel:${emergencyContact.mobile_phone}`} className="text-lg font-bold text-forest-800 hover:underline">
                  📞 {formatPhoneForDisplay(emergencyContact.mobile_phone)}
                </a>
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
        <div className="border-2 border-forest-800 p-6">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-3 border-b border-forest-800">
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
        <div className="border-2 border-forest-800 p-6">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-3 border-b border-forest-800">
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
                        {guardian.is_primary_contact ? 'PRIMARY' : guardian.relationship_type.toUpperCase()}
                      </span>
                    </div>
                    <div className="text-sm text-gray-600">
                      📱 {formatPhoneForDisplay(guardian.mobile_phone)}<br/>
                      ✉️ {guardian.email}
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
      <div className="border-2 border-forest-800 mb-6">
        <div className="flex border-b-2 border-forest-800">
          {[
            { id: 'profile', label: 'Extended Profile' },
            { id: 'medical', label: 'Medical Info' },
            { id: 'documents', label: 'Documents' },
            { id: 'performance', label: 'Performance' }
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex-1 px-4 py-3 text-sm font-medium uppercase tracking-wide border-r border-forest-800 last:border-r-0 ${
                activeTab === tab.id
                  ? 'bg-forest-800 text-white'
                  : 'bg-white text-forest-800 hover:bg-gray-50'
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
              <button className="mt-4 px-6 py-2 bg-forest-800 text-white hover:bg-forest-700">
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

      {/* Contact Button */}
      <button className="w-full bg-white border-2 border-forest-800 text-forest-800 py-3 px-6 font-bold uppercase hover:bg-forest-800 hover:text-white transition-colors">
        Contact Family
      </button>
    </div>
  );
};

export default AthleteProfileEnhanced;
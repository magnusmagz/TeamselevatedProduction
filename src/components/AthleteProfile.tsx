import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';

interface Athlete {
  id: number;
  first_name: string;
  middle_initial?: string;
  last_name: string;
  preferred_name?: string;
  date_of_birth: string;
  gender: string;
  home_address_line1: string;
  home_address_line2?: string;
  city: string;
  state: string;
  zip_code: string;
  school_name?: string;
  grade_level?: number;
  photo_url?: string;
  dietary_restrictions?: any;
}

interface Guardian {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  mobile_phone: string;
  work_phone?: string;
  relationship_type: string;
  is_primary_contact: boolean;
  can_authorize_medical: boolean;
  can_pickup: boolean;
  financial_responsible: boolean;
}

interface TeamAssignment {
  team_id: number;
  team_name: string;
  jersey_number?: number;
  jersey_number_alt?: number;
  positions: string[];
  primary_position?: string;
  team_priority: 'primary' | 'secondary' | 'guest';
  status: 'active' | 'injured' | 'suspended' | 'inactive';
  join_date: string;
}

interface EmergencyContact {
  id: number;
  contact_name: string;
  relationship: string;
  primary_phone: string;
  alternate_phone?: string;
  can_authorize_medical: boolean;
  priority_order: number;
}

interface MedicalRecord {
  physical_exam_date: string;
  physician_name: string;
  physician_phone: string;
  preferred_hospital?: string;
  blood_type?: string;
  has_asthma: boolean;
  has_diabetes: boolean;
  has_seizures: boolean;
  has_heart_condition: boolean;
}

interface Allergy {
  id: number;
  allergy_type: string;
  allergen: string;
  reaction_severity: string;
  reaction_description?: string;
  treatment_required?: string;
  epipen_required: boolean;
}

interface Medication {
  id: number;
  medication_name: string;
  dosage: string;
  frequency: string;
  prescribing_doctor?: string;
  is_active: boolean;
}

interface Insurance {
  id: number;
  is_primary: boolean;
  provider_name: string;
  policy_number: string;
  group_number?: string;
  policy_holder_name: string;
  provider_phone: string;
}

interface Document {
  id: number;
  document_type: string;
  document_name: string;
  signed_date?: string;
  expires_date?: string;
  is_current: boolean;
  is_required: boolean;
}

interface AthleteSport {
  sport_type: string;
  years_experience: number;
  skill_level?: string;
  dominant_hand?: string;
  dominant_foot?: string;
  sport_specific_id?: string;
}

const AthleteProfile: React.FC = () => {
  const { athleteId } = useParams<{ athleteId: string }>();
  const [activeTab, setActiveTab] = useState('performance');
  const [selectedTeam, setSelectedTeam] = useState<number>(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // State for all athlete data
  const [athlete, setAthlete] = useState<Athlete | null>(null);
  const [guardians, setGuardians] = useState<Guardian[]>([]);
  const [teams, setTeams] = useState<TeamAssignment[]>([]);
  const [emergencyContacts, setEmergencyContacts] = useState<EmergencyContact[]>([]);
  const [medicalRecord, setMedicalRecord] = useState<MedicalRecord | null>(null);
  const [allergies, setAllergies] = useState<Allergy[]>([]);
  const [medications, setMedications] = useState<Medication[]>([]);
  const [insurance, setInsurance] = useState<Insurance[]>([]);
  const [documents, setDocuments] = useState<Document[]>([]);
  const [sports, setSports] = useState<AthleteSport[]>([]);

  useEffect(() => {
    fetchAthleteData();
  }, [athleteId]);

  const fetchAthleteData = async () => {
    try {
      setLoading(true);
      const response = await fetch(`http://localhost:3003/api/athletes/${athleteId}/full-profile`);
      if (!response.ok) throw new Error('Failed to fetch athlete data');
      const data = await response.json();

      setAthlete(data.athlete);
      setGuardians(data.guardians || []);
      setTeams(data.teams || []);
      setEmergencyContacts(data.emergencyContacts || []);
      setMedicalRecord(data.medicalRecord);
      setAllergies(data.allergies || []);
      setMedications(data.medications || []);
      setInsurance(data.insurance || []);
      setDocuments(data.documents || []);
      setSports(data.sports || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

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

  const formatPhoneNumber = (phone: string) => {
    const cleaned = phone.replace(/\D/g, '');
    const match = cleaned.match(/^(\d{3})(\d{3})(\d{4})$/);
    if (match) {
      return `(${match[1]}) ${match[2]}-${match[3]}`;
    }
    return phone;
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-screen">
        <div className="text-xl">Loading athlete profile...</div>
      </div>
    );
  }

  if (error || !athlete) {
    return (
      <div className="flex justify-center items-center min-h-screen">
        <div className="text-xl text-red-600">Error: {error || 'Athlete not found'}</div>
      </div>
    );
  }

  const primaryGuardian = guardians.find(g => g.is_primary_contact);
  const currentTeam = teams[selectedTeam] || teams.find(t => t.team_priority === 'primary');
  const severeAllergies = allergies.filter(a =>
    a.reaction_severity === 'Severe' || a.reaction_severity === 'Life-threatening'
  );

  return (
    <div className="container mx-auto max-w-7xl p-5">
      {/* Header */}
      <div className="border-b-2 border-black pb-5 mb-5">
        <div className="flex gap-5 mb-5 text-sm">
          {teams.length > 1 && <span>Multi-Team Athlete</span>}
          <span>Coach View</span>
        </div>
        <h1 className="text-3xl font-bold">
          {athlete.preferred_name || athlete.first_name} {athlete.last_name}
        </h1>
      </div>

      {/* Team Selector */}
      {teams.length > 0 && (
        <div className="flex gap-2 mb-5 border-2 border-black p-4">
          {teams.map((team, index) => (
            <button
              key={team.team_id}
              onClick={() => setSelectedTeam(index)}
              className={`flex-1 p-4 border-2 border-black transition-colors ${
                selectedTeam === index ? 'bg-black text-white' : 'bg-white hover:bg-gray-100'
              }`}
            >
              <div className="font-bold mb-1">{team.team_name}</div>
              <div className="text-xs">
                #{team.jersey_number} • {team.primary_position || team.positions[0]} • {team.team_priority}
              </div>
            </button>
          ))}
        </div>
      )}

      {/* Quick Info Bar */}
      {currentTeam && (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-5 p-5 border-2 border-black mb-5">
          <div>
            <div className="text-xs uppercase tracking-wide">Jersey</div>
            <div className="text-2xl font-bold">#{currentTeam.jersey_number}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide">Positions</div>
            <div className="text-2xl font-bold">{currentTeam.positions.join('/')}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide">Status</div>
            <div className={`text-2xl font-bold ${currentTeam.status === 'active' ? 'text-green-600' : 'text-red-600'}`}>
              {currentTeam.status}
            </div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide">Age</div>
            <div className="text-2xl font-bold">{calculateAge(athlete.date_of_birth)}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide">Team Priority</div>
            <div className="text-2xl font-bold capitalize">{currentTeam.team_priority}</div>
          </div>
        </div>
      )}

      {/* Emergency Section */}
      <div className="border-4 border-black p-5 mb-5">
        <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-2 border-b border-black">
          EMERGENCY INFORMATION
        </div>
        <div className="grid md:grid-cols-2 gap-5">
          <div>
            {primaryGuardian && (
              <div className="mb-4">
                <div className="font-bold mb-1">Primary Contact</div>
                <div>{primaryGuardian.first_name} {primaryGuardian.last_name} ({primaryGuardian.relationship_type})</div>
                <a href={`tel:${primaryGuardian.mobile_phone}`} className="text-lg font-bold hover:underline">
                  📞 {formatPhoneNumber(primaryGuardian.mobile_phone)}
                </a>
              </div>
            )}
            {emergencyContacts[0] && (
              <div>
                <div className="font-bold mb-1">Emergency Contact</div>
                <div>{emergencyContacts[0].contact_name} ({emergencyContacts[0].relationship})</div>
                <a href={`tel:${emergencyContacts[0].primary_phone}`} className="text-lg font-bold hover:underline">
                  📞 {formatPhoneNumber(emergencyContacts[0].primary_phone)}
                </a>
              </div>
            )}
          </div>
          <div>
            {(severeAllergies.length > 0 || medicalRecord?.has_asthma) && (
              <div className="border-2 border-black p-3 bg-gray-50 mb-3">
                <div className="font-bold mb-1">⚠ MEDICAL ALERTS</div>
                {severeAllergies.map(allergy => (
                  <div key={allergy.id}>
                    • {allergy.allergen} allergy - {allergy.reaction_severity}
                    {allergy.epipen_required && ' - EpiPen required'}
                  </div>
                ))}
                {medicalRecord?.has_asthma && <div>• Asthma - Inhaler required</div>}
              </div>
            )}
            {insurance[0] && (
              <div>
                <strong>Insurance:</strong> {insurance[0].provider_name}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Main Grid */}
      <div className="grid md:grid-cols-2 gap-5 mb-5">
        {/* Player Details */}
        <div className="border-2 border-black p-5">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-2 border-b border-black">
            Player Details
          </div>
          <div className="space-y-3">
            <div className="flex justify-between py-2 border-b border-gray-300">
              <span className="font-medium">Birth Date</span>
              <span>{new Date(athlete.date_of_birth).toLocaleDateString()}</span>
            </div>
            <div className="flex justify-between py-2 border-b border-gray-300">
              <span className="font-medium">Gender</span>
              <span>{athlete.gender}</span>
            </div>
            {athlete.school_name && (
              <div className="flex justify-between py-2 border-b border-gray-300">
                <span className="font-medium">School</span>
                <span>{athlete.school_name}</span>
              </div>
            )}
            {athlete.grade_level && (
              <div className="flex justify-between py-2 border-b border-gray-300">
                <span className="font-medium">Grade</span>
                <span>{athlete.grade_level}th Grade</span>
              </div>
            )}
            {sports.map(sport => (
              <React.Fragment key={sport.sport_type}>
                {sport.dominant_foot && (
                  <div className="flex justify-between py-2 border-b border-gray-300">
                    <span className="font-medium">Dominant Foot</span>
                    <span>{sport.dominant_foot}</span>
                  </div>
                )}
                {sport.years_experience > 0 && (
                  <div className="flex justify-between py-2 border-b border-gray-300">
                    <span className="font-medium">Years Experience</span>
                    <span>{sport.years_experience} years</span>
                  </div>
                )}
              </React.Fragment>
            ))}
          </div>
        </div>

        {/* Household & Family */}
        <div className="border-2 border-black p-5">
          <div className="text-sm font-bold uppercase tracking-wide mb-4 pb-2 border-b border-black">
            Household & Family
          </div>
          <div className="space-y-4">
            <div>
              <div className="font-bold mb-2">Primary Residence</div>
              <div className="text-sm leading-relaxed">
                {athlete.home_address_line1}<br/>
                {athlete.home_address_line2 && <>{athlete.home_address_line2}<br/></>}
                {athlete.city}, {athlete.state} {athlete.zip_code}
              </div>
            </div>

            <div>
              <div className="font-bold mb-2">Parents/Guardians</div>
              {guardians.map(guardian => (
                <div key={guardian.id} className="mb-3 pb-3 border-b border-gray-300">
                  <div className="flex justify-between">
                    <span className="font-medium">
                      {guardian.first_name} {guardian.last_name} ({guardian.relationship_type})
                    </span>
                    {guardian.is_primary_contact && (
                      <span className="text-xs bg-black text-white px-2 py-1">PRIMARY</span>
                    )}
                  </div>
                  <div className="text-sm text-gray-600">
                    📱 {formatPhoneNumber(guardian.mobile_phone)}
                    {guardian.can_authorize_medical && ' | ✓ Medical'}
                    {guardian.can_pickup && ' | ✓ Pickup'}
                    {guardian.financial_responsible && ' | ✓ Financial'}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Tabs Section */}
      <div className="border-2 border-black mb-5">
        <div className="flex border-b-2 border-black">
          {['performance', 'equipment', 'medical', 'documents'].map((tab) => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={`flex-1 p-4 border-r border-black last:border-r-0 text-sm font-medium uppercase tracking-wide transition-colors ${
                activeTab === tab ? 'bg-black text-white' : 'bg-white hover:bg-gray-50'
              }`}
            >
              {tab === 'performance' && 'Performance by Team'}
              {tab === 'equipment' && 'Equipment & Jerseys'}
              {tab === 'medical' && 'Medical'}
              {tab === 'documents' && 'Documents'}
            </button>
          ))}
        </div>

        <div className="p-5">
          {/* Performance Tab */}
          {activeTab === 'performance' && (
            <table className="w-full">
              <thead>
                <tr className="border-b">
                  <th className="text-left py-2 text-xs uppercase tracking-wide">Team</th>
                  <th className="text-left py-2 text-xs uppercase tracking-wide">Status</th>
                  <th className="text-left py-2 text-xs uppercase tracking-wide">Jersey #</th>
                  <th className="text-left py-2 text-xs uppercase tracking-wide">Positions</th>
                  <th className="text-left py-2 text-xs uppercase tracking-wide">Joined</th>
                </tr>
              </thead>
              <tbody>
                {teams.map((team) => (
                  <tr key={team.team_id} className="border-b">
                    <td className="py-3">
                      <strong>{team.team_name}</strong>
                      {team.team_priority === 'primary' && (
                        <span className="ml-2 text-xs bg-black text-white px-2 py-1">PRIMARY</span>
                      )}
                    </td>
                    <td className="py-3">{team.status}</td>
                    <td className="py-3">
                      #{team.jersey_number}
                      {team.jersey_number_alt && ` / #${team.jersey_number_alt}`}
                    </td>
                    <td className="py-3">{team.positions.join(', ')}</td>
                    <td className="py-3">{new Date(team.join_date).toLocaleDateString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {/* Equipment Tab */}
          {activeTab === 'equipment' && (
            <div>
              <table className="w-full mb-4">
                <thead>
                  <tr className="border-b">
                    <th className="text-left py-2 text-xs uppercase tracking-wide">Team</th>
                    <th className="text-left py-2 text-xs uppercase tracking-wide">Jersey Numbers</th>
                    <th className="text-left py-2 text-xs uppercase tracking-wide">Positions</th>
                    <th className="text-left py-2 text-xs uppercase tracking-wide">Priority</th>
                  </tr>
                </thead>
                <tbody>
                  {teams.map((team) => (
                    <tr key={team.team_id} className="border-b">
                      <td className="py-3"><strong>{team.team_name}</strong></td>
                      <td className="py-3">
                        #{team.jersey_number}
                        {team.jersey_number_alt && ` (Alt: #${team.jersey_number_alt})`}
                      </td>
                      <td className="py-3">{team.positions.join(', ')}</td>
                      <td className="py-3 capitalize">{team.team_priority}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {sports.length > 0 && (
                <div className="mt-4 p-4 border border-black">
                  <strong>Sport Details:</strong>
                  {sports.map(sport => (
                    <div key={sport.sport_type} className="mt-2">
                      • {sport.sport_type}: {sport.skill_level} level
                      {sport.dominant_foot && `, ${sport.dominant_foot}-footed`}
                      {sport.years_experience > 0 && `, ${sport.years_experience} years experience`}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Medical Tab */}
          {activeTab === 'medical' && (
            <div className="space-y-4">
              {medicalRecord && (
                <>
                  <div className="flex justify-between py-2 border-b">
                    <span className="font-medium">Physical Exam</span>
                    <span>✓ Valid until {new Date(medicalRecord.physical_exam_date).toLocaleDateString()}</span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="font-medium">Physician</span>
                    <span>{medicalRecord.physician_name} - {medicalRecord.physician_phone}</span>
                  </div>
                </>
              )}

              {allergies.length > 0 && (
                <div className="flex justify-between py-2 border-b">
                  <span className="font-medium">Allergies</span>
                  <span>{allergies.map(a => `${a.allergen} (${a.reaction_severity})`).join(', ')}</span>
                </div>
              )}

              {medications.filter(m => m.is_active).length > 0 && (
                <div className="flex justify-between py-2 border-b">
                  <span className="font-medium">Medications</span>
                  <span>{medications.filter(m => m.is_active).map(m => m.medication_name).join(', ')}</span>
                </div>
              )}

              {medicalRecord && (
                <>
                  {medicalRecord.has_asthma && (
                    <div className="flex justify-between py-2 border-b">
                      <span className="font-medium">Asthma</span>
                      <span>Yes - Inhaler required</span>
                    </div>
                  )}
                  {medicalRecord.has_diabetes && (
                    <div className="flex justify-between py-2 border-b">
                      <span className="font-medium">Diabetes</span>
                      <span>Yes</span>
                    </div>
                  )}
                  {medicalRecord.preferred_hospital && (
                    <div className="flex justify-between py-2 border-b">
                      <span className="font-medium">Preferred Hospital</span>
                      <span>{medicalRecord.preferred_hospital}</span>
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {/* Documents Tab */}
          {activeTab === 'documents' && (
            <div className="space-y-2">
              {documents.map((doc) => (
                <div key={doc.id} className="flex items-center py-2 border-b">
                  <span className={`inline-block w-5 h-5 border-2 border-black mr-3 text-center leading-4 font-bold ${
                    doc.is_current ? 'bg-black text-white' : ''
                  }`}>
                    {doc.is_current ? '✓' : ''}
                  </span>
                  <span className="flex-1">{doc.document_name}</span>
                  <span className="text-sm">
                    {doc.signed_date && `Signed ${new Date(doc.signed_date).toLocaleDateString()}`}
                    {doc.expires_date && ` • Expires ${new Date(doc.expires_date).toLocaleDateString()}`}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Action Buttons */}
      <div className="grid md:grid-cols-2 gap-4">
        <button
          className="p-3 bg-white border-2 border-black hover:bg-black hover:text-white transition-colors"
          onClick={() => window.location.href = `/athlete/${athleteId}/documents`}
        >
          Manage Documents
        </button>
        <button
          className="p-3 bg-white border-2 border-black hover:bg-black hover:text-white transition-colors"
          onClick={() => window.location.href = `mailto:${primaryGuardian?.email}`}
        >
          Contact Parents
        </button>
      </div>
    </div>
  );
};

export default AthleteProfile;
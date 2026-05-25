import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';
import { ParentHeader } from '../components/ParentHeader';

interface MedicalInfo {
  id: number;
  athlete_id: number;
  athlete_name: string;
  medical_conditions?: string;
  allergies?: string;
  medications?: string;
  blood_type?: string;
  insurance_provider?: string;
  insurance_policy_number?: string;
  insurance_group_number?: string;
  primary_physician?: string;
  physician_phone?: string;
  emergency_contact_name?: string;
  emergency_contact_phone?: string;
  emergency_contact_relationship?: string;
  secondary_emergency_contact_name?: string;
  secondary_emergency_contact_phone?: string;
  notes?: string;
  updated_at?: string;
}

export const MedicalInfoPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { id } = useParams<{ id: string }>();
  const { accessibleAthleteIds, loading: permissionsLoading } = useFinancialPermissions();
  // Defense-in-depth: medical data is highly sensitive — only fetch/show it for
  // an athlete this parent actually has access to. Backend enforces this too.
  const accessAllowed =
    permissionsLoading || (!!id && accessibleAthleteIds.includes(Number(id)));
  const accessDenied = !permissionsLoading && !accessAllowed;
  const [medical, setMedical] = useState<MedicalInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchMedical = async () => {
      if (!id) return;
      if (permissionsLoading) return;
      if (!accessAllowed) {
        setLoading(false);
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(
          `${API_URL}/api/athletes/?action=get&id=${id}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await response.json();

        if (data.success && data.athlete) {
          setMedical({
            id: data.athlete.id,
            athlete_id: data.athlete.id,
            athlete_name: `${data.athlete.first_name} ${data.athlete.last_name}`,
            medical_conditions: data.athlete.medical_conditions,
            allergies: data.athlete.allergies,
            medications: data.athlete.medications,
            blood_type: data.athlete.blood_type,
            insurance_provider: data.athlete.insurance_provider,
            insurance_policy_number: data.athlete.insurance_policy_number,
            insurance_group_number: data.athlete.insurance_group_number,
            primary_physician: data.athlete.primary_physician,
            physician_phone: data.athlete.physician_phone,
            emergency_contact_name: data.athlete.emergency_contact_name,
            emergency_contact_phone: data.athlete.emergency_contact_phone,
            emergency_contact_relationship: data.athlete.emergency_contact_relationship,
            secondary_emergency_contact_name: data.athlete.secondary_emergency_contact_name,
            secondary_emergency_contact_phone: data.athlete.secondary_emergency_contact_phone,
            notes: data.athlete.medical_notes,
          });
        } else {
          setError('Athlete not found');
        }
      } catch (err) {
        setError('Failed to load medical information');
      } finally {
        setLoading(false);
      }
    };

    fetchMedical();
  }, [API_URL, id, accessAllowed, permissionsLoading]);

  const InfoSection: React.FC<{
    title: string;
    icon: React.ReactNode;
    children: React.ReactNode;
  }> = ({ title, icon, children }) => (
    <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
      <div className="flex items-center gap-2 px-4 py-3 bg-gray-50 border-b border-gray-200">
        <span className="text-brand-primary">{icon}</span>
        <h2 className="font-semibold text-brand-primary">{title}</h2>
      </div>
      <div className="p-4">{children}</div>
    </div>
  );

  const InfoRow: React.FC<{ label: string; value?: string }> = ({ label, value }) => {
    if (!value) return null;
    return (
      <div className="py-2 border-b border-gray-100 last:border-0">
        <p className="text-sm text-gray-500">{label}</p>
        <p className="text-gray-900">{value}</p>
      </div>
    );
  };

  if (accessDenied) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Medical Info" showBack />
        <div className="pt-14 px-4">
          <div className="mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
            Access denied. You do not have permission to view this athlete's medical information.
          </div>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Medical Info" showBack />
        <div className="pt-14 flex items-center justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
        </div>
      </div>
    );
  }

  if (error || !medical) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Medical Info" showBack />
        <div className="pt-14 px-4">
          <div className="mt-4 bg-red-50 text-red-700 px-4 py-3 rounded-lg">
            {error || 'Medical information not found'}
          </div>
        </div>
      </div>
    );
  }

  const hasMedicalInfo =
    medical.medical_conditions ||
    medical.allergies ||
    medical.medications ||
    medical.blood_type;

  const hasInsurance =
    medical.insurance_provider ||
    medical.insurance_policy_number ||
    medical.primary_physician;

  const hasEmergencyContacts =
    medical.emergency_contact_name || medical.secondary_emergency_contact_name;

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title={`${medical.athlete_name.split(' ')[0]}'s Medical Info`} showBack />

      <div className="pt-14 px-4 pb-4 space-y-4">
        {/* Medical Conditions */}
        {hasMedicalInfo && (
          <InfoSection
            title="Medical Information"
            icon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                />
              </svg>
            }
          >
            <InfoRow label="Blood Type" value={medical.blood_type} />
            <InfoRow label="Medical Conditions" value={medical.medical_conditions} />
            <InfoRow label="Allergies" value={medical.allergies} />
            <InfoRow label="Current Medications" value={medical.medications} />
          </InfoSection>
        )}

        {/* Insurance */}
        {hasInsurance && (
          <InfoSection
            title="Insurance & Physician"
            icon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                />
              </svg>
            }
          >
            <InfoRow label="Insurance Provider" value={medical.insurance_provider} />
            <InfoRow label="Policy Number" value={medical.insurance_policy_number} />
            <InfoRow label="Group Number" value={medical.insurance_group_number} />
            <InfoRow label="Primary Physician" value={medical.primary_physician} />
            <InfoRow label="Physician Phone" value={medical.physician_phone} />
          </InfoSection>
        )}

        {/* Emergency Contacts */}
        {hasEmergencyContacts && (
          <InfoSection
            title="Emergency Contacts"
            icon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"
                />
              </svg>
            }
          >
            {medical.emergency_contact_name && (
              <div className="py-2 border-b border-gray-100">
                <p className="text-sm text-gray-500">Primary Emergency Contact</p>
                <p className="font-medium text-gray-900">{medical.emergency_contact_name}</p>
                {medical.emergency_contact_relationship && (
                  <p className="text-sm text-gray-600">
                    {medical.emergency_contact_relationship}
                  </p>
                )}
                {medical.emergency_contact_phone && (
                  <a
                    href={`tel:${medical.emergency_contact_phone}`}
                    className="text-brand-primary hover:underline"
                  >
                    {medical.emergency_contact_phone}
                  </a>
                )}
              </div>
            )}
            {medical.secondary_emergency_contact_name && (
              <div className="py-2">
                <p className="text-sm text-gray-500">Secondary Emergency Contact</p>
                <p className="font-medium text-gray-900">
                  {medical.secondary_emergency_contact_name}
                </p>
                {medical.secondary_emergency_contact_phone && (
                  <a
                    href={`tel:${medical.secondary_emergency_contact_phone}`}
                    className="text-brand-primary hover:underline"
                  >
                    {medical.secondary_emergency_contact_phone}
                  </a>
                )}
              </div>
            )}
          </InfoSection>
        )}

        {/* Notes */}
        {medical.notes && (
          <InfoSection
            title="Additional Notes"
            icon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                />
              </svg>
            }
          >
            <p className="text-gray-700 whitespace-pre-wrap">{medical.notes}</p>
          </InfoSection>
        )}

        {/* No Info State */}
        {!hasMedicalInfo && !hasInsurance && !hasEmergencyContacts && !medical.notes && (
          <div className="text-center py-12">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">No Medical Info</h3>
            <p className="mt-1 text-sm text-gray-500">
              Medical information has not been added yet.
            </p>
          </div>
        )}
      </div>
    </div>
  );
};

export default MedicalInfoPage;

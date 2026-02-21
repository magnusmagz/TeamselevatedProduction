import React, { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { ParentHeader } from '../components/ParentHeader';

interface AthleteDetails {
  id: number;
  first_name: string;
  last_name: string;
  date_of_birth?: string;
  photo_url?: string;
  email?: string;
  phone?: string;
  home_address_line1?: string;
  city?: string;
  state?: string;
  zip_code?: string;
  teams?: Array<{ id: number; name: string }>;
}

export const AthleteDetailPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const navigate = useNavigate();
  const [athlete, setAthlete] = useState<AthleteDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [deleteConfirmName, setDeleteConfirmName] = useState('');
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  useEffect(() => {
    console.log('[AthleteDetailPage] useEffect triggered, id:', id);

    const fetchAthlete = async () => {
      if (!id) {
        console.log('[AthleteDetailPage] No ID, skipping fetch');
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const url = `${API_URL}/api/athletes/?action=get&id=${id}`;
        console.log('[AthleteDetailPage] Fetching:', url);

        const response = await fetch(url, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const data = await response.json();
        console.log('[AthleteDetailPage] Response:', data);

        if (data.success && data.athlete) {
          setAthlete(data.athlete);
        } else {
          console.error('[AthleteDetailPage] No athlete in response:', data);
          setError(data.error || 'Athlete not found');
        }
      } catch (err) {
        console.error('[AthleteDetailPage] Fetch error:', err);
        setError('Failed to load athlete details');
      } finally {
        setLoading(false);
      }
    };

    fetchAthlete();
  }, [API_URL, id]);

  const getInitials = (firstName: string, lastName: string) => {
    return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
  };

  const handleDeleteData = async () => {
    if (!athlete || !user) return;

    const expectedName = `${athlete.first_name} ${athlete.last_name}`;
    if (deleteConfirmName.trim().toLowerCase() !== expectedName.toLowerCase()) {
      setDeleteError(`Please type "${expectedName}" to confirm.`);
      return;
    }

    setDeleting(true);
    setDeleteError(null);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/consent.php?action=request-deletion`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          athlete_id: athlete.id,
          guardian_id: user.id,
        }),
      });

      const result = await response.json();

      if (result.success) {
        setShowDeleteModal(false);
        navigate('/parent', { state: { deletedAthlete: expectedName } });
      } else {
        setDeleteError(result.error || 'Failed to delete data. Please try again.');
      }
    } catch {
      setDeleteError('Unable to reach the server. Please try again later.');
    } finally {
      setDeleting(false);
    }
  };

  const formatAge = (dob: string) => {
    const birthDate = new Date(dob);
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
      age--;
    }
    return age;
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Athlete Details" showBack />
        <div className="pt-14 flex items-center justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
        </div>
      </div>
    );
  }

  if (error || !athlete) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Athlete Details" showBack />
        <div className="pt-14 px-4">
          <div className="bg-red-50 text-red-700 px-4 py-3 rounded-lg mt-4">
            {error || 'Athlete not found'}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title={`${athlete.first_name} ${athlete.last_name}`} showBack />

      <div className="pt-14 pb-4">
        {/* Profile Header */}
        <div className="bg-white border-b border-gray-200 px-4 py-6">
          <div className="flex items-center gap-4">
            {athlete.photo_url ? (
              <img
                src={athlete.photo_url}
                alt={`${athlete.first_name} ${athlete.last_name}`}
                className="w-20 h-20 rounded-full object-cover"
              />
            ) : (
              <div className="w-20 h-20 rounded-full bg-brand-primary text-white flex items-center justify-center text-2xl font-medium">
                {getInitials(athlete.first_name, athlete.last_name)}
              </div>
            )}
            <div>
              <h1 className="text-xl font-bold text-gray-900">
                {athlete.first_name} {athlete.last_name}
              </h1>
              {athlete.date_of_birth && (
                <p className="text-gray-600">
                  {formatAge(athlete.date_of_birth)} years old
                </p>
              )}
              {athlete.teams && athlete.teams.length > 0 && (
                <div className="flex flex-wrap gap-1 mt-2">
                  {athlete.teams.map((team) => (
                    <span
                      key={team.id}
                      className="inline-block px-2 py-0.5 bg-brand-secondary text-brand-primary text-xs rounded"
                    >
                      {team.name}
                    </span>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Quick Actions */}
        <div className="px-4 py-4 grid grid-cols-2 gap-3">
          <Link
            to={`/parent/payments`}
            className="flex items-center gap-3 p-4 bg-white rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50"
          >
            <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center">
              <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
              </svg>
            </div>
            <span className="font-medium text-gray-900">Payments</span>
          </Link>

          <Link
            to={`/parent/medical/${athlete.id}`}
            className="flex items-center gap-3 p-4 bg-white rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50"
          >
            <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center">
              <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <span className="font-medium text-gray-900">Medical</span>
          </Link>

          <Link
            to={`/parent/documents/${athlete.id}`}
            className="flex items-center gap-3 p-4 bg-white rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50"
          >
            <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center">
              <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
            </div>
            <span className="font-medium text-gray-900">Documents</span>
          </Link>

          <Link
            to={`/parent/schedule`}
            className="flex items-center gap-3 p-4 bg-white rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50"
          >
            <div className="w-10 h-10 rounded-full bg-brand-secondary flex items-center justify-center">
              <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
            <span className="font-medium text-gray-900">Schedule</span>
          </Link>
        </div>

        {/* Contact Info */}
        {(athlete.email || athlete.phone) && (
          <div className="px-4 mb-4">
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="font-semibold text-gray-900 mb-3">Contact Information</h2>
              {athlete.email && (
                <div className="flex items-center gap-3 py-2">
                  <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                  </svg>
                  <span className="text-gray-700">{athlete.email}</span>
                </div>
              )}
              {athlete.phone && (
                <div className="flex items-center gap-3 py-2">
                  <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                  </svg>
                  <span className="text-gray-700">{athlete.phone}</span>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Address */}
        {athlete.home_address_line1 && athlete.home_address_line1 !== 'N/A' && (
          <div className="px-4 mb-4">
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="font-semibold text-gray-900 mb-3">Address</h2>
              <div className="flex items-start gap-3">
                <svg className="w-5 h-5 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <div className="text-gray-700">
                  <p>{athlete.home_address_line1}</p>
                  {(athlete.city || athlete.state || athlete.zip_code) && (
                    <p>
                      {[athlete.city, athlete.state].filter(Boolean).join(', ')}
                      {athlete.zip_code && ` ${athlete.zip_code}`}
                    </p>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Data Privacy Section */}
        <div className="px-4 mb-4 mt-6">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <h2 className="font-semibold text-gray-900 mb-2">Data Privacy</h2>
            <p className="text-sm text-gray-600 mb-3">
              Under our privacy policy, you have the right to request deletion of your child's personal and medical data.
            </p>
            <button
              onClick={() => {
                setShowDeleteModal(true);
                setDeleteConfirmName('');
                setDeleteError(null);
              }}
              className="w-full flex items-center justify-center gap-2 p-3 bg-white rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition-colors text-sm font-medium"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
              Delete My Child's Data
            </button>
          </div>
        </div>
      </div>

      {/* Delete Confirmation Modal */}
      {showDeleteModal && athlete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div className="p-6">
              <div className="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg className="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
              </div>
              <h3 className="text-lg font-semibold text-gray-900 text-center mb-2">
                Delete Data for {athlete.first_name} {athlete.last_name}?
              </h3>
              <p className="text-sm text-gray-600 text-center mb-4">
                This will permanently delete all medical records, allergy information, medications, and insurance data for {athlete.first_name}. Their profile will be deactivated.
              </p>
              <p className="text-sm text-gray-600 text-center mb-4">
                This action <span className="font-semibold">cannot be undone</span>.
              </p>
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Type <span className="font-semibold">{athlete.first_name} {athlete.last_name}</span> to confirm
                </label>
                <input
                  type="text"
                  value={deleteConfirmName}
                  onChange={(e) => {
                    setDeleteConfirmName(e.target.value);
                    setDeleteError(null);
                  }}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-red-500"
                  placeholder={`${athlete.first_name} ${athlete.last_name}`}
                />
              </div>
              {deleteError && (
                <div className="bg-red-50 text-red-700 px-3 py-2 rounded text-sm mb-4">
                  {deleteError}
                </div>
              )}
            </div>
            <div className="border-t border-gray-200 px-6 py-4 flex gap-3">
              <button
                onClick={() => setShowDeleteModal(false)}
                disabled={deleting}
                className="flex-1 px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm font-medium"
              >
                Cancel
              </button>
              <button
                onClick={handleDeleteData}
                disabled={deleting || deleteConfirmName.trim().toLowerCase() !== `${athlete.first_name} ${athlete.last_name}`.toLowerCase()}
                className="flex-1 px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 text-sm font-medium"
              >
                {deleting ? 'Deleting...' : 'Delete Data'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default AthleteDetailPage;

import React, { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';
import { ParentHeader } from '../components/ParentHeader';
import { JerseySizeCard } from '../components/JerseySizeCard';
import AthleteEvaluationsPanel from '../../components/evaluations/AthleteEvaluationsPanel';

interface AthleteDetails {
  id: number;
  first_name: string;
  last_name: string;
  date_of_birth?: string;
  photo_url?: string;
  email?: string;
  phone?: string;
  /** Stored code ('YM'), shared with the staff-side athlete form. */
  jersey_size?: string | null;
  home_address_line1?: string;
  city?: string;
  state?: string;
  zip_code?: string;
  teams?: Array<{ id: number; name: string }>;
}

interface Coach {
  id: number;
  first_name: string;
  last_name: string;
  email?: string;
  profile_image_url?: string;
  coaching_background?: string;
  role: 'head_coach' | 'assistant_coach' | 'team_manager';
  team_name?: string;
}

interface Registration {
  id: number;
  program_id: number;
  program_name: string;
  season?: string;
  start_date?: string;
  end_date?: string;
  status: string;
  submitted_at: string;
  reviewed_at?: string;
  invoice_id?: number;
  invoice_number?: string;
  total_amount?: string;
  amount_paid?: string;
  invoice_status?: string;
}

interface AthleteEvent {
  id: number;
  title: string;
  date: string;
  start_time?: string;
  end_time?: string;
  location?: string;
  type: string;
  team_name?: string;
}

export const AthleteDetailPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const navigate = useNavigate();
  // myChildrenIds — a parent-portal page authorizes on FAMILY, not on the roster a
  // coach happens to run. accessibleAthleteIds includes the latter.
  const { myChildrenIds, loading: permissionsLoading } = useFinancialPermissions();
  // Defense-in-depth: only fetch/show an athlete this parent actually has
  // access to. The backend enforces this too; this avoids a wasted request and
  // shows a clear Access-denied state for a tampered/guessed :id.
  const accessAllowed =
    permissionsLoading || (!!id && myChildrenIds.includes(Number(id)));
  const accessDenied = !permissionsLoading && !accessAllowed;
  const [athlete, setAthlete] = useState<AthleteDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [registrations, setRegistrations] = useState<Registration[]>([]);
  const [registrationsLoading, setRegistrationsLoading] = useState(true);
  const [athleteEvents, setAthleteEvents] = useState<AthleteEvent[]>([]);
  const [eventsLoading, setEventsLoading] = useState(true);
  const [coaches, setCoaches] = useState<Coach[]>([]);
  const [coachesLoading, setCoachesLoading] = useState(true);
  const [expandedCoachId, setExpandedCoachId] = useState<number | null>(null);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [deleteConfirmName, setDeleteConfirmName] = useState('');
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  useEffect(() => {
    const fetchAthlete = async () => {
      if (!id) return;
      // Wait for permissions to resolve; skip entirely if not accessible.
      if (permissionsLoading) return;
      if (!accessAllowed) {
        setLoading(false);
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${API_URL}/api/athletes/?action=get&id=${id}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const data = await response.json();

        if (data.success && data.athlete) {
          setAthlete(data.athlete);
        } else {
          setError(data.error || 'Athlete not found');
        }
      } catch (err) {
        setError('Failed to load athlete details');
      } finally {
        setLoading(false);
      }
    };

    fetchAthlete();
  }, [API_URL, id, accessAllowed, permissionsLoading]);

  useEffect(() => {
    const fetchRegistrations = async () => {
      if (!id) return;
      if (permissionsLoading || !accessAllowed) {
        setRegistrationsLoading(false);
        return;
      }

      setRegistrationsLoading(true);
      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(
          `${API_URL}/registration/registrations-api.php?athlete_id=${id}`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await response.json();
        if (Array.isArray(data)) {
          setRegistrations(data);
        }
      } catch (err) {
        console.error('Error fetching registrations:', err);
      } finally {
        setRegistrationsLoading(false);
      }
    };

    fetchRegistrations();
  }, [API_URL, id, accessAllowed, permissionsLoading]);

  useEffect(() => {
    const fetchEvents = async () => {
      if (!id) return;
      if (permissionsLoading || !accessAllowed) {
        setEventsLoading(false);
        return;
      }
      setEventsLoading(true);
      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(
          `${API_URL}/api/calendar-events-gateway.php?action=upcoming&athlete_id=${id}&limit=5`,
          { headers: { Authorization: `Bearer ${token}` } }
        );
        const data = await response.json();
        if (data.success && Array.isArray(data.events)) {
          setAthleteEvents(data.events);
        }
      } catch (err) {
        console.error('Error fetching athlete events:', err);
      } finally {
        setEventsLoading(false);
      }
    };
    fetchEvents();
  }, [API_URL, id, accessAllowed, permissionsLoading]);

  useEffect(() => {
    const fetchCoaches = async () => {
      if (!athlete?.teams || athlete.teams.length === 0) {
        setCoachesLoading(false);
        return;
      }
      setCoachesLoading(true);
      try {
        const token = localStorage.getItem('auth_token');
        const responses = await Promise.all(
          athlete.teams.map((team) =>
            fetch(`${API_URL}/api/team-coaches.php?team_id=${team.id}`, {
              headers: { Authorization: `Bearer ${token}` },
            })
              .then((r) => r.json())
              .then((data) => (data.success ? (data.coaches as Omit<Coach, 'team_name'>[]).map((c) => ({ ...c, team_name: team.name })) : []))
              .catch(() => [])
          )
        );
        // Flatten + dedupe by user id (a coach on multiple of the athlete's teams shows once)
        const seen = new Map<number, Coach>();
        for (const list of responses) {
          for (const c of list) {
            if (!seen.has(c.id)) seen.set(c.id, c);
          }
        }
        setCoaches(Array.from(seen.values()));
      } catch (err) {
        console.error('Error fetching coaches:', err);
      } finally {
        setCoachesLoading(false);
      }
    };
    fetchCoaches();
  }, [API_URL, athlete]);

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

  if (accessDenied) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Athlete Details" showBack />
        <div className="pt-14 px-4">
          <div className="bg-red-50 text-red-700 px-4 py-3 rounded-lg mt-4">
            Access denied. You do not have permission to view this athlete.
          </div>
        </div>
      </div>
    );
  }

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
              <h1 className="text-xl font-bold text-brand-primary">
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
            to={`/parent/schedule?athlete=${athlete.id}`}
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

        {/* Uniform — jersey size. Crew-editable: the same athletes.jersey_size
            column staff edit on the athlete form, so whichever side saves last
            is what both sides read. */}
        <JerseySizeCard
          athleteId={athlete.id}
          jerseySize={athlete.jersey_size}
          athleteFirstName={athlete.first_name}
          onSaved={(size) =>
            setAthlete((prev) => (prev ? { ...prev, jersey_size: size } : prev))
          }
        />

        {/* Coach evaluations and the development plan, READ ONLY.
            The same component the staff profile renders, not a portal-specific
            copy: a parent must see exactly what the coach wrote. `readOnly`
            removes every write control, and the server refuses a guardian's
            write regardless — api/athlete-evaluations.php gates reads on
            userCanAccessAthlete (a guardian passes) and writes on
            staffCanManageAthlete AND coaching the athlete (they do not). */}
        <div className="px-4 mb-4">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <AthleteEvaluationsPanel
              athleteId={athlete.id}
              athleteName={`${athlete.first_name} ${athlete.last_name}`}
              readOnly
              apiUrl={API_URL}
            />
          </div>
        </div>

        {/* Upcoming Schedule for this athlete */}
        <div className="px-4 mb-4">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div className="flex items-center justify-between mb-3">
              <h2 className="font-semibold text-brand-primary">Upcoming Schedule</h2>
              <Link to={`/parent/schedule?athlete=${athlete.id}`} className="text-xs text-brand-accent hover:underline">
                See all
              </Link>
            </div>
            {eventsLoading ? (
              <div className="flex justify-center py-4">
                <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-brand-primary"></div>
              </div>
            ) : athleteEvents.length === 0 ? (
              <p className="text-sm text-gray-500 py-2">No upcoming events.</p>
            ) : (
              <div className="space-y-2">
                {athleteEvents.map((evt) => {
                  // Parse "YYYY-MM-DD" as local time so PDT users don't see the day before.
                  const [_y, _m, _d] = evt.date.split('-').map(Number);
                  const dateLabel = new Date(_y, _m - 1, _d).toLocaleDateString('en-US', {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                  });
                  const timeLabel = evt.start_time
                    ? new Date(`2000-01-01T${evt.start_time}`).toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                      })
                    : '';
                  return (
                    <Link
                      key={evt.id}
                      to={`/parent/schedule/rsvp/${evt.id}`}
                      className="flex items-center justify-between gap-3 p-3 border border-gray-100 rounded-lg hover:bg-gray-50"
                    >
                      <div className="min-w-0 flex-1">
                        <p className="font-medium text-gray-900 truncate">{evt.title}</p>
                        <p className="text-xs text-gray-500">
                          {dateLabel}{timeLabel ? ` · ${timeLabel}` : ''}
                          {evt.team_name ? ` · ${evt.team_name}` : ''}
                        </p>
                      </div>
                      <svg className="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                      </svg>
                    </Link>
                  );
                })}
              </div>
            )}
          </div>
        </div>

        {/* Registrations */}
        <div className="px-4 mb-4">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <h2 className="font-semibold text-brand-primary mb-3">Registrations</h2>
            {registrationsLoading ? (
              <div className="flex justify-center py-4">
                <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-brand-primary"></div>
              </div>
            ) : registrations.length === 0 ? (
              <p className="text-sm text-gray-500 py-2">No registrations yet.</p>
            ) : (
              <div className="space-y-3">
                {registrations.map((reg) => (
                  <div key={reg.id} className="border border-gray-100 rounded-lg p-3">
                    <div className="flex items-start justify-between mb-1">
                      <div>
                        <p className="font-medium text-gray-900">{reg.program_name}</p>
                        {reg.season && (
                          <p className="text-xs text-gray-500">{reg.season}</p>
                        )}
                      </div>
                      <span
                        className={`px-2 py-0.5 text-xs font-medium rounded ${
                          reg.status === 'approved'
                            ? 'bg-green-100 text-green-800'
                            : reg.status === 'pending'
                            ? 'bg-yellow-100 text-yellow-800'
                            : reg.status === 'rejected'
                            ? 'bg-red-100 text-red-800'
                            : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {reg.status.charAt(0).toUpperCase() + reg.status.slice(1)}
                      </span>
                    </div>
                    <div className="text-xs text-gray-500">
                      Submitted {new Date(reg.submitted_at).toLocaleDateString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                      })}
                    </div>
                    {reg.invoice_id && (
                      <div className="mt-2 pt-2 border-t border-gray-100 flex items-center justify-between text-sm">
                        <span className="text-gray-600">
                          Invoice #{reg.invoice_number}
                        </span>
                        <span
                          className={`font-medium ${
                            reg.invoice_status === 'paid'
                              ? 'text-green-600'
                              : 'text-gray-900'
                          }`}
                        >
                          ${parseFloat(reg.total_amount || '0').toFixed(2)}
                          {reg.invoice_status && reg.invoice_status !== 'paid' && (
                            <span className="text-xs text-gray-500 ml-1">({reg.invoice_status})</span>
                          )}
                        </span>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Coaches */}
        {(coachesLoading || coaches.length > 0) && (
          <div className="px-4 mb-4">
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="font-semibold text-brand-primary mb-3">Coaches</h2>
              {coachesLoading ? (
                <div className="flex justify-center py-4">
                  <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-brand-primary"></div>
                </div>
              ) : (
                <div className="space-y-2">
                  {coaches.map((coach) => {
                    const isOpen = expandedCoachId === coach.id;
                    const roleLabel =
                      coach.role === 'head_coach' ? 'Head Coach' :
                      coach.role === 'assistant_coach' ? 'Assistant Coach' :
                      'Team Manager';
                    return (
                      <div key={coach.id} className="border border-gray-100 rounded-lg overflow-hidden">
                        <button
                          onClick={() => setExpandedCoachId(isOpen ? null : coach.id)}
                          className="w-full px-3 py-2 flex items-center gap-3 hover:bg-gray-50 text-left"
                        >
                          {coach.profile_image_url ? (
                            <img src={coach.profile_image_url} alt="" className="w-10 h-10 rounded-full object-cover" />
                          ) : (
                            <div className="w-10 h-10 rounded-full bg-brand-primary text-white flex items-center justify-center text-sm font-medium">
                              {getInitials(coach.first_name, coach.last_name)}
                            </div>
                          )}
                          <div className="flex-1 min-w-0">
                            <p className="font-medium text-gray-900 truncate">{coach.first_name} {coach.last_name}</p>
                            <p className="text-xs text-gray-500">
                              {roleLabel}{coach.team_name ? ` · ${coach.team_name}` : ''}
                            </p>
                          </div>
                          {coach.coaching_background && (
                            <svg className={`w-4 h-4 text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                            </svg>
                          )}
                        </button>
                        {isOpen && coach.coaching_background && (
                          <div className="px-3 py-2 bg-gray-50 border-t border-gray-100 text-sm text-gray-700 whitespace-pre-line">
                            {coach.coaching_background}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          </div>
        )}

        {/* Contact Info */}
        {(athlete.email || athlete.phone) && (
          <div className="px-4 mb-4">
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="font-semibold text-brand-primary mb-3">Contact Information</h2>
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
              <h2 className="font-semibold text-brand-primary mb-3">Address</h2>
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
            <h2 className="font-semibold text-brand-primary mb-2">Data Privacy</h2>
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
              <h3 className="text-lg font-semibold text-brand-primary text-center mb-2">
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

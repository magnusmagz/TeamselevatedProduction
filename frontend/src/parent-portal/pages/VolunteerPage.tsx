import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../../contexts/OrgContext';
import { ParentHeader } from '../components/ParentHeader';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Assignment {
  id: number;
  team_id: number;
  team_name: string;
  age_group?: string;
  division?: string;
  coach_name?: string;
  start_date?: string;
  background_check_status?: string;
  status: string;
}

interface AvailableTeam {
  id: number;
  name: string;
  age_group?: string;
  division?: string;
  season_name?: string;
  coach_name?: string;
  volunteer_count: number;
  already_volunteer: boolean;
  pending_signup: boolean;
}

export const VolunteerPage: React.FC = () => {
  const { currentClubId } = useOrg();

  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [pendingSignups, setPendingSignups] = useState<Assignment[]>([]);
  const [availableTeams, setAvailableTeams] = useState<AvailableTeam[]>([]);
  const [loadingAssignments, setLoadingAssignments] = useState(true);
  const [loadingTeams, setLoadingTeams] = useState(true);
  const [errorAssignments, setErrorAssignments] = useState<string | null>(null);
  const [errorTeams, setErrorTeams] = useState<string | null>(null);

  const [signupModalTeam, setSignupModalTeam] = useState<AvailableTeam | null>(null);
  const [signupNotes, setSignupNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const bgCheckCleared = assignments.some(
    (a) => a.background_check_status === 'cleared'
  );

  const getAuthHeaders = useCallback(() => {
    const token = localStorage.getItem('auth_token');
    return {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
    };
  }, []);

  const fetchAssignments = useCallback(async () => {
    setLoadingAssignments(true);
    setErrorAssignments(null);
    try {
      const response = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=my-assignments`,
        { headers: getAuthHeaders() }
      );
      const data = await response.json();
      if (data.success) {
        setAssignments(data.assignments || []);
        setPendingSignups(data.pending_signups || []);
      } else {
        setErrorAssignments('Failed to load volunteer assignments');
      }
    } catch {
      setErrorAssignments('Failed to load volunteer assignments');
    } finally {
      setLoadingAssignments(false);
    }
  }, [getAuthHeaders]);

  const fetchAvailableTeams = useCallback(async () => {
    if (!currentClubId) return;
    setLoadingTeams(true);
    setErrorTeams(null);
    try {
      const response = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=available-teams&club_id=${currentClubId}`,
        { headers: getAuthHeaders() }
      );
      const data = await response.json();
      if (data.success) {
        setAvailableTeams(data.teams || []);
      } else {
        setErrorTeams('Failed to load available teams');
      }
    } catch {
      setErrorTeams('Failed to load available teams');
    } finally {
      setLoadingTeams(false);
    }
  }, [currentClubId, getAuthHeaders]);

  useEffect(() => {
    fetchAssignments();
  }, [fetchAssignments]);

  useEffect(() => {
    fetchAvailableTeams();
  }, [fetchAvailableTeams]);

  const handleSignup = async () => {
    if (!signupModalTeam) return;
    setSubmitting(true);
    try {
      const response = await fetch(
        `${API_URL}/api/volunteer-gateway.php?action=self-signup`,
        {
          method: 'POST',
          headers: getAuthHeaders(),
          body: JSON.stringify({
            team_id: signupModalTeam.id,
            ...(signupNotes.trim() ? { notes: signupNotes.trim() } : {}),
          }),
        }
      );
      const data = await response.json();
      if (data.success) {
        setSuccessMessage('Your request has been submitted for review');
        setSignupModalTeam(null);
        setSignupNotes('');
        fetchAssignments();
        fetchAvailableTeams();
        setTimeout(() => setSuccessMessage(null), 5000);
      } else {
        setErrorTeams(data.message || 'Signup failed. Please try again.');
      }
    } catch {
      setErrorTeams('Signup failed. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const getBgCheckBadge = (status?: string) => {
    switch (status) {
      case 'cleared':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
            Cleared
          </span>
        );
      case 'pending':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
            Pending
          </span>
        );
      case 'expired':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
            Expired
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
            Not Submitted
          </span>
        );
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'active':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
            Active
          </span>
        );
      case 'pending':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
            Pending
          </span>
        );
      case 'inactive':
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
            Inactive
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
            {status}
          </span>
        );
    }
  };

  const formatDate = (dateStr?: string) => {
    if (!dateStr) return '--';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader title="Volunteer" showBack />

      <div className="pt-14 px-4 pb-24">
        {/* BG Check Warning */}
        {!loadingAssignments && !bgCheckCleared && (
          <div className="mt-4 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 flex items-start gap-3">
            <svg
              className="h-5 w-5 text-amber-500 mt-0.5 flex-shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"
              />
            </svg>
            <p className="text-sm text-amber-800">
              Your background check is not yet cleared. You can submit signup
              requests, but they cannot be approved until your background check
              is cleared.
            </p>
          </div>
        )}

        {/* Success Toast */}
        {successMessage && (
          <div className="mt-4 bg-green-50 border border-green-200 rounded-lg px-4 py-3 flex items-center gap-3">
            <svg
              className="h-5 w-5 text-green-500 flex-shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M5 13l4 4L19 7"
              />
            </svg>
            <p className="text-sm text-green-800">{successMessage}</p>
          </div>
        )}

        {/* Section 1: My Volunteer Assignments */}
        <section className="mt-6">
          <h2 className="text-lg font-semibold text-brand-primary mb-3">
            My Volunteer Assignments
          </h2>

          {loadingAssignments && (
            <div className="flex items-center justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
            </div>
          )}

          {errorAssignments && (
            <div className="bg-red-50 text-red-700 px-4 py-3 rounded-lg">
              {errorAssignments}
            </div>
          )}

          {!loadingAssignments &&
            !errorAssignments &&
            assignments.length === 0 &&
            pendingSignups.length === 0 && (
              <div className="text-center py-8 bg-white rounded-lg border border-gray-200">
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
                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                  />
                </svg>
                <p className="mt-2 text-sm text-gray-500">
                  You're not currently volunteering for any teams
                </p>
              </div>
            )}

          {/* Pending Signups */}
          {!loadingAssignments && pendingSignups.length > 0 && (
            <div className="space-y-3 mb-4">
              {pendingSignups.map((signup) => (
                <div
                  key={`pending-${signup.id}`}
                  className="bg-white rounded-lg shadow-sm border border-yellow-200 p-4"
                >
                  <div className="flex items-start justify-between">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="font-semibold text-brand-primary">
                          {signup.team_name}
                        </h3>
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                          Pending
                        </span>
                      </div>
                      {(signup.age_group || signup.division) && (
                        <p className="text-sm text-gray-600 mt-1">
                          {[signup.age_group, signup.division]
                            .filter(Boolean)
                            .join(' - ')}
                        </p>
                      )}
                      {signup.coach_name && (
                        <p className="text-sm text-gray-500 mt-0.5">
                          Coach: {signup.coach_name}
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Active Assignments */}
          {!loadingAssignments && assignments.length > 0 && (
            <div className="space-y-3">
              {assignments.map((assignment) => (
                <div
                  key={assignment.id}
                  className="bg-white rounded-lg shadow-sm border border-gray-200 p-4"
                >
                  <div className="flex items-start justify-between">
                    <div className="flex-1 min-w-0">
                      <h3 className="font-semibold text-brand-primary">
                        {assignment.team_name}
                      </h3>
                      {(assignment.age_group || assignment.division) && (
                        <p className="text-sm text-gray-600 mt-1">
                          {[assignment.age_group, assignment.division]
                            .filter(Boolean)
                            .join(' - ')}
                        </p>
                      )}
                      {assignment.coach_name && (
                        <p className="text-sm text-gray-500 mt-0.5">
                          Coach: {assignment.coach_name}
                        </p>
                      )}
                      {assignment.start_date && (
                        <p className="text-sm text-gray-500 mt-0.5">
                          Started: {formatDate(assignment.start_date)}
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center gap-2 mt-3 flex-wrap">
                    <span className="text-xs text-gray-500">BG Check:</span>
                    {getBgCheckBadge(assignment.background_check_status)}
                    <span className="text-xs text-gray-500 ml-2">Status:</span>
                    {getStatusBadge(assignment.status)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>

        {/* Section 2: Available Opportunities */}
        <section className="mt-8">
          <h2 className="text-lg font-semibold text-brand-primary mb-3">
            Available Opportunities
          </h2>

          {loadingTeams && (
            <div className="flex items-center justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
            </div>
          )}

          {errorTeams && (
            <div className="bg-red-50 text-red-700 px-4 py-3 rounded-lg mb-3">
              {errorTeams}
            </div>
          )}

          {!loadingTeams && !errorTeams && availableTeams.length === 0 && (
            <div className="text-center py-8 bg-white rounded-lg border border-gray-200">
              <p className="text-sm text-gray-500">
                No teams are currently looking for volunteers.
              </p>
            </div>
          )}

          {!loadingTeams && availableTeams.length > 0 && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {availableTeams.map((team) => (
                <div
                  key={team.id}
                  className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex flex-col"
                >
                  <h3 className="font-semibold text-brand-primary">
                    {team.name}
                  </h3>
                  {(team.age_group || team.division) && (
                    <p className="text-sm text-gray-600 mt-1">
                      {[team.age_group, team.division]
                        .filter(Boolean)
                        .join(' - ')}
                    </p>
                  )}
                  {team.season_name && (
                    <p className="text-sm text-gray-500 mt-0.5">
                      Season: {team.season_name}
                    </p>
                  )}
                  {team.coach_name && (
                    <p className="text-sm text-gray-500 mt-0.5">
                      Coach: {team.coach_name}
                    </p>
                  )}
                  <p className="text-sm text-gray-500 mt-0.5">
                    Volunteers: {team.volunteer_count}
                  </p>

                  <div className="mt-auto pt-3">
                    {team.already_volunteer ? (
                      <button
                        disabled
                        className="w-full px-4 py-2 text-sm font-medium text-gray-400 bg-gray-100 rounded-lg cursor-not-allowed"
                      >
                        Already volunteering
                      </button>
                    ) : team.pending_signup ? (
                      <button
                        disabled
                        className="w-full px-4 py-2 text-sm font-medium text-yellow-700 bg-yellow-50 rounded-lg cursor-not-allowed"
                      >
                        Request pending
                      </button>
                    ) : (
                      <button
                        onClick={() => {
                          setSignupModalTeam(team);
                          setSignupNotes('');
                        }}
                        className="w-full px-4 py-2 text-sm font-medium text-white bg-brand-primary rounded-lg hover:opacity-90 transition-opacity active:opacity-80"
                      >
                        Sign Up
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>

      {/* Signup Modal */}
      {signupModalTeam && (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
          <div
            className="fixed inset-0 bg-black bg-opacity-40"
            onClick={() => {
              if (!submitting) {
                setSignupModalTeam(null);
                setSignupNotes('');
              }
            }}
          />
          <div className="relative bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl p-6 shadow-xl z-10">
            <h3 className="text-lg font-semibold text-brand-primary">
              Volunteer Sign Up
            </h3>
            <p className="text-sm text-gray-600 mt-1">
              Request to volunteer for{' '}
              <span className="font-medium">{signupModalTeam.name}</span>
            </p>

            <div className="mt-4">
              <label
                htmlFor="signup-notes"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Notes (optional)
              </label>
              <textarea
                id="signup-notes"
                value={signupNotes}
                onChange={(e) => setSignupNotes(e.target.value)}
                placeholder="Any relevant experience, availability, etc."
                rows={3}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-primary focus:border-brand-primary outline-none resize-none"
              />
            </div>

            <div className="flex gap-3 mt-5">
              <button
                onClick={() => {
                  setSignupModalTeam(null);
                  setSignupNotes('');
                }}
                disabled={submitting}
                className="flex-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={handleSignup}
                disabled={submitting}
                className="flex-1 px-4 py-2.5 text-sm font-medium text-white bg-brand-primary rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50 flex items-center justify-center"
              >
                {submitting ? (
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                ) : (
                  'Confirm'
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

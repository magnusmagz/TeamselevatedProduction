import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { TournamentRegistration, TournamentDivision, RegistrationStatus, PaymentStatus, TournamentStatus } from '../types';
import RegistrationForm from './RegistrationForm';
import RegistrationRosterModal from './RegistrationRosterModal';
import DataTable, { DataTableColumn } from '../../../components/ui/DataTable';
import Button from '../../../components/ui/Button';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  tournamentId: number;
  divisions: TournamentDivision[];
  isAdmin: boolean;
  clubId: number;
  status?: TournamentStatus;
  registrationOpenDate?: string | null;
}

const STATUS_COLORS: Record<string, string> = {
  pending: 'bg-yellow-100 text-yellow-700',
  accepted: 'bg-green-100 text-green-700',
  rejected: 'bg-red-100 text-red-700',
  waitlisted: 'bg-orange-100 text-orange-700',
  withdrawn: 'bg-gray-100 text-gray-500',
};

const PAYMENT_COLORS: Record<string, string> = {
  unpaid: 'bg-red-50 text-red-600',
  paid: 'bg-green-50 text-green-600',
  refunded: 'bg-gray-50 text-gray-500',
  waived: 'bg-blue-50 text-blue-600',
};


// Why teams may not be able to sign up yet. Registration being closed does not
// stop an admin adding a team here — the backend has no status gate — so these
// say what state the tournament is in rather than blocking anything.
const REGISTRATION_NOT_OPEN_COPY: Partial<Record<TournamentStatus, string>> = {
  draft: 'This tournament is still a draft, so teams cannot sign up themselves and it is not listed publicly. Set the status to Registration Open on the Overview tab when you are ready.',
  registration_closed: 'Registration is closed, so teams can no longer sign up themselves.',
  scheduling: 'Registration is closed while the schedule is being built, so teams can no longer sign up themselves.',
  in_progress: 'The tournament is underway and registration is closed.',
  weather_delay: 'The tournament is in a weather delay and registration is closed.',
  completed: 'This tournament is complete and registration is closed.',
  cancelled: 'This tournament is cancelled.',
};

const RegistrationManager: React.FC<Props> = ({ tournamentId, divisions, isAdmin, clubId, status, registrationOpenDate }) => {
  const [registrations, setRegistrations] = useState<TournamentRegistration[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [divisionFilter, setDivisionFilter] = useState<string>('');
  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [rosterRegistration, setRosterRegistration] = useState<TournamentRegistration | null>(null);

  // Decline modal state — when set, opens the editable email modal instead
  // of immediately moving the registration to rejected.
  const [decliningReg, setDecliningReg] = useState<TournamentRegistration | null>(null);
  const [declinePreviewLoading, setDeclinePreviewLoading] = useState(false);
  const [declineSubject, setDeclineSubject] = useState('');
  const [declineBody, setDeclineBody] = useState('');
  const [declineSkipEmail, setDeclineSkipEmail] = useState(false);
  const [declineOfferedDivisionId, setDeclineOfferedDivisionId] = useState<number | ''>('');
  const [declineSiblingDivisions, setDeclineSiblingDivisions] = useState<Array<{ id: number; name: string; max_teams: number | null; accepted_count: number }>>([]);
  const [declineRecipients, setDeclineRecipients] = useState<Array<{ email: string; name: string }>>([]);
  const [declineSending, setDeclineSending] = useState(false);

  const token = localStorage.getItem('auth_token');
  // Stable across renders so the fetch effects/callbacks below can depend on
  // it without re-firing on every render.
  const headers: HeadersInit = useMemo(() => ({ 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }), [token]);

  const fetchRegistrations = useCallback(async () => {
    setLoading(true);
    try {
      let url = `${API_URL}/api/tournament-gateway.php?action=registrations-list&tournament_id=${tournamentId}`;
      if (statusFilter) url += `&status=${statusFilter}`;
      if (divisionFilter) url += `&division_id=${divisionFilter}`;

      const res = await fetch(url, { headers });
      const data = await res.json();
      setRegistrations(data.registrations || []);
      setCounts(data.counts || {});
    } catch (err) {
      console.error('Failed to fetch registrations:', err);
    } finally {
      setLoading(false);
    }
  }, [tournamentId, statusFilter, divisionFilter, headers]);

  useEffect(() => { fetchRegistrations(); }, [fetchRegistrations]);

  const handleStatusUpdate = async (regId: number, status: RegistrationStatus) => {
    setUpdatingId(regId);
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=registration-update-status&id=${regId}`,
        { method: 'PUT', headers, body: JSON.stringify({ status }) }
      );
      if (!res.ok) {
        const err = await res.json();
        alert(err.error || 'Failed to update');
        return;
      }
      fetchRegistrations();
    } catch (err) {
      alert('Failed to update registration status');
    } finally {
      setUpdatingId(null);
    }
  };

  const openDeclineModal = async (reg: TournamentRegistration) => {
    setDecliningReg(reg);
    setDeclineSubject('');
    setDeclineBody('');
    setDeclineSkipEmail(false);
    setDeclineOfferedDivisionId('');
    setDeclineSiblingDivisions([]);
    setDeclineRecipients([]);
    setDeclinePreviewLoading(true);
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=registration-decline-preview&id=${reg.id}`,
        { headers }
      );
      const data = await res.json();
      if (data.success) {
        setDeclineSubject(data.subject || '');
        setDeclineBody(data.body || '');
        setDeclineSiblingDivisions(data.sibling_divisions || []);
        setDeclineRecipients(data.recipients || []);
      } else {
        // Fall through with empty fields — admin can still write a custom message.
        console.error('decline preview failed:', data.error);
      }
    } catch (err) {
      console.error('decline preview error:', err);
    } finally {
      setDeclinePreviewLoading(false);
    }
  };

  const closeDeclineModal = () => {
    setDecliningReg(null);
  };

  const submitDecline = async () => {
    if (!decliningReg) return;
    if (!declineSkipEmail && declineSubject.trim() === '') {
      alert('Subject is required, or check "Don\'t send email".');
      return;
    }
    setDeclineSending(true);
    try {
      const body: Record<string, unknown> = {
        status: 'rejected',
        email_override: declineSkipEmail
          ? { skip: true }
          : { subject: declineSubject, body: declineBody, html_body: '' },
      };
      if (declineOfferedDivisionId) {
        body.offered_division_id = Number(declineOfferedDivisionId);
      }
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=registration-update-status&id=${decliningReg.id}`,
        { method: 'PUT', headers, body: JSON.stringify(body) }
      );
      if (!res.ok) {
        const err = await res.json();
        alert(err.error || 'Failed to decline registration');
        return;
      }
      closeDeclineModal();
      fetchRegistrations();
    } catch (err) {
      alert('Failed to decline registration');
    } finally {
      setDeclineSending(false);
    }
  };

  const handlePromoteWaitlist = async (regId: number) => {
    if (!window.confirm('Email this team a waitlist offer? They\'ll have 48 hours to accept or decline.')) return;
    setUpdatingId(regId);
    try {
      const res = await fetch(
        `${API_URL}/api/tournament-gateway.php?action=registration-waitlist-promote&id=${regId}`,
        { method: 'PUT', headers }
      );
      if (!res.ok) {
        const err = await res.json();
        alert(err.error || 'Failed to send offer');
        return;
      }
      fetchRegistrations();
    } catch (err) {
      alert('Failed to send waitlist offer');
    } finally {
      setUpdatingId(null);
    }
  };

  const handlePaymentUpdate = async (regId: number, paymentStatus: PaymentStatus, reference?: string) => {
    try {
      await fetch(
        `${API_URL}/api/tournament-gateway.php?action=registration-update-payment&id=${regId}`,
        { method: 'PUT', headers, body: JSON.stringify({ payment_status: paymentStatus, payment_reference: reference }) }
      );
      fetchRegistrations();
    } catch (err) {
      alert('Failed to update payment');
    }
  };

  const totalCount = Object.values(counts).reduce((a, b) => a + b, 0);

  const hasDivisions = divisions.length > 0;
  const isPreOpen = !!(registrationOpenDate && new Date(registrationOpenDate).getTime() > Date.now());
  const notOpenCopy = status ? REGISTRATION_NOT_OPEN_COPY[status] : undefined;

  const columns: DataTableColumn<TournamentRegistration>[] = [
    {
      key: 'team',
      header: 'Team',
      render: (reg) => (
        <>
          <div className="font-medium text-gray-900">{reg.display_name}</div>
          {reg.club_name_override && (
            <div className="text-xs text-gray-500">{reg.club_name_override}</div>
          )}
        </>
      ),
    },
    {
      key: 'division',
      header: 'Division',
      render: (reg) => <span className="text-gray-600">{reg.division_name}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (reg) => (
        <>
          <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[reg.status]}`}>
            {reg.status}
            {reg.status === 'waitlisted' && reg.waitlist_position != null && (
              <span className="ml-1 text-orange-700/70 font-normal">#{reg.waitlist_position}</span>
            )}
          </span>
          {/* Waitlist-offer state pill: shown only on waitlisted rows
              with an active or terminal offer state. Helps the
              director see "this team has an offer out, don't
              re-promote yet" at a glance. */}
          {reg.status === 'waitlisted' && reg.waitlist_offer_state && reg.waitlist_offer_state !== 'none' && (
            <span className={`ml-1 inline-flex px-2 py-0.5 rounded-full text-[10px] font-medium ${
              reg.waitlist_offer_state === 'offered'  ? 'bg-purple-100 text-purple-700'
              : reg.waitlist_offer_state === 'declined' ? 'bg-gray-100 text-gray-600'
              : 'bg-amber-100 text-amber-700'
            }`}>
              {reg.waitlist_offer_state === 'offered' && reg.waitlist_offer_expires_at
                ? `offered, expires ${new Date(reg.waitlist_offer_expires_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`
                : reg.waitlist_offer_state}
            </span>
          )}
        </>
      ),
    },
    {
      key: 'payment',
      header: 'Payment',
      render: (reg) => (
        <>
          <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${PAYMENT_COLORS[reg.payment_status]}`}>
            {reg.payment_status}
          </span>
          {reg.payment_amount_cents ? (
            <span className="ml-1 text-xs text-gray-500">${(reg.payment_amount_cents / 100).toFixed(2)}</span>
          ) : null}
        </>
      ),
    },
    {
      key: 'registered_by',
      header: 'Registered By',
      render: (reg) => <span className="text-gray-500">{reg.registered_by_name}</span>,
    },
    ...(isAdmin
      ? [
          {
            key: 'actions',
            header: 'Actions',
            actions: true,
            className: 'space-x-1',
            render: (reg: TournamentRegistration) => (
              <>
                {reg.status === 'pending' && (
                  <>
                    <Button
                      variant="link"
                      size="sm"
                      onClick={() => handleStatusUpdate(reg.id, 'accepted')}
                      disabled={updatingId === reg.id}
                    >
                      Accept
                    </Button>
                    <Button
                      variant="danger-link"
                      size="sm"
                      onClick={() => openDeclineModal(reg)}
                      disabled={updatingId === reg.id}
                    >
                      Reject
                    </Button>
                  </>
                )}
                {reg.status === 'waitlisted' && (
                  <>
                    <Button
                      variant="link"
                      size="sm"
                      onClick={() => handleStatusUpdate(reg.id, 'accepted')}
                      disabled={updatingId === reg.id}
                    >
                      Accept
                    </Button>
                    {/* Promote = email this team an offer with a 48h
                        acceptance window. Useful when re-offering a
                        previously declined/expired row, or when the
                        director wants to skip ahead in the FIFO queue. */}
                    <Button
                      variant="link"
                      size="sm"
                      onClick={() => handlePromoteWaitlist(reg.id)}
                      disabled={updatingId === reg.id || reg.waitlist_offer_state === 'offered'}
                      title={reg.waitlist_offer_state === 'offered'
                        ? 'Offer already sent — waiting for response'
                        : 'Email this team an offer with a 48-hour acceptance window'}
                    >
                      Promote
                    </Button>
                  </>
                )}
                {reg.payment_status === 'unpaid' && (
                  <Button
                    variant="link"
                    size="sm"
                    onClick={() => {
                      const ref = prompt('Payment reference (check #, etc):');
                      if (ref !== null) handlePaymentUpdate(reg.id, 'paid', ref);
                    }}
                  >
                    Mark Paid
                  </Button>
                )}
                {(reg.status === 'accepted' || reg.status === 'waitlisted') && (
                  <Button
                    variant="link"
                    size="sm"
                    onClick={() => setRosterRegistration(reg)}
                    title="Manage tournament roster for this team"
                  >
                    Roster
                  </Button>
                )}
              </>
            ),
          } as DataTableColumn<TournamentRegistration>,
        ]
      : []),
  ];

  if (showForm) {
    return (
      <RegistrationForm
        tournamentId={tournamentId}
        divisions={divisions}
        clubId={clubId}
        isAdmin={isAdmin}
        registrationOpenDate={registrationOpenDate ?? null}
        onSave={() => { setShowForm(false); fetchRegistrations(); }}
        onCancel={() => setShowForm(false)}
      />
    );
  }

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h3 className="text-lg font-semibold text-gray-900">
          Registrations ({totalCount})
        </h3>
        <Button
          onClick={() => setShowForm(true)}
          disabled={!hasDivisions}
          title={hasDivisions ? undefined : 'Add a division first'}
        >
          Register Team
        </Button>
      </div>

      {/* Why a team may not be registerable yet. A division is required — the
          division dropdown on the form has nothing to offer without one. */}
      {!hasDivisions && (
        <div className="mb-4 bg-amber-50 border border-amber-200 rounded-md p-3 text-amber-800 text-sm">
          <strong>No divisions yet.</strong> Every registration goes into a division, so add at
          least one on the <strong>Divisions</strong> tab before registering teams.
        </div>
      )}

      {hasDivisions && isPreOpen && (
        <div className="mb-4 bg-amber-50 border border-amber-200 rounded-md p-3 text-amber-800 text-sm">
          Registration opens{' '}
          <strong>
            {new Date(registrationOpenDate as string).toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' })}
          </strong>
          . Teams added before then join the waitlist and are promoted automatically when it opens.
        </div>
      )}

      {hasDivisions && !isPreOpen && notOpenCopy && (
        <div className="mb-4 bg-blue-50 border border-blue-200 rounded-md p-3 text-blue-800 text-sm">
          {notOpenCopy} You can still add teams here yourself.
        </div>
      )}

      {/* Status counts */}
      <div className="flex space-x-3 mb-4">
        {Object.entries(counts).map(([status, count]) => (
          <button
            key={status}
            onClick={() => setStatusFilter(statusFilter === status ? '' : status)}
            className={`px-3 py-1 rounded-full text-xs font-medium ${
              statusFilter === status ? 'ring-2 ring-brand-primary' : ''
            } ${STATUS_COLORS[status] || 'bg-gray-100 text-gray-600'}`}
          >
            {status}: {count}
          </button>
        ))}
      </div>

      {/* Division filter */}
      {divisions.length > 1 && (
        <div className="mb-4">
          <select
            value={divisionFilter}
            onChange={(e) => setDivisionFilter(e.target.value)}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm"
          >
            <option value="">All Divisions</option>
            {divisions.map((d) => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </select>
        </div>
      )}

      {/* Table */}
      {loading ? (
        <div className="text-center py-8 text-gray-500">Loading registrations...</div>
      ) : (
        <DataTable<TournamentRegistration>
          columns={columns}
          rows={registrations}
          rowKey={(reg) => reg.id}
          emptyState="No registrations yet."
        />
      )}

      {rosterRegistration && (
        <RegistrationRosterModal
          registration={rosterRegistration}
          onClose={() => setRosterRegistration(null)}
        />
      )}

      {decliningReg && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-semibold text-gray-900">
                Decline {decliningReg.display_name}
              </h3>
              <p className="text-xs text-gray-500 mt-1">
                Edit the email below before confirming, or check "Don't send email" for a silent decline.
              </p>
            </div>

            <div className="px-6 py-4 space-y-4">
              {declinePreviewLoading ? (
                <div className="text-center text-gray-500 py-8">Loading email preview…</div>
              ) : (
                <>
                  {/* Recipient list */}
                  <div className="text-xs text-gray-600">
                    <span className="font-medium uppercase">To:</span>{' '}
                    {declineRecipients.length === 0
                      ? <span className="italic text-gray-400">No valid email on file</span>
                      : declineRecipients.map((r, i) => (
                          <span key={i}>
                            {r.name} &lt;{r.email}&gt;{i < declineRecipients.length - 1 ? ', ' : ''}
                          </span>
                        ))}
                  </div>

                  {/* Skip email */}
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={declineSkipEmail}
                      onChange={(e) => setDeclineSkipEmail(e.target.checked)}
                      className="w-4 h-4"
                    />
                    <span className="text-sm font-medium text-gray-700">
                      Don't send an email — silent decline
                    </span>
                  </label>

                  {/* Subject */}
                  <div className={declineSkipEmail ? 'opacity-50 pointer-events-none' : ''}>
                    <label className="block text-xs font-medium text-gray-700 uppercase mb-1">Subject</label>
                    <input
                      type="text"
                      value={declineSubject}
                      onChange={(e) => setDeclineSubject(e.target.value)}
                      disabled={declineSkipEmail}
                      className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-primary"
                    />
                  </div>

                  {/* Body */}
                  <div className={declineSkipEmail ? 'opacity-50 pointer-events-none' : ''}>
                    <label className="block text-xs font-medium text-gray-700 uppercase mb-1">Message</label>
                    <textarea
                      value={declineBody}
                      onChange={(e) => setDeclineBody(e.target.value)}
                      disabled={declineSkipEmail}
                      rows={10}
                      className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-primary font-sans"
                    />
                    <p className="text-[11px] text-gray-400 mt-1">
                      Plain text. The email will use this as both the visible body and the basis for HTML formatting.
                    </p>
                  </div>

                  {/* Offer another division */}
                  {declineSiblingDivisions.length > 0 && (
                    <div className={declineSkipEmail ? 'opacity-50 pointer-events-none' : ''}>
                      <label className="block text-xs font-medium text-gray-700 uppercase mb-1">
                        Offer another division (optional)
                      </label>
                      <select
                        value={declineOfferedDivisionId}
                        onChange={(e) => setDeclineOfferedDivisionId(e.target.value === '' ? '' : Number(e.target.value))}
                        disabled={declineSkipEmail}
                        className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-brand-primary"
                      >
                        <option value="">— Don't offer another division —</option>
                        {declineSiblingDivisions.map((d) => {
                          const full = d.max_teams != null && d.accepted_count >= d.max_teams;
                          return (
                            <option key={d.id} value={d.id}>
                              {d.name}
                              {d.max_teams != null && ` (${d.accepted_count}/${d.max_teams}${full ? ' — full' : ''})`}
                            </option>
                          );
                        })}
                      </select>
                      <p className="text-[11px] text-gray-400 mt-1">
                        Adds a paragraph to the email offering the team a spot in this division.
                      </p>
                    </div>
                  )}
                </>
              )}
            </div>

            <div className="px-6 py-4 border-t border-gray-200 flex justify-end gap-2">
              <Button variant="secondary" onClick={closeDeclineModal} disabled={declineSending}>
                Cancel
              </Button>
              <Button
                variant="danger"
                onClick={submitDecline}
                loading={declineSending}
                disabled={declinePreviewLoading}
              >
                {declineSkipEmail ? 'Decline silently' : 'Send Decline & Email'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default RegistrationManager;

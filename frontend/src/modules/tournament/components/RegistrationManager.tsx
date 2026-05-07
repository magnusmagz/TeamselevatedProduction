import React, { useState, useEffect, useCallback } from 'react';
import { TournamentRegistration, TournamentDivision, RegistrationStatus, PaymentStatus } from '../types';
import RegistrationForm from './RegistrationForm';
import RegistrationRosterModal from './RegistrationRosterModal';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Props {
  tournamentId: number;
  divisions: TournamentDivision[];
  isAdmin: boolean;
  clubId: number;
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

const RegistrationManager: React.FC<Props> = ({ tournamentId, divisions, isAdmin, clubId, registrationOpenDate }) => {
  const [registrations, setRegistrations] = useState<TournamentRegistration[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [divisionFilter, setDivisionFilter] = useState<string>('');
  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [rosterRegistration, setRosterRegistration] = useState<TournamentRegistration | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers: HeadersInit = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

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
  }, [tournamentId, statusFilter, divisionFilter]);

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
        <button
          onClick={() => setShowForm(true)}
          className="inline-flex items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-primary hover:bg-brand-primary-hover"
        >
          Register Team
        </button>
      </div>

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
      ) : registrations.length === 0 ? (
        <div className="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
          <p className="text-gray-500">No registrations yet.</p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Team</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Division</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registered By</th>
                {isAdmin && <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>}
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {registrations.map((reg) => (
                <tr key={reg.id}>
                  <td className="px-4 py-3 text-sm">
                    <div className="font-medium text-gray-900">{reg.display_name}</div>
                    {reg.club_name_override && (
                      <div className="text-xs text-gray-500">{reg.club_name_override}</div>
                    )}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">{reg.division_name}</td>
                  <td className="px-4 py-3">
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
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${PAYMENT_COLORS[reg.payment_status]}`}>
                      {reg.payment_status}
                    </span>
                    {reg.payment_amount_cents ? (
                      <span className="ml-1 text-xs text-gray-500">${(reg.payment_amount_cents / 100).toFixed(2)}</span>
                    ) : null}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-500">{reg.registered_by_name}</td>
                  {isAdmin && (
                    <td className="px-4 py-3 text-right space-x-1">
                      {reg.status === 'pending' && (
                        <>
                          <button
                            onClick={() => handleStatusUpdate(reg.id, 'accepted')}
                            disabled={updatingId === reg.id}
                            className="text-xs text-green-600 hover:underline disabled:opacity-50"
                          >
                            Accept
                          </button>
                          <button
                            onClick={() => handleStatusUpdate(reg.id, 'rejected')}
                            disabled={updatingId === reg.id}
                            className="text-xs text-red-600 hover:underline disabled:opacity-50"
                          >
                            Reject
                          </button>
                        </>
                      )}
                      {reg.status === 'waitlisted' && (
                        <>
                          <button
                            onClick={() => handleStatusUpdate(reg.id, 'accepted')}
                            disabled={updatingId === reg.id}
                            className="text-xs text-green-600 hover:underline disabled:opacity-50"
                          >
                            Accept
                          </button>
                          {/* Promote = email this team an offer with a 48h
                              acceptance window. Useful when re-offering a
                              previously declined/expired row, or when the
                              director wants to skip ahead in the FIFO queue. */}
                          <button
                            onClick={() => handlePromoteWaitlist(reg.id)}
                            disabled={updatingId === reg.id || reg.waitlist_offer_state === 'offered'}
                            className="text-xs text-purple-600 hover:underline disabled:opacity-50"
                            title={reg.waitlist_offer_state === 'offered'
                              ? 'Offer already sent — waiting for response'
                              : 'Email this team an offer with a 48-hour acceptance window'}
                          >
                            Promote
                          </button>
                        </>
                      )}
                      {reg.payment_status === 'unpaid' && (
                        <button
                          onClick={() => {
                            const ref = prompt('Payment reference (check #, etc):');
                            if (ref !== null) handlePaymentUpdate(reg.id, 'paid', ref);
                          }}
                          className="text-xs text-blue-600 hover:underline"
                        >
                          Mark Paid
                        </button>
                      )}
                      {(reg.status === 'accepted' || reg.status === 'waitlisted') && (
                        <button
                          onClick={() => setRosterRegistration(reg)}
                          className="text-xs text-gray-700 hover:underline"
                          title="Manage tournament roster for this team"
                        >
                          Roster
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {rosterRegistration && (
        <RegistrationRosterModal
          registration={rosterRegistration}
          onClose={() => setRosterRegistration(null)}
        />
      )}
    </div>
  );
};

export default RegistrationManager;

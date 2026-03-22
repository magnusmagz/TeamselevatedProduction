import React, { useState, useEffect } from 'react';
import { Program } from '../types';

interface Registration {
  id: number;
  program_id: number;
  program_name: string;
  athlete_id: number;
  guardian_id: number;
  status: 'pending' | 'approved' | 'rejected';
  submitted_at: string;
  reviewed_at: string | null;
  invoice_id: number | null;
  invoice_number: string | null;
  total_amount: number | null;
  invoice_status: string | null;
  form_data: {
    athlete_first: string;
    athlete_last: string;
    athlete_birthday: string;
    athlete_gender: string;
    athlete_grade?: string;
    guardian_first: string;
    guardian_last: string;
    guardian_email: string;
    mobile_phone: string;
  };
}

interface RegistrationsModalProps {
  program: Program;
  onClose: () => void;
}

const RegistrationsModal: React.FC<RegistrationsModalProps> = ({ program, onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [registrations, setRegistrations] = useState<Registration[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'all' | 'pending' | 'approved' | 'rejected'>('all');
  const [processing, setProcessing] = useState<number | null>(null);

  useEffect(() => {
    fetchRegistrations();
  }, [program.id]);

  const fetchRegistrations = async () => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/registration/registrations-api.php?program_id=${program.id}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setRegistrations(data);
    } catch (error) {
      console.error('Error fetching registrations:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleUpdateStatus = async (registrationId: number, status: 'approved' | 'rejected') => {
    setProcessing(registrationId);
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/registration/registrations-api.php?id=${registrationId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ status })
      });

      const result = await response.json();

      if (response.ok && result.success) {
        // If approved and we have an athlete_payment_id, create invoice
        if (status === 'approved' && result.athlete_payment_id) {
          await createInvoiceFromPayment(result.athlete_payment_id);
        }
        // Refresh the list
        await fetchRegistrations();
      }
    } catch (error) {
      console.error('Error updating registration:', error);
    } finally {
      setProcessing(null);
    }
  };

  const createInvoiceFromPayment = async (athletePaymentId: number) => {
    try {
      // Create invoice from the athlete payment
      const token = localStorage.getItem('auth_token');
      await fetch(`${API_URL}/api/invoices.php?action=create`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({
          athlete_payment_id: athletePaymentId
        })
      });
    } catch (error) {
      console.error('Error creating invoice:', error);
    }
  };

  const filteredRegistrations = registrations.filter(reg => {
    if (filter === 'all') return true;
    return reg.status === filter;
  });

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'pending':
        return <span className="bg-yellow-100 text-yellow-800 text-xs font-semibold px-2 py-1 rounded">Pending</span>;
      case 'approved':
        return <span className="bg-green-100 text-green-800 text-xs font-semibold px-2 py-1 rounded">Approved</span>;
      case 'rejected':
        return <span className="bg-red-100 text-red-800 text-xs font-semibold px-2 py-1 rounded">Rejected</span>;
      default:
        return <span className="bg-gray-100 text-gray-800 text-xs font-semibold px-2 py-1 rounded">{status}</span>;
    }
  };

  const pendingCount = registrations.filter(r => r.status === 'pending').length;
  const approvedCount = registrations.filter(r => r.status === 'approved').length;
  const rejectedCount = registrations.filter(r => r.status === 'rejected').length;

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      <div className="absolute inset-0 bg-black bg-opacity-30" onClick={onClose} />
      <div className="bg-white rounded-md shadow-xl absolute inset-y-0 right-0 w-full max-w-[75vw] flex flex-col">
        {/* Header */}
        <div className="bg-brand-primary text-white p-6">
          <div className="flex justify-between items-start">
            <div>
              <h2 className="text-xl font-bold">Registrations</h2>
              <p className="text-blue-100 mt-1">{program.name}</p>
            </div>
            <button
              onClick={onClose}
              className="text-white hover:text-gray-200 text-2xl leading-none"
            >
              &times;
            </button>
          </div>
        </div>

        {/* Stats Bar */}
        <div className="bg-gray-50 border-b px-6 py-3 flex gap-4 text-sm">
          <span className="text-gray-600">
            Total: <strong>{registrations.length}</strong>
          </span>
          <span className="text-yellow-600">
            Pending: <strong>{pendingCount}</strong>
          </span>
          <span className="text-green-600">
            Approved: <strong>{approvedCount}</strong>
          </span>
          <span className="text-red-600">
            Rejected: <strong>{rejectedCount}</strong>
          </span>
        </div>

        {/* Filter Tabs */}
        <div className="border-b px-6">
          <div className="flex gap-1">
            {(['all', 'pending', 'approved', 'rejected'] as const).map((f) => (
              <button
                key={f}
                onClick={() => setFilter(f)}
                className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors ${
                  filter === f
                    ? 'border-brand-primary text-brand-primary'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                }`}
              >
                {f.charAt(0).toUpperCase() + f.slice(1)}
                {f === 'pending' && pendingCount > 0 && (
                  <span className="ml-2 bg-yellow-100 text-yellow-800 text-xs px-2 py-0.5 rounded-full">
                    {pendingCount}
                  </span>
                )}
              </button>
            ))}
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {loading ? (
            <div className="text-center py-12 text-gray-500">Loading registrations...</div>
          ) : filteredRegistrations.length === 0 ? (
            <div className="text-center py-12 text-gray-500">
              {filter === 'all' ? 'No registrations yet.' : `No ${filter} registrations.`}
            </div>
          ) : (
            <div className="space-y-4">
              {filteredRegistrations.map((reg) => (
                <div
                  key={reg.id}
                  className="border rounded-lg p-4 hover:shadow-md transition-shadow"
                >
                  <div className="flex justify-between items-start">
                    {/* Athlete & Guardian Info */}
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-2">
                        <h3 className="font-semibold text-lg text-brand-primary">
                          {reg.form_data.athlete_first} {reg.form_data.athlete_last}
                        </h3>
                        {getStatusBadge(reg.status)}
                      </div>
                      <div className="grid grid-cols-2 gap-x-8 gap-y-1 text-sm text-gray-600">
                        <div>
                          <span className="text-gray-400">Birthday:</span>{' '}
                          {new Date(reg.form_data.athlete_birthday).toLocaleDateString()}
                        </div>
                        <div>
                          <span className="text-gray-400">Gender:</span>{' '}
                          {reg.form_data.athlete_gender}
                        </div>
                        {reg.form_data.athlete_grade && (
                          <div>
                            <span className="text-gray-400">Grade:</span>{' '}
                            {reg.form_data.athlete_grade}
                          </div>
                        )}
                        <div>
                          <span className="text-gray-400">Submitted:</span>{' '}
                          {new Date(reg.submitted_at).toLocaleDateString()}
                        </div>
                      </div>
                      <div className="mt-3 pt-3 border-t text-sm">
                        <span className="text-gray-400">Guardian:</span>{' '}
                        <span className="text-gray-700">
                          {reg.form_data.guardian_first} {reg.form_data.guardian_last}
                        </span>
                        <span className="text-gray-400 ml-4">Email:</span>{' '}
                        <a href={`mailto:${reg.form_data.guardian_email}`} className="text-brand-primary hover:underline">
                          {reg.form_data.guardian_email}
                        </a>
                        <span className="text-gray-400 ml-4">Phone:</span>{' '}
                        <span className="text-gray-700">{reg.form_data.mobile_phone}</span>
                      </div>
                    </div>

                    {/* Action Buttons */}
                    {reg.status === 'pending' && (
                      <div className="flex gap-2 ml-4">
                        <button
                          onClick={() => handleUpdateStatus(reg.id, 'approved')}
                          disabled={processing === reg.id}
                          className="bg-brand-primary hover:bg-brand-primary-hover text-white px-4 py-2 rounded font-medium text-sm disabled:opacity-50"
                        >
                          {processing === reg.id ? 'Processing...' : 'Approve'}
                        </button>
                        <button
                          onClick={() => handleUpdateStatus(reg.id, 'rejected')}
                          disabled={processing === reg.id}
                          className="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded font-medium text-sm disabled:opacity-50"
                        >
                          Reject
                        </button>
                      </div>
                    )}

                    {reg.status === 'approved' && (
                      <div className="ml-4 text-right">
                        {reg.invoice_id ? (
                          <div className="space-y-1">
                            <div className="text-sm text-gray-500">
                              {reg.invoice_number} • ${Number(reg.total_amount).toFixed(2)}
                            </div>
                            <div className="flex gap-2 justify-end">
                              <button
                                onClick={() => {
                                  const paymentUrl = `${window.location.origin}/pay/${reg.invoice_id}`;
                                  navigator.clipboard.writeText(paymentUrl);
                                  alert('Payment link copied to clipboard!');
                                }}
                                className="text-xs text-brand-primary hover:text-brand-primary-dark underline"
                              >
                                Copy Payment Link
                              </button>
                              <a
                                href={`/pay/${reg.invoice_id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs text-brand-primary hover:text-brand-primary-hover underline"
                              >
                                View Invoice
                              </a>
                            </div>
                            {reg.invoice_status === 'paid' && (
                              <span className="inline-block bg-green-100 text-green-800 text-xs font-semibold px-2 py-0.5 rounded">
                                Paid
                              </span>
                            )}
                          </div>
                        ) : (
                          <div className="text-sm text-gray-500">
                            No invoice yet
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="border-t px-6 py-4 bg-gray-50">
          <button
            onClick={onClose}
            className="px-6 py-2 border border-gray-300 rounded text-gray-700 hover:bg-gray-100 font-medium"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
};

export default RegistrationsModal;

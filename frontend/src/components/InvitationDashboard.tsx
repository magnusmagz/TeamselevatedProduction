import React, { useState, useEffect } from 'react';

interface Invitation {
  id: string;
  email: string;
  role: string;
  status: string;
  inviter_name: string;
  created_at: string;
  personal_message?: string;
}

interface InvitationLink {
  id: string;
  code: string;
  role: string;
  max_uses: number | null;
  uses_count: number;
  url: string;
  creator_name: string;
  created_at: string;
}

interface InvitationDashboardProps {
  clubId?: number;
}

export default function InvitationDashboard({ clubId }: InvitationDashboardProps) {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const [invitations, setInvitations] = useState<Invitation[]>([]);
  const [invitationLinks, setInvitationLinks] = useState<InvitationLink[]>([]);
  const [filter, setFilter] = useState<'all' | 'pending' | 'accepted'>('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchInvitations();
  }, [clubId, filter]);

  const fetchInvitations = async () => {
    try {
      setLoading(true);
      const token = localStorage.getItem('auth_token');
      const params = new URLSearchParams();

      if (clubId) params.append('clubId', clubId.toString());
      if (filter !== 'all') params.append('status', filter);

      const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=list&${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      if (!response.ok) throw new Error('Failed to fetch invitations');

      const data = await response.json();
      setInvitations(data.invitations || []);
      setInvitationLinks(data.invitationLinks || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  const handleResend = async (invitationId: string) => {
    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=resend`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ invitationId })
      });

      if (!response.ok) throw new Error('Failed to resend invitation');

      alert('Invitation resent successfully!');
      fetchInvitations();
    } catch (err) {
      alert('Failed to resend invitation');
    }
  };

  const handleCancel = async (invitationId: string) => {
    if (!window.confirm('Are you sure you want to cancel this invitation?')) return;

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/invitations-gateway.php?action=cancel`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ invitationId })
      });

      if (!response.ok) throw new Error('Failed to cancel invitation');

      fetchInvitations();
    } catch (err) {
      alert('Failed to cancel invitation');
    }
  };

  const getStatusBadge = (status: string) => {
    const classes = {
      pending: 'bg-yellow-100 text-yellow-800',
      accepted: 'bg-green-100 text-green-800',
      expired: 'bg-gray-100 text-gray-800',
      canceled: 'bg-red-100 text-red-800',
    };

    return (
      <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-md uppercase ${classes[status as keyof typeof classes] || 'bg-gray-100 text-gray-800'}`}>
        {status}
      </span>
    );
  };

  const formatRole = (role: string) => {
    return role.replace('_', ' ').toUpperCase();
  };

  if (loading) {
    return <div className="text-center py-8 text-brand-primary">Loading invitations...</div>;
  }

  if (error) {
    return <div className="text-red-600 text-center py-8">{error}</div>;
  }

  return (
    <div className="space-y-6">
      {/* Filter Tabs */}
      <div className="flex space-x-1 bg-gray-100 p-1 rounded-md">
        <button
          onClick={() => setFilter('all')}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-semibold transition-all uppercase ${
            filter === 'all'
              ? 'bg-white text-brand-primary shadow-sm'
              : 'text-gray-700 hover:text-gray-900'
          }`}
        >
          All Invitations
        </button>
        <button
          onClick={() => setFilter('pending')}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-semibold transition-all uppercase ${
            filter === 'pending'
              ? 'bg-white text-brand-primary shadow-sm'
              : 'text-gray-700 hover:text-gray-900'
          }`}
        >
          Pending
        </button>
        <button
          onClick={() => setFilter('accepted')}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-semibold transition-all uppercase ${
            filter === 'accepted'
              ? 'bg-white text-brand-primary shadow-sm'
              : 'text-gray-700 hover:text-gray-900'
          }`}
        >
          Accepted
        </button>
      </div>

      {/* Invitation Links Section */}
      {invitationLinks.length > 0 && (
        <div className="bg-brand-secondary border border-brand-secondary rounded-md p-4">
          <h3 className="text-lg font-semibold text-brand-primary mb-3 uppercase">Active Invitation Links</h3>
          <div className="space-y-2">
            {invitationLinks.map((link) => (
              <div key={link.id} className="bg-white p-3 rounded-md border border-brand-secondary">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="font-mono text-sm text-brand-primary font-semibold">{link.code}</p>
                    <p className="text-xs text-gray-600 mt-1">
                      Created by {link.creator_name} •
                      {link.max_uses ? ` ${link.uses_count}/${link.max_uses} uses` : ` ${link.uses_count} uses`} •
                      Role: {formatRole(link.role)}
                    </p>
                  </div>
                  <button
                    onClick={() => navigator.clipboard.writeText(link.url)}
                    className="text-sm text-brand-primary hover:text-brand-primary-hover font-semibold px-3 py-1 border border-brand-secondary rounded-md hover:bg-brand-secondary uppercase"
                  >
                    Copy Link
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Invitations List */}
      <div className="bg-white rounded-md border border-brand-secondary overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-brand-secondary">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                  Email
                </th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                  Role
                </th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                  Invited By
                </th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                  Date
                </th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-brand-primary uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-brand-secondary">
              {invitations.map((invitation) => (
                <tr key={invitation.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="flex items-center text-sm text-brand-primary">
                      <svg className="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                      </svg>
                      {invitation.email}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-brand-primary">
                    {formatRole(invitation.role)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    {getStatusBadge(invitation.status)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    {invitation.inviter_name}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    {new Date(invitation.created_at).toLocaleDateString()}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm">
                    {invitation.status === 'pending' && (
                      <div className="flex space-x-2">
                        <button
                          onClick={() => handleResend(invitation.id)}
                          className="text-brand-primary hover:text-brand-primary-hover font-semibold uppercase"
                        >
                          Resend
                        </button>
                        <button
                          onClick={() => handleCancel(invitation.id)}
                          className="text-red-600 hover:text-red-700 font-semibold uppercase"
                        >
                          Cancel
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {invitations.length === 0 && (
          <div className="text-center py-8 text-gray-600">
            No invitations found
          </div>
        )}
      </div>
    </div>
  );
}

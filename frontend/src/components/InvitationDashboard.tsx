import React, { useState, useEffect, useCallback } from 'react';
import DataTable, { DataTableColumn } from './ui/DataTable';
import Button from './ui/Button';

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
  // Track which invitation row has an in-flight resend/cancel so we can
  // disable its buttons and avoid double-submits ("invited over and over").
  const [resendingId, setResendingId] = useState<string | null>(null);
  const [cancelingId, setCancelingId] = useState<string | null>(null);

  const fetchInvitations = useCallback(async () => {
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
  }, [API_URL, clubId, filter]);

  useEffect(() => {
    fetchInvitations();
  }, [fetchInvitations]);

  const handleResend = async (invitationId: string) => {
    // Guard against double-submits / rapid re-clicks.
    if (resendingId) return;
    setResendingId(invitationId);
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

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        // Surface the cooldown / specific server message when present.
        throw new Error(data.error || 'Failed to resend invitation');
      }

      alert('Invitation resent successfully!');
      fetchInvitations();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to resend invitation');
    } finally {
      setResendingId(null);
    }
  };

  const handleCancel = async (invitationId: string) => {
    if (cancelingId) return;
    if (!window.confirm('Are you sure you want to cancel this invitation?')) return;

    setCancelingId(invitationId);
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

      const data = await response.json().catch(() => ({}));

      if (!response.ok) throw new Error(data.error || 'Failed to cancel invitation');

      fetchInvitations();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to cancel invitation');
    } finally {
      setCancelingId(null);
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

  const invitationColumns: DataTableColumn<Invitation>[] = [
    {
      key: 'email',
      header: 'Email',
      className: 'whitespace-nowrap',
      render: (invitation) => (
        <div className="flex items-center text-sm text-brand-primary">
          <svg className="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
          {invitation.email}
        </div>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      className: 'whitespace-nowrap text-brand-primary',
      render: (invitation) => formatRole(invitation.role),
    },
    {
      key: 'status',
      header: 'Status',
      className: 'whitespace-nowrap',
      render: (invitation) => getStatusBadge(invitation.status),
    },
    {
      key: 'inviter_name',
      header: 'Invited By',
      className: 'whitespace-nowrap text-gray-700',
      render: (invitation) => invitation.inviter_name,
    },
    {
      key: 'created_at',
      header: 'Date',
      className: 'whitespace-nowrap text-gray-600',
      render: (invitation) => new Date(invitation.created_at).toLocaleDateString(),
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'whitespace-nowrap',
      render: (invitation) =>
        invitation.status === 'pending' ? (
          <div className="flex space-x-2">
            <Button
              variant="link"
              onClick={() => handleResend(invitation.id)}
              disabled={resendingId === invitation.id || cancelingId === invitation.id}
            >
              {resendingId === invitation.id ? 'Resending...' : 'Resend'}
            </Button>
            <Button
              variant="danger-link"
              onClick={() => handleCancel(invitation.id)}
              disabled={cancelingId === invitation.id || resendingId === invitation.id}
            >
              {cancelingId === invitation.id ? 'Canceling...' : 'Cancel'}
            </Button>
          </div>
        ) : null,
    },
  ];

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
                  <Button variant="secondary" size="sm" onClick={() => navigator.clipboard.writeText(link.url)}>
                    Copy Link
                  </Button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Invitations List */}
      <DataTable<Invitation>
        columns={invitationColumns}
        rows={invitations}
        rowKey={(invitation) => invitation.id}
        emptyState="No invitations found"
      />
    </div>
  );
}

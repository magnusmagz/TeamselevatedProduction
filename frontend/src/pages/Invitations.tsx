import React from 'react';
import { useOrg } from '../contexts/OrgContext';
import InviteUsersForm from '../components/InviteUsersForm';
import InvitationDashboard from '../components/InvitationDashboard';

export default function Invitations() {
  const { currentClubId } = useOrg();

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-brand-primary uppercase">Invitations</h1>
        <p className="text-gray-600 mt-2">
          Invite users to join your organization as coaches or admins
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Invite Form - Left Column */}
        <div className="lg:col-span-1">
          <InviteUsersForm
            clubId={currentClubId || undefined}
          />
        </div>

        {/* Dashboard - Right Column */}
        <div className="lg:col-span-2">
          <InvitationDashboard
            clubId={currentClubId || undefined}
          />
        </div>
      </div>
    </main>
  );
}

import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { usePWAInstall } from '../../hooks/usePWAInstall';
import { ParentHeader } from '../components/ParentHeader';
import { SupportDialog } from '../../components/support/SupportDialog';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';

interface MenuItem {
  label: string;
  description?: string;
  to?: string;
  onClick?: () => void;
  icon: React.ReactNode;
  external?: boolean;
}

export const MoreMenuPage: React.FC = () => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const { isInstallable, isInstalled, isIOS, promptInstall } = usePWAInstall();
  const [supportOpen, setSupportOpen] = useState(false);
  const { roles } = useFinancialPermissions();

  /**
   * The way back for someone who wears both hats.
   *
   * The staff ProfileMenu now links INTO the portal; without this the trip is
   * one-way, and a coach who came here to check their own child's schedule would
   * be stuck on a surface with no route to the team they coach.
   *
   * Staff-only, so an ordinary parent never sees a door into an app that would
   * show them nothing.
   */
  const hasStaffAccess = Boolean(
    roles.is_coach || roles.is_club_admin || roles.is_treasurer ||
    user?.system_role === 'super_admin'
  );

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  const menuItems: MenuItem[] = [
    {
      label: 'My Athletes',
      description: 'View and manage your athletes',
      to: '/parent/athletes',
      icon: (
        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
        </svg>
      ),
    },
    {
      label: 'Announcements',
      description: 'Team and club announcements',
      to: '/parent/announcements',
      icon: (
        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
        </svg>
      ),
    },
    {
      label: 'Documents',
      description: 'View and download documents',
      to: '/parent/documents',
      icon: (
        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
        </svg>
      ),
    },
    {
      label: 'Volunteer',
      description: 'View assignments and sign up to volunteer',
      to: '/parent/volunteer',
      icon: (
        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
        </svg>
      ),
    },
    {
      label: 'Account Settings',
      description: 'Update your profile and preferences',
      to: '/parent/settings',
      icon: (
        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
      ),
    },
  ];

  return (
    <div className="min-h-screen bg-gray-50">
      <SupportDialog open={supportOpen} onClose={() => setSupportOpen(false)} />
      <ParentHeader title="More" />

      <div className="pt-14 pb-4">
        {/* User Info */}
        <div className="bg-white border-b border-gray-200 px-4 py-6">
          <div className="flex items-center gap-4">
            <div className="w-16 h-16 rounded-full bg-brand-primary text-white flex items-center justify-center text-xl font-medium">
              {user?.name
                ?.split(' ')
                .map((n) => n[0])
                .join('')
                .toUpperCase()
                .slice(0, 2)}
            </div>
            <div>
              <h2 className="text-lg font-semibold text-brand-primary">{user?.name}</h2>
              <p className="text-gray-600">{user?.email}</p>
            </div>
          </div>
        </div>

        {/* Install App Banner */}
        {(isInstallable || (isIOS && !isInstalled)) && (
          <div className="px-4 pt-4">
            <div className="bg-brand-secondary rounded-lg p-4">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-brand-primary flex items-center justify-center">
                  <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                </div>
                <div className="flex-1">
                  <p className="font-medium text-brand-primary">Install App</p>
                  <p className="text-sm text-brand-primary/80">
                    {isIOS
                      ? 'Add to home screen for quick access'
                      : 'Install for a better experience'}
                  </p>
                </div>
                {isInstallable && (
                  <button
                    onClick={promptInstall}
                    className="bg-brand-primary text-white px-4 py-2 rounded-lg font-medium hover:bg-brand-primary-hover transition-colors"
                  >
                    Install
                  </button>
                )}
              </div>
              {isIOS && !isInstalled && (
                <p className="text-xs text-brand-primary/70 mt-2">
                  Tap the share button, then "Add to Home Screen"
                </p>
              )}
            </div>
          </div>
        )}

        {/* Menu Items */}
        <div className="px-4 pt-4 space-y-2">
          {menuItems.map((item) => {
            const content = (
              <div className="flex items-center gap-4 p-4 bg-white rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors">
                <span className="text-brand-primary">{item.icon}</span>
                <div className="flex-1">
                  <p className="font-medium text-gray-900">{item.label}</p>
                  {item.description && (
                    <p className="text-sm text-gray-500">{item.description}</p>
                  )}
                </div>
                <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </div>
            );

            if (item.to) {
              return (
                <Link key={item.label} to={item.to}>
                  {content}
                </Link>
              );
            }

            if (item.onClick) {
              return (
                <button key={item.label} onClick={item.onClick} className="w-full text-left">
                  {content}
                </button>
              );
            }

            return null;
          })}
        </div>

        {/* GOTR G4 — the coach's own compliance list, the same page the staff
            profile menu links to. A coach-parent may be living in either app, so
            it has a door in both; a parent with no staff role sees neither the
            link nor anything on the page. */}
        {hasStaffAccess && (
          <div className="px-4 pt-6">
            <Link
              to="/compliance/mine"
              className="flex items-center gap-4 p-4 bg-white rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
            >
              <span className="text-gray-400">
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </span>
              <div className="flex-1">
                <p className="font-medium text-gray-900">My requirements</p>
                <p className="text-sm text-gray-500">Background checks, training and expiry dates</p>
              </div>
              <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            </Link>
          </div>
        )}

        {hasStaffAccess && (
          <div className="px-4 pt-6">
            <Link
              to="/dashboard"
              className="flex items-center gap-4 p-4 bg-white rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
            >
              <span className="text-gray-400">
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
              </span>
              <div className="flex-1">
                <p className="font-medium text-gray-900">Staff view</p>
                <p className="text-sm text-gray-500">Teams, schedules and club tools</p>
              </div>
              <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            </Link>
          </div>
        )}

        {/* Report an issue — replaced a mailto:support@ link on 2026-08-17.
            A mailto asks the family to describe a bug from memory, in a mail
            client, with none of the context we actually need. This opens the same
            form the rest of the app uses and carries the page, device and an
            optional screenshot with it. It keeps the old link's prominent slot
            precisely because burying it in the list above is what we were trying
            to avoid. */}
        <div className="px-4 pt-6">
          <button
            onClick={() => setSupportOpen(true)}
            className="w-full text-left flex items-center gap-4 p-4 bg-white rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
          >
            <span className="text-gray-400">
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
                <circle cx="12" cy="12" r="9" />
                <circle cx="12" cy="12" r="3.5" />
                <path d="M5.6 5.6l3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9" />
              </svg>
            </span>
            <div className="flex-1">
              <p className="font-medium text-gray-900">Report an issue</p>
              <p className="text-sm text-gray-500">Something not working? Tell us</p>
            </div>
            <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>

        {/* Logout Button */}
        <div className="px-4 pt-6">
          <button
            onClick={handleLogout}
            className="w-full flex items-center justify-center gap-2 p-4 bg-white rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition-colors"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
            <span className="font-medium">Log Out</span>
          </button>
        </div>

        {/* Version */}
        <div className="text-center text-xs text-gray-400 py-6">
          Teams Elevated v1.0.0
        </div>
      </div>
    </div>
  );
};

export default MoreMenuPage;

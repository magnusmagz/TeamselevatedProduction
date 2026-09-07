import React, { useState, useRef, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';
import { useFinancialPermissions } from '../contexts/FinancialPermissionsContext';
import Button from './ui/Button';

const ProfileMenu: React.FC = () => {
  const [isOpen, setIsOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const { user, logout } = useAuth();
  const { isClubAdmin } = useOrg();
  const { roles } = useFinancialPermissions();
  const navigate = useNavigate();

  /**
   * Staff who are also parents had NO way into the parent portal.
   *
   * ParentRedirect deliberately leaves anyone holding a staff role on the staff
   * dashboard, and nothing in the app linked to /parent — the only reference in
   * the frontend was a catch-all redirect inside the portal itself. So a coach who
   * is also a parent could not reach their own child's schedule, invoices,
   * documents or RSVPs at all without typing the URL. 12 accounts are in that
   * position (7 CKU, 3 club 32, 2 club 50).
   *
   * The predicate is deliberately IDENTICAL to ProtectedParentRoute's. If this
   * were any broader the link would appear for someone the route then bounces,
   * which is worse than no link.
   */
  const jwtIsParent =
    user?.roles?.some((r: { role: string }) => r.role === 'parent') ||
    user?.activeRole?.role === 'parent';
  const hasParentAccess = Boolean(roles.is_parent || jwtIsParent);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [isOpen]);

  const handleSignOut = async () => {
    await logout();
    navigate('/');
  };

  return (
    <div className="relative" ref={menuRef}>
      <Button
        variant="ghost"
        onClick={() => setIsOpen(!isOpen)}
        aria-expanded={isOpen}
        trailingIcon={
          <svg
            className={`w-4 h-4 transition-transform ${isOpen ? 'rotate-180' : ''}`}
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
          </svg>
        }
      >
        {user?.name || 'Profile'}
      </Button>

      {isOpen && (
        <div className="absolute right-0 mt-2 w-48 max-sm:w-44 max-sm:-right-2 bg-white border border-brand-secondary rounded-md shadow-lg z-50">
          <div className="py-1">
            <Link
              to="/profile"
              onClick={() => setIsOpen(false)}
              className="block px-4 py-3 text-sm text-brand-primary hover:bg-brand-secondary uppercase font-medium"
            >
              My Profile
            </Link>
            {/* GOTR G4 — a coach's own compliance list. Shown to anyone signed
                in rather than gated on a staff role: standing comes from ROLE and
                the page itself says "your club has not asked you for anything
                yet" when there is nothing, which is an honest answer. Gating it
                on a role guess is how the chat typeahead went empty for nine
                coaches who had no team. */}
            <Link
              to="/compliance/mine"
              onClick={() => setIsOpen(false)}
              className="block px-4 py-3 text-sm text-brand-primary hover:bg-brand-secondary uppercase font-medium"
            >
              My Requirements
            </Link>
            {hasParentAccess && (
              <Link
                to="/parent"
                onClick={() => setIsOpen(false)}
                className="block px-4 py-3 text-sm text-brand-primary hover:bg-brand-secondary uppercase font-medium"
              >
                Parent Portal
              </Link>
            )}
            {isClubAdmin && (
              <Link
                to="/club-profile"
                onClick={() => setIsOpen(false)}
                className="block px-4 py-3 text-sm text-brand-primary hover:bg-brand-secondary uppercase font-medium"
              >
                Club Settings
              </Link>
            )}
            <button
              onClick={handleSignOut}
              className="w-full text-left px-4 py-3 text-sm text-brand-primary hover:bg-brand-secondary uppercase font-medium"
            >
              Sign Out
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default ProfileMenu;

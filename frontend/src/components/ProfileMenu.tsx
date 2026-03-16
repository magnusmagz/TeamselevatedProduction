import React, { useState, useRef, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

const ProfileMenu: React.FC = () => {
  const [isOpen, setIsOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const { user, logout } = useAuth();
  const { isClubAdmin } = useOrg();
  const navigate = useNavigate();

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
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="px-4 py-2 text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm flex items-center space-x-1"
      >
        <span>{user?.name || 'Profile'}</span>
        <svg
          className={`w-4 h-4 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>

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

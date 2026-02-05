import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import BrandingLogo from '../../components/BrandingLogo';

interface ParentHeaderProps {
  title?: string;
  showBack?: boolean;
  onBack?: () => void;
  rightElement?: React.ReactNode;
}

export const ParentHeader: React.FC<ParentHeaderProps> = ({
  title,
  showBack = false,
  onBack,
  rightElement,
}) => {
  const navigate = useNavigate();
  const { user } = useAuth();

  const handleBack = () => {
    if (onBack) {
      onBack();
    } else {
      navigate(-1);
    }
  };

  return (
    <header
      className="fixed top-0 left-0 right-0 bg-white border-b border-gray-200 z-40"
      style={{ paddingTop: 'var(--safe-area-inset-top, 0px)' }}
    >
      <div className="flex items-center justify-between h-14 px-4">
        {/* Left side */}
        <div className="flex items-center min-w-[60px]">
          {showBack ? (
            <button
              onClick={handleBack}
              className="p-2 -ml-2 text-gray-600 hover:text-gray-900 touch-manipulation"
              aria-label="Go back"
            >
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
              </svg>
            </button>
          ) : (
            <BrandingLogo size="md" fallbackToText={true} />
          )}
        </div>

        {/* Center - Title */}
        {title && (
          <h1 className="text-lg font-semibold text-gray-900 truncate flex-1 text-center px-2">
            {title}
          </h1>
        )}

        {/* Right side */}
        <div className="flex items-center min-w-[60px] justify-end">
          {rightElement || (
            <div className="flex items-center">
              <span className="text-sm text-gray-600 truncate max-w-[100px]">
                {user?.name?.split(' ')[0]}
              </span>
            </div>
          )}
        </div>
      </div>
    </header>
  );
};

export default ParentHeader;

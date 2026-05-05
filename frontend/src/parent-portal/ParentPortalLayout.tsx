import React from 'react';
import { Outlet } from 'react-router-dom';
import { BottomNavigation } from './components/BottomNavigation';
import { InstallPrompt } from './components/InstallPrompt';
import { SponsorMarquee } from './components/SponsorMarquee';

interface ParentPortalLayoutProps {
  children?: React.ReactNode;
}

export const ParentPortalLayout: React.FC<ParentPortalLayoutProps> = ({ children }) => {
  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      {/* Install prompt banner - shown at top when applicable */}
      <InstallPrompt />

      {/* Main content area with safe area insets */}
      <main
        className="flex-1 pb-20"
        style={{
          paddingTop: 'var(--safe-area-inset-top, 0px)',
          paddingLeft: 'var(--safe-area-inset-left, 0px)',
          paddingRight: 'var(--safe-area-inset-right, 0px)',
        }}
      >
        {/* Content wrapper */}
        <div className="max-w-lg mx-auto w-full">
          {children || <Outlet />}
        </div>
      </main>

      {/* Sponsor marquee — sits above bottom nav, hidden when no sponsors configured */}
      <SponsorMarquee />

      {/* Bottom navigation */}
      <BottomNavigation />
    </div>
  );
};

export default ParentPortalLayout;

import React from 'react';
import { Outlet } from 'react-router-dom';
import { BottomNavigation } from './components/BottomNavigation';
import { InstallPrompt } from './components/InstallPrompt';
import { SponsorMarquee } from './components/SponsorMarquee';
import { ParentErrorBoundary } from './components/ParentErrorBoundary';
import { ConsentGate } from './components/ConsentGate';
import { ChatProvider } from './contexts/ChatContext';

interface ParentPortalLayoutProps {
  children?: React.ReactNode;
}

export const ParentPortalLayout: React.FC<ParentPortalLayoutProps> = ({ children }) => {
  return (
    <ChatProvider>
    {/* ConsentGate wraps the WHOLE shell, bottom nav included, so an outstanding
        consent renders instead of the portal rather than over it — there is no
        route around a screen that was never mounted. See ConsentGate for why it
        still offers a decline path. */}
    <ConsentGate>
    <div className="min-h-screen bg-gray-50 flex flex-col">
      {/* Install prompt banner - shown at top when applicable */}
      <InstallPrompt />

      {/* Main content area with safe area insets */}
      <main
        className="flex-1 pb-32"
        style={{
          paddingTop: 'var(--safe-area-inset-top, 0px)',
          paddingLeft: 'var(--safe-area-inset-left, 0px)',
          paddingRight: 'var(--safe-area-inset-right, 0px)',
        }}
      >
        {/* Content wrapper */}
        <div className="max-w-lg mx-auto w-full">
          <ParentErrorBoundary>
            {children || <Outlet />}
          </ParentErrorBoundary>
        </div>
      </main>

      {/* Sponsor marquee — sits above bottom nav, hidden when no sponsors configured */}
      <SponsorMarquee />

      {/* Bottom navigation */}
      <BottomNavigation />
    </div>
    </ConsentGate>
    </ChatProvider>
  );
};

export default ParentPortalLayout;

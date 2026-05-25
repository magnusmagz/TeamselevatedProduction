import React, { useState } from 'react';
import { usePWAInstall } from '../../hooks/usePWAInstall';

const DISMISS_KEY = 'pwa-install-dismissed';

export const InstallPrompt: React.FC = () => {
  // All platform detection (iOS / Android / installed) comes from the hook so
  // the hook is the single source of truth — the component no longer reaches
  // into navigator / matchMedia itself (which previously diverged from the hook
  // and made the component fragile in non-browser/test environments).
  const { isInstallable, isInstalled, isIOS, isAndroid, promptInstall } = usePWAInstall();
  const [dismissed, setDismissed] = useState(() => {
    const stored = localStorage.getItem(DISMISS_KEY);
    if (!stored) return false;
    // Allow re-prompting after 7 days
    const dismissedAt = parseInt(stored, 10);
    return Date.now() - dismissedAt < 7 * 24 * 60 * 60 * 1000;
  });

  const handleDismiss = () => {
    setDismissed(true);
    localStorage.setItem(DISMISS_KEY, Date.now().toString());
  };

  // Don't show if already installed (display-mode standalone) or dismissed
  if (isInstalled || dismissed) {
    return null;
  }

  // Show iOS-specific instructions
  if (isIOS) {
    return (
      <div className="bg-brand-secondary px-4 py-3 border-b border-brand-primary/20 sticky top-0 z-50">
        <div className="flex items-start gap-3">
          <div className="flex-shrink-0 mt-0.5">
            <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-brand-primary">
              Install Teams Elevated
            </p>
            <p className="text-xs text-brand-primary/80 mt-0.5">
              Tap the share button <span className="inline-block">
                <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
              </span> then "Add to Home Screen"
            </p>
          </div>
          <button
            onClick={handleDismiss}
            className="flex-shrink-0 w-11 h-11 flex items-center justify-center text-brand-primary/60 hover:text-brand-primary"
            aria-label="Dismiss"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    );
  }

  // Show Android/Chrome install prompt (native prompt available)
  if (isInstallable) {
    return (
      <div className="bg-brand-secondary px-4 py-3 border-b border-brand-primary/20 sticky top-0 z-50">
        <div className="flex items-center gap-3">
          <div className="flex-shrink-0">
            <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-brand-primary">
              Install Teams Elevated for quick access
            </p>
          </div>
          <button
            onClick={promptInstall}
            className="flex-shrink-0 bg-brand-primary text-white px-3 py-1.5 text-sm font-medium rounded hover:bg-brand-primary-hover transition-colors"
          >
            Install
          </button>
          <button
            onClick={handleDismiss}
            className="flex-shrink-0 w-11 h-11 flex items-center justify-center text-brand-primary/60 hover:text-brand-primary"
            aria-label="Dismiss"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    );
  }

  // Android fallback: Chrome hasn't fired beforeinstallprompt yet, show manual instructions
  if (isAndroid) {
    return (
      <div className="bg-brand-secondary px-4 py-3 border-b border-brand-primary/20 sticky top-0 z-50">
        <div className="flex items-start gap-3">
          <div className="flex-shrink-0 mt-0.5">
            <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-brand-primary">
              Install Teams Elevated
            </p>
            <p className="text-xs text-brand-primary/80 mt-0.5">
              Tap the menu <span className="inline font-bold">(&#8942;)</span> then "Add to Home Screen" or "Install App"
            </p>
          </div>
          <button
            onClick={handleDismiss}
            className="flex-shrink-0 w-11 h-11 flex items-center justify-center text-brand-primary/60 hover:text-brand-primary"
            aria-label="Dismiss"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    );
  }

  return null;
};

export default InstallPrompt;

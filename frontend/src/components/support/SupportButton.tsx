import React, { useState } from 'react';
import { SupportDialog } from './SupportDialog';

/**
 * Floating "Report an issue" button.
 *
 * ─── Why bottom-LEFT ──────────────────────────────────────────────────────────
 * Bottom-right is taken. `ChatWidget` sits at `fixed bottom-6 right-6 z-50`, and
 * on mobile its launcher pins to the same corner. A second bubble there would
 * either overlap it or push it somewhere unfamiliar, and chat is the more
 * frequently used of the two.
 *
 * The parent portal is a different story again: it has a full-width
 * `BottomNavigation` at `fixed bottom-0 … z-50` with a `SponsorMarquee` above it
 * at `bottom-16 z-40`. The entire bottom strip is spoken for there, so this
 * button is NOT rendered on the portal at all — the entry point there is a row in
 * the More menu, matching how every other portal destination works.
 *
 * z-40 keeps it under both the chat panel and the bottom nav (z-50), so if
 * anything ever does overlap, this is what goes behind rather than what covers
 * something else. The dialog itself opens at z-60, above everything.
 */
export const SupportButton: React.FC = () => {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        onClick={() => setOpen(true)}
        aria-label="Report an issue"
        title="Report an issue"
        className="fixed bottom-6 left-6 z-40 w-12 h-12 rounded-full bg-white text-brand-primary
                   border border-brand-secondary shadow-lg flex items-center justify-center
                   hover:bg-gray-50 hover:scale-105 transition-all duration-200
                   print:hidden"
      >
        {/* Lifebuoy — distinct from the chat bubble at a glance. */}
        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
          <circle cx="12" cy="12" r="3.5" />
          <path d="M5.6 5.6l3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9" />
        </svg>
      </button>

      <SupportDialog open={open} onClose={() => setOpen(false)} />
    </>
  );
};

export default SupportButton;

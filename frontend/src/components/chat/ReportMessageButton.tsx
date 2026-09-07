import React, { useState, useRef, useEffect } from 'react';
import Button from '../ui/Button';

/**
 * Report a message to the club's moderation queue.
 *
 * Reasons are a closed set so the queue can be sorted and a compliance summary
 * has something to aggregate — the wording here must stay in step with
 * REPORT_REASONS in chat-server/lib/reports.js, which is what the server
 * validates against.
 *
 * Once reported the control becomes inert and says so. It deliberately does NOT
 * report whether anyone else flagged the same message or what an admin decided:
 * that would leak other people's reports and the outcome of a review.
 */

export const REPORT_REASONS: Array<{ value: string; label: string }> = [
  { value: 'safety_concern', label: 'Safety concern' },
  { value: 'harassment', label: 'Harassment or bullying' },
  { value: 'inappropriate', label: 'Inappropriate content' },
  { value: 'personal_information', label: 'Shares personal information' },
  { value: 'spam', label: 'Spam' },
  { value: 'other', label: 'Something else' },
];

interface Props {
  messageId: string;
  reported?: boolean;
  onReport: (messageId: string, reason: string) => void;
}

export default function ReportMessageButton({ messageId, reported, onReport }: Props) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onEsc = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onEsc);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onEsc);
    };
  }, [open]);

  if (reported) {
    return (
      <span className="text-xs text-gray-400 px-1" title="You reported this message">
        Reported
      </span>
    );
  }

  return (
    <div className="relative" ref={ref}>
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setOpen(o => !o)}
        aria-label="Report this message"
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 2H21l-3 6 3 6h-8.5l-1-2H5a2 2 0 00-2 2z"
          />
        </svg>
      </Button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 z-20 mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg py-1"
        >
          <p className="px-3 py-1.5 text-xs font-semibold text-gray-500 border-b border-gray-100">
            Report this message
          </p>
          {REPORT_REASONS.map(r => (
            <button
              key={r.value}
              role="menuitem"
              onClick={() => {
                onReport(messageId, r.value);
                setOpen(false);
              }}
              className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
            >
              {r.label}
            </button>
          ))}
          <p className="px-3 py-1.5 text-xs text-gray-400 border-t border-gray-100">
            A club administrator will review it.
          </p>
        </div>
      )}
    </div>
  );
}

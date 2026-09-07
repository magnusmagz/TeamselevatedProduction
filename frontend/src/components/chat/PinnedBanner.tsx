import React from 'react';
import { PinnedMessage } from './pollTypes';
import Button from '../ui/Button';

interface Props {
  pinned: PinnedMessage | null;
  canPin: boolean;
  onUnpin: (messageId: string) => void;
}

/**
 * The pinned message, above the conversation.
 *
 * One per conversation, so this is "the pinned message" rather than a list — a
 * stale pin is obvious when there is only ever one, and invisible when there
 * are four.
 *
 * ⚠️ It is STICKY in the parent portal, so the conversation scrolls beneath it.
 * A part-transparent ground alone let message text show straight through and
 * collide with the banner's own (2026-08-28). It keeps the translucency — which
 * reads better than a solid slab — and adds a backdrop blur, so what passes
 * underneath is softened rather than legible.
 *
 * Deliberately compact and single-line. A pin that grows to fill the screen
 * stops being a reference and starts being an obstacle to the conversation it
 * sits above; the full text is one tap away in the message itself.
 */
export const PinnedBanner: React.FC<Props> = ({ pinned, canPin, onUnpin }) => {
  if (!pinned) return null;

  return (
    <div className="flex items-start gap-2 px-3 py-2 bg-brand-secondary/80 backdrop-blur-md border-b border-brand-secondary">
      <span aria-hidden="true" className="text-brand-primary text-sm leading-5">📌</span>

      <div className="min-w-0 flex-1">
        <p className="text-xs uppercase tracking-wide text-brand-primary font-semibold">
          Pinned by {pinned.pinnedBy}
        </p>
        <p className="text-sm text-gray-700 truncate" title={pinned.text}>
          <span className="font-medium">{pinned.sender}:</span> {pinned.text}
        </p>
      </div>

      {canPin && (
        <Button variant="link" size="sm" onClick={() => onUnpin(pinned.messageId)} className="shrink-0">
          Unpin
        </Button>
      )}
    </div>
  );
};

export default PinnedBanner;

import React from 'react';
import { REACTION_EMOJI, MessageReaction, describeReactors } from './reactionEmoji';
import { sameUser } from './sameUser';

interface Props {
  reactions?: MessageReaction[];
  currentUserId: string | number | undefined;
  onToggle: (emoji: string) => void;
  /** Right-aligned under your own messages, left under everyone else's. */
  align?: 'left' | 'right';
}

/**
 * Reactions under a message, plus the picker to add one.
 *
 * Names are shown, not hidden. A reaction is a lightweight reply — an anonymous
 * one reads as oddly furtive in a club where everyone already knows each other,
 * and "who said they're coming" is usually the useful part. They surface on
 * hover and long-press rather than always, so a busy message stays readable.
 */
export const MessageReactions: React.FC<Props> = ({
  reactions, currentUserId, onToggle, align = 'left',
}) => {
  const [pickerOpen, setPickerOpen] = React.useState(false);
  const wrapRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    if (!pickerOpen) return;
    const onOutside = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setPickerOpen(false);
    };
    document.addEventListener('mousedown', onOutside);
    return () => document.removeEventListener('mousedown', onOutside);
  }, [pickerOpen]);

  const existing = (reactions || []).filter((r) => r.count > 0);

  const choose = (emoji: string) => {
    onToggle(emoji);
    setPickerOpen(false);
  };

  return (
    <div
      ref={wrapRef}
      className={`relative flex items-center gap-1 mt-1 flex-wrap ${align === 'right' ? 'justify-end' : ''}`}
    >
      {existing.map((r) => {
        const mine = r.users.some((u) => sameUser(u.id, currentUserId));
        return (
          <button
            key={r.emoji}
            type="button"
            onClick={() => onToggle(r.emoji)}
            title={describeReactors(r.users)}
            aria-pressed={mine}
            aria-label={`${r.emoji} ${r.count}${mine ? ', including you' : ''}. ${describeReactors(r.users)}`}
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs leading-none transition-colors ${
              mine
                ? 'border-brand-primary bg-brand-secondary text-brand-primary font-semibold'
                : 'border-brand-secondary bg-white text-gray-600 hover:bg-brand-secondary/40'
            }`}
          >
            <span aria-hidden="true">{r.emoji}</span>
            <span className="tabular-nums">{r.count}</span>
          </button>
        );
      })}

      <button
        type="button"
        onClick={() => setPickerOpen((o) => !o)}
        aria-label="Add a reaction"
        aria-expanded={pickerOpen}
        className="inline-flex items-center justify-center h-6 w-6 rounded-full border border-brand-secondary text-gray-500 hover:bg-brand-secondary/40 text-sm leading-none"
      >
        +
      </button>

      {pickerOpen && (
        <div
          role="menu"
          className={`absolute bottom-full mb-1 z-20 flex gap-1 rounded-full border border-brand-secondary bg-white px-2 py-1 shadow-lg ${
            align === 'right' ? 'right-0' : 'left-0'
          }`}
        >
          {REACTION_EMOJI.map((emoji) => (
            <button
              key={emoji}
              type="button"
              role="menuitem"
              onClick={() => choose(emoji)}
              aria-label={`React with ${emoji}`}
              className="h-7 w-7 rounded-full text-base leading-none hover:bg-brand-secondary/50"
            >
              {emoji}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

export default MessageReactions;

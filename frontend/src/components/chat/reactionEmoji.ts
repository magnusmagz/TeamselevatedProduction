/**
 * The six reactions, agreed with Maggie 2026-08-28.
 *
 * Acknowledge, warmth, celebrate, well done, funny, surprise. Six fits one row
 * on a phone.
 *
 * ⚠️ **Nothing negative, deliberately.** A thumbs-down or an angry face in a
 * parent group chat creates conflict a message never would, and once it is on
 * screen it gets used. Adding one is a product decision, not a tweak.
 *
 * ⚠️ Mirrored in TWO other places, and all three must change together:
 * `chat-server/lib/reactions.js` and the CHECK constraint in migration 079. The
 * database is the enforcement — a picker is only a suggestion — so a set that
 * drifts here produces reactions the server silently refuses.
 */
export const REACTION_EMOJI = ['👍', '❤️', '🎉', '👏', '😂', '😮'] as const;

export type ReactionEmoji = (typeof REACTION_EMOJI)[number];

/** Who used one reaction on one message. */
export interface MessageReaction {
  emoji: string;
  count: number;
  users: { id: string; name: string }[];
}

/** "Pat, Cora and 3 others" — for the tooltip on a reaction. */
export function describeReactors(users: { name: string }[]): string {
  const names = users.map((u) => u.name).filter(Boolean);

  if (names.length === 0) return '';
  if (names.length === 1) return names[0];
  if (names.length === 2) return `${names[0]} and ${names[1]}`;
  if (names.length === 3) return `${names[0]}, ${names[1]} and ${names[2]}`;

  return `${names[0]}, ${names[1]} and ${names.length - 2} others`;
}

import type { MessageReaction } from './reactionEmoji';
import type { PollView } from './pollTypes';

export interface Conversation {
  id: number;
  type: 'team' | 'direct' | 'group';
  teamId?: number;
  teamName?: string;
  participants: ConversationParticipant[];
  lastMessage?: { text: string; timestamp: string; senderName: string };
  unreadCount: number;
  displayName: string;
  /**
   * When this user archived the conversation; null/absent when active. Archive is
   * per-user view state — it says nothing about other participants, and no
   * message is ever removed.
   */
  archivedAt?: string | null;
}

export interface ConversationParticipant {
  userId: number;
  displayName: string;
  role: string;
}

export interface ChatMessage {
  id: string;
  conversationId: number;
  text: string;
  sender: string;
  // ⚠️ STRING at runtime, despite what you'd expect. lib/JWT.php mints the
  // user_id claim as (string)$userId and the chat server echoes it unchanged.
  // Declaring this `number` is what let "75" === 75 hide from the compiler and
  // ship two visible bugs. Compare with sameUser(), never with ===.
  senderId: string | number;
  timestamp: string;
  time?: string;
  role?: string;
  /** Set on optimistically-appended messages until the server echo reconciles them. */
  pending?: boolean;
  /** Set when an optimistic send could not be dispatched (socket offline). */
  failed?: boolean;
  /**
   * Removed by a club admin. The server nulls `text` for these, so never render
   * `text` for a removed message — render the tombstone.
   */
  removed?: boolean;
  /**
   * Reactions on this message, grouped per emoji by the server.
   *
   * Absent on an optimistic message and on any message loaded before the server
   * started sending them, so always read it defensively — never assume the array
   * exists.
   */
  reactions?: MessageReaction[];
  /** 'text' unless stated. A poll is a message, which is how it inherits
   *  notifications, moderation and archiving without those knowing about polls. */
  messageType?: 'text' | 'poll';
  /** Present on a poll message, already rendered for THIS viewer by the server. */
  poll?: PollView;
}

export interface TypingUser {
  username: string;
  timestamp: number;
}

export interface ChatUser {
  id: number;
  name: string;
  email: string;
  role: string;
  canCreate: boolean;
}

export interface TeamMember {
  userId: number;
  displayName: string;
  role: string;
}

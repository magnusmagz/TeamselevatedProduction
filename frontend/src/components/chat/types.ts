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
  senderId: number;
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

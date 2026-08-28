import { io, Socket } from 'socket.io-client';

// Chat server URL
const CHAT_SOCKET_URL = process.env.REACT_APP_CHAT_SOCKET_URL || 'https://teamselevated-chat-a1a81c3e1a90.herokuapp.com';

let socket: Socket | null = null;

/**
 * Connect to the chat server with just a JWT token (no scope needed).
 * Returns the socket instance.
 */
export function connectChat(token: string): Socket {
  if (socket?.connected) {
    // Already connected, re-authenticate
    socket.emit('authenticate', { token });
    return socket;
  }

  if (socket) {
    socket.disconnect();
  }

  socket = io(CHAT_SOCKET_URL, {
    transports: ['websocket', 'polling'],
    autoConnect: true,
    forceNew: true,
    // Recover automatically from dropped / slow connections. Without these a
    // single network blip can leave the socket permanently disconnected and
    // PAR-29 (real-time receipt) silently stops working.
    reconnection: true,
    reconnectionAttempts: Infinity,
    reconnectionDelay: 1000,      // initial backoff (ms)
    reconnectionDelayMax: 5000,   // max backoff (ms)
    randomizationFactor: 0.5,     // jitter so reconnects don't thundering-herd
    timeout: 20000
  });

  socket.on('connect', () => {
    console.log('Chat socket connected:', socket?.id);
    socket?.emit('authenticate', { token });
  });

  socket.on('disconnect', (reason) => {
    console.log('Chat socket disconnected:', reason);
  });

  socket.on('connect_error', (error) => {
    console.error('Chat socket connection error:', error.message);
  });

  return socket;
}

export const chatSocket = {
  getSocket: () => socket,

  isConnected: () => socket?.connected || false,

  /** Request the list of conversations for the authenticated user */
  loadConversations: () => {
    if (socket?.connected) {
      socket.emit('loadConversations');
    }
  },

  /** Join a conversation room to receive messages */
  joinConversation: (conversationId: number) => {
    if (socket?.connected) {
      socket.emit('joinConversation', { conversationId });
    }
  },

  /** Leave a conversation room */
  leaveConversation: (conversationId: number) => {
    if (socket?.connected) {
      socket.emit('leaveConversation', { conversationId });
    }
  },

  /** Create a new DM or group conversation (coaches/admins only) */
  createConversation: (participantIds: number[], type?: 'direct' | 'group') => {
    if (socket?.connected) {
      socket.emit('createConversation', { participantIds, type });
    }
  },

  /** Get team members for the participant picker (coaches/admins only) */
  getTeamMembers: (teamId: number) => {
    if (socket?.connected) {
      socket.emit('getTeamMembers', { teamId });
    }
  },

  /**
   * Send a message to a conversation.
   * Returns true if the emit was dispatched, false if the socket is not
   * connected (so callers can flag the optimistic message accordingly).
   */
  sendMessage: (conversationId: number, text: string): boolean => {
    if (socket?.connected) {
      socket.emit('sendMessage', { conversationId, text });
      return true;
    }
    console.error('Cannot send message - socket not connected');
    return false;
  },

  /** Send typing indicator */
  sendTyping: (conversationId: number, username: string, isTyping: boolean) => {
    if (socket?.connected) {
      socket.emit('typing', { conversationId, username, isTyping });
    }
  },

  /** Mark a conversation as read */
  markRead: (conversationId: number) => {
    if (socket?.connected) {
      socket.emit('markRead', { conversationId });
    }
  },

  /**
   * Report a message to the club's moderation queue.
   *
   * Any participant may report, and the response is the same whether or not the
   * message was already flagged — a reporter must not learn that someone else
   * reported it, or that an admin dismissed it.
   */
  reportMessage: (messageId: string, reason: string, note?: string): boolean => {
    if (socket?.connected) {
      socket.emit('reportMessage', { messageId, reason, note });
      return true;
    }
    console.error('Cannot report message - socket not connected');
    return false;
  },

  /**
   * Remove a message. Club admins only — the server enforces it; this is not a
   * control coaches or senders are ever shown.
   */
  removeMessage: (messageId: string, reason?: string): boolean => {
    if (socket?.connected) {
      socket.emit('removeMessage', { messageId, reason });
      return true;
    }
    console.error('Cannot remove message - socket not connected');
    return false;
  },

  /**
   * Archive a conversation — hides it from THIS user's list only.
   *
   * Not a delete. Nothing is removed, no other participant is affected, and the
   * next message in the thread brings it back. Chat has no user-facing delete by
   * design; the only removal path is admin moderation.
   *
   * Returns whether the emit was dispatched, so callers can avoid optimistically
   * hiding a conversation the server never heard about.
   */
  archiveConversation: (conversationId: number): boolean => {
    if (socket?.connected) {
      socket.emit('archiveConversation', { conversationId });
      return true;
    }
    console.error('Cannot archive conversation - socket not connected');
    return false;
  },

  /** Restore an archived conversation to this user's list */
  unarchiveConversation: (conversationId: number): boolean => {
    if (socket?.connected) {
      socket.emit('unarchiveConversation', { conversationId });
      return true;
    }
    console.error('Cannot unarchive conversation - socket not connected');
    return false;
  },

  /** Request this user's archived conversations */
  loadArchivedConversations: () => {
    if (socket?.connected) {
      socket.emit('loadArchivedConversations');
    }
  },

  /** Pin a message to the top of its conversation (coach or admin) */
  pinMessage: (messageId: string) => {
    if (socket?.connected) socket.emit('pinMessage', { messageId });
  },

  unpinMessage: (messageId: string) => {
    if (socket?.connected) socket.emit('unpinMessage', { messageId });
  },

  /** Cast or withdraw a poll vote */
  votePoll: (optionId: string) => {
    if (socket?.connected) socket.emit('votePoll', { optionId });
  },

  /** Create a poll in a conversation */
  createPoll: (input: {
    conversationId: number; question: string; options: string[];
    isAnonymous: boolean; allowMultiple: boolean; resultsBeforeVote: boolean; closesAt: string | null;
  }) => {
    if (socket?.connected) socket.emit('createPoll', input);
  },

  /** Add a reaction to a message */
  addReaction: (messageId: string, emoji: string) => {
    if (socket?.connected) {
      socket.emit('addReaction', { messageId, emoji });
    }
  },

  /** Remove a reaction from a message */
  removeReaction: (messageId: string, emoji: string) => {
    if (socket?.connected) {
      socket.emit('removeReaction', { messageId, emoji });
    }
  },

  /** Disconnect the socket */
  disconnect: () => {
    if (socket) {
      socket.disconnect();
      socket = null;
    }
  }
};

export default chatSocket;

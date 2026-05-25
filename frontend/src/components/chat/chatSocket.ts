import { io, Socket } from 'socket.io-client';
import type { Conversation, ChatMessage, TypingUser, ChatUser, TeamMember } from './types';

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

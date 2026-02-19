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
    forceNew: true
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

  /** Send a message to a conversation */
  sendMessage: (conversationId: number, text: string) => {
    if (socket?.connected) {
      socket.emit('sendMessage', { conversationId, text });
    } else {
      console.error('Cannot send message - socket not connected');
    }
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

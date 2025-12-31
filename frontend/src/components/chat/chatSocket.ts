import { io, Socket } from 'socket.io-client';
import { ChatScope } from './ChatWidget';

const CHAT_SOCKET_URL = process.env.REACT_APP_CHAT_SOCKET_URL || 'http://localhost:5001';

let socket: Socket | null = null;
let currentScope: ChatScope | null = null;

export function initializeChatSocket(token: string, scope: ChatScope) {
  // If scope changed, disconnect and reconnect
  if (socket && currentScope &&
      (currentScope.type !== scope.type || currentScope.id !== scope.id)) {
    socket.disconnect();
    socket = null;
  }

  currentScope = scope;

  if (!socket) {
    socket = io(CHAT_SOCKET_URL, {
      transports: ['websocket', 'polling'],
      autoConnect: true,
      forceNew: true
    });

    socket.on('connect', () => {
      console.log('Chat socket connected:', socket?.id);
      // Authenticate with token and scope
      socket?.emit('authenticate', {
        token,
        scopeType: scope.type,
        scopeId: scope.id
      });
    });

    socket.on('disconnect', (reason) => {
      console.log('Chat socket disconnected:', reason);
    });

    socket.on('connect_error', (error) => {
      console.error('Chat socket connection error:', error.message);
    });
  } else if (socket.connected) {
    // Already connected, just re-authenticate with new scope
    socket.emit('authenticate', {
      token,
      scopeType: scope.type,
      scopeId: scope.id
    });
  }

  return socket;
}

export const chatSocket = {
  getSocket: () => socket,

  isConnected: () => socket?.connected || false,

  sendMessage: (messageData: any) => {
    if (socket?.connected) {
      socket.emit('sendMessage', messageData);
    } else {
      console.error('Cannot send message - socket not connected');
    }
  },

  sendTyping: (typingData: { channel: string; username: string; isTyping: boolean }) => {
    if (socket?.connected) {
      socket.emit('typing', typingData);
    }
  },

  addReaction: (messageId: string, emoji: string) => {
    if (socket?.connected) {
      socket.emit('addReaction', { messageId, emoji });
    }
  },

  removeReaction: (messageId: string, emoji: string) => {
    if (socket?.connected) {
      socket.emit('removeReaction', { messageId, emoji });
    }
  },

  disconnect: () => {
    if (socket) {
      socket.disconnect();
      socket = null;
      currentScope = null;
    }
  }
};

export default chatSocket;

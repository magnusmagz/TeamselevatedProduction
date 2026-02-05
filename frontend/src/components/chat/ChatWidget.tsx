import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useOrg } from '../../contexts/OrgContext';
import { chatSocket, initializeChatSocket } from './chatSocket';
import ChatScopeSelector from './ChatScopeSelector';
import ChatMessageList from './ChatMessageList';
import ChatInput from './ChatInput';

export type ChatScope = {
  type: 'club' | 'team';
  id: number;
  name: string;
};

interface Message {
  id: string;
  text: string;
  sender: string;
  senderId: string;
  timestamp: string;
  time: string;
  channel: string;
  role?: string;
  scopeType: 'club' | 'team';
  scopeId: number;
}

interface TypingUser {
  username: string;
  timestamp: number;
}

export default function ChatWidget() {
  const { user } = useAuth();
  const { activeContext, currentClubId, isClubAdmin } = useOrg();

  // Check if user has chat access (coaches, admins, and parents can use chat)
  const hasChatAccess = isClubAdmin ||
    activeContext?.role === 'coach' ||
    activeContext?.role === 'club_admin' ||
    activeContext?.role === 'parent';

  const [isExpanded, setIsExpanded] = useState(false);
  const [isConnected, setIsConnected] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [typingUsers, setTypingUsers] = useState<TypingUser[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [activeChannel, setActiveChannel] = useState('general');

  // Chat scope - defaults to current context
  const [scope, setScope] = useState<ChatScope | null>(null);

  // Available teams for team-level chat (when in club context)
  const [availableTeams, setAvailableTeams] = useState<Array<{id: number; name: string}>>([]);

  // Initialize scope based on current context
  useEffect(() => {
    if (activeContext && !scope) {
      if (activeContext.scope_type === 'team') {
        setScope({
          type: 'team',
          id: activeContext.scope_id,
          name: activeContext.scope_name
        });
      } else if (activeContext.scope_type === 'club') {
        // Default to club-wide chat
        setScope({
          type: 'club',
          id: currentClubId || activeContext.scope_id,
          name: activeContext.scope_name
        });
      }
    }
  }, [activeContext, currentClubId, scope]);

  // Initialize socket connection
  useEffect(() => {
    if (!user || !scope) return;

    const token = localStorage.getItem('auth_token');
    if (!token) return;

    // Initialize socket with scope
    initializeChatSocket(token, scope);

    // Set up event listeners
    const socket = chatSocket.getSocket();
    if (!socket) return;

    const handleConnect = () => {
      console.log('Chat connected');
      setIsConnected(true);
    };

    const handleDisconnect = () => {
      console.log('Chat disconnected');
      setIsConnected(false);
    };

    const handleAuthSuccess = (data: any) => {
      console.log('Chat auth success:', data);
      setIsConnected(true);
    };

    const handleAuthError = (error: any) => {
      console.error('Chat auth error:', error);
      setIsConnected(false);
    };

    const handleMessageHistory = (history: Message[]) => {
      const channelMessages = history.filter(msg =>
        (msg.channel || 'general') === activeChannel
      );
      setMessages(channelMessages);
    };

    const handleNewMessage = (msg: Message) => {
      if ((msg.channel || 'general') === activeChannel) {
        setMessages(prev => {
          const exists = prev.some(m => m.id === msg.id);
          if (exists) return prev;
          return [...prev, msg];
        });

        // Increment unread if widget is collapsed
        if (!isExpanded) {
          setUnreadCount(prev => prev + 1);
        }
      }
    };

    const handleTypingUpdate = (data: { channel: string; typingUsers: TypingUser[] }) => {
      if (data.channel === activeChannel) {
        setTypingUsers(data.typingUsers || []);
      }
    };

    socket.on('connect', handleConnect);
    socket.on('disconnect', handleDisconnect);
    socket.on('authSuccess', handleAuthSuccess);
    socket.on('authError', handleAuthError);
    socket.on('messageHistory', handleMessageHistory);
    socket.on('receiveMessage', handleNewMessage);
    socket.on('typingUpdate', handleTypingUpdate);

    // Check initial connection
    setIsConnected(socket.connected);

    return () => {
      socket.off('connect', handleConnect);
      socket.off('disconnect', handleDisconnect);
      socket.off('authSuccess', handleAuthSuccess);
      socket.off('authError', handleAuthError);
      socket.off('messageHistory', handleMessageHistory);
      socket.off('receiveMessage', handleNewMessage);
      socket.off('typingUpdate', handleTypingUpdate);
    };
  }, [user, scope, activeChannel, isExpanded]);

  // Clear unread when expanded
  useEffect(() => {
    if (isExpanded) {
      setUnreadCount(0);
    }
  }, [isExpanded]);

  // Handle scope change
  const handleScopeChange = useCallback((newScope: ChatScope) => {
    setScope(newScope);
    setMessages([]);
    setTypingUsers([]);

    // Reconnect with new scope
    const token = localStorage.getItem('auth_token');
    if (token) {
      initializeChatSocket(token, newScope);
    }
  }, []);

  // Send message
  const handleSendMessage = useCallback((text: string) => {
    if (!text.trim() || !isConnected || !scope || !user) return;

    const message = {
      id: Date.now().toString(),
      text,
      sender: user.name || user.email,
      senderId: user.id,
      timestamp: new Date().toISOString(),
      channel: activeChannel,
      role: activeContext?.role,
      scopeType: scope.type,
      scopeId: scope.id
    };

    chatSocket.sendMessage(message);
  }, [isConnected, scope, user, activeChannel, activeContext]);

  // Handle typing
  const handleTyping = useCallback((isTyping: boolean) => {
    if (!user) return;
    chatSocket.sendTyping({
      channel: activeChannel,
      username: user.name || user.email,
      isTyping
    });
  }, [user, activeChannel]);

  // Don't render if no user or user doesn't have chat access
  if (!user || !hasChatAccess) return null;

  return (
    <div className="fixed bottom-6 right-6 z-50">
      {/* Collapsed state - Chat bubble */}
      {!isExpanded && (
        <button
          onClick={() => setIsExpanded(true)}
          className="w-14 h-14 bg-brand-primary hover:bg-brand-primary text-white rounded-full shadow-lg flex items-center justify-center transition-all duration-200 hover:scale-105"
          aria-label="Open chat"
        >
          <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>

          {/* Unread badge */}
          {unreadCount > 0 && (
            <span className="absolute -top-1 -right-1 bg-brand-secondary text-brand-primary text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center border border-brand-primary">
              {unreadCount > 9 ? '9+' : unreadCount}
            </span>
          )}
        </button>
      )}

      {/* Expanded state - Chat panel */}
      {isExpanded && (
        <div className="w-96 h-[32rem] bg-white rounded-lg shadow-2xl border border-brand-secondary flex flex-col overflow-hidden">
          {/* Header */}
          <div className="bg-brand-primary text-white px-4 py-3 flex items-center justify-between flex-shrink-0">
            <div className="flex items-center gap-2">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
              </svg>
              <span className="font-medium">Team Chat</span>
              <div className={`w-2 h-2 rounded-full ${isConnected ? 'bg-green-400' : 'bg-red-400'}`} />
            </div>
            <button
              onClick={() => setIsExpanded(false)}
              className="p-1 hover:bg-brand-secondary rounded transition-colors"
              aria-label="Close chat"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
              </svg>
            </button>
          </div>

          {/* Scope Selector */}
          <ChatScopeSelector
            currentScope={scope}
            onScopeChange={handleScopeChange}
            currentClubId={currentClubId}
            activeContext={activeContext}
          />

          {/* Messages */}
          <ChatMessageList
            messages={messages}
            currentUser={user}
            typingUsers={typingUsers}
          />

          {/* Input */}
          <ChatInput
            onSend={handleSendMessage}
            onTyping={handleTyping}
            disabled={!isConnected}
            placeholder={scope ? `Message ${scope.name}...` : 'Connecting...'}
          />
        </div>
      )}
    </div>
  );
}

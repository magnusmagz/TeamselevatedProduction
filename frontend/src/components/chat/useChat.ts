import { useState, useEffect, useCallback, useRef } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useOrg } from '../../contexts/OrgContext';
import { connectChat, chatSocket } from './chatSocket';
import type { Conversation, ChatMessage, TypingUser, ChatUser } from './types';

interface UseChatReturn {
  conversations: Conversation[];
  activeConversation: Conversation | null;
  messages: ChatMessage[];
  typingUsers: TypingUser[];
  isConnected: boolean;
  loading: boolean;
  chatUser: ChatUser | null;
  canCreate: boolean;
  totalUnreadCount: number;
  selectConversation: (conversation: Conversation | null) => void;
  sendMessage: (text: string) => void;
  createConversation: (participantIds: number[]) => void;
  handleTyping: (isTyping: boolean) => void;
}

export function useChat(): UseChatReturn {
  const { user } = useAuth();
  const { activeContext, isClubAdmin } = useOrg();

  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [activeConversation, setActiveConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [typingUsers, setTypingUsers] = useState<TypingUser[]>([]);
  const [isConnected, setIsConnected] = useState(false);
  const [loading, setLoading] = useState(true);
  const [chatUser, setChatUser] = useState<ChatUser | null>(null);

  const activeConvRef = useRef<Conversation | null>(null);

  // Keep ref in sync
  useEffect(() => {
    activeConvRef.current = activeConversation;
  }, [activeConversation]);

  const canCreate = chatUser?.canCreate || isClubAdmin ||
    activeContext?.role === 'coach' ||
    activeContext?.role === 'club_admin' ||
    (activeContext?.role as string) === 'owner';

  const totalUnreadCount = conversations.reduce((sum, c) => sum + (c.unreadCount || 0), 0);

  // Connect socket on mount
  useEffect(() => {
    if (!user) return;

    const token = localStorage.getItem('auth_token');
    if (!token) return;

    const socket = connectChat(token);

    const handleConnect = () => setIsConnected(true);
    const handleDisconnect = () => setIsConnected(false);

    const handleAuthSuccess = (data: { user: ChatUser }) => {
      setIsConnected(true);
      setChatUser(data.user);
      // Load conversations after auth
      chatSocket.loadConversations();
    };

    const handleAuthError = (error: { message: string }) => {
      console.error('Chat auth error:', error.message);
      setIsConnected(false);
      setLoading(false);
    };

    const handleConversationsList = (convs: Conversation[]) => {
      setConversations(convs);
      setLoading(false);
    };

    const handleMessageHistory = (data: { conversationId: number; messages: ChatMessage[] }) => {
      if (activeConvRef.current?.id === data.conversationId) {
        setMessages(data.messages);
      }
    };

    const handleNewMessage = (msg: ChatMessage) => {
      if (activeConvRef.current?.id === msg.conversationId) {
        setMessages(prev => {
          const exists = prev.some(m => m.id === msg.id);
          if (exists) return prev;
          return [...prev, msg];
        });
      }

      // Update conversation list preview and unread
      setConversations(prev => prev.map(c => {
        if (c.id === msg.conversationId) {
          return {
            ...c,
            lastMessage: {
              text: msg.text.substring(0, 100),
              timestamp: msg.timestamp,
              senderName: msg.sender
            },
            unreadCount: activeConvRef.current?.id === msg.conversationId
              ? c.unreadCount
              : c.unreadCount + 1
          };
        }
        return c;
      }));
    };

    const handleConversationUpdated = (data: {
      conversationId: number;
      lastMessage: { text: string; timestamp: string; senderName: string };
    }) => {
      setConversations(prev => {
        const updated = prev.map(c => {
          if (c.id === data.conversationId) {
            return { ...c, lastMessage: data.lastMessage };
          }
          return c;
        });
        // Sort by last message time
        return updated.sort((a, b) => {
          const aTime = a.lastMessage?.timestamp || '';
          const bTime = b.lastMessage?.timestamp || '';
          return bTime.localeCompare(aTime);
        });
      });
    };

    const handleConversationCreated = (conv: Conversation) => {
      setConversations(prev => {
        if (prev.some(c => c.id === conv.id)) return prev;
        return [conv, ...prev];
      });
      setActiveConversation(conv);
      chatSocket.joinConversation(conv.id);
    };

    const handleNewConversation = (conv: Conversation) => {
      setConversations(prev => {
        if (prev.some(c => c.id === conv.id)) return prev;
        return [conv, ...prev];
      });
    };

    const handleTypingUpdate = (data: { conversationId: number; typingUsers: TypingUser[] }) => {
      if (activeConvRef.current?.id === data.conversationId) {
        setTypingUsers(data.typingUsers || []);
      }
    };

    socket.on('connect', handleConnect);
    socket.on('disconnect', handleDisconnect);
    socket.on('authSuccess', handleAuthSuccess);
    socket.on('authError', handleAuthError);
    socket.on('conversationsList', handleConversationsList);
    socket.on('messageHistory', handleMessageHistory);
    socket.on('receiveMessage', handleNewMessage);
    socket.on('conversationUpdated', handleConversationUpdated);
    socket.on('conversationCreated', handleConversationCreated);
    socket.on('newConversation', handleNewConversation);
    socket.on('typingUpdate', handleTypingUpdate);

    setIsConnected(socket.connected);

    return () => {
      socket.off('connect', handleConnect);
      socket.off('disconnect', handleDisconnect);
      socket.off('authSuccess', handleAuthSuccess);
      socket.off('authError', handleAuthError);
      socket.off('conversationsList', handleConversationsList);
      socket.off('messageHistory', handleMessageHistory);
      socket.off('receiveMessage', handleNewMessage);
      socket.off('conversationUpdated', handleConversationUpdated);
      socket.off('conversationCreated', handleConversationCreated);
      socket.off('newConversation', handleNewConversation);
      socket.off('typingUpdate', handleTypingUpdate);
      chatSocket.disconnect();
    };
  }, [user]);

  // Select a conversation: join room, load messages
  const selectConversation = useCallback((conversation: Conversation | null) => {
    // Leave previous room
    if (activeConversation) {
      chatSocket.leaveConversation(activeConversation.id);
    }

    setActiveConversation(conversation);
    setMessages([]);
    setTypingUsers([]);

    if (conversation) {
      chatSocket.joinConversation(conversation.id);
      chatSocket.markRead(conversation.id);

      // Clear unread for this conversation locally
      setConversations(prev => prev.map(c =>
        c.id === conversation.id ? { ...c, unreadCount: 0 } : c
      ));
    }
  }, [activeConversation]);

  // Send a message to the active conversation
  const sendMessage = useCallback((text: string) => {
    if (!text.trim() || !activeConversation || !isConnected) return;
    chatSocket.sendMessage(activeConversation.id, text.trim());
  }, [activeConversation, isConnected]);

  // Create a new conversation
  const createConversation = useCallback((participantIds: number[]) => {
    if (!canCreate || participantIds.length === 0) return;
    chatSocket.createConversation(participantIds);
  }, [canCreate]);

  // Handle typing indicator
  const handleTyping = useCallback((isTyping: boolean) => {
    if (!activeConversation || !user) return;
    chatSocket.sendTyping(activeConversation.id, user.name || user.email, isTyping);
  }, [activeConversation, user]);

  return {
    conversations,
    activeConversation,
    messages,
    typingUsers,
    isConnected,
    loading,
    chatUser,
    canCreate: !!canCreate,
    totalUnreadCount,
    selectConversation,
    sendMessage,
    createConversation,
    handleTyping,
  };
}

export default useChat;

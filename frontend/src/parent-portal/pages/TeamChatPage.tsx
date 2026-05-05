import React, { useEffect, useRef } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useChatContext } from '../contexts/ChatContext';
import ConversationList from '../../components/chat/ConversationList';
import { ParentHeader } from '../components/ParentHeader';

export const TeamChatPage: React.FC = () => {
  const { user } = useAuth();
  const {
    conversations,
    activeConversation,
    messages,
    typingUsers,
    isConnected,
    loading,
    selectConversation,
    sendMessage,
    handleTyping,
  } = useChatContext();

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [messageText, setMessageText] = React.useState('');
  const [isTyping, setIsTyping] = React.useState(false);
  const typingTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  // Scroll to bottom when messages change
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const handleSend = () => {
    if (!messageText.trim()) return;
    sendMessage(messageText.trim());
    setMessageText('');
    inputRef.current?.focus();

    // Stop typing indicator
    if (typingTimeoutRef.current) {
      clearTimeout(typingTimeoutRef.current);
    }
    setIsTyping(false);
    handleTyping(false);
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setMessageText(e.target.value);
    if (e.target.value.length > 0 && !isTyping) {
      setIsTyping(true);
      handleTyping(true);
    }
    if (typingTimeoutRef.current) {
      clearTimeout(typingTimeoutRef.current);
    }
    typingTimeoutRef.current = setTimeout(() => {
      setIsTyping(false);
      handleTyping(false);
    }, 3000);
  };

  useEffect(() => {
    return () => {
      if (typingTimeoutRef.current) clearTimeout(typingTimeoutRef.current);
    };
  }, []);

  const handleBack = () => {
    selectConversation(null);
  };

  const formatTime = (dateStr: string) => {
    const date = new Date(dateStr);
    const now = new Date();
    const isToday = date.toDateString() === now.toDateString();
    if (isToday) {
      return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    return date.toLocaleDateString('en-US', {
      month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
    });
  };

  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  // Filter out current user from typing users
  const otherTypingUsers = typingUsers.filter(
    t => t.username !== user?.name && t.username !== user?.email
  );

  // No conversations and loading done
  if (!loading && conversations.length === 0) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Messages" showBack />
        <div className="pt-14 flex items-center justify-center py-12">
          <div className="text-center px-4">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">No Messages</h3>
            <p className="mt-1 text-sm text-gray-500">
              You'll see messages here when a coach starts a conversation.
            </p>
          </div>
        </div>
      </div>
    );
  }

  // Chat view: showing messages for a selected conversation
  if (activeConversation) {
    return (
      <div className="min-h-screen bg-gray-50 flex flex-col">
        <ParentHeader
          title={activeConversation.displayName}
          showBack
          onBack={handleBack}
        />

        {/* Messages Container */}
        <div
          className="flex-1 overflow-y-auto px-4 py-4"
          style={{ paddingTop: '72px', paddingBottom: 'calc(5rem + 4rem + var(--safe-area-inset-bottom, 0px))' }}
        >
          {messages.length === 0 && (
            <div className="text-center py-12">
              <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
              </svg>
              <h3 className="mt-2 text-lg font-medium text-brand-primary">No Messages Yet</h3>
              <p className="mt-1 text-sm text-gray-500">Be the first to send a message!</p>
            </div>
          )}

          {messages.length > 0 && (
            <div className="space-y-4">
              {messages.map((message) => {
                const isOwnMessage = message.senderId === user?.id;

                return (
                  <div
                    key={message.id}
                    className={`flex gap-3 ${isOwnMessage ? 'flex-row-reverse' : ''}`}
                  >
                    {!isOwnMessage && (
                      <div className="w-8 h-8 rounded-full bg-brand-primary text-white flex items-center justify-center text-xs font-medium flex-shrink-0">
                        {getInitials(message.sender)}
                      </div>
                    )}
                    <div className={`max-w-[75%] ${isOwnMessage ? 'items-end' : 'items-start'}`}>
                      {!isOwnMessage && (
                        <div className="flex items-center gap-1.5 mb-1">
                          <p className="text-xs text-gray-500">{message.sender}</p>
                          {(message.role === 'coach' || message.role === 'club_admin') && (
                            <span className="text-xs bg-brand-secondary text-brand-primary px-1 rounded">
                              {message.role === 'coach' ? 'Coach' : 'Admin'}
                            </span>
                          )}
                        </div>
                      )}
                      <div
                        className={`px-4 py-2 rounded-2xl ${
                          isOwnMessage
                            ? 'bg-brand-primary text-white rounded-br-md'
                            : 'bg-white border border-gray-200 rounded-bl-md'
                        }`}
                      >
                        <p className="text-sm whitespace-pre-wrap break-words">{message.text}</p>
                      </div>
                      <p className={`text-xs text-gray-400 mt-1 ${isOwnMessage ? 'text-right' : ''}`}>
                        {formatTime(message.timestamp)}
                      </p>
                    </div>
                  </div>
                );
              })}

              {/* Typing indicator */}
              {otherTypingUsers.length > 0 && (
                <div className="flex items-center gap-2 text-gray-500 text-sm">
                  <div className="flex space-x-1">
                    <span className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
                    <span className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
                    <span className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
                  </div>
                  <span>
                    {otherTypingUsers.length === 1
                      ? `${otherTypingUsers[0].username} is typing...`
                      : `${otherTypingUsers.length} people are typing...`}
                  </span>
                </div>
              )}

              <div ref={messagesEndRef} />
            </div>
          )}
        </div>

        {/* Message Input - positioned above bottom nav */}
        <div
          className="fixed left-0 right-0 bg-white border-t border-gray-200 p-4 z-40"
          style={{ bottom: 'calc(4rem + var(--safe-area-inset-bottom, 0px))' }}
        >
          <div className="max-w-lg mx-auto">
            <div className="flex gap-2">
              <input
                ref={inputRef}
                type="text"
                value={messageText}
                onChange={handleInputChange}
                onKeyPress={handleKeyPress}
                placeholder="Type a message..."
                className="flex-1 px-4 py-2 border border-gray-300 rounded-full focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                disabled={!isConnected}
              />
              <button
                onClick={handleSend}
                disabled={!messageText.trim() || !isConnected}
                className="w-10 h-10 bg-brand-primary text-white rounded-full flex items-center justify-center hover:bg-brand-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // List view: show all conversations
  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <ParentHeader title="Messages" showBack />

      <div className="pt-14 flex-1 flex flex-col" style={{ paddingBottom: '80px' }}>
        {!isConnected && !loading && (
          <div className="px-4 py-2 bg-yellow-50 border-b border-yellow-200 text-center">
            <span className="text-xs text-yellow-700">Connecting to chat...</span>
          </div>
        )}

        <ConversationList
          conversations={conversations}
          onSelect={(conv) => selectConversation(conv)}
          loading={loading}
        />
      </div>
    </div>
  );
};

export default TeamChatPage;

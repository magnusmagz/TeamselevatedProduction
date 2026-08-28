import React, { useEffect, useRef } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useChatContext } from '../contexts/ChatContext';
import ConversationList from '../../components/chat/ConversationList';
import NewConversationDialog from '../../components/chat/NewConversationDialog';
import ReportMessageButton from '../../components/chat/ReportMessageButton';
import { ParentHeader } from '../components/ParentHeader';
import { sameUser } from '../../components/chat/sameUser';
import { reportNotificationClick } from '../../components/chat/reportNotificationClick';
import MessageReactions from '../../components/chat/MessageReactions';
import PollMessage from '../../components/chat/PollMessage';

export const TeamChatPage: React.FC = () => {
  const { user } = useAuth();
  const {
    conversations,
    archivedConversations,
    activeConversation,
    messages,
    typingUsers,
    isConnected,
    loading,
    canCreate,
    showArchived,
    selectConversation,
    sendMessage,
    toggleReaction,
    votePoll,
    createConversation,
    handleTyping,
    reportedMessageIds,
    reportMessage,
    archiveConversation,
    unarchiveConversation,
    setShowArchived,
  } = useChatContext();

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const scrollContainerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [messageText, setMessageText] = React.useState('');
  const [isTyping, setIsTyping] = React.useState(false);
  const [showNewConversation, setShowNewConversation] = React.useState(false);
  const typingTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  const handleCreateConversation = (participantIds: number[]) => {
    createConversation(participantIds);
    setShowNewConversation(false);
  };

  const scrollToBottom = (behavior: ScrollBehavior) => {
    const container = scrollContainerRef.current;
    if (container) {
      container.scrollTo({ top: container.scrollHeight, behavior });
    }
  };

  // Jump INSTANTLY to the newest message when a conversation is opened, so it
  // never loads with the latest messages out of view. Keyed on the active
  // conversation id (not the messages array) so it only fires on open/switch.
  const activeConversationId = activeConversation?.id;
  useEffect(() => {
    if (activeConversationId == null) return;
    // Run after layout so scrollHeight reflects the rendered messages.
    requestAnimationFrame(() => scrollToBottom('auto'));
  }, [activeConversationId]);

  // SMOOTHLY follow new messages that arrive while the conversation is open.
  useEffect(() => {
    if (activeConversationId == null) return;
    scrollToBottom('smooth');
  }, [messages, activeConversationId]);

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

  /**
   * Open the conversation a push notification pointed at (`?chat=<id>`).
   *
   * Same reason as the staff widget: landing on the chat list when you were just
   * told about a specific message means going to find it yourself. Keyed on
   * `conversations` because the list loads asynchronously; the ref makes it fire
   * once so pressing Back does not immediately reopen it.
   */
  const openedFromNotification = useRef(false);
  useEffect(() => {
    if (openedFromNotification.current) return;

    const wanted = new URLSearchParams(window.location.search).get('chat');
    if (!wanted) return;

    const conv = conversations.find((c) => String(c.id) === wanted);
    if (!conv) return;

    openedFromNotification.current = true;
    selectConversation(conv);

    reportNotificationClick(conv.id, new URLSearchParams(window.location.search).get('tec'));

    const url = new URL(window.location.href);
    ['chat', 'tec', 'utm_source', 'utm_medium', 'utm_campaign'].forEach((p) => url.searchParams.delete(p));
    window.history.replaceState({}, '', url.toString());
  }, [conversations, selectConversation]);

  /**
   * Same, for a notification clicked while the portal is already open. The
   * service worker posts OPEN_CHAT rather than navigating, because
   * client.navigate() rejects on windows it does not control.
   */
  useEffect(() => {
    if (!('serviceWorker' in navigator)) return;

    const onMessage = (event: MessageEvent) => {
      if (!event.data || event.data.type !== 'OPEN_CHAT') return;

      let wanted: string | null = null;
      try {
        wanted = new URL(event.data.url, window.location.origin).searchParams.get('chat');
      } catch {
        return;
      }
      if (!wanted) return;

      const conv = conversations.find((c) => String(c.id) === wanted);
      if (conv) selectConversation(conv);
    };

    navigator.serviceWorker.addEventListener('message', onMessage);
    return () => navigator.serviceWorker.removeEventListener('message', onMessage);
  }, [conversations, selectConversation]);

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
        <div
          className="flex items-center justify-center py-12"
          style={{ paddingTop: 'calc(3.5rem + var(--safe-area-inset-top, 0px) + 2rem)' }}
        >
          <div className="text-center px-4">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">No Messages</h3>
            <p className="mt-1 text-sm text-gray-500">
              {canCreate ? "Start a conversation with one of your athletes' coaches." : "You'll see messages here when a coach starts a conversation."}
            </p>
            {canCreate && (
              <button
                onClick={() => setShowNewConversation(true)}
                className="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-brand-primary text-white rounded-full text-sm font-medium hover:bg-brand-primary/90"
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                </svg>
                New Message
              </button>
            )}
          </div>
        </div>
        {showNewConversation && (
          <div className="fixed inset-0 bg-white z-[60]">
            <NewConversationDialog
              onClose={() => setShowNewConversation(false)}
              onCreate={handleCreateConversation}
            />
          </div>
        )}
      </div>
    );
  }

  // Chat view: showing messages for a selected conversation
  if (activeConversation) {
    // Header is fixed (h-14 = 3.5rem) + top safe area; input bar + bottom nav
    // sit at the bottom. The scroll region is bounded between them so ONLY the
    // message list scrolls internally — the page itself never scrolls under the
    // fixed header (which previously hid the first message / empty state).
    const headerOffset = 'calc(3.5rem + var(--safe-area-inset-top, 0px))';
    const footerOffset = 'calc(4rem + 4.5rem + var(--safe-area-inset-bottom, 0px))';
    return (
      <div className="fixed inset-0 bg-gray-50 overflow-hidden">
        <ParentHeader
          title={activeConversation.displayName}
          showBack
          onBack={handleBack}
        />

        {/* Messages Container — bounded internal scroll between header and input */}
        <div
          ref={scrollContainerRef}
          className="absolute left-0 right-0 overflow-y-auto px-4 py-4"
          style={{ top: headerOffset, bottom: footerOffset }}
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
                const isOwnMessage = sameUser(message.senderId, user?.id);

                // Removed by an admin: a tombstone, not a gap. The server nulls
                // the text, so without this branch crew would see a blank bubble.
                if (message.removed) {
                  return (
                    <div key={message.id} className="flex justify-center">
                      <div className="max-w-[80%] px-3 py-1.5 rounded-full bg-gray-100 border border-gray-200">
                        <p className="text-xs italic text-gray-500 text-center">
                          Message removed by an administrator
                        </p>
                      </div>
                    </div>
                  );
                }

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
                        } ${message.pending ? 'opacity-60' : ''}`}
                      >
                        {message.poll ? (
                          <PollMessage
                            poll={message.poll}
                            onVote={votePoll}
                            onDark={isOwnMessage}
                          />
                        ) : (
                          <p className="text-sm whitespace-pre-wrap break-words">{message.text}</p>
                        )}
                      </div>
                      {!message.removed && (
                        <MessageReactions
                          reactions={message.reactions}
                          currentUserId={user?.id}
                          align={isOwnMessage ? 'right' : 'left'}
                          onToggle={(emoji) => toggleReaction(String(message.id), emoji)}
                        />
                      )}
                      <div className={`flex items-center gap-1 ${isOwnMessage ? 'justify-end' : ''}`}>
                        <p className={`text-xs mt-1 ${message.failed ? 'text-red-500' : 'text-gray-400'}`}>
                          {message.failed
                            ? 'Not delivered'
                            : message.pending
                            ? 'Sending…'
                            : formatTime(message.timestamp)}
                        </p>
                        {/* Reporting is offered on other people's messages only. */}
                        {!isOwnMessage && (
                          <ReportMessageButton
                            messageId={message.id}
                            reported={reportedMessageIds.includes(message.id)}
                            onReport={reportMessage}
                          />
                        )}
                      </div>
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
              />
              <button
                onClick={handleSend}
                disabled={!messageText.trim()}
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
          conversations={showArchived ? archivedConversations : conversations}
          onSelect={(conv) => selectConversation(conv)}
          loading={loading}
          onArchive={archiveConversation}
          onUnarchive={unarchiveConversation}
          showArchived={showArchived}
          onToggleArchived={setShowArchived}
        />
      </div>

      {canCreate && (
        <button
          onClick={() => setShowNewConversation(true)}
          aria-label="New message"
          className="fixed right-4 z-40 w-14 h-14 bg-brand-primary text-white rounded-full shadow-lg flex items-center justify-center hover:bg-brand-primary/90"
          style={{ bottom: 'calc(5rem + var(--safe-area-inset-bottom, 0px))' }}
        >
          <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
        </button>
      )}

      {showNewConversation && (
        <div className="fixed inset-0 bg-white z-[60]">
          <NewConversationDialog
            onClose={() => setShowNewConversation(false)}
            onCreate={handleCreateConversation}
          />
        </div>
      )}
    </div>
  );
};

export default TeamChatPage;

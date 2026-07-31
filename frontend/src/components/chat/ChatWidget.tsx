import React, { useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useChat } from './useChat';
import ConversationList from './ConversationList';
import NewConversationDialog from './NewConversationDialog';
import ChatMessageList from './ChatMessageList';
import ChatInput from './ChatInput';

type View = 'list' | 'chat' | 'new';

export default function ChatWidget() {
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
    totalUnreadCount,
    showArchived,
    selectConversation,
    sendMessage,
    createConversation,
    handleTyping,
    reportedMessageIds,
    reportMessage,
    archiveConversation,
    unarchiveConversation,
    setShowArchived,
  } = useChat();

  const [isExpanded, setIsExpanded] = useState(false);
  const [view, setView] = useState<View>('list');

  const handleSelectConversation = (conv: typeof conversations[0]) => {
    selectConversation(conv);
    setView('chat');
  };

  const handleBack = () => {
    selectConversation(null);
    setView('list');
  };

  const handleNewConversation = () => {
    setView('new');
  };

  const handleCreateConversation = (participantIds: number[]) => {
    createConversation(participantIds);
    setView('chat');
  };

  // Adapt messages to ChatMessageList format
  const adaptedMessages = messages.map(msg => ({
    id: msg.id,
    text: msg.text,
    sender: msg.sender,
    senderId: msg.senderId as string | number,
    timestamp: msg.timestamp,
    time: msg.time,
    channel: 'general',
    role: msg.role,
    // Without this a removed message arrives with empty text and no flag, and
    // renders as a blank bubble instead of a tombstone.
    removed: msg.removed,
  }));

  if (!user) return null;

  return (
    <div className="fixed bottom-6 right-6 z-50 max-sm:bottom-0 max-sm:right-0 max-sm:left-0 max-sm:top-0 max-sm:pointer-events-none">
      {/* Collapsed state - Chat bubble */}
      {!isExpanded && (
        <button
          onClick={() => setIsExpanded(true)}
          className="w-14 h-14 bg-brand-primary hover:bg-brand-primary text-white rounded-full shadow-lg flex items-center justify-center transition-all duration-200 hover:scale-105 max-sm:pointer-events-auto max-sm:fixed max-sm:bottom-6 max-sm:right-6"
          aria-label="Open chat"
        >
          <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>

          {totalUnreadCount > 0 && (
            <span className="absolute -top-1 -right-1 bg-brand-secondary text-brand-primary text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center border border-brand-primary">
              {totalUnreadCount > 9 ? '9+' : totalUnreadCount}
            </span>
          )}
        </button>
      )}

      {/* Expanded state - Chat panel (full-screen on mobile, floating on desktop) */}
      {isExpanded && (
        <div className="w-96 h-[32rem] bg-white rounded-lg shadow-2xl border border-brand-secondary flex flex-col overflow-hidden max-sm:pointer-events-auto max-sm:fixed max-sm:inset-0 max-sm:w-full max-sm:h-full max-sm:rounded-none max-sm:border-0">
          {/* List View */}
          {view === 'list' && (
            <>
              {/* Header */}
              <div className="bg-brand-primary text-white px-4 py-3 flex items-center justify-between flex-shrink-0">
                <div className="flex items-center gap-2">
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                  </svg>
                  <span className="font-medium">Messages</span>
                  <div className={`w-2 h-2 rounded-full ${isConnected ? 'bg-green-400' : 'bg-red-400'}`} />
                </div>
                <button
                  onClick={() => setIsExpanded(false)}
                  className="w-11 h-11 flex items-center justify-center hover:bg-white/20 rounded transition-colors"
                  aria-label="Close chat"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
              </div>

              <ConversationList
                conversations={showArchived ? archivedConversations : conversations}
                onSelect={handleSelectConversation}
                onNewConversation={canCreate ? handleNewConversation : undefined}
                loading={loading}
                onArchive={archiveConversation}
                onUnarchive={unarchiveConversation}
                showArchived={showArchived}
                onToggleArchived={setShowArchived}
              />
            </>
          )}

          {/* Chat View */}
          {view === 'chat' && activeConversation && (
            <>
              {/* Header with back arrow */}
              <div className="bg-brand-primary text-white px-4 py-3 flex items-center gap-2 flex-shrink-0">
                <button
                  onClick={handleBack}
                  className="p-1 hover:bg-white/20 rounded transition-colors"
                  aria-label="Back to conversations"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                  </svg>
                </button>
                <div className="flex-1 min-w-0">
                  <span className="font-medium truncate block">{activeConversation.displayName}</span>
                  <span className="text-xs text-white/70 capitalize">{activeConversation.type} chat</span>
                </div>
                <div className={`w-2 h-2 rounded-full ${isConnected ? 'bg-green-400' : 'bg-red-400'}`} />
              </div>

              {/* Messages */}
              <ChatMessageList
                messages={adaptedMessages}
                currentUser={user}
                typingUsers={typingUsers}
                onReport={reportMessage}
                reportedMessageIds={reportedMessageIds}
              />

              {/* Input */}
              <ChatInput
                onSend={sendMessage}
                onTyping={handleTyping}
                disabled={!isConnected}
                placeholder={`Message ${activeConversation.displayName}...`}
              />
            </>
          )}

          {/* New Conversation View */}
          {view === 'new' && (
            <NewConversationDialog
              onClose={() => setView('list')}
              onCreate={handleCreateConversation}
            />
          )}
        </div>
      )}
    </div>
  );
}

import React, { useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useChat } from './useChat';
import { reportNotificationClick } from './reportNotificationClick';
import ConversationList from './ConversationList';
import NewConversationDialog from './NewConversationDialog';
import ChatMessageList from './ChatMessageList';
import ChatInput from './ChatInput';
import PollComposer from './PollComposer';
import { canCreatePoll, canPinMessage } from './pollTypes';
import PinnedBanner from './PinnedBanner';

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
    chatUser,
    toggleReaction,
    votePoll,
    createPoll,
    pinnedMessage,
    pinMessage,
    unpinMessage,
    chatError,
    clearChatError,
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
  const [pollComposerOpen, setPollComposerOpen] = useState(false);
  const openedFromNotification = React.useRef(false);

  /**
   * Open the conversation a push notification pointed at.
   *
   * Staff chat is a widget rather than a route, so there is no URL that opens
   * it — tapping a notification used to land you on the dashboard with the chat
   * still closed, which is barely better than no link at all. The dispatcher
   * therefore sends `/dashboard?chat=<id>` and this reads it.
   *
   * Runs on `conversations` because the list loads asynchronously: on the first
   * pass it is usually empty, and the effect re-runs once it arrives. The ref
   * makes it fire once, so closing the widget does not immediately reopen it.
   */
  React.useEffect(() => {
    if (openedFromNotification.current) return;

    const wanted = new URLSearchParams(window.location.search).get('chat');
    if (!wanted) return;

    const conv = conversations.find((c) => String(c.id) === wanted);
    if (!conv) return;

    openedFromNotification.current = true;
    selectConversation(conv);
    setView('chat');
    setIsExpanded(true);

    // Tell the server the notification worked. Chat notifications carry no
    // tracking pixel by design, so this is the only signal that one brought
    // someone back — and it covers push, which a pixel never could.
    reportNotificationClick(conv.id, new URLSearchParams(window.location.search).get('tec'));

    // Drop the parameters so a later refresh does not reopen the chat or record
    // a second click, and so the URL the person can copy is the page they are
    // actually on.
    const url = new URL(window.location.href);
    ['chat', 'tec', 'utm_source', 'utm_medium', 'utm_campaign'].forEach((p) => url.searchParams.delete(p));
    window.history.replaceState({}, '', url.toString());
  }, [conversations, selectConversation]);

  /**
   * Open the chat when a notification is CLICKED while the app is already open.
   *
   * The service worker focuses the window and posts OPEN_CHAT rather than
   * navigating it: client.navigate() only works on windows the worker actually
   * controls and rejects silently otherwise, which is why clicking a
   * notification appeared to do nothing (2026-08-26).
   *
   * Not merged with the `?chat=` effect above — that one handles a cold start
   * where the app loads with the parameter already in the URL. This one handles
   * a live tab, which never reloads and so never re-reads the URL.
   */
  React.useEffect(() => {
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
      if (!conv) return;

      selectConversation(conv);
      setView('chat');
      setIsExpanded(true);
    };

    navigator.serviceWorker.addEventListener('message', onMessage);
    return () => navigator.serviceWorker.removeEventListener('message', onMessage);
  }, [conversations, selectConversation]);

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
    // ⚠️ And this. Reactions updated in state and then vanished on the way to
    // the list, so clicking an emoji appeared to do nothing — the row was in
    // the database the whole time. Reported 2026-08-28.
    //
    // This adapter names every field it passes, so ANY new field on a message is
    // silently dropped here by default. That is the second time: the comment
    // above records the same thing happening to `removed`. If a third arrives,
    // spread the message instead of listing fields.
    reactions: msg.reactions,
    messageType: msg.messageType,
    poll: msg.poll,
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
              <PinnedBanner
                pinned={pinnedMessage}
                canPin={canPinMessage(chatUser?.role)}
                onUnpin={unpinMessage}
              />

              <ChatMessageList
                messages={adaptedMessages}
                currentUser={user}
                typingUsers={typingUsers}
                onReport={reportMessage}
                reportedMessageIds={reportedMessageIds}
                onToggleReaction={toggleReaction}
                onVotePoll={votePoll}
                onPin={canPinMessage(chatUser?.role) ? pinMessage : undefined}
                pinnedMessageId={pinnedMessage?.messageId}
              />

              {/* Whatever the server last refused. Without this a rejected
                  action does nothing at all and the reason sits in a log only
                  we can read — which is exactly how "Post poll" appeared to be
                  broken. */}
              {chatError && (
                <div className="mx-3 mb-2 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-800 flex items-start justify-between gap-2" role="alert">
                  <span>{chatError}</span>
                  <button type="button" onClick={clearChatError} aria-label="Dismiss" className="text-red-600">✕</button>
                </div>
              )}

              {/* Poll composer, or the normal input. Only coaches and club
                  admins can create one — the server enforces it too, since a
                  hidden button is not an access control. */}
              {pollComposerOpen ? (
                <PollComposer
                  onCancel={() => setPollComposerOpen(false)}
                  onCreate={(input) => { createPoll(input); setPollComposerOpen(false); }}
                />
              ) : (
                <>
                  {canCreatePoll(chatUser?.role) && (
                    <div className="px-3 pt-2 pb-2">
                      <button
                        type="button"
                        onClick={() => setPollComposerOpen(true)}
                        className="text-xs font-medium text-brand-primary hover:underline"
                      >
                        + Create a poll
                      </button>
                    </div>
                  )}
                  <ChatInput
                    onSend={sendMessage}
                    onTyping={handleTyping}
                    disabled={!isConnected}
                    placeholder={`Message ${activeConversation.displayName}...`}
                  />
                </>
              )}
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

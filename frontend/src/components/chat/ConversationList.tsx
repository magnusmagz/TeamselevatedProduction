import { formatMessageTime } from './messageTime';
import React from 'react';
import type { Conversation } from './types';
import Button from '../ui/Button';

interface Props {
  conversations: Conversation[];
  activeConversationId?: number;
  onSelect: (conversation: Conversation) => void;
  onNewConversation?: () => void;
  loading?: boolean;
  /**
   * Archive hides a conversation from this user's list only. It is deliberately
   * not a delete — nothing is removed, nobody else is affected, and the next
   * message brings it back. Labelling it anything stronger would promise an
   * erasure the system does not perform.
   */
  onArchive?: (conversationId: number) => void;
  onUnarchive?: (conversationId: number) => void;
  showArchived?: boolean;
  onToggleArchived?: (show: boolean) => void;
}

function getTypeIcon(type: Conversation['type']) {
  if (type === 'team') {
    return (
      <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
    );
  }
  if (type === 'direct') {
    return (
      <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
      </svg>
    );
  }
  // group
  return (
    <svg className="w-5 h-5 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
    </svg>
  );
}


function ArchiveIcon({ restore }: { restore?: boolean }) {
  return (
    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth={2}
        d={
          restore
            ? 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'
            : 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4'
        }
      />
    </svg>
  );
}

export default function ConversationList({
  conversations,
  activeConversationId,
  onSelect,
  onNewConversation,
  loading,
  onArchive,
  onUnarchive,
  showArchived = false,
  onToggleArchived,
}: Props) {
  if (loading) {
    return (
      <div className="flex-1 flex items-center justify-center py-8">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary" />
      </div>
    );
  }

  return (
    <div className="flex-1 overflow-y-auto">
      {/* Archived view header / entry point */}
      {onToggleArchived && (
        showArchived ? (
          <button
            onClick={() => onToggleArchived(false)}
            className="w-full px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 hover:bg-gray-50 transition-colors text-left"
          >
            <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            <span className="text-sm font-medium text-gray-600">Back to chats</span>
          </button>
        ) : (
          <button
            onClick={() => onToggleArchived(true)}
            className="w-full px-4 py-2.5 flex items-center gap-2 border-b border-gray-100 hover:bg-gray-50 transition-colors text-left"
          >
            <span className="text-gray-400"><ArchiveIcon /></span>
            <span className="text-sm font-medium text-gray-600">Archived</span>
          </button>
        )
      )}

      {/* New Message button (only for coaches/admins) */}
      {!showArchived && onNewConversation && (
        <button
          onClick={onNewConversation}
          className="w-full px-4 py-3 flex items-center gap-3 bg-brand-secondary hover:bg-brand-secondary/80 border-b border-brand-secondary transition-colors"
        >
          <div className="w-8 h-8 rounded-full bg-brand-primary text-white flex items-center justify-center">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
          </div>
          <span className="text-sm font-medium text-brand-primary">New Message</span>
        </button>
      )}

      {conversations.length === 0 && (
        <div className="text-center text-gray-400 text-sm py-8 px-4">
          {showArchived ? 'No archived chats.' : 'No conversations yet.'}
        </div>
      )}

      {conversations.map((conv) => {
        const isActive = activeConversationId === conv.id;
        // The row is a <div> wrapping two sibling buttons, not one <button>: the
        // archive control cannot be nested inside the select button.
        const action = showArchived ? onUnarchive : onArchive;

        return (
          <div
            key={conv.id}
            className={`group flex items-center border-b border-gray-100 transition-colors hover:bg-gray-50 ${
              isActive ? 'bg-brand-secondary/50' : ''
            }`}
          >
            <button
              onClick={() => onSelect(conv)}
              className="flex-1 min-w-0 px-4 py-3 flex items-center gap-3 text-left"
            >
              {/* Type icon */}
              <div className="flex-shrink-0">
                {getTypeIcon(conv.type)}
              </div>

              {/* Content */}
              <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between">
                  <span className={`text-sm truncate ${conv.unreadCount > 0 ? 'font-semibold text-gray-900' : 'font-medium text-gray-700'}`}>
                    {conv.displayName}
                  </span>
                  {conv.lastMessage?.timestamp && (
                    <span className="text-xs text-gray-400 flex-shrink-0 ml-2">
                      {formatMessageTime(conv.lastMessage.timestamp)}
                    </span>
                  )}
                </div>

                {conv.lastMessage && (
                  <p className={`text-xs truncate mt-0.5 ${conv.unreadCount > 0 ? 'text-gray-700 font-medium' : 'text-gray-400'}`}>
                    {conv.lastMessage.senderName}: {conv.lastMessage.text}
                  </p>
                )}
              </div>

              {/* Unread badge */}
              {conv.unreadCount > 0 && (
                <span className="flex-shrink-0 bg-brand-primary text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
                  {conv.unreadCount > 9 ? '9+' : conv.unreadCount}
                </span>
              )}
            </button>

            {/* Archive / restore. Always rendered rather than hover-revealed —
                this is a PWA and touch devices have no hover state. */}
            {action && (
              <Button
                variant="ghost"
                size="icon"
                onClick={() => action(conv.id)}
                aria-label={
                  showArchived
                    ? `Restore ${conv.displayName} to your chats`
                    : `Archive ${conv.displayName}`
                }
                title={
                  showArchived
                    ? 'Restore to your chats'
                    : 'Hide from your chats. Nothing is deleted, and a new message brings it back.'
                }
                className="flex-shrink-0 mr-1"
              >
                <ArchiveIcon restore={showArchived} />
              </Button>
            )}
          </div>
        );
      })}
    </div>
  );
}

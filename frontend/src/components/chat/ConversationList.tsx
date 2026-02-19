import React from 'react';
import type { Conversation } from './types';

interface Props {
  conversations: Conversation[];
  activeConversationId?: number;
  onSelect: (conversation: Conversation) => void;
  onNewConversation?: () => void;
  loading?: boolean;
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

function formatTime(timestamp: string) {
  try {
    const date = new Date(timestamp);
    const now = new Date();
    const isToday = date.toDateString() === now.toDateString();

    if (isToday) {
      return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
      });
    }

    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
      return 'Yesterday';
    }

    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  } catch {
    return '';
  }
}

export default function ConversationList({
  conversations,
  activeConversationId,
  onSelect,
  onNewConversation,
  loading,
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
      {/* New Message button (only for coaches/admins) */}
      {onNewConversation && (
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
          No conversations yet.
        </div>
      )}

      {conversations.map((conv) => {
        const isActive = activeConversationId === conv.id;

        return (
          <button
            key={conv.id}
            onClick={() => onSelect(conv)}
            className={`w-full px-4 py-3 flex items-center gap-3 border-b border-gray-100 hover:bg-gray-50 transition-colors text-left ${
              isActive ? 'bg-brand-secondary/50' : ''
            }`}
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
                    {formatTime(conv.lastMessage.timestamp)}
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
        );
      })}
    </div>
  );
}

import React, { useEffect, useRef } from 'react';

interface Message {
  id: string;
  text: string;
  sender: string;
  senderId: string | number;
  timestamp: string;
  time?: string;
  channel: string;
  role?: string;
}

interface TypingUser {
  username: string;
  timestamp: number;
}

interface User {
  id: number;
  name?: string;
  email: string;
}

interface Props {
  messages: Message[];
  currentUser: User;
  typingUsers: TypingUser[];
}

export default function ChatMessageList({ messages, currentUser, typingUsers }: Props) {
  const bottomRef = useRef<HTMLDivElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  // Auto-scroll to bottom when new messages arrive
  useEffect(() => {
    if (bottomRef.current) {
      bottomRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages]);

  const formatTime = (timestamp: string) => {
    try {
      return new Date(timestamp).toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
      });
    } catch {
      return '';
    }
  };

  const isOwnMessage = (msg: Message) => {
    return String(msg.senderId) === String(currentUser.id) ||
           msg.sender === currentUser.name ||
           msg.sender === currentUser.email;
  };

  // Filter out current user from typing users
  const otherTypingUsers = typingUsers.filter(
    u => u.username !== currentUser.name && u.username !== currentUser.email
  );

  return (
    <div
      ref={containerRef}
      className="flex-1 overflow-y-auto px-4 py-3 space-y-3 bg-gray-50"
    >
      {messages.length === 0 && (
        <div className="text-center text-gray-400 text-sm py-8">
          No messages yet. Start the conversation!
        </div>
      )}

      {messages.map((msg) => {
        const isOwn = isOwnMessage(msg);

        return (
          <div
            key={msg.id}
            className={`flex ${isOwn ? 'justify-end' : 'justify-start'}`}
          >
            <div
              className={`max-w-[80%] ${
                isOwn
                  ? 'bg-brand-primary text-white rounded-lg rounded-br-sm'
                  : 'bg-white text-gray-800 rounded-lg rounded-bl-sm border border-gray-200'
              } px-3 py-2 shadow-sm`}
            >
              {/* Sender name for other users */}
              {!isOwn && (
                <div className="flex items-center gap-1.5 mb-1">
                  <span className="text-xs font-semibold text-brand-primary">
                    {msg.sender}
                  </span>
                  {(msg.role === 'coach' || msg.role === 'league_admin' || msg.role === 'club_admin') && (
                    <span className="text-xs bg-brand-secondary text-brand-primary px-1 rounded">
                      {msg.role === 'coach' ? 'Coach' : 'Admin'}
                    </span>
                  )}
                </div>
              )}

              {/* Message text */}
              <p className="text-sm leading-relaxed break-words whitespace-pre-wrap">
                {msg.text}
              </p>

              {/* Timestamp */}
              <div
                className={`text-xs mt-1 ${
                  isOwn ? 'text-brand-light' : 'text-gray-400'
                }`}
              >
                {msg.time || formatTime(msg.timestamp)}
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

      <div ref={bottomRef} />
    </div>
  );
}

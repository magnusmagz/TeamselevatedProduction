import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useParentAthletes } from '../hooks/useParentAthletes';
import { useAuth } from '../../contexts/AuthContext';
import { ParentHeader } from '../components/ParentHeader';

interface Message {
  id: number;
  user_id: number;
  user_name: string;
  content: string;
  created_at: string;
  team_id?: number;
}

interface Team {
  id: number;
  name: string;
}

export const TeamChatPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  const { athletes } = useParentAthletes();

  const [teams, setTeams] = useState<Team[]>([]);
  const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [newMessage, setNewMessage] = useState('');
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Extract unique teams from athletes
  useEffect(() => {
    const uniqueTeams: Team[] = [];
    athletes.forEach((athlete) => {
      athlete.teams?.forEach((team) => {
        if (!uniqueTeams.find((t) => t.id === team.id)) {
          uniqueTeams.push(team);
        }
      });
    });
    setTeams(uniqueTeams);
    if (uniqueTeams.length > 0 && !selectedTeamId) {
      setSelectedTeamId(uniqueTeams[0].id);
    }
  }, [athletes, selectedTeamId]);

  // Fetch messages for selected team
  const fetchMessages = useCallback(async () => {
    if (!selectedTeamId) return;

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(
        `${API_URL}/api/chat.php?action=messages&team_id=${selectedTeamId}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      const data = await response.json();

      if (data.success && data.messages) {
        setMessages(data.messages);
      }
    } catch (err) {
      console.error('Error fetching messages:', err);
    } finally {
      setLoading(false);
    }
  }, [API_URL, selectedTeamId]);

  useEffect(() => {
    setLoading(true);
    fetchMessages();

    // Poll for new messages every 5 seconds
    const interval = setInterval(fetchMessages, 5000);
    return () => clearInterval(interval);
  }, [fetchMessages]);

  // Scroll to bottom when messages change
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const handleSend = async () => {
    if (!newMessage.trim() || !selectedTeamId || !user) return;

    setSending(true);
    setError(null);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_URL}/api/chat.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          action: 'send',
          team_id: selectedTeamId,
          content: newMessage.trim(),
        }),
      });

      const data = await response.json();

      if (data.success) {
        setNewMessage('');
        fetchMessages();
        inputRef.current?.focus();
      } else {
        setError(data.error || 'Failed to send message');
      }
    } catch (err) {
      setError('Failed to send message');
    } finally {
      setSending(false);
    }
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  const formatTime = (dateStr: string) => {
    const date = new Date(dateStr);
    const now = new Date();
    const isToday = date.toDateString() === now.toDateString();

    if (isToday) {
      return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
      });
    }

    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
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

  if (teams.length === 0) {
    return (
      <div className="min-h-screen bg-gray-50">
        <ParentHeader title="Team Chat" showBack />
        <div className="pt-14 flex items-center justify-center py-12">
          <div className="text-center px-4">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-gray-900">No Teams Found</h3>
            <p className="mt-1 text-sm text-gray-500">
              Your athletes need to be assigned to teams to access chat.
            </p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <ParentHeader title="Team Chat" showBack />

      {/* Team Selector */}
      {teams.length > 1 && (
        <div className="pt-14 bg-white border-b border-gray-200 px-4 py-2">
          <div className="flex gap-2 overflow-x-auto pb-1">
            {teams.map((team) => (
              <button
                key={team.id}
                onClick={() => setSelectedTeamId(team.id)}
                className={`px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-colors ${
                  selectedTeamId === team.id
                    ? 'bg-brand-primary text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                {team.name}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Messages Container */}
      <div
        className={`flex-1 overflow-y-auto px-4 py-4 ${
          teams.length > 1 ? '' : 'pt-18'
        }`}
        style={{ paddingTop: teams.length > 1 ? '8px' : '72px', paddingBottom: '80px' }}
      >
        {loading && (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
          </div>
        )}

        {!loading && messages.length === 0 && (
          <div className="text-center py-12">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-gray-900">No Messages Yet</h3>
            <p className="mt-1 text-sm text-gray-500">Be the first to send a message!</p>
          </div>
        )}

        {!loading && messages.length > 0 && (
          <div className="space-y-4">
            {messages.map((message) => {
              const isOwnMessage = message.user_id === user?.id;

              return (
                <div
                  key={message.id}
                  className={`flex gap-3 ${isOwnMessage ? 'flex-row-reverse' : ''}`}
                >
                  {!isOwnMessage && (
                    <div className="w-8 h-8 rounded-full bg-brand-primary text-white flex items-center justify-center text-xs font-medium flex-shrink-0">
                      {getInitials(message.user_name)}
                    </div>
                  )}
                  <div
                    className={`max-w-[75%] ${isOwnMessage ? 'items-end' : 'items-start'}`}
                  >
                    {!isOwnMessage && (
                      <p className="text-xs text-gray-500 mb-1">{message.user_name}</p>
                    )}
                    <div
                      className={`px-4 py-2 rounded-2xl ${
                        isOwnMessage
                          ? 'bg-brand-primary text-white rounded-br-md'
                          : 'bg-white border border-gray-200 rounded-bl-md'
                      }`}
                    >
                      <p className="text-sm whitespace-pre-wrap break-words">
                        {message.content}
                      </p>
                    </div>
                    <p
                      className={`text-xs text-gray-400 mt-1 ${
                        isOwnMessage ? 'text-right' : ''
                      }`}
                    >
                      {formatTime(message.created_at)}
                    </p>
                  </div>
                </div>
              );
            })}
            <div ref={messagesEndRef} />
          </div>
        )}
      </div>

      {/* Message Input */}
      <div className="fixed bottom-20 left-0 right-0 bg-white border-t border-gray-200 p-4">
        <div className="max-w-lg mx-auto">
          {error && (
            <p className="text-red-600 text-sm mb-2">{error}</p>
          )}
          <div className="flex gap-2">
            <input
              ref={inputRef}
              type="text"
              value={newMessage}
              onChange={(e) => setNewMessage(e.target.value)}
              onKeyPress={handleKeyPress}
              placeholder="Type a message..."
              className="flex-1 px-4 py-2 border border-gray-300 rounded-full focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
              disabled={sending}
            />
            <button
              onClick={handleSend}
              disabled={!newMessage.trim() || sending}
              className="w-10 h-10 bg-brand-primary text-white rounded-full flex items-center justify-center hover:bg-brand-primary-hover transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default TeamChatPage;

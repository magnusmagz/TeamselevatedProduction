import React, { createContext, useContext } from 'react';
import { useChat } from '../../components/chat/useChat';

type ChatContextValue = ReturnType<typeof useChat>;

const ChatContext = createContext<ChatContextValue | null>(null);

export const ChatProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const chat = useChat();
  return <ChatContext.Provider value={chat}>{children}</ChatContext.Provider>;
};

const SAFE_DEFAULT: ChatContextValue = {
  conversations: [],
  archivedConversations: [],
  activeConversation: null,
  messages: [],
  typingUsers: [],
  isConnected: false,
  loading: false,
  chatUser: null,
  canCreate: false,
  totalUnreadCount: 0,
  showArchived: false,
  selectConversation: () => {},
  sendMessage: () => {},
  toggleReaction: () => {},
  votePoll: () => {},
  pinnedMessage: null,
  pinMessage: () => {},
  unpinMessage: () => {},
  chatError: null,
  clearChatError: () => {},
  createPoll: () => {},
  createConversation: () => {},
  handleTyping: () => {},
  reportedMessageIds: [],
  reportMessage: () => {},
  archiveConversation: () => {},
  unarchiveConversation: () => {},
  setShowArchived: () => {},
};

// Returns safe defaults if used outside a ChatProvider so that ancillary
// consumers (like the nav badge in unit tests) don't crash. Real consumers
// inside ParentPortalLayout get the live useChat values.
export function useChatContext(): ChatContextValue {
  return useContext(ChatContext) ?? SAFE_DEFAULT;
}

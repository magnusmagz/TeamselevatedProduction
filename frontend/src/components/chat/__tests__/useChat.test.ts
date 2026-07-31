import { renderHook, act } from '@testing-library/react';
import { useChat } from '../useChat';
import type { ChatMessage, Conversation } from '../types';
import * as chatSocketModule from '../chatSocket';

// ---- Mock the socket layer -------------------------------------------------
// The hook calls connectChat(token) to get a socket and attaches its event
// handlers via socket.on(...). It emits actions through the chatSocket wrapper.
//
// The mock factory is hoisted above all other declarations, so it cannot
// reference outer `const`s (they would be in the temporal dead zone at the
// moment the mocked module is first required). Instead we build EVERYTHING the
// hook touches inside the factory and hang the test handles off the returned
// module object, then read them back via the `chatSocketModule` import.
jest.mock('../chatSocket', () => {
  const registeredHandlers: Record<string, Function> = {};
  const fakeSocket: any = {
    connected: true,
    on: jest.fn((event: string, handler: Function) => {
      registeredHandlers[event] = handler;
    }),
    off: jest.fn(),
    emit: jest.fn(),
  };
  const chatSocket = {
    getSocket: () => fakeSocket,
    isConnected: () => fakeSocket.connected,
    loadConversations: jest.fn(),
    joinConversation: jest.fn(),
    leaveConversation: jest.fn(),
    sendMessage: jest.fn((): boolean => true),
    markRead: jest.fn(),
    createConversation: jest.fn(),
    sendTyping: jest.fn(),
    archiveConversation: jest.fn((): boolean => true),
    unarchiveConversation: jest.fn((): boolean => true),
    loadArchivedConversations: jest.fn(),
    disconnect: jest.fn(),
  };
  return {
    connectChat: jest.fn(() => fakeSocket),
    chatSocket,
    // Test-only handles:
    __fakeSocket: fakeSocket,
    __registeredHandlers: registeredHandlers,
  };
});

// Typed accessors into the mocked module.
const mockedModule = chatSocketModule as unknown as {
  connectChat: jest.Mock;
  chatSocket: {
    sendMessage: jest.Mock;
    joinConversation: jest.Mock;
    markRead: jest.Mock;
    leaveConversation: jest.Mock;
    archiveConversation: jest.Mock;
    unarchiveConversation: jest.Mock;
    loadArchivedConversations: jest.Mock;
  };
  __fakeSocket: { connected: boolean; on: jest.Mock };
  __registeredHandlers: Record<string, Function>;
};
const mockConnectChat = mockedModule.connectChat;
const mockChatSocket = mockedModule.chatSocket;
const mockSendMessage = mockChatSocket.sendMessage;
const mockJoinConversation = mockChatSocket.joinConversation;
const mockRegisteredHandlers = mockedModule.__registeredHandlers;
const mockFakeSocket = mockedModule.__fakeSocket;
const mockArchive = mockChatSocket.archiveConversation;
const mockUnarchive = mockChatSocket.unarchiveConversation;
const mockLoadArchived = mockChatSocket.loadArchivedConversations;

// ---- Mock the contexts the hook depends on --------------------------------
jest.mock('../../../contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 42, name: 'Test Parent', email: 'parent@example.com' },
  }),
}));

jest.mock('../../../contexts/OrgContext', () => ({
  useOrg: () => ({
    activeContext: { role: 'parent', scope_type: 'club', scope_id: 1 },
    isClubAdmin: false,
  }),
}));

const conversation: Conversation = {
  id: 7,
  type: 'team',
  participants: [],
  unreadCount: 0,
  displayName: 'U14 Mustangs',
};

function fire(event: string, payload: any) {
  const handler = mockRegisteredHandlers[event];
  if (!handler) throw new Error(`No handler registered for "${event}"`);
  act(() => handler(payload));
}

beforeEach(() => {
  // CRA's jest preset sets resetMocks:true, which wipes mock IMPLEMENTATIONS
  // before each test. Re-establish the ones whose behaviour the hook relies on.
  for (const k of Object.keys(mockRegisteredHandlers)) delete mockRegisteredHandlers[k];
  mockFakeSocket.connected = true;
  mockFakeSocket.on.mockImplementation((event: string, handler: Function) => {
    mockRegisteredHandlers[event] = handler;
  });
  mockConnectChat.mockReturnValue(mockFakeSocket);
  mockSendMessage.mockReturnValue(true);
  mockJoinConversation.mockImplementation(() => {});
  mockArchive.mockReturnValue(true);
  mockUnarchive.mockReturnValue(true);
  mockChatSocket.leaveConversation.mockImplementation(() => {});
  localStorage.setItem('auth_token', 'test-token');
});

afterEach(() => {
  localStorage.clear();
});

describe('useChat', () => {
  test('(a) sendMessage optimistically appends the message immediately', () => {
    const { result } = renderHook(() => useChat());

    act(() => result.current.selectConversation(conversation));
    expect(result.current.messages).toHaveLength(0);

    act(() => result.current.sendMessage('Hello coach'));

    // Appears immediately (PAR-28), before any server echo.
    expect(result.current.messages).toHaveLength(1);
    const msg = result.current.messages[0];
    expect(msg.text).toBe('Hello coach');
    expect(msg.senderId).toBe(42);
    expect(msg.pending).toBe(true);
    expect(msg.id).toMatch(/^temp-/);

    // It was actually emitted to the socket.
    expect(mockSendMessage).toHaveBeenCalledWith(7, 'Hello coach');
  });

  test('(b) a receiveMessage socket event appends to the active conversation', () => {
    const { result } = renderHook(() => useChat());
    act(() => result.current.selectConversation(conversation));

    const incoming: ChatMessage = {
      id: '100',
      conversationId: 7,
      text: 'Practice moved to 5pm',
      sender: 'Coach Dave',
      senderId: 99,
      timestamp: new Date().toISOString(),
      role: 'coach',
    };

    fire('receiveMessage', incoming);

    expect(result.current.messages).toHaveLength(1);
    expect(result.current.messages[0].text).toBe('Practice moved to 5pm');
    expect(result.current.messages[0].senderId).toBe(99);
  });

  test('(c) the echo of an optimistic message does NOT create a duplicate', () => {
    const { result } = renderHook(() => useChat());
    act(() => result.current.selectConversation(conversation));

    act(() => result.current.sendMessage('Are we still on for Saturday?'));
    expect(result.current.messages).toHaveLength(1);
    expect(result.current.messages[0].pending).toBe(true);

    // Server echoes the same message back with a real id (no temp id round-trip).
    const echo: ChatMessage = {
      id: '555',
      conversationId: 7,
      text: 'Are we still on for Saturday?',
      sender: 'Test Parent',
      senderId: 42,
      timestamp: new Date().toISOString(),
      role: 'parent',
    };

    fire('receiveMessage', echo);

    // Still exactly one message — the temp message was reconciled, not doubled.
    expect(result.current.messages).toHaveLength(1);
    const reconciled = result.current.messages[0];
    expect(reconciled.id).toBe('555');
    expect(reconciled.pending).toBeFalsy();

    // A second delivery of the same server id is also ignored.
    fire('receiveMessage', echo);
    expect(result.current.messages).toHaveLength(1);
  });

  test('joinConversation is called when a conversation is selected (PAR-29)', () => {
    const { result } = renderHook(() => useChat());
    act(() => result.current.selectConversation(conversation));
    expect(mockJoinConversation).toHaveBeenCalledWith(7);
  });

  test('an offline send is flagged failed, not silently dropped (PAR-28)', () => {
    mockSendMessage.mockReturnValueOnce(false); // socket offline
    const { result } = renderHook(() => useChat());
    act(() => result.current.selectConversation(conversation));

    act(() => result.current.sendMessage('Offline message'));

    expect(result.current.messages).toHaveLength(1);
    expect(result.current.messages[0].failed).toBe(true);
    expect(result.current.messages[0].pending).toBe(false);
  });

  // ---- Moderation removal -------------------------------------------------
  describe('removal', () => {
    const incoming: ChatMessage = {
      id: '100',
      conversationId: 7,
      text: 'something that had to go',
      sender: 'Coach Dave',
      senderId: 99,
      timestamp: new Date().toISOString(),
    };

    test('a removed message becomes a tombstone in place, not a gap', () => {
      const { result } = renderHook(() => useChat());
      act(() => result.current.selectConversation(conversation));
      fire('receiveMessage', incoming);
      expect(result.current.messages).toHaveLength(1);

      fire('messageRemoved', { messageId: '100', conversationId: 7 });

      // Still present — a message that silently disappears leaves people unsure
      // whether they imagined it.
      expect(result.current.messages).toHaveLength(1);
      expect(result.current.messages[0].removed).toBe(true);
    });

    test('the removed text is dropped from client state', () => {
      const { result } = renderHook(() => useChat());
      act(() => result.current.selectConversation(conversation));
      fire('receiveMessage', incoming);

      fire('messageRemoved', { messageId: '100', conversationId: 7 });

      expect(result.current.messages[0].text).toBe('');
      expect(result.current.messages[0].text).not.toContain('had to go');
    });

    test('removal matches on id even when the server sends a number', () => {
      // History delivers string ids; the socket payload carries whatever the
      // server put in it. A strict === would silently no-op.
      const { result } = renderHook(() => useChat());
      act(() => result.current.selectConversation(conversation));
      fire('receiveMessage', incoming);

      fire('messageRemoved', { messageId: 100, conversationId: 7 });

      expect(result.current.messages[0].removed).toBe(true);
    });

    test('other messages are untouched', () => {
      const { result } = renderHook(() => useChat());
      act(() => result.current.selectConversation(conversation));
      fire('receiveMessage', incoming);
      fire('receiveMessage', { ...incoming, id: '101', text: 'this one stays' });

      fire('messageRemoved', { messageId: '100', conversationId: 7 });

      expect(result.current.messages).toHaveLength(2);
      expect(result.current.messages[1].removed).toBeFalsy();
      expect(result.current.messages[1].text).toBe('this one stays');
    });
  });

  // ---- Archive ------------------------------------------------------------
  // Archive is per-user view state, never deletion. Chat has no user-facing
  // delete: a control labelled "delete" that soft-deletes would tell the user
  // their message is gone when it is not.
  describe('archive', () => {
    const other: Conversation = {
      id: 8,
      type: 'direct',
      participants: [],
      unreadCount: 0,
      displayName: 'Coach Dave',
    };

    function withConversations() {
      const hook = renderHook(() => useChat());
      fire('conversationsList', [conversation, other]);
      return hook;
    }

    test('archiving removes the conversation from the active list', () => {
      const { result } = withConversations();
      expect(result.current.conversations).toHaveLength(2);

      act(() => result.current.archiveConversation(7));

      expect(mockArchive).toHaveBeenCalledWith(7);
      expect(result.current.conversations.map(c => c.id)).toEqual([8]);
      // It moved, it did not vanish.
      expect(result.current.archivedConversations.map(c => c.id)).toEqual([7]);
    });

    test('archiving the OPEN conversation closes it', () => {
      const { result } = withConversations();
      act(() => result.current.selectConversation(conversation));
      expect(result.current.activeConversation?.id).toBe(7);

      act(() => result.current.archiveConversation(7));

      // Otherwise the user is left reading a thread that is no longer listed.
      expect(result.current.activeConversation).toBeNull();
      expect(result.current.messages).toHaveLength(0);
    });

    test('an offline archive does not hide the conversation', () => {
      // Hiding a conversation the server never heard about is a silent lie that
      // survives until the next reload.
      mockArchive.mockReturnValueOnce(false);
      const { result } = withConversations();

      act(() => result.current.archiveConversation(7));

      expect(result.current.conversations).toHaveLength(2);
      expect(result.current.archivedConversations).toHaveLength(0);
    });

    test('unarchiving restores the conversation to the active list', () => {
      const { result } = withConversations();
      act(() => result.current.archiveConversation(7));
      expect(result.current.conversations.map(c => c.id)).toEqual([8]);

      act(() => result.current.unarchiveConversation(7));
      expect(mockUnarchive).toHaveBeenCalledWith(7);

      // The server confirms with the full conversation object.
      fire('conversationUnarchived', { conversationId: 7, conversation });

      expect(result.current.conversations.map(c => c.id).sort()).toEqual([7, 8]);
      expect(result.current.archivedConversations).toHaveLength(0);
    });

    test('a new message brings an archived conversation back', () => {
      // The server un-archives for everyone who had archived it and pushes the
      // whole conversation, because conversationUpdated has nothing to patch
      // while the conversation is absent from this client's list.
      const { result } = withConversations();
      act(() => result.current.archiveConversation(7));
      expect(result.current.conversations.map(c => c.id)).toEqual([8]);

      fire('newConversation', conversation);

      expect(result.current.conversations.map(c => c.id)).toContain(7);
      expect(result.current.archivedConversations).toHaveLength(0);
    });

    test('opening the archived view requests it from the server', () => {
      const { result } = withConversations();

      act(() => result.current.setShowArchived(true));
      expect(result.current.showArchived).toBe(true);
      expect(mockLoadArchived).toHaveBeenCalled();

      fire('archivedConversationsList', [other]);
      expect(result.current.archivedConversations.map(c => c.id)).toEqual([8]);

      act(() => result.current.setShowArchived(false));
      expect(result.current.showArchived).toBe(false);
    });

    test('archiving never removes messages', () => {
      // The guard against this quietly becoming a delete.
      const { result } = withConversations();
      act(() => result.current.selectConversation(other));
      fire('receiveMessage', {
        id: '900',
        conversationId: 8,
        text: 'See you Saturday',
        sender: 'Coach Dave',
        senderId: 99,
        timestamp: new Date().toISOString(),
      } as ChatMessage);
      expect(result.current.messages).toHaveLength(1);

      act(() => result.current.archiveConversation(7));

      // A different conversation's messages are untouched, and the archived one
      // is still fully present in state — just filed elsewhere.
      expect(result.current.messages).toHaveLength(1);
      expect(result.current.archivedConversations[0].displayName).toBe('U14 Mustangs');
    });
  });
});

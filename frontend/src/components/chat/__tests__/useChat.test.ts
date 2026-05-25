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
});

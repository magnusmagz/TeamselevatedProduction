import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import TournamentCreate from '../pages/TournamentCreate';
import { useNavigate, useParams } from 'react-router-dom';

// Mock auth context
jest.mock('../../../contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 118, system_role: 'user', organization: { orgId: 47 } },
  }),
}));

jest.mock('../../../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 47, activeContext: { scope_id: 47 } }),
}));

// MarkdownEditor is a thin wrapper around TipTap. @tiptap/pm ships raw .ts that
// Jest's default transformIgnorePatterns will not transform, so importing the real
// module fails the whole suite at parse time. Nothing here exercises the editor's
// internals - TournamentCreate only needs a controlled text field for `description`
// - so it is stubbed with one. If a test is ever added for the editor itself, it
// needs its own transform config rather than this stub.
jest.mock('../components/MarkdownEditor', () => ({
  __esModule: true,
  default: ({ value, onChange }: { value: string; onChange: (md: string) => void }) => (
    <textarea
      data-testid="markdown-editor"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    />
  ),
}));

// Mock fetch. The stub is routed by URL, not by call order: VenuePicker fetches
// the venue list on mount, so an order-based mockResolvedValueOnce is consumed by
// that request and the create call silently gets `undefined` back.
const mockFetch = jest.fn();
global.fetch = mockFetch;

const CREATE_URL = 'tournament-gateway.php?action=create';

// Calls to tournament-gateway.php?action=create — i.e. actual submissions.
const createCalls = () =>
  mockFetch.mock.calls.filter((c) => String(c[0]).includes(CREATE_URL));

describe('TournamentCreate', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // Re-set useParams mock after clearAllMocks
    (useParams as jest.Mock).mockReturnValue({});
    // Default: VenuePicker's venue list resolves empty. Individual tests add the
    // create response on top.
    mockFetch.mockImplementation((url: string) => {
      if (String(url).includes('venues-gateway.php')) {
        return Promise.resolve({ ok: true, json: async () => ({ venues: [] }) });
      }
      return Promise.resolve({ ok: true, json: async () => ({}) });
    });
  });

  test('validates required fields before submission', async () => {
    render(<TournamentCreate />);

    const submitButton = screen.getByRole('button', { name: /Create Tournament/i });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText('Tournament name is required')).toBeInTheDocument();
    });
    expect(screen.getByText('Start date is required')).toBeInTheDocument();
    expect(screen.getByText('End date is required')).toBeInTheDocument();
    expect(screen.getByText('Host is required')).toBeInTheDocument();

    // No tournament was created. Asserting on the create endpoint rather than on
    // fetch as a whole: the page legitimately fetches the venue list on mount.
    expect(createCalls()).toHaveLength(0);
  });

  test('successful create navigates to detail page', async () => {
    const mockNav = jest.fn();
    (useNavigate as jest.Mock).mockReturnValue(mockNav);

    mockFetch.mockImplementation((url: string) => {
      if (String(url).includes(CREATE_URL)) {
        return Promise.resolve({
          ok: true,
          json: async () => ({ id: 5, message: 'Tournament created successfully' }),
        });
      }
      // VenuePicker's mount-time venue list.
      return Promise.resolve({ ok: true, json: async () => ({ venues: [] }) });
    });

    render(<TournamentCreate />);

    // Fill required fields
    fireEvent.change(screen.getByPlaceholderText('Spring Classic 2026'), {
      target: { value: 'Test Tournament', name: 'name' },
    });

    // Host is a required field too - validate() rejects the form without it.
    const hostInput = document.querySelector('input[name="host_name"]');
    if (hostInput) fireEvent.change(hostInput, { target: { value: 'Test Club', name: 'host_name' } });

    // Find date inputs by their name attribute
    const inputs = document.querySelectorAll('input');
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput = document.querySelector('input[name="end_date"]');

    if (startInput) fireEvent.change(startInput, { target: { value: '2026-06-01', name: 'start_date' } });
    if (endInput) fireEvent.change(endInput, { target: { value: '2026-06-03', name: 'end_date' } });

    const submitButton = screen.getByRole('button', { name: /Create Tournament/i });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(mockNav).toHaveBeenCalledWith('/tournaments/5');
    });
  });
});

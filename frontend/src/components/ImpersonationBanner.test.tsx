import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ImpersonationBanner } from './ImpersonationBanner';

jest.mock('../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));

import { useAuth } from '../contexts/AuthContext';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;

const NOW_SECONDS = 1700000000;

const authValue = (overrides: Partial<ReturnType<typeof useAuth>> = {}) => ({
  user: { id: 42, email: 'parent@example.com', name: 'Pat Parent' },
  isLoading: false,
  error: null,
  login: jest.fn(),
  updateUser: jest.fn(),
  refreshAuth: jest.fn(),
  logout: jest.fn(),
  switchContext: jest.fn(),
  hasPermission: jest.fn(),
  isSuperAdmin: jest.fn(),
  impersonation: null,
  impersonate: jest.fn(),
  stopImpersonation: jest.fn(),
  ...overrides,
}) as unknown as ReturnType<typeof useAuth>;

const impersonation = (secondsLeft: number) => ({
  by: 7,
  by_email: 'admin@example.com',
  by_name: 'Ada Admin',
  started_at: NOW_SECONDS - 60,
  exp: NOW_SECONDS + secondsLeft,
});

describe('ImpersonationBanner', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.spyOn(Date, 'now').mockReturnValue(NOW_SECONDS * 1000);
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  test('renders nothing for an ordinary session', () => {
    mockUseAuth.mockReturnValue(authValue());

    const { container } = render(<ImpersonationBanner />);

    expect(container).toBeEmptyDOMElement();
  });

  test('names the user being viewed and the admin behind the session', () => {
    mockUseAuth.mockReturnValue(authValue({ impersonation: impersonation(600) }));

    render(<ImpersonationBanner />);

    expect(screen.getByText(/Viewing as/i)).toBeInTheDocument();
    expect(screen.getByText('Pat Parent')).toBeInTheDocument();
    expect(screen.getByText(/Ada Admin/)).toBeInTheDocument();
  });

  test('shows the remaining time, because the token dies with it', () => {
    mockUseAuth.mockReturnValue(authValue({ impersonation: impersonation(125) }));

    render(<ImpersonationBanner />);

    expect(screen.getByText('Ends in 2:05')).toBeInTheDocument();
  });

  /**
   * The banner is the ONLY thing distinguishing this session from a real login
   * as the target, and every action taken hits their real data. A dismiss
   * control would let an admin lose track of which account they are in.
   */
  test('offers no way to dismiss it — only to exit', () => {
    mockUseAuth.mockReturnValue(authValue({ impersonation: impersonation(600) }));

    render(<ImpersonationBanner />);

    const buttons = screen.getAllByRole('button');
    expect(buttons).toHaveLength(1);
    expect(buttons[0]).toHaveTextContent(/exit/i);
  });

  test('Exit ends the impersonation', async () => {
    const stopImpersonation = jest.fn().mockResolvedValue(undefined);
    mockUseAuth.mockReturnValue(authValue({ impersonation: impersonation(600), stopImpersonation }));

    render(<ImpersonationBanner />);
    fireEvent.click(screen.getByRole('button', { name: /exit/i }));

    await waitFor(() => expect(stopImpersonation).toHaveBeenCalledTimes(1));
  });

  test('an already-expired window reads as ended, not as negative time', () => {
    mockUseAuth.mockReturnValue(authValue({ impersonation: impersonation(-30) }));

    render(<ImpersonationBanner />);

    expect(screen.getByText('Session ended')).toBeInTheDocument();
  });
});

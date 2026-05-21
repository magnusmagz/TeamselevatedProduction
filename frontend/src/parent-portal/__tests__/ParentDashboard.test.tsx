import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { ParentDashboard } from '../ParentDashboard';

// Mock BrandingLogo to avoid OrgContext dependency
jest.mock('../../components/BrandingLogo', () => ({
  __esModule: true,
  default: () => <div data-testid="branding-logo">Logo</div>,
}));

// Mock react-router-dom
jest.mock('react-router-dom', () => ({
  Link: ({ to, children, className }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} className={className}>{children}</a>
  ),
  useNavigate: () => jest.fn(),
}));

// Mock fetch
global.fetch = jest.fn();

// Mock the hooks and contexts
jest.mock('../../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));

jest.mock('../hooks/useParentAthletes', () => ({
  useParentAthletes: jest.fn(),
}));

import { useAuth } from '../../contexts/AuthContext';
import { useParentAthletes } from '../hooks/useParentAthletes';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockUseParentAthletes = useParentAthletes as jest.MockedFunction<typeof useParentAthletes>;

describe('ParentDashboard', () => {
  const mockUser = {
    id: 1,
    email: 'parent@example.com',
    name: 'Parent User',
  };

  const mockAthletes = [
    {
      id: 1,
      first_name: 'John',
      last_name: 'Doe',
      teams: [{ id: 10, name: 'U14 Soccer' }],
    },
    {
      id: 2,
      first_name: 'Jane',
      last_name: 'Doe',
      teams: [{ id: 11, name: 'U12 Soccer' }],
    },
  ];

  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');

    mockUseAuth.mockReturnValue({
      user: mockUser,
      isLoading: false,
      error: null,
      login: jest.fn(),
      updateUser: jest.fn(),
      logout: jest.fn(),
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
    });

    mockUseParentAthletes.mockReturnValue({
      athletes: mockAthletes,
      loading: false,
      error: null,
      selectedAthleteId: null,
      selectAthlete: jest.fn(),
      selectedAthlete: null,
      fetchAthleteDetails: jest.fn(),
      refreshAthletes: jest.fn(),
    });

    // Mock fetch for invoices, events, announcements
    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      if (url.includes('invoices')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            success: true,
            invoices: [
              {
                id: 1,
                athlete_id: 1,
                athlete_first: 'John',
                athlete_last: 'Doe',
                total_amount: 500,
                amount_paid: 200,
                amount_remaining: 300,
                is_overdue: false,
                status: 'partial',
                due_date: '2024-02-15',
              },
            ],
          }),
        });
      }
      if (url.includes('events')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            success: true,
            events: [
              {
                id: 1,
                title: 'Practice',
                date: '2024-02-10',
                time: '16:00',
                location: 'Main Field',
              },
            ],
          }),
        });
      }
      if (url.includes('announcements')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            success: true,
            announcements: [
              {
                id: 1,
                title: 'Team Meeting',
                message: 'Important meeting next week',
                created_at: new Date().toISOString(),
              },
            ],
          }),
        });
      }
      return Promise.reject(new Error('Unknown URL'));
    });
  });

  afterEach(() => {
    localStorage.clear();
  });

  test('renders welcome message with user name', async () => {
    render(
      <ParentDashboard />
    );

    await waitFor(() => {
      expect(screen.getByText(/Welcome, Parent/)).toBeInTheDocument();
    });
  });

  test('renders quick action buttons', async () => {
    render(
      <ParentDashboard />
    );

    await waitFor(() => {
      expect(screen.getByText('Make Payment')).toBeInTheDocument();
      expect(screen.getByText('View Schedule')).toBeInTheDocument();
    });
  });

  test('renders athletes section', async () => {
    render(
      <ParentDashboard />
    );

    await waitFor(() => {
      expect(screen.getByText('My Athletes')).toBeInTheDocument();
      expect(screen.getByText('John Doe')).toBeInTheDocument();
      expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    });
  });

  test('shows outstanding payment alert when balance due', async () => {
    render(
      <ParentDashboard />
    );

    await waitFor(() => {
      expect(screen.getByText('$300.00 Outstanding')).toBeInTheDocument();
    });
  });

  test('shows loading spinner when athletes loading', () => {
    mockUseParentAthletes.mockReturnValue({
      athletes: [],
      loading: true,
      error: null,
      selectedAthleteId: null,
      selectAthlete: jest.fn(),
      selectedAthlete: null,
      fetchAthleteDetails: jest.fn(),
      refreshAthletes: jest.fn(),
    });

    render(
      <ParentDashboard />
    );

    expect(document.querySelector('.animate-spin')).toBeInTheDocument();
  });

  test('shows empty state when no athletes', async () => {
    mockUseParentAthletes.mockReturnValue({
      athletes: [],
      loading: false,
      error: null,
      selectedAthleteId: null,
      selectAthlete: jest.fn(),
      selectedAthlete: null,
      fetchAthleteDetails: jest.fn(),
      refreshAthletes: jest.fn(),
    });

    render(
      <ParentDashboard />
    );

    await waitFor(() => {
      expect(screen.getByText('No Athletes Found')).toBeInTheDocument();
    });
  });
});

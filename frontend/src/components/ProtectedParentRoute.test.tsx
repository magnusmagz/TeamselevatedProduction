import React from 'react';
import { render, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { ProtectedParentRoute } from './ProtectedParentRoute';

// Mock the hooks
jest.mock('../hooks/useAuth', () => ({
  useAuth: jest.fn(),
}));

jest.mock('../contexts/FinancialPermissionsContext', () => ({
  useFinancialPermissions: jest.fn(),
}));

import { useAuth } from '../hooks/useAuth';
import { useFinancialPermissions } from '../contexts/FinancialPermissionsContext';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockUseFinancialPermissions = useFinancialPermissions as jest.MockedFunction<typeof useFinancialPermissions>;

const RouterWrapper: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <BrowserRouter>{children}</BrowserRouter>
);

describe('ProtectedParentRoute', () => {
  const TestChild = () => <div>Protected Content</div>;

  beforeEach(() => {
    jest.clearAllMocks();
  });

  test('shows loading spinner when auth is loading', () => {
    mockUseAuth.mockReturnValue({
      user: null,
      isLoading: true,
      error: null,
      login: jest.fn(),
      logout: jest.fn(),
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
      impersonation: null,
      impersonate: jest.fn(),
      stopImpersonation: jest.fn(),
      updateUser: jest.fn(),
    });
    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: false, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [],
      accessibleAthletes: [],
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    render(
      <RouterWrapper>
        <ProtectedParentRoute>
          <TestChild />
        </ProtectedParentRoute>
      </RouterWrapper>
    );

    expect(screen.getByText('LOADING...')).toBeInTheDocument();
  });

  test('shows loading spinner when permissions are loading', () => {
    mockUseAuth.mockReturnValue({
      user: { id: 1, email: 'test@example.com', name: 'Test User' },
      isLoading: false,
      error: null,
      login: jest.fn(),
      logout: jest.fn(),
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
      impersonation: null,
      impersonate: jest.fn(),
      stopImpersonation: jest.fn(),
      updateUser: jest.fn(),
    });
    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: false, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [],
      accessibleAthletes: [],
      loading: true,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    render(
      <RouterWrapper>
        <ProtectedParentRoute>
          <TestChild />
        </ProtectedParentRoute>
      </RouterWrapper>
    );

    expect(screen.getByText('LOADING...')).toBeInTheDocument();
  });

  test('does not render children when user is not authenticated', () => {
    mockUseAuth.mockReturnValue({
      user: null,
      isLoading: false,
      error: null,
      login: jest.fn(),
      logout: jest.fn(),
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
      impersonation: null,
      impersonate: jest.fn(),
      stopImpersonation: jest.fn(),
      updateUser: jest.fn(),
    });
    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: false, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [],
      accessibleAthletes: [],
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    render(
      <RouterWrapper>
        <ProtectedParentRoute>
          <TestChild />
        </ProtectedParentRoute>
      </RouterWrapper>
    );

    expect(screen.queryByText('Protected Content')).not.toBeInTheDocument();
  });

  test('does not render children when user is not a parent', () => {
    mockUseAuth.mockReturnValue({
      user: { id: 1, email: 'test@example.com', name: 'Test User' },
      isLoading: false,
      error: null,
      login: jest.fn(),
      logout: jest.fn(),
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
      impersonation: null,
      impersonate: jest.fn(),
      stopImpersonation: jest.fn(),
      updateUser: jest.fn(),
    });
    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: false, is_coach: true, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [],
      accessibleAthletes: [],
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    render(
      <RouterWrapper>
        <ProtectedParentRoute>
          <TestChild />
        </ProtectedParentRoute>
      </RouterWrapper>
    );

    expect(screen.queryByText('Protected Content')).not.toBeInTheDocument();
  });

  test('renders children when user is a parent', () => {
    mockUseAuth.mockReturnValue({
      user: { id: 1, email: 'parent@example.com', name: 'Parent User' },
      isLoading: false,
      error: null,
      login: jest.fn(),
      logout: jest.fn(),
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
      impersonation: null,
      impersonate: jest.fn(),
      stopImpersonation: jest.fn(),
      updateUser: jest.fn(),
    });
    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: true, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [1, 2],
      accessibleAthletes: [
        { id: 1, first_name: 'John', last_name: 'Doe' },
        { id: 2, first_name: 'Jane', last_name: 'Doe' },
      ],
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    render(
      <RouterWrapper>
        <ProtectedParentRoute>
          <TestChild />
        </ProtectedParentRoute>
      </RouterWrapper>
    );

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });
});

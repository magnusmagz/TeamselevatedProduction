import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { AthleteDetailPage } from '../pages/AthleteDetailPage';

// Mock react-router-dom: drive the :id param and stub Link/useNavigate.
let mockId = '999';
jest.mock('react-router-dom', () => ({
  useParams: () => ({ id: mockId }),
  useNavigate: () => jest.fn(),
  Link: ({ to, children, className }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} className={className}>{children}</a>
  ),
}));

jest.mock('../components/ParentHeader', () => ({
  ParentHeader: ({ title }: { title: string }) => <header>{title}</header>,
}));

jest.mock('../../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));

jest.mock('../../contexts/FinancialPermissionsContext', () => ({
  useFinancialPermissions: jest.fn(),
}));

import { useAuth } from '../../contexts/AuthContext';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockUsePerms = useFinancialPermissions as jest.MockedFunction<typeof useFinancialPermissions>;

const basePerms = {
  permissions: {} as any,
  roles: { is_club_admin: false, is_treasurer: false, is_coach: false, is_parent: true },
  accessibleAthletes: [],
  myChildren: [],
  loading: false,
  canViewAthletePayments: () => false,
  canViewAthleteAmounts: () => false,
  isFinancialAdmin: false,
  refreshPermissions: async () => {},
};

describe('AthleteDetailPage access control', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
    global.fetch = jest.fn() as any;
    mockUseAuth.mockReturnValue({ user: { id: 1, email: 'parent@example.com', name: 'P' } } as any);
  });

  test('renders Access-denied and does NOT fetch for an athlete who is not this user\'s child', async () => {
    mockId = '999';
    mockUsePerms.mockReturnValue({
      ...basePerms,
      myChildrenIds: [1, 2],
    } as any);

    render(<AthleteDetailPage />);

    expect(await screen.findByText(/access denied/i)).toBeInTheDocument();
    // Critical: no network request for an out-of-scope athlete.
    expect(global.fetch).not.toHaveBeenCalled();
  });

  test('fetches when the athlete IS this user\'s child', async () => {
    mockId = '2';
    mockUsePerms.mockReturnValue({
      ...basePerms,
      myChildrenIds: [1, 2],
    } as any);

    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, athlete: { id: 2, first_name: 'Jane', last_name: 'Doe', teams: [] } }),
    });

    render(<AthleteDetailPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(screen.queryByText(/access denied/i)).not.toBeInTheDocument();
  });
});

import { renderHook, waitFor, act } from '@testing-library/react';
import { useParentAthletes } from '../hooks/useParentAthletes';

// Mock fetch
global.fetch = jest.fn();

// Mock the FinancialPermissionsContext
jest.mock('../../contexts/FinancialPermissionsContext', () => ({
  useFinancialPermissions: jest.fn(),
}));

import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';

const mockUseFinancialPermissions = useFinancialPermissions as jest.MockedFunction<typeof useFinancialPermissions>;

describe('useParentAthletes', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
  });

  afterEach(() => {
    localStorage.clear();
  });

  test('returns loading true when permissions are loading', () => {
    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: true, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [],
      accessibleAthletes: [],
      loading: true,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    const { result } = renderHook(() => useParentAthletes());

    expect(result.current.loading).toBe(true);
    expect(result.current.athletes).toEqual([]);
  });

  test('fetches athlete details for accessible athletes', async () => {
    const mockAthletes = [
      { id: 1, first_name: 'John', last_name: 'Doe' },
    ];

    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: true, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [1],
      accessibleAthletes: mockAthletes,
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        athlete: {
          id: 1,
          first_name: 'John',
          last_name: 'Doe',
          date_of_birth: '2010-01-15',
          teams: [{ id: 10, name: 'U14 Soccer' }],
        },
      }),
    });

    const { result } = renderHook(() => useParentAthletes());

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(result.current.athletes).toHaveLength(1);
    expect(result.current.athletes[0].first_name).toBe('John');
  });

  test('auto-selects first athlete when none selected', async () => {
    const mockAthletes = [
      { id: 1, first_name: 'John', last_name: 'Doe' },
    ];

    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: true, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [1],
      accessibleAthletes: mockAthletes,
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        athlete: { id: 1, first_name: 'John', last_name: 'Doe', teams: [] },
      }),
    });

    const { result } = renderHook(() => useParentAthletes());

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(result.current.selectedAthleteId).toBe(1);
  });

  test('selectAthlete updates selected athlete', async () => {
    const mockAthletes = [
      { id: 1, first_name: 'John', last_name: 'Doe' },
      { id: 2, first_name: 'Jane', last_name: 'Doe' },
    ];

    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: true, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [1, 2],
      accessibleAthletes: mockAthletes,
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        athlete: { id: 1, first_name: 'John', last_name: 'Doe', teams: [] },
      }),
    });

    const { result } = renderHook(() => useParentAthletes());

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    // Select athlete 2
    act(() => {
      result.current.selectAthlete(2);
    });

    expect(result.current.selectedAthleteId).toBe(2);
  });

  test('returns null for selectedAthlete when none selected', () => {
    mockUseFinancialPermissions.mockReturnValue({
      permissions: {} as any,
      roles: { is_parent: true, is_coach: false, is_club_admin: false, is_treasurer: false },
      accessibleAthleteIds: [],
      accessibleAthletes: [],
      loading: false,
      canViewAthletePayments: jest.fn(),
      canViewAthleteAmounts: jest.fn(),
      isFinancialAdmin: false,
      refreshPermissions: jest.fn(),
    });

    const { result } = renderHook(() => useParentAthletes());

    expect(result.current.selectedAthlete).toBeNull();
  });
});

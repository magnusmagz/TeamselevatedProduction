import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MedicalInfoPage } from '../pages/MedicalInfoPage';

let mockId = '999';
jest.mock('react-router-dom', () => ({
  useParams: () => ({ id: mockId }),
}));

jest.mock('../components/ParentHeader', () => ({
  ParentHeader: ({ title }: { title: string }) => <header>{title}</header>,
}));

jest.mock('../../contexts/FinancialPermissionsContext', () => ({
  useFinancialPermissions: jest.fn(),
}));

import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';

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

describe('MedicalInfoPage access control', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
    global.fetch = jest.fn() as any;
  });

  test('renders Access-denied and does NOT fetch medical data for an out-of-scope athlete', async () => {
    mockId = '999';
    mockUsePerms.mockReturnValue({ ...basePerms, myChildrenIds: [1, 2] } as any);

    render(<MedicalInfoPage />);

    expect(await screen.findByText(/access denied/i)).toBeInTheDocument();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  test('fetches medical data when athlete IS accessible', async () => {
    mockId = '1';
    mockUsePerms.mockReturnValue({ ...basePerms, myChildrenIds: [1, 2] } as any);

    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, athlete: { id: 1, first_name: 'John', last_name: 'Doe' } }),
    });

    render(<MedicalInfoPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(screen.queryByText(/access denied/i)).not.toBeInTheDocument();
  });
});

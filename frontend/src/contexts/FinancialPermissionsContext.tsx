import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { useAuth } from './AuthContext';

interface FinancialPermissions {
  can_view_revenue: boolean;
  can_view_all_payments: boolean;
  can_view_athlete_payment_status: boolean;
  can_view_own_payments: boolean;
  can_send_reminders: boolean;
  can_process_payments: boolean;
  can_view_transactions: boolean;
  can_export_reports: boolean;
  can_view_roster_fees: boolean;
  view_amounts: boolean;
}

interface UserRoles {
  is_league_admin: boolean;
  is_club_admin: boolean;
  is_treasurer: boolean;
  is_coach: boolean;
  is_parent: boolean;
}

interface AccessibleAthlete {
  id: number;
  first_name: string;
  last_name: string;
}

interface FinancialPermissionsContextType {
  permissions: FinancialPermissions;
  roles: UserRoles;
  accessibleAthleteIds: number[];
  accessibleAthletes: AccessibleAthlete[];
  loading: boolean;
  canViewAthletePayments: (athleteId: number) => boolean;
  canViewAthleteAmounts: (athleteId: number) => boolean;
  isFinancialAdmin: boolean;
  refreshPermissions: () => Promise<void>;
}

const defaultPermissions: FinancialPermissions = {
  can_view_revenue: false,
  can_view_all_payments: false,
  can_view_athlete_payment_status: false,
  can_view_own_payments: true,
  can_send_reminders: false,
  can_process_payments: false,
  can_view_transactions: false,
  can_export_reports: false,
  can_view_roster_fees: false,
  view_amounts: false
};

const defaultRoles: UserRoles = {
  is_league_admin: false,
  is_club_admin: false,
  is_treasurer: false,
  is_coach: false,
  is_parent: false
};

const FinancialPermissionsContext = createContext<FinancialPermissionsContextType>({
  permissions: defaultPermissions,
  roles: defaultRoles,
  accessibleAthleteIds: [],
  accessibleAthletes: [],
  loading: true,
  canViewAthletePayments: () => false,
  canViewAthleteAmounts: () => false,
  isFinancialAdmin: false,
  refreshPermissions: async () => {}
});

export const useFinancialPermissions = () => useContext(FinancialPermissionsContext);

interface Props {
  children: ReactNode;
}

export const FinancialPermissionsProvider: React.FC<Props> = ({ children }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  const [permissions, setPermissions] = useState<FinancialPermissions>(defaultPermissions);
  const [roles, setRoles] = useState<UserRoles>(defaultRoles);
  const [accessibleAthleteIds, setAccessibleAthleteIds] = useState<number[]>([]);
  const [accessibleAthletes, setAccessibleAthletes] = useState<AccessibleAthlete[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchPermissions = async () => {
    const token = localStorage.getItem('auth_token');

    if (!user || !token) {
      setPermissions(defaultPermissions);
      setRoles(defaultRoles);
      setAccessibleAthleteIds([]);
      setAccessibleAthletes([]);
      setLoading(false);
      return;
    }

    try {
      const response = await fetch(`${API_URL}/api/financial-permissions.php?action=check`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      const data = await response.json();

      if (data.success && data.authenticated) {
        setPermissions(data.permissions);
        setRoles(data.roles);
        setAccessibleAthleteIds(data.accessible_athlete_ids || []);
        setAccessibleAthletes(data.accessible_athletes || []);
      } else {
        setPermissions(defaultPermissions);
        setRoles(defaultRoles);
      }
    } catch (error) {
      console.error('Error fetching financial permissions:', error);
      setPermissions(defaultPermissions);
      setRoles(defaultRoles);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchPermissions();
  }, [user]);

  const canViewAthletePayments = (athleteId: number): boolean => {
    if (permissions.can_view_all_payments) return true;
    if (permissions.can_view_athlete_payment_status && accessibleAthleteIds.includes(athleteId)) return true;
    if (permissions.can_view_own_payments && accessibleAthleteIds.includes(athleteId)) return true;
    return false;
  };

  const canViewAthleteAmounts = (athleteId: number): boolean => {
    if (permissions.view_amounts) return true;
    if (roles.is_parent && accessibleAthleteIds.includes(athleteId)) return true;
    return false;
  };

  const isFinancialAdmin = roles.is_league_admin || roles.is_club_admin || roles.is_treasurer;

  const value: FinancialPermissionsContextType = {
    permissions,
    roles,
    accessibleAthleteIds,
    accessibleAthletes,
    loading,
    canViewAthletePayments,
    canViewAthleteAmounts,
    isFinancialAdmin,
    refreshPermissions: fetchPermissions
  };

  return (
    <FinancialPermissionsContext.Provider value={value}>
      {children}
    </FinancialPermissionsContext.Provider>
  );
};

export default FinancialPermissionsContext;

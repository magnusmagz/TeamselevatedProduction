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
  /**
   * Athletes whose FINANCES this user may view: their own children plus every
   * athlete on the teams they coach. Use for payment screens.
   */
  accessibleAthleteIds: number[];
  accessibleAthletes: AccessibleAthlete[];
  /**
   * Athletes this user is a GUARDIAN of. Their family, and nothing else.
   *
   * Anything in the parent portal that means "my children" reads these. Reading
   * accessibleAthletes there is what showed a coach-parent their whole roster and
   * asked them to give parental consent for other people's kids (2026-08-17).
   */
  myChildrenIds: number[];
  myChildren: AccessibleAthlete[];
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
  myChildrenIds: [],
  myChildren: [],
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
  const [myChildrenIds, setMyChildrenIds] = useState<number[]>([]);
  const [myChildren, setMyChildren] = useState<AccessibleAthlete[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchPermissions = async () => {
    const token = localStorage.getItem('auth_token');

    if (!user || !token) {
      setPermissions(defaultPermissions);
      setRoles(defaultRoles);
      setAccessibleAthleteIds([]);
      setAccessibleAthletes([]);
      setMyChildrenIds([]);
      setMyChildren([]);
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

        // ⚠️ `??`, not `||`, and the distinction is load-bearing.
        //
        // ABSENT my_children means the backend predates this field — `main` is
        // shared and deploys are by push, so the frontend can be live before the
        // backend is. Falling back to accessible_athletes there reinstates the old
        // (wrong) behaviour for a few minutes, which is visible and known. Treating
        // it as "no children" instead would silently stop prompting EVERY family for
        // consent, which is a compliance gap nobody would notice.
        //
        // EMPTY my_children is a real answer — a staff-only account with no guardian
        // row — and must be respected. `||` would collapse the two and hand a coach
        // their whole roster forever.
        setMyChildrenIds(data.my_children_ids ?? data.accessible_athlete_ids ?? []);
        setMyChildren(data.my_children ?? data.accessible_athletes ?? []);
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

  const isFinancialAdmin = roles.is_club_admin || roles.is_treasurer;

  const value: FinancialPermissionsContextType = {
    permissions,
    roles,
    accessibleAthleteIds,
    accessibleAthletes,
    myChildrenIds,
    myChildren,
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

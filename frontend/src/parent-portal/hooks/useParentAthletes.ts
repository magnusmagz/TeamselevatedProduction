import { useState, useEffect, useCallback } from 'react';
import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';

interface Athlete {
  id: number;
  first_name: string;
  last_name: string;
  date_of_birth?: string;
  profile_image_url?: string;
  teams?: Array<{
    id: number;
    name: string;
  }>;
}

interface AthleteDetails extends Athlete {
  email?: string;
  phone?: string;
  address?: string;
  city?: string;
  state?: string;
  zip?: string;
  emergency_contact_name?: string;
  emergency_contact_phone?: string;
  medical_conditions?: string;
  allergies?: string;
  medications?: string;
  insurance_provider?: string;
  insurance_policy_number?: string;
}

interface UseParentAthletesReturn {
  athletes: Athlete[];
  loading: boolean;
  error: string | null;
  selectedAthleteId: number | null;
  selectAthlete: (id: number | null) => void;
  selectedAthlete: Athlete | null;
  fetchAthleteDetails: (id: number) => Promise<AthleteDetails | null>;
  refreshAthletes: () => void;
}

export function useParentAthletes(): UseParentAthletesReturn {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { accessibleAthletes, loading: permissionsLoading } = useFinancialPermissions();
  const [athletes, setAthletes] = useState<Athlete[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedAthleteId, setSelectedAthleteId] = useState<number | null>(null);

  const fetchAthletes = useCallback(async () => {
    if (permissionsLoading) return;

    setLoading(true);
    setError(null);

    try {
      const token = localStorage.getItem('auth_token');
      if (!token) {
        throw new Error('Not authenticated');
      }

      // Use accessible athletes from financial permissions as base
      // Then fetch additional details for each
      const athletesWithDetails = await Promise.all(
        accessibleAthletes.map(async (athlete) => {
          try {
            const response = await fetch(
              `${API_URL}/api/athletes/?action=get&id=${athlete.id}`,
              {
                headers: {
                  Authorization: `Bearer ${token}`,
                },
              }
            );
            const data = await response.json();
            if (data.success && data.athlete) {
              return {
                id: athlete.id,
                first_name: data.athlete.first_name || athlete.first_name,
                last_name: data.athlete.last_name || athlete.last_name,
                date_of_birth: data.athlete.date_of_birth,
                profile_image_url: data.athlete.profile_image_url,
                teams: data.athlete.teams || [],
              };
            }
          } catch (err) {
            console.error(`Error fetching athlete ${athlete.id}:`, err);
          }
          // Fallback to basic info
          return {
            id: athlete.id,
            first_name: athlete.first_name,
            last_name: athlete.last_name,
            teams: [],
          };
        })
      );

      setAthletes(athletesWithDetails);

      // Auto-select first athlete if none selected
      if (athletesWithDetails.length > 0 && !selectedAthleteId) {
        setSelectedAthleteId(athletesWithDetails[0].id);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load athletes');
    } finally {
      setLoading(false);
    }
  }, [API_URL, accessibleAthletes, permissionsLoading, selectedAthleteId]);

  useEffect(() => {
    fetchAthletes();
  }, [fetchAthletes]);

  const selectAthlete = useCallback((id: number | null) => {
    setSelectedAthleteId(id);
  }, []);

  const selectedAthlete = athletes.find((a) => a.id === selectedAthleteId) || null;

  const fetchAthleteDetails = useCallback(
    async (id: number): Promise<AthleteDetails | null> => {
      try {
        const token = localStorage.getItem('auth_token');
        if (!token) return null;

        const response = await fetch(
          `${API_URL}/api/athletes/?action=get&id=${id}`,
          {
            headers: {
              Authorization: `Bearer ${token}`,
            },
          }
        );
        const data = await response.json();
        if (data.success && data.athlete) {
          return data.athlete;
        }
      } catch (err) {
        console.error(`Error fetching athlete details ${id}:`, err);
      }
      return null;
    },
    [API_URL]
  );

  return {
    athletes,
    loading: loading || permissionsLoading,
    error,
    selectedAthleteId,
    selectAthlete,
    selectedAthlete,
    fetchAthleteDetails,
    refreshAthletes: fetchAthletes,
  };
}

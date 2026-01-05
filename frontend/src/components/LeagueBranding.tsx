import React, { useState, useEffect } from 'react';
import { LogoColorExtractor, LogoColorData } from './LogoColorExtractor';
import { useTheme } from '../contexts/ThemeContext';
import { clearBrandingCache } from './BrandingLogo';

interface LeagueBrandingProps {
  leagueId: number;
}

const LeagueBranding: React.FC<LeagueBrandingProps> = ({ leagueId }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { updateTheme } = useTheme();
  const [initialData, setInitialData] = useState<any>({});
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchLeagueData();
  }, [leagueId]); // eslint-disable-line react-hooks/exhaustive-deps

  const fetchLeagueData = async () => {
    try {
      const response = await fetch(`${API_URL}/api/leagues-gateway.php?action=get&id=${leagueId}`);
      const data = await response.json();

      if (data.success && data.league) {
        setInitialData({
          logoData: data.league.logo_url,
          logoFilename: data.league.logo_url ? 'league-logo.png' : undefined,
          primaryColor: data.league.primary_color,
          secondaryColor: data.league.secondary_color,
          accentColor: data.league.accent_color
        });
      }
    } catch (error) {
      console.error('Error fetching league data:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async (data: LogoColorData) => {
    try {
      const response = await fetch(`${API_URL}/api/leagues-gateway.php?action=update&id=${leagueId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          logo_url: data.logoBase64,
          primary_color: data.primaryColor,
          secondary_color: data.secondaryColor,
          accent_color: data.accentColor
        })
      });

      const result = await response.json();

      if (!result.success) {
        throw new Error(result.error || 'Failed to save league branding');
      }

      // Clear the logo cache and update theme colors immediately
      clearBrandingCache('league', leagueId);
      updateTheme(data.primaryColor);
    } catch (error) {
      throw new Error(error instanceof Error ? error.message : 'Failed to save league branding');
    }
  };

  if (loading) {
    return (
      <div className="text-center text-brand-primary py-12">
        Loading league branding...
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h2 className="text-xl font-semibold text-brand-primary">League Branding</h2>
        <p className="text-gray-600 mt-1">
          Upload your league logo and customize brand colors. Your logo will appear throughout the platform for all league members.
        </p>
      </div>

      <LogoColorExtractor
        initialData={initialData}
        onSave={handleSave}
        maxFileSize={2 * 1024 * 1024}
      />
    </div>
  );
};

export default LeagueBranding;

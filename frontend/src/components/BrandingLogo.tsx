import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../contexts/OrgContext';

interface BrandingData {
  logo_url: string | null;
  name: string;
  context_type: 'league' | 'club' | 'team';
  context_id: number;
  primary_color?: string;
  secondary_color?: string;
  fallback?: BrandingData | null;
}

interface BrandingLogoProps {
  contextType?: 'league' | 'club' | 'team';
  contextId?: number;
  fallbackToText?: boolean;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  className?: string;
}

// In-memory cache with 5-minute TTL
const logoCache = new Map<string, { data: BrandingData; timestamp: number }>();
const CACHE_TTL = 5 * 60 * 1000; // 5 minutes

const BrandingLogo: React.FC<BrandingLogoProps> = ({
  contextType,
  contextId,
  fallbackToText = true,
  size = 'md',
  className = ''
}) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { activeContext, currentLeagueId, currentClubId } = useOrg();

  const [branding, setBranding] = useState<BrandingData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [currentLogoUrl, setCurrentLogoUrl] = useState<string | null>(null);

  // Determine context from props or OrgContext
  const effectiveContextType = contextType || activeContext?.scope_type || 'league';
  const effectiveContextId = contextId || (() => {
    if (contextType === 'league' || activeContext?.scope_type === 'league') {
      return currentLeagueId;
    }
    if (contextType === 'club' || activeContext?.scope_type === 'club') {
      return currentClubId;
    }
    return currentLeagueId; // Default fallback
  })();

  // Size classes
  const sizeClasses = {
    sm: 'h-6',
    md: 'h-8',
    lg: 'h-12',
    xl: 'h-16'
  };

  const textSizeClasses = {
    sm: 'text-sm',
    md: 'text-lg',
    lg: 'text-2xl',
    xl: 'text-3xl'
  };

  const fetchBranding = useCallback(async (type: string, id: number | null) => {
    if (!id) {
      setLoading(false);
      return null;
    }

    const cacheKey = `${type}-${id}`;

    // Check cache
    const cached = logoCache.get(cacheKey);
    if (cached && Date.now() - cached.timestamp < CACHE_TTL) {
      return cached.data;
    }

    try {
      const response = await fetch(
        `${API_URL}/api/organization-branding.php?context_type=${type}&context_id=${id}`
      );

      if (!response.ok) {
        throw new Error('Failed to fetch branding');
      }

      const data = await response.json();

      if (data.success && data.branding) {
        // Cache the result
        logoCache.set(cacheKey, {
          data: data.branding,
          timestamp: Date.now()
        });

        return data.branding;
      }

      return null;
    } catch (err) {
      console.error('Error fetching branding:', err);
      return null;
    }
  }, [API_URL]);

  // Find first available logo in fallback chain
  const findLogo = useCallback((brandingData: BrandingData | null): string | null => {
    if (!brandingData) return null;

    // Check current level
    if (brandingData.logo_url) {
      return brandingData.logo_url;
    }

    // Check fallback
    if (brandingData.fallback) {
      return findLogo(brandingData.fallback);
    }

    return null;
  }, []);

  useEffect(() => {
    const loadBranding = async () => {
      setLoading(true);
      setError(false);

      const brandingData = await fetchBranding(effectiveContextType, effectiveContextId);

      if (brandingData) {
        setBranding(brandingData);
        const logoUrl = findLogo(brandingData);
        setCurrentLogoUrl(logoUrl);
      } else {
        setError(true);
      }

      setLoading(false);
    };

    if (effectiveContextId) {
      loadBranding();
    } else {
      setLoading(false);
    }
  }, [effectiveContextType, effectiveContextId, fetchBranding, findLogo]);

  // Handle image load error - try fallback
  const handleImageError = useCallback(() => {
    if (branding?.fallback) {
      const fallbackLogoUrl = findLogo(branding.fallback);
      if (fallbackLogoUrl && fallbackLogoUrl !== currentLogoUrl) {
        setCurrentLogoUrl(fallbackLogoUrl);
        return;
      }
    }

    // No fallback available, show text
    setError(true);
  }, [branding, currentLogoUrl, findLogo]);

  // Loading state
  if (loading) {
    return (
      <div className={`${sizeClasses[size]} ${className} flex items-center`}>
        <div className="text-forest-800 opacity-50 animate-pulse uppercase tracking-wide font-bold">
          Loading...
        </div>
      </div>
    );
  }

  // Show logo if available
  if (currentLogoUrl && !error) {
    return (
      <img
        src={currentLogoUrl}
        alt={branding?.name || 'Organization logo'}
        className={`${sizeClasses[size]} ${className} w-auto object-contain`}
        onError={handleImageError}
        style={{ imageRendering: 'crisp-edges' }}
      />
    );
  }

  // Fallback to text
  if (fallbackToText) {
    return (
      <span className={`${textSizeClasses[size]} ${className} font-bold text-forest-800 uppercase tracking-wide`}>
        {branding?.name || 'TEAMS ELEVATED'}
      </span>
    );
  }

  // No fallback, return null
  return null;
};

export default BrandingLogo;

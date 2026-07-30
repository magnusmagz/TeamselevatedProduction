import React, { createContext, useState, useEffect, useContext, useCallback } from 'react';
import { useOrg } from './OrgContext';
import { useAuth } from './AuthContext';
import { generateColorPalette } from '../utils/colorExtractor';

// Default forest green colors (fallback when no branding is set)
const DEFAULT_PRIMARY = '#12443e';

interface ThemeColors {
  primary: string;
  primaryHover: string;
  primaryDark: string;
  secondary: string;
  secondaryHover: string;
  accent: string;
  light: string;
  muted: string;
}

interface ThemeContextType {
  colors: ThemeColors;
  updateTheme: (primaryColor: string, secondaryColor?: string) => void;
  isLoading: boolean;
  brandingVersion: number; // Incremented when branding changes to trigger re-fetches
}

const ThemeContext = createContext<ThemeContextType | undefined>(undefined);

/**
 * Apply theme colors to CSS custom properties on the document root
 */
function applyThemeToDOM(colors: ThemeColors) {
  const root = document.documentElement;
  root.style.setProperty('--color-primary', colors.primary);
  root.style.setProperty('--color-primary-hover', colors.primaryHover);
  root.style.setProperty('--color-primary-dark', colors.primaryDark);
  root.style.setProperty('--color-secondary', colors.secondary);
  root.style.setProperty('--color-secondary-hover', colors.secondaryHover);
  root.style.setProperty('--color-accent', colors.accent);
  root.style.setProperty('--color-light', colors.light);
  root.style.setProperty('--color-muted', colors.muted);
}

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const { currentClubId } = useOrg();
  const { user } = useAuth();
  const isSuperAdmin = user?.system_role === 'super_admin';
  const [colors, setColors] = useState<ThemeColors>(() => {
    // Generate default palette
    const palette = generateColorPalette(DEFAULT_PRIMARY);
    return palette;
  });
  const [isLoading, setIsLoading] = useState(true);
  const [brandingVersion, setBrandingVersion] = useState(0);

  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

  // Fetch club branding on mount and when club changes.
  // Super admins always see platform-level branding regardless of which
  // clubs they have access to, so their marketing screenshots of the app
  // don't pick up arbitrary client logos or colors.
  useEffect(() => {
    const fetchBranding = async () => {
      if (isSuperAdmin || !currentClubId) {
        const defaultPalette = generateColorPalette(DEFAULT_PRIMARY);
        setColors(defaultPalette);
        applyThemeToDOM(defaultPalette);
        setIsLoading(false);
        return;
      }

      try {
        const response = await fetch(
          `${API_URL}/api/organization-branding.php?context_type=club&context_id=${currentClubId}`
        );
        const data = await response.json();

        if (data.success && data.branding?.primary_color) {
          // Generate palette from the club's primary color, honoring the club's
          // explicitly saved secondary_color when present (falls back to a
          // primary-derived tint inside generateColorPalette when absent).
          const palette = generateColorPalette(
            data.branding.primary_color,
            data.branding.secondary_color || undefined
          );
          setColors(palette);
          applyThemeToDOM(palette);
        } else {
          // No branding set, use defaults
          const defaultPalette = generateColorPalette(DEFAULT_PRIMARY);
          setColors(defaultPalette);
          applyThemeToDOM(defaultPalette);
        }
      } catch (error) {
        console.error('Error fetching club branding:', error);
        // On error, use defaults
        const defaultPalette = generateColorPalette(DEFAULT_PRIMARY);
        setColors(defaultPalette);
        applyThemeToDOM(defaultPalette);
      } finally {
        setIsLoading(false);
      }
    };

    fetchBranding();
  }, [currentClubId, API_URL, isSuperAdmin]);

  /**
   * Update theme colors immediately (called when branding is saved).
   * Accepts an optional secondaryColor so a club's saved secondary brand
   * color is applied to --color-secondary right away, rather than being
   * overwritten by a primary-derived tint.
   */
  const updateTheme = useCallback((primaryColor: string, secondaryColor?: string) => {
    const palette = generateColorPalette(primaryColor, secondaryColor);
    setColors(palette);
    applyThemeToDOM(palette);
    // Increment version to trigger re-fetches in components like BrandingLogo
    setBrandingVersion(v => v + 1);
  }, []);

  // Apply default theme on initial mount (before fetch completes)
  useEffect(() => {
    applyThemeToDOM(colors);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <ThemeContext.Provider value={{ colors, updateTheme, isLoading, brandingVersion }}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme() {
  const context = useContext(ThemeContext);
  if (context === undefined) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }
  return context;
}

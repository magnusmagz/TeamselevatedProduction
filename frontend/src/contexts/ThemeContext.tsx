import React, { createContext, useState, useEffect, useContext, useCallback } from 'react';
import { useOrg } from './OrgContext';
import { generateColorPalette } from '../utils/colorExtractor';

// Default forest green colors (fallback when no branding is set)
const DEFAULT_PRIMARY = '#12443e';
const DEFAULT_SECONDARY = '#a3ebd1';
const DEFAULT_ACCENT = '#3fcb9a';

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
  updateTheme: (primaryColor: string) => void;
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
  const [colors, setColors] = useState<ThemeColors>(() => {
    // Generate default palette
    const palette = generateColorPalette(DEFAULT_PRIMARY);
    return palette;
  });
  const [isLoading, setIsLoading] = useState(true);
  const [brandingVersion, setBrandingVersion] = useState(0);

  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

  // Fetch club branding on mount and when club changes
  useEffect(() => {
    const fetchBranding = async () => {
      if (!currentClubId) {
        // No club selected, use defaults
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
          // Generate palette from club's primary color
          const palette = generateColorPalette(data.branding.primary_color);
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
  }, [currentClubId, API_URL]);

  /**
   * Update theme colors immediately (called when branding is saved)
   */
  const updateTheme = useCallback((primaryColor: string) => {
    const palette = generateColorPalette(primaryColor);
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

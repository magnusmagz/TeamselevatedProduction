import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import { ThemeProvider, useTheme } from './ThemeContext';

// --- Mock the contexts ThemeProvider depends on -------------------------
let mockCurrentClubId: number | null = 32;
let mockSystemRole: 'super_admin' | 'user' | undefined = 'user';

jest.mock('./OrgContext', () => ({
  useOrg: () => ({ currentClubId: mockCurrentClubId }),
}));

jest.mock('./AuthContext', () => ({
  useAuth: () => ({ user: { system_role: mockSystemRole } }),
}));

global.fetch = jest.fn();

// Small consumer that exposes updateTheme + current secondary color
const Probe: React.FC = () => {
  const { colors, updateTheme } = useTheme();
  return (
    <div>
      <span data-testid="secondary">{colors.secondary}</span>
      <span data-testid="primary">{colors.primary}</span>
      <button
        data-testid="update"
        onClick={() => updateTheme('#0000ff', '#ffaa00')}
      >
        update
      </button>
      <button
        data-testid="update-primary-only"
        onClick={() => updateTheme('#0000ff')}
      >
        update primary only
      </button>
    </div>
  );
};

const getCssVar = (name: string) =>
  document.documentElement.style.getPropertyValue(name).trim();

describe('ThemeContext theming layer', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
    mockCurrentClubId = 32;
    mockSystemRole = 'user';
    // reset any inline CSS vars left from a previous test
    document.documentElement.removeAttribute('style');
  });

  it('applies the club\'s SAVED secondary_color to --color-secondary on load (not a primary-derived tint)', async () => {
    (fetch as jest.Mock).mockResolvedValueOnce({
      json: async () => ({
        success: true,
        branding: {
          primary_color: '#123456',
          secondary_color: '#ff8800', // explicit, distinct hue
        },
      }),
    });

    render(
      <ThemeProvider>
        <Probe />
      </ThemeProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('secondary')).toHaveTextContent('#ff8800');
    });

    // The exact saved color must reach the CSS variable consumed by brand-secondary
    expect(getCssVar('--color-secondary')).toBe('#ff8800');
    expect(getCssVar('--color-primary')).toBe('#123456');
  });

  it('falls back to a derived secondary when no secondary_color is saved', async () => {
    (fetch as jest.Mock).mockResolvedValueOnce({
      json: async () => ({
        success: true,
        branding: { primary_color: '#123456', secondary_color: null },
      }),
    });

    render(
      <ThemeProvider>
        <Probe />
      </ThemeProvider>
    );

    await waitFor(() => {
      // derived secondary is a lightened primary, so it must NOT equal primary
      // and must be populated
      expect(getCssVar('--color-secondary')).not.toBe('');
    });
    expect(getCssVar('--color-secondary')).not.toBe('#123456');
  });

  it('updateTheme applies the passed secondary color to --color-secondary', async () => {
    (fetch as jest.Mock).mockResolvedValueOnce({
      json: async () => ({ success: true, branding: { primary_color: '#123456' } }),
    });

    render(
      <ThemeProvider>
        <Probe />
      </ThemeProvider>
    );

    await waitFor(() => expect(getCssVar('--color-primary')).toBe('#123456'));

    await act(async () => {
      screen.getByTestId('update').click();
    });

    expect(screen.getByTestId('primary')).toHaveTextContent('#0000ff');
    expect(screen.getByTestId('secondary')).toHaveTextContent('#ffaa00');
    expect(getCssVar('--color-secondary')).toBe('#ffaa00');
  });

  it('updateTheme without a secondary derives one from primary (back-compat)', async () => {
    (fetch as jest.Mock).mockResolvedValueOnce({
      json: async () => ({ success: true, branding: { primary_color: '#123456' } }),
    });

    render(
      <ThemeProvider>
        <Probe />
      </ThemeProvider>
    );

    await waitFor(() => expect(getCssVar('--color-primary')).toBe('#123456'));

    await act(async () => {
      screen.getByTestId('update-primary-only').click();
    });

    expect(getCssVar('--color-primary')).toBe('#0000ff');
    // derived, populated, and not equal to primary
    const secondary = getCssVar('--color-secondary');
    expect(secondary).not.toBe('');
    expect(secondary).not.toBe('#0000ff');
  });
});

import React from 'react';
import { render, screen, waitFor, act, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ClubProfilePage from './ClubProfilePage';

// --- Mocks --------------------------------------------------------------
const mockUpdateTheme = jest.fn();
jest.mock('../contexts/ThemeContext', () => ({
  useTheme: () => ({ updateTheme: mockUpdateTheme }),
}));

const mockClearBrandingCache = jest.fn();
jest.mock('../components/BrandingLogo', () => ({
  clearBrandingCache: () => mockClearBrandingCache(),
}));

// Heavy / unrelated child components stubbed out
jest.mock('../components/ClubUserManagement', () => () => <div>users</div>);
jest.mock('../components/ImportTilesGrid', () => () => <div>imports</div>);
jest.mock('../components/GooglePlacesAutocomplete', () => () => (
  <input aria-label="address" />
));

// Expose the branding tab's onSave so we can drive it directly
let capturedOnSave: ((data: any) => Promise<void>) | null = null;
jest.mock('../components/LogoColorExtractor', () => ({
  LogoColorExtractor: (props: any) => {
    capturedOnSave = props.onSave;
    return <div>branding-editor</div>;
  },
}));

global.fetch = jest.fn();

const mockProfile = {
  id: 32,
  club_name: 'Test FC',
  address: '123 Main St',
  city: 'San Francisco',
  state: 'CA',
  zip: '94102',
  email: 'club@test.com',
  primary_color: '#123456',
  secondary_color: '#ff8800',
};

const renderPage = () =>
  render(
    <MemoryRouter>
      <ClubProfilePage />
    </MemoryRouter>
  );

describe('ClubProfilePage save flow', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockReset();
    mockUpdateTheme.mockClear();
    mockClearBrandingCache.mockClear();
    capturedOnSave = null;
    localStorage.setItem('auth_token', 'tok123');
  });

  it('loads the existing club profile from the gateway on mount', async () => {
    (fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => mockProfile,
    });

    renderPage();

    await waitFor(() =>
      expect(screen.getByDisplayValue('Test FC')).toBeInTheDocument()
    );

    const [url, opts] = (fetch as jest.Mock).mock.calls[0];
    expect(url).toContain('/legacy/club-profile-gateway.php');
    expect(opts.headers.Authorization).toBe('Bearer tok123');
  });

  it('PUTs the form data (name/address/colors) to the save endpoint on submit', async () => {
    (fetch as jest.Mock)
      .mockResolvedValueOnce({ ok: true, json: async () => mockProfile }) // initial GET
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true }) }); // PUT

    renderPage();

    await waitFor(() =>
      expect(screen.getByDisplayValue('Test FC')).toBeInTheDocument()
    );

    // change the name then submit
    fireEvent.change(screen.getByDisplayValue('Test FC'), {
      target: { value: 'New Club Name' },
    });

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
    });

    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));

    const [putUrl, putOpts] = (fetch as jest.Mock).mock.calls[1];
    expect(putUrl).toContain('/legacy/club-profile-gateway.php');
    expect(putOpts.method).toBe('PUT');

    const body = JSON.parse(putOpts.body);
    expect(body.club_name).toBe('New Club Name');
    expect(body.address).toBe('123 Main St');
    expect(body.primary_color).toBe('#123456');
    expect(body.secondary_color).toBe('#ff8800');
  });

  it('applies BOTH primary and secondary colors to the theme after a successful save', async () => {
    (fetch as jest.Mock)
      .mockResolvedValueOnce({ ok: true, json: async () => mockProfile })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true }) });

    renderPage();

    await waitFor(() =>
      expect(screen.getByDisplayValue('Test FC')).toBeInTheDocument()
    );

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
    });

    await waitFor(() => expect(mockUpdateTheme).toHaveBeenCalled());

    // Regression guard: the saved SECONDARY color must be passed, not dropped
    expect(mockUpdateTheme).toHaveBeenCalledWith('#123456', '#ff8800');
    expect(mockClearBrandingCache).toHaveBeenCalled();
  });

  it('branding tab save persists colors and applies both to the theme', async () => {
    (fetch as jest.Mock)
      .mockResolvedValueOnce({ ok: true, json: async () => mockProfile })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true }) });

    renderPage();

    await waitFor(() =>
      expect(screen.getByDisplayValue('Test FC')).toBeInTheDocument()
    );

    // switch to the Branding tab so LogoColorExtractor mounts and captures onSave
    fireEvent.click(screen.getByRole('button', { name: /branding/i }));
    await waitFor(() => expect(capturedOnSave).not.toBeNull());

    await act(async () => {
      await capturedOnSave!({
        logoBase64: 'data:image/png;base64,abc',
        logoFilename: 'logo.png',
        primaryColor: '#aabbcc',
        secondaryColor: '#ddeeff',
        accentColor: '#112233',
      });
    });

    const [putUrl, putOpts] = (fetch as jest.Mock).mock.calls[1];
    expect(putUrl).toContain('/legacy/club-profile-gateway.php');
    expect(putOpts.method).toBe('PUT');
    const body = JSON.parse(putOpts.body);
    expect(body.primary_color).toBe('#aabbcc');
    expect(body.secondary_color).toBe('#ddeeff');

    expect(mockUpdateTheme).toHaveBeenCalledWith('#aabbcc', '#ddeeff');
  });
});

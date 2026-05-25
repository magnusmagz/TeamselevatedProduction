import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { MoreMenuPage } from '../pages/MoreMenuPage';

// Mock BrandingLogo to avoid OrgContext dependency
jest.mock('../../components/BrandingLogo', () => ({
  __esModule: true,
  default: () => <div data-testid="branding-logo">Logo</div>,
}));

// Mock react-router-dom
jest.mock('react-router-dom', () => ({
  Link: ({ to, children, className }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} className={className}>{children}</a>
  ),
  useNavigate: () => jest.fn(),
}));

// Mock the hooks
jest.mock('../../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));

jest.mock('../../hooks/usePWAInstall', () => ({
  usePWAInstall: jest.fn(),
}));

import { useAuth } from '../../contexts/AuthContext';
import { usePWAInstall } from '../../hooks/usePWAInstall';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockUsePWAInstall = usePWAInstall as jest.MockedFunction<typeof usePWAInstall>;

describe('MoreMenuPage', () => {
  const mockLogout = jest.fn();
  const mockUser = {
    id: 1,
    email: 'parent@example.com',
    name: 'Parent User',
  };

  beforeEach(() => {
    jest.clearAllMocks();

    mockUseAuth.mockReturnValue({
      user: mockUser,
      isLoading: false,
      error: null,
      login: jest.fn(),
      updateUser: jest.fn(),
      logout: mockLogout,
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
    });

    mockUsePWAInstall.mockReturnValue({
      isInstallable: false,
      isInstalled: true,
      isIOS: false,
      isAndroid: false,
      promptInstall: jest.fn(),
    });
  });

  test('displays user name and email', () => {
    render(<MoreMenuPage />);

    expect(screen.getByText('Parent User')).toBeInTheDocument();
    expect(screen.getByText('parent@example.com')).toBeInTheDocument();
  });

  test('displays user initials', () => {
    render(<MoreMenuPage />);

    expect(screen.getByText('PU')).toBeInTheDocument();
  });

  test('renders all menu items', () => {
    render(<MoreMenuPage />);

    expect(screen.getByText('My Athletes')).toBeInTheDocument();
    expect(screen.getByText('Announcements')).toBeInTheDocument();
    expect(screen.getByText('Documents')).toBeInTheDocument();
    expect(screen.getByText('Account Settings')).toBeInTheDocument();
  });

  test('menu items have correct links', () => {
    render(<MoreMenuPage />);

    expect(screen.getByText('My Athletes').closest('a')).toHaveAttribute('href', '/parent/athletes');
    expect(screen.getByText('Announcements').closest('a')).toHaveAttribute('href', '/parent/announcements');
    expect(screen.getByText('Documents').closest('a')).toHaveAttribute('href', '/parent/documents');
    expect(screen.getByText('Account Settings').closest('a')).toHaveAttribute('href', '/profile');
  });

  test('renders Get Help link', () => {
    render(<MoreMenuPage />);

    expect(screen.getByText('Get Help')).toBeInTheDocument();
    expect(screen.getByText('Contact support')).toBeInTheDocument();
  });

  test('renders logout button', () => {
    render(<MoreMenuPage />);

    expect(screen.getByText('Log Out')).toBeInTheDocument();
  });

  test('calls logout when logout clicked', async () => {
    mockLogout.mockResolvedValue(undefined);
    render(<MoreMenuPage />);

    fireEvent.click(screen.getByText('Log Out'));

    expect(mockLogout).toHaveBeenCalled();
  });

  test('shows install app banner when installable', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: true,
      isInstalled: false,
      isIOS: false,
      isAndroid: false,
      promptInstall: jest.fn(),
    });

    render(<MoreMenuPage />);

    expect(screen.getByText('Install App')).toBeInTheDocument();
    expect(screen.getByText('Install for a better experience')).toBeInTheDocument();
  });

  test('does not show install banner when already installed', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: false,
      isInstalled: true,
      isIOS: false,
      isAndroid: false,
      promptInstall: jest.fn(),
    });

    render(<MoreMenuPage />);

    expect(screen.queryByText('Install App')).not.toBeInTheDocument();
  });

  test('displays version number', () => {
    render(<MoreMenuPage />);

    expect(screen.getByText('Teams Elevated v1.0.0')).toBeInTheDocument();
  });
});

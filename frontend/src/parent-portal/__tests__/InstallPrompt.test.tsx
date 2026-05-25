import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { InstallPrompt } from '../components/InstallPrompt';

// Mock the usePWAInstall hook — the component derives ALL platform state from
// the hook (single source of truth), so the mock fully controls its behavior.
jest.mock('../../hooks/usePWAInstall', () => ({
  usePWAInstall: jest.fn(),
}));

import { usePWAInstall } from '../../hooks/usePWAInstall';

const mockUsePWAInstall = usePWAInstall as jest.MockedFunction<typeof usePWAInstall>;

type PWAState = ReturnType<typeof usePWAInstall>;

const baseState: PWAState = {
  isInstallable: false,
  isInstalled: false,
  isIOS: false,
  isAndroid: false,
  promptInstall: jest.fn(),
};

const mockState = (overrides: Partial<PWAState>) =>
  mockUsePWAInstall.mockReturnValue({ ...baseState, ...overrides });

describe('InstallPrompt', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
  });

  test('does not render when already installed (display-mode standalone)', () => {
    mockState({ isInstalled: true });

    const { container } = render(<InstallPrompt />);

    expect(container.firstChild).toBeNull();
  });

  test('renders iOS-specific instructions on iOS devices', () => {
    mockState({ isIOS: true });

    render(<InstallPrompt />);

    expect(screen.getByText('Install Teams Elevated')).toBeInTheDocument();
    expect(screen.getByText(/Add to Home Screen/)).toBeInTheDocument();
  });

  test('renders install button on Android/Chrome when prompt is available', () => {
    mockState({ isInstallable: true, isAndroid: true });

    render(<InstallPrompt />);

    expect(screen.getByText('Install Teams Elevated for quick access')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Install' })).toBeInTheDocument();
  });

  test('renders Android fallback instructions when prompt not yet fired', () => {
    // Android device but beforeinstallprompt has not fired => no native prompt.
    mockState({ isInstallable: false, isAndroid: true });

    render(<InstallPrompt />);

    expect(screen.getByText('Install Teams Elevated')).toBeInTheDocument();
    expect(screen.getByText(/Add to Home Screen/)).toBeInTheDocument();
    // Fallback banner has no native Install button.
    expect(screen.queryByRole('button', { name: 'Install' })).not.toBeInTheDocument();
  });

  test('calls promptInstall when install button clicked', () => {
    const mockPromptInstall = jest.fn();
    mockState({ isInstallable: true, isAndroid: true, promptInstall: mockPromptInstall });

    render(<InstallPrompt />);

    fireEvent.click(screen.getByRole('button', { name: 'Install' }));

    expect(mockPromptInstall).toHaveBeenCalled();
  });

  test('can be dismissed on iOS', () => {
    mockState({ isIOS: true });

    render(<InstallPrompt />);

    const dismissButton = screen.getByRole('button', { name: /dismiss/i });
    fireEvent.click(dismissButton);

    expect(screen.queryByText('Install Teams Elevated')).not.toBeInTheDocument();
  });

  test('can be dismissed on Android (native prompt)', () => {
    mockState({ isInstallable: true, isAndroid: true });

    render(<InstallPrompt />);

    const dismissButton = screen.getByRole('button', { name: /dismiss/i });
    fireEvent.click(dismissButton);

    expect(screen.queryByText('Install Teams Elevated for quick access')).not.toBeInTheDocument();
  });

  test('dismiss persists to localStorage and hides on remount', () => {
    mockState({ isInstallable: true, isAndroid: true });

    const { unmount } = render(<InstallPrompt />);
    fireEvent.click(screen.getByRole('button', { name: /dismiss/i }));

    // localStorage should now hold the dismissal timestamp.
    expect(localStorage.getItem('pwa-install-dismissed')).not.toBeNull();

    unmount();

    // Re-render: the persisted dismissal keeps the banner hidden.
    const { container } = render(<InstallPrompt />);
    expect(container.firstChild).toBeNull();
  });

  test('does not render when not installable, not iOS, not Android', () => {
    mockState({});

    const { container } = render(<InstallPrompt />);

    expect(container.firstChild).toBeNull();
  });
});

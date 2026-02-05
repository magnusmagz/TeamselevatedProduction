import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { InstallPrompt } from '../components/InstallPrompt';

// Mock the usePWAInstall hook
jest.mock('../../hooks/usePWAInstall', () => ({
  usePWAInstall: jest.fn(),
}));

import { usePWAInstall } from '../../hooks/usePWAInstall';

const mockUsePWAInstall = usePWAInstall as jest.MockedFunction<typeof usePWAInstall>;

describe('InstallPrompt', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  test('does not render when already installed', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: false,
      isInstalled: true,
      isIOS: false,
      promptInstall: jest.fn(),
    });

    const { container } = render(<InstallPrompt />);

    expect(container.firstChild).toBeNull();
  });

  test('renders iOS-specific instructions on iOS devices', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: false,
      isInstalled: false,
      isIOS: true,
      promptInstall: jest.fn(),
    });

    render(<InstallPrompt />);

    expect(screen.getByText('Install Teams Elevated')).toBeInTheDocument();
    expect(screen.getByText(/Add to Home Screen/)).toBeInTheDocument();
  });

  test('renders install button on Android/Chrome', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: true,
      isInstalled: false,
      isIOS: false,
      promptInstall: jest.fn(),
    });

    render(<InstallPrompt />);

    expect(screen.getByText('Install Teams Elevated for quick access')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Install' })).toBeInTheDocument();
  });

  test('calls promptInstall when install button clicked', () => {
    const mockPromptInstall = jest.fn();
    mockUsePWAInstall.mockReturnValue({
      isInstallable: true,
      isInstalled: false,
      isIOS: false,
      promptInstall: mockPromptInstall,
    });

    render(<InstallPrompt />);

    fireEvent.click(screen.getByRole('button', { name: 'Install' }));

    expect(mockPromptInstall).toHaveBeenCalled();
  });

  test('can be dismissed on iOS', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: false,
      isInstalled: false,
      isIOS: true,
      promptInstall: jest.fn(),
    });

    render(<InstallPrompt />);

    // Find and click dismiss button
    const dismissButton = screen.getByRole('button', { name: /dismiss/i });
    fireEvent.click(dismissButton);

    // Should no longer be visible
    expect(screen.queryByText('Install Teams Elevated')).not.toBeInTheDocument();
  });

  test('can be dismissed on Android', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: true,
      isInstalled: false,
      isIOS: false,
      promptInstall: jest.fn(),
    });

    render(<InstallPrompt />);

    // Find and click dismiss button
    const dismissButton = screen.getByRole('button', { name: /dismiss/i });
    fireEvent.click(dismissButton);

    // Should no longer be visible
    expect(screen.queryByText('Install Teams Elevated for quick access')).not.toBeInTheDocument();
  });

  test('does not render when not installable and not iOS', () => {
    mockUsePWAInstall.mockReturnValue({
      isInstallable: false,
      isInstalled: false,
      isIOS: false,
      promptInstall: jest.fn(),
    });

    const { container } = render(<InstallPrompt />);

    expect(container.firstChild).toBeNull();
  });
});

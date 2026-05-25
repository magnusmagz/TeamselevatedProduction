import { renderHook, act } from '@testing-library/react';
import { usePWAInstall } from './usePWAInstall';

describe('usePWAInstall', () => {
  const originalNavigator = window.navigator;
  const originalMatchMedia = window.matchMedia;

  beforeEach(() => {
    // Reset mocks
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: jest.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: jest.fn(),
        removeListener: jest.fn(),
        addEventListener: jest.fn(),
        removeEventListener: jest.fn(),
        dispatchEvent: jest.fn(),
      })),
    });
  });

  afterEach(() => {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: originalMatchMedia,
    });
  });

  test('returns initial state correctly', () => {
    const { result } = renderHook(() => usePWAInstall());

    expect(result.current.isInstallable).toBe(false);
    expect(result.current.isInstalled).toBe(false);
    expect(result.current.isAndroid).toBe(false);
    expect(typeof result.current.promptInstall).toBe('function');
  });

  test('detects iOS device', () => {
    Object.defineProperty(navigator, 'userAgent', {
      value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
      configurable: true,
    });

    const { result } = renderHook(() => usePWAInstall());

    expect(result.current.isIOS).toBe(true);
    expect(result.current.isAndroid).toBe(false);
  });

  test('detects Android device', () => {
    Object.defineProperty(navigator, 'userAgent', {
      value: 'Mozilla/5.0 (Linux; Android 10; Pixel 4) AppleWebKit/537.36 Chrome/100',
      configurable: true,
    });

    const { result } = renderHook(() => usePWAInstall());

    expect(result.current.isAndroid).toBe(true);
    expect(result.current.isIOS).toBe(false);
  });

  test('detects non-iOS device', () => {
    Object.defineProperty(navigator, 'userAgent', {
      value: 'Mozilla/5.0 (Linux; Android 10)',
      configurable: true,
    });

    const { result } = renderHook(() => usePWAInstall());

    expect(result.current.isIOS).toBe(false);
  });

  test('does not throw when matchMedia is unavailable', () => {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: undefined,
    });

    expect(() => renderHook(() => usePWAInstall())).not.toThrow();
  });

  test('detects standalone mode (installed)', () => {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: jest.fn().mockImplementation((query) => ({
        matches: query === '(display-mode: standalone)',
        media: query,
        onchange: null,
        addListener: jest.fn(),
        removeListener: jest.fn(),
        addEventListener: jest.fn(),
        removeEventListener: jest.fn(),
        dispatchEvent: jest.fn(),
      })),
    });

    const { result } = renderHook(() => usePWAInstall());

    expect(result.current.isInstalled).toBe(true);
  });

  test('handles beforeinstallprompt event', () => {
    const { result } = renderHook(() => usePWAInstall());

    // Initially not installable
    expect(result.current.isInstallable).toBe(false);

    // Simulate beforeinstallprompt event
    act(() => {
      const event = new Event('beforeinstallprompt');
      (event as any).prompt = jest.fn();
      (event as any).userChoice = Promise.resolve({ outcome: 'accepted', platform: 'web' });
      window.dispatchEvent(event);
    });

    expect(result.current.isInstallable).toBe(true);
  });

  test('handles appinstalled event', () => {
    const { result } = renderHook(() => usePWAInstall());

    // First trigger install prompt
    act(() => {
      const promptEvent = new Event('beforeinstallprompt');
      (promptEvent as any).prompt = jest.fn();
      (promptEvent as any).userChoice = Promise.resolve({ outcome: 'accepted', platform: 'web' });
      window.dispatchEvent(promptEvent);
    });

    expect(result.current.isInstallable).toBe(true);
    expect(result.current.isInstalled).toBe(false);

    // Then trigger installed event
    act(() => {
      window.dispatchEvent(new Event('appinstalled'));
    });

    expect(result.current.isInstalled).toBe(true);
    expect(result.current.isInstallable).toBe(false);
  });

  test('promptInstall calls the deferred prompt', async () => {
    const mockPrompt = jest.fn();
    const mockUserChoice = Promise.resolve({ outcome: 'accepted', platform: 'web' });

    const { result } = renderHook(() => usePWAInstall());

    // Trigger beforeinstallprompt
    act(() => {
      const event = new Event('beforeinstallprompt');
      (event as any).prompt = mockPrompt;
      (event as any).userChoice = mockUserChoice;
      window.dispatchEvent(event);
    });

    // Call promptInstall
    await act(async () => {
      await result.current.promptInstall();
    });

    expect(mockPrompt).toHaveBeenCalled();
  });

  test('promptInstall does nothing when no prompt available', async () => {
    const { result } = renderHook(() => usePWAInstall());

    // Should not throw when called without a deferred prompt
    await act(async () => {
      await result.current.promptInstall();
    });

    // No error should occur
    expect(result.current.isInstallable).toBe(false);
  });
});

import React from 'react';
import { render, screen } from '@testing-library/react';
import PushNotificationToggle from './PushNotificationToggle';
import { usePushNotifications, PushState } from '../hooks/usePushNotifications';
import { usePWAInstall } from '../hooks/usePWAInstall';

jest.mock('../hooks/usePushNotifications');
jest.mock('../hooks/usePWAInstall');

const mockPush = usePushNotifications as jest.MockedFunction<typeof usePushNotifications>;
const mockInstall = usePWAInstall as jest.MockedFunction<typeof usePWAInstall>;

function setup(
  state: PushState,
  platform: { isIOS?: boolean; isInstalled?: boolean } = {},
  error: string | null = null
) {
  mockPush.mockReturnValue({ state, busy: false, error, enable: jest.fn(), disable: jest.fn() });
  mockInstall.mockReturnValue({
    isInstallable: false,
    isInstalled: platform.isInstalled ?? false,
    isIOS: platform.isIOS ?? false,
    isAndroid: false,
    promptInstall: jest.fn(),
  });
  render(<PushNotificationToggle />);
}

describe('PushNotificationToggle', () => {
  afterEach(() => jest.resetAllMocks());

  it('offers to turn notifications on when they are available', () => {
    setup('off');
    expect(screen.getByRole('button', { name: /turn on/i })).toBeInTheDocument();
  });

  it('offers to turn them off once they are on', () => {
    setup('on');
    expect(screen.getByRole('button', { name: /turn off/i })).toBeInTheDocument();
    expect(screen.getByText(/notifications are on for this device/i)).toBeInTheDocument();
  });

  /**
   * The one that decides whether push reaches most families or almost none.
   *
   * On iPhone, PushManager only exists inside a PWA added to the Home Screen, so
   * without this the control would simply be absent and the person would
   * reasonably conclude the feature is broken.
   */
  it('tells an iPhone user the one step that unlocks notifications', () => {
    setup('unsupported', { isIOS: true, isInstalled: false });

    expect(screen.getByText(/add to home screen/i)).toBeInTheDocument();
    expect(screen.getByText(/one step first on iphone/i)).toBeInTheDocument();
  });

  it('does not show the install step once the iOS app is installed', () => {
    setup('unsupported', { isIOS: true, isInstalled: true });

    expect(screen.queryByText(/add to home screen/i)).not.toBeInTheDocument();
    expect(screen.getByText(/does not support notifications/i)).toBeInTheDocument();
  });

  /** Only the person can undo a denial, so do not offer a button that cannot work. */
  it('explains a browser-level block instead of offering a dead button', () => {
    setup('denied');

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText(/blocked for this site/i)).toBeInTheDocument();
    expect(screen.getByText(/keep emailing you/i)).toBeInTheDocument();
  });

  /** Nothing the person can act on, so show nothing rather than a broken control. */
  it('renders nothing when the server has no push keys', () => {
    const { container } = (() => {
      setup('unconfigured');
      return { container: document.body };
    })();

    expect(container.querySelector('h3')).toBeNull();
  });

  /**
   * The reason this exists: the control used to swallow every failure and flash
   * back to "Turn on" with nothing on screen and nothing in the console. A
   * person hitting that has no way forward, and neither does whoever supports
   * them.
   */
  it('says what went wrong instead of silently flashing', () => {
    setup('off', {}, 'Your session has expired. Sign in again, then turn notifications on.');

    expect(screen.getByRole('alert')).toHaveTextContent(/session has expired/i);
  });

  it('does not claim notifications are on while reporting an error', () => {
    setup('on', {}, 'Could not save the registration (error 500).');

    expect(screen.queryByText(/notifications are on for this device/i)).not.toBeInTheDocument();
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  it('shows no alert when nothing has gone wrong', () => {
    setup('on');

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByText(/notifications are on for this device/i)).toBeInTheDocument();
  });

  it('always says this device, never implies an account-wide setting', () => {
    setup('off');
    expect(screen.getByText(/on this device/i)).toBeInTheDocument();
  });
});

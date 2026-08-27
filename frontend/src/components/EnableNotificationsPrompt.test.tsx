import React from 'react';
import { render, screen } from '@testing-library/react';
import EnableNotificationsPrompt from './EnableNotificationsPrompt';
import { usePushNotifications, PushState } from '../hooks/usePushNotifications';
import { usePWAInstall } from '../hooks/usePWAInstall';

jest.mock('../hooks/usePushNotifications');
jest.mock('../hooks/usePWAInstall');

const mockPush = usePushNotifications as jest.MockedFunction<typeof usePushNotifications>;
const mockInstall = usePWAInstall as jest.MockedFunction<typeof usePWAInstall>;

function setup(state: PushState, platform: { isIOS?: boolean; isInstalled?: boolean } = {}) {
  mockPush.mockReturnValue({ state, busy: false, error: null, enable: jest.fn(), disable: jest.fn() });
  mockInstall.mockReturnValue({
    isInstallable: false,
    isInstalled: platform.isInstalled ?? false,
    isIOS: platform.isIOS ?? false,
    isAndroid: false,
    promptInstall: jest.fn(),
  });
  return render(<EnableNotificationsPrompt />);
}

describe('EnableNotificationsPrompt', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => jest.resetAllMocks());

  it('offers to turn notifications on when they are available and off', () => {
    setup('off');
    expect(screen.getByRole('button', { name: /turn on notifications/i })).toBeInTheDocument();
  });

  it('disappears once notifications are on', () => {
    const { container } = setup('on');
    expect(container).toBeEmptyDOMElement();
  });

  /**
   * A blocked browser cannot be re-prompted by any button — browsers refuse so
   * sites cannot nag. Offering one would be a control that provably does nothing.
   */
  it('does not offer a button that cannot possibly work when blocked', () => {
    const { container } = setup('denied');
    expect(container).toBeEmptyDOMElement();
  });

  it('stays out of the way when the server has no push keys', () => {
    const { container } = setup('unconfigured');
    expect(container).toBeEmptyDOMElement();
  });

  /** On iOS the permission does not exist until the app is on the Home Screen. */
  it('gives an iPhone the install step instead of a button that would fail', () => {
    setup('unsupported', { isIOS: true, isInstalled: false });

    expect(screen.getByText(/add to home screen/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /turn on notifications/i })).not.toBeInTheDocument();
  });

  it('shows nothing on a browser that simply cannot do notifications', () => {
    const { container } = setup('unsupported', { isIOS: false });
    expect(container).toBeEmptyDOMElement();
  });

  /**
   * Dismissal is remembered for two weeks, not forever: a parent dismissing in
   * August may well want this in September when the season starts.
   */
  it('stays hidden while a recent dismissal is still in force', () => {
    localStorage.setItem('te_push_prompt_dismissed_until', String(Date.now() + 86400000));
    const { container } = setup('off');
    expect(container).toBeEmptyDOMElement();
  });

  it('comes back once the dismissal has expired', () => {
    localStorage.setItem('te_push_prompt_dismissed_until', String(Date.now() - 1000));
    setup('off');
    expect(screen.getByRole('button', { name: /turn on notifications/i })).toBeInTheDocument();
  });
});

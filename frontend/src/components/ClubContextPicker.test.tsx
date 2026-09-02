import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ClubContextPicker from './ClubContextPicker';

/**
 * The club picker.
 *
 * The assertion that matters most is the one about ABSENCE: almost every user
 * has exactly one club, and a switcher offering a choice of one is clutter in
 * the nav of a product whose main users are volunteer coaches on phones.
 */

const mockSwitchToContext = jest.fn();
const mockUseOrg = jest.fn();
jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => mockUseOrg(),
  contextLabel: (c: any) => (c ? c.scope_name || `Club ${c.scope_id}` : ''),
}));

const org = (contexts: any[], active: any) => ({
  activeContext: active,
  availableContexts: contexts,
  switchToContext: mockSwitchToContext,
  isClubAdmin: false,
  currentClubId: active?.scope_id ?? null,
});

const TE = { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Teams Elevated' };
const CKU = { role: 'coach', scope_type: 'club', scope_id: 51, scope_name: 'Central Kansas United' };

beforeEach(() => {
  jest.clearAllMocks();
  mockSwitchToContext.mockResolvedValue(undefined);
});

it('renders nothing for a single-club user', () => {
  mockUseOrg.mockReturnValue(org([TE], TE));

  const { container } = render(<ClubContextPicker />);

  expect(container).toBeEmptyDOMElement();
});

it('renders nothing when the user has no context at all', () => {
  mockUseOrg.mockReturnValue(org([], null));

  const { container } = render(<ClubContextPicker />);

  expect(container).toBeEmptyDOMElement();
});

/**
 * Two roles in ONE club is one switch target. `switch-context` takes a
 * scope_id, so listing the club twice would offer a choice that does nothing —
 * and 7 live accounts hold two roles in the same club.
 */
it('lists one entry per club, not per role', async () => {
  mockUseOrg.mockReturnValue(
    org([TE, { ...TE, role: 'coach' }], TE)
  );

  const { container } = render(<ClubContextPicker />);

  expect(container).toBeEmptyDOMElement();
});

it('shows the active club and lists the others', async () => {
  mockUseOrg.mockReturnValue(org([TE, CKU], TE));

  render(<ClubContextPicker />);
  expect(screen.getByRole('button', { name: /switch club/i })).toHaveTextContent('Teams Elevated');

  await userEvent.click(screen.getByRole('button', { name: /switch club/i }));

  const options = screen.getAllByRole('option');
  expect(options.map((o) => o.textContent)).toEqual(['Central Kansas United', 'Teams Elevated']);
  expect(screen.getByRole('option', { name: 'Teams Elevated' })).toHaveAttribute('aria-selected', 'true');
});

it('switches on click and closes', async () => {
  mockUseOrg.mockReturnValue(org([TE, CKU], TE));

  render(<ClubContextPicker />);
  await userEvent.click(screen.getByRole('button', { name: /switch club/i }));
  await userEvent.click(screen.getByRole('option', { name: 'Central Kansas United' }));

  expect(mockSwitchToContext).toHaveBeenCalledWith(51, 'club');
  await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument());
});

it('does not re-switch to the club already active', async () => {
  mockUseOrg.mockReturnValue(org([TE, CKU], TE));

  render(<ClubContextPicker />);
  await userEvent.click(screen.getByRole('button', { name: /switch club/i }));
  await userEvent.click(screen.getByRole('option', { name: 'Teams Elevated' }));

  expect(mockSwitchToContext).not.toHaveBeenCalled();
});

/**
 * `switch-context` checks `user_club_access` directly, so a club reached only
 * through a DERIVED coach role answers 403. That file is on the do-not-modify
 * list, so the refusal has to be visible here rather than looking like a dead
 * button.
 */
it('surfaces a refused switch instead of silently doing nothing', async () => {
  mockSwitchToContext.mockRejectedValue(new Error('You do not have access to this organization'));
  mockUseOrg.mockReturnValue(org([TE, CKU], TE));

  render(<ClubContextPicker />);
  await userEvent.click(screen.getByRole('button', { name: /switch club/i }));
  await userEvent.click(screen.getByRole('option', { name: 'Central Kansas United' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('You do not have access to this organization');
  // The menu stays open: the person needs to see which pick failed.
  expect(screen.getByRole('listbox')).toBeInTheDocument();
});

/**
 * A slim token whose name backfill has not landed (or failed) still gets a
 * usable picker — labelled by id, never blank.
 */
it('labels a club by id when its name is unknown', async () => {
  const nameless = { role: 'coach', scope_type: 'club', scope_id: 99 };
  mockUseOrg.mockReturnValue(org([TE, nameless], TE));

  render(<ClubContextPicker />);
  await userEvent.click(screen.getByRole('button', { name: /switch club/i }));

  expect(screen.getByRole('option', { name: 'Club 99' })).toBeInTheDocument();
});

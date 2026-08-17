import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import ProfileMenu from './ProfileMenu';

jest.mock('../contexts/AuthContext', () => ({ useAuth: jest.fn() }));
jest.mock('../contexts/OrgContext', () => ({ useOrg: jest.fn() }));
jest.mock('../contexts/FinancialPermissionsContext', () => ({ useFinancialPermissions: jest.fn() }));

import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';
import { useFinancialPermissions } from '../contexts/FinancialPermissionsContext';

const mockAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockOrg = useOrg as jest.MockedFunction<typeof useOrg>;
const mockPerms = useFinancialPermissions as jest.MockedFunction<typeof useFinancialPermissions>;

const setup = (opts: {
  jwtRoles?: { role: string }[];
  isParentByGuardianChain?: boolean;
  isClubAdmin?: boolean;
}) => {
  mockAuth.mockReturnValue({
    user: { id: 1, name: 'Jed Phillips', email: 'jed@example.com', roles: opts.jwtRoles ?? [] },
    logout: jest.fn(),
  } as unknown as ReturnType<typeof useAuth>);
  mockOrg.mockReturnValue({ isClubAdmin: opts.isClubAdmin ?? false } as unknown as ReturnType<typeof useOrg>);
  mockPerms.mockReturnValue({
    roles: { is_parent: opts.isParentByGuardianChain ?? false, is_coach: false, is_club_admin: false, is_treasurer: false },
  } as unknown as ReturnType<typeof useFinancialPermissions>);

  render(
    <BrowserRouter>
      <ProfileMenu />
    </BrowserRouter>
  );
  // The menu is a dropdown; open it before asserting on its contents.
  fireEvent.click(screen.getAllByRole('button')[0]);
};

describe('ProfileMenu — parent portal link', () => {
  beforeEach(() => jest.clearAllMocks());

  /**
   * The reported gap: ParentRedirect leaves staff on the staff dashboard, and
   * nothing linked to /parent, so a coach who is also a parent could not reach
   * their own child's schedule, invoices or documents at all.
   */
  it('shows the link to a coach who also holds a parent role', () => {
    setup({ jwtRoles: [{ role: 'coach' }, { role: 'parent' }] });

    expect(screen.getByText('Parent Portal').closest('a')).toHaveAttribute('href', '/parent');
  });

  /** Parent standing is often derived from the guardian chain, not a role row. */
  it('shows the link when only the guardian chain says they are a parent', () => {
    setup({ jwtRoles: [{ role: 'coach' }], isParentByGuardianChain: true });

    expect(screen.getByText('Parent Portal')).toBeInTheDocument();
  });

  /**
   * A link that leads to a bounce is worse than no link — the predicate here must
   * not be broader than ProtectedParentRoute's.
   */
  it('hides the link from staff who are not parents', () => {
    setup({ jwtRoles: [{ role: 'coach' }], isParentByGuardianChain: false });

    expect(screen.queryByText('Parent Portal')).toBeNull();
  });

  it('hides the link from a club admin with no parent standing', () => {
    setup({ jwtRoles: [{ role: 'club_admin' }], isClubAdmin: true });

    expect(screen.queryByText('Parent Portal')).toBeNull();
    expect(screen.getByText('Club Settings')).toBeInTheDocument();
  });

  it('still shows the usual entries', () => {
    setup({ jwtRoles: [{ role: 'coach' }, { role: 'parent' }] });

    expect(screen.getByText('My Profile')).toBeInTheDocument();
    expect(screen.getByText('Sign Out')).toBeInTheDocument();
  });
});

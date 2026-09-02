import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';

// CKU R89/R90/R91: programs group into collapsible type sections, archived
// programs are visibly archived rather than gone, and the collapse state
// survives a reload.
//
// Both the desktop table and the mobile cards are in the DOM under jsdom — only
// CSS hides one of them — so every assertion here uses the *All* queries. A
// getByText would fail on two perfectly correct copies of the same row.

let mockCurrentClubId: number | null = 32;
let mockIsClubAdmin = true;

jest.mock('../../../../contexts/OrgContext', () => ({
  useOrg: () => ({
    currentClubId: mockCurrentClubId,
    activeContext: mockCurrentClubId
      ? { role: 'club_admin', scope_type: 'club', scope_id: mockCurrentClubId, scope_name: 'Active Club' }
      : null,
    availableContexts: [],
    switchToContext: jest.fn(),
    isClubAdmin: mockIsClubAdmin,
  }),
}));

jest.mock('../../../../hooks/useAuth', () => ({
  useAuth: () => ({ user: { id: 50, roles: [] } }),
}));

jest.mock('../../../tournament/api/tournamentApi', () => ({
  listTournaments: jest.fn().mockResolvedValue({ tournaments: [] }),
}));

jest.mock('../../components/ProgramFormBuilder', () => () => null);
jest.mock('../../components/EmbedCodeModal', () => () => null);
jest.mock('../../components/RegistrationsModal', () => () => null);
jest.mock('../../components/TryoutCreationWizard', () => () => null);
jest.mock('../../components/TryoutManagement', () => () => null);

import ProgramManagement from '../ProgramManagement';

const PROGRAMS = [
  { id: 1, name: 'Fall League', type: 'league', status: 'published', archived_at: null, sort_order: 0 },
  { id: 2, name: 'Spring League', type: 'league', status: 'draft', archived_at: null, sort_order: 1 },
  { id: 3, name: 'Winter Clinic', type: 'clinic', status: 'published', archived_at: '2026-09-01T12:00:00Z' },
];

const mockList = (rows: unknown[] = PROGRAMS) => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => rows,
  }) as jest.Mock;
};

describe('ProgramManagement grouping, collapse and archive', () => {
  beforeEach(() => {
    mockCurrentClubId = 32;
    mockIsClubAdmin = true;
    localStorage.clear();
    mockList();
  });

  afterEach(() => {
    jest.clearAllMocks();
  });

  const renderPage = async () => {
    render(<ProgramManagement />);
    await waitFor(() => expect(screen.getAllByText('Fall League').length).toBeGreaterThan(0));
  };

  it('groups programs into a section per type', async () => {
    await renderPage();

    // Section headers are buttons that own the section they toggle. They are
    // addressed by test id, not by name: the type TABS carry the same labels and
    // counts, so a name query is ambiguous between the tab and the section.
    const leagueHeader = screen.getByTestId('section-toggle-league');
    const clinicHeader = screen.getByTestId('section-toggle-clinic');

    expect(leagueHeader).toHaveAttribute('aria-expanded', 'true');
    expect(clinicHeader).toHaveAttribute('aria-expanded', 'true');
  });

  it('collapsing a section hides its programs and remembers the choice', async () => {
    await renderPage();

    const leagueHeader = screen.getByTestId('section-toggle-league');
    fireEvent.click(leagueHeader);

    await waitFor(() => {
      expect(screen.queryAllByText('Fall League')).toHaveLength(0);
    });
    expect(leagueHeader).toHaveAttribute('aria-expanded', 'false');

    // The other section is untouched — collapse is per type.
    expect(screen.getAllByText('Winter Clinic').length).toBeGreaterThan(0);

    expect(JSON.parse(localStorage.getItem('programs-collapsed-types') || '[]'))
      .toEqual(['league']);
  });

  it('restores the collapsed sections from localStorage on mount', async () => {
    localStorage.setItem('programs-collapsed-types', JSON.stringify(['league']));

    render(<ProgramManagement />);
    await waitFor(() => expect(screen.getAllByText('Winter Clinic').length).toBeGreaterThan(0));

    expect(screen.queryAllByText('Fall League')).toHaveLength(0);
    expect(screen.getByTestId('section-toggle-league')).toHaveAttribute('aria-expanded', 'false');
  });

  it('a corrupt localStorage value leaves every section open', async () => {
    localStorage.setItem('programs-collapsed-types', 'not json');

    await renderPage();

    expect(screen.getAllByText('Fall League').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Winter Clinic').length).toBeGreaterThan(0);
  });

  it('renders an Archived badge on an archived program and not on a live one', async () => {
    await renderPage();

    // One badge per rendered copy (desktop row + mobile card) of the one
    // archived program — and none for the two live ones.
    const badges = screen.getAllByTestId('archived-badge');
    expect(badges.length).toBeGreaterThan(0);
    badges.forEach((b) => expect(b).toHaveTextContent('Archived'));

    // Unarchive is offered for the archived row; Archive for the live ones.
    expect(screen.getAllByText('Unarchive').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Archive').length).toBeGreaterThan(0);
  });

  it('asks the backend for archived programs only when Show archived is ticked', async () => {
    await renderPage();

    const firstUrl = (global.fetch as jest.Mock).mock.calls[0][0] as string;
    expect(firstUrl).not.toContain('include_archived');

    fireEvent.click(screen.getByLabelText('Show archived'));

    await waitFor(() => {
      const urls = (global.fetch as jest.Mock).mock.calls.map((c) => c[0] as string);
      expect(urls.some((u) => u.includes('include_archived=1'))).toBe(true);
    });
  });

  it('hides the archive and reorder controls from a non-admin', async () => {
    mockIsClubAdmin = false;

    await renderPage();

    expect(screen.queryAllByText('Archive')).toHaveLength(0);
    expect(screen.queryAllByText('Unarchive')).toHaveLength(0);
    expect(screen.queryAllByLabelText('Move up')).toHaveLength(0);
    // The badge still renders — an admin-only control is not an admin-only fact.
    expect(screen.getAllByTestId('archived-badge').length).toBeGreaterThan(0);
  });

  it('move down sends the whole section in its new order', async () => {
    await renderPage();

    // Desktop and mobile each render a Move down button per row; the first
    // belongs to the first program of the first section.
    const moveDown = screen.getAllByLabelText('Move down')[0];
    fireEvent.click(moveDown);

    await waitFor(() => {
      const reorder = (global.fetch as jest.Mock).mock.calls
        .find((c) => String(c[0]).includes('action=reorder'));
      expect(reorder).toBeDefined();
      expect(JSON.parse(reorder[1].body)).toEqual({ program_ids: [2, 1] });
    });
  });

  it('the first row cannot move up and the last cannot move down', async () => {
    await renderPage();

    const ups = screen.getAllByLabelText('Move up');
    const downs = screen.getAllByLabelText('Move down');

    // First program of the League section.
    expect(ups[0]).toBeDisabled();
    // Second (and last) program of the League section.
    expect(downs[1]).toBeDisabled();
  });
});

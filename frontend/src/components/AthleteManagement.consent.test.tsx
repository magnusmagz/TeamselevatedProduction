import React from 'react';
import { render, screen, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import { AthleteListContent } from './AthleteManagement';

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 32 }),
}));

import { consentStatusMeta, consentStatusRank, CONSENT_STATUS_ORDER } from '../utils/consentStatus';

/**
 * The staff-facing consent column.
 *
 * What matters here is that a club can SEE who still owes consent. The failure
 * this guards against is the quiet one: an athlete with no consent record
 * rendering as an empty cell, which reads as "fine" rather than "nobody has
 * agreed to anything for this child".
 */

const athletes = [
  { id: 1, first_name: 'Anna', last_name: 'Aaron' },
  { id: 2, first_name: 'Ben', last_name: 'Brown' },
  { id: 3, first_name: 'Cara', last_name: 'Cross' },
] as any[];

const baseProps: any = {
  athletes,
  loading: false,
  searchTerm: '',
  setSearchTerm: () => {},
  filterGender: '',
  setFilterGender: () => {},
  filterGrade: '',
  setFilterGrade: () => {},
  handleAddAthlete: () => {},
  handleEditAthlete: () => {},
  handleManageGuardians: () => {},
  handleArchiveAthlete: () => {},
  calculateAge: () => 12,
  athleteTeams: {},
  showTeamSelector: null,
  setShowTeamSelector: () => {},
  availableTeams: [],
  handleAddToTeam: () => {},
};

/**
 * Assertions are scoped to <tbody> throughout: the column header carries a
 * filter <select> whose options use the SAME labels as the badges, so an
 * unscoped getByText matches the option as well as the cell.
 */
const renderList = (consentByAthlete?: Record<number, string>) => {
  const { container } = render(
    <MemoryRouter>
      <AthleteListContent {...baseProps} consentByAthlete={consentByAthlete} />
    </MemoryRouter>
  );
  return within(container.querySelector('tbody') as HTMLElement);
};

describe('AthleteManagement consent column', () => {
  test('renders a status for every athlete, including those with nothing on file', () => {
    const body = renderList({ 1: 'verified', 2: 'signup_only' });

    expect(body.getByText('Verified')).toBeInTheDocument();
    expect(body.getByText('Sign-up only')).toBeInTheDocument();
    // Athlete 3 has no entry at all. It must still say something.
    expect(body.getByText('Unknown')).toBeInTheDocument();
  });

  test('an athlete with no consent record reads as "Not on file", not as blank', () => {
    const body = renderList({ 1: 'none', 2: 'none', 3: 'none' });

    expect(body.getAllByText('Not on file')).toHaveLength(3);
  });

  test('the column header offers every status as a filter', () => {
    renderList({ 1: 'verified' });

    // The Consent header carries a select whose options are the five rungs.
    const header = screen.getByRole('button', { name: /Consent/i }).closest('th');
    expect(header).not.toBeNull();
    const select = within(header as HTMLElement).getByRole('combobox');
    for (const status of CONSENT_STATUS_ORDER) {
      expect(
        within(select).getByRole('option', { name: consentStatusMeta(status).label })
      ).toBeInTheDocument();
    }
  });
});

describe('consentStatus ladder', () => {
  /**
   * Ascending rank must put what the club has to chase FIRST — sorting the
   * column is the whole point of having it.
   */
  test('ranks worst-first so sorting surfaces outstanding families', () => {
    expect(consentStatusRank('none')).toBeLessThan(consentStatusRank('signup_only'));
    expect(consentStatusRank('signup_only')).toBeLessThan(consentStatusRank('confirmed'));
    expect(consentStatusRank('confirmed')).toBeLessThan(consentStatusRank('verified'));
  });

  test('only portal-backed states count as settled', () => {
    expect(consentStatusMeta('verified').outstanding).toBe(false);
    expect(consentStatusMeta('confirmed').outstanding).toBe(false);
    // Real consent, but not tied to an account — still something to chase.
    expect(consentStatusMeta('signup_only').outstanding).toBe(true);
    expect(consentStatusMeta('partial').outstanding).toBe(true);
    expect(consentStatusMeta('none').outstanding).toBe(true);
  });

  /** An unrecognised status must render, not vanish. */
  test('an unknown status is surfaced rather than hidden', () => {
    const meta = consentStatusMeta('something_new');
    expect(meta.label).toBe('something_new');
    expect(meta.outstanding).toBe(true);
  });
});

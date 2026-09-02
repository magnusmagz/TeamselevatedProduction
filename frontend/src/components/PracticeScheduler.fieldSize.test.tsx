import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import PracticeScheduler from './PracticeScheduler';

/**
 * CKU R73 slice 6.3 — the field picker steers a team to a correctly sized
 * pitch, and never blocks one.
 *
 * Only the picker is under test; date generation is deliberately untouched by
 * this slice and is covered by practiceDates.test.ts.
 *
 * Fixture: Ashford Park has a 9v9 pitch (fits the U12 team), an 11v11 pitch
 * (wrong size) and one with no size recorded.
 */

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

const venues = [{ id: 1, name: 'Ashford Park' }];
const venueDetail = {
  id: 1,
  name: 'Ashford Park',
  fields: [
    { id: 1, name: 'Pitch 1', venue_id: 1 },
    { id: 2, name: 'Pitch 2', venue_id: 1 },
    { id: 3, name: 'North', venue_id: 1 },
  ],
};

const forTeamU12 = {
  team_id: 10,
  age_group: 'U12',
  age_group_label: 'U12',
  recommended_size: '9v9',
  sizing_available: true,
  fields: [
    { id: 1, name: 'Pitch 1', venue_id: 1, venue_name: 'Ashford Park', display_name: 'Ashford Park - Pitch 1', field_size: '9v9', size_match: true },
    { id: 3, name: 'North', venue_id: 1, venue_name: 'Ashford Park', display_name: 'Ashford Park - North', field_size: null, size_match: null },
    { id: 2, name: 'Pitch 2', venue_id: 1, venue_name: 'Ashford Park', display_name: 'Ashford Park - Pitch 2', field_size: '11v11', size_match: false },
  ],
};

const mountWith = (forTeam: any | null) => {
  mockFetch.mockReset();
  localStorage.setItem('auth_token', 'test-token');
  mockFetch.mockImplementation((url: string) => {
    if (url.includes('action=for-team')) {
      return forTeam
        ? Promise.resolve({ ok: true, json: () => Promise.resolve(forTeam) })
        : Promise.resolve({ ok: false, json: () => Promise.resolve({}) });
    }
    if (url.includes('venues-gateway.php?id=')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve(venueDetail) });
    }
    if (url.includes('venues-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve(venues) });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ events: [] }) });
  });

  return render(<PracticeScheduler team={{ id: 10, name: 'Sharks U12' }} onClose={() => {}} />);
};

// The labels in this form are not associated with their controls, so the two
// selects are found by their own placeholder option rather than by label.
const selectHolding = (placeholder: string): HTMLSelectElement =>
  screen.getByText(placeholder).closest('select') as HTMLSelectElement;

const fieldSelect = async (): Promise<HTMLSelectElement> => {
  const facility = selectHolding('Select a facility...');
  await waitFor(() => expect(within(facility).getByText('Ashford Park')).toBeInTheDocument());
  fireEvent.change(facility, { target: { value: '1' } });
  return selectHolding('Select a field...');
};

describe('practice scheduler field picker', () => {
  it('groups the fitting fields first, under a hint naming the age group and size', async () => {
    mountWith(forTeamU12);
    const select = await fieldSelect();

    await waitFor(() => {
      expect(within(select).getByRole('group', { name: /fits U12 \(9v9\)/i })).toBeInTheDocument();
    });

    const groups = Array.from(select.querySelectorAll('optgroup')).map((g) => g.label);
    expect(groups[0]).toMatch(/fits U12 \(9v9\)/i);
    expect(groups).toEqual(expect.arrayContaining([expect.stringMatching(/No size recorded/i), 'Other sizes']));
    expect(groups.indexOf('Other sizes')).toBe(groups.length - 1);
  });

  /** A mismatch is offered, not hidden — the club may know better. */
  it('still offers a wrong-sized field, under Other sizes', async () => {
    mountWith(forTeamU12);
    const select = await fieldSelect();

    await waitFor(() => expect(select.querySelectorAll('optgroup').length).toBeGreaterThan(0));
    const other = Array.from(select.querySelectorAll('optgroup')).find((g) => g.label === 'Other sizes');
    expect(within(other as HTMLElement).getByText(/Pitch 2 \(11v11\)/)).toBeInTheDocument();
    expect((other as HTMLOptGroupElement).querySelector('option')).not.toBeDisabled();
  });

  it('warns after a mismatched field is chosen, without blocking it', async () => {
    mountWith(forTeamU12);
    const select = await fieldSelect();
    await waitFor(() => expect(select.querySelectorAll('optgroup').length).toBeGreaterThan(0));

    fireEvent.change(select, { target: { value: '2' } });

    expect(await screen.findByText(/Pitch 2 is 11v11/)).toBeInTheDocument();
    expect(screen.getByText(/U12 normally plays 9v9/)).toBeInTheDocument();
    expect(select).toHaveValue('2');
  });

  it('says nothing once a fitting field is chosen', async () => {
    mountWith(forTeamU12);
    const select = await fieldSelect();
    await waitFor(() => expect(select.querySelectorAll('optgroup').length).toBeGreaterThan(0));

    fireEvent.change(select, { target: { value: '1' } });
    expect(screen.queryByText(/normally plays/)).not.toBeInTheDocument();
  });

  /**
   * The endpoint 404s or the column is not live yet. The picker must look
   * exactly as it did before this slice — a flat list of every field, no
   * headings, no warnings — rather than showing an error or an empty list.
   */
  it('degrades to the flat list when the fit question is never answered', async () => {
    mountWith(null);
    const select = await fieldSelect();

    await waitFor(() => expect(within(select).getByText('Pitch 1')).toBeInTheDocument());
    expect(select.querySelectorAll('optgroup')).toHaveLength(0);
    expect(within(select).getByText('Pitch 2')).toBeInTheDocument();
    expect(within(select).getByText('North')).toBeInTheDocument();
    expect(screen.queryByText(/normally plays/)).not.toBeInTheDocument();
  });
});

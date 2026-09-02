import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import AthleteForm from './AthleteForm';

/**
 * "Setting a different guardian as primary does not stick" (CKU, R78).
 *
 * The form had no primary control. It posted
 * `is_primary_contact: i === 0 ? 1 : 0` for every crew member on EVERY athlete
 * save, and the crew list is fetched primary-first — so the current primary was
 * position 0 and got written straight back. A promotion made in the Crew modal
 * survived only until the next time anyone saved the athlete.
 *
 * These tests fix the flag to the crew member, not to the card's position.
 */

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 51, activeContext: null }),
}));

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

// Guardian B is the primary, and B is at index 1. Under the old code this saved
// as "A is primary" — the exact reported bug.
const athlete = {
  id: 42,
  first_name: 'Sofia',
  last_name: 'Devora',
  date_of_birth: '2013-04-02',
  gender: 'Female',
  guardians: [
    {
      id: 11,
      first_name: 'Alex',
      last_name: 'Devora',
      email: 'alex@example.com',
      mobile_phone: '555-0101',
      relationship_type: 'Parent',
      is_primary_contact: false,
    },
    {
      id: 12,
      first_name: 'Bianca',
      last_name: 'Devora',
      email: 'bianca@example.com',
      mobile_phone: '555-0102',
      relationship_type: 'Parent',
      is_primary_contact: true,
    },
  ],
};

const ok = (body: any = { success: true }) =>
  Promise.resolve({ ok: true, json: () => Promise.resolve(body) });

beforeEach(() => {
  mockFetch.mockReset();
  mockFetch.mockImplementation((url: string) => {
    if (url.includes('medical-gateway.php')) {
      return ok({ success: true, medical: { exists: false } });
    }
    if (url.includes('athletes-gateway.php')) {
      return ok({ success: true, id: 42, athlete_id: 42 });
    }
    return ok();
  });
  window.localStorage.setItem('auth_token', 'test-token');
  // jsdom has no alert; the form ends a successful save with one.
  window.alert = jest.fn();
});

const renderForm = () =>
  render(<AthleteForm athlete={athlete} onSubmit={jest.fn()} onClose={jest.fn()} />);

/** Step 1 → 2 → 3, then past the 500ms double-click guard on step 3. */
const goToLastStepAndSave = async () => {
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  await screen.findByRole('button', { name: 'Update Athlete' });

  // handleSubmit ignores a submit landing within 500ms of step 3 rendering, so a
  // double-click on Next cannot save before the user sees the step.
  await act(async () => {
    await new Promise(resolve => setTimeout(resolve, 600));
  });

  fireEvent.click(screen.getByRole('button', { name: 'Update Athlete' }));
};

/** Every POST to the guardian gateway, as {name, is_primary_contact}. */
const guardianPosts = () =>
  mockFetch.mock.calls
    .filter(([url]: [string]) => url.includes('guardian-gateway.php'))
    .map(([, init]: [string, any]) => {
      const body = JSON.parse(init.body);
      return { name: body.first_name, is_primary_contact: body.is_primary_contact };
    });

describe('AthleteForm primary crew member (R78)', () => {
  it('shows the primary label and radio on the crew member who is actually primary', async () => {
    renderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));

    await screen.findByText('Primary Crew Member');

    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    expect(radios).toHaveLength(2);
    // Bianca sits at index 1 and is the one flagged — position decides nothing.
    expect(radios[0].checked).toBe(false);
    expect(radios[1].checked).toBe(true);

    // The card at index 0 is NOT labelled primary just for being first.
    expect(screen.getByText('Crew Member 1')).toBeInTheDocument();
  });

  it('saves the flagged crew member as primary, not the first card', async () => {
    renderForm();
    await goToLastStepAndSave();

    await waitFor(() => expect(guardianPosts()).toHaveLength(2));

    expect(guardianPosts()).toEqual([
      { name: 'Alex', is_primary_contact: 0 },
      { name: 'Bianca', is_primary_contact: 1 },
    ]);
  });

  it('promotes a different crew member when the radio is changed', async () => {
    renderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Primary Crew Member');

    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    fireEvent.click(radios[0]);

    // Exactly one primary — promoting Alex demotes Bianca.
    expect((screen.getAllByRole('radio')[0] as HTMLInputElement).checked).toBe(true);
    expect((screen.getAllByRole('radio')[1] as HTMLInputElement).checked).toBe(false);

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByRole('button', { name: 'Update Athlete' });
    await act(async () => {
      await new Promise(resolve => setTimeout(resolve, 600));
    });
    fireEvent.click(screen.getByRole('button', { name: 'Update Athlete' }));

    await waitFor(() => expect(guardianPosts()).toHaveLength(2));

    expect(guardianPosts()).toEqual([
      { name: 'Alex', is_primary_contact: 1 },
      { name: 'Bianca', is_primary_contact: 0 },
    ]);
  });
});

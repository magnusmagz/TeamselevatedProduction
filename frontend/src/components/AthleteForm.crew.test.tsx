import React from 'react';
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import AthleteForm from './AthleteForm';

/**
 * THERE IS NO PRIMARY CREW MEMBER. Crew members are equal.
 *
 * Product rule, reaffirmed by Maggie 2026-09-02. This replaces
 * `AthleteForm.primary.test.tsx`, which pinned the opposite: that the form
 * carried a radio group electing one crew member and posted the choice as
 * `is_primary_contact`. Removing that test without adding this one would leave
 * the control free to come back the next time someone wants "the parent to
 * contact".
 *
 * The two things worth pinning are the SCREEN and the PAYLOAD, and they are
 * separate failures. The form once had no control at all and still posted
 * `is_primary_contact: i === 0 ? 1 : 0` on every save — an invisible claim about
 * a family, made by a form with nothing on it about primaries.
 */

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 51, activeContext: null }),
}));

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

// Two crew members. The fetched order is the only order there is — nothing in
// the payload or on screen may rank one above the other.
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
    },
    {
      id: 12,
      first_name: 'Bianca',
      last_name: 'Devora',
      email: 'bianca@example.com',
      mobile_phone: '555-0102',
      relationship_type: 'Parent',
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

  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 600));
  });

  fireEvent.click(screen.getByRole('button', { name: 'Update Athlete' }));

  // The save is a chain of awaited fetches; the crew POSTs happen after the
  // athlete PUT resolves.
  await waitFor(() => expect(guardianPosts().length).toBeGreaterThan(0));
};

/** Every POST body sent to the guardian gateway. */
const guardianPosts = () =>
  mockFetch.mock.calls
    .filter(([url]: [string]) => url.includes('guardian-gateway.php'))
    .map(([, init]: [string, any]) => JSON.parse(init.body));

describe('AthleteForm crew members are equal', () => {
  it('numbers the cards and offers no way to rank one crew member above another', async () => {
    renderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));

    // Positions, for the required-field messages to name. Not ranks.
    await screen.findByText('Crew Member 1');
    expect(screen.getByText('Crew Member 2')).toBeInTheDocument();

    // No heading elects anyone, and there is no control to elect them with.
    expect(screen.queryByText(/primary crew member/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/primary contact/i)).not.toBeInTheDocument();
    expect(screen.queryAllByRole('radio', { name: /primary/i })).toHaveLength(0);
  });

  it('sends no is_primary_contact on save, for any crew member', async () => {
    renderForm();
    await goToLastStepAndSave();

    const posts = guardianPosts();
    expect(posts.map((p) => p.first_name)).toEqual(['Alex', 'Bianca']);

    // ⚠️ ABSENT, not false. The gateway leaves the column alone when the key is
    // missing; a `0` is still a claim, and the gateway used to coerce a missing
    // key into one — which is how a form with no primary control kept writing
    // primaries.
    posts.forEach((p) => {
      expect(p).not.toHaveProperty('is_primary_contact');
    });
  });

  it('treats every crew member as financially responsible, not just the first', async () => {
    renderForm();
    await goToLastStepAndSave();

    // This rode on the primary flag. Whoever happened to be first was the only
    // person the club could bill, which is the same ranking wearing a different
    // field name.
    expect(guardianPosts().map((p) => p.financial_responsible)).toEqual([1, 1]);
  });
});

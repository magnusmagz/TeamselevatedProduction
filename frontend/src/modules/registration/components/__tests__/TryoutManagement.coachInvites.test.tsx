/**
 * CKU report R86 — "colour-coded coach invited player" (slice 8.2).
 *
 * Two things are covered, and they are the two halves of the report: the button
 * a coach presses on an athlete's row, and the table the director reads back.
 *
 * The colour state is asserted through `data-invited` and the class, never
 * through the colour alone — a control that only changes hue is not a state a
 * person can read, which is why the label changes too and is asserted with it.
 */
import React from 'react';
import { render, screen, fireEvent, within, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import TryoutManagement, {
  coachInviteNamesFor,
  coachInviteLabel,
  coachInviteTitle,
  coachInviteButtonClass,
} from '../TryoutManagement';
import { CoachInvite } from '../../types';

let mockIsClubAdmin = true;

jest.mock('../../../../contexts/OrgContext', () => ({
  useOrg: () => ({
    currentClubId: 51,
    activeContext: null,
    get isClubAdmin() {
      return mockIsClubAdmin;
    },
  }),
}));

jest.mock('../EvaluationModal', () => ({
  __esModule: true,
  default: () => null,
}));

const REGISTRATIONS = [
  {
    id: 1000,
    athlete_id: 100,
    first_name: 'Maya',
    last_name: 'Rivera',
    date_of_birth: '2013-05-04',
    tryout_status: 'registered',
    tryout_number: '12',
    overall_score: null,
    evaluation_count: '0',
  },
  {
    id: 2000,
    athlete_id: 200,
    first_name: 'Sam',
    last_name: 'Alvarez',
    date_of_birth: '2013-03-02',
    tryout_status: 'registered',
    tryout_number: '13',
    overall_score: null,
    evaluation_count: '0',
  },
];

const invite = (over: Partial<CoachInvite> = {}): CoachInvite => ({
  id: 1,
  registration_id: 1000,
  athlete_id: 100,
  athlete_name: 'Maya Rivera',
  team_id: 7,
  team_name: 'Thunder U12',
  invited_by: 61,
  invited_by_name: 'Dana Fields',
  invited_at: '2026-09-02T10:00:00Z',
  email_sent_at: '2026-09-02T10:00:01Z',
  status: 'invited',
  rostered: false,
  ...over,
});

const jsonResponse = (body: unknown, status = 200) =>
  Promise.resolve({
    ok: status < 400,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response);

/** @param invites what `path=coach-invites` answers; a number answers that status. */
const mockFetch = (invites: CoachInvite[] | number) => {
  const impl = (input: unknown) => {
    const url = String(input);
    if (url.includes('path=coach-invites')) {
      return typeof invites === 'number'
        ? jsonResponse({ error: 'nope' }, invites)
        : jsonResponse(invites);
    }
    if (url.includes('path=coach-invite')) return jsonResponse({ invited: true, email_sent: true });
    if (url.includes('path=registrations')) return jsonResponse(REGISTRATIONS);
    return jsonResponse([]);
  };
  // CRA's jest config sets `resetMocks: true`, so the stub is rebuilt per test.
  (global as any).fetch = jest.fn(impl);
  return (global as any).fetch as jest.Mock;
};

const renderScreen = async () => {
  render(
    <TryoutManagement
      programId={10}
      programName="Fall 2026 Tryouts"
      currentUserId={61}
      onClose={() => {}}
    />
  );
  await screen.findByText('Maya Rivera');
};

/** The invite button on one athlete's row. */
const buttonFor = (athlete: string): HTMLElement => {
  const row = screen.getByText(athlete).closest('tr') as HTMLElement;
  return within(row).getByRole('button', { name: /invite/i });
};

beforeEach(() => {
  mockIsClubAdmin = true;
});

describe('coach invite button state (pure)', () => {
  it('names every coach who has claimed a registrant, de-duplicated', () => {
    const invites = [
      invite({ id: 1, invited_by_name: 'Dana Fields' }),
      invite({ id: 2, invited_by_name: 'Rob Hale' }),
      invite({ id: 3, invited_by_name: 'Dana Fields' }),
      invite({ id: 4, registration_id: 2000, invited_by_name: 'Ann Poe' }),
    ];
    expect(coachInviteNamesFor(invites, 1000)).toEqual(['Dana Fields', 'Rob Hale']);
    expect(coachInviteNamesFor(invites, 2000)).toEqual(['Ann Poe']);
  });

  it('a withdrawn claim no longer colours the button', () => {
    const invites = [invite({ status: 'withdrawn' })];
    expect(coachInviteNamesFor(invites, 1000)).toEqual([]);
  });

  it('labels the claim, and names everyone in the hover text', () => {
    expect(coachInviteLabel([])).toBe('Invite to my team');
    expect(coachInviteLabel(['Dana Fields'])).toBe('Invited by Dana Fields');
    expect(coachInviteLabel(['Dana Fields', 'Rob Hale'])).toBe('Invited by 2 coaches');

    // The label collapses at two; the title never does.
    expect(coachInviteTitle(['Dana Fields', 'Rob Hale'])).toBe('Invited by Dana Fields, Rob Hale');
    expect(coachInviteTitle([])).toBe('Invite this player to your team');
  });

  it('uses a different colour once claimed', () => {
    expect(coachInviteButtonClass(true)).not.toBe(coachInviteButtonClass(false));
    expect(coachInviteButtonClass(true)).toContain('amber');
  });
});

describe('coach invite button (rendered)', () => {
  it('is uncoloured and unclaimed before anyone invites', async () => {
    mockFetch([]);
    await renderScreen();

    const button = buttonFor('Maya Rivera');
    expect(button).toHaveAttribute('data-invited', 'false');
    expect(button).toHaveTextContent('Invite to my team');
  });

  it('turns a distinct colour and names the coach once claimed by anyone', async () => {
    mockFetch([invite()]);
    await renderScreen();

    const claimed = buttonFor('Maya Rivera');
    expect(claimed).toHaveAttribute('data-invited', 'true');
    expect(claimed).toHaveTextContent('Invited by Dana Fields');
    expect(claimed).toHaveAttribute('title', 'Invited by Dana Fields');
    expect(claimed.className).toContain('amber');

    // The claim is per registrant — the other athlete is untouched.
    const untouched = buttonFor('Sam Alvarez');
    expect(untouched).toHaveAttribute('data-invited', 'false');
  });

  it('stays pressable for a second coach', async () => {
    mockFetch([invite()]);
    await renderScreen();
    expect(buttonFor('Maya Rivera')).not.toBeDisabled();
  });

  it('posts the registration id and never a coach id', async () => {
    const fetchMock = mockFetch([]);
    await renderScreen();

    fireEvent.click(buttonFor('Maya Rivera'));

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(c => String(c[0]).includes('path=coach-invite&') ||
        (String(c[0]).endsWith('path=coach-invite') && c[1]?.method === 'POST'));
      expect(call).toBeTruthy();
      const body = JSON.parse(call![1].body);
      expect(body.registration_id).toBe(1000);
      // invited_by is resolved from the token server-side. Sending one here
      // would make the director's table a list of claims anyone could type.
      expect(body).not.toHaveProperty('invited_by');
    });
  });

  it('sends the Authorization header on every call', async () => {
    window.localStorage.setItem('auth_token', 'test-token');
    const fetchMock = mockFetch([]);
    await renderScreen();
    fireEvent.click(buttonFor('Maya Rivera'));

    await waitFor(() => {
      const invited = fetchMock.mock.calls.filter(c => String(c[0]).includes('coach-invite'));
      expect(invited.length).toBeGreaterThan(0);
      invited.forEach(call => {
        expect(call[1].headers.Authorization).toBe('Bearer test-token');
      });
    });
    window.localStorage.removeItem('auth_token');
  });

  it('says so, rather than doing nothing, before migration 087 is applied', async () => {
    mockFetch(503);
    await renderScreen();

    expect(screen.getAllByText('Invites not available yet').length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: /invite to my team/i })).toBeNull();
  });
});

describe('the director table', () => {
  const openTab = async () => {
    await renderScreen();
    fireEvent.click(screen.getByRole('button', { name: 'Coach invites' }));
    await screen.findByLabelText('Filter coach invites by status');
  };

  const rowsByAthlete = (): string[] =>
    screen
      .getAllByRole('row')
      .slice(1)
      .map(row => (within(row).getAllByRole('cell')[0].textContent || '').trim());

  it('is hidden from a coach and shown to a club admin', async () => {
    mockIsClubAdmin = false;
    mockFetch([]);
    await renderScreen();
    expect(screen.queryByRole('button', { name: 'Coach invites' })).toBeNull();
  });

  it('lists every coach claim with the coach, the team and what happened next', async () => {
    mockFetch([
      invite({ id: 1, invited_by_name: 'Dana Fields', rostered: true }),
      invite({
        id: 2,
        registration_id: 2000,
        athlete_name: 'Sam Alvarez',
        invited_by_name: 'Rob Hale',
        team_name: null,
        email_sent_at: null,
        status: 'declined',
      }),
    ]);
    await openTab();

    const maya = screen.getByText('Maya Rivera').closest('tr') as HTMLElement;
    expect(within(maya).getByText('Dana Fields')).toBeInTheDocument();
    expect(within(maya).getByText('Thunder U12')).toBeInTheDocument();
    expect(within(maya).getByText('Sent')).toBeInTheDocument();
    expect(within(maya).getByText('Yes')).toBeInTheDocument();

    const sam = screen.getByText('Sam Alvarez').closest('tr') as HTMLElement;
    // "Not sent" and "No team yet" are real answers and must not read as blank.
    expect(within(sam).getByText('Not sent')).toBeInTheDocument();
    expect(within(sam).getByText('No team yet')).toBeInTheDocument();
    expect(within(sam).getByText('Declined')).toBeInTheDocument();
  });

  it('filters by status, and keeps the control visible when nothing matches', async () => {
    mockFetch([
      invite({ id: 1 }),
      invite({ id: 2, registration_id: 2000, athlete_name: 'Sam Alvarez', status: 'withdrawn' }),
    ]);
    await openTab();
    expect(rowsByAthlete()).toEqual(['Maya Rivera', 'Sam Alvarez']);

    fireEvent.change(screen.getByLabelText('Filter coach invites by status'), {
      target: { value: 'withdrawn' },
    });
    expect(rowsByAthlete()).toEqual(['Sam Alvarez']);

    fireEvent.change(screen.getByLabelText('Filter coach invites by status'), {
      target: { value: 'declined' },
    });
    expect(screen.getByText('No coach invites match this filter.')).toBeInTheDocument();
    // The choice that emptied the list must still be undoable.
    expect(screen.getByLabelText('Filter coach invites by status')).toBeInTheDocument();
  });

  it('distinguishes "nobody has invited anyone" from "not available yet"', async () => {
    mockFetch([]);
    await openTab();
    expect(screen.getByText('No coach has invited a player yet.')).toBeInTheDocument();
  });

  it('degrades to a sentence before migration 087 is applied', async () => {
    mockFetch(503);
    await renderScreen();
    fireEvent.click(screen.getByRole('button', { name: 'Coach invites' }));

    expect(
      await screen.findByText(/Coach invites are not available yet/)
    ).toBeInTheDocument();
  });

  it('posts a status change with the invite id', async () => {
    const fetchMock = mockFetch([invite()]);
    await openTab();

    fireEvent.click(screen.getByRole('button', { name: 'Withdraw' }));

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(c => String(c[0]).includes('coach-invite-status'));
      expect(call).toBeTruthy();
      expect(JSON.parse(call![1].body)).toEqual({ invite_id: 1, status: 'withdrawn' });
    });
  });
});

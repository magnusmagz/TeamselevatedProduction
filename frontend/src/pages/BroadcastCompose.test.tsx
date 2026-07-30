import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import BroadcastCompose from './BroadcastCompose';

let mockIsClubAdmin = true;

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({
    currentClubId: 32,
    isClubAdmin: mockIsClubAdmin,
    activeContext: { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Club' },
  }),
}));

global.fetch = jest.fn();

const TEAMS = [
  { id: 7, name: 'Eagles U14', athlete_count: 12, guardian_count: 18 },
  { id: 8, name: 'Hawks U12', athlete_count: 10, guardian_count: 15 },
];

/** Route each call by URL so tests don't depend on request ordering. */
const mockApi = (
  preview: any = { total: 142, suppressed: 3, final_count: 139 },
  sendResponse: any = { ok: true, body: { success: true, data: { queued: 139, skipped: 3 } } }
) => {
  (fetch as jest.Mock).mockImplementation((url: string) => {
    if (url.includes('action=groups')) {
      return Promise.resolve({ ok: true, json: async () => ({ groups: TEAMS }) });
    }
    if (url.includes('action=preview-broadcast')) {
      return Promise.resolve({ ok: true, json: async () => ({ success: true, data: preview }) });
    }
    if (url.includes('action=send-broadcast')) {
      return Promise.resolve({
        ok: sendResponse.ok,
        json: async () => sendResponse.body,
      });
    }
    return Promise.resolve({ ok: true, json: async () => ({}) });
  });
};

const bodyOf = (matcher: string) => {
  const call = (fetch as jest.Mock).mock.calls.find((c) => (c[0] as string).includes(matcher));
  if (!call) throw new Error(`no request matching ${matcher}`);
  return JSON.parse(call[1].body);
};

/** Pick a team and let the debounced preview fire. */
const selectTeamAndSettle = async (name = 'Eagles U14') => {
  await screen.findByLabelText(new RegExp(name));
  fireEvent.click(screen.getByLabelText(new RegExp(name)));
  await act(async () => {
    jest.advanceTimersByTime(400);
  });
};

/** Click Send and let the resulting state updates flush. */
const clickSend = async () => {
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: /Send broadcast/i }));
  });
};

describe('BroadcastCompose', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    mockIsClubAdmin = true;
    (fetch as jest.Mock).mockClear();
    mockApi();
  });

  afterEach(() => {
    jest.runOnlyPendingTimers();
    jest.useRealTimers();
  });

  // ── E1 / E2 ──────────────────────────────────────────────────────────────
  test('sends via action=send-broadcast with channel sms', async () => {
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    fireEvent.change(screen.getByLabelText(/Message/i), {
      target: { value: 'Practice cancelled tonight.' },
    });
    await clickSend();

    await waitFor(() =>
      expect(
        (fetch as jest.Mock).mock.calls.some((c) => (c[0] as string).includes('send-broadcast'))
      ).toBe(true)
    );

    const body = bodyOf('send-broadcast');
    expect(body.channel).toBe('sms');
    expect(body.team_ids).toEqual([7]);
    expect(body.body).toBe('Practice cancelled tonight.');
  });

  /**
   * The plural forms are what recipient-search's resolve-group takes. Sending them
   * to send-broadcast resolves nobody and returns a cheerful 200 — no error, no
   * messages. This assertion is the tripwire.
   */
  test('sends SINGULAR recipient_types', async () => {
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'Hi' } });
    await clickSend();

    await waitFor(() =>
      expect(
        (fetch as jest.Mock).mock.calls.some((c) => (c[0] as string).includes('send-broadcast'))
      ).toBe(true)
    );

    const { recipient_types } = bodyOf('send-broadcast');
    expect(recipient_types).toEqual(['athlete', 'guardian']);
    expect(recipient_types).not.toContain('athletes');
    expect(recipient_types).not.toContain('guardians');
  });

  test('leaves Coaches unchecked by default', async () => {
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    expect(screen.getByLabelText('Athletes')).toBeChecked();
    expect(screen.getByLabelText('Crew')).toBeChecked();
    expect(screen.getByLabelText('Coaches')).not.toBeChecked();

    expect(bodyOf('preview-broadcast').recipient_types).not.toContain('coach');
  });

  // ── E3 ───────────────────────────────────────────────────────────────────
  test('refetches the preview when the audience changes, debounced', async () => {
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    const afterFirst = (fetch as jest.Mock).mock.calls.filter((c) =>
      (c[0] as string).includes('preview-broadcast')
    ).length;
    expect(afterFirst).toBe(1);

    // Three rapid changes should collapse into ONE more request.
    fireEvent.click(screen.getByLabelText('Coaches'));
    fireEvent.click(screen.getByLabelText('Athletes'));
    fireEvent.click(screen.getByLabelText('Athletes'));
    await act(async () => {
      jest.advanceTimersByTime(400);
    });

    const afterSecond = (fetch as jest.Mock).mock.calls.filter((c) =>
      (c[0] as string).includes('preview-broadcast')
    ).length;
    expect(afterSecond).toBe(2);
  });

  // ── E4 ───────────────────────────────────────────────────────────────────
  test('surfaces the opted-out count rather than dropping it silently', async () => {
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    expect(await screen.findByText(/142 recipients/)).toBeInTheDocument();
    expect(screen.getByText(/3 opted out/)).toBeInTheDocument();
    expect(screen.getByText(/139 will receive/)).toBeInTheDocument();
  });

  // ── E6 ───────────────────────────────────────────────────────────────────
  test('counts a 161-character message as 2 segments', async () => {
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'a'.repeat(160) } });
    expect(screen.queryByText(/segments per recipient/)).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'a'.repeat(161) } });
    expect(screen.getByText(/2 segments per recipient/)).toBeInTheDocument();
  });

  // ── E7 ───────────────────────────────────────────────────────────────────
  test('disables send until there is an audience and a message', async () => {
    render(<BroadcastCompose />);

    const button = () => screen.getByRole('button', { name: /Send broadcast/i });
    expect(button()).toBeDisabled();

    await selectTeamAndSettle();
    expect(button()).toBeDisabled(); // audience but no message

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'Hi' } });
    expect(button()).toBeEnabled();
  });

  test('will not send when the preview resolves to nobody', async () => {
    mockApi({ total: 0, suppressed: 0, final_count: 0 });
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'Hi' } });

    expect(screen.getByRole('button', { name: /Send broadcast/i })).toBeDisabled();
  });

  test('does not request a preview before an audience is chosen', async () => {
    render(<BroadcastCompose />);
    await act(async () => {
      jest.advanceTimersByTime(400);
    });

    expect(
      (fetch as jest.Mock).mock.calls.filter((c) => (c[0] as string).includes('preview-broadcast'))
    ).toHaveLength(0);
    expect(screen.getByText(/to see who this reaches/i)).toBeInTheDocument();
  });

  // ── Club-wide ────────────────────────────────────────────────────────────
  test('club-wide sends scope=club and no team_ids', async () => {
    render(<BroadcastCompose />);
    await screen.findByLabelText(/Everyone in the club/);

    fireEvent.click(screen.getByLabelText(/Everyone in the club/));
    await act(async () => {
      jest.advanceTimersByTime(400);
    });

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'Season starts!' } });
    await clickSend();

    await waitFor(() =>
      expect(
        (fetch as jest.Mock).mock.calls.some((c) => (c[0] as string).includes('send-broadcast'))
      ).toBe(true)
    );

    const body = bodyOf('send-broadcast');
    expect(body.scope).toBe('club');
    expect(body.team_ids).toEqual([]);
  });

  test('hides the club-wide option from coaches', async () => {
    mockIsClubAdmin = false;
    render(<BroadcastCompose />);
    await screen.findByLabelText(/Eagles U14/);

    // The backend refuses it independently; this only removes a control that
    // would always 403.
    expect(screen.queryByLabelText(/Everyone in the club/)).not.toBeInTheDocument();
  });

  test('a coach send always carries scope=teams', async () => {
    mockIsClubAdmin = false;
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'Hi' } });
    await clickSend();

    await waitFor(() =>
      expect(
        (fetch as jest.Mock).mock.calls.some((c) => (c[0] as string).includes('send-broadcast'))
      ).toBe(true)
    );

    expect(bodyOf('send-broadcast').scope).toBe('teams');
  });

  // ── Failure surfacing ────────────────────────────────────────────────────
  test('shows the backend error instead of reporting a false success', async () => {
    mockApi(undefined, {
      ok: false,
      body: { error: 'Only club admins can send a club-wide broadcast' },
    });

    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'Hi' } });
    await clickSend();

    expect(await screen.findByText(/Only club admins/)).toBeInTheDocument();
  });

  test('reports queued and skipped counts on success', async () => {
    render(<BroadcastCompose />);
    await selectTeamAndSettle();

    fireEvent.change(screen.getByLabelText(/Message/i), { target: { value: 'Hi' } });
    await clickSend();

    expect(await screen.findByText(/queued for 139 recipients/i)).toBeInTheDocument();
    expect(screen.getByText(/3 skipped/i)).toBeInTheDocument();
  });
});

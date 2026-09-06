import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ReminderStreamPanel } from '../ReminderStreamPanel';

/**
 * The reminder-stream panel (GOTR G7).
 *
 * Pinned: the panel says which stream applies (own / inherited from X / the
 * default cadence); the allowed tags are rendered as chips from the SERVER's
 * list; an unknown tag never leaves the browser; the save body is the shape
 * api/compliance-streams.php validates, with the club tier only on a create;
 * a preview asks the server and renders what it answers; switching off says
 * it falls back rather than going silent.
 */

const ok = (body: unknown) => ({ ok: true, status: 200, json: async () => body });
const fail = (status: number, body: unknown) => ({ ok: false, status, json: async () => body });

const tags = ['first_name', 'requirement_name', 'expires_on', 'days_left', 'club_name', 'renewal_url'];

const inheritedDescription = {
  success: true,
  available: true,
  applies: 'inherited',
  stream: {
    id: 5, requirement_id: 10, org_unit_id: 2, club_profile_id: null, active: true, tier: 'org_unit',
    tier_unit: { id: 2, type: 'division', name: 'West' },
    steps: [{ days_before: 45, subject: 'From the division', body: 'x', channel: 'email' }],
  },
  own: null,
  inherited_from: { id: 2, type: 'division', name: 'West' },
  default_thresholds: [90, 60, 30, 7],
  tags,
};

const defaultDescription = { ...inheritedDescription, applies: 'default', stream: null, inherited_from: null };

const ownDescription = {
  ...inheritedDescription,
  applies: 'own',
  stream: {
    id: 9, requirement_id: 10, org_unit_id: null, club_profile_id: 100, active: true, tier: 'club', tier_unit: null,
    steps: [{ days_before: 14, subject: 'Two weeks', body: 'Hi {{first_name}}', channel: 'email' }],
  },
  own: {
    id: 9, requirement_id: 10, org_unit_id: null, club_profile_id: 100, active: true,
    steps: [{ days_before: 14, subject: 'Two weeks', body: 'Hi {{first_name}}', channel: 'email' }],
  },
  inherited_from: null,
};

function mockFetch(description: unknown) {
  global.fetch = jest.fn(async (url: any, init?: any) => {
    const u = String(url);
    if (u.includes('action=for-requirement')) return ok(description);
    if (u.includes('action=preview')) {
      const body = JSON.parse(init.body);
      return ok({ success: true, subject: body.subject.replace('{{requirement_name}}', 'SafeSport'), body: body.body.replace('{{first_name}}', 'Ada'), values: {} });
    }
    if (u.includes('action=save')) return ok({ success: true, id: 9, stream: ownDescription.own });
    if (u.includes('action=set-active')) return ok({ success: true, active: false, stream: { ...ownDescription.own, active: false } });
    return ok({ success: true });
  }) as any;
}

const open = async () => {
  fireEvent.click(screen.getByRole('button', { name: /reminder stream/i }));
  await waitFor(() => expect(screen.getByTestId('stream-status-10')).toBeInTheDocument());
};

describe('ReminderStreamPanel', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tok');
  });

  test('says which stream applies: inherited, with the tier named', async () => {
    mockFetch(inheritedDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    expect(screen.getByTestId('stream-status-10')).toHaveTextContent('Inherited from West (division)');
    expect(screen.getByText(/45 days before expiry/)).toBeInTheDocument();
    // The request named THIS club's tier.
    expect(String((global.fetch as jest.Mock).mock.calls[0][0])).toContain('club_id=100');
  });

  test('says when the default cadence applies, with its thresholds', async () => {
    mockFetch(defaultDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    expect(screen.getByTestId('stream-status-10')).toHaveTextContent('default cadence: 90, 60, 30, 7 days');
  });

  test('editing shows the server’s tag list as chips and refuses an unknown tag before saving', async () => {
    mockFetch(defaultDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    fireEvent.click(screen.getByText('Write a stream for this tier'));

    for (const tag of tags) {
      expect(screen.getByText(`{{${tag}}}`)).toBeInTheDocument();
    }

    fireEvent.change(screen.getByLabelText('Step 1 subject'), { target: { value: 'Hello {{last_name}}' } });
    fireEvent.change(screen.getByLabelText('Step 1 body'), { target: { value: 'Body' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('unknown merge tag: {{last_name}}');
    expect((global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=save'))).toBe(false);
  });

  test('a duplicate offset and a blank body are caught in the browser too', async () => {
    mockFetch(defaultDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    fireEvent.click(screen.getByText('Write a stream for this tier'));
    fireEvent.change(screen.getByLabelText('Step 1 subject'), { target: { value: 'S' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Step 1 has no body');

    fireEvent.change(screen.getByLabelText('Step 1 body'), { target: { value: 'B' } });
    fireEvent.click(screen.getByText('Add a step'));
    fireEvent.change(screen.getByLabelText('Step 2 days before expiry'), { target: { value: '30' } });
    fireEvent.change(screen.getByLabelText('Step 2 subject'), { target: { value: 'S2' } });
    fireEvent.change(screen.getByLabelText('Step 2 body'), { target: { value: 'B2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Two steps are both 30 days from expiry');
  });

  test('saving a new club stream posts the shape the gateway validates', async () => {
    mockFetch(defaultDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    fireEvent.click(screen.getByText('Write a stream for this tier'));
    fireEvent.change(screen.getByLabelText('Step 1 days before expiry'), { target: { value: '-7' } });
    fireEvent.change(screen.getByLabelText('Step 1 subject'), { target: { value: '{{requirement_name}} expired' } });
    fireEvent.change(screen.getByLabelText('Step 1 body'), { target: { value: 'Hi {{first_name}}, renew: {{renewal_url}}' } });
    expect(screen.getByText('7 days after expiry')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Save and switch on' }));

    await waitFor(() =>
      expect((global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=save'))).toBe(true)
    );
    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=save'));
    const body = JSON.parse(call[1].body);
    expect(body).toEqual({
      requirement_id: 10,
      club_profile_id: 100,
      active: true,
      steps: [{ days_before: -7, subject: '{{requirement_name}} expired', body: 'Hi {{first_name}}, renew: {{renewal_url}}', channel: 'email' }],
    });
    expect(body.id).toBeUndefined();
  });

  test('editing an existing stream sends its id and never the tier', async () => {
    mockFetch(ownDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    expect(screen.getByTestId('stream-status-10')).toHaveTextContent('own stream');
    fireEvent.click(screen.getByText('Edit steps'));
    expect(screen.getByLabelText('Step 1 subject')).toHaveValue('Two weeks');
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() =>
      expect((global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=save'))).toBe(true)
    );
    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=save'));
    const body = JSON.parse(call[1].body);
    expect(body.id).toBe(9);
    expect(body.club_profile_id).toBeUndefined();
    expect(body.org_unit_id).toBeUndefined();
  });

  test('preview asks the server for one step and renders what it answers', async () => {
    mockFetch(defaultDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    fireEvent.click(screen.getByText('Write a stream for this tier'));
    fireEvent.change(screen.getByLabelText('Step 1 subject'), { target: { value: '{{requirement_name}} soon' } });
    fireEvent.change(screen.getByLabelText('Step 1 body'), { target: { value: 'Hi {{first_name}}' } });
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }));

    const preview = await screen.findByTestId('stream-preview-0');
    expect(preview).toHaveTextContent('SafeSport soon');
    expect(preview).toHaveTextContent('Hi Ada');

    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=preview'));
    expect(JSON.parse(call[1].body)).toMatchObject({ days_before: 30, subject: '{{requirement_name}} soon', club_id: 100, requirement_name: 'SafeSport' });
  });

  test('a server 422 for an unknown tag is shown, not swallowed', async () => {
    mockFetch(defaultDescription);
    (global.fetch as jest.Mock).mockImplementation(async (url: any) => {
      const u = String(url);
      if (u.includes('action=for-requirement')) return ok({ ...defaultDescription, tags: [...tags, 'late_tag'] });
      if (u.includes('action=save')) return fail(422, { success: false, error: 'Unknown merge tag: {{late_tag}}', reason: 'unknown_tag', unknown_tags: ['late_tag'] });
      return ok({ success: true });
    });
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    fireEvent.click(screen.getByText('Write a stream for this tier'));
    fireEvent.change(screen.getByLabelText('Step 1 subject'), { target: { value: 'S {{late_tag}}' } });
    fireEvent.change(screen.getByLabelText('Step 1 body'), { target: { value: 'B' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Unknown merge tag: {{late_tag}}');
  });

  test('switching off says it falls back, and posts set-active', async () => {
    mockFetch(ownDescription);
    render(<ReminderStreamPanel requirementId={10} requirementName="SafeSport" tier={{ club_id: 100 }} />);
    await open();
    expect(screen.getByText(/never to no reminders/i)).toBeInTheDocument();
    fireEvent.click(screen.getByText('Switch off'));
    await waitFor(() =>
      expect((global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('action=set-active'))).toBe(true)
    );
    const call = (global.fetch as jest.Mock).mock.calls.find((c) => String(c[0]).includes('action=set-active'));
    expect(JSON.parse(call[1].body)).toEqual({ id: 9, active: false });
  });
});

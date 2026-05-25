import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { SmsCompose } from './SmsCompose';

jest.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 79, email: 'coach@example.com', name: 'Coach Carter', phone: '5559990000' },
  }),
}));

jest.mock('../../contexts/OrgContext', () => ({
  useOrg: () => ({
    activeContext: { role: 'coach', scope_type: 'club', scope_id: 32, scope_name: 'Club' },
  }),
}));

jest.mock('./RecipientSelector', () => ({
  RecipientSelector: () => <div data-testid="recipient-selector" />,
}));

global.fetch = jest.fn();

const makeRecipient = (overrides: Partial<any> = {}) => ({
  id: 1,
  type: 'guardian' as const,
  first_name: 'Jane',
  last_name: 'Doe',
  email: 'jane@example.com',
  phone: '5551234567',
  athlete_id: 10,
  suppressed: false,
  ...overrides,
});

const renderWith = (recipients: any[]) =>
  render(
    <SmsCompose
      isOpen={true}
      onClose={jest.fn()}
      clubProfileId={32}
      preselectedRecipients={recipients}
    />
  );

describe('SmsCompose send routing (CA-49)', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
    (fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { queued: 2, skipped: 0, skipped_details: [] } }),
    });
  });

  test('routes a multi-recipient send to action=send-sms (not send-broadcast)', async () => {
    renderWith([
      makeRecipient({ id: 1, phone: '5551110000' }),
      makeRecipient({ id: 2, phone: '5552220000' }),
    ]);

    fireEvent.change(screen.getByPlaceholderText(/Type your message/i), {
      target: { value: 'Game moved to 4pm' },
    });

    fireEvent.click(screen.getByRole('button', { name: /Send to Group/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    const calledUrl = (fetch as jest.Mock).mock.calls[0][0] as string;
    expect(calledUrl).toContain('action=send-sms');
    expect(calledUrl).not.toContain('send-broadcast');
  });

  test('sends a recipients array (not team_ids) in the body', async () => {
    renderWith([
      makeRecipient({ id: 1, phone: '5551110000' }),
      makeRecipient({ id: 2, phone: '5552220000' }),
    ]);

    fireEvent.change(screen.getByPlaceholderText(/Type your message/i), {
      target: { value: 'Hello team' },
    });

    fireEvent.click(screen.getByRole('button', { name: /Send to Group/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    const body = JSON.parse((fetch as jest.Mock).mock.calls[0][1].body);
    expect(Array.isArray(body.recipients)).toBe(true);
    expect(body.recipients.length).toBe(2);
    expect(body.team_ids).toBeUndefined();
    expect(body.body).toBe('Hello team');
  });
});

describe('SmsCompose missing-phone warning (CA-48)', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
  });

  test('renders a warning that lists and counts phoneless recipients and states exclusion', () => {
    renderWith([
      makeRecipient({ id: 1, first_name: 'Has', last_name: 'Phone', phone: '5551110000' }),
      makeRecipient({ id: 2, first_name: 'No', last_name: 'Phone', phone: undefined }),
    ]);

    // Count + exclusion message
    expect(screen.getByText(/1 recipient missing a phone number/i)).toBeInTheDocument();
    expect(screen.getByText(/will be excluded from this SMS/i)).toBeInTheDocument();
    // Lists the affected recipient name (exact, comma-joined list of full names)
    expect(screen.getByText('No Phone')).toBeInTheDocument();
  });

  test('no warning when all recipients have phones', () => {
    renderWith([
      makeRecipient({ id: 1, phone: '5551110000' }),
      makeRecipient({ id: 2, phone: '5552220000' }),
    ]);

    expect(screen.queryByText(/missing a phone number/i)).not.toBeInTheDocument();
  });

  test('only recipients with phones are sent', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { queued: 1, skipped: 0, skipped_details: [] } }),
    });

    renderWith([
      makeRecipient({ id: 1, phone: '5551110000' }),
      makeRecipient({ id: 2, phone: undefined }),
    ]);

    fireEvent.change(screen.getByPlaceholderText(/Type your message/i), {
      target: { value: 'Hello' },
    });
    fireEvent.click(screen.getByRole('button', { name: /^Send$/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    const body = JSON.parse((fetch as jest.Mock).mock.calls[0][1].body);
    expect(body.recipients.length).toBe(1);
    expect(body.recipients[0].id).toBe(1);
  });
});

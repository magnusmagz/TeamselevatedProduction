import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { EmailCompose } from './EmailCompose';

// Mock auth context
jest.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 79, email: 'coach@example.com', name: 'Coach Carter' },
  }),
}));

// Mock the RecipientSelector so we can inject pre-resolved recipients via the
// preselectedRecipients prop without exercising the search API.
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

describe('EmailCompose send routing (CA-44)', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
    (fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { queued: 2, skipped: 0, skipped_details: [] } }),
    });
  });

  const renderWith = (recipients: any[]) =>
    render(
      <EmailCompose
        isOpen={true}
        onClose={jest.fn()}
        clubProfileId={32}
        preselectedRecipients={recipients}
      />
    );

  test('routes a multi-recipient send to action=send-email (not send-broadcast)', async () => {
    renderWith([
      makeRecipient({ id: 1, email: 'a@example.com' }),
      makeRecipient({ id: 2, email: 'b@example.com' }),
    ]);

    fireEvent.change(screen.getByPlaceholderText(/Enter subject line/i), {
      target: { value: 'Practice Update' },
    });
    fireEvent.change(screen.getByPlaceholderText(/Compose your email/i), {
      target: { value: 'See you at practice' },
    });

    fireEvent.click(screen.getByRole('button', { name: /Send to Group/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalled());

    const calledUrl = (fetch as jest.Mock).mock.calls[0][0] as string;
    expect(calledUrl).toContain('action=send-email');
    expect(calledUrl).not.toContain('send-broadcast');
  });

  test('single recipient also routes to action=send-email', async () => {
    renderWith([makeRecipient({ id: 1, email: 'a@example.com' })]);

    fireEvent.change(screen.getByPlaceholderText(/Enter subject line/i), {
      target: { value: 'Hi' },
    });
    fireEvent.change(screen.getByPlaceholderText(/Compose your email/i), {
      target: { value: 'Body' },
    });

    fireEvent.click(screen.getByRole('button', { name: /^Send$/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    const calledUrl = (fetch as jest.Mock).mock.calls[0][0] as string;
    expect(calledUrl).toContain('action=send-email');
  });

  test('sends a recipients array (not team_ids) in the body', async () => {
    renderWith([
      makeRecipient({ id: 1, email: 'a@example.com' }),
      makeRecipient({ id: 2, email: 'b@example.com' }),
    ]);

    fireEvent.change(screen.getByPlaceholderText(/Enter subject line/i), {
      target: { value: 'Subject' },
    });
    fireEvent.change(screen.getByPlaceholderText(/Compose your email/i), {
      target: { value: 'Body' },
    });

    fireEvent.click(screen.getByRole('button', { name: /Send to Group/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    const body = JSON.parse((fetch as jest.Mock).mock.calls[0][1].body);
    expect(Array.isArray(body.recipients)).toBe(true);
    expect(body.recipients.length).toBe(2);
    expect(body.team_ids).toBeUndefined();
    expect(body.club_profile_id).toBe(32);
  });

  test('shows queued/skipped count from backend response', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { queued: 2, skipped: 1, skipped_details: [] } }),
    });

    renderWith([
      makeRecipient({ id: 1, email: 'a@example.com' }),
      makeRecipient({ id: 2, email: 'b@example.com' }),
    ]);

    fireEvent.change(screen.getByPlaceholderText(/Enter subject line/i), {
      target: { value: 'Subject' },
    });
    fireEvent.change(screen.getByPlaceholderText(/Compose your email/i), {
      target: { value: 'Body' },
    });

    fireEvent.click(screen.getByRole('button', { name: /Send to Group/i }));

    await waitFor(() =>
      expect(screen.getByText(/queued for 2 recipients \(1 skipped\)/i)).toBeInTheDocument()
    );
  });
});

describe('EmailCompose suppressed count (CA-44)', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
    (fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { queued: 1, skipped: 0, skipped_details: [] } }),
    });
  });

  test('footer shows active recipient count with suppressed count', () => {
    render(
      <EmailCompose
        isOpen={true}
        onClose={jest.fn()}
        clubProfileId={32}
        preselectedRecipients={[
          makeRecipient({ id: 1, email: 'a@example.com' }),
          makeRecipient({ id: 2, email: 'b@example.com', suppressed: true }),
        ]}
      />
    );

    expect(screen.getByText(/Sending to/i)).toBeInTheDocument();
    expect(screen.getByText(/1 suppressed/i)).toBeInTheDocument();
  });
});

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import CommunicationHistory from './CommunicationHistory';

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

// Backend now returns the field shape this component consumes (CA-25):
// sender_first_name/last_name, full body, open_count/click_count. The contact-
// history endpoint wraps rows under both `data` and `entries`.
const entries = [
  {
    id: 11,
    channel: 'email',
    recipient_name: 'John Stone',
    recipient_email: 'john@stone.com',
    subject: 'Practice moved to 6pm',
    body: '<p>Hi, practice is at 6pm.</p>',
    status: 'delivered',
    sender_first_name: 'Coach',
    sender_last_name: 'Riley',
    open_count: 2,
    click_count: 1,
    created_at: '2026-05-01T10:00:00Z',
    sent_at: '2026-05-01T10:00:05Z',
    delivered_at: '2026-05-01T10:00:30Z',
  },
  {
    id: 12,
    channel: 'sms',
    recipient_name: 'John Stone',
    recipient_phone: '5125551212',
    body: 'Game cancelled due to rain.',
    status: 'sent',
    sender_first_name: 'Coach',
    sender_last_name: 'Riley',
    open_count: 0,
    click_count: 0,
    created_at: '2026-05-02T09:00:00Z',
    sent_at: '2026-05-02T09:00:02Z',
    delivered_at: '',
  },
];

beforeEach(() => {
  mockFetch.mockReset();
  window.localStorage.setItem('auth_token', 'test-token');
});

function mockHistory(rows: any[]) {
  mockFetch.mockImplementation((url: string) => {
    if (url.includes('action=contact-history')) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, data: rows, entries: rows }),
      });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
  });
}

describe('CommunicationHistory (CA-25)', () => {
  it('requests contact-history scoped to the athlete with a bearer token', async () => {
    mockHistory(entries);
    render(<CommunicationHistory contactType="athlete" contactId={1} clubProfileId={100} />);

    await waitFor(() => expect(screen.getByText('Practice moved to 6pm')).toBeInTheDocument());

    const [calledUrl, opts] = mockFetch.mock.calls[0];
    expect(calledUrl).toContain('action=contact-history');
    expect(calledUrl).toContain('contact_type=athlete');
    expect(calledUrl).toContain('contact_id=1');
    expect(calledUrl).toContain('club_profile_id=100');
    expect(opts.headers.Authorization).toBe('Bearer test-token');
  });

  it('renders sender name, subject, status and open/click counts from the backend shape', async () => {
    mockHistory(entries);
    render(<CommunicationHistory contactType="athlete" contactId={1} clubProfileId={100} />);

    await waitFor(() => expect(screen.getByText('Practice moved to 6pm')).toBeInTheDocument());

    // Sender comes from sender_first_name + sender_last_name (not a blank).
    // Both entries share the sender, so there are two matches.
    expect(screen.getAllByText(/From: Coach Riley/i).length).toBe(2);
    // Open/click summary derived from open_count/click_count.
    expect(screen.getByText(/2 opens/i)).toBeInTheDocument();
    expect(screen.getByText(/1 click/i)).toBeInTheDocument();
    // SMS body preview is shown for sms entries.
    expect(screen.getByText(/Game cancelled due to rain/i)).toBeInTheDocument();
  });

  it('expands an entry to show the full body', async () => {
    mockHistory(entries);
    render(<CommunicationHistory contactType="athlete" contactId={1} clubProfileId={100} />);

    await waitFor(() => expect(screen.getByText('Practice moved to 6pm')).toBeInTheDocument());

    // Expand the SMS entry (plain text body, easy to assert).
    fireEvent.click(screen.getByText(/Game cancelled due to rain/i));
    expect(screen.getByText('Message')).toBeInTheDocument();
  });

  it('shows the empty state when there is no history', async () => {
    mockHistory([]);
    render(<CommunicationHistory contactType="athlete" contactId={1} clubProfileId={100} />);

    await waitFor(() =>
      expect(screen.getByText(/No communications sent to this contact yet/i)).toBeInTheDocument()
    );
  });
});

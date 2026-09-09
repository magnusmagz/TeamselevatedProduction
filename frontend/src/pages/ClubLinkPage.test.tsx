import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import ClubLinkPage, { eventKind, formatTime, initials, COACHES_PER_PAGE, PublicPagePayload } from './ClubLinkPage';

// react-router-dom is mocked outright, as the other page tests do (see
// AcceptCoachInvite.test.tsx): the page reads one param and renders no Links.
let mockSlug = 'central-kansas-united';
jest.mock('react-router-dom', () => ({
  useParams: () => ({ slug: mockSlug }),
}));

const payload = (over: Partial<PublicPagePayload> = {}): PublicPagePayload => ({
  club: {
    id: 51, name: 'Central Kansas United', slug: 'central-kansas-united', tagline: 'Youth soccer for Salina',
    phone: '785-555-0100', website: 'https://centralkansassoccer.org', city: 'Salina', state: 'KS',
    socials: { facebook: 'https://facebook.com/cku', instagram: 'https://instagram.com/cku' },
    logo_url: null, primary_color: '#323c50', secondary_color: '#919fba',
  },
  sponsors: [{ id: 1, name: 'Salina Ford', website: 'https://salinaford.com', logo_data: null }],
  coaches: Array.from({ length: 11 }, (_, i) => ({ name: `Coach ${i + 1}`, role: 'Head coach', photo: null, teams: [`U${10 + i}`] })),
  events: [
    { id: 100, name: 'U12 Boys', type: 'game', event_date: '2026-09-13', start_time: '09:00', end_time: '10:30', opponent_name: 'Salina Storm', venue_name: 'Bill Burke Park', field_name: 'Field 3', teams: ['U12 Boys'] },
    { id: 104, name: 'Sunflower Cup', type: 'tournament', event_date: '2026-09-27', start_time: null, end_time: null, opponent_name: null, venue_name: null, field_name: null, teams: [] },
  ],
  ...over,
});

function mockFetch(handler: (url: string, init?: RequestInit) => { status: number; body: unknown }) {
  (global as any).fetch = jest.fn(async (url: string, init?: RequestInit) => {
    const r = handler(url, init);
    return { ok: r.status >= 200 && r.status < 300, status: r.status, json: async () => r.body };
  });
}

function renderPage(slug = 'central-kansas-united') {
  mockSlug = slug;
  return render(<ClubLinkPage />);
}

describe('ClubLinkPage', () => {
  afterEach(() => { jest.restoreAllMocks(); });

  it('renders the club, its links, sponsors, games and the first nine coaches', async () => {
    mockFetch(() => ({ status: 200, body: { success: true, ...payload() } }));
    renderPage();
    expect(await screen.findByRole('heading', { level: 1, name: 'Central Kansas United' })).toBeInTheDocument();
    expect(screen.getByText('Salina, KS')).toBeInTheDocument();
    expect(screen.getByText('Youth soccer for Salina')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /visit our website/i })).toHaveAttribute('href', 'https://centralkansassoccer.org');
    expect(screen.getByRole('link', { name: /call the club/i })).toHaveAttribute('href', 'tel:7855550100');
    expect(screen.getByRole('link', { name: /contact us/i })).toHaveAttribute('href', '#contact');
    expect(screen.getByRole('link', { name: 'Facebook' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Salina Ford' })).toHaveAttribute('href', 'https://salinaford.com');
    expect(screen.getByText('U12 Boys vs Salina Storm')).toBeInTheDocument();
    expect(screen.getByText('9:00 AM – 10:30 AM')).toBeInTheDocument();
    expect(screen.getByText('Bill Burke Park · Field 3')).toBeInTheDocument();
    expect(screen.getByText('Tournament')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /add to my calendar/i })).toHaveAttribute('href', expect.stringContaining('action=ics&slug=central-kansas-united'));
    // 3x3 grid, paged at nine
    expect(screen.getByTestId('coaches-grid').querySelectorAll('li')).toHaveLength(COACHES_PER_PAGE);
    expect(screen.getByText('1 of 2')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /next coaches/i }));
    expect(screen.getByTestId('coaches-grid').querySelectorAll('li')).toHaveLength(2);
    expect(screen.getByText('Coach 11')).toBeInTheDocument();
    // no staff chrome, no athlete anything
    expect(screen.queryByText(/athlete/i)).not.toBeInTheDocument();
  });

  it('hides sections the club has nothing for', async () => {
    mockFetch(() => ({ status: 200, body: { success: true, ...payload({ sponsors: [], coaches: [], club: { ...payload().club, phone: null, website: null, socials: {}, tagline: null } }) } }));
    renderPage();
    await screen.findByRole('heading', { level: 1 });
    expect(screen.queryByText(/thank you to our sponsors/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/our coaches/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /call the club/i })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /contact us/i })).toBeInTheDocument();
  });

  it('shows the not-available state on a 404', async () => {
    mockFetch(() => ({ status: 404, body: { error: 'This club page is not available.' } }));
    renderPage('nope');
    expect(await screen.findByText('This club page is not available.')).toBeInTheDocument();
  });

  it('posts the contact form with the honeypot empty and shows the thank-you', async () => {
    const calls: Array<{ url: string; init?: RequestInit }> = [];
    mockFetch((url, init) => {
      calls.push({ url, init });
      if (url.includes('action=contact')) return { status: 200, body: { success: true, sent: true } };
      return { status: 200, body: { success: true, ...payload() } };
    });
    renderPage();
    await screen.findByRole('heading', { level: 1 });
    fireEvent.change(screen.getByLabelText(/your name/i), { target: { value: 'Jane Doe' } });
    fireEvent.change(screen.getByLabelText(/^email/i), { target: { value: 'jane@example.com' } });
    fireEvent.change(screen.getByLabelText(/message/i), { target: { value: 'Hello' } });
    fireEvent.click(screen.getByRole('button', { name: /send message/i }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/on its way/));
    const post = calls.find((c) => c.url.includes('action=contact'))!;
    const body = JSON.parse(post.init!.body as string);
    expect(body).toMatchObject({ name: 'Jane Doe', email: 'jane@example.com', message: 'Hello', website_url: '' });
    expect(post.init!.headers).not.toHaveProperty('Authorization');
  });

  it('explains a rate limit and a switched-off form', async () => {
    let status = 429;
    mockFetch((url) => {
      if (url.includes('action=contact')) return status === 429
        ? { status: 429, body: { success: false, reason: 'rate_limited', error: 'Please try again in an hour.' } }
        : { status: 503, body: { success: false, feature_disabled: 'PUBLIC_CLUB_CONTACT' } };
      return { status: 200, body: { success: true, ...payload() } };
    });
    renderPage();
    await screen.findByRole('heading', { level: 1 });
    fireEvent.change(screen.getByLabelText(/your name/i), { target: { value: 'J' } });
    fireEvent.change(screen.getByLabelText(/^email/i), { target: { value: 'j@x.com' } });
    fireEvent.change(screen.getByLabelText(/message/i), { target: { value: 'Hi' } });
    fireEvent.click(screen.getByRole('button', { name: /send message/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Please try again in an hour.');
    status = 503;
    fireEvent.click(screen.getByRole('button', { name: /send message/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/not available right now/));
  });
});

describe('helpers', () => {
  it('classifies home, away and tournament', () => {
    expect(eventKind({ type: 'game', name: 'U12 Boys', opponent_name: 'Storm' })).toBe('Home');
    expect(eventKind({ type: 'game', name: 'U14 Boys at Hutchinson', opponent_name: null })).toBe('Away');
    expect(eventKind({ type: 'game', name: 'Away vs Wichita', opponent_name: null })).toBe('Away');
    expect(eventKind({ type: 'tournament', name: 'Cup', opponent_name: null })).toBe('Tournament');
  });
  it('formats times and initials', () => {
    expect(formatTime('09:05')).toBe('9:05 AM');
    expect(formatTime('13:30')).toBe('1:30 PM');
    expect(formatTime('00:00')).toBe('12:00 AM');
    expect(formatTime(null)).toBeNull();
    expect(initials('Jamie Rodriguez')).toBe('JR');
    expect(initials('Cher')).toBe('C');
  });
});

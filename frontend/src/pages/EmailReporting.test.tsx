import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import { EmailReporting } from './EmailReporting';

// --- Mock contexts the page depends on ---
jest.mock('../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));
jest.mock('../contexts/OrgContext', () => ({
  useOrg: jest.fn(),
}));

// recharts renders nothing useful in jsdom (no layout) and warns about zero
// width; stub the pieces this page uses so the panels under test render.
jest.mock('recharts', () => {
  const Stub = ({ children }: any) => <div>{children}</div>;
  return {
    ResponsiveContainer: Stub,
    LineChart: Stub,
    BarChart: Stub,
    PieChart: Stub,
    Pie: Stub,
    Line: Stub,
    Bar: Stub,
    Cell: Stub,
    XAxis: Stub,
    YAxis: Stub,
    CartesianGrid: Stub,
    Tooltip: Stub,
    Legend: Stub,
  };
});

import { useAuth } from '../contexts/AuthContext';
import { useOrg } from '../contexts/OrgContext';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockUseOrg = useOrg as jest.MockedFunction<typeof useOrg>;

// --- Mock API responses keyed by action ---

const overviewResponse = {
  success: true,
  stats: {
    total_sent: 10, email_sent: 8, sms_sent: 2,
    total_delivered: 9, total_opened: 5, total_clicked: 3,
    total_bounced: 1, total_failed: 0, total_pending: 0,
    delivery_rate: 90, open_rate: 62.5, click_rate: 37.5,
    prev_total_sent: 8, prev_delivery_rate: 88,
    prev_open_rate: 60, prev_click_rate: 30,
  },
};

const linkAnalyticsResponse = {
  success: true,
  links: [
    { original_url: 'https://club.example.com/schedule', total_clicks: 12, emails_containing: 4 },
    { original_url: 'https://club.example.com/roster', total_clicks: 5, emails_containing: 2 },
  ],
};

const recentSendsResponse = {
  success: true,
  sends: [
    {
      id: 42, type: 'broadcast', subject: 'Team Dinner Friday', channel: 'email',
      sent_at: '2026-05-20T18:00:00Z', sender: 'Coach Carter',
      total_recipients: 3, delivered: 3, opened: 2, clicked: 1, bounced: 0, failed: 0,
      recipient_name: null, open_rate: 66.7, click_rate: 33.3,
    },
  ],
  pagination: { page: 1, per_page: 10, total: 1, total_pages: 1 },
};

const perEmailReportResponse = {
  success: true,
  report: {
    id: 42, type: 'broadcast', channel: 'email', subject: 'Team Dinner Friday',
    sender_name: 'Coach Carter', sent_at: '2026-05-20T18:00:00Z',
    recipient_count: 3, total_delivered: 3, total_opened: 2, total_clicked: 1, total_bounced: 0,
    recipients: [
      { name: 'Jane Doe', email: 'jane@example.com', phone: null, status: 'delivered', opened: true, clicked: true, opened_at: '2026-05-20T18:05:00Z', clicked_at: '2026-05-20T18:06:00Z' },
      { name: 'John Smith', email: 'john@example.com', phone: null, status: 'delivered', opened: true, clicked: false, opened_at: '2026-05-20T18:10:00Z', clicked_at: null },
      { name: 'Bob Lee', email: 'bob@example.com', phone: null, status: 'bounced', opened: false, clicked: false, opened_at: null, clicked_at: null },
    ],
    links: [],
  },
};

// Records the URLs fetch() was called with, for asserting query params.
let fetchedUrls: string[] = [];

function routeFetch(url: string) {
  fetchedUrls.push(url);
  let body: any = { success: true };
  if (url.includes('action=overview')) body = overviewResponse;
  else if (url.includes('action=link-analytics')) body = linkAnalyticsResponse;
  else if (url.includes('action=recent-sends')) body = recentSendsResponse;
  else if (url.includes('action=per-email-report')) body = perEmailReportResponse;
  else if (url.includes('action=campaign-performance')) body = { success: true, volume: [], engagement: [] };
  else if (url.includes('action=teams')) body = { success: true, teams: [] };
  return Promise.resolve({ json: async () => body } as any);
}

describe('EmailReporting — CA-60 per-email report & CA-61 link analytics', () => {
  beforeEach(() => {
    fetchedUrls = [];
    mockUseAuth.mockReturnValue({ user: { id: 50, email: 'admin@example.com', name: 'Admin' } } as any);
    mockUseOrg.mockReturnValue({ currentClubId: 32, isClubAdmin: true } as any);
    global.fetch = jest.fn((url: any) => routeFetch(String(url))) as any;
    localStorage.setItem('auth_token', 'test-token');
  });

  // ---- CA-61 ----
  test('renders the Top Clicked Links panel with clicked URLs and counts', async () => {
    render(<EmailReporting />);

    await waitFor(() => {
      expect(screen.getByText('Top Clicked Links')).toBeInTheDocument();
    });

    // URLs (truncated text still contains the host) and their click counts.
    await waitFor(() => {
      expect(screen.getByText(/club\.example\.com\/schedule/)).toBeInTheDocument();
    });
    expect(screen.getByText(/club\.example\.com\/roster/)).toBeInTheDocument();
    expect(screen.getByText('12 clicks')).toBeInTheDocument();
    expect(screen.getByText('5 clicks')).toBeInTheDocument();
  });

  // The page renders both a desktop table and a mobile card list (jsdom has no
  // CSS, so both are present). Click the desktop <tr> row specifically.
  async function clickFirstSendRow(): Promise<void> {
    const matches = await screen.findAllByText('Team Dinner Friday');
    const tableCell = matches.find(el => el.closest('tr'));
    fireEvent.click((tableCell || matches[0]).closest('tr') || (tableCell || matches[0]));
  }

  // ---- CA-60: click opens per-recipient breakdown ----
  test('clicking a recent-send row opens the per-recipient breakdown', async () => {
    render(<EmailReporting />);
    await clickFirstSendRow();

    // Per-recipient rows render with who opened / clicked.
    expect((await screen.findAllByText('Jane Doe')).length).toBeGreaterThan(0);
    expect(screen.getAllByText('John Smith').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Bob Lee').length).toBeGreaterThan(0);
    expect(screen.getByText('jane@example.com')).toBeInTheDocument();

    // The detail fetch must be issued.
    expect(
      fetchedUrls.some(u => u.includes('action=per-email-report')),
    ).toBe(true);
  });

  // ---- CA-60: broadcast row must request type=broadcast, not type=single ----
  test('per-email report fetch passes the row type (broadcast)', async () => {
    render(<EmailReporting />);
    await clickFirstSendRow();

    await waitFor(() => {
      expect(
        fetchedUrls.some(u => u.includes('action=per-email-report') && u.includes('type=broadcast') && u.includes('id=42')),
      ).toBe(true);
    });
    // It must NOT misroute a broadcast id as a single log id.
    expect(
      fetchedUrls.some(u => u.includes('action=per-email-report') && u.includes('type=single')),
    ).toBe(false);
  });

  // ---- CA-60: aggregate metrics shown in the expanded report ----
  test('expanded report shows aggregate delivered / opened / clicked counts', async () => {
    render(<EmailReporting />);
    await clickFirstSendRow();

    await screen.findAllByText('Jane Doe');

    // Aggregate metric labels are present in the expanded detail.
    expect(screen.getAllByText('Delivered').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Opened').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Clicked').length).toBeGreaterThan(0);

    // Per-recipient flags in the desktop table: Jane opened+clicked (2x Yes),
    // Bob neither (2x No). Scope to the <tr> rows.
    const janeCell = screen.getAllByText('Jane Doe').find(el => el.closest('tr'))!;
    const janeRow = janeCell.closest('tr')!;
    expect(within(janeRow).getAllByText('Yes').length).toBeGreaterThanOrEqual(2);

    const bobCell = screen.getAllByText('Bob Lee').find(el => el.closest('tr'))!;
    const bobRow = bobCell.closest('tr')!;
    expect(within(bobRow).getAllByText('No').length).toBeGreaterThanOrEqual(2);
  });
});

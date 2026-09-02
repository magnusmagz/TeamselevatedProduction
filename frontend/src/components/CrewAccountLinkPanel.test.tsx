import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import CrewAccountLinkPanel from './CrewAccountLinkPanel';

/**
 * The Crew page's "Accounts not connected to a family" panel.
 *
 * What is worth pinning is what the admin is told and what the request says —
 * the scope rules live on the server and are covered by CrewLinkTest. Here:
 *
 *  - a non-admin renders nothing (the nav is not an access control, but neither
 *    should the control be drawn for someone who will only get a 403);
 *  - a healthy club renders nothing rather than an empty scary header;
 *  - Connect posts club_id / user_id / guardian_id, which is the whole contract;
 *  - success states the COUNT, because "Connected" cannot distinguish two
 *    children from none, and a link that resolved to nobody is a link pointed at
 *    the wrong crew record;
 *  - a 409 names the account already holding that crew record.
 */

const candidate = {
  user_id: 10,
  first_name: 'Allix',
  last_name: 'Boyce',
  email: 'allix@gmail.com',
  last_login_at: '2026-08-30 10:00:00',
  suggestions: [
    {
      guardian_id: 110,
      first_name: 'Allix',
      last_name: 'Boyce',
      email: 'allix@yahoo.com',
      mobile_phone: '7855550110',
      match: 'first_and_last_name' as const,
      athletes: [
        { id: 210, first_name: 'Ava', last_name: 'Boyce' },
        { id: 211, first_name: 'Bo', last_name: 'Boyce' },
      ],
      already_reachable_by: null,
    },
  ],
};

const pool = [
  { guardian_id: 999, first_name: 'Morgan', last_name: 'Powell', email: 'morgan@example.test', athletes: 'Zed Powell' },
];

const json = (body: any, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: () => Promise.resolve(body),
});

const mockFetch = (...responses: any[]) => {
  const fn = jest.fn();
  responses.forEach((r) => fn.mockImplementationOnce(() => Promise.resolve(r)));
  // Any further calls (the reload after a successful link) see no candidates.
  fn.mockImplementation(() => Promise.resolve(json({ success: true, candidates: [] })));
  (global as any).fetch = fn;
  return fn;
};

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
});

afterEach(() => {
  jest.restoreAllMocks();
  delete (global as any).fetch;
});

const renderPanel = (props: Partial<React.ComponentProps<typeof CrewAccountLinkPanel>> = {}) =>
  render(
    <CrewAccountLinkPanel
      clubProfileId={51}
      isClubAdmin
      searchPool={pool}
      {...props}
    />
  );

test('a non-admin sees nothing and the endpoint is never called', () => {
  const fetchMock = mockFetch(json({ success: true, candidates: [candidate] }));
  const { container } = renderPanel({ isClubAdmin: false });

  expect(container).toBeEmptyDOMElement();
  expect(fetchMock).not.toHaveBeenCalled();
});

test('a club with nothing to repair renders nothing', async () => {
  mockFetch(json({ success: true, candidates: [] }));
  const { container } = renderPanel();

  await waitFor(() => expect(container).toBeEmptyDOMElement());
});

test('lists the stuck account with the athletes each suggestion would connect', async () => {
  mockFetch(json({ success: true, candidates: [candidate] }));
  renderPanel();

  // Twice: once as the stuck account, once as the crew record being suggested.
  expect(await screen.findAllByText('Allix Boyce')).toHaveLength(2);
  expect(screen.getByText('allix@gmail.com')).toBeInTheDocument();
  expect(screen.getByText(/Ava Boyce, Bo Boyce/)).toBeInTheDocument();

  const url = String((global as any).fetch.mock.calls[0][0]);
  expect(url).toContain('/api/crew-link.php?action=candidates&club_id=51');
});

test('Connect posts the club, the account and the crew record', async () => {
  const fetchMock = mockFetch(
    json({ success: true, candidates: [candidate] }),
    json({ success: true, user_id: 10, guardian_id: 110, athletes: [{ id: 210 }, { id: 211 }] }, 201)
  );
  renderPanel();

  fireEvent.click(await screen.findByRole('button', { name: /Connect to Allix Boyce/i }));

  await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThan(1));
  const [url, init] = fetchMock.mock.calls[1];
  expect(String(url)).toContain('/api/crew-link.php?action=link');
  expect(init.method).toBe('POST');
  expect(JSON.parse(init.body)).toEqual({ club_id: 51, user_id: 10, guardian_id: 110 });
  expect(init.headers.Authorization).toBe('Bearer tok');
});

test('success states how many athletes are now visible', async () => {
  mockFetch(
    json({ success: true, candidates: [candidate] }),
    json({ success: true, athletes: [{ id: 210 }, { id: 211 }] }, 201)
  );
  const onLinked = jest.fn();
  renderPanel({ onLinked });

  fireEvent.click(await screen.findByRole('button', { name: /Connect to Allix Boyce/i }));

  expect(
    await screen.findByText('Connected — 2 athletes now visible to Allix Boyce.')
  ).toBeInTheDocument();
  await waitFor(() => expect(onLinked).toHaveBeenCalled());
});

test('a link that resolves to nobody says so rather than reporting success', async () => {
  // The failure this catches is a link pointed at the wrong crew record, which
  // is otherwise indistinguishable from a correct one.
  mockFetch(
    json({ success: true, candidates: [candidate] }),
    json({ success: true, athletes: [] }, 201)
  );
  renderPanel();

  fireEvent.click(await screen.findByRole('button', { name: /Connect to Allix Boyce/i }));

  expect(await screen.findByText(/no athletes are attached to Allix Boyce/i)).toBeInTheDocument();
});

test('a 409 names the account already holding that crew record', async () => {
  mockFetch(
    json({ success: true, candidates: [candidate] }),
    json(
      {
        success: false,
        reason: 'guardian_already_linked',
        linked_to: { user_id: 12, first_name: 'Drift', last_name: 'Ed', email: 'drifted@gmail.com' },
      },
      409
    )
  );
  renderPanel();

  fireEvent.click(await screen.findByRole('button', { name: /Connect to Allix Boyce/i }));

  expect(
    await screen.findByText('Allix Boyce is already connected to Drift Ed (drifted@gmail.com).')
  ).toBeInTheDocument();
});

test('the search box picks any crew member in the club and connects to them', async () => {
  const fetchMock = mockFetch(
    json({ success: true, candidates: [candidate] }),
    json({ success: true, athletes: [{ id: 300 }] }, 201)
  );
  renderPanel();

  fireEvent.click(await screen.findByRole('button', { name: /Search all crew/i }));
  fireEvent.change(screen.getByLabelText(/Search crew to connect to Allix Boyce/i), {
    target: { value: 'powell' },
  });

  fireEvent.click(await screen.findByRole('button', { name: /^Connect$/i }));

  await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThan(1));
  expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
    club_id: 51,
    user_id: 10,
    guardian_id: 999,
  });
});

import React from 'react';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Organizations from './Organizations';

/**
 * Organizations — the super-admin tree above the club (GOTR G1).
 *
 * Two things worth pinning here. The tree has to render before anyone can build
 * one, and `available: false` — migration 090 not applied — has to read as
 * "not set up yet" rather than as an empty tree, or a super admin will type a
 * council into a form that cannot save it.
 */

global.fetch = jest.fn();

const UNITS = [
  { id: 1, parent_id: null, type: 'national', name: 'Girls on the Run', external_code: null, path: '/1/', depth: 0, club_count: 0 },
  { id: 2, parent_id: 1, type: 'division', name: 'West', external_code: 'W', path: '/1/2/', depth: 1, club_count: 0 },
  { id: 3, parent_id: 2, type: 'council', name: 'Kansas', external_code: null, path: '/1/2/3/', depth: 2, club_count: 1 },
];

function installFetch(overrides: { available?: boolean } = {}) {
  (fetch as jest.Mock).mockImplementation((url: string) => {
    if (url.includes('action=org-units')) {
      return Promise.resolve({
        ok: true,
        json: async () => ({
          success: true,
          available: overrides.available !== false,
          units: overrides.available === false ? [] : UNITS,
          attached_clubs: overrides.available === false ? [] : [{ id: 100, name: 'GOTR Kansas', org_unit_id: 3 }],
          access: [],
        }),
      });
    }
    if (url.includes('action=clubs')) {
      return Promise.resolve({ ok: true, json: async () => ({ success: true, clubs: [{ id: 100, name: 'GOTR Kansas' }] }) });
    }
    return Promise.resolve({ ok: true, json: async () => ({ success: true }) });
  });
}

const renderPage = () => render(
  <MemoryRouter>
    <Organizations />
  </MemoryRouter>
);

beforeEach(() => {
  (fetch as jest.Mock).mockReset();
});

// Scoped to the tree: every unit name also appears in three <select>s, and a
// bare getByText would match those instead.
const tree = async () => within(await screen.findByLabelText('Organization tree'));

test('renders the tree, its tiers and the clubs attached to it', async () => {
  installFetch();
  renderPage();

  const list = await tree();
  expect(list.getByText('Girls on the Run')).toBeInTheDocument();
  expect(list.getByText('West')).toBeInTheDocument();
  expect(list.getByText('Kansas')).toBeInTheDocument();
  expect(list.getByText('GOTR Kansas')).toBeInTheDocument();
  expect(list.getByText('1 club')).toBeInTheDocument();
  expect(list.getByText('division')).toBeInTheDocument();
});

test('saving a unit posts the name, type and parent the form was given', async () => {
  installFetch();
  renderPage();
  await tree();

  fireEvent.change(screen.getByLabelText(/^Name$/), { target: { value: 'California' } });
  fireEvent.change(screen.getByLabelText(/^Type$/), { target: { value: 'council' } });
  fireEvent.change(screen.getByLabelText(/^Parent$/), { target: { value: '2' } });
  fireEvent.change(screen.getByLabelText(/External code/), { target: { value: 'CA-01' } });
  fireEvent.click(screen.getByRole('button', { name: 'Add unit' }));

  await waitFor(() => {
    const call = (fetch as jest.Mock).mock.calls.find(([url]) => url.includes('action=org-unit-save'));
    expect(call).toBeTruthy();
    expect(call[1].method).toBe('POST');
    expect(JSON.parse(call[1].body)).toEqual({
      name: 'California',
      type: 'council',
      parent_id: 2,
      external_code: 'CA-01',
    });
  });
});

test('a top-level unit posts parent_id null, not zero', async () => {
  installFetch();
  renderPage();
  await tree();

  fireEvent.change(screen.getByLabelText(/^Name$/), { target: { value: 'GOTR Two' } });
  fireEvent.change(screen.getByLabelText(/^Type$/), { target: { value: 'national' } });
  fireEvent.click(screen.getByRole('button', { name: 'Add unit' }));

  await waitFor(() => {
    const call = (fetch as jest.Mock).mock.calls.find(([url]) => url.includes('action=org-unit-save'));
    expect(JSON.parse(call[1].body).parent_id).toBeNull();
  });
});

test('attaching a club posts the club and the unit', async () => {
  installFetch();
  renderPage();
  await tree();

  fireEvent.change(screen.getByLabelText(/^Club$/), { target: { value: '100' } });
  fireEvent.change(screen.getAllByLabelText(/^Unit$/)[0], { target: { value: '3' } });
  fireEvent.click(screen.getByRole('button', { name: 'Attach' }));

  await waitFor(() => {
    const call = (fetch as jest.Mock).mock.calls.find(([url]) => url.includes('action=org-unit-attach-club'));
    expect(JSON.parse(call[1].body)).toEqual({ club_id: 100, org_unit_id: 3 });
  });
});

test('granting access posts the email, unit and role', async () => {
  installFetch();
  renderPage();
  await tree();

  fireEvent.change(screen.getByLabelText(/^Email$/), { target: { value: 'dana@gotr.org' } });
  const unitSelects = screen.getAllByLabelText(/^Unit$/);
  fireEvent.change(unitSelects[unitSelects.length - 1], { target: { value: '2' } });
  fireEvent.click(screen.getByRole('button', { name: 'Grant' }));

  await waitFor(() => {
    const call = (fetch as jest.Mock).mock.calls.find(([url]) => url.includes('action=org-access-grant'));
    expect(JSON.parse(call[1].body)).toEqual({
      email: 'dana@gotr.org',
      org_unit_id: 2,
      role: 'org_admin',
    });
  });
});

// The reason `available` is in the contract at all: "no organizations exist" and
// "this environment has no org_units table" are opposite answers.
test('says the feature is not set up when the migration has not been applied', async () => {
  installFetch({ available: false });
  renderPage();

  expect(await screen.findByText(/not set up on this environment yet/i)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Add unit' })).not.toBeInTheDocument();
});

// The server's refusal is the only one a super admin can act on: "detach its
// clubs and move its children first" is a to-do list, "failed" is not.
test('shows the refusal the server wrote', async () => {
  installFetch();
  renderPage();
  await tree();

  (fetch as jest.Mock).mockImplementationOnce(() => Promise.resolve({
    ok: false,
    json: async () => ({ error: 'Detach its clubs and move its children first.', reason: 'not_empty' }),
  }));

  window.confirm = jest.fn(() => true);
  fireEvent.click(screen.getAllByRole('button', { name: 'Delete' })[1]);

  expect(await screen.findByRole('alert')).toHaveTextContent('Detach its clubs and move its children first.');
});

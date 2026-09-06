import React from 'react';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import CoachManagement from './CoachManagement';

/**
 * Coaches page: assign a coach to a team FROM THE COACH'S ROW (Maggie,
 * 2026-09-06). The modal offers the club's active teams and the three roles,
 * warns before replacing a head coach, and View Teams lists every role — not
 * just the head-coach teams the old `primary_coach_id=` read returned.
 */

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 51, activeContext: { scope_id: 51, scope_type: 'club' } }),
}));

const coaches = [
  { id: 7, first_name: 'Cal', last_name: 'Coach', email: 'cal@club.test', team_count: 0, status: 'active' },
];

const available = [
  { id: 10, name: 'U10 Sharks', program_name: 'Fall Rec', head_coach: { id: 8, name: 'Jane Doe' } },
  { id: 11, name: 'U12 Rays', program_name: 'Travel', head_coach: null },
];

const calsTeams = [
  { id: 10, name: 'U10 Sharks', program_name: 'Fall Rec', head_coach: { id: 7, name: 'Cal Coach' }, role: 'head_coach' },
  { id: 11, name: 'U12 Rays', program_name: 'Travel', head_coach: null, role: 'assistant_coach' },
  { id: 12, name: 'U14 Eels', program_name: 'Travel', head_coach: null, role: 'team_manager' },
];

const json = (body: any, status = 200) => ({ ok: status < 300, status, json: () => Promise.resolve(body) });

let listTeams: any[] = [];
let assignCalls: any[] = [];
let unassignCalls: any[] = [];

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  listTeams = [];
  assignCalls = [];
  unassignCalls = [];
  (global as any).fetch = jest.fn((url: any, init?: any) => {
    const u = String(url);
    if (u.includes('coaches-gateway.php?action=available')) {
      return Promise.resolve(json({ success: true, coaches }));
    }
    if (u.includes('coach-teams.php?action=list')) {
      return Promise.resolve(json({ success: true, teams: listTeams, available }));
    }
    if (u.includes('coach-teams.php?action=assign')) {
      assignCalls.push(JSON.parse(init.body));
      return Promise.resolve(json({ success: true, team_id: 11, team_name: 'U12 Rays', previous_head_coach: null }));
    }
    if (u.includes('coach-teams.php?action=unassign')) {
      unassignCalls.push(JSON.parse(init.body));
      return Promise.resolve(json({ success: true, removed_roles: ['assistant_coach'] }));
    }
    return Promise.reject(new Error(`unexpected fetch: ${u}`));
  });
  window.alert = jest.fn();
  window.confirm = jest.fn(() => true);
});

afterEach(() => {
  delete (global as any).fetch;
});

const renderPage = () => render(<MemoryRouter><CoachManagement /></MemoryRouter>);

test('every row has Assign to Team next to Edit / View Schedule / View Teams', async () => {
  renderPage();
  const row = (await screen.findByText('Cal Coach')).closest('tr')!;
  expect(within(row).getByRole('button', { name: /^Edit$/i })).toBeInTheDocument();
  expect(within(row).getByRole('button', { name: /View Schedule/i })).toBeInTheDocument();
  expect(within(row).getByRole('button', { name: /View Teams/i })).toBeInTheDocument();
  expect(within(row).getByRole('button', { name: /Assign to Team/i })).toBeInTheDocument();
  expect(within(row).queryByRole('button', { name: /invite|password|login link/i })).not.toBeInTheDocument();
});

test('the modal offers the club teams grouped by program and the three roles', async () => {
  renderPage();
  const row = (await screen.findByText('Cal Coach')).closest('tr')!;
  fireEvent.click(within(row).getByRole('button', { name: /Assign to Team/i }));

  const teamSelect = (await screen.findByLabelText('Team')) as HTMLSelectElement;
  await waitFor(() => expect(within(teamSelect).getByText('U10 Sharks')).toBeInTheDocument());
  expect(within(teamSelect).getByText('U12 Rays')).toBeInTheDocument();
  expect(teamSelect.querySelector('optgroup[label="Fall Rec"]')).not.toBeNull();
  expect(teamSelect.querySelector('optgroup[label="Travel"]')).not.toBeNull();

  const roleSelect = screen.getByLabelText('Role') as HTMLSelectElement;
  expect(Array.from(roleSelect.options).map((o) => o.textContent)).toEqual(['Head coach', 'Assistant coach', 'Team manager']);

  const listUrl = String((global as any).fetch.mock.calls.find((c: any[]) => String(c[0]).includes('action=list'))[0]);
  expect(listUrl).toContain('user_id=7');
  expect(listUrl).toContain('club_id=51');
});

test('choosing Head coach on a team that has one warns who is replaced', async () => {
  renderPage();
  const row = (await screen.findByText('Cal Coach')).closest('tr')!;
  fireEvent.click(within(row).getByRole('button', { name: /Assign to Team/i }));
  const teamSelect = (await screen.findByLabelText('Team')) as HTMLSelectElement;
  await waitFor(() => expect(within(teamSelect).getByText('U10 Sharks')).toBeInTheDocument());

  fireEvent.change(teamSelect, { target: { value: '10' } });
  expect(screen.getByText(/Replaces Jane Doe as head coach of U10 Sharks/)).toBeInTheDocument();

  // Not as assistant — nobody is replaced.
  fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'assistant_coach' } });
  expect(screen.queryByText(/Replaces Jane Doe/)).not.toBeInTheDocument();

  // And not on a team with no head.
  fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'head_coach' } });
  fireEvent.change(teamSelect, { target: { value: '11' } });
  expect(screen.queryByText(/Replaces/)).not.toBeInTheDocument();
});

test('Assign posts user, team and role, then refreshes the team count', async () => {
  renderPage();
  const row = (await screen.findByText('Cal Coach')).closest('tr')!;
  expect(within(row).getByText('0')).toBeInTheDocument();
  fireEvent.click(within(row).getByRole('button', { name: /Assign to Team/i }));
  const teamSelect = (await screen.findByLabelText('Team')) as HTMLSelectElement;
  await waitFor(() => expect(within(teamSelect).getByText('U12 Rays')).toBeInTheDocument());

  const assign = screen.getByRole('button', { name: /^Assign$/i });
  expect(assign).toBeDisabled();
  fireEvent.change(teamSelect, { target: { value: '11' } });
  fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'assistant_coach' } });
  listTeams = [calsTeams[1]];
  fireEvent.click(assign);

  await waitFor(() => expect(assignCalls).toEqual([{ user_id: 7, team_id: 11, role: 'assistant_coach' }]));
  await waitFor(() => expect(screen.queryByLabelText('Team')).not.toBeInTheDocument());
  await waitFor(() => expect(within(screen.getByText('Cal Coach').closest('tr')!).getByText('1')).toBeInTheDocument());
});

test('View Teams lists head, assistant and manager roles with an Unassign control each', async () => {
  listTeams = calsTeams;
  renderPage();
  const row = (await screen.findByText('Cal Coach')).closest('tr')!;
  fireEvent.click(within(row).getByRole('button', { name: /View Teams/i }));

  expect(await screen.findByText('U10 Sharks')).toBeInTheDocument();
  expect(screen.getByText(/Head coach/)).toBeInTheDocument();
  expect(screen.getByText(/Assistant coach/)).toBeInTheDocument();
  expect(screen.getByText(/Team manager/)).toBeInTheDocument();
  expect(screen.getAllByRole('button', { name: /Unassign/i })).toHaveLength(3);

  const rays = screen.getByText('U12 Rays').closest('li')!;
  fireEvent.click(within(rays).getByRole('button', { name: /Unassign/i }));
  await waitFor(() => expect(unassignCalls).toEqual([{ user_id: 7, team_id: 11 }]));
  await waitFor(() => expect(screen.queryByText('U12 Rays')).not.toBeInTheDocument());
  expect(screen.getAllByRole('button', { name: /Unassign/i })).toHaveLength(2);
});

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import AthleteManagement from './AthleteManagement';

jest.mock('../contexts/OrgContext', () => ({ useOrg: () => ({ currentClubId: 32, isClubAdmin: true }) }));
jest.mock('../hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 50 } }) }));
jest.mock('./AthleteForm', () => () => null);
jest.mock('./GuardianManagement', () => () => null);
jest.mock('./communications/EmailCompose', () => () => null);
jest.mock('./communications/SmsCompose', () => () => null);

const athletes = [
  { id: 1, first_name: 'Zoe', last_name: 'Adams', gender: 'Female', grade_level: 5, date_of_birth: '2014-03-01' },
  { id: 2, first_name: 'Amy', last_name: 'Zych', gender: 'Male', grade_level: 2, date_of_birth: '2017-06-01' },
  { id: 3, first_name: 'Max', last_name: 'Brown', gender: 'Male', grade_level: 9, date_of_birth: '2010-01-01' },
];

beforeEach(() => {
  (global as any).fetch = jest.fn((url: any) => {
    const u = String(url);
    const json = (body: any) => Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
    if (u.includes('athletes-gateway')) return json({ athletes });
    if (u.includes('team-players-gateway')) return json({ success: true, team_players: [] });
    if (u.includes('teams-gateway')) return json({ teams: [] });
    return json({});
  });
});

function rowNames(): string[] {
  return screen
    .getAllByRole('link')
    .filter((a) => a.getAttribute('href')?.includes('/enhanced'))
    .map((a) => (a.textContent || '').trim());
}

test('full component: repeated Name clicks keep toggling asc/desc', async () => {
  render(
    <MemoryRouter>
      <AthleteManagement />
    </MemoryRouter>
  );
  await screen.findByText(/Zoe Adams/);

  const nameBtn = screen.getByRole('button', { name: /^Name/ });
  fireEvent.click(nameBtn);
  const asc = rowNames();
  fireEvent.click(nameBtn);
  const desc = rowNames();
  fireEvent.click(nameBtn);
  const ascAgain = rowNames();

  // eslint-disable-next-line no-console
  console.log('asc:', asc, '\ndesc:', desc, '\nasc again:', ascAgain);

  expect(asc).toEqual(['Zoe Adams', 'Max Brown', 'Amy Zych']);
  expect(desc).toEqual(['Amy Zych', 'Max Brown', 'Zoe Adams']);
  expect(ascAgain).toEqual(asc); // third click flips back to asc (never unsorts)
});

test('dedupes duplicate-id rows (multi-guardian household) and still re-sorts', async () => {
  // The gateway used to return a duplicate row for an athlete with 2 guardians.
  const dupRows = [...athletes, { ...athletes[0] }]; // Zoe Adams (id 1) appears twice
  (global as any).fetch = jest.fn((url: any) => {
    const u = String(url);
    const json = (b: any) => Promise.resolve({ ok: true, json: () => Promise.resolve(b) });
    if (u.includes('athletes-gateway')) return json({ athletes: dupRows });
    if (u.includes('team-players-gateway')) return json({ success: true, team_players: [] });
    if (u.includes('teams-gateway')) return json({ teams: [] });
    return json({});
  });

  render(
    <MemoryRouter>
      <AthleteManagement />
    </MemoryRouter>
  );
  await screen.findByText(/Zoe Adams/);

  expect(rowNames().length).toBe(3); // 4 rows in, 3 unique out

  const nameBtn = screen.getByRole('button', { name: /^Name/ });
  fireEvent.click(nameBtn);
  const asc = rowNames();
  fireEvent.click(nameBtn);
  const desc = rowNames();
  expect(asc).toEqual(['Zoe Adams', 'Max Brown', 'Amy Zych']);
  expect(desc).toEqual(['Amy Zych', 'Max Brown', 'Zoe Adams']); // re-sort works (unique keys)
});

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import { AthleteListContent } from './AthleteManagement';

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 32 }),
}));

const athletes = [
  { id: 1, first_name: 'Zoe', last_name: 'Adams', gender: 'Female', grade_level: 5, date_of_birth: '2014-03-01' },
  { id: 2, first_name: 'Amy', last_name: 'Zych', gender: 'Male', grade_level: 2, date_of_birth: '2017-06-01' },
  { id: 3, first_name: 'Max', last_name: 'Brown', gender: 'Male', grade_level: 9, date_of_birth: '2010-01-01' },
];

const noop = () => {};
const baseProps: any = {
  athletes,
  loading: false,
  searchTerm: '', setSearchTerm: noop,
  filterGender: '', setFilterGender: noop,
  filterGrade: '', setFilterGrade: noop,
  handleAddAthlete: noop, handleEditAthlete: noop, handleManageGuardians: noop, handleArchiveAthlete: noop,
  calculateAge: (dob: string) => 2026 - new Date(dob).getFullYear(),
  athleteTeams: {}, showTeamSelector: null, setShowTeamSelector: noop, availableTeams: [], handleAddToTeam: noop,
};

function rowNames(): string[] {
  return screen
    .getAllByRole('link')
    .filter((a) => a.getAttribute('href')?.includes('/enhanced'))
    .map((a) => (a.textContent || '').trim());
}

test('repeated clicks on Name keep sorting (asc then desc)', () => {
  render(
    <MemoryRouter>
      <AthleteListContent {...baseProps} />
    </MemoryRouter>
  );
  const nameBtn = screen.getByRole('button', { name: /^Name/ });

  fireEvent.click(nameBtn);
  const asc = rowNames();
  fireEvent.click(nameBtn);
  const desc = rowNames();

  // eslint-disable-next-line no-console
  console.log('initial->asc:', asc, '\nsecond click->desc:', desc);

  expect(asc).toEqual(['Zoe Adams', 'Max Brown', 'Amy Zych']);
  expect(desc).toEqual(['Amy Zych', 'Max Brown', 'Zoe Adams']);
  expect(desc).not.toEqual(asc);
});

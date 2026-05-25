import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AthleteManagement from './AthleteManagement';

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 100, activeContext: null }),
}));
jest.mock('./AthleteForm', () => () => <div data-testid="athlete-form" />);
jest.mock('./GuardianManagement', () => () => <div data-testid="guardian-management" />);
jest.mock('./communications/EmailCompose', () => () => <div data-testid="email-compose" />);
jest.mock('./communications/SmsCompose', () => () => <div data-testid="sms-compose" />);

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

const athletes = [
  { id: 1, first_name: 'Alice', last_name: 'Anders', gender: 'Female', grade_level: 6, date_of_birth: '2013-01-01' },
  { id: 2, first_name: 'Bob', last_name: 'Brown', gender: 'Male', grade_level: 8, date_of_birth: '2011-01-01' },
  // CA-18: a club athlete with NO team — backend scope now includes these via
  // athletes.club_id, so the list must contain Carol too.
  { id: 3, first_name: 'Carol', last_name: 'Clark', gender: 'Female', grade_level: 6, date_of_birth: '2013-06-06' },
];

beforeEach(() => {
  mockFetch.mockReset();
  mockFetch.mockImplementation((url: string) => {
    if (url.includes('athletes-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, athletes }) });
    }
    if (url.includes('team-players-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, team_players: [] }) });
    }
    if (url.includes('teams-gateway.php')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ teams: [] }) });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
  });
  window.localStorage.setItem('auth_token', 'test-token');
});

const renderPage = () =>
  render(
    <MemoryRouter>
      <AthleteManagement />
    </MemoryRouter>
  );

describe('AthleteManagement search + filters (CA-18)', () => {
  it('lists all club athletes including the team-less one', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Alice Anders')).toBeInTheDocument());
    expect(screen.getByText('Bob Brown')).toBeInTheDocument();
    expect(screen.getByText('Carol Clark')).toBeInTheDocument();
  });

  it('filters by name search', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Alice Anders')).toBeInTheDocument());

    fireEvent.change(screen.getByPlaceholderText('Search athletes...'), {
      target: { value: 'carol' },
    });

    expect(screen.getByText('Carol Clark')).toBeInTheDocument();
    expect(screen.queryByText('Alice Anders')).not.toBeInTheDocument();
    expect(screen.queryByText('Bob Brown')).not.toBeInTheDocument();
  });

  it('filters by gender', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Bob Brown')).toBeInTheDocument());

    // The gender select is the first <select> (All Genders)
    const selects = screen.getAllByRole('combobox');
    fireEvent.change(selects[0], { target: { value: 'Male' } });

    expect(screen.getByText('Bob Brown')).toBeInTheDocument();
    expect(screen.queryByText('Alice Anders')).not.toBeInTheDocument();
    expect(screen.queryByText('Carol Clark')).not.toBeInTheDocument();
  });

  it('filters by grade', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Alice Anders')).toBeInTheDocument());

    const selects = screen.getAllByRole('combobox');
    // Second select is grade; value '6' matches Alice and Carol.
    fireEvent.change(selects[1], { target: { value: '6' } });

    expect(screen.getByText('Alice Anders')).toBeInTheDocument();
    expect(screen.getByText('Carol Clark')).toBeInTheDocument();
    expect(screen.queryByText('Bob Brown')).not.toBeInTheDocument();
  });

  it('combines search and filter', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Alice Anders')).toBeInTheDocument());

    fireEvent.change(screen.getByPlaceholderText('Search athletes...'), {
      target: { value: 'a' }, // matches Alice (Anders) and Carol (Clark) by letter
    });
    const selects = screen.getAllByRole('combobox');
    fireEvent.change(selects[0], { target: { value: 'Female' } });

    expect(screen.getByText('Alice Anders')).toBeInTheDocument();
    expect(screen.getByText('Carol Clark')).toBeInTheDocument();
    expect(screen.queryByText('Bob Brown')).not.toBeInTheDocument();
  });

  it('clear filters restores the full list', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('Alice Anders')).toBeInTheDocument());

    fireEvent.change(screen.getByPlaceholderText('Search athletes...'), {
      target: { value: 'zzz' },
    });
    expect(screen.queryByText('Alice Anders')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /clear filters/i }));

    expect(screen.getByText('Alice Anders')).toBeInTheDocument();
    expect(screen.getByText('Bob Brown')).toBeInTheDocument();
    expect(screen.getByText('Carol Clark')).toBeInTheDocument();
  });
});

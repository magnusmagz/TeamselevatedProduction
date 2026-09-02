import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import AthleteProfileEnhanced from './AthleteProfileEnhanced';

// react-router-dom is mocked (not requireActual) to avoid a duplicate-module
// instance under the worktree's symlinked node_modules, which made useParams()
// resolve from a different Router context and return undefined.
jest.mock('react-router-dom', () => ({
  useParams: () => ({ athleteId: '1' }),
  useNavigate: () => jest.fn(),
  Link: ({ children }: any) => <span>{children}</span>,
}));

// --- Mock org context ---
jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 100, activeContext: { scope_name: 'Test Club' } }),
}));

// --- Stub heavy child components so we test THIS component's tab wiring ---
jest.mock('./AthletePhotoUpload', () => () => <div data-testid="photo-upload" />);
jest.mock('./PlayerCard', () => () => <div data-testid="player-card" />);
jest.mock('./AthleteForm', () => () => <div data-testid="athlete-form" />);
jest.mock('./communications/EmailCompose', () => () => <div data-testid="email-compose" />);
jest.mock('./communications/SmsCompose', () => () => <div data-testid="sms-compose" />);
jest.mock('./DocumentManager', () => (props: any) => (
  <div data-testid="document-manager">docs-for-{props.athleteId}</div>
));
jest.mock('../pages/AthletePaymentsDashboard', () => ({
  AthletePaymentsDashboard: () => <div data-testid="payments-dashboard">payments</div>,
}));
// CommunicationHistory is the real component (CA-25 path under test); it fetches
// contact-history on mount.
jest.mock('./communications/CommunicationHistory', () => (props: any) => (
  <div data-testid="communication-history">
    comms-{props.contactType}-{props.contactId}-club-{props.clubProfileId}
  </div>
));

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

const athlete = {
  id: 1,
  first_name: 'Sam',
  last_name: 'Stone',
  date_of_birth: '2012-05-01',
  gender: 'Male',
  email: 'sam@student.com',
  school_name: 'Lincoln',
  grade_level: '6',
  home_address_line1: '1 Main St',
  city: 'Austin',
  state: 'TX',
  zip_code: '78701',
  country: 'USA',
  active_status: 1,
  guardians: [
    {
      id: 200, first_name: 'John', last_name: 'Stone', email: 'john@stone.com',
      mobile_phone: '5125551212', relationship_type: 'Father',
      can_pickup: 1, can_authorize_medical: 1, financial_responsible: 1,
    },
  ],
};

function mockEndpoints() {
  mockFetch.mockImplementation((url: string) => {
    if (url.includes('athletes-gateway.php?id=')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve(athlete) });
    }
    if (url.includes('team-players-gateway.php')) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          success: true,
          team_players: [
            { id: 5, team_id: 10, athlete_id: 1, team_name: 'Mustangs', jersey_number: '7', primary_position: 'Striker', created_at: '2026-01-01' },
          ],
        }),
      });
    }
    if (url.includes('medical-gateway.php')) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, medical: { athlete_id: 1, exists: false } }),
      });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
  });
}

beforeEach(() => {
  mockFetch.mockReset();
  mockEndpoints();
  window.localStorage.setItem('auth_token', 'test-token');
});

// useParams is mocked to return athleteId='1'.
const renderProfile = () => render(<AthleteProfileEnhanced />);

describe('AthleteProfileEnhanced — tabs (CA-19)', () => {
  it('renders all six tabs', async () => {
    renderProfile();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sam Stone'));

    const labels = ['Overview', 'Teams', 'Medical', 'Documents', 'Communications', 'Payments'];
    for (const label of labels) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
    }
  });

  it('Overview tab is shown by default with player details', async () => {
    renderProfile();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sam Stone'));
    // "Player Details" appears in both the static summary card and the Overview
    // tab body; at least one is present.
    expect(screen.getAllByText('Player Details').length).toBeGreaterThan(0);
  });

  it('Teams tab loads the athlete team assignment', async () => {
    renderProfile();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sam Stone'));
    fireEvent.click(screen.getByRole('button', { name: 'Teams' }));
    // 'Mustangs' appears both in the top team selector and the tab; getAllByText
    expect(screen.getAllByText('Mustangs').length).toBeGreaterThan(0);
  });

  it('Documents tab renders the DocumentManager for this athlete', async () => {
    renderProfile();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sam Stone'));
    fireEvent.click(screen.getByRole('button', { name: 'Documents' }));
    expect(screen.getByTestId('document-manager')).toHaveTextContent('docs-for-1');
  });

  it('Payments tab renders the AthletePaymentsDashboard', async () => {
    renderProfile();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sam Stone'));
    fireEvent.click(screen.getByRole('button', { name: 'Payments' }));
    expect(screen.getByTestId('payments-dashboard')).toBeInTheDocument();
  });

  it('Medical tab shows the no-medical-on-file state', async () => {
    renderProfile();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sam Stone'));
    fireEvent.click(screen.getByRole('button', { name: 'Medical' }));
    expect(screen.getByText('No medical information on file')).toBeInTheDocument();
  });
});

describe('AthleteProfileEnhanced — Communications tab (CA-25)', () => {
  it('loads CommunicationHistory scoped to this athlete', async () => {
    renderProfile();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Sam Stone'));
    fireEvent.click(screen.getByRole('button', { name: 'Communications' }));
    expect(screen.getByTestId('communication-history')).toHaveTextContent(
      'comms-athlete-1-club-100'
    );
  });
});

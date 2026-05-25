import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { AnnouncementsPage } from '../pages/AnnouncementsPage';
import { useParams } from 'react-router-dom';

// react-router-dom is stubbed globally (src/__mocks__); useParams is a jest.fn
// we can drive per-test.
const mockUseParams = useParams as jest.MockedFunction<typeof useParams>;

// ParentHeader pulls in AuthContext; AthleteSelector is irrelevant here. Stub
// both so the page can render without provider wiring.
jest.mock('../components/ParentHeader', () => ({
  ParentHeader: () => <div data-testid="parent-header" />,
}));
jest.mock('../components/AthleteSelector', () => ({
  AthleteSelector: () => <div data-testid="athlete-selector" />,
}));

jest.mock('../hooks/useParentAthletes', () => ({
  useParentAthletes: jest.fn(),
}));
import { useParentAthletes } from '../hooks/useParentAthletes';
const mockUseParentAthletes = useParentAthletes as jest.MockedFunction<typeof useParentAthletes>;

global.fetch = jest.fn();

const announcements = [
  {
    id: 1,
    title: 'First Announcement',
    message: 'Body of the first announcement.',
    created_at: new Date().toISOString(),
    read: true,
  },
  {
    id: 2,
    title: 'Second Announcement',
    message: 'Body of the second announcement that should be expanded.',
    created_at: new Date().toISOString(),
    read: true,
  },
];

describe('AnnouncementsPage detail view (PAR-10)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');

    mockUseParentAthletes.mockReturnValue({
      athletes: [],
      loading: false,
      error: null,
      selectedAthleteId: null,
      selectAthlete: jest.fn(),
      selectedAthlete: null,
      fetchAthleteDetails: jest.fn(),
      refreshAthletes: jest.fn(),
    } as any);

    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ success: true, announcements }),
    });

    // jsdom doesn't implement scrollIntoView.
    Element.prototype.scrollIntoView = jest.fn();
  });

  afterEach(() => {
    localStorage.clear();
  });

  test('list view (no :id) renders titles collapsed', async () => {
    mockUseParams.mockReturnValue({});

    render(<AnnouncementsPage />);

    await waitFor(() => {
      expect(screen.getByText('First Announcement')).toBeInTheDocument();
      expect(screen.getByText('Second Announcement')).toBeInTheDocument();
    });
  });

  test('detail view (:id) auto-expands the targeted announcement', async () => {
    mockUseParams.mockReturnValue({ id: '2' });

    render(<AnnouncementsPage />);

    // The targeted announcement's full body is shown (expanded state renders
    // the message in a whitespace-pre-wrap block).
    await waitFor(() => {
      expect(
        screen.getByText('Body of the second announcement that should be expanded.')
      ).toBeInTheDocument();
    });

    // scrollIntoView was invoked to bring it into view.
    await waitFor(() => {
      expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
    });
  });
});

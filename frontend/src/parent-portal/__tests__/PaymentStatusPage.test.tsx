import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { PaymentStatusPage } from '../pages/PaymentStatusPage';

// Mock fetch
global.fetch = jest.fn();

// Mock the hooks
jest.mock('../../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));

import { useAuth } from '../../contexts/AuthContext';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;

const RouterWrapper: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <BrowserRouter>{children}</BrowserRouter>
);

describe('PaymentStatusPage', () => {
  const mockUser = {
    id: 1,
    email: 'parent@example.com',
    name: 'Parent User',
  };

  // Shape mirrors the invoices.php?action=family API the component maps from
  // (athlete_first/last, program_name, amount_paid, amount_remaining, is_overdue).
  const mockInvoices = [
    {
      id: 1,
      athlete_id: 1,
      athlete_first: 'John',
      athlete_last: 'Doe',
      program_name: 'Registration Fee',
      total_amount: 500,
      amount_paid: 200,
      amount_remaining: 300,
      is_overdue: false,
      status: 'partial',
      due_date: '2024-02-15',
    },
    {
      id: 2,
      athlete_id: 2,
      athlete_first: 'Jane',
      athlete_last: 'Doe',
      program_name: 'Uniform Fee',
      total_amount: 100,
      amount_paid: 100,
      amount_remaining: 0,
      is_overdue: false,
      status: 'paid',
      due_date: '2024-01-15',
    },
    {
      id: 3,
      athlete_id: 1,
      athlete_first: 'John',
      athlete_last: 'Doe',
      program_name: 'Tournament Fee',
      total_amount: 200,
      amount_paid: 0,
      amount_remaining: 200,
      is_overdue: false,
      status: 'pending',
      due_date: '2024-03-01',
    },
  ];

  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');

    mockUseAuth.mockReturnValue({
      user: mockUser,
      isLoading: false,
      error: null,
      login: jest.fn(),
      updateUser: jest.fn(),
      logout: jest.fn(),
      refreshAuth: jest.fn(),
      switchContext: jest.fn(),
      hasPermission: jest.fn(),
      isSuperAdmin: jest.fn(),
    });

    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        invoices: mockInvoices,
      }),
    });
  });

  afterEach(() => {
    localStorage.clear();
  });

  test('renders page title', async () => {
    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    await waitFor(() => {
      expect(screen.getByText('Payments')).toBeInTheDocument();
    });
  });

  test('displays total outstanding balance', async () => {
    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    await waitFor(() => {
      // $300 (partial) + $200 (pending) = $500
      expect(screen.getByText('$500.00')).toBeInTheDocument();
    });
  });

  test('shows Make Payment button when balance due', async () => {
    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    await waitFor(() => {
      // Each outstanding invoice renders a "Pay Now" CTA when a balance is due.
      expect(screen.getAllByText('Pay Now').length).toBeGreaterThan(0);
    });
  });

  test('renders filter tabs', async () => {
    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    await waitFor(() => {
      expect(screen.getByText('Outstanding')).toBeInTheDocument();
      expect(screen.getByText('Paid')).toBeInTheDocument();
      expect(screen.getByText('All')).toBeInTheDocument();
    });
  });

  test('shows outstanding invoices by default', async () => {
    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    await waitFor(() => {
      // Should show partial and pending, not paid
      expect(screen.getByText('Registration Fee')).toBeInTheDocument();
      expect(screen.getByText('Tournament Fee')).toBeInTheDocument();
      expect(screen.queryByText('Uniform Fee')).not.toBeInTheDocument();
    });
  });

  test('filters to paid invoices when tab clicked', async () => {
    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    await waitFor(() => {
      expect(screen.getByText('Registration Fee')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Paid'));

    await waitFor(() => {
      expect(screen.getByText('Uniform Fee')).toBeInTheDocument();
      expect(screen.queryByText('Registration Fee')).not.toBeInTheDocument();
    });
  });

  test('shows loading spinner while fetching', () => {
    (global.fetch as jest.Mock).mockImplementation(() => new Promise(() => {}));

    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    expect(document.querySelector('.animate-spin')).toBeInTheDocument();
  });

  test('shows error message on fetch failure', async () => {
    (global.fetch as jest.Mock).mockRejectedValue(new Error('Network error'));

    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    await waitFor(() => {
      expect(screen.getByText('Failed to load payment information')).toBeInTheDocument();
    });
  });
});

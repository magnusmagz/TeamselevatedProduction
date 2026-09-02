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
      impersonation: null,
      impersonate: jest.fn(),
      stopImpersonation: jest.fn(),
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

  // ---- PAR-16: invoice detail view (line items + amounts + athlete name) ----

  test('highlights overdue invoices', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          success: true,
          invoices: [
            {
              ...mockInvoices[0],
              id: 1,
              is_overdue: true,
              status: 'sent',
              due_date: '2024-01-01',
            },
          ],
        }),
    });

    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    const card = await screen.findByTestId('invoice-card-1');
    // Overdue cards get a red highlight treatment.
    expect(card.className).toMatch(/red/);
    expect(screen.getByText(/Overdue:/)).toBeInTheDocument();
  });

  test('expanding an invoice fetches and renders line items, amounts, and athlete name', async () => {
    // Routed by URL, not by call order: expanding a card ALSO kicks off a
    // supplementary invoice-payments.php request for the payment ledger, and it is
    // fired before the detail fetch. An ordered stub hands that request the detail
    // response and the detail request the family list, so nothing renders.
    const detailResponse = {
      ok: true,
      json: () =>
        Promise.resolve({
          success: true,
          invoice: {
            id: 1,
            invoice_number: 'INV-202402-00001',
            athlete_first: 'John',
            athlete_last: 'Doe',
            program_name: 'Spring Soccer',
            total_amount: 500,
            amount_paid: 200,
            due_date: '2024-02-15',
            items: [
              { id: 11, description: 'Registration', quantity: 1, unit_price: 400, line_total: 400 },
              { id: 12, description: 'Uniform', quantity: 1, unit_price: 100, line_total: 100 },
            ],
          },
        }),
    };

    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      const u = String(url);
      if (u.includes('action=get&id=1')) return Promise.resolve(detailResponse);
      if (u.includes('invoice-payments.php')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ success: true, payments: [] }),
        });
      }
      // invoices.php?action=family
      return Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            success: true,
            invoices: [mockInvoices[0]], // John Doe, Registration Fee, $500/$300 due
          }),
      });
    });

    render(
      <RouterWrapper>
        <PaymentStatusPage />
      </RouterWrapper>
    );

    // Card visible from the family list.
    await screen.findByTestId('invoice-card-1');

    // Expand to load detail.
    fireEvent.click(screen.getByText('View details'));

    // Detail panel renders line items + amounts once the action=get call resolves.
    expect(await screen.findByText('Registration')).toBeInTheDocument();
    expect(screen.getByTestId('invoice-detail-1')).toBeInTheDocument();
    expect(screen.getByText('Uniform')).toBeInTheDocument();
    expect(screen.getByText('$400.00')).toBeInTheDocument();
    expect(screen.getByText('INV-202402-00001')).toBeInTheDocument();
    // Athlete name appears (card + detail header).
    expect(screen.getAllByText('John Doe').length).toBeGreaterThan(0);

    // Detail fetch hit the action=get endpoint for this invoice. Not
    // toHaveBeenLastCalledWith: the payment-ledger request races it and either may
    // land last.
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('action=get&id=1'),
      expect.anything()
    );
  });
});

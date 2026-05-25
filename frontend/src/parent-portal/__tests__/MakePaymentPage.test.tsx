import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MakePaymentPage } from '../pages/MakePaymentPage';

// Drive a specific invoice via ?invoice= and stub navigation.
const mockNavigate = jest.fn();
jest.mock('react-router-dom', () => ({
  useSearchParams: () => [new URLSearchParams('invoice=1')],
  useNavigate: () => mockNavigate,
}));

jest.mock('../components/ParentHeader', () => ({
  ParentHeader: ({ title }: { title: string }) => <header>{title}</header>,
}));

jest.mock('../../contexts/AuthContext', () => ({
  useAuth: jest.fn(),
}));

import { useAuth } from '../../contexts/AuthContext';

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;

// An outstanding invoice: $500 total, $200 paid, $300 due.
const familyResponse = (amountPaid: number, remaining: number, status: string) => ({
  ok: true,
  json: () =>
    Promise.resolve({
      success: true,
      invoices: [
        {
          id: 1,
          athlete_id: 1,
          athlete_first: 'John',
          athlete_last: 'Doe',
          program_name: 'Registration Fee',
          total_amount: 500,
          amount_paid: amountPaid,
          amount_remaining: remaining,
          is_overdue: false,
          status,
          due_date: '2024-02-15',
        },
      ],
    }),
});

const emptyMethods = { ok: true, json: () => Promise.resolve({ success: true, methods: [] }) };

describe('MakePaymentPage payment flow (PAR-17)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
    mockUseAuth.mockReturnValue({ user: { id: 1, email: 'parent@example.com', name: 'P' } } as any);
  });

  afterEach(() => {
    localStorage.clear();
  });

  test('shows the selected invoice balance on load', async () => {
    (global.fetch as jest.Mock) = jest
      .fn()
      .mockResolvedValueOnce(familyResponse(200, 300, 'partial')) // family
      .mockResolvedValueOnce(emptyMethods); // payment-methods

    render(<MakePaymentPage />);

    await waitFor(() => {
      // Balance due appears on the invoice row and the "Pay Full Amount" line.
      expect(screen.getAllByText('$300.00').length).toBeGreaterThan(0);
    });
  });

  test('POSTs to payments.php, refetches balances, and navigates on success', async () => {
    const fetchMock = jest
      .fn()
      // initial load
      .mockResolvedValueOnce(familyResponse(200, 300, 'partial')) // family
      .mockResolvedValueOnce(emptyMethods) // payment-methods
      // after submit
      .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ success: true, amount_applied: 300 }) }) // payments.php
      .mockResolvedValueOnce(familyResponse(500, 0, 'paid')); // family refetch (now paid off)
    (global.fetch as jest.Mock) = fetchMock;

    render(<MakePaymentPage />);

    // Wait for the Pay button (only renders once invoices load).
    const payButton = await screen.findByRole('button', { name: /Pay Now/i });
    fireEvent.click(payButton);

    await waitFor(() => {
      // Payment was recorded against payments.php with the selected invoice.
      const paymentCall = fetchMock.mock.calls.find((c) =>
        String(c[0]).includes('/api/payments.php')
      );
      expect(paymentCall).toBeTruthy();
      expect(paymentCall![1].method).toBe('POST');
      const body = JSON.parse(paymentCall![1].body as string);
      expect(body.invoice_ids).toContain(1);
      expect(body.amount).toBe(300);
    });

    await waitFor(() => {
      // Balances were refetched (family endpoint called a second time)...
      const familyCalls = fetchMock.mock.calls.filter((c) =>
        String(c[0]).includes('action=family')
      );
      expect(familyCalls.length).toBe(2);
      // ...and navigation happened with the success flash.
      expect(mockNavigate).toHaveBeenCalledWith(
        '/parent/payments',
        expect.objectContaining({ state: { message: 'Payment successful!' } })
      );
    });
  });

  test('surfaces a server error and does not navigate', async () => {
    const fetchMock = jest
      .fn()
      .mockResolvedValueOnce(familyResponse(200, 300, 'partial')) // family
      .mockResolvedValueOnce(emptyMethods) // payment-methods
      .mockResolvedValueOnce({ ok: false, json: () => Promise.resolve({ error: 'You are not authorized to pay invoice 1' }) }); // payments.php 403
    (global.fetch as jest.Mock) = fetchMock;

    render(<MakePaymentPage />);

    const payButton = await screen.findByRole('button', { name: /Pay Now/i });
    fireEvent.click(payButton);

    await waitFor(() => {
      expect(screen.getByText('You are not authorized to pay invoice 1')).toBeInTheDocument();
    });
    expect(mockNavigate).not.toHaveBeenCalled();
  });
});

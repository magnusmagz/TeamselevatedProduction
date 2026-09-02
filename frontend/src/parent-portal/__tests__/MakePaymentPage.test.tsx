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

describe('MakePaymentPage payment flow (PAR-17)', () => {
  // jsdom will not navigate, and asserting on the redirect is the point of the
  // Stripe-checkout test, so window.location is replaced with a plain object.
  const realLocation = window.location;
  beforeAll(() => {
    delete (window as unknown as { location?: Location }).location;
    (window as unknown as { location: { href: string } }).location = { href: '' };
  });
  afterAll(() => {
    (window as unknown as { location: Location }).location = realLocation;
  });

  beforeEach(() => {
    jest.clearAllMocks();
    (window as unknown as { location: { href: string } }).location.href = '';
    localStorage.setItem('auth_token', 'test-token');
    mockUseAuth.mockReturnValue({ user: { id: 1, email: 'parent@example.com', name: 'P' } } as any);
  });

  afterEach(() => {
    localStorage.clear();
  });

  test('shows the selected invoice balance on load', async () => {
    (global.fetch as jest.Mock) = jest
      .fn()
      .mockResolvedValueOnce(familyResponse(200, 300, 'partial')); // family

    render(<MakePaymentPage />);

    await waitFor(() => {
      // Balance due appears on the invoice row and the "Pay Full Amount" line.
      expect(screen.getAllByText('$300.00').length).toBeGreaterThan(0);
    });
  });

  test('POSTs to checkout-sessions.php and hands the browser to Stripe', async () => {
    // The parent no longer pays inside the portal: this page opens a hosted Stripe
    // Checkout session and redirects. The webhook - not the redirect back - is what
    // marks the invoice paid, so there is deliberately no refetch and no navigate()
    // here. (The earlier version of this test asserted a POST to payments.php plus an
    // in-app navigate; that flow was replaced by Stripe Connect.)
    const fetchMock = jest
      .fn()
      .mockResolvedValueOnce(familyResponse(200, 300, 'partial')) // family
      .mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ success: true, url: 'https://checkout.stripe.com/c/pay/cs_test_123' }),
      }); // checkout-sessions.php
    (global.fetch as jest.Mock) = fetchMock;

    render(<MakePaymentPage />);

    // Wait for the Pay button (only renders once invoices load).
    const payButton = await screen.findByRole('button', { name: /Pay Now/i });
    fireEvent.click(payButton);

    await waitFor(() => {
      const checkoutCall = fetchMock.mock.calls.find((c) =>
        String(c[0]).includes('/api/checkout-sessions.php')
      );
      expect(checkoutCall).toBeTruthy();
      expect(checkoutCall![1].method).toBe('POST');
      const body = JSON.parse(checkoutCall![1].body as string);
      expect(body.invoice_ids).toContain(1);
      expect(body.amount).toBe(300);
      expect(body.return_context).toBe('parent');
    });

    // The browser is sent to the Stripe-hosted page.
    await waitFor(() => {
      expect(window.location.href).toBe('https://checkout.stripe.com/c/pay/cs_test_123');
    });

    // Nothing is marked paid client-side, and the parent is not routed away by us.
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(screen.queryByText('Could not start payment')).not.toBeInTheDocument();
  });

  test('surfaces a server error and does not navigate', async () => {
    const fetchMock = jest
      .fn()
      .mockResolvedValueOnce(familyResponse(200, 300, 'partial')) // family
      .mockResolvedValueOnce({
        ok: false,
        json: () => Promise.resolve({ error: 'You are not authorized to pay invoice 1' }),
      }); // checkout-sessions.php 403
    (global.fetch as jest.Mock) = fetchMock;

    render(<MakePaymentPage />);

    const payButton = await screen.findByRole('button', { name: /Pay Now/i });
    fireEvent.click(payButton);

    await waitFor(() => {
      expect(screen.getByText('You are not authorized to pay invoice 1')).toBeInTheDocument();
    });
    expect(mockNavigate).not.toHaveBeenCalled();
    // The refusal must not have moved the browser anywhere.
    expect(window.location.href).toBe('');
  });
});

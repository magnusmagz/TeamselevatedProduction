import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { BrowserRouter, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { PaymentCheckout } from './PaymentCheckout';

// react-router-dom is served by the manual mock in src/__mocks__/react-router-dom.tsx,
// which Jest applies to node modules automatically. jest.requireActual cannot be used
// here: react-router-dom 7 declares its entry point through "exports" and its legacy
// "main" (dist/main.js) does not exist, so Jest 27's resolver cannot find the real
// module at all - which is what made this whole suite fail to run.
//
// The mock's hooks are jest.fn()s and CRA sets resetMocks: true, so their return
// values have to be re-established in beforeEach or they hand back undefined.
const mockNavigate = jest.fn();

// Mock OrgContext so the page can read the active club id without an OrgProvider.
jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({
    currentClubId: 32,
    activeContext: { role: 'club_admin', scope_type: 'club', scope_id: 32, scope_name: 'Test Club' },
    availableContexts: [],
    switchToContext: jest.fn(),
    isClubAdmin: true,
  }),
}));

// Mock fetch
global.fetch = jest.fn();

const mockPaymentData = {
  success: true,
  athlete: {
    id: 1,
    name: 'John Doe',
    total_owed: '500.00',
    total_paid: '200.00',
    total_remaining: '300.00'
  },
  payments: [
    {
      id: 101,
      item_name: 'Registration Fee',
      program_name: 'Fall Soccer - U12',
      amount_remaining: '150.00',
      status: 'pending'
    }
  ]
};

// The page fires TWO independent requests on mount: athlete-payments.php for the
// invoice, and payment-plans.php?action=list for the club's plan options. A blanket
// mockResolvedValue answers BOTH with the athlete payload, so `data.plans` comes back
// undefined - which used to take the whole page down (`paymentPlans.length` on
// undefined) and now degrades to the "plans unavailable" notice instead. Either way the
// stub routes by URL so the two requests can be answered separately; `plans` overrides
// only the payment-plans.php response.
const mockApi = (opts: { submit?: unknown; plans?: { ok?: boolean; body?: unknown } } = {}) => {
  (fetch as jest.Mock).mockImplementation((url: string) => {
    const u = String(url);
    if (u.includes('payment-plans.php')) {
      const plans = opts.plans ?? { ok: true, body: { success: true, plans: [] } };
      return Promise.resolve({
        ok: plans.ok !== false,
        status: plans.ok === false ? 500 : 200,
        json: async () => plans.body ?? { success: true, plans: [] },
      });
    }
    if (u.includes('payments-stub.php')) {
      return Promise.resolve({
        ok: true,
        json: async () => opts.submit ?? { success: true, transaction_id: 'txn_123' },
      });
    }
    // athlete-payments.php
    return Promise.resolve({ ok: true, json: async () => mockPaymentData });
  });
};

describe('PaymentCheckout', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockReset();
    mockNavigate.mockClear();
    (useNavigate as jest.Mock).mockReturnValue(mockNavigate);
    (useParams as jest.Mock).mockReturnValue({ athleteId: '1', paymentId: '101' });
    (useSearchParams as jest.Mock).mockReturnValue([new URLSearchParams(), jest.fn()]);
    process.env.REACT_APP_PAYMENT_MODE = 'demo';
  });

  const renderComponent = () => {
    return render(
      <BrowserRouter>
        <PaymentCheckout />
      </BrowserRouter>
    );
  };

  test('renders loading state initially', () => {
    (fetch as jest.Mock).mockImplementation(() => new Promise(() => {}));
    renderComponent();

    expect(screen.getByText(/Loading payment details/i)).toBeInTheDocument();
  });

  test('renders payment form with payment details', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      expect(screen.getByText('Payment Checkout')).toBeInTheDocument();
    });

    expect(screen.getByText('John Doe')).toBeInTheDocument();
    expect(screen.getByText(/Registration Fee/)).toBeInTheDocument();
  });

  test('displays demo mode banner', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      expect(screen.getByText(/Demo Mode - Test Cards/i)).toBeInTheDocument();
    });

    expect(screen.getByText(/4242 4242 4242 4242/)).toBeInTheDocument();
  });

  test('allows selecting payment method', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      expect(screen.getByText('Payment Method')).toBeInTheDocument();
    });

    const cardButton = screen.getByText('Credit/Debit Card');
    const achButton = screen.getByText('Bank Account (ACH)');

    expect(cardButton).toBeInTheDocument();
    expect(achButton).toBeInTheDocument();
  });

  test('validates card number input', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      const cardInput = screen.getByPlaceholderText(/4242 4242 4242 4242/);
      expect(cardInput).toBeInTheDocument();
    });
  });

  test('formats card number with spaces', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      const cardInput = screen.getByPlaceholderText(/4242 4242 4242 4242/) as HTMLInputElement;
      fireEvent.change(cardInput, { target: { value: '4242424242424242' } });

      expect(cardInput.value).toBe('4242 4242 4242 4242');
    });
  });

  test('displays discount code field', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      expect(screen.getByText(/Discount Code/i)).toBeInTheDocument();
      expect(screen.getByPlaceholderText(/Enter code/i)).toBeInTheDocument();
    });
  });

  test('submits payment successfully', async () => {
    mockApi({ submit: { success: true, transaction_id: 'txn_123' } });

    renderComponent();

    await waitFor(() => {
      const cardInput = screen.getByPlaceholderText(/4242 4242 4242 4242/);
      fireEvent.change(cardInput, { target: { value: '4242424242424242' } });
    });

    const monthInput = screen.getByPlaceholderText('MM');
    const yearInput = screen.getByPlaceholderText('YY');
    const cvvInput = screen.getByPlaceholderText('123');

    fireEvent.change(monthInput, { target: { value: '12' } });
    fireEvent.change(yearInput, { target: { value: '25' } });
    fireEvent.change(cvvInput, { target: { value: '123' } });

    const submitButton = screen.getByRole('button', { name: /Pay/i });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Payment Successful/i)).toBeInTheDocument();
    });
  });

  test('displays error on payment failure', async () => {
    mockApi({ submit: { success: false, error: 'Payment declined' } });

    renderComponent();

    await waitFor(() => {
      const submitButton = screen.getByRole('button', { name: /Pay/i });
      fireEvent.click(submitButton);
    });

    await waitFor(() => {
      expect(screen.getByText(/Payment declined/i)).toBeInTheDocument();
    });
  });

  test('disables submit button during processing', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      const submitButton = screen.getByRole('button', { name: /Pay/i }) as HTMLButtonElement;
      fireEvent.click(submitButton);

      expect(submitButton).toBeDisabled();
    });
  });

  // The plans request is optional to this page: a failure must degrade to pay-in-full
  // with a notice, never a blank page and never a silent empty plans list (which would
  // claim the club offers no plans - a different fact from "we could not ask").
  describe('when payment plans cannot be loaded', () => {
    let consoleError: jest.SpyInstance;

    beforeEach(() => {
      consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
      consoleError.mockRestore();
    });

    const expectCheckoutWithoutPlans = async () => {
      await waitFor(() => {
        expect(screen.getByText('Payment Checkout')).toBeInTheDocument();
      });

      // The notice, and the pay-in-full path still fully usable.
      expect(
        screen.getByText(/Payment plans are unavailable right now; pay in full below/i)
      ).toBeInTheDocument();
      expect(screen.getByText('John Doe')).toBeInTheDocument();
      expect(screen.getByText('Payment Details')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Pay \$150\.00/ })).toBeInTheDocument();

      // and no plans UI pretending the club has none
      expect(screen.queryByText('Payment Options')).not.toBeInTheDocument();
      expect(screen.queryByText('Use Payment Plan')).not.toBeInTheDocument();
    };

    test('success:true with no plans key renders pay-in-full plus the notice', async () => {
      mockApi({ plans: { ok: true, body: { success: true } } });

      renderComponent();

      await expectCheckoutWithoutPlans();
    });

    test('a 500 from payment-plans renders pay-in-full plus the notice', async () => {
      mockApi({ plans: { ok: false, body: { success: false, error: 'boom' } } });

      renderComponent();

      await expectCheckoutWithoutPlans();
    });
  });

  test('shows ACH coming soon message', async () => {
    mockApi();

    renderComponent();

    await waitFor(() => {
      const achButton = screen.getByText('Bank Account (ACH)');
      fireEvent.click(achButton);
    });

    expect(screen.getByText(/ACH payment processing is coming soon/i)).toBeInTheDocument();
  });
});

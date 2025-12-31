import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { PaymentCheckout } from './PaymentCheckout';

// Mock react-router-dom
const mockNavigate = jest.fn();
jest.mock('react-router-dom', () => ({
  ...jest.requireActual('react-router-dom'),
  useNavigate: () => mockNavigate,
  useParams: () => ({ athleteId: '1', paymentId: '101' })
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

describe('PaymentCheckout', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
    mockNavigate.mockClear();
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
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

    renderComponent();

    await waitFor(() => {
      expect(screen.getByText('Payment Checkout')).toBeInTheDocument();
    });

    expect(screen.getByText('John Doe')).toBeInTheDocument();
    expect(screen.getByText(/Registration Fee/)).toBeInTheDocument();
  });

  test('displays demo mode banner', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

    renderComponent();

    await waitFor(() => {
      expect(screen.getByText(/Demo Mode - Test Cards/i)).toBeInTheDocument();
    });

    expect(screen.getByText(/4242 4242 4242 4242/)).toBeInTheDocument();
  });

  test('allows selecting payment method', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

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
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

    renderComponent();

    await waitFor(() => {
      const cardInput = screen.getByPlaceholderText(/4242 4242 4242 4242/);
      expect(cardInput).toBeInTheDocument();
    });
  });

  test('formats card number with spaces', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

    renderComponent();

    await waitFor(() => {
      const cardInput = screen.getByPlaceholderText(/4242 4242 4242 4242/) as HTMLInputElement;
      fireEvent.change(cardInput, { target: { value: '4242424242424242' } });

      expect(cardInput.value).toBe('4242 4242 4242 4242');
    });
  });

  test('displays discount code field', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

    renderComponent();

    await waitFor(() => {
      expect(screen.getByText(/Discount Code/i)).toBeInTheDocument();
      expect(screen.getByPlaceholderText(/Enter code/i)).toBeInTheDocument();
    });
  });

  test('submits payment successfully', async () => {
    (fetch as jest.Mock)
      .mockResolvedValueOnce({
        json: async () => mockPaymentData
      })
      .mockResolvedValueOnce({
        json: async () => ({ success: true, transaction_id: 'txn_123' })
      });

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
    (fetch as jest.Mock)
      .mockResolvedValueOnce({
        json: async () => mockPaymentData
      })
      .mockResolvedValueOnce({
        json: async () => ({ success: false, error: 'Payment declined' })
      });

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
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

    renderComponent();

    await waitFor(() => {
      const submitButton = screen.getByRole('button', { name: /Pay/i }) as HTMLButtonElement;
      fireEvent.click(submitButton);

      expect(submitButton).toBeDisabled();
    });
  });

  test('shows ACH coming soon message', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockPaymentData
    });

    renderComponent();

    await waitFor(() => {
      const achButton = screen.getByText('Bank Account (ACH)');
      fireEvent.click(achButton);
    });

    expect(screen.getByText(/ACH payment processing is coming soon/i)).toBeInTheDocument();
  });
});

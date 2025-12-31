import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { DemoModeBanner } from './DemoModeBanner';

describe('DemoModeBanner', () => {
  const originalEnv = process.env;

  beforeEach(() => {
    jest.resetModules();
    process.env = { ...originalEnv };
  });

  afterEach(() => {
    process.env = originalEnv;
  });

  test('renders banner when REACT_APP_PAYMENT_MODE is demo', () => {
    process.env.REACT_APP_PAYMENT_MODE = 'demo';
    render(<DemoModeBanner />);

    expect(screen.getByText('DEMO MODE')).toBeInTheDocument();
    expect(screen.getByText(/No real payments will be processed/i)).toBeInTheDocument();
  });

  test('does not render banner when REACT_APP_PAYMENT_MODE is not demo', () => {
    process.env.REACT_APP_PAYMENT_MODE = 'live';
    render(<DemoModeBanner />);

    expect(screen.queryByText('DEMO MODE')).not.toBeInTheDocument();
  });

  test('does not render banner when REACT_APP_PAYMENT_MODE is not set', () => {
    delete process.env.REACT_APP_PAYMENT_MODE;
    render(<DemoModeBanner />);

    expect(screen.queryByText('DEMO MODE')).not.toBeInTheDocument();
  });

  test('displays test card number', () => {
    process.env.REACT_APP_PAYMENT_MODE = 'demo';
    render(<DemoModeBanner />);

    expect(screen.getByText(/4242 4242 4242 4242/)).toBeInTheDocument();
  });

  test('can be dismissed by clicking close button', () => {
    process.env.REACT_APP_PAYMENT_MODE = 'demo';
    render(<DemoModeBanner />);

    const closeButton = screen.getByRole('button', { name: /dismiss/i });
    fireEvent.click(closeButton);

    expect(screen.queryByText('DEMO MODE')).not.toBeInTheDocument();
  });

  test('contains test icon emoji', () => {
    process.env.REACT_APP_PAYMENT_MODE = 'demo';
    const { container } = render(<DemoModeBanner />);

    expect(container.textContent).toContain('🧪');
  });
});

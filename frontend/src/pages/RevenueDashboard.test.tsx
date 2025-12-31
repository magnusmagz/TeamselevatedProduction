import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { RevenueDashboard } from './RevenueDashboard';

// Mock fetch
global.fetch = jest.fn();

const mockRevenueSummary = {
  success: true,
  summary: {
    total_revenue: '15090.00',
    collected: '9301.00',
    outstanding: '5789.00',
    collection_rate: '61.6'
  },
  by_program: [
    {
      id: 76,
      name: 'Winter Basketball - U14',
      athletes: 25,
      revenue: '4200.00',
      collected: '2275.00',
      outstanding: '1925.00',
      collection_rate: '54.2'
    },
    {
      id: 75,
      name: 'Fall Soccer - U12',
      athletes: 25,
      revenue: '4050.00',
      collected: '2820.00',
      outstanding: '1230.00',
      collection_rate: '69.6'
    }
  ],
  by_status: {
    paid: { count: 50, amount: 6975 },
    partial: { count: 30, amount: 4150 },
    pending: { count: 20, amount: 3965 }
  }
};

describe('RevenueDashboard', () => {
  beforeEach(() => {
    (fetch as jest.Mock).mockClear();
  });

  test('renders loading state initially', () => {
    (fetch as jest.Mock).mockImplementation(() => new Promise(() => {}));
    render(<RevenueDashboard />);

    expect(screen.getByText(/Loading revenue data/i)).toBeInTheDocument();
  });

  test('renders revenue dashboard with data', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockRevenueSummary
    });

    render(<RevenueDashboard />);

    await waitFor(() => {
      expect(screen.getByText('Revenue Dashboard')).toBeInTheDocument();
    });

    expect(screen.getByText('Total Revenue')).toBeInTheDocument();
    expect(screen.getByText('$15,090.00')).toBeInTheDocument();
    expect(screen.getByText('Collected')).toBeInTheDocument();
    expect(screen.getByText('$9,301.00')).toBeInTheDocument();
    expect(screen.getByText('Outstanding')).toBeInTheDocument();
    expect(screen.getByText('$5,789.00')).toBeInTheDocument();
    expect(screen.getByText(/61\.6%/)).toBeInTheDocument();
  });

  test('renders program breakdown table', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockRevenueSummary
    });

    render(<RevenueDashboard />);

    await waitFor(() => {
      expect(screen.getByText('Revenue by Program')).toBeInTheDocument();
    });

    expect(screen.getByText('Winter Basketball - U14')).toBeInTheDocument();
    expect(screen.getByText('Fall Soccer - U12')).toBeInTheDocument();
  });

  test('renders payment status breakdown', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockRevenueSummary
    });

    render(<RevenueDashboard />);

    await waitFor(() => {
      expect(screen.getByText('Payment Status Breakdown')).toBeInTheDocument();
    });

    expect(screen.getByText(/paid/i)).toBeInTheDocument();
    expect(screen.getByText(/partial/i)).toBeInTheDocument();
    expect(screen.getByText(/pending/i)).toBeInTheDocument();
  });

  test('handles error state gracefully', async () => {
    (fetch as jest.Mock).mockRejectedValue(new Error('API Error'));

    render(<RevenueDashboard />);

    await waitFor(() => {
      expect(screen.getByText(/No revenue data available/i)).toBeInTheDocument();
    });
  });

  test('formats currency correctly', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockRevenueSummary
    });

    render(<RevenueDashboard />);

    await waitFor(() => {
      expect(screen.getByText('$15,090.00')).toBeInTheDocument();
    });
  });

  test('displays collection rate as percentage', async () => {
    (fetch as jest.Mock).mockResolvedValue({
      json: async () => mockRevenueSummary
    });

    render(<RevenueDashboard />);

    await waitFor(() => {
      expect(screen.getByText(/61\.6%/)).toBeInTheDocument();
    });
  });
});

import React from 'react';
import { render, screen } from '@testing-library/react';
import { BottomNavigation } from '../components/BottomNavigation';

// Mock react-router-dom with proper implementation
const mockLocation = {
  pathname: '/parent',
  search: '',
  hash: '',
  state: null,
  key: 'default',
};

jest.mock('react-router-dom', () => ({
  NavLink: ({ to, children, className }: { to: string; children: React.ReactNode; className?: string | ((props: { isActive: boolean }) => string) }) => {
    const computedClassName = typeof className === 'function' ? className({ isActive: false }) : className;
    return <a href={to} className={computedClassName}>{children}</a>;
  },
  useLocation: () => mockLocation,
}));

describe('BottomNavigation', () => {
  beforeEach(() => {
    mockLocation.pathname = '/parent';
  });

  test('renders all 5 navigation items', () => {
    render(<BottomNavigation />);

    expect(screen.getByText('Home')).toBeInTheDocument();
    expect(screen.getByText('Schedule')).toBeInTheDocument();
    expect(screen.getByText('Payments')).toBeInTheDocument();
    expect(screen.getByText('Chat')).toBeInTheDocument();
    expect(screen.getByText('More')).toBeInTheDocument();
  });

  test('navigation links have correct hrefs', () => {
    render(<BottomNavigation />);

    expect(screen.getByText('Home').closest('a')).toHaveAttribute('href', '/parent');
    expect(screen.getByText('Schedule').closest('a')).toHaveAttribute('href', '/parent/schedule');
    expect(screen.getByText('Payments').closest('a')).toHaveAttribute('href', '/parent/payments');
    expect(screen.getByText('Chat').closest('a')).toHaveAttribute('href', '/parent/chat');
    expect(screen.getByText('More').closest('a')).toHaveAttribute('href', '/parent/more');
  });

  test('has fixed positioning at bottom', () => {
    const { container } = render(<BottomNavigation />);

    const nav = container.querySelector('nav');
    expect(nav).toHaveClass('fixed', 'bottom-0');
  });
});

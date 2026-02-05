import React from 'react';

// Mock implementation of react-router-dom for Jest tests

export const BrowserRouter: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <>{children}</>
);

export const MemoryRouter: React.FC<{ children: React.ReactNode; initialEntries?: string[] }> = ({ children }) => (
  <>{children}</>
);

export const Routes: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <>{children}</>
);

export const Route: React.FC<{ path?: string; element?: React.ReactNode; children?: React.ReactNode }> = ({ element, children }) => (
  <>{element || children}</>
);

export const Link: React.FC<{ to: string; children: React.ReactNode; className?: string }> = ({ to, children, className }) => (
  <a href={to} className={className}>{children}</a>
);

export const NavLink: React.FC<{
  to: string;
  children: React.ReactNode | ((props: { isActive: boolean }) => React.ReactNode);
  className?: string | ((props: { isActive: boolean }) => string);
  end?: boolean;
}> = ({ to, children, className }) => {
  const isActive = false;
  const childContent = typeof children === 'function' ? children({ isActive }) : children;
  const classNameValue = typeof className === 'function' ? className({ isActive }) : className;
  return <a href={to} className={classNameValue}>{childContent}</a>;
};

export const Outlet: React.FC = () => <div data-testid="outlet" />;

export const Navigate: React.FC<{ to: string; replace?: boolean }> = () => null;

// Default location object
const defaultLocation = {
  pathname: '/',
  search: '',
  hash: '',
  state: null,
  key: 'default',
};

// Hooks
export const useNavigate = jest.fn(() => jest.fn());
export const useParams = jest.fn(() => ({}));
export const useSearchParams = jest.fn(() => [new URLSearchParams(), jest.fn()]);
export const useLocation = jest.fn().mockReturnValue(defaultLocation);
export const useMatch = jest.fn(() => null);

// Export everything
export default {
  BrowserRouter,
  MemoryRouter,
  Routes,
  Route,
  Link,
  NavLink,
  Outlet,
  Navigate,
  useNavigate,
  useParams,
  useSearchParams,
  useLocation,
  useMatch,
};

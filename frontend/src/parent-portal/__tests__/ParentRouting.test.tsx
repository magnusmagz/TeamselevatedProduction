import React from 'react';
import { render, screen } from '@testing-library/react';
import { ParentErrorBoundary } from '../components/ParentErrorBoundary';

// NOTE: this project stubs react-router-dom in src/__mocks__ because RR v7 ships
// ESM that CRA's Jest config will not transform. That stub renders <Navigate>
// as null and <Outlet> as a placeholder, so real path matching / redirect
// behavior for the catch-all route cannot be exercised in RTL here. What PAR-46
// actually needs verified — that a bad :id or a thrown render error does not
// white-screen or leak — is covered by the error-boundary tests below, which
// run against the real ParentErrorBoundary implementation.

// A page that throws when given a bad id, simulating a component that blows up
// on a malformed route param.
const PageWithBadId: React.FC<{ id?: string }> = ({ id }) => {
  if (id === 'boom' || id === undefined) {
    throw new Error('bad id');
  }
  return <div>Loaded athlete {id}</div>;
};

describe('ParentErrorBoundary (PAR-46 bad-URL / bad-:id safety)', () => {
  beforeEach(() => {
    // The boundary logs caught errors; silence the expected noise.
    jest.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    (console.error as jest.Mock).mockRestore();
  });

  test('renders children normally when nothing throws', () => {
    render(
      <ParentErrorBoundary>
        <PageWithBadId id="123" />
      </ParentErrorBoundary>
    );

    expect(screen.getByText('Loaded athlete 123')).toBeInTheDocument();
    expect(screen.queryByText('Something went wrong')).not.toBeInTheDocument();
  });

  test('a bad :id that throws shows the safe fallback, not a white screen', () => {
    render(
      <ParentErrorBoundary>
        <PageWithBadId id="boom" />
      </ParentErrorBoundary>
    );

    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
    // No raw error message / stack leaked to the user.
    expect(screen.queryByText(/bad id/)).not.toBeInTheDocument();
  });

  test('a missing :id that throws shows the safe fallback', () => {
    render(
      <ParentErrorBoundary>
        <PageWithBadId />
      </ParentErrorBoundary>
    );

    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
  });

  test('fallback offers a route back to the parent home', () => {
    render(
      <ParentErrorBoundary>
        <PageWithBadId id="boom" />
      </ParentErrorBoundary>
    );

    expect(screen.getByText('Back to Home')).toHaveAttribute('href', '/parent');
  });
});

// Integration: the parent layout must wrap its routed content in the boundary so
// that a per-page crash is contained while the layout chrome survives.
jest.mock('../components/BottomNavigation', () => ({
  BottomNavigation: () => <div data-testid="bottom-nav" />,
}));
jest.mock('../components/SponsorMarquee', () => ({
  SponsorMarquee: () => <div data-testid="sponsor-marquee" />,
}));
jest.mock('../components/InstallPrompt', () => ({
  InstallPrompt: () => null,
}));
jest.mock('../contexts/ChatContext', () => ({
  ChatProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

import { ParentPortalLayout } from '../ParentPortalLayout';

const Exploding: React.FC = () => {
  throw new Error('kaboom');
};

describe('ParentPortalLayout error containment (PAR-46)', () => {
  beforeEach(() => {
    jest.spyOn(console, 'error').mockImplementation(() => {});
  });
  afterEach(() => {
    (console.error as jest.Mock).mockRestore();
  });

  test('a crashing child renders the boundary fallback but keeps layout chrome', () => {
    render(
      <ParentPortalLayout>
        <Exploding />
      </ParentPortalLayout>
    );

    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
    // Bottom nav (layout chrome outside the boundary) is still present.
    expect(screen.getByTestId('bottom-nav')).toBeInTheDocument();
  });

  test('a healthy child renders inside the layout', () => {
    render(
      <ParentPortalLayout>
        <div>healthy content</div>
      </ParentPortalLayout>
    );

    expect(screen.getByText('healthy content')).toBeInTheDocument();
    expect(screen.queryByText('Something went wrong')).not.toBeInTheDocument();
  });
});

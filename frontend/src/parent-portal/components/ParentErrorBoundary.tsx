import React from 'react';

interface ParentErrorBoundaryProps {
  children: React.ReactNode;
}

interface ParentErrorBoundaryState {
  hasError: boolean;
}

/**
 * Error boundary for the parent portal. A bad :id, a thrown render error, or a
 * malformed API response should show a safe fallback instead of white-screening
 * or leaking a raw stack trace to a parent user.
 */
export class ParentErrorBoundary extends React.Component<
  ParentErrorBoundaryProps,
  ParentErrorBoundaryState
> {
  constructor(props: ParentErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError(): ParentErrorBoundaryState {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    // Log for diagnostics; do not surface details to the user.
    console.error('Parent portal error boundary caught an error:', error, info);
  }

  handleReset = () => {
    this.setState({ hasError: false });
  };

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
          <div className="text-center max-w-sm">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
              />
            </svg>
            <h2 className="mt-3 text-lg font-medium text-brand-primary">
              Something went wrong
            </h2>
            <p className="mt-1 text-sm text-gray-500">
              We couldn't load this page. Please try again.
            </p>
            <a
              href="/parent"
              onClick={this.handleReset}
              className="mt-4 inline-block bg-brand-primary text-white px-4 py-2 text-sm font-medium rounded hover:bg-brand-primary-hover transition-colors"
            >
              Back to Home
            </a>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ParentErrorBoundary;

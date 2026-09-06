import React from 'react';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import PageHeader from './PageHeader';

const renderHeader = (ui: React.ReactElement) => render(<MemoryRouter>{ui}</MemoryRouter>);

describe('PageHeader', () => {
  it('renders exactly one h1 with the title and the one chosen size', () => {
    renderHeader(<PageHeader title="Program Management" />);
    const h1s = screen.getAllByRole('heading', { level: 1 });
    expect(h1s).toHaveLength(1);
    expect(h1s[0]).toHaveTextContent('Program Management');
    expect(h1s[0].className).toContain('text-2xl');
    expect(h1s[0].className).toContain('text-brand-primary');
    expect(h1s[0].className).toContain('uppercase');
  });

  it('renders the subtitle only when given', () => {
    const { rerender } = renderHeader(<PageHeader title="Crew" />);
    expect(screen.queryByText('Crew & family across your club')).not.toBeInTheDocument();
    rerender(
      <MemoryRouter>
        <PageHeader title="Crew" subtitle="Crew & family across your club" />
      </MemoryRouter>
    );
    expect(screen.getByText('Crew & family across your club')).toBeInTheDocument();
  });

  it('renders a back link to the given route', () => {
    renderHeader(<PageHeader title="Tournament" backTo="/tournaments" backLabel="Back to Tournaments" />);
    const link = screen.getByRole('link', { name: /Back to Tournaments/ });
    expect(link).toHaveAttribute('href', '/tournaments');
  });

  it('defaults the back label to "Back"', () => {
    renderHeader(<PageHeader title="T" backTo="/x" />);
    expect(screen.getByRole('link', { name: /Back/ })).toHaveAttribute('href', '/x');
  });

  it('renders actions right-aligned with the primary action first', () => {
    renderHeader(
      <PageHeader
        title="Programs"
        actions={
          <>
            <button>+ Program</button>
            <button>Export</button>
          </>
        }
      />
    );
    const actions = screen.getByTestId('page-header-actions');
    const buttons = within(actions).getAllByRole('button');
    expect(buttons.map((b) => b.textContent)).toEqual(['+ Program', 'Export']);
    expect(actions.className).toContain('sm:justify-end');
  });

  it('renders the meta slot under the title', () => {
    renderHeader(<PageHeader title="Athletes" meta={<span>42 athletes</span>} />);
    expect(screen.getByText('42 athletes')).toBeInTheDocument();
  });

  it('stacks on mobile and rows on sm+', () => {
    renderHeader(<PageHeader title="X" actions={<button>Go</button>} />);
    const header = screen.getByTestId('page-header');
    const row = header.querySelector('.flex-col.sm\\:flex-row');
    expect(row).not.toBeNull();
  });
});

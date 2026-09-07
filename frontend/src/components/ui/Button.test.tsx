import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Button, { LinkButton, buttonClassName } from './Button';

describe('Button', () => {
  it('renders the primary variant on the brand tokens by default', () => {
    render(<Button>Save</Button>);
    const b = screen.getByRole('button', { name: 'Save' });
    expect(b.className).toContain('bg-brand-primary');
    expect(b.className).toContain('hover:bg-brand-primary-hover');
    expect(b.className).toContain('text-white');
    expect(b.className).toContain('uppercase');
    expect(b.className).toContain('rounded-md');
    expect(b.className).toContain('focus-visible:ring-brand-accent');
  });

  it.each([
    ['secondary', ['bg-white', 'border-brand-secondary', 'text-brand-primary']],
    ['danger', ['bg-red-600', 'hover:bg-red-700', 'text-white']],
    ['ghost', ['bg-transparent', 'text-brand-primary', 'hover:bg-brand-light/40']],
    ['link', ['hover:underline', 'text-brand-primary', 'p-0']],
    ['danger-link', ['hover:underline', 'text-red-600', 'p-0']],
  ] as const)('renders the %s variant classes', (variant, classes) => {
    render(<Button variant={variant}>X</Button>);
    const b = screen.getByRole('button', { name: 'X' });
    for (const c of classes) expect(b.className).toContain(c);
  });

  it('defaults to type="button" so it never submits a form by accident', () => {
    const onSubmit = jest.fn((e: React.FormEvent) => e.preventDefault());
    render(
      <form onSubmit={onSubmit}>
        <Button>Not submit</Button>
        <Button type="submit">Submit</Button>
      </form>
    );
    expect(screen.getByRole('button', { name: 'Not submit' })).toHaveAttribute('type', 'button');
    fireEvent.click(screen.getByRole('button', { name: 'Not submit' }));
    expect(onSubmit).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Submit' }));
    expect(onSubmit).toHaveBeenCalledTimes(1);
  });

  it('loading disables the button, shows a spinner and keeps the label in the DOM', () => {
    const onClick = jest.fn();
    render(
      <Button loading onClick={onClick}>
        Saving
      </Button>
    );
    const b = screen.getByRole('button', { name: 'Saving' });
    expect(b).toBeDisabled();
    expect(b).toHaveAttribute('aria-busy', 'true');
    expect(screen.getByTestId('button-spinner')).toBeInTheDocument();
    expect(screen.getByText('Saving')).toBeInTheDocument();
    expect(screen.getByText('Saving').className).toContain('invisible');
    fireEvent.click(b);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('passes through disabled, handlers and native attributes', () => {
    const onClick = jest.fn();
    render(
      <Button disabled onClick={onClick} data-testid="x" aria-label="Close" title="t">
        ×
      </Button>
    );
    const b = screen.getByTestId('x');
    expect(b).toBeDisabled();
    expect(b).toHaveAttribute('aria-label', 'Close');
    expect(b).toHaveAttribute('title', 't');
    fireEvent.click(b);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('sizes, fullWidth, icon slots and extra className', () => {
    render(
      <Button size="sm" fullWidth className="mt-2" leadingIcon={<span data-testid="lead" />} trailingIcon={<span data-testid="trail" />}>
        Go
      </Button>
    );
    const b = screen.getByRole('button', { name: 'Go' });
    expect(b.className).toContain('px-3 py-1.5 text-xs');
    expect(b.className).toContain('w-full');
    expect(b.className).toContain('mt-2');
    expect(screen.getByTestId('lead')).toBeInTheDocument();
    expect(screen.getByTestId('trail')).toBeInTheDocument();
    expect(buttonClassName({ size: 'icon', variant: 'ghost' })).toContain('p-2');
  });
});

describe('LinkButton', () => {
  it('renders a router link with the button classes', () => {
    render(
      <MemoryRouter>
        <LinkButton to="/teams" variant="secondary">
          Teams
        </LinkButton>
      </MemoryRouter>
    );
    const a = screen.getByRole('link', { name: 'Teams' });
    expect(a).toHaveAttribute('href', '/teams');
    expect(a.className).toContain('border-brand-secondary');
    expect(a.className).toContain('rounded-md');
  });

  it('renders a plain anchor when href is given', () => {
    render(
      <LinkButton href="https://example.com" target="_blank" rel="noreferrer">
        Out
      </LinkButton>
    );
    const a = screen.getByRole('link', { name: 'Out' });
    expect(a).toHaveAttribute('href', 'https://example.com');
    expect(a).toHaveAttribute('target', '_blank');
    expect(a.className).toContain('bg-brand-primary');
  });
});

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { AthleteSelector } from '../components/AthleteSelector';

describe('AthleteSelector', () => {
  const mockAthletes = [
    { id: 1, first_name: 'John', last_name: 'Doe', profile_image_url: undefined },
    { id: 2, first_name: 'Jane', last_name: 'Doe', profile_image_url: 'https://example.com/jane.jpg' },
    { id: 3, first_name: 'Bob', last_name: 'Smith', profile_image_url: undefined },
  ];

  test('renders nothing when no athletes provided', () => {
    const { container } = render(
      <AthleteSelector
        athletes={[]}
        selectedAthleteId={null}
        onSelect={jest.fn()}
      />
    );

    expect(container.firstChild).toBeNull();
  });

  test('renders static display for single athlete without showAllOption', () => {
    render(
      <AthleteSelector
        athletes={[mockAthletes[0]]}
        selectedAthleteId={1}
        onSelect={jest.fn()}
        showAllOption={false}
      />
    );

    expect(screen.getByText('John Doe')).toBeInTheDocument();
    // Should not be a button (static display)
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  test('renders dropdown for multiple athletes', () => {
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={1}
        onSelect={jest.fn()}
      />
    );

    // Should show selected athlete name
    expect(screen.getByText('John')).toBeInTheDocument();
    // Should be clickable
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  test('opens dropdown on click', () => {
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={1}
        onSelect={jest.fn()}
      />
    );

    fireEvent.click(screen.getByRole('button'));

    // All athletes should be visible in dropdown
    expect(screen.getByText('John Doe')).toBeInTheDocument();
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.getByText('Bob Smith')).toBeInTheDocument();
  });

  test('shows "All Athletes" option when showAllOption is true', () => {
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={1}
        onSelect={jest.fn()}
        showAllOption={true}
      />
    );

    fireEvent.click(screen.getByRole('button'));

    // Should show "All Athletes" option in the dropdown
    expect(screen.getByText('All Athletes')).toBeInTheDocument();
  });

  test('calls onSelect with athlete id when athlete selected', () => {
    const mockOnSelect = jest.fn();
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={1}
        onSelect={mockOnSelect}
      />
    );

    fireEvent.click(screen.getByRole('button'));
    fireEvent.click(screen.getByText('Jane Doe'));

    expect(mockOnSelect).toHaveBeenCalledWith(2);
  });

  test('calls onSelect with null when "All Athletes" selected', () => {
    const mockOnSelect = jest.fn();
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={1}
        onSelect={mockOnSelect}
        showAllOption={true}
      />
    );

    fireEvent.click(screen.getByRole('button'));
    fireEvent.click(screen.getByText('All Athletes'));

    expect(mockOnSelect).toHaveBeenCalledWith(null);
  });

  test('closes dropdown after selection', () => {
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={1}
        onSelect={jest.fn()}
      />
    );

    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Jane Doe'));

    // Dropdown should close - Jane Doe as full name should not be visible
    // (only first name shown in closed state)
    expect(screen.queryByText('Jane Doe')).not.toBeInTheDocument();
  });

  test('displays initials when no profile image', () => {
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={1}
        onSelect={jest.fn()}
      />
    );

    fireEvent.click(screen.getByRole('button'));

    // Check for initials in the dropdown (may have duplicates for selected athlete)
    expect(screen.getAllByText('JD').length).toBeGreaterThanOrEqual(1); // John Doe
    expect(screen.getByText('BS')).toBeInTheDocument(); // Bob Smith
  });

  test('displays profile image when available', () => {
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={2}
        onSelect={jest.fn()}
      />
    );

    const images = screen.getAllByRole('img');
    expect(images.some(img => img.getAttribute('src') === 'https://example.com/jane.jpg')).toBe(true);
  });

  test('highlights currently selected athlete', () => {
    render(
      <AthleteSelector
        athletes={mockAthletes}
        selectedAthleteId={2}
        onSelect={jest.fn()}
      />
    );

    fireEvent.click(screen.getByRole('button'));

    // The selected athlete's button should have the highlight class
    const janeButton = screen.getByText('Jane Doe').closest('button');
    expect(janeButton).toHaveClass('bg-brand-secondary');
  });
});

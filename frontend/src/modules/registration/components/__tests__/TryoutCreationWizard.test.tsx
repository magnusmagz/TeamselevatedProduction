import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import TryoutCreationWizard from '../TryoutCreationWizard';

// Mock fetch
global.fetch = jest.fn();

describe('TryoutCreationWizard', () => {
  const mockOnComplete = jest.fn();
  const mockOnCancel = jest.fn();

  beforeEach(() => {
    mockOnComplete.mockClear();
    mockOnCancel.mockClear();
    (global.fetch as jest.Mock).mockClear();
  });

  // Helper to get the name input
  const getNameInput = () => screen.getByPlaceholderText(/2026 Spring Tryouts/i);

  it('renders step 1 (Basic Info) initially', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    expect(screen.getByText('Create Tryout')).toBeInTheDocument();
    expect(screen.getByText('Basic Info')).toBeInTheDocument();
    expect(getNameInput()).toBeInTheDocument();
  });

  it('shows all 3 steps in indicator', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    expect(screen.getByText('Basic Info')).toBeInTheDocument();
    expect(screen.getByText('Sessions')).toBeInTheDocument();
    expect(screen.getByText('Evaluation Criteria')).toBeInTheDocument();
  });

  it('requires name to proceed to step 2', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Try to proceed without name
    fireEvent.click(screen.getByText('Next'));

    // Should still be on step 1 (name input still visible)
    expect(getNameInput()).toBeInTheDocument();
  });

  it('proceeds to step 2 when name provided', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Fill in name
    fireEvent.change(getNameInput(), {
      target: { value: 'Test Tryout' }
    });

    fireEvent.click(screen.getByText('Next'));

    // Should show Sessions content
    expect(screen.getByText('Tryout Sessions')).toBeInTheDocument();
    expect(screen.getByText('+ Add Session')).toBeInTheDocument();
  });

  it('can add sessions in step 2', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Go to step 2
    fireEvent.change(getNameInput(), {
      target: { value: 'Test Tryout' }
    });
    fireEvent.click(screen.getByText('Next'));

    // Add a session
    fireEvent.click(screen.getByText('+ Add Session'));

    // Should show session form
    expect(screen.getByText('Session 1')).toBeInTheDocument();
  });

  it('proceeds to step 3 (Evaluation Criteria)', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Go through steps
    fireEvent.change(getNameInput(), {
      target: { value: 'Test Tryout' }
    });
    fireEvent.click(screen.getByText('Next')); // to step 2
    fireEvent.click(screen.getByText('Next')); // to step 3

    // Should show criteria header (appears multiple times)
    const criteriaHeaders = screen.getAllByText('Evaluation Criteria');
    expect(criteriaHeaders.length).toBeGreaterThan(0);
    expect(screen.getByText('Technical Skills')).toBeInTheDocument(); // default criterion
  });

  it('shows default evaluation criteria in step 3', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Navigate to step 3
    fireEvent.change(getNameInput(), {
      target: { value: 'Test Tryout' }
    });
    fireEvent.click(screen.getByText('Next'));
    fireEvent.click(screen.getByText('Next'));

    // Check default criteria
    expect(screen.getByText('Technical Skills')).toBeInTheDocument();
    expect(screen.getByText('Tactical Awareness')).toBeInTheDocument();
    expect(screen.getByText('Physical Fitness')).toBeInTheDocument();
    expect(screen.getByText('Attitude/Coachability')).toBeInTheDocument();
  });

  it('can go back to previous steps', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Go to step 2
    fireEvent.change(getNameInput(), {
      target: { value: 'Test Tryout' }
    });
    fireEvent.click(screen.getByText('Next'));

    // Go back
    fireEvent.click(screen.getByText('Back'));

    // Should be back on step 1 (name input visible again)
    expect(getNameInput()).toBeInTheDocument();
  });

  it('calls onCancel when Cancel clicked', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    fireEvent.click(screen.getByText('Cancel'));

    expect(mockOnCancel).toHaveBeenCalledTimes(1);
  });

  it('calls onCancel when X button clicked', () => {
    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Find the close button (×)
    const closeButton = screen.getByText('×');
    fireEvent.click(closeButton);

    expect(mockOnCancel).toHaveBeenCalledTimes(1);
  });

  it('submits tryout on final step', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ success: true, id: 123, embed_code: 'TRY123' })
    });

    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Fill form and navigate to step 3
    fireEvent.change(getNameInput(), {
      target: { value: 'Test Tryout' }
    });
    fireEvent.click(screen.getByText('Next'));
    fireEvent.click(screen.getByText('Next'));

    // Submit - use getByRole to get the button specifically
    fireEvent.click(screen.getByRole('button', { name: 'Create Tryout' }));

    await waitFor(() => {
      expect(mockOnComplete).toHaveBeenCalledWith(123);
    });
  });

  it('shows error on failed submission', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      json: async () => ({ error: 'Failed to create tryout' })
    });

    render(
      <TryoutCreationWizard
        clubId={1}
        onComplete={mockOnComplete}
        onCancel={mockOnCancel}
      />
    );

    // Navigate to final step
    fireEvent.change(getNameInput(), {
      target: { value: 'Test Tryout' }
    });
    fireEvent.click(screen.getByText('Next'));
    fireEvent.click(screen.getByText('Next'));

    // Submit - use getByRole to get the button specifically
    fireEvent.click(screen.getByRole('button', { name: 'Create Tryout' }));

    await waitFor(() => {
      expect(screen.getByText('Failed to create tryout')).toBeInTheDocument();
    });
  });
});

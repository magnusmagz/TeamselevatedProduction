import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import EvaluationModal from '../EvaluationModal';
import { TryoutRegistration } from '../../types';

// Mock fetch
global.fetch = jest.fn();

describe('EvaluationModal', () => {
  const mockRegistration: TryoutRegistration = {
    id: 101,
    athlete_id: 1,
    first_name: 'Emma',
    last_name: 'Anderson',
    tryout_status: 'checked_in'
  };

  const mockCriteria = [
    { id: 1, name: 'Technical Skills', description: 'Ball control', max_score: 5, weight: 1.0 },
    { id: 2, name: 'Tactical Awareness', description: 'Game sense', max_score: 5, weight: 1.0 }
  ];

  const mockOnClose = jest.fn();
  const mockOnSave = jest.fn();

  beforeEach(() => {
    mockOnClose.mockClear();
    mockOnSave.mockClear();
    (global.fetch as jest.Mock).mockClear();

    // Default mock responses
    (global.fetch as jest.Mock)
      .mockResolvedValueOnce({
        ok: true,
        json: async () => mockCriteria
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => [] // no existing evaluations
      });
  });

  it('renders athlete name in header', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Emma Anderson')).toBeInTheDocument();
    });
  });

  it('loads and displays criteria', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Technical Skills')).toBeInTheDocument();
      expect(screen.getByText('Tactical Awareness')).toBeInTheDocument();
    });
  });

  it('displays score buttons for each criterion', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      // Should have score buttons 1-5 for each criterion
      const buttons1 = screen.getAllByText('1');
      const buttons5 = screen.getAllByText('5');
      expect(buttons1.length).toBe(2); // 2 criteria
      expect(buttons5.length).toBe(2);
    });
  });

  it('shows overall score preview', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Overall Score Preview')).toBeInTheDocument();
    });
  });

  it('updates preview score when scores selected', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Technical Skills')).toBeInTheDocument();
    });

    // Click score buttons (first set of 5s)
    const fiveButtons = screen.getAllByText('5');
    fireEvent.click(fiveButtons[0]); // Technical Skills = 5
    fireEvent.click(fiveButtons[1]); // Tactical Awareness = 5

    // Preview should show 100 (max score)
    await waitFor(() => {
      expect(screen.getByText('100.0')).toBeInTheDocument();
    });
  });

  it('has notes textarea', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/Additional observations/i)).toBeInTheDocument();
    });
  });

  it('calls onClose when Cancel clicked', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Cancel')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Cancel'));

    expect(mockOnClose).toHaveBeenCalledTimes(1);
  });

  it('calls onClose when X button clicked', async () => {
    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('×')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('×'));

    expect(mockOnClose).toHaveBeenCalledTimes(1);
  });

  it('shows alert when trying to submit without all scores', async () => {
    const alertMock = jest.spyOn(window, 'alert').mockImplementation(() => {});

    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Submit Evaluation')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText('Submit Evaluation'));

    expect(alertMock).toHaveBeenCalledWith('Please provide scores for all criteria');
    alertMock.mockRestore();
  });

  it('submits evaluation when all scores provided', async () => {
    (global.fetch as jest.Mock)
      .mockReset()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => mockCriteria
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => []
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ success: true, overall_score: 80 })
      });

    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Technical Skills')).toBeInTheDocument();
    });

    // Select scores
    const fourButtons = screen.getAllByText('4');
    fireEvent.click(fourButtons[0]);
    fireEvent.click(fourButtons[1]);

    // Submit
    fireEvent.click(screen.getByText('Submit Evaluation'));

    await waitFor(() => {
      expect(mockOnSave).toHaveBeenCalled();
    });
  });

  it('shows existing evaluation notice when updating', async () => {
    const existingEvaluation = {
      id: 1,
      registration_id: 101,
      evaluator_id: 2,
      scores: { '1': 4, '2': 4 },
      overall_score: 80,
      notes: 'Previous notes'
    };

    (global.fetch as jest.Mock)
      .mockReset()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => mockCriteria
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => [existingEvaluation]
      });

    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText(/already submitted an evaluation/i)).toBeInTheDocument();
    });
  });

  it('shows Update Evaluation button for existing evaluation', async () => {
    const existingEvaluation = {
      id: 1,
      registration_id: 101,
      evaluator_id: 2,
      scores: { '1': 4, '2': 4 },
      overall_score: 80
    };

    (global.fetch as jest.Mock)
      .mockReset()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => mockCriteria
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => [existingEvaluation]
      });

    render(
      <EvaluationModal
        registration={mockRegistration}
        programId={100}
        evaluatorId={2}
        onClose={mockOnClose}
        onSave={mockOnSave}
      />
    );

    await waitFor(() => {
      expect(screen.getByText('Update Evaluation')).toBeInTheDocument();
    });
  });
});

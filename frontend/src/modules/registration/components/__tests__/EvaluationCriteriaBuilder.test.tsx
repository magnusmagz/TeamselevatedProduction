import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import EvaluationCriteriaBuilder from '../EvaluationCriteriaBuilder';
import { EvaluationCriterion } from '../../types';

describe('EvaluationCriteriaBuilder', () => {
  const mockCriteria: EvaluationCriterion[] = [
    {
      id: 1,
      name: 'Technical Skills',
      description: 'Ball control, passing',
      max_score: 5,
      weight: 1.0,
      display_order: 0
    },
    {
      id: 2,
      name: 'Tactical Awareness',
      description: 'Game understanding',
      max_score: 5,
      weight: 1.0,
      display_order: 1
    }
  ];

  const mockOnChange = jest.fn();

  beforeEach(() => {
    mockOnChange.mockClear();
  });

  it('renders criteria list', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    expect(screen.getByText('Technical Skills')).toBeInTheDocument();
    expect(screen.getByText('Tactical Awareness')).toBeInTheDocument();
  });

  it('shows descriptions', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    expect(screen.getByText('Ball control, passing')).toBeInTheDocument();
    expect(screen.getByText('Game understanding')).toBeInTheDocument();
  });

  it('shows max score and weight', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    // Check for "1-5" (max score display)
    const scoreDisplays = screen.getAllByText(/1-5/);
    expect(scoreDisplays.length).toBeGreaterThan(0);
  });

  it('shows empty state when no criteria', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={[]}
        onChange={mockOnChange}
      />
    );

    expect(screen.getByText(/No evaluation criteria defined/)).toBeInTheDocument();
  });

  it('shows Add Criterion button', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    expect(screen.getByText('+ Add Criterion')).toBeInTheDocument();
  });

  it('hides Add button in readOnly mode', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
        readOnly={true}
      />
    );

    expect(screen.queryByText('+ Add Criterion')).not.toBeInTheDocument();
  });

  it('shows edit/delete buttons for each criterion', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    const editButtons = screen.getAllByText('Edit');
    const deleteButtons = screen.getAllByText('Delete');

    expect(editButtons).toHaveLength(2);
    expect(deleteButtons).toHaveLength(2);
  });

  it('hides edit/delete buttons in readOnly mode', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
        readOnly={true}
      />
    );

    expect(screen.queryByText('Edit')).not.toBeInTheDocument();
    expect(screen.queryByText('Delete')).not.toBeInTheDocument();
  });

  it('opens edit modal when Edit clicked', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    const editButtons = screen.getAllByText('Edit');
    fireEvent.click(editButtons[0]);

    expect(screen.getByText('Edit Criterion')).toBeInTheDocument();
  });

  it('opens add modal when Add Criterion clicked', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    fireEvent.click(screen.getByText('+ Add Criterion'));

    expect(screen.getByText('Add Criterion')).toBeInTheDocument();
  });

  it('calls onChange when criterion deleted', () => {
    render(
      <EvaluationCriteriaBuilder
        criteria={mockCriteria}
        onChange={mockOnChange}
      />
    );

    const deleteButtons = screen.getAllByText('Delete');
    fireEvent.click(deleteButtons[0]);

    expect(mockOnChange).toHaveBeenCalledTimes(1);
    expect(mockOnChange).toHaveBeenCalledWith(
      expect.arrayContaining([
        expect.objectContaining({ name: 'Tactical Awareness' })
      ])
    );
  });
});

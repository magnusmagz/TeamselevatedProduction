import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import TeamFormWithTabs from './TeamFormWithTabs';

const mockFetch = jest.fn();
global.fetch = mockFetch;

describe('TeamFormWithTabs gender', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // Coaches / seasons / fields lookups; fields-gateway answers a bare array.
    mockFetch.mockImplementation((url: string) => {
      if (String(url).includes('seasons-gateway')) {
        return Promise.resolve({ ok: true, json: async () => ({ success: true, seasons: [{ id: 3, name: 'Fall 2026' }] }) });
      }
      return Promise.resolve({ ok: true, json: async () => [] });
    });
  });

  test('offers only values the column CHECK constraint accepts', async () => {
    render(<TeamFormWithTabs team={null} onSubmit={() => {}} onClose={() => {}} />);
    const select = (await screen.findByLabelText('Gender *')) as HTMLSelectElement;
    expect(Array.from(select.options).map((o) => o.value)).toEqual(['Male', 'Female', 'Mixed']);
    expect(Array.from(select.options).map((o) => o.text)).toEqual(['Boys', 'Girls', 'Coed']);
    expect(select.value).toBe('Mixed');
  });

  test('submits the selected gender', async () => {
    const onSubmit = jest.fn();
    render(<TeamFormWithTabs team={null} onSubmit={onSubmit} onClose={() => {}} />);

    fireEvent.change(await screen.findByLabelText('Gender *'), { target: { value: 'Female' } });
    fireEvent.change(screen.getByPlaceholderText('e.g., Lightning Bolts'), { target: { value: 'Comets' } });
    fireEvent.change(await screen.findByDisplayValue('Select a season'), { target: { value: '3' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create Team' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalled());
    expect(onSubmit.mock.calls[0][0].gender).toBe('Female');
  });

  test('an existing team keeps the gender it was saved with', async () => {
    render(
      <TeamFormWithTabs
        team={{ id: 1, name: 'Comets', age_group: 'U12', gender: 'Female', division: 'Recreational', season_id: '' }}
        onSubmit={() => {}}
        onClose={() => {}}
      />
    );
    const select = (await screen.findByLabelText('Gender *')) as HTMLSelectElement;
    expect(select.value).toBe('Female');
  });
});

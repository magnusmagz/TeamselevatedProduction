import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { JerseySizeCard } from '../components/JerseySizeCard';

/**
 * The card is the crew's only write path into athletes.jersey_size, so what
 * matters here is that it tells the truth about what got saved.
 *
 * The failure worth guarding: the backend answers 422 when it cannot resolve a
 * submitted size (see api/athlete-jersey-size.php and
 * te_classify_jersey_size_submission). If the card treated any response as
 * success, a parent would be shown their child's size on file when the column is
 * actually unchanged — which is the same silent-failure shape the endpoint went
 * out of its way to avoid on the server side.
 */
describe('JerseySizeCard', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
  });

  test('shows the stored size as a human label, not the raw code', () => {
    render(
      <JerseySizeCard athleteId={1} jerseySize="YM" athleteFirstName="Rachel" />
    );

    expect(screen.getByText('Youth Medium (10-12)')).toBeInTheDocument();
    expect(screen.queryByText('YM')).not.toBeInTheDocument();
  });

  test('prompts for the size when none is on file', () => {
    render(
      <JerseySizeCard athleteId={1} jerseySize={null} athleteFirstName="Rachel" />
    );

    expect(screen.getByText('Not set')).toBeInTheDocument();
    expect(screen.getByText(/don't have Rachel's jersey size yet/)).toBeInTheDocument();
  });

  test('saves the selected code and reports the value the server stored', async () => {
    const onSaved = jest.fn();
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, jersey_size: 'AL', jersey_label: 'Adult Large' }),
    }) as any;

    render(
      <JerseySizeCard
        athleteId={7}
        jerseySize={null}
        athleteFirstName="Rachel"
        onSaved={onSaved}
      />
    );

    fireEvent.click(screen.getByText('Add size'));
    fireEvent.change(screen.getByLabelText('Jersey size'), { target: { value: 'AL' } });
    fireEvent.click(screen.getByText('Save'));

    await waitFor(() => expect(onSaved).toHaveBeenCalledWith('AL'));

    const [url, options] = (global.fetch as jest.Mock).mock.calls[0];
    expect(url).toContain('/api/athlete-jersey-size.php');
    expect(options.method).toBe('PUT');
    // The stored code goes over the wire — not the display label.
    expect(JSON.parse(options.body)).toEqual({ athlete_id: 7, jersey_size: 'AL' });

    // The displayed value comes from the response, so the card can never claim a
    // size the server did not accept.
    expect(await screen.findByText('Adult Large')).toBeInTheDocument();
  });

  test('an empty selection is a deliberate clear, sent as such', async () => {
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, jersey_size: null, jersey_label: null }),
    }) as any;

    render(
      <JerseySizeCard athleteId={7} jerseySize="YM" athleteFirstName="Rachel" />
    );

    fireEvent.click(screen.getByText('Edit'));
    fireEvent.change(screen.getByLabelText('Jersey size'), { target: { value: '' } });
    fireEvent.click(screen.getByText('Save'));

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());

    const [, options] = (global.fetch as jest.Mock).mock.calls[0];
    expect(JSON.parse(options.body).jersey_size).toBe('');
    expect(await screen.findByText('Not set')).toBeInTheDocument();
  });

  test('a rejected size surfaces the error and leaves the old value showing', async () => {
    const onSaved = jest.fn();
    global.fetch = jest.fn().mockResolvedValue({
      ok: false,
      json: async () => ({ success: false, error: 'Unrecognized jersey size' }),
    }) as any;

    render(
      <JerseySizeCard
        athleteId={7}
        jerseySize="YM"
        athleteFirstName="Rachel"
        onSaved={onSaved}
      />
    );

    fireEvent.click(screen.getByText('Edit'));
    fireEvent.click(screen.getByText('Save'));

    expect(await screen.findByText('Unrecognized jersey size')).toBeInTheDocument();
    // Still in the editor, nothing claimed as saved, parent page not told otherwise.
    expect(onSaved).not.toHaveBeenCalled();
    expect(screen.queryByText('Saved.')).not.toBeInTheDocument();
  });

  test('a network failure does not look like a successful save', async () => {
    const onSaved = jest.fn();
    global.fetch = jest.fn().mockRejectedValue(new Error('offline')) as any;

    render(
      <JerseySizeCard
        athleteId={7}
        jerseySize="YM"
        athleteFirstName="Rachel"
        onSaved={onSaved}
      />
    );

    fireEvent.click(screen.getByText('Edit'));
    fireEvent.click(screen.getByText('Save'));

    expect(await screen.findByText(/Unable to reach the server/)).toBeInTheDocument();
    expect(onSaved).not.toHaveBeenCalled();
  });
});

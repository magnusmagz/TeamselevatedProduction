import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import GameRefereesBlock from '../GameRefereesBlock';

const API = 'https://api.test';
const hit = { id: 1, name: 'Ray Whistle', first_name: 'Ray', last_name: 'Whistle', email: 'ref@whistle.test', grade: 'Regional', certification_level: null, user_id: 300 };
const assigned = (over: Record<string, unknown> = {}) => ({
  assignment_id: 9, id: 2, name: 'Nora Flag', first_name: 'Nora', last_name: 'Flag', email: null, phone: null, grade: 'Grade 5',
  certification_level: null, role: 'center', self_assigned: false, grade_override: false, ...over,
});

beforeEach(() => {
  global.fetch = jest.fn();
  localStorage.setItem('auth_token', 'tok');
});
afterEach(() => {
  delete (global as any).fetch;
});

function mockSearch(hits: unknown[]) {
  (global.fetch as jest.Mock).mockImplementation((url: string) => {
    if (url.includes('action=search')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: hits }) });
    }
    return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: [] }) });
  });
}

describe('GameRefereesBlock', () => {
  it('EDIT mode: lists assignments with role, grade, the self-assigned badge, and unassigns', async () => {
    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      if (url.includes('action=for-event')) {
        return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: [assigned(), assigned({ assignment_id: 10, id: 3, name: 'Sam Line', role: 'assistant', self_assigned: true, grade: null, grade_override: true })], roles: [] }) });
      }
      if (url.includes('action=unassign')) {
        return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, removed: true, referees: [assigned()] }) });
      }
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, referees: [] }) });
    });
    const onChanged = jest.fn();
    render(<GameRefereesBlock apiUrl={API} clubId={100} eventId={500} canEdit minGrade="National" onChanged={onChanged} />);

    const nora = await screen.findByTestId('game-referee-2');
    expect(nora).toHaveTextContent('Nora Flag');
    expect(nora).toHaveTextContent('Center');
    expect(nora).toHaveTextContent('Grade 5');
    const sam = screen.getByTestId('game-referee-3');
    expect(sam).toHaveTextContent('Self-assigned');
    expect(sam).toHaveTextContent('Below minimum');
    expect(screen.getByText('Minimum grade: National')).toBeInTheDocument();

    fireEvent.click(screen.getAllByRole('button', { name: /Unassign/ })[1]);
    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const call = (global.fetch as jest.Mock).mock.calls.find(([u]) => String(u).includes('action=unassign'));
    expect(JSON.parse(call![1].body)).toEqual({ event_id: 500, referee_id: 3 });
    await waitFor(() => expect(screen.queryByTestId('game-referee-3')).not.toBeInTheDocument());
  });

  it('EDIT mode: read-only for a viewer who cannot edit', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: [assigned()], roles: [] }) });
    render(<GameRefereesBlock apiUrl={API} clubId={100} eventId={500} canEdit={false} />);
    expect(await screen.findByText('Nora Flag')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Unassign/ })).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText('Add a referee…')).not.toBeInTheDocument();
  });

  it('EDIT mode: picks from the typeahead, warns below the minimum, and assigns with the role', async () => {
    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      if (url.includes('action=for-event')) return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: [], roles: [] }) });
      if (url.includes('action=search')) return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: [hit] }) });
      if (url.includes('action=assign')) return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, referees: [assigned({ id: 1, name: 'Ray Whistle', role: 'assistant', grade: 'Regional', grade_override: true })] }) });
      return Promise.resolve({ ok: true, status: 200, json: async () => ({}) });
    });
    render(<GameRefereesBlock apiUrl={API} clubId={100} eventId={500} canEdit minGrade="National" />);
    expect(await screen.findByTestId('game-referees-empty')).toBeInTheDocument();

    const input = screen.getByPlaceholderText('Add a referee…');
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: 'ray' } });
    fireEvent.click(await screen.findByRole('option', { name: /Ray Whistle/ }));
    expect(screen.getByTestId('grade-warning')).toHaveTextContent('below this game');

    fireEvent.change(screen.getByLabelText(/Referee role/), { target: { value: 'assistant' } });
    fireEvent.click(screen.getByRole('button', { name: /^Assign$/ }));

    await waitFor(() => {
      const call = (global.fetch as jest.Mock).mock.calls.find(([u]) => String(u).includes('action=assign'));
      expect(call).toBeTruthy();
      expect(JSON.parse(call![1].body)).toEqual({ event_id: 500, referee_id: 1, role: 'assistant' });
    });
    expect(await screen.findByTestId('game-referee-1')).toHaveTextContent('Below minimum');
  });

  it('warns when an assistant-only grade is picked as center, and shows the time-clash badge + warnings after assign', async () => {
    const ar = { ...hit, id: 5, name: 'Ann Line', grade: 'National Assistant Referee' };
    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      if (url.includes('action=for-event')) return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: [], roles: [] }) });
      if (url.includes('action=search')) return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, available: true, referees: [ar] }) });
      if (url.includes('action=assign')) return Promise.resolve({ ok: true, status: 200, json: async () => ({ success: true, warnings: ['They are already on League match from 10:00, which overlaps this game. The assignment is marked.'], referees: [assigned({ id: 5, name: 'Ann Line', role: 'center', grade: 'National Assistant Referee', grade_override: true, conflict_override: true })] }) });
      return Promise.resolve({ ok: true, status: 200, json: async () => ({}) });
    });
    render(<GameRefereesBlock apiUrl={API} clubId={100} eventId={500} canEdit minGrade={null} />);
    await screen.findByTestId('game-referees-empty');
    const input = screen.getByPlaceholderText('Add a referee…');
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: 'ann' } });
    fireEvent.click(await screen.findByRole('option', { name: /Ann Line/ }));
    expect(screen.getByTestId('grade-warning')).toHaveTextContent('assistant referee grade');
    fireEvent.change(screen.getByLabelText(/Referee role/), { target: { value: 'assistant' } });
    expect(screen.queryByTestId('grade-warning')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/Referee role/), { target: { value: 'center' } });
    fireEvent.click(screen.getByRole('button', { name: /^Assign$/ }));
    expect(await screen.findByTestId('conflict-badge')).toBeInTheDocument();
    expect(screen.getByTestId('assign-warnings')).toHaveTextContent('already on League match');
  });

  it('CREATE mode: holds picks locally and reports them to the parent, never calling assign', async () => {
    mockSearch([hit]);
    const onPendingChange = jest.fn();
    const { rerender } = render(<GameRefereesBlock apiUrl={API} clubId={100} canEdit pending={[]} onPendingChange={onPendingChange} />);

    const input = screen.getByPlaceholderText('Add a referee…');
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: 'ray' } });
    fireEvent.click(await screen.findByRole('option', { name: /Ray Whistle/ }));
    fireEvent.click(screen.getByRole('button', { name: /^Assign$/ }));

    expect(onPendingChange).toHaveBeenCalledWith([{ referee_id: 1, name: 'Ray Whistle', grade: 'Regional', role: 'center' }]);
    expect((global.fetch as jest.Mock).mock.calls.some(([u]) => String(u).includes('action=assign'))).toBe(false);

    rerender(<GameRefereesBlock apiUrl={API} clubId={100} canEdit pending={[{ referee_id: 1, name: 'Ray Whistle', grade: 'Regional', role: 'center' }]} onPendingChange={onPendingChange} />);
    expect(screen.getByTestId('game-referee-1')).toHaveTextContent('Ray Whistle');
    fireEvent.click(screen.getByRole('button', { name: /Unassign/ }));
    expect(onPendingChange).toHaveBeenLastCalledWith([]);
  });
});

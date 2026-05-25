import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import AttendanceModal from './AttendanceModal';

// CA-16: attendance must support present / absent / late / excused per athlete,
// save them, and reflect them in the summary.

const mockFetch = jest.fn();
global.fetch = mockFetch as any;

const mockAthletes = [
  { athlete_id: 10, first_name: 'Amy', last_name: 'Alpha', team_id: 1, team_name: 'Mustangs' },
  { athlete_id: 11, first_name: 'Ben', last_name: 'Beta', team_id: 1, team_name: 'Mustangs' },
];

beforeEach(() => {
  mockFetch.mockReset();
  mockFetch.mockImplementation((url: string, opts?: any) => {
    if (url.includes('action=get')) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          success: true,
          event: { id: 1, name: 'Practice', type: 'practice', event_date: '2026-05-25', status: 'scheduled' },
          athletes: mockAthletes,
          attendance: {},
          summary: { present: 0, absent: 0, late: 0, excused: 0, total: 2, not_marked: 2 },
        }),
      });
    }
    if (url.includes('action=save')) {
      const body = JSON.parse(opts.body);
      // Echo a summary derived from the posted records.
      const counts = { present: 0, absent: 0, late: 0, excused: 0 } as Record<string, number>;
      body.attendance.forEach((r: any) => { counts[r.status] = (counts[r.status] || 0) + 1; });
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          success: true,
          saved_count: body.attendance.length,
          summary: { ...counts, total: body.attendance.length },
          _posted: body.attendance,
        }),
      });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
  });
});

describe('AttendanceModal 4-state (CA-16)', () => {
  it('renders all four status buttons per athlete', async () => {
    render(<AttendanceModal eventId={1} eventName="Practice" eventDate="2026-05-25" onClose={() => {}} />);
    await waitFor(() => expect(screen.getByText('Amy Alpha')).toBeInTheDocument());

    const row = screen.getByText('Amy Alpha').closest('tr') as HTMLElement;
    expect(within(row).getByText('Present')).toBeInTheDocument();
    expect(within(row).getByText('Absent')).toBeInTheDocument();
    expect(within(row).getByText('Late')).toBeInTheDocument();
    expect(within(row).getByText('Excused')).toBeInTheDocument();
  });

  it('saves the selected per-athlete statuses including late and excused', async () => {
    render(<AttendanceModal eventId={1} eventName="Practice" eventDate="2026-05-25" onClose={() => {}} />);
    await waitFor(() => expect(screen.getByText('Amy Alpha')).toBeInTheDocument());

    // Mark Amy late, Ben excused.
    const amyRow = screen.getByText('Amy Alpha').closest('tr') as HTMLElement;
    fireEvent.click(within(amyRow).getByText('Late'));
    const benRow = screen.getByText('Ben Beta').closest('tr') as HTMLElement;
    fireEvent.click(within(benRow).getByText('Excused'));

    fireEvent.click(screen.getByText('Save Attendance'));

    await waitFor(() => {
      const saveCall = mockFetch.mock.calls.find(c => String(c[0]).includes('action=save'));
      expect(saveCall).toBeTruthy();
      const posted = JSON.parse(saveCall![1].body).attendance;
      const amy = posted.find((r: any) => r.athlete_id === 10);
      const ben = posted.find((r: any) => r.athlete_id === 11);
      expect(amy.status).toBe('late');
      expect(ben.status).toBe('excused');
    });
  });

  it('updates the live summary counts as statuses change', async () => {
    const { container } = render(
      <AttendanceModal eventId={1} eventName="Practice" eventDate="2026-05-25" onClose={() => {}} />
    );
    await waitFor(() => expect(screen.getByText('Amy Alpha')).toBeInTheDocument());

    // The summary count spans carry the text-2xl class with a hue per status.
    const countByColor = (cls: string) =>
      (container.querySelector(`.text-2xl.${cls}`) as HTMLElement)?.textContent;

    // Both default to present -> Present (green) count should be 2.
    expect(countByColor('text-green-600')).toBe('2');

    // Mark Amy excused -> Excused (blue) becomes 1, Present 1.
    const amyRow = screen.getByText('Amy Alpha').closest('tr') as HTMLElement;
    fireEvent.click(within(amyRow).getByText('Excused'));

    await waitFor(() => expect(countByColor('text-blue-600')).toBe('1'));
    expect(countByColor('text-green-600')).toBe('1');
  });
});

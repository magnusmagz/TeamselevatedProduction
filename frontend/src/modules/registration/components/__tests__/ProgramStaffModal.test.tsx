import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';

import ProgramStaffModal from '../ProgramStaffModal';

/**
 * The Staff panel on a program (CKU R66, slice 8.1).
 *
 * Three things it must not get wrong:
 *   - "not available yet" (migration 086 unapplied) is a DIFFERENT statement
 *     from "nobody is assigned", and an empty list reads as the second;
 *   - a refusal from the backend (422 "not a coach") must be shown, because a
 *     silent no-op is the worst possible answer to a click;
 *   - already-assigned coaches must not be offered again, or Add becomes a
 *     no-op the admin cannot explain.
 */

const STAFF_URL = 'action=staff';
const COACHES_URL = 'coaches-gateway';

type Handler = (url: string, init?: RequestInit) => { ok: boolean; body: unknown };

const mockFetch = (handler: Handler) => {
  (global as unknown as { fetch: jest.Mock }).fetch = jest.fn(
    (url: string, init?: RequestInit) => {
      const { ok, body } = handler(String(url), init);
      return Promise.resolve({ ok, json: () => Promise.resolve(body) } as Response);
    }
  );
};

const COACHES = [
  { id: 158, first_name: 'Morgan', last_name: 'Long', email: 'morgan@example.com' },
  { id: 159, first_name: 'Idle', last_name: 'Coach', email: 'idle@example.com' },
];

beforeEach(() => {
  localStorage.setItem('auth_token', 'test-token');
});

afterEach(() => {
  jest.resetAllMocks();
});

test('lists the assigned coaches', async () => {
  mockFetch((url) => {
    if (url.includes(STAFF_URL)) {
      return {
        ok: true,
        body: {
          success: true,
          available: true,
          staff: [{ user_id: 158, first_name: 'Morgan', last_name: 'Long', email: 'morgan@example.com', role: 'coach' }],
        },
      };
    }
    if (url.includes(COACHES_URL)) return { ok: true, body: COACHES };
    return { ok: false, body: {} };
  });

  render(<ProgramStaffModal programId={900} programName="Summer Camp" onClose={jest.fn()} />);

  expect(await screen.findByText('Morgan Long')).toBeInTheDocument();
  expect(screen.getByText('Summer Camp')).toBeInTheDocument();
});

test('an already-assigned coach is not offered again', async () => {
  mockFetch((url) => {
    if (url.includes(STAFF_URL)) {
      return {
        ok: true,
        body: {
          success: true,
          available: true,
          staff: [{ user_id: 158, first_name: 'Morgan', last_name: 'Long', email: 'morgan@example.com', role: 'coach' }],
        },
      };
    }
    if (url.includes(COACHES_URL)) return { ok: true, body: COACHES };
    return { ok: false, body: {} };
  });

  render(<ProgramStaffModal programId={900} programName="Summer Camp" onClose={jest.fn()} />);

  await screen.findByText('Morgan Long');
  await waitFor(() => expect(screen.getByRole('option', { name: /Idle Coach/ })).toBeInTheDocument());
  expect(screen.queryByRole('option', { name: /Morgan Long/ })).not.toBeInTheDocument();
});

/**
 * `available: false` means the table is not in Neon yet. Saying "no coaches
 * assigned" there would be a true sentence about a feature that cannot be used,
 * and the admin would keep clicking Add.
 */
test('says the feature is not live yet rather than showing an empty roster', async () => {
  mockFetch((url) => {
    if (url.includes(STAFF_URL)) return { ok: true, body: { success: true, available: false, staff: [] } };
    if (url.includes(COACHES_URL)) return { ok: true, body: COACHES };
    return { ok: false, body: {} };
  });

  render(<ProgramStaffModal programId={900} programName="Summer Camp" onClose={jest.fn()} />);

  expect(await screen.findByText(/not available yet/i)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Add' })).toBeDisabled();
});

test("shows the backend's refusal instead of failing silently", async () => {
  mockFetch((url, init) => {
    if (url.includes('assign-staff')) {
      return {
        ok: false,
        body: { success: false, reason: 'not_staff', error: 'That person is not a coach or admin of this club.' },
      };
    }
    if (url.includes(STAFF_URL)) return { ok: true, body: { success: true, available: true, staff: [] } };
    if (url.includes(COACHES_URL)) return { ok: true, body: COACHES };
    return { ok: false, body: {} };
  });

  render(<ProgramStaffModal programId={900} programName="Summer Camp" onClose={jest.fn()} />);

  await waitFor(() => expect(screen.getByRole('option', { name: /Morgan Long/ })).toBeInTheDocument());
  fireEvent.change(screen.getByLabelText('Add a coach'), { target: { value: '158' } });
  fireEvent.click(screen.getByRole('button', { name: 'Add' }));

  expect(await screen.findByRole('alert')).toHaveTextContent(/not a coach or admin/i);
});

test('assigning posts the program and user and reloads the list', async () => {
  let assigned = false;
  mockFetch((url) => {
    if (url.includes('assign-staff')) {
      assigned = true;
      return { ok: true, body: { success: true, program_id: 900, user_id: 158, role: 'coach' } };
    }
    if (url.includes(STAFF_URL)) {
      return {
        ok: true,
        body: {
          success: true,
          available: true,
          staff: assigned
            ? [{ user_id: 158, first_name: 'Morgan', last_name: 'Long', email: 'morgan@example.com', role: 'coach' }]
            : [],
        },
      };
    }
    if (url.includes(COACHES_URL)) return { ok: true, body: COACHES };
    return { ok: false, body: {} };
  });

  render(<ProgramStaffModal programId={900} programName="Summer Camp" onClose={jest.fn()} />);

  await waitFor(() => expect(screen.getByRole('option', { name: /Morgan Long/ })).toBeInTheDocument());
  fireEvent.change(screen.getByLabelText('Add a coach'), { target: { value: '158' } });
  fireEvent.click(screen.getByRole('button', { name: 'Add' }));

  expect(await screen.findByText('Morgan Long')).toBeInTheDocument();

  const calls = (global.fetch as jest.Mock).mock.calls;
  const assign = calls.find(([u]: [string]) => String(u).includes('assign-staff'));
  expect(assign).toBeDefined();
  expect(JSON.parse(assign[1].body)).toMatchObject({ program_id: 900, user_id: 158 });
});

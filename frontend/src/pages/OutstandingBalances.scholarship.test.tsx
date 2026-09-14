import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import { OutstandingBalances } from './OutstandingBalances';

/**
 * Option 2 of the scholarship find-ability plan (Maggie, 2026-09-14): the
 * treasurer's Outstanding Balances list offers Apply scholarship on each
 * payment that has an invoice, and shows a chip on one that already has a
 * scholarship. Financial admins only; a payment with no invoice says so.
 */

const mockCtx: any = { current: { role: 'club_admin', scope_type: 'club', scope_id: 53 } };
jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 53, activeContext: mockCtx.current, isClubAdmin: mockCtx.current?.role === 'club_admin' }),
}));

const balancesPayload = {
  success: true,
  summary: { total_outstanding: 250, total_families: 1, overdue_count: 0, total_overdue: 0 },
  balances: [
    {
      athlete_id: 548, athlete_name: 'Molly Clune', guardian_name: 'Kate Clune', guardian_email: 'k@x.test',
      guardian_phone: null, payment_count: 2, total_owed: '250.00', total_paid: '0.00', total_remaining: '250.00',
      earliest_due_date: null, days_overdue: 0, has_overdue: false,
      payments: [
        { id: 243, item_name: 'Fall Registration', program_name: 'Fall 2026', amount: '250.00', due_date: null, status: 'pending', scholarship_amount: '50.00', invoice_id: 19 },
        { id: 244, item_name: 'Uniform', program_name: 'Fall 2026', amount: '40.00', due_date: null, status: 'pending', scholarship_amount: 0, invoice_id: null },
      ],
    },
  ],
};

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  (global as any).fetch = jest.fn(async (url: string) => {
    if (String(url).includes('outstanding-balances.php')) return { ok: true, json: async () => balancesPayload };
    if (String(url).includes('action=get&id=19')) {
      return { ok: true, json: async () => ({ success: true, invoice: { id: 19, invoice_number: 'INV-19', subtotal: '300.00', discount_amount: '0.00', scholarship_amount: '50.00', scholarship_label: 'Scholarship', total_amount: '250.00', amount_paid: '0.00' } }) };
    }
    return { ok: true, json: async () => ({ success: true }) };
  });
});
afterEach(() => jest.restoreAllMocks());

const open = async () => {
  render(<MemoryRouter><OutstandingBalances /></MemoryRouter>);
  await screen.findByText('Molly Clune');
  fireEvent.click(screen.getByRole('button', { name: 'Expand' }));
};

test('a financial admin sees the chip, an Edit control on the invoiced payment, and No invoice on the other', async () => {
  await open();
  expect(screen.getByTestId('scholarship-chip')).toHaveTextContent('Scholarship −$50.00');
  fireEvent.click(screen.getByRole('button', { name: 'Edit scholarship' }));
  expect(await screen.findByRole('heading', { name: 'Edit scholarship' })).toBeInTheDocument();
  expect(screen.getByText('No invoice')).toBeInTheDocument();
});

test('a coach-shaped context draws no scholarship control at all', async () => {
  mockCtx.current = { role: 'coach', scope_type: 'club', scope_id: 53 };
  await open();
  await waitFor(() => expect(screen.getByText('Fall Registration')).toBeInTheDocument());
  expect(screen.queryByRole('button', { name: /scholarship/i })).toBeNull();
  mockCtx.current = { role: 'club_admin', scope_type: 'club', scope_id: 53 };
});

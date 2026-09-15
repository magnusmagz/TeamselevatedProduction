import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { MemoryRouter } from 'react-router-dom';
import { ScholarshipsReport } from './ScholarshipsReport';

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 53, activeContext: { role: 'treasurer', scope_type: 'club', scope_id: 53 }, isClubAdmin: false }),
}));

const payload = {
  success: true,
  available: true,
  summary: { count: 2, total_awarded: 250 },
  scholarships: [
    { invoice_id: 19, invoice_number: 'INV-19', athlete_id: 548, athlete_first: 'Molly', athlete_last: 'Clune', program_name: 'Fall 2026', subtotal: '300.00', discount_amount: '0.00', scholarship_amount: '50.00', scholarship_label: 'Scholarship', scholarship_reason: 'Board approved 9/12', total_amount: '250.00', amount_paid: '0.00', status: 'draft', scholarship_awarded_at: '2026-09-14 23:20:07', awarded_by_name: 'Maggie Mae' },
    { invoice_id: 21, invoice_number: 'INV-21', athlete_id: 550, athlete_last: 'Park', athlete_first: 'Liam', program_name: 'Fall 2026', subtotal: '200.00', discount_amount: '0.00', scholarship_amount: '200.00', scholarship_label: 'Booster fund', scholarship_reason: 'Hardship', total_amount: '0.00', amount_paid: '0.00', status: 'paid', scholarship_awarded_at: '2026-09-13 10:00:00', awarded_by_name: null },
  ],
};

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  (global as any).fetch = jest.fn(async (url: string) => {
    if (String(url).includes('action=scholarships&club_id=53')) return { ok: true, json: async () => payload };
    if (String(url).includes('action=get&id=19')) return { ok: true, json: async () => ({ success: true, invoice: payload.scholarships[0] }) };
    return { ok: true, json: async () => ({ success: true }) };
  });
});
afterEach(() => jest.restoreAllMocks());

test('lists every scholarship with the season total, the reason and who awarded it, and Edit opens the modal', async () => {
  render(<MemoryRouter><ScholarshipsReport /></MemoryRouter>);
  expect(await screen.findByText('Molly Clune')).toBeInTheDocument();
  expect(screen.getByTestId('total-awarded')).toHaveTextContent('$250.00 awarded');
  expect(screen.getByText('Board approved 9/12')).toBeInTheDocument();
  expect(screen.getByText('by Maggie Mae')).toBeInTheDocument();
  expect(screen.getByText('Booster fund')).toBeInTheDocument();
  fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0]);
  expect(await screen.findByRole('heading', { name: 'Edit scholarship' })).toBeInTheDocument();
});

test('an empty club says where to award one', async () => {
  (global as any).fetch = jest.fn(async () => ({ ok: true, json: async () => ({ success: true, available: true, summary: { count: 0, total_awarded: 0 }, scholarships: [] }) }));
  render(<MemoryRouter><ScholarshipsReport /></MemoryRouter>);
  expect(await screen.findByText(/No scholarships yet/)).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Go to Outstanding Balances' })).toHaveAttribute('href', '/payment/outstanding');
});

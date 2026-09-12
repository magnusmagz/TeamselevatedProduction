import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ScholarshipModal, scholarshipDollars } from './ScholarshipModal';

/**
 * The modal is a convenience over lib/scholarship.php: it converts percent to
 * dollars BEFORE submitting (the API takes dollars only), previews the new
 * total, refuses locally what the server would refuse, and renders the
 * server's sentence verbatim when it does refuse.
 */

const invoice = {
  id: 101,
  invoice_number: 'INV-1',
  subtotal: '425.00',
  discount_amount: '42.50',
  scholarship_amount: 0,
  scholarship_label: null,
  total_amount: '382.50',
  amount_paid: '0.00',
};

beforeEach(() => {
  localStorage.setItem('auth_token', 'tok');
  (global as any).fetch = jest.fn();
});
afterEach(() => jest.restoreAllMocks());

const fetchMock = () => (global as any).fetch as jest.Mock;

describe('scholarshipDollars', () => {
  it('converts a percent of the post-discount fee to cents-rounded dollars', () => {
    expect(scholarshipDollars('percent', '50', 382.5)).toBe(191.25);
    expect(scholarshipDollars('percent', '33', 100)).toBe(33);
    expect(scholarshipDollars('dollars', '200', 382.5)).toBe(200);
  });
  it('answers null for nothing, zero and negatives', () => {
    expect(scholarshipDollars('dollars', '', 100)).toBeNull();
    expect(scholarshipDollars('dollars', '0', 100)).toBeNull();
    expect(scholarshipDollars('percent', '-5', 100)).toBeNull();
  });
});

test('submits DOLLARS when the admin typed a percent, and previews the new total', async () => {
  fetchMock().mockResolvedValue({ ok: true, json: async () => ({ success: true }) });
  const onSaved = jest.fn();
  render(<ScholarshipModal invoice={invoice} onClose={() => {}} onSaved={onSaved} />);

  fireEvent.click(screen.getByRole('button', { name: '%' }));
  fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '50' } });
  fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Board approved' } });

  expect(screen.getByTestId('percent-as-dollars')).toHaveTextContent('50% of $382.50 is $191.25');
  expect(screen.getByTestId('preview')).toHaveTextContent('New total$191.25');

  fireEvent.click(screen.getByRole('button', { name: 'Apply scholarship' }));

  await waitFor(() => expect(onSaved).toHaveBeenCalled());
  const [url, init] = fetchMock().mock.calls[0];
  expect(url).toContain('action=award-scholarship&id=101');
  const body = JSON.parse(init.body);
  expect(body).toEqual({ amount: 191.25, label: 'Scholarship', reason: 'Board approved' });
});

test('refuses locally an amount above the fee, and one that would go below what was paid', () => {
  render(<ScholarshipModal invoice={{ ...invoice, amount_paid: '150.00' }} onClose={() => {}} onSaved={() => {}} />);
  fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'r' } });

  fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '400' } });
  expect(screen.getByRole('alert')).toHaveTextContent('more than the $382.50 fee');
  expect(screen.getByRole('button', { name: 'Apply scholarship' })).toBeDisabled();

  fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '300' } }); // 382.50 - 300 = 82.50 < 150 paid
  expect(screen.getByRole('alert')).toHaveTextContent('$150.00 has already been paid');
  expect(screen.getByRole('button', { name: 'Apply scholarship' })).toBeDisabled();

  fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '100' } });
  expect(screen.queryByRole('alert')).toBeNull();
  expect(screen.getByRole('button', { name: 'Apply scholarship' })).toBeEnabled();
  expect(fetchMock()).not.toHaveBeenCalled();
});

test("renders the server's refusal verbatim", async () => {
  fetchMock().mockResolvedValue({
    ok: false,
    status: 422,
    json: async () => ({ success: false, error: '$150.00 has already been paid on this invoice; a scholarship cannot take the total below that. Refund first.' }),
  });
  const onSaved = jest.fn();
  render(<ScholarshipModal invoice={invoice} onClose={() => {}} onSaved={onSaved} />);
  fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '100' } });
  fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'r' } });
  fireEvent.click(screen.getByRole('button', { name: 'Apply scholarship' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('Refund first.');
  expect(onSaved).not.toHaveBeenCalled();
});

test('an existing scholarship offers Remove, which calls revoke', async () => {
  fetchMock().mockResolvedValue({ ok: true, json: async () => ({ success: true }) });
  const onSaved = jest.fn();
  render(
    <ScholarshipModal
      invoice={{ ...invoice, scholarship_amount: '200.00', scholarship_label: 'Booster fund', total_amount: '182.50' }}
      onClose={() => {}}
      onSaved={onSaved}
    />
  );
  expect(screen.getByRole('heading', { name: 'Edit scholarship' })).toBeInTheDocument();
  expect(screen.getByLabelText('Label the family sees')).toHaveValue('Booster fund');
  fireEvent.click(screen.getByRole('button', { name: 'Remove scholarship' }));
  await waitFor(() => expect(onSaved).toHaveBeenCalled());
  expect(fetchMock().mock.calls[0][0]).toContain('action=revoke-scholarship&id=101');
});

test('the reason is required and never labelled as something the family sees', () => {
  render(<ScholarshipModal invoice={invoice} onClose={() => {}} onSaved={() => {}} />);
  expect(screen.getByLabelText(/Reason/)).toBeRequired();
  expect(screen.getByText(/the reason stays with the club/i)).toBeInTheDocument();
});

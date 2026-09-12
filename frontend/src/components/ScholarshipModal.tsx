import React, { useMemo, useState } from 'react';
import Button from './ui/Button';

/**
 * Apply / replace / remove a scholarship on one invoice.
 *
 * Decided with Maggie 2026-09-12 (docs/scholarship-on-invoice-plan-2026-09.md).
 * The API takes DOLLARS only: percent is a convenience here, converted live and
 * shown as dollars before anything is submitted, so what is stored is what was
 * seen. Every refusal from the server is rendered verbatim — the sentence names
 * the paid amount, the cap, or the missing reason.
 *
 * Rendered by AthletePaymentsDashboard for financial admins only (club admin /
 * treasurer); the server gates on the same standing, so this is a convenience,
 * not the access control.
 */

export interface ScholarshipInvoice {
  id: number;
  invoice_number: string;
  subtotal?: string | number | null;
  discount_amount?: string | number | null;
  scholarship_amount?: string | number | null;
  scholarship_label?: string | null;
  scholarship_reason?: string | null;
  total_amount: string | number;
  amount_paid: string | number;
}

interface Props {
  invoice: ScholarshipInvoice;
  onClose: () => void;
  /** Called after a successful award or removal; the parent refetches. */
  onSaved: () => void;
}

const API_URL = process.env.REACT_APP_API_URL || '';

const num = (v: string | number | null | undefined): number => {
  const n = parseFloat(String(v ?? 0));
  return Number.isFinite(n) ? n : 0;
};

export const money = (n: number): string =>
  `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** Dollars for a given input in the chosen mode, rounded to cents. */
export function scholarshipDollars(mode: 'dollars' | 'percent', raw: string, base: number): number | null {
  const v = parseFloat(raw);
  if (!Number.isFinite(v) || v <= 0) return null;
  const dollars = mode === 'percent' ? (base * v) / 100 : v;
  return Math.round(dollars * 100) / 100;
}

export const ScholarshipModal: React.FC<Props> = ({ invoice, onClose, onSaved }) => {
  const subtotal = num(invoice.subtotal);
  const discount = num(invoice.discount_amount);
  const existing = num(invoice.scholarship_amount);
  const paid = num(invoice.amount_paid);
  // The most a scholarship can be: the fee after the sibling discount.
  const base = subtotal > 0 ? subtotal - discount : num(invoice.total_amount) + existing;

  const [mode, setMode] = useState<'dollars' | 'percent'>('dollars');
  const [raw, setRaw] = useState(existing > 0 ? existing.toFixed(2) : '');
  const [label, setLabel] = useState(invoice.scholarship_label || 'Scholarship');
  const [reason, setReason] = useState(invoice.scholarship_reason || '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const dollars = useMemo(() => scholarshipDollars(mode, raw, base), [mode, raw, base]);
  const newTotal = dollars !== null ? Math.max(0, Math.round((base - dollars) * 100) / 100) : null;
  const tooMuch = dollars !== null && dollars > base + 0.000001;
  const belowPaid = newTotal !== null && newTotal < paid - 0.000001;

  const headers = () => ({
    Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
    'Content-Type': 'application/json',
  });

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (dollars === null) {
      setError('Enter an amount greater than zero.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/api/invoices.php?action=award-scholarship&id=${invoice.id}`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ amount: dollars, label: label.trim(), reason: reason.trim() }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        setError(data.error || 'The scholarship could not be saved.');
        return;
      }
      onSaved();
    } catch {
      setError('The scholarship could not be saved. Check your connection and try again.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    setSaving(true);
    setError(null);
    try {
      const res = await fetch(`${API_URL}/api/invoices.php?action=revoke-scholarship&id=${invoice.id}`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ reason: reason.trim() || null }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        setError(data.error || 'The scholarship could not be removed.');
        return;
      }
      onSaved();
    } catch {
      setError('The scholarship could not be removed. Check your connection and try again.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="scholarship-title">
      <form onSubmit={submit} className="w-full max-w-lg rounded-lg bg-white shadow-xl">
        <div className="border-b border-brand-secondary px-6 py-4">
          <h2 id="scholarship-title" className="text-lg font-bold uppercase tracking-wide text-brand-primary">
            {existing > 0 ? 'Edit scholarship' : 'Apply scholarship'}
          </h2>
          <p className="mt-1 text-sm text-brand-primary-dark">
            Invoice {invoice.invoice_number}. The family sees the label and amount; the reason stays with the club.
          </p>
        </div>

        <div className="space-y-4 px-6 py-5">
          <div>
            <label htmlFor="scholarship-amount" className="block text-sm font-medium text-brand-primary-dark">
              Amount
            </label>
            <div className="mt-1 flex gap-2">
              <input
                id="scholarship-amount"
                type="number"
                inputMode="decimal"
                min="0"
                step={mode === 'percent' ? '1' : '0.01'}
                value={raw}
                onChange={(e) => setRaw(e.target.value)}
                className="w-full rounded border border-brand-secondary px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-accent"
                required
              />
              <div className="flex rounded border border-brand-secondary text-sm" role="group" aria-label="Amount type">
                {/* A segmented toggle: its look is its state, so these stay raw (BUTTON_ALLOWLIST). */}
                <button
                  type="button"
                  onClick={() => setMode('dollars')}
                  aria-pressed={mode === 'dollars'}
                  className={`px-3 ${mode === 'dollars' ? 'bg-brand-primary text-white' : 'text-brand-primary'}`}
                >
                  $
                </button>
                <button
                  type="button"
                  onClick={() => setMode('percent')}
                  aria-pressed={mode === 'percent'}
                  className={`px-3 ${mode === 'percent' ? 'bg-brand-primary text-white' : 'text-brand-primary'}`}
                >
                  %
                </button>
              </div>
            </div>
            {mode === 'percent' && dollars !== null && (
              <p className="mt-1 text-xs text-brand-primary-dark" data-testid="percent-as-dollars">
                {raw}% of {money(base)} is {money(dollars)}. {money(dollars)} is what will be saved.
              </p>
            )}
          </div>

          <div>
            <label htmlFor="scholarship-label" className="block text-sm font-medium text-brand-primary-dark">
              Label the family sees
            </label>
            <input
              id="scholarship-label"
              type="text"
              maxLength={80}
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              className="mt-1 w-full rounded border border-brand-secondary px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-accent"
            />
          </div>

          <div>
            <label htmlFor="scholarship-reason" className="block text-sm font-medium text-brand-primary-dark">
              Reason <span className="font-normal text-gray-500">(internal, required)</span>
            </label>
            <textarea
              id="scholarship-reason"
              rows={2}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              className="mt-1 w-full rounded border border-brand-secondary px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-accent"
              required
            />
          </div>

          <dl className="rounded border border-brand-secondary bg-brand-light/40 px-4 py-3 text-sm tabular-nums" data-testid="preview">
            <div className="flex justify-between"><dt>Fee</dt><dd>{money(subtotal || base + discount)}</dd></div>
            {discount > 0 && (
              <div className="flex justify-between text-gray-600"><dt>Sibling discount</dt><dd>−{money(discount)}</dd></div>
            )}
            <div className="flex justify-between font-medium text-brand-primary">
              <dt>{label.trim() || 'Scholarship'}</dt>
              <dd>{dollars !== null ? `−${money(dollars)}` : '—'}</dd>
            </div>
            <div className="mt-1 flex justify-between border-t border-brand-secondary pt-1 font-semibold">
              <dt>New total</dt><dd>{newTotal !== null ? money(newTotal) : '—'}</dd>
            </div>
            {paid > 0 && (
              <div className="flex justify-between text-gray-600"><dt>Already paid</dt><dd>{money(paid)}</dd></div>
            )}
          </dl>

          {tooMuch && (
            <p className="text-sm text-red-700" role="alert">
              That is more than the {money(base)} fee. Enter {money(base)} or less.
            </p>
          )}
          {!tooMuch && belowPaid && (
            <p className="text-sm text-red-700" role="alert">
              {money(paid)} has already been paid on this invoice. A scholarship cannot take the total below that; refund first.
            </p>
          )}
          {error && (
            <p className="text-sm text-red-700" role="alert">{error}</p>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-brand-secondary px-6 py-4">
          <div>
            {existing > 0 && (
              <Button type="button" variant="danger-link" size="sm" onClick={remove} disabled={saving}>
                Remove scholarship
              </Button>
            )}
          </div>
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" variant="primary" loading={saving} disabled={dollars === null || tooMuch || belowPaid}>
              {existing > 0 ? 'Save scholarship' : 'Apply scholarship'}
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
};

export default ScholarshipModal;

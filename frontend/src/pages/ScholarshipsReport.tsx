import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useOrg } from '../contexts/OrgContext';
import PageHeader from '../components/ui/PageHeader';
import Button from '../components/ui/Button';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';
import { ScholarshipModal } from '../components/ScholarshipModal';
import { formatDateOnly } from '../utils/dateFormat';

/**
 * Treasurer: Scholarships report — every invoice in the club carrying a
 * scholarship, with the season total. This is the staff view, so it shows
 * the reason and who awarded it; the family never sees those.
 *
 * Reached from the Revenue dashboard and the Scholarships tile on
 * Club Settings → Payments. Awarding happens on the invoice (athlete payments
 * page or Outstanding Balances); Edit here opens the same modal.
 */

interface ScholarshipRow {
  invoice_id: number;
  invoice_number: string;
  athlete_id: number;
  athlete_first: string;
  athlete_last: string;
  program_name: string | null;
  subtotal: string;
  discount_amount: string;
  scholarship_amount: string;
  scholarship_label: string | null;
  scholarship_reason: string | null;
  total_amount: string;
  amount_paid: string;
  status: string;
  scholarship_awarded_at: string | null;
  awarded_by_name: string | null;
}

const money = (v: string | number) =>
  `$${parseFloat(String(v)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const awardedOn = (ts: string | null) => (ts ? formatDateOnly(ts.slice(0, 10)) : '');

export const ScholarshipsReport: React.FC = () => {
  const { currentClubId, activeContext } = useOrg();
  const clubId = currentClubId ?? activeContext?.scope_id ?? null;
  const [rows, setRows] = useState<ScholarshipRow[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<number | null>(null);

  const load = useCallback(async () => {
    if (clubId == null) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${process.env.REACT_APP_API_URL}/api/invoices.php?action=scholarships&club_id=${clubId}`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        setError(data.error || 'Scholarships could not be loaded.');
        return;
      }
      setRows(data.scholarships ?? []);
      setTotal(Number(data.summary?.total_awarded ?? 0));
    } catch {
      setError('Scholarships could not be loaded. Check your connection and try again.');
    } finally {
      setLoading(false);
    }
  }, [clubId]);

  useEffect(() => {
    load();
  }, [load]);

  const columns: DataTableColumn<ScholarshipRow>[] = [
    {
      key: 'athlete',
      header: 'Athlete',
      sortable: true,
      sortValue: (r) => `${r.athlete_last} ${r.athlete_first}`,
      render: (r) => (
        <Link to={`/athlete/${r.athlete_id}/payments`} className="font-semibold text-brand-primary hover:underline">
          {r.athlete_first} {r.athlete_last}
        </Link>
      ),
    },
    { key: 'invoice', header: 'Invoice', render: (r) => <span className="font-mono text-xs">{r.invoice_number}</span> },
    { key: 'program', header: 'Program', render: (r) => r.program_name || '' },
    { key: 'label', header: 'Label', render: (r) => r.scholarship_label || 'Scholarship' },
    {
      key: 'amount',
      header: 'Scholarship',
      align: 'right',
      sortable: true,
      sortValue: (r) => parseFloat(r.scholarship_amount),
      render: (r) => <span className="font-semibold tabular-nums">{money(r.scholarship_amount)}</span>,
    },
    {
      key: 'balance',
      header: 'Balance due',
      align: 'right',
      render: (r) => <span className="tabular-nums">{money(Math.max(0, parseFloat(r.total_amount) - parseFloat(r.amount_paid)))}</span>,
    },
    { key: 'status', header: 'Status', render: (r) => <span className="uppercase text-xs font-semibold">{r.status}</span> },
    {
      key: 'awarded',
      header: 'Awarded',
      sortable: true,
      sortValue: (r) => r.scholarship_awarded_at || '',
      render: (r) => (
        <span className="text-sm">
          {awardedOn(r.scholarship_awarded_at)}
          {r.awarded_by_name && <span className="block text-xs text-gray-500">by {r.awarded_by_name}</span>}
        </span>
      ),
    },
    {
      key: 'reason',
      header: 'Reason',
      render: (r) => <span className="text-sm text-gray-700">{r.scholarship_reason || ''}</span>,
    },
    {
      key: 'edit',
      header: '',
      actions: true,
      render: (r) => (
        <Button variant="secondary" size="sm" onClick={() => setEditing(r.invoice_id)}>
          Edit
        </Button>
      ),
    },
  ];

  return (
    <div className="container mx-auto p-6">
      <PageHeader
        title="Scholarships"
        subtitle="Every scholarship awarded on an invoice in your club. The reason is for the club's records; families see only the label and amount."
        backTo="/payment/revenue"
        backLabel="Revenue"
        meta={
          <>
            <span className="rounded-full bg-brand-light px-3 py-1 text-sm font-semibold text-brand-primary" data-testid="total-awarded">
              {money(total)} awarded
            </span>
            <span className="text-sm text-gray-600">{rows.length} {rows.length === 1 ? 'scholarship' : 'scholarships'}</span>
          </>
        }
      />

      {error && (
        <p className="mb-4 text-sm text-red-700" role="alert">
          {error}
        </p>
      )}

      {loading ? (
        <p className="py-10 text-center text-gray-500">Loading scholarships...</p>
      ) : (
        <DataTable<ScholarshipRow>
          columns={columns}
          rows={rows}
          rowKey={(r) => r.invoice_id}
          defaultSort={{ key: 'awarded', dir: 'desc' }}
          emptyState={{
            text: 'No scholarships yet. Award one from an invoice on an athlete’s payments page or from Outstanding Balances.',
            action: (
              <Link to="/payment/outstanding" className="text-brand-primary underline">
                Go to Outstanding Balances
              </Link>
            ),
          }}
        />
      )}

      {editing != null && (
        <ScholarshipModal
          invoiceId={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            load();
          }}
        />
      )}
    </div>
  );
};

export default ScholarshipsReport;

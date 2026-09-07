import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../contexts/OrgContext';
import DataTable, { DataTableColumn } from './ui/DataTable';
import Button from './ui/Button';

interface PaymentAccount {
  stripe_account_id: string;
  onboarding_status: 'pending' | 'in_progress' | 'restricted' | 'complete';
  charges_enabled: boolean;
  payouts_enabled: boolean;
  details_submitted: boolean;
}

interface ReportSummary {
  collected: number;
  refunded: number;
  net: number;
  transaction_count: number;
}

interface ReportTransaction {
  id: number;
  payer_name: string;
  amount: number;
  refund_amount: number;
  payment_method: string;
  status: string;
  invoice_numbers: string[];
  date: string;
}

interface Payout {
  stripe_payout_id: string;
  amount: number;
  status: string;
  arrival_date: string | null;
  failure_message: string | null;
}

const STATUS_COPY: Record<PaymentAccount['onboarding_status'], { label: string; blurb: string }> = {
  pending: { label: 'Not started', blurb: 'Onboarding has not been started yet.' },
  in_progress: {
    label: 'Onboarding in progress',
    blurb: 'Stripe still needs some information before your club can accept payments. Pick up where you left off below.',
  },
  restricted: {
    label: 'Action required',
    blurb: 'Your details were submitted, but Stripe has not enabled charges yet. Continue below to see what Stripe still needs.',
  },
  complete: {
    label: 'Ready to accept payments',
    blurb: 'Your club is fully set up. Registration fees, camps, and invoices can be paid online.',
  },
};

const Flag: React.FC<{ ok: boolean; children: React.ReactNode }> = ({ ok, children }) => (
  <span className="inline-flex items-center gap-1.5 text-sm mr-4">
    <span className={`inline-block w-2.5 h-2.5 rounded-full ${ok ? 'bg-green-500' : 'bg-gray-300'}`} />
    <span className={ok ? 'text-gray-800' : 'text-gray-500'}>{children}</span>
  </span>
);

const ClubPaymentsSettings: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { isClubAdmin, currentClubId } = useOrg();

  const [account, setAccount] = useState<PaymentAccount | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Treasurer reporting — loaded once an account exists.
  const [summary, setSummary] = useState<ReportSummary | null>(null);
  const [transactions, setTransactions] = useState<ReportTransaction[]>([]);
  const [payouts, setPayouts] = useState<Payout[]>([]);
  const justReturned = new URLSearchParams(window.location.search).get('onboarding') === 'return';

  const fetchStatus = useCallback(async () => {
    if (!currentClubId) return;
    const token = localStorage.getItem('auth_token');
    try {
      const res = await fetch(
        `${API_URL}/api/payment-accounts.php?action=status&club_id=${currentClubId}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to load payment status');
      setAccount(data.account);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load payment status');
    } finally {
      setLoading(false);
    }
  }, [API_URL, currentClubId]);

  useEffect(() => {
    fetchStatus();
  }, [fetchStatus]);

  useEffect(() => {
    if (!account || !currentClubId) return;
    const token = localStorage.getItem('auth_token');
    const headers = { Authorization: `Bearer ${token}` };
    const get = async (action: string) => {
      const res = await fetch(`${API_URL}/api/payment-reports.php?action=${action}&club_id=${currentClubId}`, { headers });
      return res.ok ? res.json() : null;
    };
    (async () => {
      try {
        const [s, t, p] = await Promise.all([get('summary'), get('transactions'), get('payouts')]);
        if (s?.success) setSummary(s.summary);
        if (t?.success) setTransactions(t.transactions);
        if (p?.success) setPayouts(p.payouts);
      } catch {
        // reporting is supplementary — the account card above still works
      }
    })();
  }, [API_URL, account, currentClubId]);

  // After returning from Stripe, the account.updated webhook can lag the
  // redirect by a few seconds — re-poll briefly so the badges flip without a
  // manual refresh.
  useEffect(() => {
    if (!justReturned || account?.onboarding_status === 'complete') return;
    const timer = setInterval(fetchStatus, 4000);
    const stop = setTimeout(() => clearInterval(timer), 30000);
    return () => {
      clearInterval(timer);
      clearTimeout(stop);
    };
  }, [justReturned, account?.onboarding_status, fetchStatus]);

  const startOnboarding = async (action: 'create' | 'refresh-link') => {
    if (!currentClubId) return;
    setWorking(true);
    setError(null);
    const token = localStorage.getItem('auth_token');
    try {
      const res = await fetch(`${API_URL}/api/payment-accounts.php?action=${action}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ club_id: currentClubId }),
      });
      const data = await res.json();
      if (!res.ok || !data.url) throw new Error(data.error || 'Could not start Stripe onboarding');
      window.location.href = data.url; // hand off to Stripe-hosted onboarding
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not start Stripe onboarding');
      setWorking(false);
    }
  };

  if (!isClubAdmin) {
    return (
      <p className="text-gray-600">
        Only club administrators can manage payment settings.
      </p>
    );
  }

  if (loading) {
    return <p className="text-gray-500">Loading payment status…</p>;
  }

  const status = account ? STATUS_COPY[account.onboarding_status] : null;

  const transactionColumns: DataTableColumn<ReportTransaction>[] = [
    { key: 'payer_name', header: 'Payer', render: (t) => t.payer_name },
    {
      key: 'invoices',
      header: 'Invoices',
      className: 'text-gray-500 font-mono text-xs',
      render: (t) => t.invoice_numbers.join(', '),
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      className: 'tabular-nums',
      render: (t) => (
        <>
          <span className={t.refund_amount > 0 ? 'text-gray-400' : 'text-gray-900'}>
            ${t.amount.toFixed(2)}
          </span>
          {t.refund_amount > 0 && (
            <span className="block text-xs text-amber-700">-${t.refund_amount.toFixed(2)} refunded</span>
          )}
        </>
      ),
    },
    {
      key: 'date',
      header: 'Date',
      className: 'text-gray-500',
      render: (t) => new Date(t.date).toLocaleDateString(),
    },
  ];

  return (
    <div className="max-w-2xl">
      <h2 className="text-lg font-semibold text-brand-primary mb-2">Online Payments</h2>
      <p className="text-sm text-gray-600 mb-6">
        Connect your club to Stripe to accept registration fees, camp payments, and invoices
        online. Money goes directly to your club's bank account.
      </p>

      {error && (
        <div className="mb-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {!account ? (
        <div className="rounded-md border border-brand-secondary p-6">
          <p className="text-gray-700 mb-4">
            Your club isn't set up for online payments yet. Setup takes about 10 minutes and is
            handled securely by Stripe — you'll need your club's bank account details and tax ID.
          </p>
          <Button onClick={() => startOnboarding('create')} disabled={working}>
            {working ? 'Redirecting to Stripe…' : 'Set up payments with Stripe'}
          </Button>
        </div>
      ) : (
        <div className="rounded-md border border-brand-secondary p-6">
          <div className="flex items-center justify-between mb-3">
            <span
              className={`inline-block rounded-full px-3 py-1 text-xs font-semibold uppercase ${
                account.onboarding_status === 'complete'
                  ? 'bg-green-100 text-green-800'
                  : account.onboarding_status === 'restricted'
                  ? 'bg-amber-100 text-amber-800'
                  : 'bg-gray-100 text-gray-700'
              }`}
            >
              {status?.label}
            </span>
            <Button variant="link" onClick={fetchStatus}>
              Refresh status
            </Button>
          </div>

          <p className="text-sm text-gray-600 mb-4">
            {justReturned && account.onboarding_status !== 'complete'
              ? 'Stripe is finalizing your account — this usually takes under a minute.'
              : status?.blurb}
          </p>

          <div className="mb-5">
            <Flag ok={account.details_submitted}>Details submitted</Flag>
            <Flag ok={account.charges_enabled}>Charges enabled</Flag>
            <Flag ok={account.payouts_enabled}>Payouts enabled</Flag>
          </div>

          {account.onboarding_status !== 'complete' && (
            <Button onClick={() => startOnboarding('refresh-link')} disabled={working}>
              {working ? 'Redirecting to Stripe…' : 'Continue setup on Stripe'}
            </Button>
          )}
        </div>
      )}

      {account && summary && (
        <div className="mt-8">
          <h3 className="text-base font-semibold text-brand-primary mb-3">Reporting</h3>

          <div className="grid grid-cols-3 gap-3 mb-5">
            <div className="rounded-md border border-brand-secondary p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500 mb-1">Collected</p>
              <p className="text-xl font-bold text-gray-900 tabular-nums">${summary.collected.toFixed(2)}</p>
            </div>
            <div className="rounded-md border border-brand-secondary p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500 mb-1">Refunded</p>
              <p className="text-xl font-bold text-gray-900 tabular-nums">${summary.refunded.toFixed(2)}</p>
            </div>
            <div className="rounded-md border border-brand-secondary p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500 mb-1">Net</p>
              <p className="text-xl font-bold text-brand-primary tabular-nums">${summary.net.toFixed(2)}</p>
            </div>
          </div>

          <div className="mb-5">
            <p className="text-sm font-medium text-gray-900 mb-2">Bank payouts</p>
            {payouts.length === 0 ? (
              <p className="text-sm text-gray-500">
                Deposits to your bank account will appear here after your first payout from Stripe.
              </p>
            ) : (
              <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
                {payouts.map((p) => (
                  <li key={p.stripe_payout_id} className="flex items-center justify-between px-4 py-2 text-sm">
                    <span className="text-gray-700">
                      {p.arrival_date || 'Pending'}
                      {p.status === 'failed' && (
                        <span className="ml-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded px-1.5">
                          failed{p.failure_message ? ` — ${p.failure_message}` : ''}
                        </span>
                      )}
                    </span>
                    <span className="font-medium tabular-nums text-gray-900">${p.amount.toFixed(2)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div>
            <p className="text-sm font-medium text-gray-900 mb-2">
              Recent payments{summary.transaction_count > 0 ? ` (${summary.transaction_count})` : ''}
            </p>
            <DataTable<ReportTransaction>
              columns={transactionColumns}
              rows={transactions}
              rowKey={(t) => t.id}
              emptyState="No online payments yet."
            />
          </div>
        </div>
      )}
    </div>
  );
};

export default ClubPaymentsSettings;

import React, { useState, useEffect, useCallback } from 'react';
import { useOrg } from '../contexts/OrgContext';
import { daysUntilExpiry, formatDocumentDate } from '../utils/documentStatus';
import PageHeader from './ui/PageHeader';
import DataTable, { DataTableColumn } from './ui/DataTable';
import Button from './ui/Button';

interface ExpiringDocument {
  id: number;
  title: string;
  slot?: string | null;
  file_path?: string | null;
  link_url?: string | null;
  expires_at: string;
  is_required: boolean;
  uploaded_by_name?: string;
}

type ExpiringDocumentWithDays = ExpiringDocument & { days: number };

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

const ExpirationDashboard: React.FC = () => {
  const { currentClubId } = useOrg();
  const [docs, setDocs] = useState<ExpiringDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [daysFilter, setDaysFilter] = useState(30);

  const fetchExpiring = useCallback(async () => {
    if (!currentClubId) {
      setDocs([]);
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${API_URL}/api/documents-gateway.php?action=expiring&club_profile_id=${currentClubId}&days=${daysFilter}`,
        { headers: token ? { Authorization: `Bearer ${token}` } : {} }
      );
      const data = await res.json();
      if (data.success && Array.isArray(data.documents)) {
        setDocs(data.documents);
      } else {
        setDocs([]);
        if (!data.success) setError(data.error || 'Failed to load expiring documents');
      }
    } catch {
      setError('Failed to load expiring documents');
    } finally {
      setLoading(false);
    }
  }, [currentClubId, daysFilter]);

  useEffect(() => {
    fetchExpiring();
  }, [fetchExpiring]);

  if (loading) return <div className="p-5">Loading expiration alerts...</div>;
  if (error) return <div className="p-5 text-red-600">{error}</div>;

  const withDays = docs.map((d) => ({ ...d, days: daysUntilExpiry(d.expires_at) }));
  const expired = withDays.filter((d) => d.days < 0);
  const thisWeek = withDays.filter((d) => d.days >= 0 && d.days <= 7);
  const nextTwoWeeks = withDays.filter((d) => d.days > 7 && d.days <= 14);
  const thisMonth = withDays.filter((d) => d.days > 14 && d.days <= 30);
  const upcoming = withDays.filter((d) => d.days >= 0).sort((a, b) => a.days - b.days);

  const expiredColumns: DataTableColumn<ExpiringDocumentWithDays>[] = [
    { key: 'title', header: 'Document', className: 'font-medium', render: (doc) => doc.title },
    {
      key: 'slot',
      header: 'Category',
      className: 'capitalize',
      render: (doc) => (doc.slot ? doc.slot.replace(/_/g, ' ') : '—'),
    },
    {
      key: 'expired',
      header: 'Expired',
      className: 'text-red-600 font-bold',
      render: (doc) => `${Math.abs(doc.days)} days ago`,
    },
  ];

  const upcomingColumns: DataTableColumn<ExpiringDocumentWithDays>[] = [
    {
      key: 'days',
      header: 'Days',
      render: (doc) => (
        <span className={`px-3 py-1 border rounded-md text-sm font-bold uppercase ${
          doc.days <= 7
            ? 'border-red-200 bg-red-50 text-red-600'
            : doc.days <= 14
            ? 'border-orange-200 bg-orange-50 text-orange-600'
            : doc.days <= 30
            ? 'border-yellow-200 bg-yellow-50 text-yellow-600'
            : 'border-brand-secondary bg-gray-50 text-brand-primary'
        }`}>
          {doc.days}d
        </span>
      ),
    },
    { key: 'title', header: 'Document', className: 'font-medium text-brand-primary', render: (doc) => doc.title },
    {
      key: 'slot',
      header: 'Category',
      className: 'text-brand-primary capitalize',
      render: (doc) => (doc.slot ? doc.slot.replace(/_/g, ' ') : '—'),
    },
    {
      key: 'expires_at',
      header: 'Expires',
      className: 'text-brand-primary',
      render: (doc) => formatDocumentDate(doc.expires_at),
    },
    {
      key: 'required',
      header: 'Required?',
      render: (doc) =>
        doc.is_required ? (
          <span className="px-2 py-0.5 text-xs font-semibold uppercase rounded bg-amber-100 text-amber-700">
            Required
          </span>
        ) : (
          <span className="text-xs text-gray-400">Optional</span>
        ),
    },
    {
      key: 'action',
      header: 'Action',
      align: 'left',
      render: () => (
        <Button size="sm" onClick={() => (window.location.href = '/club-documents')}>
          Manage →
        </Button>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Document Expiration Dashboard"
        subtitle="Monitor and manage expiring club documents"
      />

      {/* Filter */}
      <div className="mb-6 flex gap-2">
        {[7, 30, 60, 90].map((d) => (
          <button
            key={d}
            onClick={() => setDaysFilter(d)}
            className={`px-4 py-2 border border-brand-secondary rounded-md uppercase font-medium ${
              daysFilter === d
                ? 'bg-brand-primary text-white'
                : 'bg-white text-brand-primary hover:bg-brand-primary hover:text-white'
            }`}
          >
            Next {d} Days
          </button>
        ))}
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        {expired.length > 0 && (
          <div className="p-6 border border-red-200 rounded-md bg-red-50">
            <div className="text-4xl font-bold text-red-600 mb-1">{expired.length}</div>
            <div className="text-sm text-red-800 uppercase tracking-wide">Already Expired</div>
          </div>
        )}
        <div className={`p-6 border rounded-md ${
          thisWeek.length > 0 ? 'border-orange-200 bg-orange-50' : 'border-brand-secondary bg-white'
        }`}>
          <div className={`text-4xl font-bold mb-1 ${thisWeek.length > 0 ? 'text-orange-600' : 'text-brand-primary'}`}>
            {thisWeek.length}
          </div>
          <div className="text-sm uppercase tracking-wide text-brand-primary">This Week</div>
        </div>
        <div className={`p-6 border rounded-md ${
          nextTwoWeeks.length > 0 ? 'border-yellow-200 bg-yellow-50' : 'border-brand-secondary bg-white'
        }`}>
          <div className={`text-4xl font-bold mb-1 ${nextTwoWeeks.length > 0 ? 'text-yellow-600' : 'text-brand-primary'}`}>
            {nextTwoWeeks.length}
          </div>
          <div className="text-sm uppercase tracking-wide text-brand-primary">Next 2 Weeks</div>
        </div>
        <div className="p-6 border border-brand-secondary rounded-md bg-white">
          <div className="text-4xl font-bold text-brand-primary mb-1">{thisMonth.length}</div>
          <div className="text-sm uppercase tracking-wide text-brand-primary">This Month</div>
        </div>
      </div>

      {/* Expired alert */}
      {expired.length > 0 && (
        <div className="mb-6 p-6 bg-red-50 border border-red-200 rounded-md">
          <h3 className="font-bold text-red-800 mb-4 text-lg uppercase tracking-wide">
            ⚠️ EXPIRED DOCUMENTS — Immediate Action Required
          </h3>
          <DataTable<ExpiringDocumentWithDays>
            className="border-red-200"
            columns={expiredColumns}
            rows={expired}
            rowKey={(doc) => doc.id}
            rowClassName={() => 'hover:bg-red-50'}
          />
        </div>
      )}

      {/* Upcoming */}
      {upcoming.length > 0 && (
        <div className="border border-brand-secondary rounded-md bg-white">
          <div className="bg-brand-primary p-4 border-b border-brand-secondary">
            <h3 className="font-bold text-white uppercase tracking-wide">Expiring Documents</h3>
          </div>
          <DataTable<ExpiringDocumentWithDays>
            className="rounded-t-none border-0"
            columns={upcomingColumns}
            rows={upcoming}
            rowKey={(doc) => doc.id}
          />
        </div>
      )}

      {/* Empty state */}
      {docs.length === 0 && (
        <div className="p-12 text-center bg-green-50 border border-green-200 rounded-md">
          <h3 className="font-bold text-green-800 mb-3 text-xl uppercase tracking-wide">All Clear!</h3>
          <p className="text-green-700 text-lg">No documents expiring in the next {daysFilter} days.</p>
        </div>
      )}
    </div>
  );
};

export default ExpirationDashboard;

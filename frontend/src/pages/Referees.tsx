import React, { useCallback, useEffect, useMemo, useState } from 'react';
import PageHeader from '../components/ui/PageHeader';
import DataTable, { DataTableColumn } from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import { useOrg } from '../contexts/OrgContext';
import { RefereeRow } from '../components/referee/refereeTypes';
import { REFEREE_GRADE_OPTIONS, OTHER_GRADE, isListedGrade } from '../constants/refereeGrades';
import { portalStatusMeta } from '../utils/portalStatus';

/**
 * People → Referees: the club's referee directory (Maggie, 2026-09-08).
 *
 * One row per person per club — the club's grade, certification and notes
 * about them. "Invite to portal" gives the referee an account with the
 * `referee` role in this club (or adds the role to the account that already
 * holds the address — one person, many clubs) and links the row to it; from
 * then on the referee sees their games at /referee.
 *
 * Club admin only (ProtectedClubAdminRoute in App.tsx; te_is_club_admin on
 * every write server-side). Archive, never delete.
 */
const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';

interface FormState {
  id: number | null;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  gradeChoice: string;
  gradeOther: string;
  certification_level: string;
  notes: string;
  invite: boolean;
}

const emptyForm = (): FormState => ({
  id: null, first_name: '', last_name: '', email: '', phone: '', gradeChoice: '', gradeOther: '',
  certification_level: '', notes: '', invite: false,
});

function formFromRow(r: RefereeRow): FormState {
  const listed = isListedGrade(r.grade);
  return {
    id: r.id,
    first_name: r.first_name,
    last_name: r.last_name,
    email: r.email ?? '',
    phone: r.phone ?? '',
    gradeChoice: r.grade ? (listed ? r.grade : OTHER_GRADE) : '',
    gradeOther: r.grade && !listed ? r.grade : '',
    certification_level: r.certification_level ?? '',
    notes: r.notes ?? '',
    invite: false,
  };
}

function gradeValue(f: FormState): string {
  if (f.gradeChoice === OTHER_GRADE) return f.gradeOther.trim();
  return f.gradeChoice;
}

const Referees: React.FC = () => {
  const { currentClubId } = useOrg();
  const [rows, setRows] = useState<RefereeRow[]>([]);
  const [available, setAvailable] = useState(true);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showArchived, setShowArchived] = useState(false);
  const [form, setForm] = useState<FormState | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers = useMemo(
    () => ({ 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }),
    [token]
  );

  const load = useCallback(async () => {
    if (currentClubId == null) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setLoadError(null);
    try {
      const res = await fetch(
        `${API_URL}/api/referees.php?action=list&club_id=${currentClubId}${showArchived ? '&include_archived=1' : ''}`,
        { headers }
      );
      const data = await res.json();
      if (!res.ok || !data?.success) {
        setLoadError(data?.error || `Could not load referees (${res.status})`);
        setRows([]);
        return;
      }
      setAvailable(data.available !== false);
      setRows(Array.isArray(data.referees) ? data.referees : []);
    } catch (err: any) {
      setLoadError(err?.message || 'Could not load referees');
    } finally {
      setLoading(false);
    }
  }, [currentClubId, headers, showArchived]);

  useEffect(() => {
    load();
  }, [load]);

  const post = async (action: string, body: Record<string, unknown>, method = 'POST') => {
    const res = await fetch(`${API_URL}/api/referees.php?action=${action}`, { method, headers, body: JSON.stringify(body) });
    const data = await res.json().catch(() => ({}));
    return { res, data };
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form || currentClubId == null) return;
    setSaveError(null);
    const isEdit = form.id !== null;
    const payload: Record<string, unknown> = {
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      email: form.email.trim(),
      phone: form.phone.trim(),
      grade: gradeValue(form),
      certification_level: form.certification_level.trim(),
      notes: form.notes.trim(),
    };
    if (isEdit) payload.id = form.id;
    else {
      payload.club_id = currentClubId;
      payload.invite = form.invite && form.email.trim() !== '';
    }
    setSaving(true);
    try {
      const { res, data } = await post(isEdit ? 'update' : 'create', payload, isEdit ? 'PUT' : 'POST');
      if (!res.ok || !data?.success) {
        setSaveError(data?.error || `Could not save (${res.status})`);
        return;
      }
      setForm(null);
      if (!isEdit && data.invite?.message) setNotice(`${data.referee?.name ?? 'Referee'} added. ${data.invite.message}`);
      else setNotice(isEdit ? 'Referee updated.' : 'Referee added.');
      await load();
    } catch (err: any) {
      setSaveError(err?.message || 'Could not save');
    } finally {
      setSaving(false);
    }
  };

  const archiveToggle = async (r: RefereeRow) => {
    setBusyId(r.id);
    setNotice(null);
    try {
      const { res, data } = await post(r.archived_at ? 'restore' : 'archive', { id: r.id });
      if (!res.ok || !data?.success) {
        setNotice(data?.error || `Could not ${r.archived_at ? 'restore' : 'archive'} (${res.status})`);
        return;
      }
      await load();
    } finally {
      setBusyId(null);
    }
  };

  const invite = async (r: RefereeRow) => {
    setBusyId(r.id);
    setNotice(null);
    try {
      const { res, data } = await post('invite', { id: r.id });
      if (!res.ok || !data?.success) {
        setNotice(data?.error || `Could not invite (${res.status})`);
        return;
      }
      setNotice(`${r.name}: ${data.invite?.message ?? 'Invited.'}`);
      await load();
    } finally {
      setBusyId(null);
    }
  };

  const portalCell = (r: RefereeRow) => {
    if (r.status) {
      const meta = portalStatusMeta(r.status);
      return (
        <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium ${meta.cls}`} title={meta.help}>
          <span className={`w-1.5 h-1.5 rounded-full ${meta.dot}`} />
          {meta.label}
        </span>
      );
    }
    return <span className="text-xs text-gray-600">{r.user_id ? 'Has account' : 'No account'}</span>;
  };

  const columns: DataTableColumn<RefereeRow>[] = [
    {
      key: 'name', header: 'Name', sortable: true, sortValue: (r) => `${r.last_name} ${r.first_name}`.toLowerCase(),
      render: (r) => (
        <span className="text-brand-primary font-medium">
          {r.name}
          {r.archived_at && <span className="ml-2 text-xs text-gray-500 font-normal">(archived)</span>}
        </span>
      ),
    },
    { key: 'email', header: 'Email', sortable: true, sortValue: (r) => r.email ?? '', render: (r) => <span className="text-gray-700">{r.email || '—'}</span> },
    { key: 'phone', header: 'Phone', render: (r) => <span className="text-gray-700">{r.phone || '—'}</span> },
    { key: 'grade', header: 'Grade', sortable: true, sortValue: (r) => r.grade ?? '', render: (r) => <span className="text-gray-700">{r.grade || '—'}</span> },
    { key: 'certification_level', header: 'Certification', render: (r) => <span className="text-gray-700">{r.certification_level || '—'}</span> },
    { key: 'portal', header: 'Portal', render: portalCell },
    {
      key: 'actions', header: '', actions: true,
      render: (r) => (
        <div className="flex justify-end gap-3">
          <Button variant="link" size="sm" onClick={() => { setSaveError(null); setForm(formFromRow(r)); }}>Edit</Button>
          {!r.user_id && r.email && !r.archived_at && (
            <Button variant="link" size="sm" disabled={busyId === r.id} onClick={() => invite(r)}>Invite to portal</Button>
          )}
          <Button variant={r.archived_at ? 'link' : 'danger-link'} size="sm" disabled={busyId === r.id} onClick={() => archiveToggle(r)}>
            {r.archived_at ? 'Restore' : 'Archive'}
          </Button>
        </div>
      ),
    },
  ];

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <PageHeader
        title="Referees"
        subtitle="Your club's referee directory. Invite a referee to the portal so they can see their games."
        actions={
          <Button onClick={() => { setSaveError(null); setForm(emptyForm()); }} disabled={!available}>
            + Add Referee
          </Button>
        }
        meta={
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} />
            Show archived
          </label>
        }
      />

      {notice && (
        <div className="mb-4 bg-brand-light/40 border border-brand-secondary rounded-md p-3 text-sm text-brand-primary" role="status">
          {notice}
        </div>
      )}
      {!available && !loading && (
        <div className="mb-4 bg-amber-50 border border-amber-200 rounded-md p-3 text-sm text-amber-900">
          The referee directory is not switched on for this club yet.
        </div>
      )}
      {loadError && (
        <div className="mb-4 bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800">{loadError}</div>
      )}

      <DataTable<RefereeRow>
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        defaultSort={{ key: 'name', dir: 'asc' }}
        emptyState={loading ? 'Loading…' : { text: 'No referees yet.', action: undefined }}
        caption="Referees"
      />

      {form && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center p-4 z-50 overflow-y-auto">
          <div className="bg-white border border-brand-secondary rounded-md max-w-lg w-full my-8">
            <div className="border-b border-brand-secondary px-6 py-4">
              <h2 className="text-lg font-semibold text-brand-primary uppercase tracking-wide">
                {form.id === null ? 'Add Referee' : 'Edit Referee'}
              </h2>
            </div>
            <form onSubmit={submit} className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="ref-first" className="block text-sm font-medium text-gray-700">First name</label>
                  <input id="ref-first" type="text" required value={form.first_name}
                    onChange={(e) => setForm({ ...form, first_name: e.target.value })}
                    className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
                </div>
                <div>
                  <label htmlFor="ref-last" className="block text-sm font-medium text-gray-700">Last name</label>
                  <input id="ref-last" type="text" required value={form.last_name}
                    onChange={(e) => setForm({ ...form, last_name: e.target.value })}
                    className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
                </div>
              </div>
              <div>
                <label htmlFor="ref-email" className="block text-sm font-medium text-gray-700">Email</label>
                <input id="ref-email" type="email" value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
              </div>
              <div>
                <label htmlFor="ref-phone" className="block text-sm font-medium text-gray-700">Phone</label>
                <input id="ref-phone" type="tel" value={form.phone}
                  onChange={(e) => setForm({ ...form, phone: e.target.value })}
                  placeholder="(316) 555-0100"
                  className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="ref-grade" className="block text-sm font-medium text-gray-700">Grade</label>
                  <select id="ref-grade" value={form.gradeChoice}
                    onChange={(e) => setForm({ ...form, gradeChoice: e.target.value })}
                    className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm bg-white">
                    <option value="">Not set</option>
                    <optgroup label="US Soccer">
                      {REFEREE_GRADE_OPTIONS.filter((o) => o.group === 'current').map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                      ))}
                    </optgroup>
                    <optgroup label="Older scale">
                      {REFEREE_GRADE_OPTIONS.filter((o) => o.group === 'legacy').map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                      ))}
                    </optgroup>
                    <option value={OTHER_GRADE}>Other</option>
                  </select>
                  {form.gradeChoice === OTHER_GRADE && (
                    <input aria-label="Other grade" type="text" value={form.gradeOther}
                      onChange={(e) => setForm({ ...form, gradeOther: e.target.value })}
                      placeholder="Describe the grade"
                      className="mt-2 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
                  )}
                </div>
                <div>
                  <label htmlFor="ref-cert" className="block text-sm font-medium text-gray-700">Certification</label>
                  <input id="ref-cert" type="text" value={form.certification_level}
                    onChange={(e) => setForm({ ...form, certification_level: e.target.value })}
                    placeholder="e.g. SafeSport, Futsal"
                    className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
                </div>
              </div>
              <div>
                <label htmlFor="ref-notes" className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea id="ref-notes" rows={3} value={form.notes}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  className="mt-1 block w-full border border-brand-secondary rounded-md px-3 py-2 text-sm" />
              </div>
              {form.id === null && form.email.trim() !== '' && (
                <label className="flex items-start gap-2 text-sm text-gray-800">
                  <input type="checkbox" checked={form.invite} onChange={(e) => setForm({ ...form, invite: e.target.checked })} className="mt-0.5" />
                  <span>
                    <span className="font-medium">Invite to portal</span>
                    <span className="block text-xs text-gray-500">Emails a link to set up their account. If they already have one, it is added to this club.</span>
                  </span>
                </label>
              )}
              {saveError && (
                <div className="bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800" role="alert">{saveError}</div>
              )}
              <div className="flex justify-end gap-2 pt-2 border-t border-brand-secondary">
                <Button variant="secondary" onClick={() => setForm(null)}>Cancel</Button>
                <Button type="submit" loading={saving}>{form.id === null ? 'Add Referee' : 'Save'}</Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </main>
  );
};

export default Referees;

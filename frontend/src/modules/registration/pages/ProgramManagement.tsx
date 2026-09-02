import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { Program, ProgramType, ProgramStatus } from '../types';
import { formatDateOnly } from '../../../utils/dateFormat';
import ProgramFormBuilder from '../components/ProgramFormBuilder';
import EmbedCodeModal from '../components/EmbedCodeModal';
import RegistrationsModal from '../components/RegistrationsModal';
import TryoutCreationWizard from '../components/TryoutCreationWizard';
import TryoutManagement from '../components/TryoutManagement';
import { useAuth } from '../../../hooks/useAuth';
import { useOrg } from '../../../contexts/OrgContext';
import { listTournaments } from '../../tournament/api/tournamentApi';
import { Tournament, TOURNAMENT_STATUS_CONFIG } from '../../tournament/types';

type ProgramTab = 'all' | ProgramType;

const TAB_STORAGE_KEY = 'programs-active-tab';
// Which type sections are collapsed, per browser. Purely a viewing convenience —
// nothing here is state anyone else needs to see, so localStorage is the right
// home for it and a read that throws (private browsing, blocked site data) must
// leave every section open rather than break the page.
const COLLAPSED_STORAGE_KEY = 'programs-collapsed-types';

const TABS: { value: ProgramTab; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'league', label: 'League' },
  { value: 'camp', label: 'Camp' },
  { value: 'clinic', label: 'Clinic' },
  { value: 'tryout', label: 'Tryout' },
  { value: 'tournament', label: 'Tournament' },
  { value: 'drop_in', label: 'Drop-In' },
];

// Section order on the All tab. A type that is not listed here still renders —
// it falls to the end rather than disappearing, the same rule the email template
// taxonomy uses for an unknown category.
const TYPE_SECTION_ORDER: ProgramType[] = ['league', 'camp', 'clinic', 'tryout', 'tournament', 'drop_in'];

const readCollapsedTypes = (): string[] => {
  try {
    const raw = typeof window !== 'undefined' ? localStorage.getItem(COLLAPSED_STORAGE_KEY) : null;
    const parsed = raw ? JSON.parse(raw) : null;
    return Array.isArray(parsed) ? parsed.filter((t): t is string => typeof t === 'string') : [];
  } catch {
    return [];
  }
};

const writeCollapsedTypes = (types: string[]) => {
  try {
    localStorage.setItem(COLLAPSED_STORAGE_KEY, JSON.stringify(types));
  } catch {
    // ignore storage failures (private browsing, quota)
  }
};

const isArchived = (program: Program): boolean => Boolean(program.archived_at);

const ProgramManagement: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const { user } = useAuth();
  const { currentClubId, activeContext, isClubAdmin } = useOrg();
  const clubId = currentClubId ?? activeContext?.scope_id ?? null;
  const [programs, setPrograms] = useState<Program[]>([]);
  // Tournaments live in their own module/table (not the programs table). We surface
  // them read-only under the Tournament tab; managing them links into /tournaments/:id.
  const [tournaments, setTournaments] = useState<Tournament[]>([]);
  const [showFormBuilder, setShowFormBuilder] = useState(false);
  const [selectedProgram, setSelectedProgram] = useState<Program | null>(null);
  const [embedProgram, setEmbedProgram] = useState<Program | null>(null);
  const [registrationsProgram, setRegistrationsProgram] = useState<Program | null>(null);
  const [loading, setLoading] = useState(true);
  const [showTryoutWizard, setShowTryoutWizard] = useState(false);
  const [editingTryout, setEditingTryout] = useState<Program | null>(null);
  const [manageTryoutProgram, setManageTryoutProgram] = useState<Program | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [showArchived, setShowArchived] = useState(false);
  const [busyProgramId, setBusyProgramId] = useState<number | null>(null);
  const [collapsedTypes, setCollapsedTypes] = useState<string[]>(() => readCollapsedTypes());
  const [activeTab, setActiveTab] = useState<ProgramTab>(() => {
    const stored = typeof window !== 'undefined' ? localStorage.getItem(TAB_STORAGE_KEY) : null;
    return (stored as ProgramTab) || 'all';
  });

  const handleTabChange = (tab: ProgramTab) => {
    setActiveTab(tab);
    try {
      localStorage.setItem(TAB_STORAGE_KEY, tab);
    } catch {
      // ignore storage failures (private browsing, quota)
    }
  };

  const toggleSection = (type: string) => {
    setCollapsedTypes((prev) => {
      const next = prev.includes(type) ? prev.filter((t) => t !== type) : [...prev, type];
      writeCollapsedTypes(next);
      return next;
    });
  };

  // Tournament statuses (registration_open, in_progress, …) don't share the program
  // status vocabulary, so only the shared values (draft/cancelled) or "All" surface them.
  const visibleTournaments = useMemo(() => {
    return statusFilter === 'all'
      ? tournaments
      : tournaments.filter((t) => t.status === statusFilter);
  }, [tournaments, statusFilter]);

  const tabCounts = useMemo(() => {
    const statusFiltered = statusFilter === 'all'
      ? programs
      : programs.filter((p) => p.status === statusFilter);
    return TABS.reduce<Record<ProgramTab, number>>((acc, { value }) => {
      const programCount = value === 'all'
        ? statusFiltered.length
        : statusFiltered.filter((p) => p.type === value).length;
      // Fold module tournaments into the All and Tournament tab counts.
      const tournamentCount = value === 'all' || value === 'tournament'
        ? visibleTournaments.length
        : 0;
      acc[value] = programCount + tournamentCount;
      return acc;
    }, {} as Record<ProgramTab, number>);
  }, [programs, statusFilter, visibleTournaments]);

  const filteredPrograms = useMemo(() => {
    return programs.filter((program) => {
      if (statusFilter !== 'all' && program.status !== statusFilter) return false;
      if (activeTab !== 'all' && program.type !== activeTab) return false;
      return true;
    });
  }, [programs, statusFilter, activeTab]);

  // Programs grouped into the collapsible type sections. Server order is
  // preserved inside each group — the backend already sorts by sort_order, so
  // re-sorting here would fight it.
  const programGroups = useMemo(() => {
    const buckets = new Map<string, Program[]>();
    filteredPrograms.forEach((program) => {
      const key = program.type || 'other';
      const bucket = buckets.get(key);
      if (bucket) bucket.push(program);
      else buckets.set(key, [program]);
    });

    const known = TYPE_SECTION_ORDER.filter((t) => buckets.has(t));
    const unknown = Array.from(buckets.keys()).filter((t) => !TYPE_SECTION_ORDER.includes(t as ProgramType));
    return [...known, ...unknown].map((type) => ({
      type,
      label: TABS.find((t) => t.value === type)?.label
        ?? type.charAt(0).toUpperCase() + type.slice(1).replace('_', ' '),
      items: buckets.get(type) || [],
    }));
  }, [filteredPrograms]);

  // Module tournaments render under the All and Tournament tabs only.
  const shownTournaments = useMemo(() => {
    return activeTab === 'all' || activeTab === 'tournament' ? visibleTournaments : [];
  }, [activeTab, visibleTournaments]);

  const fetchPrograms = useCallback(async () => {
    if (clubId == null) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const url = `${API_URL}/registration/programs-api.php?path=list&club_id=${clubId}`
        + (showArchived ? '&include_archived=1' : '');
      const response = await fetch(url, {
        headers: { 'Authorization': `Bearer ${localStorage.getItem('auth_token')}` }
      });
      if (!response.ok) {
        console.error('Failed to fetch programs:', response.status);
        return;
      }
      const data = await response.json();
      if (Array.isArray(data)) {
        setPrograms(data);
      } else {
        console.error('Unexpected programs response:', data);
      }
    } catch (error) {
      console.error('Error fetching programs:', error);
    } finally {
      setLoading(false);
    }
  }, [API_URL, clubId, showArchived]);

  const fetchTournaments = useCallback(async () => {
    if (clubId == null) return;
    try {
      const data = await listTournaments(clubId);
      setTournaments(Array.isArray(data?.tournaments) ? data.tournaments : []);
    } catch (error) {
      // Non-fatal: the programs list still renders if the tournament module is unavailable.
      console.error('Error fetching tournaments:', error);
      setTournaments([]);
    }
  }, [clubId]);

  useEffect(() => {
    fetchPrograms();
    fetchTournaments();
  }, [fetchPrograms, fetchTournaments]);

  const handleCreateProgram = () => {
    setSelectedProgram(null);
    setShowFormBuilder(true);
  };

  const handleEditProgram = (program: Program) => {
    if (program.type === 'tryout') {
      setEditingTryout(program);
    } else {
      setSelectedProgram(program);
      setShowFormBuilder(true);
    }
  };

  const handleDeleteProgram = async (id: number) => {
    if (!window.confirm('Are you sure you want to delete this program?')) return;

    try {
      const response = await fetch(`${API_URL}/registration/programs-api.php?id=${id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${localStorage.getItem('auth_token')}` }
      });
      if (response.ok) {
        fetchPrograms();
      }
    } catch (error) {
      console.error('Error deleting program:', error);
    }
  };

  // Archive is the alternative to Delete, not a softer version of it: the row,
  // its registrations and its history all stay, it just stops filling the list.
  const handleArchiveToggle = async (program: Program) => {
    if (!program.id) return;
    const archiving = !isArchived(program);
    setBusyProgramId(program.id);
    try {
      const response = await fetch(
        `${API_URL}/legacy/programs-gateway.php?action=${archiving ? 'archive' : 'unarchive'}`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
          },
          body: JSON.stringify({ id: program.id }),
        }
      );
      const data = await response.json().catch(() => null);
      if (!response.ok) {
        // 503 means the backend is deployed ahead of migration 084. Say so rather
        // than reporting a generic failure the admin cannot act on.
        window.alert(data?.error || 'Could not update this program.');
        return;
      }
      await fetchPrograms();
    } catch (error) {
      console.error('Error archiving program:', error);
      window.alert('Could not update this program.');
    } finally {
      setBusyProgramId(null);
    }
  };

  // Move up / move down. The whole section is sent in its new order, not a pair
  // of ids: sort_order is positional, so a partial update leaves gaps and ties
  // that the next move has to guess about.
  const handleMove = async (type: string, index: number, direction: -1 | 1) => {
    const group = programGroups.find((g) => g.type === type);
    if (!group) return;
    const target = index + direction;
    if (target < 0 || target >= group.items.length) return;

    const reordered = [...group.items];
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
    const orderedIds = reordered.map((p) => p.id).filter((id): id is number => typeof id === 'number');
    if (orderedIds.length !== reordered.length) return;

    // Optimistic: drop the reordered programs back into the slots this section
    // already occupied in `programs`, so the rows swap immediately.
    setPrograms((prev) => {
      const slots: number[] = [];
      prev.forEach((p, i) => {
        if (p.id != null && orderedIds.includes(p.id)) slots.push(i);
      });
      if (slots.length !== orderedIds.length) return prev;
      const byId = new Map(prev.map((p) => [p.id, p]));
      const next = [...prev];
      orderedIds.forEach((id, k) => {
        const found = byId.get(id);
        if (found) next[slots[k]] = found;
      });
      return next;
    });

    try {
      const response = await fetch(`${API_URL}/legacy/programs-gateway.php?action=reorder`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
        },
        body: JSON.stringify({ program_ids: orderedIds }),
      });
      if (!response.ok) {
        const data = await response.json().catch(() => null);
        window.alert(data?.error || 'Could not save the new order.');
        // Re-read rather than guessing: the optimistic swap is now a lie.
        await fetchPrograms();
      }
    } catch (error) {
      console.error('Error reordering programs:', error);
      await fetchPrograms();
    }
  };

  const getStatusColor = (status: ProgramStatus) => {
    switch (status) {
      case 'published': return 'text-green-600';
      case 'draft': return 'text-gray-600';
      case 'closed': return 'text-red-600';
      case 'cancelled': return 'text-orange-600';
      default: return 'text-gray-600';
    }
  };

  const getProgramTypeLabel = (type: ProgramType) => {
    if (!type) return 'Unknown';
    return type.charAt(0).toUpperCase() + type.slice(1).replace('_', ' ');
  };

  const archivedBadge = (
    <span
      className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-200 text-gray-700 uppercase"
      data-testid="archived-badge"
    >
      Archived
    </span>
  );

  const reorderControls = (type: string, index: number, count: number) => (
    <div className="flex flex-col leading-none">
      <button
        type="button"
        aria-label="Move up"
        disabled={index === 0}
        onClick={() => handleMove(type, index, -1)}
        className="px-1 text-brand-primary disabled:text-gray-300"
      >
        ▲
      </button>
      <button
        type="button"
        aria-label="Move down"
        disabled={index === count - 1}
        onClick={() => handleMove(type, index, 1)}
        className="px-1 text-brand-primary disabled:text-gray-300"
      >
        ▼
      </button>
    </div>
  );

  const hasAnything = filteredPrograms.length > 0 || shownTournaments.length > 0;

  return (
    <div className="min-h-screen bg-white">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-8">
        {/* Header */}
        <div className="mb-4 sm:mb-8">
          <h1 className="text-2xl sm:text-3xl font-bold text-brand-primary uppercase tracking-wide">
            Program Management
          </h1>
          <p className="text-gray-600 mt-1 sm:mt-2 text-sm sm:text-base">
            Create and manage registration programs
          </p>
        </div>

        {/* Action Bar */}
        <div className="mb-4 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
          <div className="flex gap-3 items-center">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary flex-1 sm:flex-none"
            >
              <option value="all">All Statuses</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
              <option value="closed">Closed</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <label className="flex items-center gap-2 text-sm text-gray-700 whitespace-nowrap">
              <input
                type="checkbox"
                checked={showArchived}
                onChange={(e) => setShowArchived(e.target.checked)}
                className="rounded border-brand-secondary text-brand-primary focus:ring-brand-primary"
              />
              Show archived
            </label>
          </div>
          <div className="flex gap-3">
            <button
              onClick={handleCreateProgram}
              className="bg-brand-primary text-white px-4 py-2 sm:px-6 sm:py-3 rounded-md hover:bg-brand-primary uppercase font-semibold text-sm sm:text-base flex-1 sm:flex-none"
            >
              + Program
            </button>
            <button
              onClick={() => setShowTryoutWizard(true)}
              className="bg-brand-primary text-white px-4 py-2 sm:px-6 sm:py-3 rounded-md hover:bg-brand-primary-hover uppercase font-semibold text-sm sm:text-base flex-1 sm:flex-none"
            >
              + Tryout
            </button>
            <Link
              to="/tournaments/create"
              className="bg-brand-primary text-white px-4 py-2 sm:px-6 sm:py-3 rounded-md hover:bg-brand-primary-hover uppercase font-semibold text-sm sm:text-base flex-1 sm:flex-none text-center"
            >
              + Tournament
            </Link>
          </div>
        </div>

        {/* Type Tabs */}
        <div className="border-b border-brand-secondary mb-6 flex flex-wrap gap-1 overflow-x-auto" role="tablist" aria-label="Program type">
          {TABS.map(({ value, label }) => {
            const isActive = activeTab === value;
            return (
              <button
                key={value}
                role="tab"
                aria-selected={isActive}
                onClick={() => handleTabChange(value)}
                className={`px-4 py-2 -mb-px border-b-2 text-sm font-semibold uppercase tracking-wide transition-colors whitespace-nowrap ${
                  isActive
                    ? 'border-brand-primary text-brand-primary'
                    : 'border-transparent text-gray-500 hover:text-brand-primary hover:border-brand-secondary'
                }`}
              >
                {label}
                <span className={`ml-2 px-2 py-0.5 text-xs rounded-full ${
                  isActive ? 'bg-brand-primary text-white' : 'bg-gray-200 text-gray-700'
                }`}>
                  {tabCounts[value]}
                </span>
              </button>
            );
          })}
        </div>

        {/* Programs List */}
        {loading ? (
          <div className="text-center py-12 text-brand-primary">Loading programs...</div>
        ) : !hasAnything ? (
          <div className="border border-brand-secondary rounded-md p-12 text-center bg-white">
            <p className="text-gray-600 text-lg">No programs yet.</p>
            <p className="text-gray-500 mt-2">Create your first program to start accepting registrations.</p>
          </div>
        ) : (
          <div className="space-y-6">
            {programGroups.map((group) => {
              const isCollapsed = collapsedTypes.includes(group.type);
              return (
                <section key={group.type}>
                  <button
                    type="button"
                    onClick={() => toggleSection(group.type)}
                    aria-expanded={!isCollapsed}
                    aria-controls={`program-section-${group.type}`}
                    data-testid={`section-toggle-${group.type}`}
                    className="w-full flex items-center gap-2 py-2 text-left text-brand-primary"
                  >
                    <span className="text-xs">{isCollapsed ? '▶' : '▼'}</span>
                    <span className="font-bold uppercase tracking-wide text-sm">{group.label}</span>
                    <span className="px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-700">
                      {group.items.length}
                    </span>
                  </button>

                  {!isCollapsed && (
                    <div id={`program-section-${group.type}`}>
                      {/* Desktop Table View */}
                      <div className="hidden md:block border border-brand-secondary rounded-md">
                        <table className="min-w-full bg-white">
                          <thead>
                            <tr className="border-b border-brand-secondary">
                              {isClubAdmin && (
                                <th className="px-2 py-3 text-left text-xs font-bold text-brand-primary uppercase w-10">
                                  <span className="sr-only">Order</span>
                                </th>
                              )}
                              <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                                Program Name
                              </th>
                              <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                                Type
                              </th>
                              <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                                Dates
                              </th>
                              <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                                Registrations
                              </th>
                              <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                                Status
                              </th>
                              <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                                Actions
                              </th>
                            </tr>
                          </thead>
                          <tbody>
                            {group.items.map((program, index) => (
                              <tr
                                key={program.id}
                                className={`border-b border-gray-300 hover:bg-gray-50 ${
                                  isArchived(program) ? 'opacity-60 bg-gray-50' : ''
                                }`}
                              >
                                {isClubAdmin && (
                                  <td className="px-2 py-4 align-middle">
                                    {reorderControls(group.type, index, group.items.length)}
                                  </td>
                                )}
                                <td className="px-6 py-4">
                                  <div className="text-brand-primary font-medium">
                                    {program.name}
                                    {isArchived(program) && archivedBadge}
                                  </div>
                                  {program.description && (
                                    <div className="text-gray-500 text-sm mt-1 truncate max-w-xs">
                                      {program.description}
                                    </div>
                                  )}
                                </td>
                                <td className="px-6 py-4">
                                  <span className="text-brand-primary">
                                    {getProgramTypeLabel(program.type)}
                                  </span>
                                </td>
                                <td className="px-6 py-4">
                                  <div className="text-sm text-brand-primary">
                                    {program.start_date ? (
                                      <>
                                        {formatDateOnly(program.start_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}
                                        {program.end_date && (
                                          <> - {formatDateOnly(program.end_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}</>
                                        )}
                                      </>
                                    ) : (
                                      <span className="text-gray-400">No dates set</span>
                                    )}
                                  </div>
                                </td>
                                <td className="px-6 py-4">
                                  <button
                                    onClick={() => setRegistrationsProgram(program)}
                                    className="text-brand-primary hover:text-brand-primary-dark underline"
                                  >
                                    {program.registration_count || 0} registrations
                                  </button>
                                  {(program.pending_count ?? 0) > 0 && (
                                    <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                      {program.pending_count} pending
                                    </span>
                                  )}
                                  {program.capacity && (
                                    <span className="text-gray-500 ml-1">/ {program.capacity}</span>
                                  )}
                                </td>
                                <td className="px-6 py-4">
                                  <span className={`uppercase text-xs font-semibold ${getStatusColor(program.status)}`}>
                                    {program.status}
                                  </span>
                                </td>
                                <td className="px-6 py-4">
                                  <div className="flex space-x-3">
                                    <button
                                      onClick={() => handleEditProgram(program)}
                                      className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold"
                                    >
                                      Edit
                                    </button>
                                    {program.type === 'tryout' && (
                                      <button
                                        onClick={() => setManageTryoutProgram(program)}
                                        className="text-green-600 hover:text-green-500 uppercase text-xs font-semibold"
                                      >
                                        Manage
                                      </button>
                                    )}
                                    {program.status === 'published' && (
                                      <>
                                        <button
                                          onClick={() => setEmbedProgram(program)}
                                          className="text-brand-primary hover:text-brand-primary-dark uppercase text-xs font-semibold"
                                        >
                                          Embed
                                        </button>
                                        <button
                                          onClick={() => {
                                            navigator.clipboard.writeText(
                                              `${window.location.origin}/register/${program.embed_code}`
                                            );
                                            alert('Registration link copied to clipboard!');
                                          }}
                                          className="text-brand-primary hover:text-brand-primary-dark uppercase text-xs font-semibold"
                                        >
                                          Link
                                        </button>
                                      </>
                                    )}
                                    {isClubAdmin && (
                                      <button
                                        onClick={() => handleArchiveToggle(program)}
                                        disabled={busyProgramId === program.id}
                                        className="text-gray-600 hover:text-gray-500 uppercase text-xs font-semibold disabled:opacity-50"
                                      >
                                        {isArchived(program) ? 'Unarchive' : 'Archive'}
                                      </button>
                                    )}
                                    <button
                                      onClick={() => program.id && handleDeleteProgram(program.id)}
                                      className="text-red-600 hover:text-red-500 uppercase text-xs font-semibold"
                                    >
                                      Delete
                                    </button>
                                  </div>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>

                      {/* Mobile Card View */}
                      <div className="md:hidden space-y-4">
                        {group.items.map((program, index) => (
                          <div
                            key={program.id}
                            className={`border border-brand-secondary rounded-md bg-white p-4 ${
                              isArchived(program) ? 'opacity-60' : ''
                            }`}
                          >
                            {/* Program Header */}
                            <div className="mb-3 flex items-start justify-between gap-2">
                              <div>
                                <h3 className="text-lg font-bold text-brand-primary">
                                  {program.name}
                                  {isArchived(program) && archivedBadge}
                                </h3>
                                {program.description && (
                                  <p className="text-gray-600 text-sm mt-1">{program.description}</p>
                                )}
                              </div>
                              {isClubAdmin && reorderControls(group.type, index, group.items.length)}
                            </div>

                            {/* Program Details */}
                            <div className="space-y-2 mb-4">
                              <div className="flex justify-between">
                                <span className="text-gray-600 text-sm font-semibold uppercase">Type:</span>
                                <span className="text-brand-primary text-sm">{getProgramTypeLabel(program.type)}</span>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-gray-600 text-sm font-semibold uppercase">Dates:</span>
                                <span className="text-brand-primary text-sm">
                                  {program.start_date ? (
                                    <>
                                      {formatDateOnly(program.start_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}
                                      {program.end_date && (
                                        <> - {formatDateOnly(program.end_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}</>
                                      )}
                                    </>
                                  ) : (
                                    <span className="text-gray-400">No dates set</span>
                                  )}
                                </span>
                              </div>
                              <div className="flex justify-between items-center">
                                <span className="text-gray-600 text-sm font-semibold uppercase">Registrations:</span>
                                <div className="flex items-center">
                                  <button
                                    onClick={() => setRegistrationsProgram(program)}
                                    className="text-brand-primary text-sm hover:text-brand-primary-dark underline"
                                  >
                                    {program.registration_count || 0} registrations
                                  </button>
                                  {(program.pending_count ?? 0) > 0 && (
                                    <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                      {program.pending_count} pending
                                    </span>
                                  )}
                                  {program.capacity && (
                                    <span className="text-gray-500 ml-1">/ {program.capacity}</span>
                                  )}
                                </div>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-gray-600 text-sm font-semibold uppercase">Status:</span>
                                <span className={`uppercase text-xs font-semibold ${getStatusColor(program.status)}`}>
                                  {program.status}
                                </span>
                              </div>
                            </div>

                            {/* Action Buttons */}
                            <div className="grid grid-cols-2 gap-2">
                              <button
                                onClick={() => handleEditProgram(program)}
                                className="border border-brand-secondary rounded-md text-brand-primary hover:bg-brand-secondary py-2 uppercase text-xs font-semibold"
                              >
                                Edit
                              </button>
                              {program.type === 'tryout' && (
                                <button
                                  onClick={() => setManageTryoutProgram(program)}
                                  className="border border-green-200 rounded-md text-green-600 hover:bg-green-50 py-2 uppercase text-xs font-semibold"
                                >
                                  Manage
                                </button>
                              )}
                              {program.status === 'published' && (
                                <>
                                  <button
                                    onClick={() => setEmbedProgram(program)}
                                    className="border border-brand-primary/30 rounded-md text-brand-primary hover:bg-brand-light py-2 uppercase text-xs font-semibold"
                                  >
                                    Embed
                                  </button>
                                  <button
                                    onClick={() => {
                                      navigator.clipboard.writeText(
                                        `${window.location.origin}/register/${program.embed_code}`
                                      );
                                      alert('Registration link copied to clipboard!');
                                    }}
                                    className="border border-brand-primary/30 rounded-md text-brand-primary hover:bg-brand-light py-2 uppercase text-xs font-semibold"
                                  >
                                    Link
                                  </button>
                                </>
                              )}
                              {isClubAdmin && (
                                <button
                                  onClick={() => handleArchiveToggle(program)}
                                  disabled={busyProgramId === program.id}
                                  className="border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50 py-2 uppercase text-xs font-semibold disabled:opacity-50"
                                >
                                  {isArchived(program) ? 'Unarchive' : 'Archive'}
                                </button>
                              )}
                              <button
                                onClick={() => program.id && handleDeleteProgram(program.id)}
                                className="border border-red-200 rounded-md text-red-600 hover:bg-red-50 py-2 uppercase text-xs font-semibold"
                              >
                                Delete
                              </button>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </section>
              );
            })}

            {/* Tournament module — a separate table, not part of the programs
                ordering: those rows live in their own module and have no
                sort_order to write. */}
            {shownTournaments.length > 0 && (
              <section>
                <div className="py-2 flex items-center gap-2 text-brand-primary">
                  <span className="font-bold uppercase tracking-wide text-sm">Tournament module</span>
                  <span className="px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-700">
                    {shownTournaments.length}
                  </span>
                </div>
                <div className="hidden md:block border border-brand-secondary rounded-md">
                  <table className="min-w-full bg-white">
                    <thead>
                      <tr className="border-b border-brand-secondary">
                        <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">Name</th>
                        <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">Type</th>
                        <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">Dates</th>
                        <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">Registrations</th>
                        <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">Status</th>
                        <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {shownTournaments.map((t) => (
                        <tr key={`tournament-${t.id}`} className="border-b border-gray-300 hover:bg-gray-50">
                          <td className="px-6 py-4">
                            <div className="text-brand-primary font-medium">{t.name}</div>
                            <div className="text-gray-500 text-xs mt-1">Tournament module</div>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-brand-primary">Tournament</span>
                          </td>
                          <td className="px-6 py-4">
                            <div className="text-sm text-brand-primary">
                              {t.start_date ? (
                                <>
                                  {formatDateOnly(t.start_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}
                                  {t.end_date && (
                                    <> - {formatDateOnly(t.end_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}</>
                                  )}
                                </>
                              ) : (
                                <span className="text-gray-400">No dates set</span>
                              )}
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-brand-primary">
                              {t.registration_count || 0} registrations
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <span className={`uppercase text-xs font-semibold px-2 py-0.5 rounded-full ${TOURNAMENT_STATUS_CONFIG[t.status]?.color || 'bg-gray-100 text-gray-700'}`}>
                              {TOURNAMENT_STATUS_CONFIG[t.status]?.label || t.status}
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <Link
                              to={`/tournaments/${t.id}`}
                              className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold"
                            >
                              Manage
                            </Link>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="md:hidden space-y-4">
                  {shownTournaments.map((t) => (
                    <div key={`tournament-${t.id}`} className="border border-brand-secondary rounded-md bg-white p-4">
                      <div className="mb-3">
                        <h3 className="text-lg font-bold text-brand-primary">{t.name}</h3>
                        <p className="text-gray-500 text-xs mt-1">Tournament module</p>
                      </div>
                      <div className="space-y-2 mb-4">
                        <div className="flex justify-between">
                          <span className="text-gray-600 text-sm font-semibold uppercase">Type:</span>
                          <span className="text-brand-primary text-sm">Tournament</span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-gray-600 text-sm font-semibold uppercase">Dates:</span>
                          <span className="text-brand-primary text-sm">
                            {t.start_date ? (
                              <>
                                {formatDateOnly(t.start_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}
                                {t.end_date && (
                                  <> - {formatDateOnly(t.end_date, { year: 'numeric', month: 'numeric', day: 'numeric' })}</>
                                )}
                              </>
                            ) : (
                              <span className="text-gray-400">No dates set</span>
                            )}
                          </span>
                        </div>
                        <div className="flex justify-between items-center">
                          <span className="text-gray-600 text-sm font-semibold uppercase">Registrations:</span>
                          <span className="text-brand-primary text-sm">{t.registration_count || 0}</span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-gray-600 text-sm font-semibold uppercase">Status:</span>
                          <span className={`uppercase text-xs font-semibold px-2 py-0.5 rounded-full ${TOURNAMENT_STATUS_CONFIG[t.status]?.color || 'bg-gray-100 text-gray-700'}`}>
                            {TOURNAMENT_STATUS_CONFIG[t.status]?.label || t.status}
                          </span>
                        </div>
                      </div>
                      <Link
                        to={`/tournaments/${t.id}`}
                        className="block w-full text-center border border-brand-primary/30 rounded-md text-brand-primary hover:bg-brand-light py-2 uppercase text-xs font-semibold"
                      >
                        Manage
                      </Link>
                    </div>
                  ))}
                </div>
              </section>
            )}
          </div>
        )}

        {/* Form Builder Modal */}
        {showFormBuilder && (
          <ProgramFormBuilder
            program={selectedProgram}
            onClose={() => {
              setShowFormBuilder(false);
              fetchPrograms();
            }}
          />
        )}

        {/* Embed Code Modal */}
        {embedProgram && (
          <EmbedCodeModal
            program={embedProgram}
            onClose={() => setEmbedProgram(null)}
          />
        )}

        {/* Registrations Modal */}
        {registrationsProgram && (
          <RegistrationsModal
            program={registrationsProgram}
            onClose={() => {
              setRegistrationsProgram(null);
              fetchPrograms(); // Refresh counts after approval/rejection
            }}
          />
        )}

        {/* Tryout Creation Wizard */}
        {showTryoutWizard && (
          <TryoutCreationWizard
            clubId={clubId ?? 0}
            onComplete={(tryoutId) => {
              setShowTryoutWizard(false);
              fetchPrograms();
            }}
            onCancel={() => setShowTryoutWizard(false)}
          />
        )}

        {/* Tryout Edit Wizard */}
        {editingTryout && (
          <TryoutCreationWizard
            clubId={clubId ?? 0}
            existingProgram={editingTryout}
            onComplete={() => {
              setEditingTryout(null);
              fetchPrograms();
            }}
            onCancel={() => setEditingTryout(null)}
          />
        )}

        {/* Tryout Management */}
        {manageTryoutProgram && manageTryoutProgram.id && (
          <TryoutManagement
            programId={manageTryoutProgram.id}
            programName={manageTryoutProgram.name}
            currentUserId={user?.id || 0}
            onClose={() => {
              setManageTryoutProgram(null);
              fetchPrograms();
            }}
          />
        )}
      </div>
    </div>
  );
};

export default ProgramManagement;

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useOrg } from '../../contexts/OrgContext';

interface Props {
  onClose: () => void;
  onCreate: (participantIds: number[]) => void;
}

interface Person {
  user_id: number;
  display_name: string;
  email?: string;
  role?: string;
  team_names?: string;
}

interface TeamGroup {
  id: number;
  name: string;
  age_group: string | null;
}

interface SelectedTeam {
  id: number;
  name: string;
  age_group: string | null;
}

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

export default function NewConversationDialog({ onClose, onCreate }: Props) {
  const { currentClubId } = useOrg();

  const [query, setQuery] = useState('');
  const [people, setPeople] = useState<Person[]>([]);
  const [teamGroups, setTeamGroups] = useState<TeamGroup[]>([]);
  const [searching, setSearching] = useState(false);
  const [showBrowse, setShowBrowse] = useState(false);

  const [selectedPeople, setSelectedPeople] = useState<Person[]>([]);
  const [selectedTeams, setSelectedTeams] = useState<SelectedTeam[]>([]);

  const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const tokenRef = useRef<string | null>(localStorage.getItem('auth_token'));

  const runSearch = useCallback(
    async (q: string) => {
      if (!currentClubId) return;
      setSearching(true);
      try {
        const params = new URLSearchParams({
          action: 'chat-search',
          club_profile_id: String(currentClubId),
          q,
        });
        const res = await fetch(`${API_URL}/api/recipient-search?${params}`, {
          headers: { Authorization: `Bearer ${tokenRef.current ?? ''}` },
        });
        const data = await res.json();
        if (data.success) {
          setPeople(data.people || []);
          setTeamGroups(data.team_groups || []);
        } else {
          setPeople([]);
          setTeamGroups([]);
        }
      } catch (err) {
        console.error('Chat search failed:', err);
        setPeople([]);
        setTeamGroups([]);
      } finally {
        setSearching(false);
      }
    },
    [currentClubId]
  );

  useEffect(() => {
    if (debounceTimer.current) clearTimeout(debounceTimer.current);
    debounceTimer.current = setTimeout(() => runSearch(query), 250);
    return () => {
      if (debounceTimer.current) clearTimeout(debounceTimer.current);
    };
  }, [query, runSearch]);

  const togglePerson = (p: Person) => {
    setSelectedPeople((prev) =>
      prev.some((x) => x.user_id === p.user_id)
        ? prev.filter((x) => x.user_id !== p.user_id)
        : [...prev, p]
    );
  };

  const toggleTeam = (t: TeamGroup) => {
    setSelectedTeams((prev) =>
      prev.some((x) => x.id === t.id)
        ? prev.filter((x) => x.id !== t.id)
        : [...prev, { id: t.id, name: t.name, age_group: t.age_group }]
    );
  };

  const removePersonChip = (userId: number) =>
    setSelectedPeople((prev) => prev.filter((p) => p.user_id !== userId));

  const removeTeamChip = (id: number) =>
    setSelectedTeams((prev) => prev.filter((t) => t.id !== id));

  const handleStartChat = async () => {
    if (!currentClubId) return;
    const ids = new Set<number>(selectedPeople.map((p) => p.user_id));

    if (selectedTeams.length > 0) {
      try {
        const res = await fetch(`${API_URL}/api/recipient-search?action=chat-resolve-teams`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${tokenRef.current ?? ''}`,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            club_profile_id: currentClubId,
            team_ids: selectedTeams.map((t) => t.id),
          }),
        });
        const data = await res.json();
        if (data.success && Array.isArray(data.user_ids)) {
          for (const uid of data.user_ids) ids.add(uid);
        }
      } catch (err) {
        console.error('Failed to resolve team participants:', err);
      }
    }

    const finalIds = Array.from(ids);
    if (finalIds.length > 0) onCreate(finalIds);
  };

  // Group teams by age division for the browse view
  const teamsByAge = teamGroups.reduce<Record<string, TeamGroup[]>>((acc, t) => {
    const key = t.age_group || 'Other';
    (acc[key] = acc[key] || []).push(t);
    return acc;
  }, {});
  const ageKeys = Object.keys(teamsByAge).sort();

  const totalSelected = selectedPeople.length + selectedTeams.length;
  const isPersonSelected = (uid: number) => selectedPeople.some((p) => p.user_id === uid);
  const isTeamSelected = (id: number) => selectedTeams.some((t) => t.id === id);

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="bg-brand-primary text-white px-4 py-3 flex items-center gap-2 flex-shrink-0">
        <button onClick={onClose} className="p-1 hover:bg-white/20 rounded transition-colors" aria-label="Close">
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <span className="font-medium">New Message</span>
      </div>

      {/* Selected chips */}
      {totalSelected > 0 && (
        <div className="px-4 py-2 border-b border-gray-100 flex flex-wrap gap-2">
          {selectedPeople.map((p) => (
            <span key={`p-${p.user_id}`} className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-brand-primary/10 text-brand-primary text-xs">
              {p.display_name}
              <button onClick={() => removePersonChip(p.user_id)} aria-label={`Remove ${p.display_name}`} className="ml-1 hover:text-brand-primary/70">×</button>
            </span>
          ))}
          {selectedTeams.map((t) => (
            <span key={`t-${t.id}`} className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-brand-accent/10 text-brand-accent text-xs">
              {t.age_group ? `${t.age_group} · ${t.name}` : t.name}
              <button onClick={() => removeTeamChip(t.id)} aria-label={`Remove ${t.name}`} className="ml-1 hover:text-brand-accent/70">×</button>
            </span>
          ))}
        </div>
      )}

      {/* Search + browse toggle */}
      <div className="px-4 py-2 border-b border-gray-100 flex gap-2 items-center flex-shrink-0">
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search people or teams…"
          className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-accent focus:border-transparent"
        />
        {teamGroups.length > 0 && (
          <button
            onClick={() => setShowBrowse((v) => !v)}
            className={`px-3 py-2 text-xs font-medium rounded-lg border transition-colors ${
              showBrowse ? 'bg-brand-primary text-white border-brand-primary' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
            }`}
          >
            Browse Teams
          </button>
        )}
      </div>

      <div className="flex-1 overflow-y-auto">
        {showBrowse ? (
          <div>
            {ageKeys.length === 0 && (
              <div className="text-center text-gray-400 text-sm py-8">No teams available.</div>
            )}
            {ageKeys.map((age) => (
              <div key={age}>
                <div className="px-4 py-2 bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 sticky top-0">
                  {age}
                </div>
                {teamsByAge[age].map((t) => (
                  <button
                    key={t.id}
                    onClick={() => toggleTeam(t)}
                    className="w-full px-4 py-3 flex items-center gap-3 hover:bg-gray-50 transition-colors text-left"
                  >
                    <div className={`w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors ${
                      isTeamSelected(t.id) ? 'bg-brand-primary border-brand-primary' : 'border-gray-300'
                    }`}>
                      {isTeamSelected(t.id) && (
                        <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                        </svg>
                      )}
                    </div>
                    <span className="text-sm font-medium text-gray-800">{t.name}</span>
                  </button>
                ))}
              </div>
            ))}
          </div>
        ) : (
          <div>
            {searching && (
              <div className="flex items-center justify-center py-6">
                <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-brand-primary" />
              </div>
            )}
            {!searching && people.length === 0 && teamGroups.length === 0 && (
              <div className="text-center text-gray-400 text-sm py-8">
                {query.trim() ? 'No matches.' : 'Start typing to search people, or browse teams.'}
              </div>
            )}

            {teamGroups.length > 0 && (
              <div>
                <div className="px-4 py-2 bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500">Teams</div>
                {teamGroups.slice(0, 10).map((t) => (
                  <button
                    key={`tg-${t.id}`}
                    onClick={() => toggleTeam(t)}
                    className="w-full px-4 py-3 flex items-center gap-3 hover:bg-gray-50 transition-colors text-left"
                  >
                    <div className={`w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors ${
                      isTeamSelected(t.id) ? 'bg-brand-primary border-brand-primary' : 'border-gray-300'
                    }`}>
                      {isTeamSelected(t.id) && (
                        <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                        </svg>
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <span className="text-sm font-medium text-gray-800 truncate block">{t.name}</span>
                      {t.age_group && <span className="text-xs text-gray-400">{t.age_group}</span>}
                    </div>
                  </button>
                ))}
              </div>
            )}

            {people.length > 0 && (
              <div>
                <div className="px-4 py-2 bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500">People</div>
                {people.map((p) => (
                  <button
                    key={p.user_id}
                    onClick={() => togglePerson(p)}
                    className="w-full px-4 py-3 flex items-center gap-3 hover:bg-gray-50 transition-colors text-left"
                  >
                    <div className={`w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors ${
                      isPersonSelected(p.user_id) ? 'bg-brand-primary border-brand-primary' : 'border-gray-300'
                    }`}>
                      {isPersonSelected(p.user_id) && (
                        <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                        </svg>
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <span className="text-sm font-medium text-gray-800 truncate block">{p.display_name}</span>
                      <span className="text-xs text-gray-400 capitalize">
                        {p.role}
                        {p.team_names ? ` · ${p.team_names}` : ''}
                      </span>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Footer */}
      {totalSelected > 0 && (
        <div className="px-4 py-3 border-t border-gray-100 flex-shrink-0">
          <button
            onClick={handleStartChat}
            className="w-full py-2.5 bg-brand-primary text-white text-sm font-medium rounded-lg hover:bg-brand-primary/90 transition-colors"
          >
            Start Chat ({totalSelected} {totalSelected === 1 ? 'selection' : 'selections'})
          </button>
        </div>
      )}
    </div>
  );
}

import React, { useState, useEffect, useRef, useCallback } from 'react';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Recipient {
  id: number;
  type: 'athlete' | 'guardian' | 'coach';
  first_name: string;
  last_name: string;
  email?: string;
  phone?: string;
  team_name?: string;
  athlete_name?: string;
  suppressed: boolean;
  suppression_reason?: string;
}

interface TeamGroup {
  id: number;
  name: string;
  age_group?: string;
  athlete_count: number;
  guardian_count: number;
}

interface GroupChip {
  group: TeamGroup;
  recipients: Recipient[];
}

interface RecipientSelectorProps {
  clubProfileId: number;
  channel: 'email' | 'sms';
  selectedRecipients: Recipient[];
  onRecipientsChange: (recipients: Recipient[]) => void;
  onGroupSelect?: (group: TeamGroup) => void;
}

export const RecipientSelector: React.FC<RecipientSelectorProps> = ({
  clubProfileId,
  channel,
  selectedRecipients,
  onRecipientsChange,
  onGroupSelect,
}) => {
  const [query, setQuery] = useState('');
  const [searchResults, setSearchResults] = useState<Recipient[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [showDropdown, setShowDropdown] = useState(false);
  const [highlightedIndex, setHighlightedIndex] = useState(-1);
  const [showGroupDropdown, setShowGroupDropdown] = useState(false);
  const [teamGroups, setTeamGroups] = useState<TeamGroup[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(false);
  const [groupChips, setGroupChips] = useState<GroupChip[]>([]);

  const inputRef = useRef<HTMLInputElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const token = localStorage.getItem('auth_token');
  const headers = {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  };

  // Close dropdowns on outside click
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setShowDropdown(false);
        setShowGroupDropdown(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Debounced search
  const performSearch = useCallback(
    (searchQuery: string) => {
      if (debounceTimer.current) clearTimeout(debounceTimer.current);

      if (searchQuery.trim().length < 2) {
        setSearchResults([]);
        setShowDropdown(false);
        return;
      }

      debounceTimer.current = setTimeout(async () => {
        setIsSearching(true);
        try {
          const params = new URLSearchParams({
            action: 'search',
            q: searchQuery.trim(),
            club_profile_id: String(clubProfileId),
            channel,
          });
          const res = await fetch(`${API_URL}/api/recipient-search?${params}`, { headers });
          if (!res.ok) throw new Error('Search failed');
          const data = await res.json();
          setSearchResults(data.recipients || []);
          setShowDropdown(true);
          setHighlightedIndex(-1);
        } catch (err) {
          console.error('Recipient search error:', err);
          setSearchResults([]);
        } finally {
          setIsSearching(false);
        }
      }, 300);
    },
    [clubProfileId, channel, headers]
  );

  useEffect(() => {
    performSearch(query);
    return () => {
      if (debounceTimer.current) clearTimeout(debounceTimer.current);
    };
  }, [query, performSearch]);

  // Fetch team groups
  const fetchGroups = async () => {
    setLoadingGroups(true);
    try {
      const params = new URLSearchParams({
        action: 'groups',
        club_profile_id: String(clubProfileId),
      });
      const res = await fetch(`${API_URL}/api/recipient-search?${params}`, { headers });
      if (!res.ok) throw new Error('Failed to fetch groups');
      const data = await res.json();
      setTeamGroups(data.groups || []);
    } catch (err) {
      console.error('Failed to fetch groups:', err);
      setTeamGroups([]);
    } finally {
      setLoadingGroups(false);
    }
  };

  const handleGroupDropdownToggle = () => {
    if (!showGroupDropdown) {
      fetchGroups();
    }
    setShowGroupDropdown(!showGroupDropdown);
    setShowDropdown(false);
  };

  // Resolve group to individual recipients
  const handleGroupSelect = async (group: TeamGroup) => {
    try {
      const res = await fetch(`${API_URL}/api/recipient-search?action=resolve-group`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          team_id: group.id,
          club_profile_id: clubProfileId,
          channel,
        }),
      });
      if (!res.ok) throw new Error('Failed to resolve group');
      const data = await res.json();
      const resolved: Recipient[] = data.recipients || [];

      // Filter out already-selected and suppressed recipients
      const newRecipients = resolved.filter(
        (r) =>
          !r.suppressed &&
          !selectedRecipients.some((s) => s.id === r.id && s.type === r.type)
      );

      onRecipientsChange([...selectedRecipients, ...newRecipients]);
      setGroupChips((prev) => [...prev, { group, recipients: newRecipients }]);

      if (onGroupSelect) onGroupSelect(group);
    } catch (err) {
      console.error('Failed to resolve group:', err);
    }
    setShowGroupDropdown(false);
  };

  const isRecipientSelected = (r: Recipient): boolean => {
    return selectedRecipients.some((s) => s.id === r.id && s.type === r.type);
  };

  const addRecipient = (recipient: Recipient) => {
    if (recipient.suppressed || isRecipientSelected(recipient)) return;
    onRecipientsChange([...selectedRecipients, recipient]);
    setQuery('');
    setShowDropdown(false);
    inputRef.current?.focus();
  };

  const removeRecipient = (recipient: Recipient) => {
    onRecipientsChange(
      selectedRecipients.filter((r) => !(r.id === recipient.id && r.type === recipient.type))
    );
    // Also remove from group chips if present
    setGroupChips((prev) =>
      prev
        .map((gc) => ({
          ...gc,
          recipients: gc.recipients.filter(
            (r) => !(r.id === recipient.id && r.type === recipient.type)
          ),
        }))
        .filter((gc) => gc.recipients.length > 0)
    );
  };

  const removeGroupChip = (group: TeamGroup) => {
    const chip = groupChips.find((gc) => gc.group.id === group.id);
    if (chip) {
      const idsToRemove = new Set(chip.recipients.map((r) => `${r.id}-${r.type}`));
      onRecipientsChange(
        selectedRecipients.filter((r) => !idsToRemove.has(`${r.id}-${r.type}`))
      );
      setGroupChips((prev) => prev.filter((gc) => gc.group.id !== group.id));
    }
  };

  // Flatten search results into navigable list
  const flatResults = searchResults.filter((r) => !isRecipientSelected(r));

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (!showDropdown || flatResults.length === 0) {
      if (e.key === 'Backspace' && query === '' && selectedRecipients.length > 0) {
        removeRecipient(selectedRecipients[selectedRecipients.length - 1]);
      }
      return;
    }

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        setHighlightedIndex((prev) => Math.min(prev + 1, flatResults.length - 1));
        break;
      case 'ArrowUp':
        e.preventDefault();
        setHighlightedIndex((prev) => Math.max(prev - 1, 0));
        break;
      case 'Enter':
        e.preventDefault();
        if (highlightedIndex >= 0 && highlightedIndex < flatResults.length) {
          addRecipient(flatResults[highlightedIndex]);
        }
        break;
      case 'Escape':
        setShowDropdown(false);
        setHighlightedIndex(-1);
        break;
      case 'Backspace':
        if (query === '' && selectedRecipients.length > 0) {
          removeRecipient(selectedRecipients[selectedRecipients.length - 1]);
        }
        break;
    }
  };

  // Scroll highlighted item into view
  useEffect(() => {
    if (highlightedIndex >= 0 && dropdownRef.current) {
      const items = dropdownRef.current.querySelectorAll('[data-result-index]');
      if (items[highlightedIndex]) {
        items[highlightedIndex].scrollIntoView({ block: 'nearest' });
      }
    }
  }, [highlightedIndex]);

  const getTypeBadge = (type: string) => {
    const styles: Record<string, string> = {
      athlete: 'bg-blue-100 text-blue-700',
      guardian: 'bg-purple-100 text-purple-700',
      coach: 'bg-green-100 text-green-700',
    };
    const labels: Record<string, string> = {
      athlete: 'Athlete',
      guardian: 'Parent',
      coach: 'Coach',
    };
    return (
      <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium ${styles[type] || 'bg-gray-100 text-gray-600'}`}>
        {labels[type] || type}
      </span>
    );
  };

  // Group search results by type for display
  const groupedResults: Record<string, Recipient[]> = {};
  flatResults.forEach((r) => {
    const key = r.type;
    if (!groupedResults[key]) groupedResults[key] = [];
    groupedResults[key].push(r);
  });
  const groupOrder = ['athlete', 'guardian', 'coach'];
  const groupLabels: Record<string, string> = {
    athlete: 'Athletes',
    guardian: 'Parents / Guardians',
    coach: 'Coaches',
  };

  // Build a flat index mapping for keyboard navigation
  let flatIndex = 0;
  const indexMap = new Map<string, number>();
  groupOrder.forEach((type) => {
    (groupedResults[type] || []).forEach((r) => {
      indexMap.set(`${r.id}-${r.type}`, flatIndex);
      flatIndex++;
    });
  });

  const suppressedCount = selectedRecipients.filter((r) => r.suppressed).length;

  return (
    <div ref={containerRef} className="relative w-full">
      {/* Label row */}
      <div className="flex items-center justify-between mb-1">
        <label className="block text-sm font-medium text-gray-700">To</label>
        <button
          type="button"
          onClick={handleGroupDropdownToggle}
          className="text-sm font-medium text-brand-primary hover:underline focus:outline-none"
        >
          + Add Group
        </button>
      </div>

      {/* Chip container + input */}
      <div
        className="flex flex-wrap items-center gap-1.5 min-h-[44px] px-3 py-2 border border-gray-300 rounded-lg bg-white focus-within:ring-2 focus-within:ring-brand-primary/30 focus-within:border-brand-primary transition-colors cursor-text"
        onClick={() => inputRef.current?.focus()}
      >
        {/* Group chips */}
        {groupChips.map((gc) => (
          <span
            key={`group-${gc.group.id}`}
            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-brand-primary/10 text-brand-primary border border-brand-primary/20"
          >
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            {gc.group.name} ({gc.recipients.length})
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                removeGroupChip(gc.group);
              }}
              className="ml-0.5 hover:text-red-600 transition-colors"
              aria-label={`Remove group ${gc.group.name}`}
            >
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </span>
        ))}

        {/* Individual recipient chips */}
        {selectedRecipients.map((r) => (
          <span
            key={`${r.id}-${r.type}`}
            className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium transition-colors ${
              r.suppressed
                ? 'bg-red-50 text-red-500 border border-red-200 line-through'
                : 'bg-gray-100 text-gray-800 border border-gray-200'
            }`}
          >
            {r.suppressed && (
              <svg className="w-3.5 h-3.5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            )}
            <span className="max-w-[150px] truncate">
              {r.first_name} {r.last_name}
            </span>
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                removeRecipient(r);
              }}
              className="ml-0.5 hover:text-red-600 transition-colors"
              aria-label={`Remove ${r.first_name} ${r.last_name}`}
            >
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </span>
        ))}

        {/* Search input */}
        <input
          ref={inputRef}
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={handleKeyDown}
          onFocus={() => {
            if (query.trim().length >= 2 && searchResults.length > 0) setShowDropdown(true);
          }}
          placeholder={selectedRecipients.length === 0 ? 'Search by name, email, or phone...' : ''}
          className="flex-1 min-w-[180px] outline-none bg-transparent text-sm py-0.5 placeholder-gray-400"
        />

        {isSearching && (
          <svg className="animate-spin w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
        )}
      </div>

      {/* Suppressed warning */}
      {suppressedCount > 0 && (
        <p className="mt-1 text-xs text-amber-600 flex items-center gap-1">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          {suppressedCount} recipient{suppressedCount > 1 ? 's' : ''} suppressed (unsubscribed) and will not receive this message.
        </p>
      )}

      {/* Search results dropdown */}
      {showDropdown && flatResults.length > 0 && (
        <div
          ref={dropdownRef}
          className="absolute z-50 mt-1 w-full max-h-72 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg"
        >
          {groupOrder.map((type) => {
            const items = groupedResults[type];
            if (!items || items.length === 0) return null;
            return (
              <div key={type}>
                <div className="px-3 py-1.5 text-xs font-semibold text-gray-500 uppercase tracking-wide bg-gray-50 border-b border-gray-100 sticky top-0">
                  {groupLabels[type]}
                </div>
                {items.map((r) => {
                  const idx = indexMap.get(`${r.id}-${r.type}`) ?? -1;
                  const isHighlighted = idx === highlightedIndex;
                  return (
                    <button
                      key={`${r.id}-${r.type}`}
                      type="button"
                      data-result-index={idx}
                      disabled={r.suppressed}
                      onClick={() => addRecipient(r)}
                      className={`w-full text-left px-3 py-2 flex items-center gap-3 transition-colors ${
                        r.suppressed
                          ? 'opacity-50 cursor-not-allowed bg-gray-50'
                          : isHighlighted
                          ? 'bg-brand-primary/5'
                          : 'hover:bg-gray-50'
                      }`}
                    >
                      {/* Avatar placeholder */}
                      <div className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-semibold text-gray-600 flex-shrink-0">
                        {r.first_name?.[0]}
                        {r.last_name?.[0]}
                      </div>

                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium text-gray-900 truncate">
                            {r.first_name} {r.last_name}
                          </span>
                          {getTypeBadge(r.type)}
                          {r.suppressed && (
                            <span className="text-xs text-red-500 flex items-center gap-0.5">
                              <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636" />
                              </svg>
                              {r.suppression_reason || 'Unsubscribed'}
                            </span>
                          )}
                        </div>
                        <div className="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                          {channel === 'email' && r.email && <span>{r.email}</span>}
                          {channel === 'sms' && r.phone && <span>{r.phone}</span>}
                          {channel === 'sms' && !r.phone && (
                            <span className="text-amber-600">No phone number</span>
                          )}
                          {r.team_name && (
                            <>
                              <span className="text-gray-300">|</span>
                              <span>{r.team_name}</span>
                            </>
                          )}
                          {r.type === 'guardian' && r.athlete_name && (
                            <>
                              <span className="text-gray-300">|</span>
                              <span>Parent of {r.athlete_name}</span>
                            </>
                          )}
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>
            );
          })}
        </div>
      )}

      {/* No results state */}
      {showDropdown && flatResults.length === 0 && !isSearching && query.trim().length >= 2 && (
        <div className="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg p-4 text-center text-sm text-gray-500">
          No contacts found matching "{query}"
        </div>
      )}

      {/* Group selection dropdown */}
      {showGroupDropdown && (
        <div className="absolute z-50 mt-1 right-0 w-80 max-h-72 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg">
          <div className="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wide bg-gray-50 border-b border-gray-100 sticky top-0">
            Select a Team
          </div>
          {loadingGroups ? (
            <div className="p-4 text-center text-sm text-gray-500 flex items-center justify-center gap-2">
              <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              Loading teams...
            </div>
          ) : teamGroups.length === 0 ? (
            <div className="p-4 text-center text-sm text-gray-500">No teams found</div>
          ) : (
            teamGroups.map((group) => {
              const alreadyAdded = groupChips.some((gc) => gc.group.id === group.id);
              return (
                <button
                  key={group.id}
                  type="button"
                  disabled={alreadyAdded}
                  onClick={() => handleGroupSelect(group)}
                  className={`w-full text-left px-3 py-2.5 flex items-center justify-between transition-colors border-b border-gray-50 last:border-b-0 ${
                    alreadyAdded
                      ? 'opacity-50 cursor-not-allowed bg-gray-50'
                      : 'hover:bg-gray-50'
                  }`}
                >
                  <div>
                    <div className="text-sm font-medium text-gray-900">{group.name}</div>
                    {group.age_group && (
                      <div className="text-xs text-gray-500">{group.age_group}</div>
                    )}
                  </div>
                  <div className="text-xs text-gray-500 text-right">
                    <div>{group.athlete_count} athletes</div>
                    <div>{group.guardian_count} parents</div>
                  </div>
                </button>
              );
            })
          )}
        </div>
      )}
    </div>
  );
};

export default RecipientSelector;

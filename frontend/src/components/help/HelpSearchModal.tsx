import React, { useState, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { searchArticles } from '../../services/helpApi';
import { HelpSearchResult } from '../../types/help';
import HelpRoleBadge from './HelpRoleBadge';

interface Props {
  isOpen: boolean;
  onClose: () => void;
}

const HelpSearchModal: React.FC<Props> = ({ isOpen, onClose }) => {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<HelpSearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [selectedIndex, setSelectedIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout>>(undefined);
  const navigate = useNavigate();

  useEffect(() => {
    if (isOpen) {
      setQuery('');
      setResults([]);
      setSelectedIndex(0);
      setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [isOpen]);

  const performSearch = useCallback((q: string) => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    if (q.length < 2) {
      setResults([]);
      setLoading(false);
      return;
    }
    setLoading(true);
    debounceRef.current = setTimeout(async () => {
      try {
        const data = await searchArticles(q);
        setResults(data);
        setSelectedIndex(0);
      } catch {
        setResults([]);
      }
      setLoading(false);
    }, 300);
  }, []);

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelectedIndex((i) => Math.min(i + 1, results.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelectedIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter' && results[selectedIndex]) {
      const r = results[selectedIndex];
      navigate(`/help/${r.category_slug}/${r.slug}`);
      onClose();
    } else if (e.key === 'Escape') {
      onClose();
    }
  };

  const handleSelect = (r: HelpSearchResult) => {
    navigate(`/help/${r.category_slug}/${r.slug}`);
    onClose();
  };

  if (!isOpen) return null;

  // Group results by category
  const grouped: Record<string, HelpSearchResult[]> = {};
  results.forEach((r) => {
    if (!grouped[r.category_name]) grouped[r.category_name] = [];
    grouped[r.category_name].push(r);
  });

  let flatIndex = 0;

  return createPortal(
    <div className="fixed inset-0 z-[100] flex items-start justify-center pt-[15vh]" onClick={onClose}>
      <div className="absolute inset-0 bg-black/50" />
      <div
        className="relative w-full max-w-xl bg-white rounded-xl shadow-2xl overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Search input */}
        <div className="flex items-center border-b border-gray-200 px-4">
          <svg className="w-5 h-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            ref={inputRef}
            type="text"
            placeholder="Search help articles..."
            value={query}
            onChange={(e) => { setQuery(e.target.value); performSearch(e.target.value); }}
            onKeyDown={handleKeyDown}
            className="flex-1 py-3.5 text-base outline-none placeholder-gray-400"
          />
          {loading && (
            <svg className="w-5 h-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          <kbd className="ml-3 hidden sm:inline-flex items-center text-xs text-gray-400 border border-gray-300 rounded px-1.5 py-0.5">
            ESC
          </kbd>
        </div>

        {/* Results */}
        <div className="max-h-[50vh] overflow-y-auto">
          {query.length >= 2 && !loading && results.length === 0 && (
            <div className="px-4 py-8 text-center text-sm text-gray-500">
              No results found for "{query}"
            </div>
          )}

          {Object.entries(grouped).map(([category, items]) => (
            <div key={category}>
              <div className="sticky top-0 bg-gray-50 px-4 py-1.5 text-xs font-bold text-gray-500 uppercase tracking-wider">
                {category}
              </div>
              {items.map((r) => {
                const thisIndex = flatIndex++;
                return (
                  <button
                    key={`${r.category_slug}-${r.slug}`}
                    onClick={() => handleSelect(r)}
                    className={`w-full text-left px-4 py-2.5 flex items-start gap-3 transition-colors ${
                      selectedIndex === thisIndex ? 'bg-brand-secondary/30' : 'hover:bg-gray-50'
                    }`}
                  >
                    <svg className="w-4 h-4 mt-0.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <div className="min-w-0 flex-1">
                      <div className="text-sm font-medium text-gray-900 flex items-center gap-2">
                        {r.title}
                        {r.role_tags.map((tag) => (
                          <HelpRoleBadge key={tag} role={tag} />
                        ))}
                      </div>
                      {r.summary && (
                        <p className="text-xs text-gray-500 mt-0.5 truncate">{r.summary}</p>
                      )}
                    </div>
                  </button>
                );
              })}
            </div>
          ))}
        </div>

        {/* Footer */}
        {query.length < 2 && (
          <div className="px-4 py-3 text-xs text-gray-400 border-t border-gray-100">
            Type at least 2 characters to search
          </div>
        )}
      </div>
    </div>,
    document.body
  );
};

export default HelpSearchModal;
